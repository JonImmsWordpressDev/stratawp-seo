<?php
/**
 * Citation check storage — table schema, upsert, retention prune, and
 * aggregate state queries for the AI citation tracker.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence layer for swps_citation_checks rows.
 */
class SWPS_Citation_Store {

	/** Schema version — bump when the checks table DDL changes. */
	public const DB_VERSION = '1';

	/** Option key used to gate the schema upgrade. */
	public const OPT_DB_VER = 'swps_citation_db_version';

	/** Checks table (without $wpdb->prefix). */
	private const TABLE = 'swps_citation_checks';

	/**
	 * Create the citation checks table.
	 * Called on activation (from stratawp-seo.php) and via maybe_upgrade().
	 */
	public static function create_table(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . self::TABLE;

		$sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            prompt_hash CHAR(32) NOT NULL,
            prompt VARCHAR(500) NOT NULL,
            engine VARCHAR(16) NOT NULL,
            check_date DATE NOT NULL,
            cited TINYINT(1) NOT NULL DEFAULT 0,
            our_domains TEXT,
            cited_domains TEXT,
            PRIMARY KEY (id),
            UNIQUE KEY idx_prompt_engine_date (prompt_hash, engine, check_date),
            KEY idx_engine_date (engine, check_date)
        ) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Ensure the checks table exists for installs updated without reactivation.
	 * Mirrors the SWPS_AI_Referrals::maybe_upgrade() pattern.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::OPT_DB_VER ) ) {
			self::create_table();
			update_option( self::OPT_DB_VER, self::DB_VERSION );
		}
	}

	/**
	 * Insert or update today's check row for a prompt × engine pair.
	 * Re-running on the same day overwrites the previous result.
	 *
	 * @param array $row Keys: prompt_hash, prompt, engine, check_date, cited (int 0/1),
	 *                   our_domains (JSON string), cited_domains (JSON string).
	 */
	public static function upsert_check( array $row ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (prompt_hash, prompt, engine, check_date, cited, our_domains, cited_domains)
                 VALUES (%s, %s, %s, %s, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE
                     cited = VALUES(cited),
                     our_domains = VALUES(our_domains),
                     cited_domains = VALUES(cited_domains)",
				$row['prompt_hash'],
				$row['prompt'],
				$row['engine'],
				$row['check_date'],
				(int) $row['cited'],
				$row['our_domains'],
				$row['cited_domains']
			)
		);
		// phpcs:enable
	}

	/**
	 * Delete check rows older than the retention window.
	 *
	 * @param int $days Retention in days (default 180).
	 */
	public static function prune( int $days = 180 ): void {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$cutoff = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE check_date < %s", $cutoff )
		);
		// phpcs:enable
	}

	/**
	 * Fetch recent check rows for a set of prompt hashes, newest first.
	 *
	 * @param string[] $hashes Prompt hashes (md5).
	 * @param int      $limit_per_pair Max rows per prompt × engine pair.
	 * @return array<string, array<string, array>> hash => engine => rows
	 *               (each row: check_date, cited (bool), cited_domains (array)).
	 */
	public static function histories( array $hashes, int $limit_per_pair = 3 ): array {
		global $wpdb;

		if ( empty( $hashes ) ) {
			return array();
		}

		$table        = $wpdb->prefix . self::TABLE;
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT prompt_hash, engine, check_date, cited, cited_domains
                 FROM {$table}
                 WHERE prompt_hash IN ({$placeholders})
                 ORDER BY check_date DESC",
				$hashes
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$hash   = (string) $row['prompt_hash'];
			$engine = (string) $row['engine'];
			if ( ! isset( $out[ $hash ][ $engine ] ) ) {
				$out[ $hash ][ $engine ] = array();
			}
			if ( count( $out[ $hash ][ $engine ] ) >= $limit_per_pair ) {
				continue;
			}
			$out[ $hash ][ $engine ][] = array(
				'check_date'    => (string) $row['check_date'],
				'cited'         => (bool) (int) $row['cited'],
				'cited_domains' => (array) json_decode( (string) $row['cited_domains'], true ),
			);
		}

		return $out;
	}

	/**
	 * Fetch all check rows in the last N days (for share-of-voice and breakdowns).
	 *
	 * @param int $days Window in days.
	 * @return array[] Rows: prompt_hash, engine, cited (bool), cited_domains (array).
	 */
	public static function recent_checks( int $days = 30 ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT prompt_hash, engine, cited, cited_domains
                 FROM {$table}
                 WHERE check_date >= %s",
				$since
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'prompt_hash'   => (string) $row['prompt_hash'],
				'engine'        => (string) $row['engine'],
				'cited'         => (bool) (int) $row['cited'],
				'cited_domains' => (array) json_decode( (string) $row['cited_domains'], true ),
			);
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Aggregate state queries
	// -------------------------------------------------------------------------

	/**
	 * Per-prompt per-engine citation states (smoothed over last 3 checks).
	 *
	 * @param array[]  $prompts Tracked prompt entries ({ prompt, post_id, added }).
	 * @param string[] $engines Enabled engine slugs.
	 * @return array[] Entries: { prompt, post_id, states: engine => { state, last_date } }.
	 */
	public static function prompt_states( array $prompts, array $engines ): array {
		$hashes = array();
		foreach ( $prompts as $entry ) {
			$hashes[] = md5( (string) $entry['prompt'] );
		}

		$histories = self::histories( $hashes, 3 );
		$out       = array();

		foreach ( $prompts as $entry ) {
			$hash   = md5( (string) $entry['prompt'] );
			$states = array();
			foreach ( $engines as $engine ) {
				$rows    = $histories[ $hash ][ $engine ] ?? array();
				$history = array_map(
					static function ( $row ) {
						return (bool) $row['cited'];
					},
					$rows
				);

				$states[ $engine ] = array(
					'state'     => SWPS_Citation_Tracker::citation_state( $history ),
					'last_date' => $rows[0]['check_date'] ?? '',
				);
			}
			$out[] = array(
				'prompt'  => (string) $entry['prompt'],
				'post_id' => (int) ( $entry['post_id'] ?? 0 ),
				'states'  => $states,
			);
		}

		return $out;
	}

	/**
	 * Share-of-voice data: per-domain prompts-cited counts over the window for
	 * us + competitors, with division-safe percentages.
	 *
	 * @param string[] $our_domains        Our domain aliases (first = primary).
	 * @param string[] $competitor_domains Competitor domains.
	 * @param int      $days               Window in days.
	 * @return array { total_prompts: int, counts: domain => int, pct: domain => float, us: string }.
	 */
	public static function share_of_voice_data( array $our_domains, array $competitor_domains, int $days = 30 ): array {
		$checks  = self::recent_checks( $days );
		$us      = $our_domains[0] ?? '';
		$domains = array_values( array_filter( array_unique( array_merge( array( $us ), $competitor_domains ) ) ) );

		// Distinct prompts checked in the window; per-domain prompt-hash sets.
		$prompt_hashes = array();
		$cited_by      = array_fill_keys( $domains, array() );

		foreach ( $checks as $check ) {
			$hash                   = $check['prompt_hash'];
			$prompt_hashes[ $hash ] = true;
			$hosts                  = array_map( 'strval', $check['cited_domains'] );
			foreach ( $domains as $domain ) {
				if ( SWPS_Citation_Tracker::domain_cited( $domain, $hosts ) ) {
					$cited_by[ $domain ][ $hash ] = true;
				}
			}
		}

		$total  = count( $prompt_hashes );
		$counts = array();
		foreach ( $domains as $domain ) {
			$counts[ $domain ] = count( $cited_by[ $domain ] );
		}

		return array(
			'total_prompts' => $total,
			'counts'        => $counts,
			'pct'           => SWPS_Citation_Tracker::share_of_voice( $counts, $total ),
			'us'            => $us,
		);
	}

	/**
	 * Top domains cited by AI engines for our prompts over the window.
	 *
	 * @param int $days  Window in days.
	 * @param int $limit Max domains returned.
	 * @return array<string, int> domain => citation count, descending.
	 */
	public static function cited_domains_breakdown( int $days = 30, int $limit = 10 ): array {
		$counts = array();

		foreach ( self::recent_checks( $days ) as $check ) {
			foreach ( $check['cited_domains'] as $domain ) {
				$domain = (string) $domain;
				if ( '' === $domain ) {
					continue;
				}
				$counts[ $domain ] = ( $counts[ $domain ] ?? 0 ) + 1;
			}
		}

		arsort( $counts );

		return array_slice( $counts, 0, $limit, true );
	}
}
