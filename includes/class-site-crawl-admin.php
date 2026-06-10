<?php
/**
 * Site Crawl admin page — Start-crawl UI, chunked AJAX progress, issue list,
 * and one-click fixes (create redirect / exclude from sitemap / ignore host).
 *
 * Settings (caps, politeness delay, weekly re-crawl toggle) live in a small
 * section on the crawl page itself and save via the same nonce.
 *
 * All AJAX: nonce 'swps_site_crawl' + manage_options.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page + AJAX endpoints for the site crawler.
 */
class SWPS_Site_Crawl_Admin {

	/** Nonce action for every crawl AJAX endpoint. */
	private const NONCE = 'swps_site_crawl';

	/**
	 * Crawler instance used to start runs and process chunks.
	 *
	 * @var SWPS_Site_Crawler
	 */
	private SWPS_Site_Crawler $crawler;

	/**
	 * Wire menu, assets, and AJAX handlers.
	 *
	 * @param SWPS_Site_Crawler $crawler Crawler instance.
	 */
	public function __construct( SWPS_Site_Crawler $crawler ) {
		$this->crawler = $crawler;

		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_swps_crawl_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_swps_crawl_chunk', array( $this, 'ajax_chunk' ) );
		add_action( 'wp_ajax_swps_crawl_issues', array( $this, 'ajax_issues' ) );
		add_action( 'wp_ajax_swps_crawl_create_redirect', array( $this, 'ajax_create_redirect' ) );
		add_action( 'wp_ajax_swps_crawl_exclude_sitemap', array( $this, 'ajax_exclude_sitemap' ) );
		add_action( 'wp_ajax_swps_crawl_ignore_host', array( $this, 'ajax_ignore_host' ) );
		add_action( 'wp_ajax_swps_crawl_save_settings', array( $this, 'ajax_save_settings' ) );
	}

	/**
	 * Register the Site Crawl submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Site Crawl', 'stratawp-seo' ),
			__( 'Site Crawl', 'stratawp-seo' ),
			'manage_options',
			'swps-site-crawl',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the page template.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
		}
		require SWPS_PLUGIN_DIR . 'templates/site-crawl-page.php';
	}

	/**
	 * Enqueue the page JS (shared shell CSS comes from SWPS_Admin_Shell).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'stratawp-seo_page_swps-site-crawl' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'swps-site-crawl',
			SWPS_PLUGIN_URL . 'admin/js/site-crawl.js',
			array( 'jquery' ),
			SWPS_VERSION,
			true
		);
		wp_localize_script(
			'swps-site-crawl',
			'swpsSiteCrawl',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'starting'       => __( 'Starting crawl…', 'stratawp-seo' ),
					/* translators: 1: crawled count, 2: queued count, 3: issues count */
					'crawling'       => __( 'Crawling — %1$d done, %2$d queued, %3$d issues…', 'stratawp-seo' ),
					'finishing'      => __( 'Checking external links + finishing…', 'stratawp-seo' ),
					'done'           => __( 'Crawl complete.', 'stratawp-seo' ),
					'failed'         => __( 'Request failed.', 'stratawp-seo' ),
					'loopbackFail'   => __( 'The crawler could not reach your own site (0 pages fetched). Loopback requests may be blocked by basic auth or a firewall.', 'stratawp-seo' ),
					'redirectTo'     => __( 'Redirect target URL (e.g. /new-page):', 'stratawp-seo' ),
					'redirectOk'     => __( 'Redirect created.', 'stratawp-seo' ),
					'excluded'       => __( 'Excluded from sitemap.', 'stratawp-seo' ),
					'hostIgnored'    => __( 'Host added to ignore list.', 'stratawp-seo' ),
					'settingsSaved'  => __( 'Settings saved.', 'stratawp-seo' ),
					'newBadge'       => __( 'NEW', 'stratawp-seo' ),
					'noIssues'       => __( 'No issues found in the last crawl. 🎉', 'stratawp-seo' ),
					'editPost'       => __( 'Edit post', 'stratawp-seo' ),
					'createRedirect' => __( 'Create redirect', 'stratawp-seo' ),
					'excludeSitemap' => __( 'Exclude from sitemap', 'stratawp-seo' ),
					'ignoreHost'     => __( 'Ignore host', 'stratawp-seo' ),
				),
			)
		);
	}

	// =========================================================================
	// AJAX — crawl lifecycle
	// =========================================================================

	/**
	 * Verify the manage_options capability; dies with a JSON error otherwise.
	 *
	 * Nonce verification happens inline in each handler (check_ajax_referer)
	 * so the WPCS nonce sniff can statically see it.
	 */
	private function require_cap(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}
	}

	/**
	 * Start a new crawl run; returns run_id + initial queue count.
	 */
	public function ajax_start(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->require_cap();

		$run_id   = $this->crawler->start_run();
		$snapshot = SWPS_Crawl_Issues::progress_snapshot( $run_id );

		wp_send_json_success(
			array(
				'run_id'  => $run_id,
				'queued'  => $snapshot['queued'],
				'crawled' => $snapshot['crawled'],
				'issues'  => $snapshot['issues'],
			)
		);
	}

	/**
	 * Process one chunk; returns the progress snapshot.
	 */
	public function ajax_chunk(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->require_cap();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );

		$result = $this->crawler->process_chunk( 10 );

		wp_send_json_success( $result );
	}

	/**
	 * Return the latest run's issues grouped by type, with per-row fix metadata.
	 */
	public function ajax_issues(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->require_cap();

		$state  = SWPS_Site_Crawler::get_state();
		$run_id = (int) ( $state['run_id'] ?? 0 );

		if ( 0 === $run_id ) {
			wp_send_json_success(
				array(
					'run_id' => 0,
					'groups' => array(),
				)
			);
		}

		$grouped = SWPS_Crawl_Issues::issues_for_run( $run_id );

		// Enrich each row with edit-post URL + host for the JS action buttons.
		foreach ( $grouped as $type => $rows ) {
			foreach ( $rows as $i => $row ) {
				$post_id = url_to_postid( (string) $row['url'] );
				$host    = (string) wp_parse_url( (string) $row['url'], PHP_URL_HOST );
				$path    = (string) wp_parse_url( (string) $row['url'], PHP_URL_PATH );

				$grouped[ $type ][ $i ]['edit_url'] = $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : '';
				$grouped[ $type ][ $i ]['post_id']  = $post_id;
				$grouped[ $type ][ $i ]['host']     = $host;
				$grouped[ $type ][ $i ]['path']     = $path;
			}
		}

		wp_send_json_success(
			array(
				'run_id'  => $run_id,
				'summary' => get_option( SWPS_Site_Crawler::OPT_LAST_SUMMARY, array() ),
				'groups'  => $grouped,
			)
		);
	}

	// =========================================================================
	// AJAX — one-click fixes
	// =========================================================================

	/**
	 * Create a redirect for a broken URL (prefilled source path + user target).
	 */
	public function ajax_create_redirect(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->require_cap();

		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';

		if ( '' === $source || '' === $target ) {
			wp_send_json_error( array( 'message' => __( 'Source and target are required.', 'stratawp-seo' ) ) );
		}

		$result = stratawp_seo()->redirect_manager->add_redirect( $source, $target, 301 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not create the redirect.', 'stratawp-seo' ) ) );
		}

		wp_send_json_success( array( 'redirect_id' => (int) $result ) );
	}

	/**
	 * Toggle the _swps_sitemap_exclude meta on a post.
	 */
	public function ajax_exclude_sitemap(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->require_cap();

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( null === $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'stratawp-seo' ) ) );
		}

		update_post_meta( $post_id, '_swps_sitemap_exclude', 1 );

		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	/**
	 * Append a host to the swps_crawl_ignore_hosts option (comma-separated).
	 */
	public function ajax_ignore_host(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->require_cap();

		$host = isset( $_POST['host'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['host'] ) ) ) : '';

		if ( '' === $host ) {
			wp_send_json_error( array( 'message' => __( 'Host is required.', 'stratawp-seo' ) ) );
		}

		$raw   = (string) get_option( 'swps_crawl_ignore_hosts', '' );
		$hosts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

		if ( ! in_array( $host, $hosts, true ) ) {
			$hosts[] = $host;
			update_option( 'swps_crawl_ignore_hosts', implode( ',', $hosts ), false );
		}

		wp_send_json_success( array( 'hosts' => $hosts ) );
	}

	// =========================================================================
	// AJAX — settings
	// =========================================================================

	/**
	 * Save caps / politeness / weekly toggle from the crawl-page settings card.
	 */
	public function ajax_save_settings(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->require_cap();

		$internal_cap = isset( $_POST['internal_cap'] ) ? (int) $_POST['internal_cap'] : SWPS_Site_Crawler::DEFAULT_INTERNAL_CAP;
		$external_cap = isset( $_POST['external_cap'] ) ? (int) $_POST['external_cap'] : SWPS_Site_Crawler::DEFAULT_EXTERNAL_CAP;
		$delay_ms     = isset( $_POST['delay_ms'] ) ? (int) $_POST['delay_ms'] : 500;
		$weekly       = ! empty( $_POST['weekly_enabled'] ) ? 1 : 0;
		$ignore_hosts = isset( $_POST['ignore_hosts'] ) ? sanitize_text_field( wp_unslash( $_POST['ignore_hosts'] ) ) : '';

		// Clamp to safe bounds. Politeness delay floor is 100 ms via the UI;
		// the hard 500 ms default can only be lowered explicitly here.
		$internal_cap = max( 10, min( 2000, $internal_cap ) );
		$external_cap = max( 0, min( 1000, $external_cap ) );
		$delay_ms     = max( 100, min( 5000, $delay_ms ) );

		update_option( 'swps_crawl_internal_cap', $internal_cap, false );
		update_option( 'swps_crawl_external_cap', $external_cap, false );
		update_option( 'swps_crawl_delay_us', $delay_ms * 1000, false );
		update_option( 'swps_crawl_ignore_hosts', $ignore_hosts, false );
		update_option( 'swps_crawl_weekly_enabled', $weekly, false );

		if ( $weekly ) {
			SWPS_Site_Crawler::schedule_weekly_cron();
		} else {
			SWPS_Site_Crawler::unschedule_weekly_cron();
		}

		wp_send_json_success(
			array(
				'internal_cap' => $internal_cap,
				'external_cap' => $external_cap,
				'delay_ms'     => $delay_ms,
				'weekly'       => $weekly,
			)
		);
	}
}
