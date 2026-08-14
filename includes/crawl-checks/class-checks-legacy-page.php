<?php
/**
 * Legacy per-page checks migrated from SWPS_Site_Crawler::classify():
 * canonical mismatch, missing/duplicate H1, mixed content, noindex-in-sitemap.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags a page whose declared canonical differs from the URL that actually
 * served the response.
 */
class SWPS_Check_Canonical_Mismatch extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'canonical_mismatch';
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
		return __( 'Canonical mismatches', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The page declares a canonical URL that differs from the URL that actually served the response. Point the canonical tag at the final (post-redirect) URL, or fix the redirect.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$canonical = $page['canonical'] ?? null;
		if ( null === $canonical ) {
			return null;
		}
		$home_host    = (string) ( $page['home_host'] ?? '' );
		$final_url    = (string) ( $page['final_url'] ?? ( $page['url'] ?? '' ) );
		$norm_fetched = SWPS_Site_Crawler::normalize_url( $final_url, $home_host );
		$norm_canon   = SWPS_Site_Crawler::normalize_url( $canonical, $home_host );
		if ( '' === $norm_fetched || '' === $norm_canon || $norm_fetched === $norm_canon ) {
			return null;
		}
		return $this->issue(
			$page['url'],
			array(
				'canonical' => $canonical,
				'found_on'  => $page['found_on'] ?? '',
			)
		);
	}
}

/**
 * Flags a page with no H1 heading.
 */
class SWPS_Check_Missing_H1 extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'missing_h1';
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
		return __( 'Missing H1 headings', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The page has no H1 heading. Add exactly one H1 that describes the page content.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$h1_count = (int) ( $page['h1_count'] ?? 0 );
		if ( 0 !== $h1_count ) {
			return null;
		}
		return $this->issue( $page['url'], array( 'found_on' => $page['found_on'] ?? '' ) );
	}
}

/**
 * Flags a page with more than one H1 heading.
 */
class SWPS_Check_Duplicate_H1 extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'duplicate_h1';
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
		return __( 'Duplicate H1 headings', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The page has more than one H1 heading. Keep a single H1 and demote the rest to H2/H3.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$h1_count = (int) ( $page['h1_count'] ?? 0 );
		if ( $h1_count <= 1 ) {
			return null;
		}
		return $this->issue(
			$page['url'],
			array(
				'h1_count' => $h1_count,
				'found_on' => $page['found_on'] ?? '',
			)
		);
	}
}

/**
 * Flags a page that loads one or more assets over insecure HTTP. One issue
 * per page, with the full list of offending assets in detail['assets'].
 */
class SWPS_Check_Mixed_Content extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'mixed_content';
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
		return __( 'Mixed content', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The page loads one or more assets over insecure HTTP. Update the asset URLs to HTTPS.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$mixed = $page['mixed'] ?? array();
		if ( empty( $mixed ) ) {
			return null;
		}
		return $this->issue(
			$page['url'],
			array(
				'assets'   => $mixed,
				'found_on' => $page['found_on'] ?? '',
			)
		);
	}
}

/**
 * Flags a post that declares meta-robots noindex but is not excluded from
 * the sitemap. The caller injects post_id + sitemap_excluded (WP lookups)
 * into the page facts before classify() runs.
 */
class SWPS_Check_Noindex_In_Sitemap extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'noindex_in_sitemap';
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
		return __( 'Noindexed pages still in the sitemap', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The page declares meta-robots noindex but is not excluded from the sitemap. Either remove noindex or exclude the post from the sitemap.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( empty( $page['has_noindex'] ) ) {
			return null;
		}
		$post_id = (int) ( $page['post_id'] ?? 0 );
		if ( $post_id <= 0 || ! empty( $page['sitemap_excluded'] ) ) {
			return null;
		}
		return $this->issue( $page['url'], array( 'post_id' => $post_id ) );
	}
}
