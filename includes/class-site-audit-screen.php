<?php
/**
 * Site Audit dashboard screen: score gauge, triage cards, issue drill-downs,
 * and a trend strip, built on the crawl engine from Tasks 1-8.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page + AJAX/admin-post endpoints for the Site Audit dashboard.
 */
class SWPS_Site_Audit_Screen {

	/** Admin page slug. */
	public const SLUG = 'swps-site-audit';

	/** Nonce action for the "Run audit now" link. */
	private const NONCE_START = 'swps_site_audit_start';

	/** Nonce action for the progress-poll AJAX request. */
	private const NONCE_PROGRESS = 'swps_site_audit_progress';

	/** Nonce action for the exclude/re-enable admin-post form. */
	private const NONCE_EXCLUDE = 'swps_site_audit_exclude';

	/** Severity display order (rank => label maps to sort weight). */
	private const SEVERITY_RANK = array(
		'error'   => 0,
		'warning' => 1,
		'notice'  => 2,
	);

	/** Maximum page rows shown per issue group before capping. */
	private const PAGE_LIST_CAP = 50;

	/**
	 * Crawler instance used to start runs.
	 *
	 * @var SWPS_Site_Crawler
	 */
	private SWPS_Site_Crawler $crawler;

	/**
	 * Wire assets + AJAX + admin-post hooks.
	 *
	 * Menu registration lives in SWPS_Settings::register_menu() (mirrors the
	 * Search Appearance submenu), not here.
	 *
	 * @param SWPS_Site_Crawler $crawler Crawler instance.
	 */
	public function __construct( SWPS_Site_Crawler $crawler ) {
		$this->crawler = $crawler;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_swps_site_audit_progress', array( $this, 'ajax_progress' ) );
		add_action( 'wp_ajax_swps_site_audit_start', array( $this, 'ajax_start' ) );
		add_action( 'admin_post_swps_site_audit_exclude', array( $this, 'handle_exclude' ) );
	}

	// =========================================================================
	// ASSETS
	// =========================================================================

	/**
	 * Enqueue the screen's JS + CSS, scoped to this page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'stratawp-seo_page_' . self::SLUG !== $hook ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'swps-site-audit',
			SWPS_PLUGIN_URL . 'admin/css/site-audit.css',
			array( 'swps-tokens' ),
			SWPS_VERSION
		);

		wp_enqueue_script(
			'swps-site-audit',
			SWPS_PLUGIN_URL . "admin/js/site-audit{$suffix}.js",
			array(),
			SWPS_VERSION,
			true
		);
	}

	// =========================================================================
	// AJAX / ADMIN-POST
	// =========================================================================

	/**
	 * Return a progress snapshot for the JS poller.
	 *
	 * JSON contract: {percent:int, done:int, total:int, finished:bool}.
	 */
	public function ajax_progress(): void {
		check_ajax_referer( self::NONCE_PROGRESS );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}

		$state  = SWPS_Site_Crawler::get_state();
		$run_id = (int) ( $state['run_id'] ?? 0 );

		if ( 0 === $run_id ) {
			wp_send_json_success(
				array(
					'percent'  => 100,
					'done'     => 0,
					'total'    => 0,
					'finished' => true,
				)
			);
		}

		$snapshot = SWPS_Crawl_Issues::progress_snapshot( $run_id );
		$done     = (int) $snapshot['crawled'];
		$total    = $done + (int) $snapshot['queued'];
		$percent  = $total > 0 ? (int) round( ( $done / $total ) * 100 ) : ( $snapshot['done'] ? 100 : 0 );

		wp_send_json_success(
			array(
				'percent'  => $percent,
				'done'     => $done,
				'total'    => $total,
				'finished' => (bool) $snapshot['done'],
			)
		);
	}

	/**
	 * Start a new crawl run and schedule chunk processing, the same way the
	 * weekly cron trigger does. Plain nonce'd navigation (not fetch/JSON) —
	 * the dashboard picks up the in-progress state on redirect back.
	 */
	public function ajax_start(): void {
		check_ajax_referer( self::NONCE_START );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stratawp-seo' ) );
		}

		$this->crawler->start_run();

		if ( ! wp_next_scheduled( SWPS_Site_Crawler::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 1, SWPS_Site_Crawler::CRON_HOOK );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Toggle a check ID's membership in the excluded-checks option.
	 */
	public function handle_exclude(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'stratawp-seo' ) );
		}
		check_admin_referer( self::NONCE_EXCLUDE );

		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : '';
		if ( '' !== $check_id ) {
			$excluded = (array) get_option( SWPS_Crawl_Check_Registry::OPT_EXCLUDED, array() );
			if ( in_array( $check_id, $excluded, true ) ) {
				$excluded = array_values( array_diff( $excluded, array( $check_id ) ) );
			} else {
				$excluded[] = $check_id;
			}
			update_option( SWPS_Crawl_Check_Registry::OPT_EXCLUDED, $excluded, false );
		}

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	// =========================================================================
	// RENDER
	// =========================================================================

	/**
	 * Render the dashboard page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'stratawp-seo' ) );
		}

		$state   = SWPS_Site_Crawler::get_state();
		$running = ( 'running' === ( $state['status'] ?? '' ) );

		$excluded = (array) get_option( SWPS_Crawl_Check_Registry::OPT_EXCLUDED, array() );

		// Check catalog keyed by id (title, how_to_fix, severity) — includes
		// excluded checks so their rows can render in the footer strip.
		$catalog = array();
		foreach ( SWPS_Crawl_Check_Registry::all( array() ) as $check ) {
			$catalog[ $check->id() ] = $check;
		}

		// Newest-first run IDs. While a run is in progress it is the newest
		// entry but has no summary yet, so the dashboard body displays the
		// last *completed* run (offset 1) until the new one finishes.
		$run_ids         = SWPS_Crawl_Issues::latest_run_ids( 5 );
		$offset          = $running ? 1 : 0;
		$display_run_id  = $run_ids[ $offset ] ?? 0;
		$baseline_run_id = $run_ids[ $offset + 1 ] ?? 0;

		$run_summaries    = (array) get_option( 'swps_crawl_run_summaries', array() );
		$display_summary  = (array) ( $run_summaries[ $display_run_id ] ?? array() );
		$baseline_summary = (array) ( $run_summaries[ $baseline_run_id ] ?? array() );

		$score             = (int) ( $display_summary['score'] ?? 0 );
		$severity_counts   = (array) ( $display_summary['severity'] ?? array(
			'error'   => 0,
			'warning' => 0,
			'notice'  => 0,
		) );
		$page_counts       = (array) ( $display_summary['pages'] ?? array(
			'total'   => 0,
			'healthy' => 0,
			'broken'  => 0,
		) );
		$baseline_severity = (array) ( $baseline_summary['severity'] ?? array(
			'error'   => 0,
			'warning' => 0,
			'notice'  => 0,
		) );

		$baseline_issue_counts = array();
		foreach ( (array) ( $baseline_summary['issue_counts'] ?? array() ) as $row ) {
			$baseline_issue_counts[ (string) $row['type'] ] = (int) $row['cnt'];
		}

		$issues_by_type = $display_run_id ? SWPS_Crawl_Issues::issues_for_run( $display_run_id ) : array();

		$last_summary = (array) get_option( SWPS_Site_Crawler::OPT_LAST_SUMMARY, array() );

		echo '<div class="wrap swps-site-audit">';

		$this->render_header( $running, $score, $page_counts, $last_summary );

		if ( ! $running || $display_run_id ) {
			$this->render_triage_cards( $severity_counts, $baseline_severity );
			$this->render_issue_groups( $issues_by_type, $catalog, $excluded, $baseline_issue_counts );
			$this->render_excluded_footer( $excluded, $catalog );
			$this->render_trend_strip( $run_summaries );
		}

		echo '</div>';
	}

	/**
	 * Header row: score gauge + stats, or a progress bar while a run is active.
	 *
	 * @param bool  $running      Whether a crawl is currently in progress.
	 * @param int   $score        Health score for the displayed run.
	 * @param array $page_counts  {total, healthy, broken} for the displayed run.
	 * @param array $last_summary The OPT_LAST_SUMMARY option value.
	 */
	private function render_header( bool $running, int $score, array $page_counts, array $last_summary ): void {
		$start_url     = wp_nonce_url(
			admin_url( 'admin-ajax.php?action=swps_site_audit_start' ),
			self::NONCE_START
		);
		$last_run_date = __( 'Never', 'stratawp-seo' );
		if ( ! empty( $last_summary['completed_at'] ) ) {
			// completed_at is stored as a gmdate() string; strtotime(...' UTC')
			// gives date_i18n() a true UTC timestamp so it applies the site's
			// offset correctly instead of double-converting.
			$completed_ts = strtotime( (string) $last_summary['completed_at'] . ' UTC' );
			if ( false !== $completed_ts ) {
				$last_run_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $completed_ts );
			}
		}

		echo '<div class="swps-tile swps-audit-header">';

		if ( $running ) {
			$progress_nonce = wp_create_nonce( self::NONCE_PROGRESS );
			echo '<div class="swps-audit-progress" data-swps-audit-progress data-nonce="' . esc_attr( $progress_nonce ) . '">';
			echo '<p class="swps-audit-progress-label">' . esc_html__( 'Crawling your site…', 'stratawp-seo' ) . '</p>';
			echo '<div class="swps-audit-bar-track"><div class="swps-audit-bar" style="width:0%"></div></div>';
			echo '<div class="swps-audit-count">0 / 0</div>';
			echo '</div>';
		} else {
			$gauge_color = $score >= 90 ? 'var(--swps-success)' : ( $score >= 70 ? 'var(--swps-warn)' : 'var(--swps-crit)' );
			echo '<div class="swps-audit-gauge" style="--swps-audit-score:' . esc_attr( (string) $score ) . ';--swps-audit-gauge-color:' . esc_attr( $gauge_color ) . '">';
			echo '<div class="swps-audit-gauge-inner"><span class="swps-audit-gauge-num">' . esc_html( (string) $score ) . '</span><span class="swps-audit-gauge-suffix">/100</span></div>';
			echo '</div>';

			echo '<div class="swps-audit-header-stats">';
			echo '<div class="swps-audit-stat"><span class="swps-audit-stat-num">' . esc_html( number_format_i18n( (int) ( $page_counts['total'] ?? 0 ) ) ) . '</span><span class="swps-audit-stat-label">' . esc_html__( 'Pages crawled', 'stratawp-seo' ) . '</span></div>';
			echo '<div class="swps-audit-stat"><span class="swps-audit-stat-num">' . esc_html( $last_run_date ) . '</span><span class="swps-audit-stat-label">' . esc_html__( 'Last run', 'stratawp-seo' ) . '</span></div>';
			echo '</div>';

			echo '<a class="swps-btn swps-btn-grad swps-audit-run-btn" href="' . esc_url( $start_url ) . '">' . esc_html__( 'Run audit now', 'stratawp-seo' ) . '</a>';
		}

		echo '</div>';
	}

	/**
	 * Errors / Warnings / Notices triage cards with deltas vs the previous run.
	 *
	 * @param array $severity_counts Current run's {error, warning, notice} counts.
	 * @param array $baseline        Previous run's {error, warning, notice} counts.
	 */
	private function render_triage_cards( array $severity_counts, array $baseline ): void {
		$cards = array(
			'error'   => __( 'Errors', 'stratawp-seo' ),
			'warning' => __( 'Warnings', 'stratawp-seo' ),
			'notice'  => __( 'Notices', 'stratawp-seo' ),
		);

		echo '<div class="swps-audit-triage">';
		foreach ( $cards as $severity => $label ) {
			$count = (int) ( $severity_counts[ $severity ] ?? 0 );
			$prev  = (int) ( $baseline[ $severity ] ?? 0 );
			echo '<div class="swps-tile swps-audit-triage-card is-' . esc_attr( $severity ) . '">';
			echo '<span class="swps-audit-triage-label">' . esc_html( $label ) . '</span>';
			echo '<span class="swps-audit-triage-num">' . esc_html( number_format_i18n( $count ) ) . '</span>';
			echo $this->format_delta( $count - $prev ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() in format_delta().
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Issue groups, one per check ID, sorted by severity then count.
	 *
	 * @param array $issues_by_type        Issue rows keyed by check id (SWPS_Crawl_Issues::issues_for_run()).
	 * @param array $catalog               Check id => SWPS_Crawl_Check instance.
	 * @param array $excluded              Currently-excluded check ids.
	 * @param array $baseline_issue_counts Check id => count, for the previous run.
	 */
	private function render_issue_groups( array $issues_by_type, array $catalog, array $excluded, array $baseline_issue_counts ): void {
		$types = array_keys( $issues_by_type );
		foreach ( $types as $type ) {
			if ( in_array( $type, $excluded, true ) ) {
				unset( $issues_by_type[ $type ] );
			}
		}
		$types = array_keys( $issues_by_type );

		usort(
			$types,
			function ( $a, $b ) use ( $issues_by_type, $catalog ) {
				$rank_a = self::SEVERITY_RANK[ $this->check_severity( $a, $issues_by_type, $catalog ) ] ?? 3;
				$rank_b = self::SEVERITY_RANK[ $this->check_severity( $b, $issues_by_type, $catalog ) ] ?? 3;
				if ( $rank_a !== $rank_b ) {
					return $rank_a <=> $rank_b;
				}
				return count( $issues_by_type[ $b ] ) <=> count( $issues_by_type[ $a ] );
			}
		);

		echo '<div class="swps-section-h" style="margin-top:24px"><h3>' . esc_html__( 'Issues', 'stratawp-seo' ) . '</h3></div>';

		if ( empty( $types ) ) {
			echo '<div class="swps-tile swps-audit-empty"><p>' . esc_html__( 'No issues found. Nice work!', 'stratawp-seo' ) . '</p></div>';
			return;
		}

		echo '<div class="swps-audit-issue-list">';
		foreach ( $types as $type ) {
			$rows     = $issues_by_type[ $type ];
			$check    = $catalog[ $type ] ?? null;
			$severity = $this->check_severity( $type, $issues_by_type, $catalog );
			$title    = $check ? $check->title() : $type;
			$how_to   = $check ? $check->how_to_fix() : '';
			$count    = count( $rows );
			$delta    = $count - (int) ( $baseline_issue_counts[ $type ] ?? 0 );

			$this->render_issue_group( $type, $title, $severity, $how_to, $rows, $count, $delta );
		}
		echo '</div>';
	}

	/**
	 * Resolve a check id's severity, preferring the catalog entry and falling
	 * back to the first issue row (covers ids no longer in the registry).
	 *
	 * @param string $type            Check id.
	 * @param array  $issues_by_type  Issue rows keyed by check id.
	 * @param array  $catalog         Check id => SWPS_Crawl_Check instance.
	 */
	private function check_severity( string $type, array $issues_by_type, array $catalog ): string {
		if ( isset( $catalog[ $type ] ) ) {
			return $catalog[ $type ]->severity();
		}
		return (string) ( $issues_by_type[ $type ][0]['severity'] ?? 'notice' );
	}

	/**
	 * Render a single issue group: title/badge/count/delta header, "How to
	 * fix" expander, capped page list, Copy URLs button, Exclude form.
	 *
	 * @param string $type     Check id.
	 * @param string $title    Human-readable title.
	 * @param string $severity 'error'|'warning'|'notice'.
	 * @param string $how_to   Remediation guidance.
	 * @param array  $rows     Issue rows for this check.
	 * @param int    $count    Total row count.
	 * @param int    $delta    Delta vs the previous run.
	 */
	private function render_issue_group( string $type, string $title, string $severity, string $how_to, array $rows, int $count, int $delta ): void {
		$shown      = array_slice( $rows, 0, self::PAGE_LIST_CAP );
		$urls       = wp_list_pluck( $shown, 'url' );
		$copy_value = implode( '|', array_map( 'esc_url', $urls ) );

		echo '<details class="swps-tile swps-audit-issue-group">';
		echo '<summary class="swps-audit-issue-summary">';
		echo '<span class="swps-badge swps-audit-badge-' . esc_attr( $severity ) . '">' . esc_html( ucfirst( $severity ) ) . '</span>';
		echo '<span class="swps-audit-issue-title">' . esc_html( $title ) . '</span>';
		echo '<span class="swps-audit-issue-count">' . esc_html( number_format_i18n( $count ) ) . '</span>';
		echo $this->format_delta( $delta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() in format_delta().
		echo '</summary>';

		echo '<div class="swps-audit-issue-body">';

		if ( '' !== $how_to ) {
			echo '<p class="swps-audit-how-to"><strong>' . esc_html__( 'How to fix:', 'stratawp-seo' ) . '</strong> ' . esc_html( $how_to ) . '</p>';
		}

		echo '<div class="swps-audit-issue-actions">';
		echo '<button type="button" class="swps-btn swps-btn-secondary" data-swps-copy-urls="' . esc_attr( $copy_value ) . '" data-copied="' . esc_attr__( 'Copied!', 'stratawp-seo' ) . '">' . esc_html__( 'Copy URLs', 'stratawp-seo' ) . '</button>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="swps-audit-exclude-form">';
		echo '<input type="hidden" name="action" value="swps_site_audit_exclude" />';
		echo '<input type="hidden" name="check_id" value="' . esc_attr( $type ) . '" />';
		wp_nonce_field( self::NONCE_EXCLUDE );
		echo '<button type="submit" class="swps-btn swps-btn-secondary">' . esc_html__( 'Exclude this check', 'stratawp-seo' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '<table class="widefat swps-audit-page-list"><thead><tr>';
		echo '<th>' . esc_html__( 'URL', 'stratawp-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'First seen', 'stratawp-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Detail', 'stratawp-seo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $shown as $row ) {
			$url        = (string) ( $row['url'] ?? '' );
			$first_seen = (int) ( $row['first_seen_run'] ?? 0 );
			$first_date = $first_seen > 0 ? date_i18n( get_option( 'date_format' ), $first_seen ) : '';

			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a></td>';
			echo '<td>' . esc_html( $first_date ) . '</td>';
			echo '<td>' . $this->render_issue_detail( (array) ( $row['detail'] ?? array() ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html()/esc_url() in render_issue_detail().
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $count > self::PAGE_LIST_CAP ) {
			echo '<p class="swps-audit-page-list-note">' . esc_html(
				sprintf(
					/* translators: 1: shown row count, 2: total row count */
					__( 'Showing %1$d of %2$d.', 'stratawp-seo' ),
					self::PAGE_LIST_CAP,
					$count
				)
			) . '</p>';
		}

		echo '</div>'; // .swps-audit-issue-body
		echo '</details>';
	}

	/**
	 * Render the per-row detail cell for known detail shapes (unminified
	 * assets, duplicate groups, title/description length), falling back to a
	 * generic key: value listing for anything else.
	 *
	 * @param array $detail Decoded issue detail array.
	 * @return string Escaped HTML.
	 */
	private function render_issue_detail( array $detail ): string {
		if ( ! empty( $detail['assets'] ) && is_array( $detail['assets'] ) ) {
			$items = array_map( 'esc_html', array_map( 'strval', $detail['assets'] ) );
			return '<span class="swps-audit-detail-assets">' . implode( ', ', $items ) . '</span>';
		}

		if ( ! empty( $detail['duplicate_of'] ) && is_array( $detail['duplicate_of'] ) ) {
			$links = array();
			foreach ( $detail['duplicate_of'] as $dupe_url ) {
				$links[] = '<a href="' . esc_url( (string) $dupe_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $dupe_url ) . '</a>';
			}
			return esc_html__( 'Also on:', 'stratawp-seo' ) . ' ' . implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $links entries are pre-escaped above.
		}

		if ( isset( $detail['length'] ) ) {
			return esc_html(
				sprintf(
					/* translators: %d: character count */
					__( 'Length: %d chars', 'stratawp-seo' ),
					(int) $detail['length']
				)
			);
		}

		if ( empty( $detail ) ) {
			return '';
		}

		$pairs = array();
		foreach ( $detail as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$pairs[] = esc_html( (string) $key ) . ': ' . esc_html( (string) $value );
		}
		return implode( '; ', $pairs );
	}

	/**
	 * Footer strip listing currently-excluded checks with a Re-enable form.
	 *
	 * @param array $excluded Currently-excluded check ids.
	 * @param array $catalog  Check id => SWPS_Crawl_Check instance.
	 */
	private function render_excluded_footer( array $excluded, array $catalog ): void {
		if ( empty( $excluded ) ) {
			return;
		}

		echo '<div class="swps-section-h" style="margin-top:24px"><h3>' . esc_html__( 'Excluded checks', 'stratawp-seo' ) . '</h3></div>';
		echo '<div class="swps-tile swps-audit-excluded-strip">';
		foreach ( $excluded as $check_id ) {
			$check_id = (string) $check_id;
			$check    = $catalog[ $check_id ] ?? null;
			$title    = $check ? $check->title() : $check_id;

			echo '<div class="swps-audit-excluded-row">';
			echo '<span>' . esc_html( $title ) . '</span>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="swps_site_audit_exclude" />';
			echo '<input type="hidden" name="check_id" value="' . esc_attr( $check_id ) . '" />';
			wp_nonce_field( self::NONCE_EXCLUDE );
			echo '<button type="submit" class="swps-btn swps-btn-secondary">' . esc_html__( 'Re-enable', 'stratawp-seo' ) . '</button>';
			echo '</form>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Trend strip: inline-SVG sparklines for score + error/warning counts
	 * across the retained runs.
	 *
	 * @param array $run_summaries Rolling run_id => summary map (swps_crawl_run_summaries option).
	 */
	private function render_trend_strip( array $run_summaries ): void {
		if ( count( $run_summaries ) < 2 ) {
			return;
		}

		ksort( $run_summaries );

		$scores   = array();
		$errors   = array();
		$warnings = array();
		foreach ( $run_summaries as $summary ) {
			$scores[]   = (int) ( $summary['score'] ?? 0 );
			$errors[]   = (int) ( $summary['severity']['error'] ?? 0 );
			$warnings[] = (int) ( $summary['severity']['warning'] ?? 0 );
		}

		echo '<div class="swps-section-h" style="margin-top:24px"><h3>' . esc_html__( 'Trend', 'stratawp-seo' ) . '</h3></div>';
		echo '<div class="swps-tile swps-audit-trend">';

		echo '<div class="swps-audit-trend-row"><span class="swps-audit-trend-label">' . esc_html__( 'Score', 'stratawp-seo' ) . '</span>';
		echo $this->render_sparkline( $scores, 'var(--swps-accent-1)' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() in render_sparkline().
		echo '</div>';

		echo '<div class="swps-audit-trend-row"><span class="swps-audit-trend-label">' . esc_html__( 'Errors', 'stratawp-seo' ) . '</span>';
		echo $this->render_sparkline( $errors, 'var(--swps-crit)' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() in render_sparkline().
		echo '</div>';

		echo '<div class="swps-audit-trend-row"><span class="swps-audit-trend-label">' . esc_html__( 'Warnings', 'stratawp-seo' ) . '</span>';
		echo $this->render_sparkline( $warnings, 'var(--swps-warn)' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() in render_sparkline().
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Build an inline SVG polyline sparkline.
	 *
	 * @param int[]  $values Data points, oldest first.
	 * @param string $stroke CSS color (may be a var() expression).
	 * @return string Safe HTML (all dynamic parts pass through esc_attr()).
	 */
	private function render_sparkline( array $values, string $stroke ): string {
		if ( empty( $values ) ) {
			return '';
		}

		$width  = 160;
		$height = 32;
		$max    = max( max( $values ), 1 );
		$min    = min( min( $values ), 0 );
		$range  = max( 1, $max - $min );
		$count  = count( $values );
		$step   = $count > 1 ? $width / ( $count - 1 ) : 0;

		$points = array();
		foreach ( array_values( $values ) as $i => $value ) {
			$x        = $count > 1 ? $i * $step : $width / 2;
			$y        = $height - ( ( $value - $min ) / $range ) * $height;
			$points[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}

		return sprintf(
			'<svg viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" class="swps-audit-spark" role="img" aria-hidden="true"><polyline points="%3$s" fill="none" stroke="%4$s" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>',
			$width,
			$height,
			esc_attr( implode( ' ', $points ) ),
			esc_attr( $stroke )
		);
	}

	/**
	 * Format a delta as a "+N"/"−N"/"0" chip.
	 *
	 * @param int $delta Signed delta.
	 * @return string Safe HTML.
	 */
	private function format_delta( int $delta ): string {
		if ( 0 === $delta ) {
			return '<span class="swps-audit-delta is-flat">&#177;0</span>';
		}
		$class = $delta > 0 ? 'is-up' : 'is-down';
		$text  = $delta > 0 ? '+' . $delta : (string) $delta;
		return '<span class="swps-audit-delta ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}
}
