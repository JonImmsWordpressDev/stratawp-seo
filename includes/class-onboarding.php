<?php
/**
 * Onboarding wizard: state helpers, admin page, activation redirect, AJAX endpoints.
 *
 * @package StrataWP_SEO
 * @since   4.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Onboarding wizard controller.
 *
 * Pure-static helpers (normalize_state, progress) carry no WordPress
 * dependencies and are unit-testable in isolation.
 */
class SWPS_Onboarding {

	/**
	 * WordPress option that persists wizard progress.
	 *
	 * @var string
	 */
	public const OPTION_STATE = 'swps_onboarding_state';

	/**
	 * Canonical wizard step order.
	 *
	 * @var array<int, string>
	 */
	public const STEPS = array( 'migrate', 'api_key', 'site_info', 'audit', 'preview' );

	/**
	 * Nonce action used for all wizard AJAX endpoints.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'swps_onboarding';

	/**
	 * Wizard admin page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'swps-onboarding';

	/**
	 * Activation-redirect transient key.
	 *
	 * @var string
	 */
	private const REDIRECT_TRANSIENT = 'swps_onboarding_redirect';

	/**
	 * Register WordPress hooks.
	 *
	 * Called in the constructor so hooks are wired at instantiation time,
	 * not at require_once time (keeps things testable).
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 70 );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );

		// AJAX endpoints — both logged-in (wp_ajax_) and nopriv omitted (all require manage_options).
		$endpoints = array(
			'swps_onboarding_status',
			'swps_onboarding_test_key',
			'swps_onboarding_suggest_site_info',
			'swps_onboarding_save_site_info',
			'swps_onboarding_run_audit',
			'swps_onboarding_preview',
			'swps_onboarding_step_done',
			'swps_onboarding_dismiss',
		);
		foreach ( $endpoints as $action ) {
			add_action( "wp_ajax_{$action}", array( $this, "ajax_{$action}" ) );
		}
	}

	// -- Pure static helpers (no WordPress required — unit-testable) --

	/**
	 * Normalise a raw option value into a well-typed state array.
	 *
	 * Unknown keys are silently dropped; known step keys are cast to bool;
	 * missing step keys default to false; 'dismissed' is cast to bool.
	 *
	 * @param mixed $raw Raw value from get_option() or any source.
	 * @return array{steps: array<string, bool>, dismissed: bool}
	 */
	public static function normalize_state( $raw ): array {
		$steps = array();
		$raw   = is_array( $raw ) ? $raw : array();
		$saved = isset( $raw['steps'] ) && is_array( $raw['steps'] ) ? $raw['steps'] : array();

		foreach ( self::STEPS as $step ) {
			$steps[ $step ] = ! empty( $saved[ $step ] );
		}

		return array(
			'steps'     => $steps,
			'dismissed' => ! empty( $raw['dismissed'] ),
		);
	}

	/**
	 * Compute progress metrics from a normalised state array.
	 *
	 * @param array{steps: array<string, bool>} $state Normalised state (from normalize_state()).
	 * @return array{done: int, total: int, complete: bool, next: string|null}
	 */
	public static function progress( array $state ): array {
		$done = count( array_filter( $state['steps'] ) );
		$next = null;

		foreach ( self::STEPS as $step ) {
			if ( empty( $state['steps'][ $step ] ) ) {
				$next = $step;
				break;
			}
		}

		return array(
			'done'     => $done,
			'total'    => count( self::STEPS ),
			'complete' => count( self::STEPS ) === $done,
			'next'     => $next,
		);
	}

	// -- Admin page registration --

	/**
	 * Register the Setup Wizard submenu page (visible — no hidden-page pattern exists).
	 *
	 * @since 4.13.0
	 */
	public function register_page(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Setup Wizard', 'stratawp-seo' ),
			__( 'Setup Wizard', 'stratawp-seo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the wizard page (includes template or shows placeholder).
	 *
	 * @since 4.13.0
	 */
	public function render_page(): void {
		$template = SWPS_PLUGIN_DIR . 'templates/onboarding-page.php';

		if ( file_exists( $template ) ) {
			include $template;
		} else {
			echo '<div class="wrap"><h1>';
			echo esc_html__( 'StrataWP SEO — Setup Wizard', 'stratawp-seo' );
			echo '</h1><p>';
			echo esc_html__( 'Onboarding wizard template coming soon.', 'stratawp-seo' );
			echo '</p></div>';
		}
	}

	// -- Activation redirect --

	/**
	 * Redirect to the wizard on first activation (bails on AJAX/network/CLI/bulk).
	 *
	 * @since 4.13.0
	 */
	public function maybe_redirect(): void {
		if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::REDIRECT_TRANSIENT );

		// Bail conditions that make a redirect inappropriate.
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( is_network_admin() ) {
			return;
		}
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	// -- AJAX shared security guard --

	/**
	 * Verify nonce and manage_options capability; die on failure.
	 *
	 * @since 4.13.0
	 */
	private function check_ajax(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ), 403 );
		}
	}

	// -- AJAX private state helper --

	/**
	 * Mark a single wizard step as complete and persist the state option.
	 *
	 * @param string $step One of the STEPS values.
	 * @since 4.13.0
	 */
	private function mark_step( string $step ): void {
		$state                   = self::normalize_state( get_option( self::OPTION_STATE ) );
		$state['steps'][ $step ] = true;
		update_option( self::OPTION_STATE, $state, false );
	}

	// -- AJAX handlers --

	/**
	 * Return current wizard status: normalised state, progress, and migration detection.
	 *
	 * @since 4.13.0
	 */
	public function ajax_swps_onboarding_status(): void {
		$this->check_ajax();

		$state    = self::normalize_state( get_option( self::OPTION_STATE ) );
		$progress = self::progress( $state );

		$migration = stratawp_seo()->migration ?? null;
		$sources   = $migration ? $migration->detect_sources() : array();

		wp_send_json_success(
			array(
				'state'    => $state,
				'progress' => $progress,
				'sources'  => $sources,
			)
		);
	}

	/**
	 * Test an API key then save provider + key through the same Settings path.
	 *
	 * POST: provider (string), api_key (string).
	 *
	 * @since 4.13.0
	 */
	public function ajax_swps_onboarding_test_key(): void {
		$this->check_ajax();

		// Nonce already verified inside check_ajax() via check_ajax_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$provider_slug = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$api_key       = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $provider_slug ) || empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Provider and API key are required.', 'stratawp-seo' ) ) );
		}

		// Build a temporary provider instance using the factory's slug map.
		// Keys are NOT encrypted — settings stores them via sanitize_text_field.
		$provider_classes = array(
			'anthropic' => 'SWPS_Anthropic_Provider',
			'openai'    => 'SWPS_OpenAI_Provider',
			'google'    => 'SWPS_Google_Provider',
			'xai'       => 'SWPS_XAI_Provider',
		);

		if ( ! isset( $provider_classes[ $provider_slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'stratawp-seo' ) ) );
		}

		$class    = $provider_classes[ $provider_slug ];
		$provider = new $class();

		$result = $provider->test_key( $api_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'API key test failed. Please check the key and try again.', 'stratawp-seo' ) ) );
		}

		// Key is valid — save through the same option names Settings uses:
		// swps_ai_provider  and  swps_{slug}_api_key  (plain sanitize_text_field, not encrypted).
		update_option( 'swps_ai_provider', $provider_slug );
		update_option( $provider->get_api_key_option(), $api_key );

		$this->mark_step( 'api_key' );

		wp_send_json_success( array( 'message' => __( 'API key validated and saved.', 'stratawp-seo' ) ) );
	}

	/**
	 * AI-suggest a 1-2 sentence site description (fails soft — returns '' on error).
	 *
	 * @since 4.13.0
	 */
	public function ajax_swps_onboarding_suggest_site_info(): void {
		$this->check_ajax();

		$analyzer = stratawp_seo()->analyzer;
		$summary  = $analyzer->get_site_summary( 50 );
		$provider = SWPS_Provider_Factory::create_ai_provider();

		if ( empty( $provider->get_api_key() ) ) {
			wp_send_json_success( array( 'suggestion' => '' ) );
		}

		$system = 'You are an SEO assistant. Respond only with a 1-2 sentence plain-text site description — no markdown, no lists.';
		$user   = sprintf(
			/* translators: %s: JSON-encoded site summary data */
			'Based on this WordPress site data, write a concise 1-2 sentence description of the site for SEO purposes: %s',
			wp_json_encode( $summary )
		);

		$response = $provider->chat( $system, $user, 200 );

		$suggestion = is_wp_error( $response ) ? '' : trim( $response );

		wp_send_json_success( array( 'suggestion' => $suggestion ) );
	}

	/**
	 * Save the site description entered (or confirmed) by the user.
	 *
	 * POST: description (string).
	 *
	 * @since 4.13.0
	 */
	public function ajax_swps_onboarding_save_site_info(): void {
		$this->check_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside check_ajax().
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( empty( $description ) ) {
			wp_send_json_error( array( 'message' => __( 'Description cannot be empty.', 'stratawp-seo' ) ) );
		}

		// Save through the same option the Settings "Site Details" section uses.
		update_option( 'swps_site_description', $description );

		$this->mark_step( 'site_info' );

		wp_send_json_success( array( 'message' => __( 'Site description saved.', 'stratawp-seo' ) ) );
	}

	/**
	 * Run the full SEO audit, mark the step done, and return a summary.
	 *
	 * @since 4.13.0
	 */
	public function ajax_swps_onboarding_run_audit(): void {
		$this->check_ajax();

		$audit   = stratawp_seo()->seo_audit;
		$results = $audit->run_all();

		$pass    = 0;
		$issues  = 0;
		$modules = $results['modules'] ?? array();

		foreach ( $modules as $mod ) {
			$status = $mod['status'] ?? 'fail';
			if ( 'pass' === $status ) {
				++$pass;
			} else {
				++$issues;
			}
		}

		$this->mark_step( 'audit' );

		wp_send_json_success(
			array(
				'pass'      => $pass,
				'issues'    => $issues,
				'audit_url' => admin_url( 'admin.php?page=swps-seo-audit' ),
			)
		);
	}

	/**
	 * Generate a preview post (rate-limited, uses budget guardian).
	 *
	 * POST: topic (string, optional).
	 *
	 * @since 4.13.0
	 */
	public function ajax_swps_onboarding_preview(): void {
		$this->check_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside check_ajax().
		$topic  = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		$result = stratawp_seo()->generator->preview_content( $topic );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return title, meta description, and first ~600 chars of sanitised content.
		$content_html = isset( $result['content_html'] ) ? wp_kses_post( $result['content_html'] ) : '';
		if ( mb_strlen( $content_html ) > 600 ) {
			// Truncate at a space boundary to avoid mid-tag splits.
			$content_html = mb_substr( $content_html, 0, 600 );
			$last_space   = mb_strrpos( $content_html, ' ' );
			if ( false !== $last_space ) {
				$content_html = mb_substr( $content_html, 0, $last_space );
			}
			$content_html .= '&hellip;';
		}

		$this->mark_step( 'preview' );

		wp_send_json_success(
			array(
				'title'            => isset( $result['title'] ) ? sanitize_text_field( $result['title'] ) : '',
				'meta_description' => isset( $result['meta_description'] ) ? sanitize_text_field( $result['meta_description'] ) : '',
				'content_html'     => $content_html,
			)
		);
	}

	/**
	 * Mark a step done — used by the migration "I'm done" and skip buttons.
	 *
	 * POST: step (string — must be in STEPS).
	 *
	 * @since 4.13.0
	 */
	public function ajax_swps_onboarding_step_done(): void {
		$this->check_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside check_ajax().
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';

		if ( ! in_array( $step, self::STEPS, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid step.', 'stratawp-seo' ) ) );
		}

		$this->mark_step( $step );

		$state    = self::normalize_state( get_option( self::OPTION_STATE ) );
		$progress = self::progress( $state );

		wp_send_json_success(
			array(
				'step'     => $step,
				'progress' => $progress,
			)
		);
	}

	/**
	 * Set the wizard as dismissed — hides the dashboard checklist.
	 *
	 * @since 4.13.0
	 */
	public function ajax_swps_onboarding_dismiss(): void {
		$this->check_ajax();

		$state              = self::normalize_state( get_option( self::OPTION_STATE ) );
		$state['dismissed'] = true;
		update_option( self::OPTION_STATE, $state, false );

		wp_send_json_success( array( 'dismissed' => true ) );
	}
}
