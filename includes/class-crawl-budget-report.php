<?php
/**
 * SWPS_Crawl_Budget_Report — query helpers for the Crawl Budget report section.
 *
 * All methods here read from swps_bot_hits (raw, ≤7d) and swps_bot_hits_daily
 * (aggregated, up to retention). They are pure query helpers — no network I/O,
 * no cron hooks, no option writes.
 *
 * Derivable waste metrics (from what capture already stores):
 *  - 404 waste:     hits where status_code = 404 (broken URLs consuming crawl budget).
 *  - Redirect waste: hits where status_code IN (301, 302, 307, 308) (redirected pages).
 *  - Noindex waste:  not directly derivable from stored data alone (no noindex column);
 *    we report 404 + redirect waste and label it "waste (broken + redirect)".
 *
 * Compliance column derivation:
 *  - allowed_bots: keys returned by SWPS_AI_Bots::get_allowed_bots_public().
 *  - For each bot: verified_share = verified_hits / total_hits (from daily table).
 *  - Compliance status: allowed AND verified_share >= threshold → compliant.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only query layer for the Crawl Budget analytics section.
 */
class SWPS_Crawl_Budget_Report {

	private const RAW_TABLE   = 'swps_bot_hits';
	private const DAILY_TABLE = 'swps_bot_hits_daily';

	/**
	 * Minimum days of daily data required before surfacing the "not-crawled-in-90d"
	 * sitemap gap report (insufficient data guard).
	 */
	private const MIN_DATA_DAYS = 30;

	/**
	 * Look-back days for the not-crawled report.
	 */
	private const NOT_CRAWLED_DAYS = 90;

	/**
	 * Verified share threshold for marking a bot "compliant" in the compliance column.
	 */
	private const COMPLIANCE_VERIFIED_THRESHOLD = 0.50;

	/**
	 * How many days to look back for the verified-hits-per-bot-per-post-type query.
	 */
	private const BUDGET_WINDOW_DAYS = 30;

	/**
	 * Reconciliation option key — stores yesterday's findings for smoothing.
	 *
	 * Shape: array{ date: string, findings: array{ disallowed_crawling: string[],
	 *   spoofed_bots: string[] } }
	 */
	public const OPT_RECONCILE_PREV = 'swps_crawler_reconcile_prev';

	/**
	 * Option key for the 2-consecutive-day smoothed findings (drives the admin notice).
	 *
	 * Shape: array{ disallowed_crawling: string[], spoofed_bots: string[] }
	 */
	public const OPT_RECONCILE_FINDINGS = 'swps_crawler_reconcile_findings';

	/**
	 * Verified hits per bot, broken down by post type (for the budget section).
	 *
	 * Returns rows: bot_key, post_type, verified_hits, total_hits, waste_hits
	 * (waste = 404 + redirect status codes) over the last BUDGET_WINDOW_DAYS days.
	 *
	 * @param int $days Look-back window in days (default: BUDGET_WINDOW_DAYS).
	 * @return array<int, array{bot_key:string, post_type:string, verified_hits:int, total_hits:int, waste_hits:int}>
	 */
	public function get_budget_by_post_type( int $days = self::BUDGET_WINDOW_DAYS ): array {
		global $wpdb;

		$raw   = $wpdb->prefix . self::RAW_TABLE;
		$daily = $wpdb->prefix . self::DAILY_TABLE;
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.bot_key,
				        COALESCE(p.post_type, 'unknown') AS post_type,
				        SUM(c.verified_hits) AS verified_hits,
				        SUM(c.hits)          AS total_hits,
				        SUM(c.waste_hits)    AS waste_hits
				 FROM (
				     /* raw table: individual hits, last 7 days */
				     SELECT bot_key,
				            post_id,
				            1 AS hits,
				            CASE WHEN verified = 'verified' THEN 1 ELSE 0 END AS verified_hits,
				            CASE WHEN status_code = 404
				                   OR status_code IN (301,302,307,308) THEN 1 ELSE 0 END AS waste_hits
				     FROM {$raw}
				     WHERE DATE(created_at) >= %s
				     UNION ALL
				     /* daily table: aggregated older data */
				     SELECT bot_key,
				            post_id,
				            hits,
				            verified_hits,
				            errors_404 AS waste_hits
				     FROM {$daily}
				     WHERE date >= %s
				 ) c
				 LEFT JOIN {$wpdb->posts} p ON p.ID = c.post_id AND c.post_id > 0
				 GROUP BY c.bot_key, COALESCE(p.post_type, 'unknown')
				 ORDER BY SUM(c.hits) DESC",
				$since,
				$since
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'bot_key'       => (string) $row['bot_key'],
				'post_type'     => (string) $row['post_type'],
				'verified_hits' => (int) $row['verified_hits'],
				'total_hits'    => (int) $row['total_hits'],
				'waste_hits'    => (int) $row['waste_hits'],
			);
		}
		return $out;
	}

	/**
	 * Posts in the sitemap that Googlebot has NOT crawled in the last 90 days.
	 *
	 * Returns an empty array with a 'reason' key set to 'insufficient_data' when
	 * the daily table contains fewer than MIN_DATA_DAYS of rows for googlebot.
	 *
	 * @param int $limit Maximum number of posts to return (1–100).
	 * @return array{rows: array<int, array{post_id:int, title:string, url:string, post_type:string}>, reason: string}
	 */
	public function get_sitemap_not_crawled_by_googlebot( int $limit = 25 ): array {
		global $wpdb;

		$raw   = $wpdb->prefix . self::RAW_TABLE;
		$daily = $wpdb->prefix . self::DAILY_TABLE;
		$limit = max( 1, min( 100, $limit ) );
		$since = gmdate( 'Y-m-d', strtotime( '-' . self::NOT_CRAWLED_DAYS . ' days' ) );

		// Insufficient-data guard: need at least MIN_DATA_DAYS distinct daily dates
		// in the database for googlebot.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$data_days = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT all_dates.d) FROM (
				     SELECT DATE(created_at) AS d
				     FROM {$raw}
				     WHERE bot_key = 'googlebot' AND DATE(created_at) >= %s
				     UNION
				     SELECT date AS d
				     FROM {$daily}
				     WHERE bot_key = 'googlebot' AND date >= %s
				 ) all_dates",
				$since,
				$since
			)
		);

		if ( $data_days < self::MIN_DATA_DAYS ) {
			return array(
				'rows'   => array(),
				'reason' => 'insufficient_data',
			);
		}

		// Posts crawled by googlebot in the window.
		$crawled_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM (
				     SELECT post_id FROM {$raw}
				     WHERE bot_key = 'googlebot' AND post_id > 0 AND DATE(created_at) >= %s
				     UNION
				     SELECT post_id FROM {$daily}
				     WHERE bot_key = 'googlebot' AND post_id > 0 AND date >= %s
				 ) c",
				$since,
				$since
			)
		);
		// phpcs:enable

		$exclude = array_map( 'intval', (array) $crawled_ids );

		// Sitemap-registered public post types.
		$sitemap_post_types = $this->get_sitemap_post_types();

		$query_args = array(
			'post_type'      => $sitemap_post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'post__not_in'   => empty( $exclude ) ? array( 0 ) : $exclude,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		$query = new WP_Query( $query_args );

		$out = array();
		foreach ( $query->posts as $pid ) {
			$post = get_post( (int) $pid );
			if ( ! $post ) {
				continue;
			}
			$out[] = array(
				'post_id'   => (int) $post->ID,
				'title'     => (string) get_the_title( $post ),
				'url'       => (string) get_permalink( $post ),
				'post_type' => (string) $post->post_type,
			);
		}

		return array(
			'rows'   => $out,
			'reason' => '',
		);
	}

	/**
	 * Per-bot compliance summary for the AI Crawlers table.
	 *
	 * Returns one row per observed bot with:
	 *  - total_hits: combined raw + daily hits in the window
	 *  - verified_hits: sum of verified_hits from daily table
	 *  - verified_pct: percentage of hits that were CIDR-verified
	 *  - is_allowed: true when the bot key appears in swps_ai_bots_allowed
	 *  - compliance: 'compliant' | 'warning' | 'blocked' | 'unknown'
	 *    compliant   = allowed + verified_pct >= 50%
	 *    warning     = allowed + verified_pct < 50% (spoof suspicion)
	 *    blocked     = disallowed (user opted out)
	 *    unknown     = no verification data (unverifiable path only)
	 *
	 * @param int $days Look-back window in days (default: BUDGET_WINDOW_DAYS).
	 * @return array<string, array{total_hits:int, verified_hits:int, verified_pct:float, is_allowed:bool, compliance:string}>
	 */
	public function get_compliance_summary( int $days = self::BUDGET_WINDOW_DAYS ): array {
		global $wpdb;

		$raw   = $wpdb->prefix . self::RAW_TABLE;
		$daily = $wpdb->prefix . self::DAILY_TABLE;
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.bot_key,
				        SUM(c.hits)          AS total_hits,
				        SUM(c.verified_hits) AS verified_hits
				 FROM (
				     SELECT bot_key, 1 AS hits,
				            CASE WHEN verified = 'verified' THEN 1 ELSE 0 END AS verified_hits
				     FROM {$raw}
				     WHERE DATE(created_at) >= %s
				     UNION ALL
				     SELECT bot_key, hits, verified_hits
				     FROM {$daily}
				     WHERE date >= %s
				 ) c
				 GROUP BY c.bot_key",
				$since,
				$since
			),
			ARRAY_A
		);
		// phpcs:enable

		$allowed_bots = $this->get_allowed_bot_keys();
		$blocked_bots = $this->get_blocked_bot_keys();

		$out = array();
		foreach ( (array) $rows as $row ) {
			$key          = (string) $row['bot_key'];
			$total        = (int) $row['total_hits'];
			$verified     = (int) $row['verified_hits'];
			$verified_pct = $total > 0 ? round( ( $verified / $total ) * 100, 1 ) : 0.0;
			$is_allowed   = in_array( $key, $allowed_bots, true );
			$is_blocked   = in_array( $key, $blocked_bots, true );

			if ( $is_blocked ) {
				$compliance = 'blocked';
			} elseif ( 0 === $verified && $total > 0 ) {
				$compliance = 'unknown';
			} elseif ( $is_allowed && $verified_pct >= ( self::COMPLIANCE_VERIFIED_THRESHOLD * 100 ) ) {
				$compliance = 'compliant';
			} elseif ( $verified_pct < ( self::COMPLIANCE_VERIFIED_THRESHOLD * 100 ) && $total > 0 ) {
				$compliance = 'warning';
			} else {
				$compliance = 'unknown';
			}

			$out[ $key ] = array(
				'total_hits'    => $total,
				'verified_hits' => $verified,
				'verified_pct'  => $verified_pct,
				'is_allowed'    => $is_allowed,
				'compliance'    => $compliance,
			);
		}

		return $out;
	}

	/**
	 * Data for the daily reconciliation cron.
	 *
	 * Returns yesterday's aggregated hits by bot_key, including:
	 *  - total_hits
	 *  - verified_hits (spoofed = total - verified when ranges exist)
	 *  - spoofed_hits (status from raw; daily table does not store spoofed col — derive)
	 *
	 * Uses yesterday's date to match what aggregate_and_prune() would have processed.
	 *
	 * @return array<string, array{total_hits:int, verified_hits:int, spoofed_hits:int}>
	 */
	public function get_yesterday_bot_stats(): array {
		global $wpdb;

		$raw       = $wpdb->prefix . self::RAW_TABLE;
		$daily     = $wpdb->prefix . self::DAILY_TABLE;
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.bot_key,
				        SUM(c.hits)          AS total_hits,
				        SUM(c.verified_hits) AS verified_hits,
				        SUM(c.spoofed_hits)  AS spoofed_hits
				 FROM (
				     /* From raw (hits not yet rolled up — same-day rows) */
				     SELECT bot_key, 1 AS hits,
				            CASE WHEN verified = 'verified' THEN 1 ELSE 0 END AS verified_hits,
				            CASE WHEN verified = 'spoofed'  THEN 1 ELSE 0 END AS spoofed_hits
				     FROM {$raw}
				     WHERE DATE(created_at) = %s
				     UNION ALL
				     /* From daily (rolled-up rows for yesterday) */
				     SELECT bot_key, hits, verified_hits,
				            0 AS spoofed_hits
				     FROM {$daily}
				     WHERE date = %s
				 ) c
				 GROUP BY c.bot_key",
				$yesterday,
				$yesterday
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['bot_key'] ] = array(
				'total_hits'    => (int) $row['total_hits'],
				'verified_hits' => (int) $row['verified_hits'],
				'spoofed_hits'  => (int) $row['spoofed_hits'],
			);
		}
		return $out;
	}

	/**
	 * How many distinct calendar dates of daily data exist for any bot.
	 * Used by the reconciliation cron to decide if there is enough data.
	 */
	public function count_daily_data_days(): int {
		global $wpdb;

		$daily = $wpdb->prefix . self::DAILY_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT date) FROM {$daily}" );
	}

	/**
	 * Return post types registered in the StrataWP sitemap (or fallback to post/page).
	 *
	 * @return string[]
	 */
	private function get_sitemap_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		$types = array_values( array_diff( (array) $types, array( 'attachment' ) ) );

		/**
		 * Filter the post types included in the sitemap-not-crawled report.
		 * Mirrors what SWPS_Sitemap_Manager considers for its XML sitemap.
		 *
		 * @param string[] $types Public post types minus 'attachment'.
		 */
		return (array) apply_filters( 'swps_crawl_budget_sitemap_post_types', $types );
	}

	/**
	 * Bot keys the user has explicitly allowed (opt-in).
	 *
	 * @return string[]
	 */
	private function get_allowed_bot_keys(): array {
		$stored = get_option( 'swps_ai_bots_allowed', null );
		if ( null === $stored ) {
			// Default: all known AI bots allowed.
			return array_keys( SWPS_AI_Bots::KNOWN_BOTS );
		}
		return array_keys( array_filter( (array) $stored ) );
	}

	/**
	 * Bot keys the user has explicitly blocked (opt-out).
	 *
	 * @return string[]
	 */
	private function get_blocked_bot_keys(): array {
		$stored = get_option( 'swps_ai_bots_allowed', null );
		if ( null === $stored ) {
			return array();
		}
		$all     = array_keys( SWPS_AI_Bots::KNOWN_BOTS );
		$allowed = array_keys( array_filter( (array) $stored ) );
		return array_values( array_diff( $all, $allowed ) );
	}
}
