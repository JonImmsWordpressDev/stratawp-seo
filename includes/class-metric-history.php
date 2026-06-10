<?php
/**
 * Generic per-post metric history table.
 *
 * Stores one row per (post_id, metric, date) — suitable for gsc_clicks,
 * gsc_impressions, gsc_position, aeo_score, and future metrics.
 *
 * DDL:
 *   CREATE TABLE {prefix}swps_metric_history (
 *     id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 *     post_id BIGINT UNSIGNED NOT NULL,
 *     metric  VARCHAR(32)    NOT NULL,
 *     date    DATE           NOT NULL,
 *     value   FLOAT          NOT NULL DEFAULT 0,
 *     PRIMARY KEY (id),
 *     UNIQUE KEY idx_post_metric_date (post_id, metric, date),
 *     KEY idx_post_metric (post_id, metric)
 *   );
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence layer for swps_metric_history rows.
 */
class SWPS_Metric_History {

	/** Schema version — bump when DDL changes. */
	public const DB_VERSION = '1';

	/** Option key used to gate the schema upgrade. */
	public const OPT_DB_VER = 'swps_metric_history_db_version';

	/** Table name (without $wpdb->prefix). */
	private const TABLE = 'swps_metric_history';

	/**
	 * Create (or upgrade) the metric history table.
	 * Called on activation and via maybe_upgrade().
	 */
	public static function create_table(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . self::TABLE;

		$sql = "CREATE TABLE {$table} (
			id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			metric  VARCHAR(32) NOT NULL,
			date    DATE NOT NULL,
			value   FLOAT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY idx_post_metric_date (post_id, metric, date),
			KEY idx_post_metric (post_id, metric)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Ensure the table exists for installs updated without reactivation.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::OPT_DB_VER ) ) {
			self::create_table();
			update_option( self::OPT_DB_VER, self::DB_VERSION );
		}
	}

	/**
	 * Insert or update a single metric value for a given post and date.
	 *
	 * Re-running for the same (post_id, metric, date) overwrites the value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $metric  Metric name (e.g. 'gsc_clicks').
	 * @param string $date    Date string 'Y-m-d'.
	 * @param float  $value   Metric value.
	 */
	public function record( int $post_id, string $metric, string $date, float $value ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (post_id, metric, date, value)
				 VALUES (%d, %s, %s, %f)
				 ON DUPLICATE KEY UPDATE value = VALUES(value)",
				$post_id,
				$metric,
				$date,
				$value
			)
		);
		// phpcs:enable
	}

	/**
	 * Upsert multiple rows in a single query for efficiency.
	 *
	 * @param array $rows Each element: [post_id (int), metric (string), date (string), value (float)].
	 */
	public function bulk_record( array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}

		global $wpdb;

		$table        = $wpdb->prefix . self::TABLE;
		$placeholders = array();
		$values       = array();

		foreach ( $rows as $row ) {
			$placeholders[] = '(%d, %s, %s, %f)';
			$values[]       = (int) $row[0];
			$values[]       = (string) $row[1];
			$values[]       = (string) $row[2];
			$values[]       = (float) $row[3];
		}

		$sql = "INSERT INTO {$table} (post_id, metric, date, value) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE value = VALUES(value)';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable
	}

	/**
	 * Return an ordered time-series array for a given post and metric.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $metric  Metric name.
	 * @param int    $days    How many days back to look (from today).
	 * @return array<int, array{date: string, value: float}> Ascending date order.
	 */
	public function series( int $post_id, string $metric, int $days ): array {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$cutoff = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, value
				 FROM {$table}
				 WHERE post_id = %d AND metric = %s AND date >= %s
				 ORDER BY date ASC",
				$post_id,
				$metric,
				$cutoff
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'date'  => $row['date'],
					'value' => (float) $row['value'],
				);
			},
			$rows
		);
	}

	/**
	 * Delete rows older than the given number of days.
	 *
	 * Called by the decay watchdog cron after bulk_record to keep the table lean.
	 *
	 * @param int $days Rows older than this many days are removed (e.g. 400).
	 */
	public function prune( int $days ): void {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$cutoff = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE date < %s",
				$cutoff
			)
		);
		// phpcs:enable
	}
}
