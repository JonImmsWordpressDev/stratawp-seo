<?php
/**
 * Fetch-level checks: broken link, redirect loop, redirect chain.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags a URL that redirects in a cycle and never reaches a final response.
 */
class SWPS_Check_Redirect_Loop extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'redirect_loop';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'error';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Redirect loops', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The URL redirects in a cycle and never resolves. Fix the redirect rules so the chain reaches a 200 page.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( empty( $page['loop'] ) ) {
			return null;
		}
		return $this->issue(
			$page['url'],
			array(
				'hops'     => $page['hops'] ?? array(),
				'found_on' => $page['found_on'] ?? '',
			)
		);
	}
}

/**
 * Flags a URL that returned a 4xx/5xx status or no response at all.
 */
class SWPS_Check_Broken_Link extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'broken_link';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'error';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Broken pages and links', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The URL returned a 4xx/5xx status or no response. Fix the target page or update/remove links pointing to it.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$status = (int) ( $page['status_code'] ?? 0 );
		if ( $status < 400 && 0 !== $status ) {
			return null;
		}
		return $this->issue(
			$page['url'],
			array(
				'status'   => $status,
				'found_on' => $page['found_on'] ?? '',
			)
		);
	}
}

/**
 * Flags a URL that took 2+ redirect hops to reach its final destination.
 */
class SWPS_Check_Redirect_Chain extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'redirect_chain';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'warning';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Redirect chains', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The URL takes 2+ redirect hops. Point the original link (and any redirect rules) directly at the final destination.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( count( $page['hops'] ?? array() ) < 2 ) {
			return null;
		}
		return $this->issue(
			$page['url'],
			array(
				'hops'     => $page['hops'],
				'found_on' => $page['found_on'] ?? '',
			)
		);
	}
}
