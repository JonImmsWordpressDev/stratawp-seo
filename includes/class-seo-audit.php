<?php
/**
 * SEO Audit coordinator -- registers modules, runs audits, caches results.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_SEO_Audit {

	private const RESULTS_OPTION  = 'swps_audit_results';
	private const LAST_RUN_OPTION = 'swps_audit_last_run';
	private const CRON_HOOK       = 'swps_audit_cron';

	/**
	 * Default weights per module ID for overall score.
	 */
	private const WEIGHTS = [
		'canonical'   => 15,
		'sitemap'     => 15,
		'opengraph'   => 12,
		'twitter'     => 8,
		'robots'      => 10,
		'meta_robots' => 15,
		'image_seo'   => 15,
		'pagespeed'   => 10,
	];

	/** @var SWPS_Audit_Module[] */
	private array $modules = [];

	public function __construct() {
		add_action( 'init', [ $this, 'register_default_modules' ], 20 );
		add_action( self::CRON_HOOK, [ $this, 'run_all' ] );
		add_filter( 'cron_schedules', [ $this, 'register_custom_schedules' ] );
	}

	/**
	 * Register custom cron schedules needed by the audit.
	 */
	public function register_custom_schedules( array $schedules ): array {
		if ( ! isset( $schedules['swps_monthly'] ) ) {
			$schedules['swps_monthly'] = [
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Monthly', 'stratawp-seo' ),
			];
		}
		return $schedules;
	}

	/**
	 * Register the 8 built-in audit modules.
	 */
	public function register_default_modules(): void {
		$defaults = [
			new SWPS_Canonical_Module(),
			new SWPS_Sitemap_Module(),
			new SWPS_OpenGraph_Module(),
			new SWPS_Twitter_Module(),
			new SWPS_Robots_Module(),
			new SWPS_Meta_Robots_Module(),
			new SWPS_Image_SEO_Module(),
			new SWPS_PageSpeed_Module(),
		];

		foreach ( $defaults as $module ) {
			$this->modules[ $module->get_id() ] = $module;
		}

		/**
		 * Filter the registered audit modules.
		 *
		 * @param SWPS_Audit_Module[] $modules Keyed by module ID.
		 */
		$this->modules = apply_filters( 'swps_audit_modules', $this->modules );
	}

	/**
	 * Run all registered modules and cache results.
	 *
	 * @return array { @type int $overall_score, @type array $modules keyed by ID }
	 */
	public function run_all(): array {
		$results = [];

		foreach ( $this->modules as $id => $module ) {
			$result          = $module->run();
			$results[ $id ]  = apply_filters( 'swps_audit_result', $result, $id );
		}

		$overall = $this->calculate_overall_score( $results );

		$data = [
			'overall_score' => $overall,
			'modules'       => $results,
		];

		update_option( self::RESULTS_OPTION, $data, false );
		update_option( self::LAST_RUN_OPTION, current_time( 'mysql' ), false );

		do_action( 'swps_audit_complete', $data, $overall );

		return $data;
	}

	/**
	 * Run a single module by ID.
	 *
	 * @param string $id Module identifier.
	 * @return array|null Module result or null if not found.
	 */
	public function run_module( string $id ): ?array {
		if ( ! isset( $this->modules[ $id ] ) ) {
			return null;
		}

		$result = $this->modules[ $id ]->run();
		$result = apply_filters( 'swps_audit_result', $result, $id );

		// Update the cached results for this module.
		$cached                    = $this->get_cached_results();
		$cached['modules'][ $id ] = $result;
		$cached['overall_score']  = $this->calculate_overall_score( $cached['modules'] );

		update_option( self::RESULTS_OPTION, $cached, false );

		return $result;
	}

	/**
	 * Run auto-fix for a specific module.
	 *
	 * @param string $id Module identifier.
	 * @return array|null Fix result or null if module not found or not fixable.
	 */
	public function fix_module( string $id ): ?array {
		if ( ! isset( $this->modules[ $id ] ) || ! $this->modules[ $id ]->can_auto_fix() ) {
			return null;
		}

		$cached = $this->get_cached_results();
		$issues = $cached['modules'][ $id ]['issues'] ?? [];

		$fix_result = $this->modules[ $id ]->auto_fix( $issues );

		// Re-run the module to get updated results.
		$this->run_module( $id );

		return $fix_result;
	}

	/**
	 * Get cached audit results.
	 *
	 * @return array { @type int $overall_score, @type array $modules }
	 */
	public function get_cached_results(): array {
		return get_option( self::RESULTS_OPTION, [
			'overall_score' => 0,
			'modules'       => [],
		] );
	}

	/**
	 * Get last run timestamp.
	 *
	 * @return string MySQL datetime string or empty.
	 */
	public function get_last_run(): string {
		return get_option( self::LAST_RUN_OPTION, '' );
	}

	/**
	 * Get all registered modules.
	 *
	 * @return SWPS_Audit_Module[]
	 */
	public function get_modules(): array {
		return $this->modules;
	}

	/**
	 * Calculate weighted overall score from module results.
	 *
	 * @param array $module_results Module results keyed by ID.
	 * @return int Weighted overall score 0-100.
	 */
	private function calculate_overall_score( array $module_results ): int {
		$total_weight = 0;
		$weighted_sum = 0;

		foreach ( $module_results as $id => $result ) {
			$weight        = self::WEIGHTS[ $id ] ?? 10;
			$weighted_sum += ( $result['score'] ?? 0 ) * $weight;
			$total_weight += $weight;
		}

		if ( 0 === $total_weight ) {
			return 0;
		}

		return (int) round( $weighted_sum / $total_weight );
	}

	/**
	 * Schedule the audit cron.
	 */
	public static function schedule_cron(): void {
		self::unschedule_cron();

		$schedule = get_option( 'swps_audit_cron_schedule', 'weekly' );

		$recurrence_map = [
			'daily'   => 'daily',
			'weekly'  => 'weekly',
			'monthly' => 'swps_monthly',
		];

		$recurrence = $recurrence_map[ $schedule ] ?? 'weekly';
		$next_run   = time() + DAY_IN_SECONDS; // Start tomorrow.

		wp_schedule_event( $next_run, $recurrence, self::CRON_HOOK );
	}

	/**
	 * Unschedule the audit cron.
	 */
	public static function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_unschedule_hook( self::CRON_HOOK );
	}
}
