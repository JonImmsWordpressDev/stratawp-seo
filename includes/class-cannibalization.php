<?php
/**
 * Keyword Cannibalization Detector — pure helpers (unit-tested, no WP), findings table, weekly cron.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cannibalization detection, exclusion classification, and weekly scan cron.
 */
class SWPS_Cannibalization {

	/** Table name (without prefix). */
	public const TABLE = 'swps_cannibalization';
	/** Current schema version; bump whenever the DDL changes. */
	public const DB_VERSION = '1';
	/** Option key holding the installed schema version. */
	public const OPT_DB_VER = 'swps_cannibalization_db_version';
	/** Cron hook for the weekly detection run. */
	public const CRON_HOOK = 'swps_cannibal_scan';
	/** Days of GSC data to pull per scan. */
	private const SCAN_DAYS = 90;
	/** Maximum rows to fetch from GSC per scan. */
	private const SCAN_ROW_LIMIT = 5000;
	/** Retention: drop dismissed/resolved rows older than this many days. */
	private const RETENTION_DAYS = 90;

	/**
	 * GSC client.
	 *
	 * @var SWPS_Search_Console
	 */
	private SWPS_Search_Console $search_console;

	/**
	 * Built-in position→CTR curve (industry averages; filterable via swps_cannibal_ctr_curve).
	 *
	 * @var float[]
	 */
	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing,WordPress.Arrays.MultipleStatementAlignment
	private const CTR_CURVE = array(
		1 => 0.285, 2 => 0.152, 3 => 0.108, 4 => 0.080, 5 => 0.062,
		6 => 0.050, 7 => 0.042, 8 => 0.036, 9 => 0.031, 10 => 0.027,
	);
	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing,WordPress.Arrays.MultipleStatementAlignment

	/**
	 * Wire cron action; schedule weekly if not already scheduled.
	 *
	 * @param SWPS_Search_Console $search_console GSC client.
	 */
	public function __construct( SWPS_Search_Console $search_console ) {
		$this->search_console = $search_console;
		add_action( self::CRON_HOOK, array( $this, 'run_scan' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
		}
	}

	/** Create/upgrade the table when the stored schema version is stale (runs at init, no reactivation needed). */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::OPT_DB_VER ) ) {
			self::create_table();
			update_option( self::OPT_DB_VER, self::DB_VERSION );
		}
	}

	/** Create or upgrade the findings table via dbDelta (idempotent). */
	public static function create_table(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . self::TABLE;

		$sql = "CREATE TABLE {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			query_hash  VARCHAR(64)     NOT NULL DEFAULT '',
			query       VARCHAR(255)    NOT NULL DEFAULT '',
			pages       LONGTEXT        NOT NULL DEFAULT '',
			severity    VARCHAR(10)     NOT NULL DEFAULT 'cannibal',
			status      VARCHAR(10)     NOT NULL DEFAULT 'open',
			undo_json   LONGTEXT                 DEFAULT NULL,
			detected_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_query_hash (query_hash),
			KEY idx_status (status),
			KEY idx_severity (severity)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Group GSC rows into candidate findings (queries where 2+ pages each hold ≥ 20 % impressions).
	 *
	 * @param  array<int, array<string, mixed>> $rows GSC query+page rows (keys[0]=query, keys[1]=page).
	 * @param  array                            $opts Options: min_impressions, min_pages, max_position, split_threshold.
	 * @return array<int, array{query: string, pages: array}>
	 */
	public static function find_candidates( array $rows, array $opts = array() ): array {
		$min_impressions = isset( $opts['min_impressions'] ) ? (int) $opts['min_impressions'] : 100;
		$min_pages       = isset( $opts['min_pages'] ) ? (int) $opts['min_pages'] : 2;
		$max_position    = isset( $opts['max_position'] ) ? (float) $opts['max_position'] : 30.0;
		$split_threshold = isset( $opts['split_threshold'] ) ? (float) $opts['split_threshold'] : 0.20;

		$by_query = array();
		foreach ( $rows as $row ) {
			$keys = $row['keys'] ?? array();
			if ( count( $keys ) < 2 ) {
				continue;
			}
			$query = (string) $keys[0];
			$page  = (string) $keys[1];

			if ( ! isset( $by_query[ $query ] ) ) {
				$by_query[ $query ] = array();
			}
			$by_query[ $query ][] = array(
				'page'        => $page,
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'position'    => (float) ( $row['position'] ?? 0 ),
			);
		}

		$candidates = array();

		foreach ( $by_query as $query => $pages ) {
			if ( count( $pages ) < $min_pages ) {
				continue;
			}

			$total_impr   = 0;
			$avg_position = 0.0;
			foreach ( $pages as $p ) {
				$total_impr   += $p['impressions'];
				$avg_position += $p['position'];
			}
			$avg_position /= count( $pages );

			if ( $total_impr < $min_impressions ) {
				continue;
			}
			if ( $avg_position > $max_position ) {
				continue;
			}

			$qualifying = array();
			foreach ( $pages as $p ) {
				$share = $total_impr > 0 ? $p['impressions'] / $total_impr : 0.0;
				if ( $share >= $split_threshold ) {
					$qualifying[] = $p;
				}
			}

			if ( count( $qualifying ) < $min_pages ) {
				continue;
			}

			$candidates[] = array(
				'query' => $query,
				'pages' => $qualifying,
			);
		}

		return $candidates;
	}

	/**
	 * Classify a finding: brand/pagination → 'excluded'; archive-vs-post pair → 'review'; else 'cannibal'.
	 *
	 * @param  array    $finding      {query: string, pages: array}.
	 * @param  string[] $brand_tokens Lowercase tokens to treat as brand queries.
	 * @return string 'cannibal' | 'review' | 'excluded'
	 */
	public static function classify_finding( array $finding, array $brand_tokens ): string {
		$query = strtolower( (string) ( $finding['query'] ?? '' ) );
		$pages = (array) ( $finding['pages'] ?? array() );

		foreach ( $brand_tokens as $token ) {
			$token = strtolower( (string) $token );
			if ( '' !== $token && ( false !== strpos( $query, $token ) ) ) {
				return 'excluded';
			}
		}

		foreach ( $pages as $p ) {
			$url = (string) ( $p['page'] ?? '' );
			if ( preg_match( '#/page/\d+/?#i', $url ) ) {
				return 'excluded';
			}
		}

		foreach ( $pages as $p ) {
			$url = (string) ( $p['page'] ?? '' );
			if ( self::is_archive_url( $url ) ) {
				return 'review';
			}
		}

		return 'cannibal';
	}

	/**
	 * Pick the winner: highest clicks wins; lower position breaks ties.
	 *
	 * @param  array<int, array{clicks: int, impressions: int, position: float}> $pages Competing pages.
	 * @return int Index of the winning page.
	 */
	public static function pick_winner( array $pages ): int {
		$winner_idx    = 0;
		$winner_clicks = -1;
		$winner_pos    = PHP_INT_MAX;

		foreach ( $pages as $i => $p ) {
			$clicks   = (int) $p['clicks'];
			$position = (float) $p['position'];

			if ( $clicks > $winner_clicks ) {
				$winner_idx    = $i;
				$winner_clicks = $clicks;
				$winner_pos    = $position;
			} elseif ( $clicks === $winner_clicks && $position < $winner_pos ) {
				$winner_idx = $i;
				$winner_pos = $position;
			}
		}

		return $winner_idx;
	}

	/**
	 * Expected CTR at a given position (curve, or decay beyond pos 10); filterable.
	 *
	 * @param  float $position Average position (1-based).
	 * @return float CTR as a decimal (e.g. 0.285 for 28.5 %).
	 */
	public static function expected_ctr( float $position ): float {
		$curve = function_exists( 'apply_filters' )
			? (array) apply_filters( 'swps_cannibal_ctr_curve', self::CTR_CURVE )
			: self::CTR_CURVE;

		$pos_int = max( 1, (int) round( $position ) );

		if ( isset( $curve[ $pos_int ] ) ) {
			$ctr = (float) $curve[ $pos_int ];
		} else {
			$base = isset( $curve[10] ) ? (float) $curve[10] : 0.027;
			$ctr  = $base / max( 1, $pos_int - 9 );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$ctr = (float) apply_filters( 'swps_cannibal_expected_ctr', $ctr, $position );
		}

		return max( 0.0, $ctr );
	}

	/**
	 * Decide how vanished open findings are resolved after a scan.
	 *
	 * @param  bool $any_candidates Whether the scan produced any candidates (pre-exclusion).
	 * @param  bool $any_seen       Whether any candidate survived exclusion (was upserted).
	 * @return string 'partial' (resolve unseen), 'none' (all excluded — leave untouched), 'all'.
	 */
	public static function resolve_mode( bool $any_candidates, bool $any_seen ): string {
		if ( $any_seen ) {
			return 'partial';
		}
		return $any_candidates ? 'none' : 'all';
	}

	/** Weekly scan: GSC rows → candidates → classify → upsert; resolve vanished; prune retention. No-op without GSC. */
	public function run_scan(): void {
		$rows = $this->search_console->get_query_page_rows( self::SCAN_DAYS, self::SCAN_ROW_LIMIT );

		if ( empty( $rows ) ) {
			return;
		}

		$candidates = self::find_candidates( $rows );

		$brand_tokens = (array) apply_filters(
			'swps_cannibal_brand_tokens',
			array_filter( array_map( 'strtolower', explode( ' ', (string) get_bloginfo( 'name' ) ) ) )
		);

		$seen_hashes = array();
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		foreach ( $candidates as $candidate ) {
			$severity = self::classify_finding( $candidate, $brand_tokens );

			if ( 'excluded' === $severity ) {
				continue;
			}

			$hash          = md5( strtolower( trim( $candidate['query'] ) ) );
			$seen_hashes[] = $hash;

			$existing   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT id, status FROM {$table} WHERE query_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$hash
				)
			);
			$pages_json = wp_json_encode( $candidate['pages'] );

			if ( $existing ) {
				$update = array(
					'pages'    => $pages_json,
					'severity' => $severity,
				);
				if ( 'open' !== $existing->status ) {
					$update['status'] = 'open';
				}
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					$update,
					array( 'id' => (int) $existing->id ),
					null,
					array( '%d' )
				);
			} else {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'query_hash'  => $hash,
						'query'       => mb_substr( $candidate['query'], 0, 255 ),
						'pages'       => $pages_json,
						'severity'    => $severity,
						'status'      => 'open',
						'detected_at' => current_time( 'mysql' ),
					)
				);
			}
		}

		// All-excluded weeks (brand/pagination only) must NOT mass-resolve open findings.
		$mode = self::resolve_mode( ! empty( $candidates ), ! empty( $seen_hashes ) );
		if ( 'partial' === $mode ) {
			$placeholders = implode( ',', array_fill( 0, count( $seen_hashes ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'resolved' WHERE status = 'open' AND query_hash NOT IN ({$placeholders})",
					...$seen_hashes
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		} elseif ( 'all' === $mode ) {
			// Genuinely zero candidates — resolve everything open.
			$wpdb->query( "UPDATE {$table} SET status = 'resolved' WHERE status = 'open'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		// 'none': candidates existed but all were excluded — leave open findings untouched.

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ('dismissed','resolved') AND detected_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::RETENTION_DAYS
			)
		);
	}

	/** Return open findings (array of row objects) ordered by severity then detected_at desc. */
	public static function get_open_findings(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'open' ORDER BY severity ASC, detected_at DESC" );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single finding row.
	 *
	 * @param  int $id Row ID.
	 * @return object|null Row object, or null if not found.
	 */
	public static function get_finding( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return $row ? $row : null;
	}

	/** Count open findings. */
	public static function count_open(): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'open'" );
	}

	/**
	 * Update a finding's status.
	 *
	 * @param int    $id     Row ID.
	 * @param string $status One of: open, resolved, dismissed.
	 */
	public static function set_status( int $id, string $status ): bool {
		global $wpdb;
		$allowed = array( 'open', 'resolved', 'dismissed' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}
		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . self::TABLE,
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Store undo payload on a finding row.
	 *
	 * @param int   $id          Row ID.
	 * @param array $undo_payload {redirect_id: int, post_id: int, prior_status: string}.
	 */
	public static function store_undo( int $id, array $undo_payload ): bool {
		global $wpdb;
		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . self::TABLE,
			array(
				'undo_json' => wp_json_encode( $undo_payload ),
				'status'    => 'resolved',
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Retrieve the undo payload for a finding.
	 *
	 * @param  int $id Row ID.
	 * @return array|null Decoded payload, or null if not set.
	 */
	public static function get_undo( int $id ): ?array {
		global $wpdb;
		$tbl  = $wpdb->prefix . self::TABLE;
		$json = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT undo_json FROM {$tbl} WHERE id = %d LIMIT 1", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		// Sentinel: cleared undo is stored as '' (wpdb '%s' coerces null); treat '' and '{}' as empty.
		if ( empty( $json ) || '{}' === trim( (string) $json ) ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) && array() !== $decoded ? $decoded : null;
	}

	/**
	 * Clear the undo payload and re-open a finding.
	 *
	 * @param int $id Row ID.
	 */
	public static function clear_undo( int $id ): bool {
		global $wpdb;
		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . self::TABLE,
			array(
				'undo_json' => '', // Sentinel for "no undo" — '%s' would coerce null to '' anyway.
				'status'    => 'open',
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Heuristic: does the URL look like an archive? Matches /category/, /tag/, /author/.
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	private static function is_archive_url( string $url ): bool {
		return (bool) preg_match( '#/(category|tag|author)/#i', $url );
	}
}
