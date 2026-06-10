<?php
/**
 * Site Crawl audit module — READ-ONLY view of the latest stored crawl run.
 *
 * Never crawls. run() reads the swps_crawl_last_summary option written by
 * SWPS_Site_Crawler::finish_run() and converts the per-type issue counts to
 * the standard audit-result contract. SWPS_SEO_Audit::run_all() is synchronous,
 * so anything slower than an option read here would block the audit page.
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
	 * This module is informational; fixes happen on the Site Crawl page.
	 */
	public function can_auto_fix(): bool {
		return false;
	}

	/**
	 * Read the latest stored crawl summary; never crawls.
	 *
	 * @return array { score, status, issues, summary } per the base contract.
	 */
	public function run(): array {
		$summary = get_option( SWPS_Site_Crawler::OPT_LAST_SUMMARY, array() );

		$crawl_page = admin_url( 'admin.php?page=swps-site-crawl' );

		if ( empty( $summary ) || empty( $summary['run_id'] ) ) {
			return array(
				'score'   => 100,
				'status'  => 'pass',
				'issues'  => array(
					array(
						'post_id' => null,
						'message' => sprintf(
							/* translators: %s: Site Crawl admin page URL */
							__( 'No crawl has run yet. Start one from the Site Crawl page: %s', 'stratawp-seo' ),
							$crawl_page
						),
						'fixable' => false,
					),
				),
				'summary' => __( 'No crawl results yet — run a site crawl to populate this check.', 'stratawp-seo' ),
			);
		}

		$counts  = (array) ( $summary['issue_counts'] ?? array() );
		$crawled = (int) ( $summary['crawled'] ?? 0 );

		$total  = 0;
		$errors = 0;
		$issues = array();

		$labels = array(
			'broken_link'        => __( 'broken links', 'stratawp-seo' ),
			'redirect_chain'     => __( 'redirect chains', 'stratawp-seo' ),
			'canonical_mismatch' => __( 'canonical mismatches', 'stratawp-seo' ),
			'missing_h1'         => __( 'pages missing an H1', 'stratawp-seo' ),
			'duplicate_h1'       => __( 'pages with duplicate H1s', 'stratawp-seo' ),
			'mixed_content'      => __( 'mixed-content assets', 'stratawp-seo' ),
		);

		foreach ( $counts as $row ) {
			$type   = (string) ( $row['type'] ?? '' );
			$cnt    = (int) ( $row['cnt'] ?? 0 );
			$total += $cnt;

			if ( 'broken_link' === $type ) {
				$errors += $cnt;
			}

			$issues[] = array(
				'post_id' => null,
				'message' => sprintf(
					/* translators: 1: issue count, 2: issue-type label, 3: Site Crawl admin page URL */
					__( '%1$d %2$s found — review and fix on the Site Crawl page: %3$s', 'stratawp-seo' ),
					$cnt,
					$labels[ $type ] ?? $type,
					$crawl_page
				),
				'fixable' => false,
			);
		}

		// Score: start at 100, broken links cost 10 each, other issues 2 each
		// (capped at 0). Mirrors the severity split used by the crawler.
		$others = max( 0, $total - $errors );
		$score  = max( 0, 100 - ( $errors * 10 ) - ( $others * 2 ) );

		return array(
			'score'   => $score,
			'status'  => $this->status_from_score( $score ),
			'issues'  => $issues,
			'summary' => sprintf(
				/* translators: 1: total issue count, 2: crawled page count */
				__( '%1$d issues across %2$d crawled pages (latest run).', 'stratawp-seo' ),
				$total,
				$crawled
			),
		);
	}
}
