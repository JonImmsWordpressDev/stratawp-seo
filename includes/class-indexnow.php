<?php
/**
 * IndexNow integration — instantly notify Bing/Yandex/Seznam/Naver of URL changes.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns key management, the /{key}.txt verification file, lifecycle triggers,
 * the debounced submit queue, HTTP submission, the activity log, and AJAX.
 */
class SWPS_IndexNow {

	const CRON_HOOK           = 'swps_indexnow_flush';
	const ENDPOINT            = 'https://api.indexnow.org/indexnow';
	const MAX_LOG             = 50;
	const MAX_URLS_PER_REQUEST = 10000;
	const DEBOUNCE_SECONDS    = 60;
	const DAILY_CAP           = 10000;

	const OPT_ENABLED     = 'swps_indexnow_enabled';
	const OPT_AUTO        = 'swps_indexnow_auto_submit';
	const OPT_KEY         = 'swps_indexnow_api_key';
	const OPT_POST_TYPES  = 'swps_indexnow_post_types';
	const OPT_QUEUE       = 'swps_indexnow_queue';
	const OPT_LOG         = 'swps_indexnow_log';
	const OPT_DAILY_COUNT = 'swps_indexnow_daily_count';
	const OPT_DAILY_DATE  = 'swps_indexnow_daily_date';

	const META_LAST_URL  = '_swps_indexnow_last_url';
	const META_SUBMITTED = '_swps_indexnow_submitted';

	const SETTINGS_GROUP = 'swps_indexnow_settings';

	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'flush' ) );

		// Serve the verification file as early as possible.
		add_action( 'init', array( $this, 'maybe_serve_key_file' ), 1 );

		// Settings (Task 10) + admin surface (Task 11) + AJAX (Tasks 11-12).
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_' . 'swps_indexnow_generate_key', array( $this, 'ajax_generate_key' ) );
		add_action( 'wp_ajax_' . 'swps_indexnow_resubmit_all', array( $this, 'ajax_resubmit_all' ) );
		add_action( 'wp_ajax_' . 'swps_indexnow_get_log', array( $this, 'ajax_get_log' ) );
		add_action( 'wp_ajax_' . 'swps_indexnow_submit_post', array( $this, 'ajax_submit_post' ) );

		// Lifecycle triggers.
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'wp_trash_post', array( $this, 'on_delete_post' ) );
		add_action( 'created_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_change' ), 10, 3 );
	}

	/**
	 * Register the three form-persisted options in a DEDICATED option group so a
	 * partial form on the Sitemaps page cannot wipe unrelated swps_* options.
	 * The API key is NOT registered here — it is managed via the generate-key AJAX
	 * action and never rendered in the form. Each option is registered exactly once.
	 */
	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_ENABLED,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ), 'default' => 0 )
		);
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_AUTO,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ), 'default' => 1 )
		);
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_POST_TYPES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_post_types' ),
				'default'           => array( 'post', 'page' ),
			)
		);
	}

	public static function sanitize_checkbox( $value ): int {
		return $value ? 1 : 0;
	}

	public static function sanitize_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_map( 'sanitize_key', $value ) );
	}

	/** wp-cron handler — drain the debounce queue and submit as one batch. */
	public function flush(): void {
		$queue = get_option( self::OPT_QUEUE, array() );
		update_option( self::OPT_QUEUE, array(), false );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		if ( self::should_skip_environment() ) {
			self::append_log( array( 'time' => time(), 'trigger' => 'auto', 'count' => count( $queue ), 'code' => 0, 'result' => 'skipped_env' ) );
			return;
		}
		$this->submit_urls( array_values( $queue ), 'auto' );
	}

	/**
	 * POST one or more chunks of URLs to IndexNow. Logs each chunk's result.
	 *
	 * @return array[] One {code,result} per chunk.
	 */
	public function submit_urls( array $urls, string $trigger = 'manual' ): array {
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		if ( empty( $urls ) ) {
			return array();
		}
		$key = (string) get_option( self::OPT_KEY, '' );
		if ( ! self::is_valid_key( $key ) ) {
			self::append_log( array( 'time' => time(), 'trigger' => $trigger, 'count' => count( $urls ), 'code' => 0, 'result' => 'no_key' ) );
			return array();
		}
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$results = array();
		foreach ( array_chunk( $urls, self::MAX_URLS_PER_REQUEST ) as $chunk ) {
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode( self::build_payload( $host, $key, $chunk ) ),
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) ) {
				$code   = 0;
				$result = 'error';
			} else {
				$code   = (int) wp_remote_retrieve_response_code( $response );
				$result = self::interpret_response_code( $code );
			}
			self::append_log( array( 'time' => time(), 'trigger' => $trigger, 'count' => count( $chunk ), 'code' => $code, 'result' => $result ) );
			$results[] = array( 'code' => $code, 'result' => $result );
		}
		return $results;
	}

	/** Generate a fresh 32-char hex IndexNow key (does not persist). */
	public static function generate_key(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/** IndexNow keys are 8–128 chars of [a-zA-Z0-9-]. */
	public static function is_valid_key( string $key ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9-]{8,128}$/D', $key );
	}

	/** Heuristic: does this host look like a local/staging environment? */
	public static function is_staging_host( string $host ): bool {
		$host = strtolower( $host );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		foreach ( array( '.local', '.test', '.localhost', '.example', '.invalid' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		foreach ( array( 'staging.', 'stage.', 'dev.', 'test.', 'sandbox.' ) as $prefix ) {
			if ( str_starts_with( $host, $prefix ) ) {
				return true;
			}
		}
		return str_contains( $host, '.staging.' ) || str_contains( $host, '.dev.' );
	}

	/** True when submissions must be suppressed (non-production or staging host). */
	public static function should_skip_environment(): bool {
		if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
			return true;
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return self::is_staging_host( $host );
	}

	/** Build the IndexNow POST body. Pure. */
	public static function build_payload( string $host, string $key, array $urls ): array {
		return array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => 'https://' . $host . '/' . $key . '.txt',
			'urlList'     => array_values( $urls ),
		);
	}

	/** Map an HTTP status code to a log-friendly result label. Pure. */
	public static function interpret_response_code( int $code ): string {
		switch ( $code ) {
			case 200:
				return 'ok';
			case 202:
				return 'pending';
			case 400:
				return 'invalid';
			case 403:
				return 'key_not_found';
			case 422:
				return 'host_mismatch';
			case 429:
				return 'rate_limited';
			default:
				return 'error';
		}
	}

	/** Does a request path exactly match /{key}.txt? Pure. */
	public static function key_file_path_matches( string $path, string $key ): bool {
		return '/' . $key . '.txt' === $path;
	}

	/** Prepend an entry to the bounded activity log (newest first, capped at MAX_LOG). */
	public static function append_log( array $entry ): void {
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX_LOG );
		update_option( self::OPT_LOG, $log, false );
	}

	/** @return array[] The activity log, newest first. */
	public static function get_log(): array {
		$log = get_option( self::OPT_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/** Add a URL to the debounce queue (deduped) and schedule a single flush. */
	public static function enqueue_url( string $url ): void {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return;
		}
		$queue = get_option( self::OPT_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		if ( ! in_array( $url, $queue, true ) ) {
			$queue[] = $url;
			update_option( self::OPT_QUEUE, $queue, false );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::CRON_HOOK );
		}
	}

	/**
	 * Auto path: enqueue a post's permalink if IndexNow is enabled, auto-submit is
	 * on, the post type is selected, the URL is sitemap-indexable, we're on
	 * production, and the content actually changed since the last submit.
	 *
	 * @param WP_Post|object $post
	 */
	public function maybe_enqueue_post( $post ): void {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return;
		}
		if ( ! get_option( self::OPT_ENABLED, 0 ) || ! get_option( self::OPT_AUTO, 1 ) ) {
			return;
		}
		$selected = (array) get_option( self::OPT_POST_TYPES, array() );
		if ( ! in_array( ( $post->post_type ?? '' ), $selected, true ) ) {
			return;
		}
		if ( ! SWPS_Sitemap_Manager::is_post_indexable( $post ) ) {
			return;
		}
		if ( self::should_skip_environment() ) {
			return;
		}
		$modified = (string) ( $post->post_modified_gmt ?? '' );
		if ( '' !== $modified && (string) get_post_meta( $post->ID, self::META_SUBMITTED, true ) === $modified ) {
			return; // No-op resave — content unchanged since last push.
		}
		$url = get_permalink( $post );
		update_post_meta( $post->ID, self::META_LAST_URL, $url );
		update_post_meta( $post->ID, self::META_SUBMITTED, $modified );
		self::enqueue_url( $url );
	}

	/** Fired on every status change: enqueue on publish, submit the dead URL on unpublish. */
	public function on_transition_post_status( string $new_status, string $old_status, $post ): void {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post->ID ) ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			$this->maybe_enqueue_post( $post );
			return;
		}
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->enqueue_removal( (int) $post->ID );
		}
	}

	/** Fired on trash/delete: submit the last known public URL so engines recrawl (404/410). */
	public function on_delete_post( int $post_id ): void {
		$this->enqueue_removal( $post_id );
	}

	/** Submit a taxonomy term's URL when it changes, if its taxonomy is public + eligible. */
	public function on_term_change( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! get_option( self::OPT_ENABLED, 0 ) || ! get_option( self::OPT_AUTO, 1 ) ) {
			return;
		}
		if ( self::should_skip_environment() ) {
			return;
		}
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || empty( $tax->public ) ) {
			return;
		}
		if ( ! SWPS_Sitemap_Manager::is_term_indexable( $term_id, $taxonomy ) ) {
			return;
		}
		$url = get_term_link( $term_id, $taxonomy );
		if ( ! is_wp_error( $url ) ) {
			self::enqueue_url( (string) $url );
		}
	}

	/** Enqueue the stashed public URL for a removed/unpublished post. */
	private function enqueue_removal( int $post_id ): void {
		if ( ! get_option( self::OPT_ENABLED, 0 ) || ! get_option( self::OPT_AUTO, 1 ) ) {
			return;
		}
		if ( self::should_skip_environment() ) {
			return;
		}
		$url = (string) get_post_meta( $post_id, self::META_LAST_URL, true );
		if ( '' !== $url ) {
			self::enqueue_url( $url );
		}
	}

	/** AJAX: generate + persist a new IndexNow key (rotation). */
	public function ajax_generate_key(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}
		$key = self::generate_key();
		update_option( self::OPT_KEY, $key, false );
		wp_send_json_success(
			array(
				'key'          => $key,
				'key_file_url' => home_url( '/' . $key . '.txt' ),
			)
		);
	}

	/** AJAX: submit the full indexable URL set ("Resubmit all"). */
	public function ajax_resubmit_all(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}
		if ( self::should_skip_environment() ) {
			wp_send_json_error( array( 'message' => __( 'IndexNow is paused on non-production environments.', 'stratawp-seo' ) ) );
		}
		$urls    = SWPS_Sitemap_Manager::get_indexable_urls();
		$results = $this->submit_urls( $urls, 'bulk' );
		wp_send_json_success(
			array(
				'submitted' => count( $urls ),
				'batches'   => $results,
			)
		);
	}

	/** AJAX: return the activity log for the admin panel. */
	public function ajax_get_log(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}
		wp_send_json_success( array( 'log' => self::get_log() ) );
	}

	/** Serve GET /{key}.txt with the raw key body (init, priority 1). */
	public function maybe_serve_key_file(): void {
		$key = (string) get_option( self::OPT_KEY, '' );
		if ( ! self::is_valid_key( $key ) ) {
			return;
		}
		$path = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
			: '';
		if ( ! self::key_file_path_matches( $path, $key ) ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $key );
		exit;
	}
}
