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
	}

	/**
	 * wp-cron handler — drains the debounce queue and submits. Filled in Task 7.
	 */
	public function flush(): void {
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
}
