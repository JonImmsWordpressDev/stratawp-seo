<?php
/**
 * AI Bot Analytics — server-side crawler hit tracking.
 *
 * Captures requests from known AI crawlers (GPTBot, ClaudeBot, PerplexityBot,
 * Google-Extended, etc.), records what they hit and when, surfaces 404s,
 * and feeds the "AI Crawlers" dashboard tab.
 *
 * Mirrors the SWPS_Analytics_Tracker raw + daily pattern: raw rows for the
 * last 7 days, then aggregated into a daily summary and pruned per the
 * `swps_bot_analytics_retention` setting (default 90 days).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Bot_Analytics_Tracker {

	private const RAW_TABLE   = 'swps_bot_hits';
	private const DAILY_TABLE = 'swps_bot_hits_daily';
	private const CRON_HOOK   = 'swps_bot_analytics_aggregate';

	/**
	 * Max URI length stored — anything longer is truncated.
	 */
	private const URI_MAX_LEN = 500;

	public function __construct() {
		// Capture on shutdown so $wp_query, status code, and queried object are settled.
		add_action( 'shutdown', array( $this, 'maybe_record' ), 999 );

		// Daily aggregation cron.
		add_action( self::CRON_HOOK, array( $this, 'aggregate_and_prune' ) );
	}

	/**
	 * Create custom tables. Called on activation.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$raw     = $wpdb->prefix . self::RAW_TABLE;
		$daily   = $wpdb->prefix . self::DAILY_TABLE;

		$sql_raw = "CREATE TABLE {$raw} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bot_key VARCHAR(32) NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            request_uri VARCHAR(500) NOT NULL DEFAULT '',
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_bot_created (bot_key, created_at),
            KEY idx_post (post_id),
            KEY idx_status (status_code)
        ) {$charset};";

		$sql_daily = "CREATE TABLE {$daily} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bot_key VARCHAR(32) NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            date DATE NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            errors_404 INT UNSIGNED NOT NULL DEFAULT 0,
            errors_5xx INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY idx_bot_post_date (bot_key, post_id, date),
            KEY idx_date (date)
        ) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_raw );
		dbDelta( $sql_daily );
	}

	/**
	 * Drop tables. Called on uninstall.
	 */
	public static function drop_tables(): void {
		global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::RAW_TABLE );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::DAILY_TABLE );
        // phpcs:enable
	}

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Capture the current request if it came from a known AI bot.
	 */
	public function maybe_record(): void {
		if ( ! get_option( 'swps_bot_analytics_enabled', 1 ) ) {
			return;
		}

		// Skip admin, AJAX, cron, REST, CLI.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $ua ) {
			return;
		}

		$bot_key = $this->match_bot( $ua );
		if ( null === $bot_key ) {
			return;
		}

		// Method gate — GET/HEAD only.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$uri = $this->current_request_uri();
		if ( '' === $uri ) {
			return;
		}
		if ( $this->is_excluded_path( $uri ) ) {
			return;
		}

		// Sampling — `swps_bot_analytics_sample_rate` is a percent 0-100.
		$sample = (int) get_option( 'swps_bot_analytics_sample_rate', 100 );
		if ( $sample < 100 ) {
			$sample = max( 0, min( 100, $sample ) );
			if ( wp_rand( 1, 100 ) > $sample ) {
				return;
			}
		}

		$status  = $this->current_status_code();
		$post_id = ( function_exists( 'is_singular' ) && is_singular() ) ? (int) get_queried_object_id() : 0;

		/**
		 * Filter: allow third parties to veto a captured hit.
		 *
		 * @param bool   $capture Default true.
		 * @param string $bot_key Bot key from SWPS_AI_Bots::get_bots().
		 * @param string $uri     Request URI.
		 * @param int    $status  HTTP status code.
		 */
		$capture = (bool) apply_filters( 'swps_bot_analytics_capture', true, $bot_key, $uri, $status );
		if ( ! $capture ) {
			return;
		}

		$this->insert_hit( $bot_key, $post_id, $uri, $status, $ua );

		/**
		 * Fires after a bot hit is recorded — useful for downstream notifications.
		 *
		 * @param string $bot_key Bot key.
		 * @param int    $post_id Post ID (0 if not singular).
		 * @param string $uri     Request URI.
		 * @param int    $status  HTTP status code.
		 */
		do_action( 'swps_bot_analytics_hit', $bot_key, $post_id, $uri, $status );
	}

	/**
	 * Look up the UA against the canonical bot list.
	 */
	private function match_bot( string $ua ): ?string {
		foreach ( SWPS_AI_Bots::get_bots() as $key => $token ) {
			if ( stripos( $ua, $token ) !== false ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Normalize the current request URI to a clean path (no host, capped length).
	 */
	private function current_request_uri(): string {
		$raw = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $raw ) {
			return '';
		}
		// Strip host if present (some proxies prefix it).
		$path  = wp_parse_url( $raw, PHP_URL_PATH );
		$query = wp_parse_url( $raw, PHP_URL_QUERY );
		$uri   = (string) $path . ( $query ? '?' . $query : '' );

		/**
		 * Filter the normalized URI before bucketing into the analytics table.
		 * Useful for collapsing variants (e.g. paginated archives).
		 */
		$uri = (string) apply_filters( 'swps_bot_analytics_normalize_uri', $uri );

		if ( strlen( $uri ) > self::URI_MAX_LEN ) {
			$uri = substr( $uri, 0, self::URI_MAX_LEN );
		}
		return $uri;
	}

	/**
	 * Match excluded path prefixes from settings.
	 */
	private function is_excluded_path( string $uri ): bool {
		$raw   = (string) get_option(
			'swps_bot_analytics_exclude_paths',
			"/wp-admin\n/wp-json\n/wp-login\n/feed\n/xmlrpc.php"
		);
		$lines = preg_split( '/[\r\n,]+/', $raw ) ?: array();
		foreach ( $lines as $prefix ) {
			$prefix = trim( $prefix );
			if ( '' === $prefix ) {
				continue;
			}
			if ( str_starts_with( $uri, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Best-effort current HTTP status code at shutdown.
	 */
	private function current_status_code(): int {
		$code = function_exists( 'http_response_code' ) ? (int) http_response_code() : 0;
		if ( $code > 0 ) {
			return $code;
		}
		if ( function_exists( 'is_404' ) && is_404() ) {
			return 404;
		}
		return 200;
	}

	/**
	 * Single-row insert. One bot request → one row.
	 */
	private function insert_hit( string $bot_key, int $post_id, string $uri, int $status, string $ua ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::RAW_TABLE;

		$wpdb->insert(
			$table,
			array(
				'bot_key'     => $bot_key,
				'post_id'     => $post_id,
				'request_uri' => $uri,
				'status_code' => $status,
				'user_agent'  => substr( $ua, 0, 255 ),
			),
			array( '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * Roll up raw rows older than 7 days into the daily table, then prune.
	 */
	public function aggregate_and_prune(): void {
		global $wpdb;

		$raw   = $wpdb->prefix . self::RAW_TABLE;
		$daily = $wpdb->prefix . self::DAILY_TABLE;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$daily} (bot_key, post_id, date, hits, errors_404, errors_5xx)
                 SELECT bot_key,
                        post_id,
                        DATE(created_at) AS date,
                        COUNT(*) AS hits,
                        SUM(CASE WHEN status_code = 404 THEN 1 ELSE 0 END) AS errors_404,
                        SUM(CASE WHEN status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS errors_5xx
                 FROM {$raw}
                 WHERE created_at < %s
                 GROUP BY bot_key, post_id, DATE(created_at)
                 ON DUPLICATE KEY UPDATE
                    hits = hits + VALUES(hits),
                    errors_404 = errors_404 + VALUES(errors_404),
                    errors_5xx = errors_5xx + VALUES(errors_5xx)",
				$cutoff
			)
		);

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$raw} WHERE created_at < %s", $cutoff ) );

		$retention = (int) get_option( 'swps_bot_analytics_retention', 90 );
		$retention = max( 7, $retention );
		$prune     = gmdate( 'Y-m-d', strtotime( "-{$retention} days" ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE date < %s", $prune ) );
        // phpcs:enable
	}

	/**
	 * Per-bot summary: hits, last seen, sparkline data.
	 *
	 * @return array<int, array{bot_key:string, label:string, hits:int, last_seen:?string, daily:array<int,array{date:string,hits:int}>}>
	 */
	public function get_bot_summary( int $days = 30 ): array {
		global $wpdb;
		$raw   = $wpdb->prefix . self::RAW_TABLE;
		$daily = $wpdb->prefix . self::DAILY_TABLE;
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot_key, SUM(hits) AS hits, MAX(seen) AS last_seen
                 FROM (
                     SELECT bot_key, 1 AS hits, created_at AS seen
                     FROM {$raw}
                     WHERE DATE(created_at) >= %s
                     UNION ALL
                     SELECT bot_key, hits, CONCAT(date, ' 00:00:00') AS seen
                     FROM {$daily}
                     WHERE date >= %s
                 ) c
                 GROUP BY bot_key
                 ORDER BY hits DESC",
				$since,
				$since
			),
			ARRAY_A
		);

		$per_bot_daily = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot_key, date, SUM(hits) AS hits FROM (
                    SELECT bot_key, DATE(created_at) AS date, 1 AS hits
                    FROM {$raw}
                    WHERE DATE(created_at) >= %s
                    UNION ALL
                    SELECT bot_key, date, hits
                    FROM {$daily}
                    WHERE date >= %s
                ) c
                GROUP BY bot_key, date
                ORDER BY date ASC",
				$since,
				$since
			),
			ARRAY_A
		);
        // phpcs:enable

		$daily_by_bot = array();
		foreach ( $per_bot_daily as $row ) {
			$daily_by_bot[ $row['bot_key'] ][] = array(
				'date' => $row['date'],
				'hits' => (int) $row['hits'],
			);
		}

		$bots = SWPS_AI_Bots::get_bots();
		$out  = array();
		foreach ( (array) $totals as $row ) {
			$key       = (string) $row['bot_key'];
			$label     = $bots[ $key ] ?? $key;
			$last_seen = ! empty( $row['last_seen'] ) ? (string) $row['last_seen'] : null;
			$out[]     = array(
				'bot_key'   => $key,
				'label'     => $label,
				'hits'      => (int) $row['hits'],
				'last_seen' => $last_seen,
				'daily'     => $daily_by_bot[ $key ] ?? array(),
			);
		}
		return $out;
	}

	/**
	 * Get headline totals.
	 *
	 * @return array{total_hits:int, total_404:int, active_bots:int, prev_hits:int}
	 */
	public function get_totals( int $days = 30 ): array {
		global $wpdb;
		$raw   = $wpdb->prefix . self::RAW_TABLE;
		$daily = $wpdb->prefix . self::DAILY_TABLE;
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$prev  = gmdate( 'Y-m-d', strtotime( '-' . ( $days * 2 ) . ' days' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(hits) AS hits,
                        SUM(errors_404) AS errors_404,
                        COUNT(DISTINCT bot_key) AS active
                 FROM (
                     SELECT bot_key,
                            1 AS hits,
                            CASE WHEN status_code = 404 THEN 1 ELSE 0 END AS errors_404
                     FROM {$raw}
                     WHERE DATE(created_at) >= %s
                     UNION ALL
                     SELECT bot_key, hits, errors_404
                     FROM {$daily}
                     WHERE date >= %s
                 ) c",
				$since,
				$since
			),
			ARRAY_A
		);

		$prev_hits = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(hits) FROM (
                    SELECT 1 AS hits FROM {$raw}
                    WHERE DATE(created_at) >= %s AND DATE(created_at) < %s
                    UNION ALL
                    SELECT hits FROM {$daily}
                    WHERE date >= %s AND date < %s
                ) c",
				$prev,
				$since,
				$prev,
				$since
			)
		);
        // phpcs:enable

		return array(
			'total_hits'  => (int) ( $row['hits'] ?? 0 ),
			'total_404'   => (int) ( $row['errors_404'] ?? 0 ),
			'active_bots' => (int) ( $row['active'] ?? 0 ),
			'prev_hits'   => $prev_hits,
		);
	}

	/**
	 * Top crawled pages.
	 *
	 * @return array<int, array{post_id:int, request_uri:string, title:string, url:string, hits:int, errors_404:int}>
	 */
	public function get_top_pages( int $days = 30, int $limit = 20, ?string $bot_key = null ): array {
		global $wpdb;
		$raw   = $wpdb->prefix . self::RAW_TABLE;
		$daily = $wpdb->prefix . self::DAILY_TABLE;
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$limit = max( 1, min( 100, $limit ) );

		$bot_clause = '';
		$params     = array( $since, $since );
		if ( $bot_key ) {
			$bot_clause = ' WHERE bot_key = %s';
			// We'll inject in both subqueries — handled via prepare placeholders below.
		}

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $bot_key ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, MAX(request_uri) AS request_uri,
                            SUM(hits) AS hits,
                            SUM(errors_404) AS errors_404
                     FROM (
                         SELECT post_id, request_uri, bot_key,
                                1 AS hits,
                                CASE WHEN status_code = 404 THEN 1 ELSE 0 END AS errors_404
                         FROM {$raw}
                         WHERE DATE(created_at) >= %s AND bot_key = %s
                         UNION ALL
                         SELECT post_id, '' AS request_uri, bot_key, hits, errors_404
                         FROM {$daily}
                         WHERE date >= %s AND bot_key = %s
                     ) c
                     GROUP BY post_id
                     ORDER BY hits DESC
                     LIMIT %d",
					$since,
					$bot_key,
					$since,
					$bot_key,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, MAX(request_uri) AS request_uri,
                            SUM(hits) AS hits,
                            SUM(errors_404) AS errors_404
                     FROM (
                         SELECT post_id, request_uri,
                                1 AS hits,
                                CASE WHEN status_code = 404 THEN 1 ELSE 0 END AS errors_404
                         FROM {$raw}
                         WHERE DATE(created_at) >= %s
                         UNION ALL
                         SELECT post_id, '' AS request_uri, hits, errors_404
                         FROM {$daily}
                         WHERE date >= %s
                     ) c
                     GROUP BY post_id
                     ORDER BY hits DESC
                     LIMIT %d",
					$since,
					$since,
					$limit
				),
				ARRAY_A
			);
		}
        // phpcs:enable

		$out = array();
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$title   = '';
			$url     = '';
			if ( $post_id > 0 ) {
				$post = get_post( $post_id );
				if ( $post ) {
					$title = (string) get_the_title( $post );
					$url   = (string) get_permalink( $post );
				}
			}
			$out[] = array(
				'post_id'     => $post_id,
				'request_uri' => (string) $row['request_uri'],
				'title'       => $title,
				'url'         => $url,
				'hits'        => (int) $row['hits'],
				'errors_404'  => (int) $row['errors_404'],
			);
		}
		return $out;
	}

	/**
	 * Published posts with ZERO bot hits in the window — the AEO gap report.
	 *
	 * @param int    $days    Look-back window in days.
	 * @param int    $limit   Max rows (1–100).
	 * @param string $orderby 'date' (default) or 'score' (AEO score desc — high-score-but-uncrawled first).
	 * @return array<int, array{post_id:int, title:string, url:string, post_date:string, aeo_score:int|null}>
	 */
	public function get_gap_posts( int $days = 30, int $limit = 25, string $orderby = 'date' ): array {
		global $wpdb;
		$raw   = $wpdb->prefix . self::RAW_TABLE;
		$daily = $wpdb->prefix . self::DAILY_TABLE;
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$limit = max( 1, min( 100, $limit ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hit_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM (
                    SELECT post_id FROM {$raw} WHERE DATE(created_at) >= %s AND post_id > 0
                    UNION
                    SELECT post_id FROM {$daily} WHERE date >= %s AND post_id > 0
                ) c",
				$since,
				$since
			)
		);
        // phpcs:enable

		$exclude = array_map( 'intval', (array) $hit_ids );

		$query_args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'post__not_in'   => empty( $exclude ) ? array( 0 ) : $exclude,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		if ( 'score' === $orderby ) {
			// Named OR meta_query clauses keep unscored posts in the result
			// (LEFT JOIN), while ordering scored posts first by score desc.
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation'  => 'OR',
				'has_score' => array(
					'key'     => \SWPS_AEO_Scorer::META_TOTAL,
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
				'no_score'  => array(
					'key'     => \SWPS_AEO_Scorer::META_TOTAL,
					'compare' => 'NOT EXISTS',
				),
			);
			$query_args['orderby']    = array(
				'has_score' => 'DESC',
				'date'      => 'DESC',
			);
			unset( $query_args['order'] );
		}

		$query = new WP_Query( $query_args );

		$out = array();
		foreach ( $query->posts as $pid ) {
			$post = get_post( (int) $pid );
			if ( ! $post ) {
				continue;
			}
			$raw_score = get_post_meta( $post->ID, \SWPS_AEO_Scorer::META_TOTAL, true );
			$out[]     = array(
				'post_id'   => (int) $post->ID,
				'title'     => (string) get_the_title( $post ),
				'url'       => (string) get_permalink( $post ),
				'post_date' => (string) $post->post_date,
				'aeo_score' => '' !== $raw_score ? (int) $raw_score : null,
			);
		}
		return $out;
	}

	/**
	 * Top URIs returning 404 to AI bots.
	 *
	 * @return array<int, array{request_uri:string, hits:int}>
	 */
	public function get_top_404s( int $days = 30, int $limit = 10 ): array {
		global $wpdb;
		$raw   = $wpdb->prefix . self::RAW_TABLE;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$limit = max( 1, min( 50, $limit ) );

		// 404s with full URI live in the raw table; the daily table aggregates by post_id
		// which loses URI fidelity for not-found URLs (which have no post). That's fine —
		// 404s are higher-signal in the recent (raw) window anyway.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT request_uri, COUNT(*) AS hits
                 FROM {$raw}
                 WHERE status_code = 404 AND created_at >= %s
                 GROUP BY request_uri
                 ORDER BY hits DESC
                 LIMIT %d",
				$since,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'request_uri' => (string) $row['request_uri'],
				'hits'        => (int) $row['hits'],
			);
		}
		return $out;
	}

	/**
	 * Per-post bot stats (for the post-edit metabox extension).
	 *
	 * @return array{hits_7d:int, hits_30d:int, last_seen:?string}
	 */
	public function get_post_stats( int $post_id ): array {
		global $wpdb;
		$raw   = $wpdb->prefix . self::RAW_TABLE;
		$daily = $wpdb->prefix . self::DAILY_TABLE;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hits_7d = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(hits) FROM (
                    SELECT 1 AS hits FROM {$raw}
                    WHERE post_id = %d AND DATE(created_at) >= %s
                    UNION ALL
                    SELECT hits FROM {$daily}
                    WHERE post_id = %d AND date >= %s
                ) c",
				$post_id,
				gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
				$post_id,
				gmdate( 'Y-m-d', strtotime( '-7 days' ) )
			)
		);

		$hits_30d = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(hits) FROM (
                    SELECT 1 AS hits FROM {$raw}
                    WHERE post_id = %d AND DATE(created_at) >= %s
                    UNION ALL
                    SELECT hits FROM {$daily}
                    WHERE post_id = %d AND date >= %s
                ) c",
				$post_id,
				gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
				$post_id,
				gmdate( 'Y-m-d', strtotime( '-30 days' ) )
			)
		);

		$last = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(created_at) FROM {$raw} WHERE post_id = %d",
				$post_id
			)
		);
        // phpcs:enable

		return array(
			'hits_7d'   => $hits_7d,
			'hits_30d'  => $hits_30d,
			'last_seen' => $last ? (string) $last : null,
		);
	}
}
