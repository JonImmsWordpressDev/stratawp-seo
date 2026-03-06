<?php
/**
 * Keyword tracking — DB table, CRUD, GSC sync, AI suggestions.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Keyword_Tracker {

	private const TABLE     = 'swps_keyword_tracking';
	private const CRON_HOOK = 'swps_keyword_sync';

	private SWPS_Search_Console $search_console;

	public function __construct( SWPS_Search_Console $search_console ) {
		$this->search_console = $search_console;
		add_action( self::CRON_HOOK, [ $this, 'sync_from_gsc' ] );
	}

	/**
	 * Create the keyword tracking table. Called on activation.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . self::TABLE;

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword VARCHAR(255) NOT NULL,
			post_id BIGINT UNSIGNED DEFAULT NULL,
			position FLOAT DEFAULT NULL,
			clicks INT UNSIGNED NOT NULL DEFAULT 0,
			impressions INT UNSIGNED NOT NULL DEFAULT 0,
			ctr FLOAT NOT NULL DEFAULT 0,
			date DATE NOT NULL,
			PRIMARY KEY (id),
			KEY idx_keyword_date (keyword, date),
			KEY idx_post_id (post_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Schedule the keyword sync cron.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$frequency = get_option( 'swps_keyword_tracking_frequency', 'weekly' );
			wp_schedule_event( time(), $frequency, self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the keyword sync cron.
	 */
	public static function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Reschedule cron with new frequency (called when settings change).
	 */
	public static function reschedule_cron(): void {
		self::unschedule_cron();
		self::schedule_cron();
	}

	/**
	 * Track a keyword (add to tracking list).
	 *
	 * @param string $keyword Keyword text.
	 * @param int    $post_id Optional linked post ID.
	 * @return bool
	 */
	public function track_keyword( string $keyword, int $post_id = 0 ): bool {
		global $wpdb;

		$keyword = sanitize_text_field( strtolower( trim( $keyword ) ) );
		if ( empty( $keyword ) ) {
			return false;
		}

		// Check if already tracked.
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE . " WHERE keyword = %s LIMIT 1",
			$keyword
		) );

		if ( $exists ) {
			return true; // Already tracking.
		}

		return (bool) $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			[
				'keyword'     => $keyword,
				'post_id'     => $post_id ?: null,
				'position'    => null,
				'clicks'      => 0,
				'impressions' => 0,
				'ctr'         => 0,
				'date'        => gmdate( 'Y-m-d' ),
			],
			[ '%s', '%d', '%f', '%d', '%d', '%f', '%s' ]
		);
	}

	/**
	 * Untrack a keyword (remove all history).
	 *
	 * @param string $keyword Keyword text.
	 * @return bool
	 */
	public function untrack_keyword( string $keyword ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			$wpdb->prefix . self::TABLE,
			[ 'keyword' => strtolower( trim( $keyword ) ) ],
			[ '%s' ]
		);
	}

	/**
	 * Link a keyword to a post.
	 *
	 * @param string $keyword Keyword text.
	 * @param int    $post_id Post ID.
	 * @return bool
	 */
	public function link_to_post( string $keyword, int $post_id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			[ 'post_id' => $post_id ],
			[ 'keyword' => strtolower( trim( $keyword ) ) ],
			[ '%d' ],
			[ '%s' ]
		);
	}

	/**
	 * Get all tracked keywords with latest data.
	 *
	 * @param int $limit Max results.
	 * @return array
	 */
	public function get_tracked_keywords( int $limit = 100 ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// Get the most recent row per keyword.
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT t1.*
			 FROM {$table} t1
			 INNER JOIN (
				 SELECT keyword, MAX(date) as max_date
				 FROM {$table}
				 GROUP BY keyword
			 ) t2 ON t1.keyword = t2.keyword AND t1.date = t2.max_date
			 ORDER BY t1.impressions DESC
			 LIMIT %d",
			$limit
		), ARRAY_A );

		return $results ?: [];
	}

	/**
	 * Get position history for a specific keyword.
	 *
	 * @param string $keyword Keyword text.
	 * @param int    $days    Days of history.
	 * @return array
	 */
	public function get_keyword_history( string $keyword, int $days = 90 ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT date, position, clicks, impressions, ctr
			 FROM {$table}
			 WHERE keyword = %s AND date >= %s
			 ORDER BY date ASC",
			strtolower( trim( $keyword ) ),
			$since
		), ARRAY_A );

		return $results ?: [];
	}

	/**
	 * Get "striking distance" keyword opportunities (position 8-20, high impressions).
	 *
	 * @param int $limit Max results.
	 * @return array
	 */
	public function get_opportunities( int $limit = 20 ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT t1.*
			 FROM {$table} t1
			 INNER JOIN (
				 SELECT keyword, MAX(date) as max_date
				 FROM {$table}
				 GROUP BY keyword
			 ) t2 ON t1.keyword = t2.keyword AND t1.date = t2.max_date
			 WHERE t1.position BETWEEN 8 AND 20
			 AND t1.impressions > 0
			 ORDER BY t1.impressions DESC
			 LIMIT %d",
			$limit
		), ARRAY_A );

		return $results ?: [];
	}

	/**
	 * Sync tracked keywords with GSC data.
	 * Called by WP-Cron on configured schedule.
	 */
	public function sync_from_gsc(): void {
		if ( ! $this->search_console->is_connected() ) {
			return;
		}

		$tracked = $this->get_tracked_keywords( 500 );
		if ( empty( $tracked ) ) {
			return;
		}

		// Pull GSC query data for the last 7 days.
		$gsc_data = $this->search_console->get_search_data( 7 );
		$gsc_queries = [];

		foreach ( $gsc_data['queries'] ?? [] as $row ) {
			$query = strtolower( $row['keys'][0] ?? '' );
			if ( $query ) {
				$gsc_queries[ $query ] = $row;
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$today = gmdate( 'Y-m-d' );

		// Get unique tracked keywords.
		$keywords = array_unique( array_column( $tracked, 'keyword' ) );

		foreach ( $keywords as $keyword ) {
			$gsc_row = $gsc_queries[ $keyword ] ?? null;

			$wpdb->insert(
				$table,
				[
					'keyword'     => $keyword,
					'post_id'     => $this->find_post_for_keyword( $keyword, $tracked ),
					'position'    => $gsc_row ? round( $gsc_row['position'] ?? 0, 1 ) : null,
					'clicks'      => $gsc_row['clicks'] ?? 0,
					'impressions' => $gsc_row['impressions'] ?? 0,
					'ctr'         => $gsc_row ? round( ( $gsc_row['ctr'] ?? 0 ) * 100, 1 ) : 0,
					'date'        => $today,
				],
				[ '%s', '%d', '%f', '%d', '%d', '%f', '%s' ]
			);
		}
	}

	/**
	 * Generate keyword suggestions via AI.
	 *
	 * @param string $seed_topic Seed topic for suggestions.
	 * @return array|WP_Error Array of keyword suggestions or error.
	 */
	public function suggest_keywords( string $seed_topic ): array|WP_Error {
		$api   = SWPS_Provider_Factory::create_ai_provider();
		$niche = get_option( 'swps_site_niche', '' );
		$desc  = get_option( 'swps_site_description', '' );

		$prompt = sprintf(
			"You are an SEO keyword research expert. Generate 15 keyword suggestions for a website in the \"%s\" niche.\n\n"
			. "Site description: %s\n\n"
			. "Seed topic: %s\n\n"
			. "For each keyword, provide:\n"
			. "- keyword: the exact search phrase (lowercase)\n"
			. "- intent: informational, transactional, or navigational\n"
			. "- difficulty: low, medium, or high (estimate)\n"
			. "- suggested_title: a blog post title targeting this keyword\n\n"
			. "Return JSON array only. No markdown, no explanation.\n"
			. "Example: [{\"keyword\":\"best running shoes\",\"intent\":\"transactional\",\"difficulty\":\"high\",\"suggested_title\":\"10 Best Running Shoes for Every Budget in 2026\"}]",
			$niche,
			$desc,
			$seed_topic
		);

		$response = $api->generate( $prompt, 'You are an SEO keyword research expert. Return only valid JSON.' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $response['content'] ?? '';

		// Extract JSON from response.
		$json_match = [];
		if ( preg_match( '/\[.*\]/s', $text, $json_match ) ) {
			$suggestions = json_decode( $json_match[0], true );
			if ( is_array( $suggestions ) ) {
				return apply_filters( 'swps_keyword_suggestions', $suggestions, $seed_topic );
			}
		}

		return new WP_Error( 'swps_keyword_parse', __( 'Failed to parse keyword suggestions from AI response.', 'stratawp-seo' ) );
	}

	/**
	 * Find the linked post_id for a keyword from tracked data.
	 */
	private function find_post_for_keyword( string $keyword, array $tracked ): int {
		foreach ( $tracked as $row ) {
			if ( $row['keyword'] === $keyword && ! empty( $row['post_id'] ) ) {
				return (int) $row['post_id'];
			}
		}
		return 0;
	}

	/**
	 * Drop the keyword tracking table. Called on uninstall.
	 */
	public static function drop_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::TABLE );
	}
}
