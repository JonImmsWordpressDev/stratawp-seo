<?php
/**
 * AI Visibility Funnel — shared query layer.
 *
 * Provides three batched, prepared query methods consumed by the four
 * surfaces that show crawl ↔ AI-visit data: the bot-analytics gap report,
 * the AEO queue, the AEO editor panel, and the dashboard funnel tile.
 *
 * No new tables, no cron.  All reads are batched (IN (...)) or site-wide
 * aggregates; all results are null-safe.  The dashboard tile is transient-
 * cached for 15 minutes.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batched queries joining crawl data and AI-visit data for the funnel surfaces.
 */
class SWPS_Visibility_Funnel {

	private const TRANSIENT_FUNNEL = 'swps_funnel_tile';
	private const FUNNEL_TTL       = 900; // 15 minutes.

	// -------------------------------------------------------------------------
	// Pure helper — no DB, no WP (testable in isolation)
	// -------------------------------------------------------------------------

	/**
	 * Convert raw funnel counts into per-stage rates.
	 *
	 * Each stage's `pct` is expressed as a percentage of the *previous* stage
	 * (i.e. the conversion from one step to the next), division-safe and
	 * clamped to 0–100.  Stage 0 (published) is always 100 % of itself.
	 *
	 * @param int $published Total published posts.
	 * @param int $crawled   Posts with ≥1 bot hit in the window.
	 * @param int $visited   Posts with ≥1 AI visit in the window.
	 * @return array<int, array{label:string, count:int, pct:int}>  Three-element array.
	 */
	public static function stage_rates( int $published, int $crawled, int $visited ): array {
		$crawled_pct = $published > 0
			? min( 100, (int) round( ( $crawled / $published ) * 100 ) )
			: 0;

		$visited_pct = $crawled > 0
			? min( 100, (int) round( ( $visited / $crawled ) * 100 ) )
			: 0;

		// If published is zero the whole funnel is meaningless — zero everything.
		if ( 0 === $published ) {
			$crawled_pct = 0;
			$visited_pct = 0;
		}

		return array(
			array(
				'label' => __( 'Published', 'stratawp-seo' ),
				'count' => $published,
				'pct'   => $published > 0 ? 100 : 0,
			),
			array(
				'label' => __( 'Crawled by AI (30d)', 'stratawp-seo' ),
				'count' => $crawled,
				'pct'   => $crawled_pct,
			),
			array(
				'label' => __( 'Received AI visits (30d)', 'stratawp-seo' ),
				'count' => $visited,
				'pct'   => $visited_pct,
			),
		);
	}

	// -------------------------------------------------------------------------
	// Batched query: crawl stats per post set
	// -------------------------------------------------------------------------

	/**
	 * Crawl statistics for a set of post IDs.
	 *
	 * Returns one row per post_id that had ≥1 bot hit in the window.
	 * Posts with no hits are absent (callers should default to zero).
	 *
	 * @param int[] $post_ids Non-empty array of post IDs.
	 * @param int   $days     Look-back window in days.
	 * @return array<int, array{post_id:int, hits:int, last_crawl_at:string|null, last_bot:string|null}>
	 *              Keyed by post_id for O(1) lookup.
	 */
	public static function crawl_stats_for_posts( array $post_ids, int $days ): array {
		if ( empty( $post_ids ) ) {
			return array();
		}

		global $wpdb;

		$bot_raw   = $wpdb->prefix . 'swps_bot_hits';
		$bot_daily = $wpdb->prefix . 'swps_bot_hits_daily';
		$since     = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$ids_int      = array_map( 'intval', $post_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids_int ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id,
				        SUM(hits)       AS hits,
				        MAX(last_seen)  AS last_crawl_at,
				        MAX(bot_key)    AS last_bot
				 FROM (
				     SELECT post_id, 1 AS hits,
				            created_at AS last_seen,
				            bot_key
				     FROM {$bot_raw}
				     WHERE DATE(created_at) >= %s
				       AND post_id IN ({$placeholders})
				     UNION ALL
				     SELECT post_id, hits, CONCAT(date, ' 00:00:00') AS last_seen, bot_key
				     FROM {$bot_daily}
				     WHERE date >= %s
				       AND post_id IN ({$placeholders})
				 ) c
				 GROUP BY post_id",
				array_merge( array( $since ), $ids_int, array( $since ), $ids_int )
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();
		foreach ( ( $rows ? $rows : array() ) as $row ) {
			$pid         = (int) $row['post_id'];
			$out[ $pid ] = array(
				'post_id'       => $pid,
				'hits'          => (int) $row['hits'],
				'last_crawl_at' => $row['last_crawl_at'] ? (string) $row['last_crawl_at'] : null,
				'last_bot'      => $row['last_bot'] ? (string) $row['last_bot'] : null,
			);
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Batched query: AI-visit stats per post set
	// -------------------------------------------------------------------------

	/**
	 * AI-visit counts for a set of post IDs.
	 *
	 * Combines the raw analytics table (last 7 days, not yet rolled up) with
	 * the swps_ai_referrals_daily rollup — mirroring SWPS_AI_Referrals_Report.
	 *
	 * @param int[] $post_ids Non-empty array of post IDs.
	 * @param int   $days     Look-back window in days.
	 * @return array<int, array{post_id:int, ai_visits:int}>  Keyed by post_id.
	 */
	public static function ai_visits_for_posts( array $post_ids, int $days ): array {
		if ( empty( $post_ids ) ) {
			return array();
		}

		global $wpdb;

		$raw    = $wpdb->prefix . 'swps_analytics';
		$rollup = $wpdb->prefix . 'swps_ai_referrals_daily';
		$since  = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$ids_int      = array_map( 'intval', $post_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids_int ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, SUM(views) AS ai_visits
				 FROM (
				     SELECT post_id, 1 AS views
				     FROM {$raw}
				     WHERE ai_source <> ''
				       AND DATE(created_at) >= %s
				       AND post_id IN ({$placeholders})
				     UNION ALL
				     SELECT post_id, views
				     FROM {$rollup}
				     WHERE date >= %s
				       AND post_id IN ({$placeholders})
				 ) c
				 GROUP BY post_id",
				array_merge( array( $since ), $ids_int, array( $since ), $ids_int )
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();
		foreach ( ( $rows ? $rows : array() ) as $row ) {
			$pid         = (int) $row['post_id'];
			$out[ $pid ] = array(
				'post_id'   => $pid,
				'ai_visits' => (int) $row['ai_visits'],
			);
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Site-wide funnel aggregate (dashboard tile)
	// -------------------------------------------------------------------------

	/**
	 * Site-wide two-stage funnel: published → crawled → AI-visited.
	 *
	 * Results are transient-cached for 15 minutes.
	 *
	 * @param int $days Look-back window in days.
	 * @return array{published:int, crawled:int, visited:int, stages: array}
	 */
	public static function site_funnel( int $days ): array {
		$cached = get_transient( self::TRANSIENT_FUNNEL );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$bot_raw   = $wpdb->prefix . 'swps_bot_hits';
		$bot_daily = $wpdb->prefix . 'swps_bot_hits_daily';
		$raw       = $wpdb->prefix . 'swps_analytics';
		$rollup    = $wpdb->prefix . 'swps_ai_referrals_daily';
		$since     = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// Count published posts (posts + pages).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- post count has no caching requirement; $wpdb->posts is safe.
		$published = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('post', 'page')"
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$crawled = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id)
				 FROM (
				     SELECT post_id FROM {$bot_raw}
				     WHERE DATE(created_at) >= %s AND post_id > 0
				     UNION
				     SELECT post_id FROM {$bot_daily}
				     WHERE date >= %s AND post_id > 0
				 ) c",
				$since,
				$since
			)
		);

		$visited = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id)
				 FROM (
				     SELECT post_id FROM {$raw}
				     WHERE ai_source <> '' AND DATE(created_at) >= %s AND post_id > 0
				     UNION
				     SELECT post_id FROM {$rollup}
				     WHERE date >= %s AND post_id > 0
				 ) c",
				$since,
				$since
			)
		);
		// phpcs:enable

		$result = array(
			'published' => $published,
			'crawled'   => $crawled,
			'visited'   => $visited,
			'stages'    => self::stage_rates( $published, $crawled, $visited ),
		);

		set_transient( self::TRANSIENT_FUNNEL, $result, self::FUNNEL_TTL );

		return $result;
	}
}
