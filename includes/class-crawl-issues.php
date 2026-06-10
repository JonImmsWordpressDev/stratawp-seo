<?php
/**
 * Crawl Issues — issues table CRUD, run diffing, progress snapshot, and pruning.
 *
 * Provides static factory + query methods consumed by SWPS_Site_Crawler during
 * chunk processing and by SWPS_Site_Crawl_Audit during read-only rendering.
 *
 * No WordPress hook registrations live here — see SWPS_Site_Crawler for those.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues table access for site crawl runs.
 */
class SWPS_Crawl_Issues {

	/** Custom-table name (without prefix). */
	public const TABLE_ISSUES = 'swps_crawl_issues';
	public const TABLE_QUEUE  = 'swps_crawl_queue';

	/** Current schema version; bump whenever the DDL changes. */
	public const DB_VERSION = '1';

	/** Option key holding the installed schema version. */
	public const OPT_DB_VER = 'swps_crawl_db_version';

	/** Number of completed runs to retain before pruning. */
	private const RUNS_TO_KEEP = 5;

	// =========================================================================
	// DB SCHEMA
	// =========================================================================

	/**
	 * Create/upgrade the crawl tables when the stored schema version is stale.
	 *
	 * Runs at init so existing installs get the tables on plugin update
	 * without a deactivate/reactivate cycle (SWPS_Bot_Analytics_Tracker
	 * precedent).
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::OPT_DB_VER ) ) {
			self::create_tables();
			update_option( self::OPT_DB_VER, self::DB_VERSION );
		}
	}

	/**
	 * Create or upgrade crawl tables via dbDelta.
	 *
	 * Called on plugin activation and on DB-version mismatch at init.
	 * Safe to call multiple times (dbDelta is idempotent).
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$queue   = $wpdb->prefix . self::TABLE_QUEUE;
		$issues  = $wpdb->prefix . self::TABLE_ISSUES;

		$sql_queue = "CREATE TABLE {$queue} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id     BIGINT UNSIGNED NOT NULL,
			url_hash   VARCHAR(64)     NOT NULL DEFAULT '',
			url        VARCHAR(2083)   NOT NULL DEFAULT '',
			depth      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			status     VARCHAR(10)     NOT NULL DEFAULT 'pending',
			found_on   VARCHAR(2083)   NOT NULL DEFAULT '',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_run_hash (run_id, url_hash),
			KEY idx_run_status (run_id, status)
		) {$charset};";

		$sql_issues = "CREATE TABLE {$issues} (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id         BIGINT UNSIGNED NOT NULL,
			type           VARCHAR(50)     NOT NULL DEFAULT '',
			url            VARCHAR(2083)   NOT NULL DEFAULT '',
			detail         LONGTEXT        NOT NULL DEFAULT '',
			severity       VARCHAR(10)     NOT NULL DEFAULT 'warning',
			post_id        BIGINT UNSIGNED             DEFAULT NULL,
			first_seen_run BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY (id),
			KEY idx_run_id (run_id),
			KEY idx_type   (type)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_queue );
		dbDelta( $sql_issues );
	}

	// =========================================================================
	// WRITE HELPERS
	// =========================================================================

	/**
	 * Insert one issue row for the given run.
	 *
	 * Looks up (or creates) the first_seen_run value automatically.
	 *
	 * @param int      $run_id  Current run ID.
	 * @param string   $type    Issue type key (e.g. 'broken_link').
	 * @param string   $url     The URL that has the issue.
	 * @param array    $detail  Arbitrary details; JSON-encoded before storage.
	 * @param string   $severity 'error' | 'warning'.
	 * @param int|null $post_id WordPress post ID when the URL maps to a post.
	 */
	public static function insert_issue(
		int $run_id,
		string $type,
		string $url,
		array $detail,
		string $severity = 'warning',
		?int $post_id = null
	): void {
		global $wpdb;

		$first_seen = self::get_first_seen_run( $type, $url, $run_id );
		$table      = $wpdb->prefix . self::TABLE_ISSUES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'run_id'         => $run_id,
				'type'           => $type,
				'url'            => $url,
				'detail'         => wp_json_encode( $detail ),
				'severity'       => $severity,
				'post_id'        => $post_id,
				'first_seen_run' => $first_seen,
			),
			array( '%d', '%s', '%s', '%s', '%s', null === $post_id ? null : '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// =========================================================================
	// READ HELPERS
	// =========================================================================

	/**
	 * Get the first run in which an issue of this type + URL was seen.
	 *
	 * Returns $current_run_id when no prior run contains the issue (new this run).
	 *
	 * @param string $type    Issue type string.
	 * @param string $url     Issue URL.
	 * @param int    $run_id  Current run ID.
	 * @return int First-seen run ID.
	 */
	public static function get_first_seen_run( string $type, string $url, int $run_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_ISSUES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prior = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(first_seen_run) FROM {$table} WHERE type = %s AND url = %s AND run_id < %d",
				$type,
				$url,
				$run_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null !== $prior ? (int) $prior : $run_id;
	}

	/**
	 * Return a progress snapshot for the given run.
	 *
	 * @param int $run_id Target run ID.
	 * @return array{done:bool,crawled:int,queued:int,issues:int}
	 */
	public static function progress_snapshot( int $run_id ): array {
		global $wpdb;

		$queue_table  = $wpdb->prefix . self::TABLE_QUEUE;
		$issues_table = $wpdb->prefix . self::TABLE_ISSUES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$crawled = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$queue_table} WHERE run_id = %d AND status = 'done'",
				$run_id
			)
		);
		$queued  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$queue_table} WHERE run_id = %d AND status = 'pending'",
				$run_id
			)
		);
		$issues  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$issues_table} WHERE run_id = %d",
				$run_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'done'    => ( 0 === $queued ),
			'crawled' => $crawled,
			'queued'  => $queued,
			'issues'  => $issues,
		);
	}

	/**
	 * Return issue counts grouped by type for the given run.
	 *
	 * @param int $run_id Target run ID.
	 * @return array[] Rows of {type, cnt}.
	 */
	public static function issue_counts( int $run_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_ISSUES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, COUNT(*) AS cnt FROM {$table} WHERE run_id = %d GROUP BY type",
				$run_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows;
	}

	/**
	 * Return all issue rows for a run, detail JSON decoded, grouped by type.
	 *
	 * Each row gains an 'is_new' flag: true when first_seen_run equals the
	 * queried run (i.e. the issue was not present in any earlier run).
	 *
	 * @param int $run_id Target run ID.
	 * @return array<string, array[]> Issue rows keyed by type.
	 */
	public static function issues_for_run( int $run_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_ISSUES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, url, detail, severity, post_id, first_seen_run FROM {$table} WHERE run_id = %d ORDER BY type, id",
				$run_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$grouped = array();
		foreach ( $rows as $row ) {
			$detail                    = json_decode( (string) $row['detail'], true );
			$row['detail']             = is_array( $detail ) ? $detail : array();
			$row['is_new']             = (int) $row['first_seen_run'] === $run_id;
			$grouped[ $row['type'] ][] = $row;
		}

		return $grouped;
	}

	/**
	 * Return the crawled-URL count for the given run.
	 *
	 * @param int $run_id Target run ID.
	 * @return int
	 */
	public static function crawled_count( int $run_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_QUEUE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE run_id = %d AND status = 'done'",
				$run_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// =========================================================================
	// PRUNING
	// =========================================================================

	/**
	 * Remove queue + issue rows for runs older than the retention window.
	 *
	 * Keeps the RUNS_TO_KEEP most-recent completed runs.
	 *
	 * @param int $current_run_id The run just completed.
	 */
	public static function prune_old_runs( int $current_run_id ): void {
		global $wpdb;

		$queue_table  = $wpdb->prefix . self::TABLE_QUEUE;
		$issues_table = $wpdb->prefix . self::TABLE_ISSUES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old_runs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT run_id FROM {$queue_table} WHERE run_id < %d ORDER BY run_id DESC LIMIT 999 OFFSET %d",
				$current_run_id,
				self::RUNS_TO_KEEP - 1
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $old_runs ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $old_runs ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$queue_table} WHERE run_id IN ({$placeholders})", ...$old_runs ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$issues_table} WHERE run_id IN ({$placeholders})", ...$old_runs ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}
}
