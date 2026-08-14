<?php
/**
 * Site Crawl audit module — READ-ONLY summary card for the latest stored
 * crawl run.
 *
 * Never crawls. run() reads the swps_crawl_last_summary option written by
 * SWPS_Site_Crawler::finish_run() and converts it to the standard
 * audit-result contract. SWPS_SEO_Audit::run_all() is synchronous, so
 * anything slower than an option read here would block the audit page. The
 * full per-issue breakdown, triage, and drill-downs live on the Site Audit
 * dashboard (swps-site-audit); this module just scores and links to it.
 *
 * Registered via the swps_audit_modules filter (see register()).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only crawl-results audit module.
 */
class SWPS_Site_Crawl_Module extends SWPS_Audit_Module {

	/**
	 * Filter callback: append this module to the registered audit modules.
	 *
	 * Hooked to swps_audit_modules in stratawp-seo.php.
	 *
	 * @param SWPS_Audit_Module[] $modules Modules keyed by ID.
	 * @return SWPS_Audit_Module[]
	 */
	public static function register( array $modules ): array {
		$module                       = new self();
		$modules[ $module->get_id() ] = $module;
		return $modules;
	}

	/**
	 * Module identifier.
	 */
	public function get_id(): string {
		return 'site_crawl';
	}

	/**
	 * Human-readable module name.
	 */
	public function get_label(): string {
		return __( 'Site Crawl', 'stratawp-seo' );
	}

	/**
	 * This module is informational; fixes happen on the Site Audit dashboard.
	 */
	public function can_auto_fix(): bool {
		return false;
	}

	/**
	 * Read the latest stored crawl summary; never crawls.
	 *
	 * Renders as a summary card only — the full per-issue breakdown, triage,
	 * and drill-downs live on the Site Audit dashboard (swps-site-audit).
	 *
	 * @return array { score, status, issues, summary } per the base contract.
	 */
	public function run(): array {
		$summary = get_option( SWPS_Site_Crawler::OPT_LAST_SUMMARY, array() );

		$audit_page = admin_url( 'admin.php?page=swps-site-audit' );

		if ( empty( $summary ) || empty( $summary['run_id'] ) ) {
			return array(
				'score'   => 100,
				'status'  => 'pass',
				'issues'  => array(
					array(
						'post_id' => null,
						'message' => sprintf(
							/* translators: %s: Site Audit dashboard URL */
							__( 'No crawl has run yet. Start one from the Site Audit dashboard: %s', 'stratawp-seo' ),
							$audit_page
						),
						'fixable' => false,
					),
				),
				'summary' => __( 'No crawl results yet — run a site audit to populate this check.', 'stratawp-seo' ),
			);
		}

		$crawled = (int) ( $summary['crawled'] ?? 0 );

		// Severity split has been stored since 4.25.1: the crawler already
		// downgrades external bot-walls etc. to warnings, so error-level
		// points only come from genuine errors.
		$severity_counts = (array) ( $summary['severity_counts'] ?? array() );
		$errors          = (int) ( $severity_counts['error'] ?? 0 );
		$warnings        = (int) ( $severity_counts['warning'] ?? 0 );
		$notices         = (int) ( $severity_counts['notice'] ?? 0 );

		// Score: start at 100, error-severity issues cost 10 each, warnings
		// and notices 2 each (capped at 0).
		$others = $warnings + $notices;
		$score  = max( 0, 100 - ( $errors * 10 ) - ( $others * 2 ) );

		// A clean crawl (0/0/0) must report no issues — otherwise every
		// passing module still shows a "1 issue" badge and toggle on the
		// legacy SEO Audit screen, which is wrong for a module with nothing
		// to report.
		$issues = array();
		if ( $errors > 0 || $warnings > 0 || $notices > 0 ) {
			$issues[] = array(
				'post_id' => null,
				'message' => sprintf(
					/* translators: 1: error count, 2: warning count, 3: notice count, 4: Site Audit dashboard URL */
					__( '%1$d errors, %2$d warnings, %3$d notices from the latest crawl. View full Site Audit → %4$s', 'stratawp-seo' ),
					$errors,
					$warnings,
					$notices,
					$audit_page
				),
				'fixable' => false,
			);
		}

		return array(
			'score'   => $score,
			'status'  => $this->status_from_score( $score ),
			'issues'  => $issues,
			'summary' => sprintf(
				/* translators: 1: 0-100 health score, 2: crawled page count */
				__( 'Site Audit score: %1$d/100 across %2$d crawled pages.', 'stratawp-seo' ),
				$score,
				$crawled
			),
		);
	}
}
