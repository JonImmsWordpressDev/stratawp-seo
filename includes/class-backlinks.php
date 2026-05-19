<?php
/**
 * Backlinks tracker — manual + CSV-import backlink monitoring.
 *
 * Stores user-supplied backlinks in {$wpdb->prefix}swps_backlinks. A daily
 * cron job re-fetches each source page, looks for any link whose href contains
 * the site's host, captures anchor text, and updates a status of:
 *
 *   live    — found at least one link to this domain on the source page
 *   lost    — page loaded fine but no link to this domain anywhere on it
 *   broken  — source page returned an HTTP error (4xx / 5xx) or timed out
 *
 * No external paid index — this tracks a list you give it and tells you what
 * happens to those links over time. Pair with a GSC "Top linking sites" CSV
 * export to get a real-world starting set.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Backlinks {

	public const TABLE        = 'swps_backlinks';
	public const CRON_HOOK    = 'swps_backlinks_daily_verify';
	public const HTTP_TIMEOUT = 12;
	public const VERIFY_BATCH = 25;
	public const DB_VERSION   = '1';
	public const OPT_DB_VER   = 'swps_backlinks_db_version';

	public function __construct() {
		// Runtime upgrade: ensure the table exists on existing installs that
		// didn't run the activation hook (i.e. plugin updated, not reactivated).
		if ( self::DB_VERSION !== get_option( self::OPT_DB_VER ) ) {
			self::create_tables();
			update_option( self::OPT_DB_VER, self::DB_VERSION );
		}

		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'init', array( $this, 'maybe_schedule_cron' ) );

		add_action( 'wp_ajax_swps_backlink_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_swps_backlink_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_swps_backlink_verify', array( $this, 'ajax_verify' ) );
		add_action( 'wp_ajax_swps_backlink_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_swps_backlink_export', array( $this, 'ajax_export' ) );

		add_action( self::CRON_HOOK, array( $this, 'cron_verify_all' ) );
	}

	// ---------- Schema ----------

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function create_tables(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url   VARCHAR(2048) NOT NULL,
            source_host  VARCHAR(255) NOT NULL DEFAULT '',
            target_url   VARCHAR(2048) NOT NULL DEFAULT '',
            anchor_text  VARCHAR(500) NOT NULL DEFAULT '',
            status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
            http_status  SMALLINT     NOT NULL DEFAULT 0,
            first_seen   DATETIME     NULL,
            last_seen    DATETIME     NULL,
            last_checked DATETIME     NULL,
            notes        TEXT         NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY source_host (source_host),
            KEY last_checked (last_checked)
        ) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		wp_unschedule_hook( self::CRON_HOOK );
	}

	public function maybe_schedule_cron(): void {
		self::schedule_cron();
	}

	// ---------- Admin menu / assets ----------

	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Backlinks', 'stratawp-seo' ),
			__( 'Backlinks', 'stratawp-seo' ),
			'manage_options',
			'swps-backlinks',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
		}
		require SWPS_PLUGIN_DIR . 'templates/backlinks-page.php';
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'stratawp-seo_page_swps-backlinks' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'swps-page-backlinks',
			SWPS_PLUGIN_URL . 'admin/css/pages/backlinks.css',
			array( 'swps-tokens', 'swps-components', 'swps-templates' ),
			SWPS_VERSION
		);
		wp_enqueue_script(
			'swps-backlinks',
			SWPS_PLUGIN_URL . 'admin/js/backlinks.js',
			array( 'jquery' ),
			SWPS_VERSION,
			true
		);
		wp_localize_script(
			'swps-backlinks',
			'swpsBacklinks',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'swps_nonce' ),
				'i18n'    => array(
					'verifying'     => __( 'Verifying...', 'stratawp-seo' ),
					'verifyingAll'  => __( 'Verifying all backlinks...', 'stratawp-seo' ),
					'saving'        => __( 'Saving...', 'stratawp-seo' ),
					'importing'     => __( 'Importing...', 'stratawp-seo' ),
					'failed'        => __( 'Request failed.', 'stratawp-seo' ),
					'invalidUrl'    => __( 'Please enter a valid http(s) URL.', 'stratawp-seo' ),
					'confirmDelete' => __( 'Delete this backlink?', 'stratawp-seo' ),
					'imported'      => __( 'Imported %d backlinks.', 'stratawp-seo' ),
					'noNewLinks'    => __( 'No new links found in import.', 'stratawp-seo' ),
				),
			)
		);
	}

	// ---------- AJAX ----------

	public function ajax_save(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$source = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
		$target = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
		$anchor = isset( $_POST['anchor_text'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor_text'] ) ) : '';

		if ( '' === $source || ! preg_match( '#^https?://#i', $source ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid http(s) URL.', 'stratawp-seo' ) ) );
		}

		if ( $id > 0 ) {
			$this->update_row(
				$id,
				array(
					'source_url'  => $source,
					'source_host' => $this->host_of( $source ),
					'target_url'  => $target,
					'anchor_text' => $anchor,
				)
			);
		} else {
			$id = $this->insert_row(
				array(
					'source_url'  => $source,
					'source_host' => $this->host_of( $source ),
					'target_url'  => $target,
					'anchor_text' => $anchor,
					'status'      => 'pending',
					'created_at'  => current_time( 'mysql' ),
				)
			);
			if ( ! $id ) {
				wp_send_json_error( array( 'message' => __( 'Save failed.', 'stratawp-seo' ) ) );
			}
		}

		// Verify immediately so the row shows fresh data.
		$this->verify_one( $id );
		wp_send_json_success(
			array(
				'row'   => $this->get_row( $id ),
				'stats' => $this->get_stats(),
			)
		);
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid id.', 'stratawp-seo' ) ) );
		}
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_send_json_success( array( 'stats' => $this->get_stats() ) );
	}

	public function ajax_verify(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id > 0 ) {
			$this->verify_one( $id );
			wp_send_json_success(
				array(
					'row'   => $this->get_row( $id ),
					'stats' => $this->get_stats(),
				)
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$batch  = $this->get_ids_for_verify( $offset, self::VERIFY_BATCH );
		foreach ( $batch as $row_id ) {
			$this->verify_one( (int) $row_id );
		}
		$remaining = max( 0, $this->count_total() - ( $offset + count( $batch ) ) );
		wp_send_json_success(
			array(
				'processed' => count( $batch ),
				'next'      => $offset + count( $batch ),
				'remaining' => $remaining,
				'stats'     => $this->get_stats(),
			)
		);
	}

	public function ajax_import(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}
		$raw = isset( $_POST['csv'] ) ? (string) wp_unslash( $_POST['csv'] ) : '';
		if ( '' === trim( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to import.', 'stratawp-seo' ) ) );
		}

		$imported = $this->import_csv( $raw );
		wp_send_json_success(
			array(
				'imported' => $imported,
				'stats'    => $this->get_stats(),
			)
		);
	}

	public function ajax_export(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stratawp-seo' ) );
		}
		$rows = $this->get_all();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="stratawp-backlinks-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'source_url', 'target_url', 'anchor_text', 'status', 'http_status', 'first_seen', 'last_seen', 'last_checked' ) );
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r['source_url'],
					$r['target_url'],
					$r['anchor_text'],
					$r['status'],
					$r['http_status'],
					$r['first_seen'],
					$r['last_seen'],
					$r['last_checked'],
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// ---------- CSV ----------

	/**
	 * Import CSV. Accepts either:
	 *   - GSC "Top linking sites" export (single column of source domains)
	 *   - Generic source_url[,target_url[,anchor_text]] CSV
	 *   - One URL per line
	 *
	 * Returns count of newly inserted rows. Existing source_urls are skipped.
	 */
	public function import_csv( string $raw ): int {
		$raw   = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		$existing = array_flip( $this->get_all_source_urls() );
		$count    = 0;

		foreach ( $lines as $i => $line ) {
			// Skip header rows (first non-URL row).
			if ( 0 === $i && false === stripos( $line, 'http' ) ) {
				continue;
			}
			$cols = str_getcsv( $line );
			if ( empty( $cols ) ) {
				continue;
			}
			$source = trim( (string) $cols[0] );
			if ( '' === $source ) {
				continue;
			}
			// Bare-domain rows from GSC — promote to https://.
			if ( ! preg_match( '#^https?://#i', $source ) ) {
				if ( preg_match( '#^[a-z0-9.-]+\.[a-z]{2,}$#i', $source ) ) {
					$source = 'https://' . ltrim( $source, '/' );
				} else {
					continue;
				}
			}
			if ( isset( $existing[ $source ] ) ) {
				continue;
			}
			$target = isset( $cols[1] ) ? trim( (string) $cols[1] ) : '';
			$anchor = isset( $cols[2] ) ? trim( (string) $cols[2] ) : '';

			$id = $this->insert_row(
				array(
					'source_url'  => esc_url_raw( $source ),
					'source_host' => $this->host_of( $source ),
					'target_url'  => $target ? esc_url_raw( $target ) : '',
					'anchor_text' => sanitize_text_field( $anchor ),
					'status'      => 'pending',
					'created_at'  => current_time( 'mysql' ),
				)
			);
			if ( $id ) {
				$existing[ $source ] = true;
				++$count;
			}
		}
		return $count;
	}

	// ---------- Cron ----------

	public function cron_verify_all(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		$offset = 0;
		do {
			$batch = $this->get_ids_for_verify( $offset, self::VERIFY_BATCH );
			foreach ( $batch as $id ) {
				$this->verify_one( (int) $id );
				usleep( 250000 ); // 0.25s — be polite.
			}
			$offset += count( $batch );
		} while ( ! empty( $batch ) );
	}

	// ---------- Verify ----------

	public function verify_one( int $id ): array {
		$row = $this->get_row( $id );
		if ( ! $row ) {
			return array();
		}

		$source = (string) $row['source_url'];
		$now    = current_time( 'mysql' );
		$resp   = wp_remote_get(
			$source,
			array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 4,
				'user-agent'  => 'StrataWP-SEO/' . SWPS_VERSION . ' (+https://stratawpseo.com; backlink verifier)',
				'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8' ),
			)
		);

		$update = array(
			'last_checked' => $now,
			'http_status'  => 0,
			'status'       => 'broken',
			'anchor_text'  => $row['anchor_text'],
			'target_url'   => $row['target_url'],
		);

		if ( is_wp_error( $resp ) ) {
			$update['notes'] = mb_substr( (string) $resp->get_error_message(), 0, 200 );
		} else {
			$code                  = (int) wp_remote_retrieve_response_code( $resp );
			$update['http_status'] = $code;
			if ( $code < 200 || $code >= 400 ) {
				$update['notes'] = sprintf( /* translators: %d HTTP status */ __( 'HTTP %d', 'stratawp-seo' ), $code );
			} else {
				$body  = (string) wp_remote_retrieve_body( $resp );
				$found = $this->find_link( $body );
				if ( $found ) {
					$update['status']      = 'live';
					$update['anchor_text'] = '' !== ( $found['anchor'] ?? '' ) ? $found['anchor'] : $row['anchor_text'];
					$update['target_url']  = '' !== ( $found['href'] ?? '' ) ? $found['href'] : $row['target_url'];
					$update['notes']       = '';
					if ( empty( $row['first_seen'] ) ) {
						$update['first_seen'] = $now;
					}
					$update['last_seen'] = $now;
				} else {
					$update['status'] = 'lost';
					$update['notes']  = __( 'Page loaded but contained no link to this site.', 'stratawp-seo' );
				}
			}
		}

		$this->update_row( $id, $update );
		return $this->get_row( $id );
	}

	/**
	 * Look for any anchor in $html whose href contains the home host. Returns
	 * the first match's href + anchor text, or null.
	 *
	 * @return array{href:string, anchor:string}|null
	 */
	private function find_link( string $html ): ?array {
		$home_host = $this->home_host();
		if ( '' === $home_host ) {
			return null;
		}

		if ( ! preg_match_all( '#<a\b([^>]*?)href\s*=\s*["\']([^"\']+)["\']([^>]*)>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
			return null;
		}

		foreach ( $m as $match ) {
			$href = trim( $match[2] );
			if ( '' === $href ) {
				continue;
			}
			$href_host = $this->host_of( $href );
			if ( '' !== $href_host && $this->hosts_match( $href_host, $home_host ) ) {
				$anchor = trim( wp_strip_all_tags( $match[4] ) );
				if ( mb_strlen( $anchor ) > 200 ) {
					$anchor = mb_substr( $anchor, 0, 200 );
				}
				return array(
					'href'   => $href,
					'anchor' => $anchor,
				);
			}
		}
		return null;
	}

	private function hosts_match( string $a, string $b ): bool {
		$a = strtolower( preg_replace( '/^www\./i', '', $a ) ?: $a );
		$b = strtolower( preg_replace( '/^www\./i', '', $b ) ?: $b );
		return $a === $b;
	}

	private function host_of( string $url ): string {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) ? strtolower( $h ) : '';
	}

	private function home_host(): string {
		return $this->host_of( home_url() );
	}

	// ---------- Storage ----------

	public function insert_row( array $data ): int {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( self::table_name(), $data );
		return (int) $wpdb->insert_id;
	}

	public function update_row( int $id, array $data ): void {
		if ( $id <= 0 || empty( $data ) ) {
			return;
		}
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table_name(), $data, array( 'id' => $id ) );
	}

	public function get_row( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all( string $status_filter = '', string $order_by = 'last_checked DESC' ): array {
		global $wpdb;
		$table = self::table_name();
		$allow = array( 'last_checked DESC', 'last_seen DESC', 'first_seen DESC', 'source_host ASC' );
		if ( ! in_array( $order_by, $allow, true ) ) {
			$order_by = 'last_checked DESC';
		}
		if ( '' !== $status_filter ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY {$order_by}", $status_filter ), ARRAY_A );
		} else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY {$order_by}", ARRAY_A );
		}
		return is_array( $rows ) ? $rows : array();
	}

	public function get_all_source_urls(): array {
		global $wpdb;
		$table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT source_url FROM {$table}" ) );
	}

	public function get_ids_for_verify( int $offset, int $limit ): array {
		global $wpdb;
		$table  = self::table_name();
		$offset = max( 0, $offset );
		$limit  = max( 1, min( 100, $limit ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$table} ORDER BY (last_checked IS NULL) DESC, last_checked ASC LIMIT %d, %d",
					$offset,
					$limit
				)
			)
		);
	}

	public function count_total(): int {
		global $wpdb;
		$table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public function get_stats(): array {
		global $wpdb;
		$table = self::table_name();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$by_status = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
		$stats     = array(
			'total'   => 0,
			'live'    => 0,
			'lost'    => 0,
			'broken'  => 0,
			'pending' => 0,
		);
		foreach ( $by_status as $r ) {
			$key             = (string) $r['status'];
			$stats[ $key ]   = (int) $r['c'];
			$stats['total'] += (int) $r['c'];
		}

		$now30                   = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ?: time() );
		$stats['new_30d']        = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE first_seen IS NOT NULL AND first_seen >= %s",
				$now30
			)
		);
		$stats['lost_30d']       = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'lost' AND last_checked >= %s",
				$now30
			)
		);
		$stats['unique_domains'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT source_host) FROM {$table} WHERE source_host <> ''" );
        // phpcs:enable

		return $stats;
	}

	/**
	 * 3 most-recently-changed backlinks for the dashboard tile.
	 *
	 * @return array<int, array{label:string,url:string,status:string,when:string}>
	 */
	public function get_dashboard_recent( int $limit = 3 ): array {
		global $wpdb;
		$table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_host, source_url, status, last_checked FROM {$table} WHERE last_checked IS NOT NULL ORDER BY last_checked DESC LIMIT %d",
				max( 1, min( 10, $limit ) )
			),
			ARRAY_A
		);
		return array_map(
			static function ( $r ) {
				return array(
					'label'  => '' !== $r['source_host'] ? $r['source_host'] : $r['source_url'],
					'url'    => $r['source_url'],
					'status' => $r['status'],
					'when'   => $r['last_checked'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}
}
