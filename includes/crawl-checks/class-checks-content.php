<?php
/**
 * Content and asset checks: meta description, word count, text/HTML ratio,
 * image alt text, hreflang validity, schema, nofollow internal links,
 * response compression, and asset minification.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags a page with an empty meta description, unless it's paginated or noindexed.
 */
class SWPS_Check_Missing_Meta_Description extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'missing_meta_description';
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
		return __( 'Pages missing a meta description', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Add a meta description via the Meta Editor or Search Appearance description templates. Paginated archive pages intentionally have none and are not flagged.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( ! empty( $page['is_paginated'] ) || ! empty( $page['has_noindex'] ) ) {
			return null;
		}
		$meta_desc = (string) ( $page['meta_desc'] ?? '' );
		if ( '' === trim( $meta_desc ) ) {
			return $this->issue( $page['url'] );
		}
		return null;
	}
}

/**
 * Flags a page whose meta description exceeds 160 characters.
 */
class SWPS_Check_Desc_Too_Long extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'desc_too_long';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'notice';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Meta descriptions are too long', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The meta description exceeds 160 characters and will be truncated in search results. Trim it down to the key selling point for the page.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$meta_desc = (string) ( $page['meta_desc'] ?? '' );
		$length    = mb_strlen( $meta_desc );
		if ( $length > 160 ) {
			return $this->issue(
				$page['url'],
				array(
					'length' => $length,
				)
			);
		}
		return null;
	}
}

/**
 * Flags a non-archive page with fewer than 200 words of body text.
 */
class SWPS_Check_Low_Word_Count extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'low_word_count';
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
		return __( 'Pages have thin content', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The page has fewer than 200 words of body text (link and navigation text excluded). Expand the content so it thoroughly covers the topic, or noindex the page if it is not meant to rank. Archive pages are exempt from this check.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( ! empty( $page['is_archive'] ) ) {
			return null;
		}
		$word_count = (int) ( $page['word_count'] ?? 0 );
		if ( $word_count < 200 ) {
			return $this->issue(
				$page['url'],
				array(
					'word_count' => $word_count,
				)
			);
		}
		return null;
	}
}

/**
 * Flags a page whose visible text is a small fraction of the total HTML weight.
 */
class SWPS_Check_Low_Text_Html_Ratio extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'low_text_html_ratio';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'notice';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Pages have a low text-to-HTML ratio', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Visible text makes up less than 10% of the page weight, which usually points to markup bloat, excessive inline scripts/styles, or very little real content. Trim unnecessary markup or add substantive copy.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$html_bytes = (int) ( $page['html_bytes'] ?? 0 );
		if ( $html_bytes <= 0 ) {
			return null;
		}
		$text_bytes = (int) ( $page['text_bytes'] ?? 0 );
		$ratio      = $text_bytes / $html_bytes;
		if ( $ratio < 0.10 ) {
			return $this->issue(
				$page['url'],
				array(
					'ratio' => $ratio,
				)
			);
		}
		return null;
	}
}

/**
 * Flags a page with one or more images missing alt text.
 */
class SWPS_Check_Image_Missing_Alt extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'image_missing_alt';
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
		return __( 'Images are missing alt text', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Add descriptive alt text to every content image. Alt text helps screen readers, image search, and gives search engines context when the image itself cannot be understood.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 * One issue per page, listing every image missing alt text.
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$images = (array) ( $page['images_missing_alt'] ?? array() );
		if ( ! empty( $images ) ) {
			return $this->issue(
				$page['url'],
				array(
					'images' => $images,
				)
			);
		}
		return null;
	}
}

/**
 * Flags a page whose hreflang annotations are malformed or conflicting.
 */
class SWPS_Check_Hreflang_Invalid extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'hreflang_invalid';
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
		return __( 'Hreflang annotations are invalid', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Every hreflang value must be a valid language code (e.g. "en", "en-US") or "x-default", and each language may only point at one URL. Fix or remove the malformed/conflicting entries — search engines ignore the whole hreflang set on a page when they cannot parse it.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$hreflangs = (array) ( $page['hreflangs'] ?? array() );
		if ( empty( $hreflangs ) ) {
			return null;
		}

		$seen_hrefs = array();
		foreach ( $hreflangs as $entry ) {
			$lang = (string) ( $entry['lang'] ?? '' );
			$href = (string) ( $entry['href'] ?? '' );

			if ( '' === $lang || ! preg_match( '/^[a-z]{2}(-[A-Za-z]{2})?$|^x-default$/', $lang ) ) {
				return $this->issue(
					$page['url'],
					array(
						'hreflangs' => $hreflangs,
					)
				);
			}

			if ( isset( $seen_hrefs[ $lang ] ) && $seen_hrefs[ $lang ] !== $href ) {
				return $this->issue(
					$page['url'],
					array(
						'hreflangs' => $hreflangs,
					)
				);
			}
			$seen_hrefs[ $lang ] = $href;
		}

		return null;
	}
}

/**
 * Flags a non-archive, indexable page with no structured data.
 */
class SWPS_Check_Missing_Schema extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'missing_schema';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'notice';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Pages have no structured data', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'No JSON-LD schema was found on the page. Add appropriate structured data (Article, Product, FAQ, etc.) to become eligible for rich results. Archive pages and noindexed pages are exempt from this check.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( ! empty( $page['is_archive'] ) || ! empty( $page['has_noindex'] ) ) {
			return null;
		}
		if ( empty( $page['has_schema'] ) ) {
			return $this->issue( $page['url'] );
		}
		return null;
	}
}

/**
 * Flags a page that rel=nofollow's a link to its own domain.
 */
class SWPS_Check_Nofollow_Internal extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'nofollow_internal_link';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'notice';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Pages nofollow their own internal links', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'A link pointing back at this site carries rel="nofollow". Nofollow is meant for untrusted or paid outbound links — remove it from internal links so link equity flows through your own site.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$home_host = (string) ( $page['home_host'] ?? '' );
		$urls      = (array) ( $page['nofollow_internal'] ?? array() );

		$matches = array();
		foreach ( $urls as $url ) {
			// SWPS_Site_Crawler::is_internal() handles the www./case
			// variants a manual host === comparison misses.
			if ( '' !== $home_host && SWPS_Site_Crawler::is_internal( (string) $url, $home_host ) ) {
				$matches[] = $url;
			}
		}

		if ( ! empty( $matches ) ) {
			return $this->issue(
				$page['url'],
				array(
					'links' => $matches,
				)
			);
		}
		return null;
	}
}

/**
 * Flags a page that was served without gzip/brotli compression.
 */
class SWPS_Check_Uncompressed_Page extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'uncompressed_page';
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
		return __( 'Pages are served uncompressed', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Enable gzip or brotli compression for HTML responses at the server or host level.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( empty( $page['is_compressed'] ) ) {
			return $this->issue( $page['url'] );
		}
		return null;
	}
}

/**
 * Flags a page that loads first-party JS/CSS assets which don't look minified.
 */
class SWPS_Check_Unminified_Assets extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'unminified_assets';
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
		return __( 'Pages load unminified assets', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Ship minified builds of first-party JS/CSS and enqueue them with the SCRIPT_DEBUG suffix convention.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$assets = (array) ( $page['unminified_assets'] ?? array() );
		if ( ! empty( $assets ) ) {
			return $this->issue(
				$page['url'],
				array(
					'assets' => $assets,
				)
			);
		}
		return null;
	}
}
