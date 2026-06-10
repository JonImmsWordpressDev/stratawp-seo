<?php
/**
 * AI Referral Attribution — reporting queries.
 *
 * Read-side companion to SWPS_AI_Referrals. Every reader combines the raw
 * analytics table's last-7-days rows (not yet rolled up) with the
 * swps_ai_referrals_daily rollup via UNION ALL, mirroring how
 * SWPS_Analytics_Tracker::get_daily_stats() combines raw + daily.
 *
 * Averages are always computed at read time from summed totals
 * (total_time / views), never stored — avoiding the daily table's
 * compounding-average bug.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reporting queries for AI referral attribution: per-engine summary,
 * landing posts, engagement vs organic, and the crawl-to-visit funnel.
 */
class SWPS_AI_Referrals_Report {

	/**
	 * Per-source daily views over a period, with totals and a
	 * previous-period delta.
	 *
	 * @param int $days Number of days to look back.
	 * @return array{daily: array, by_source: array, total_views: int, prev_views: int, delta_pct: float|null}
	 */
	public static function get_summary( int $days ): array {
		global $wpdb;

		$raw        = $wpdb->prefix . 'swps_analytics';
		$rollup     = $wpdb->prefix . 'swps_ai_referrals_daily';
		$since      = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$prev_since = gmdate( 'Y-m-d', strtotime( '-' . ( $days * 2 ) . ' days' ) );

		// One query covers both periods; split in PHP.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, ai_source, SUM(views) AS views FROM (
                     SELECT DATE(created_at) AS date, ai_source, 1 AS views
                     FROM {$raw}
                     WHERE ai_source <> '' AND DATE(created_at) >= %s
                     UNION ALL
                     SELECT date, ai_source, views
                     FROM {$rollup}
                     WHERE date >= %s
                 ) c
                 GROUP BY date, ai_source
                 ORDER BY date ASC",
				$prev_since,
				$prev_since
			),
			ARRAY_A
		);
		$rows = $rows ? $rows : array();
        // phpcs:enable

		$daily       = array();
		$by_source   = array();
		$total_views = 0;
		$prev_views  = 0;

		foreach ( $rows as $row ) {
			$views = (int) $row['views'];

			if ( $row['date'] >= $since ) {
				$daily[]      = array(
					'date'      => $row['date'],
					'ai_source' => $row['ai_source'],
					'views'     => $views,
				);
				$total_views += $views;

				if ( ! isset( $by_source[ $row['ai_source'] ] ) ) {
					$by_source[ $row['ai_source'] ] = 0;
				}
				$by_source[ $row['ai_source'] ] += $views;
			} else {
				$prev_views += $views;
			}
		}

		arsort( $by_source );

		return array(
			'daily'       => $daily,
			'by_source'   => $by_source,
			'total_views' => $total_views,
			'prev_views'  => $prev_views,
			'delta_pct'   => $prev_views > 0
				? round( ( ( $total_views - $prev_views ) / $prev_views ) * 100, 1 )
				: null,
		);
	}

	/**
	 * Top landing posts for AI-referred visits.
	 *
	 * Averages computed at read time from summed totals.
	 *
	 * @param int $days  Number of days to look back.
	 * @param int $limit Max posts.
	 * @return array<int, array> Rows: post_id, views, avg_time, avg_scroll, bounce_rate, ai_sources.
	 */
	public static function get_landing_posts( int $days, int $limit = 10 ): array {
		global $wpdb;

		$raw    = $wpdb->prefix . 'swps_analytics';
		$rollup = $wpdb->prefix . 'swps_ai_referrals_daily';
		$since  = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id,
                        SUM(views) AS views,
                        SUM(total_time) AS total_time,
                        SUM(total_scroll) AS total_scroll,
                        SUM(bounces) AS bounces,
                        GROUP_CONCAT(DISTINCT ai_source ORDER BY ai_source) AS ai_sources
                 FROM (
                     SELECT post_id, ai_source, 1 AS views, time_on_page AS total_time,
                            scroll_depth AS total_scroll, is_bounce AS bounces
                     FROM {$raw}
                     WHERE ai_source <> '' AND DATE(created_at) >= %s AND post_id > 0
                     UNION ALL
                     SELECT post_id, ai_source, views, total_time, total_scroll, bounces
                     FROM {$rollup}
                     WHERE date >= %s AND post_id > 0
                 ) c
                 GROUP BY post_id
                 ORDER BY views DESC
                 LIMIT %d",
				$since,
				$since,
				$limit
			),
			ARRAY_A
		);
		$rows = $rows ? $rows : array();
        // phpcs:enable

		$out = array();

		foreach ( $rows as $row ) {
			$views = (int) $row['views'];
			$out[] = array(
				'post_id'     => (int) $row['post_id'],
				'views'       => $views,
				'avg_time'    => $views > 0 ? (int) round( $row['total_time'] / $views ) : 0,
				'avg_scroll'  => $views > 0 ? (int) round( $row['total_scroll'] / $views ) : 0,
				'bounce_rate' => $views > 0 ? (int) round( ( $row['bounces'] / $views ) * 100 ) : 0,
				'ai_sources'  => $row['ai_sources'],
			);
		}

		return $out;
	}

	/**
	 * AI vs organic engagement comparison over a period.
	 *
	 * AI aggregate comes from raw + the AI rollup (exact sums). The all-traffic
	 * aggregate comes from raw + the analytics daily table (daily stores
	 * per-day averages, so its time/scroll contributions are weighted by views
	 * — an approximation). Organic = all − AI.
	 *
	 * @param int $days  Number of days to look back.
	 * @param int $min_n Minimum sample size per group.
	 * @return array|null Ratio metrics, or null when either sample is too small.
	 */
	public static function get_engagement_vs_organic( int $days, int $min_n = 30 ): ?array {
		global $wpdb;

		$raw    = $wpdb->prefix . 'swps_analytics';
		$rollup = $wpdb->prefix . 'swps_ai_referrals_daily';
		$daily  = $wpdb->prefix . 'swps_analytics_daily';
		$since  = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ai = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(views) AS views, SUM(total_time) AS total_time,
                        SUM(total_scroll) AS total_scroll, SUM(bounces) AS bounces
                 FROM (
                     SELECT 1 AS views, time_on_page AS total_time,
                            scroll_depth AS total_scroll, is_bounce AS bounces
                     FROM {$raw}
                     WHERE ai_source <> '' AND DATE(created_at) >= %s
                     UNION ALL
                     SELECT views, total_time, total_scroll, bounces
                     FROM {$rollup}
                     WHERE date >= %s
                 ) c",
				$since,
				$since
			),
			ARRAY_A
		);

		$all = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(views) AS views, SUM(total_time) AS total_time,
                        SUM(total_scroll) AS total_scroll, SUM(bounces) AS bounces
                 FROM (
                     SELECT 1 AS views, time_on_page AS total_time,
                            scroll_depth AS total_scroll, is_bounce AS bounces
                     FROM {$raw}
                     WHERE DATE(created_at) >= %s
                     UNION ALL
                     SELECT views, views * avg_time_on_page AS total_time,
                            views * avg_scroll_depth AS total_scroll, bounces
                     FROM {$daily}
                     WHERE date >= %s
                 ) c",
				$since,
				$since
			),
			ARRAY_A
		);
        // phpcs:enable

		$ai_views  = (int) ( $ai['views'] ?? 0 );
		$all_views = (int) ( $all['views'] ?? 0 );
		$org_views = max( 0, $all_views - $ai_views );

		$org_time   = max( 0, (int) ( $all['total_time'] ?? 0 ) - (int) ( $ai['total_time'] ?? 0 ) );
		$org_scroll = max( 0, (int) ( $all['total_scroll'] ?? 0 ) - (int) ( $ai['total_scroll'] ?? 0 ) );
		$org_bounce = max( 0, (int) ( $all['bounces'] ?? 0 ) - (int) ( $ai['bounces'] ?? 0 ) );

		$ai_agg = array(
			'views'       => $ai_views,
			'avg_time'    => $ai_views > 0 ? $ai['total_time'] / $ai_views : 0,
			'avg_scroll'  => $ai_views > 0 ? $ai['total_scroll'] / $ai_views : 0,
			'bounce_rate' => $ai_views > 0 ? $ai['bounces'] / $ai_views : 0,
		);

		$org_agg = array(
			'views'       => $org_views,
			'avg_time'    => $org_views > 0 ? $org_time / $org_views : 0,
			'avg_scroll'  => $org_views > 0 ? $org_scroll / $org_views : 0,
			'bounce_rate' => $org_views > 0 ? $org_bounce / $org_views : 0,
		);

		$comparison = SWPS_AI_Referrals::engagement_comparison( $ai_agg, $org_agg, $min_n );

		if ( null === $comparison ) {
			return null;
		}

		// Add display-friendly absolutes alongside the ratios.
		$comparison['ai']      = array(
			'avg_time'    => (int) round( $ai_agg['avg_time'] ),
			'avg_scroll'  => (int) round( $ai_agg['avg_scroll'] ),
			'bounce_rate' => (int) round( $ai_agg['bounce_rate'] * 100 ),
		);
		$comparison['organic'] = array(
			'avg_time'    => (int) round( $org_agg['avg_time'] ),
			'avg_scroll'  => (int) round( $org_agg['avg_scroll'] ),
			'bounce_rate' => (int) round( $org_agg['bounce_rate'] * 100 ),
		);

		return $comparison;
	}

	/**
	 * Crawl-to-visit funnel: AI bot crawls joined to AI-referred visits per
	 * post and source. Two batched queries joined in PHP — no N+1.
	 *
	 * @param int $days  Number of days to look back.
	 * @param int $limit Max funnel rows.
	 * @return array{rows: array, crawled_no_visits: array}
	 */
	public static function get_funnel( int $days, int $limit = 20 ): array {
		global $wpdb;

		$bot_raw   = $wpdb->prefix . 'swps_bot_hits';
		$bot_daily = $wpdb->prefix . 'swps_bot_hits_daily';
		$raw       = $wpdb->prefix . 'swps_analytics';
		$rollup    = $wpdb->prefix . 'swps_ai_referrals_daily';
		$since     = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$bot_map = SWPS_AI_Referrals::bot_source_map();

		// Query 1: crawls per (bot_key, post_id) — raw + daily, like the bot tracker readers.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$crawl_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot_key, post_id, SUM(hits) AS hits FROM (
                     SELECT bot_key, post_id, 1 AS hits
                     FROM {$bot_raw}
                     WHERE DATE(created_at) >= %s AND post_id > 0
                     UNION ALL
                     SELECT bot_key, post_id, hits
                     FROM {$bot_daily}
                     WHERE date >= %s AND post_id > 0
                 ) c
                 GROUP BY bot_key, post_id",
				$since,
				$since
			),
			ARRAY_A
		);

		// Query 2: AI-referred visits per (ai_source, post_id) — raw + rollup.
		$visit_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ai_source, post_id, SUM(views) AS views FROM (
                     SELECT ai_source, post_id, 1 AS views
                     FROM {$raw}
                     WHERE ai_source <> '' AND DATE(created_at) >= %s AND post_id > 0
                     UNION ALL
                     SELECT ai_source, post_id, views
                     FROM {$rollup}
                     WHERE date >= %s AND post_id > 0
                 ) c
                 GROUP BY ai_source, post_id",
				$since,
				$since
			),
			ARRAY_A
		);
        // phpcs:enable

		// Map crawls to (source, post_id), skipping bots with no visit-side counterpart.
		$crawls = array();
		foreach ( $crawl_rows ? $crawl_rows : array() as $row ) {
			$source = $bot_map[ $row['bot_key'] ] ?? '';
			if ( '' === $source ) {
				continue;
			}
			$key = $source . '|' . $row['post_id'];

			$crawls[ $key ] = ( $crawls[ $key ] ?? 0 ) + (int) $row['hits'];
		}

		$visits = array();
		foreach ( $visit_rows ? $visit_rows : array() as $row ) {
			$visits[ $row['ai_source'] . '|' . $row['post_id'] ] = (int) $row['views'];
		}

		$rows = array();
		foreach ( $crawls as $key => $crawl_count ) {
			list( $source, $post_id ) = explode( '|', $key, 2 );

			$rows[] = array(
				'post_id'   => (int) $post_id,
				'ai_source' => $source,
				'crawls'    => $crawl_count,
				'visits'    => $visits[ $key ] ?? 0,
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['crawls'] <=> $a['crawls'];
			}
		);

		$crawled_no_visits = array_values(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					return 0 === $row['visits'];
				}
			)
		);

		return array(
			'rows'              => array_slice( $rows, 0, $limit ),
			'crawled_no_visits' => array_slice( $crawled_no_visits, 0, $limit ),
		);
	}
}
