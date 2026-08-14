<?php
/**
 * Head element and challenge detection checks:
 * title (missing/too-long/too-short), viewport, doctype, charset, lang, and challenge pages.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags a page whose title tag is missing, empty, or contains only whitespace.
 */
class SWPS_Check_Missing_Title extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'missing_title';
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
		return __( "Pages don't have title tags", 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Every page needs a unique, descriptive <title>. Check the Search Appearance title templates for this page type — an empty template variable renders as no title at all.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$title = (string) ( $page['title'] ?? '' );
		if ( '' === trim( $title ) ) {
			return $this->issue( $page['url'] );
		}
		return null;
	}
}

/**
 * Flags a page whose title tag exceeds 60 characters.
 */
class SWPS_Check_Title_Too_Long extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'title_too_long';
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
		return __( 'Title tags are too long', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The title tag exceeds 60 characters and will be truncated in search results. Keep titles concise and scannable.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$title  = (string) ( $page['title'] ?? '' );
		$length = strlen( $title );
		if ( $length > 60 ) {
			return $this->issue(
				$page['url'],
				array(
					'length' => $length,
					'title'  => $title,
				)
			);
		}
		return null;
	}
}

/**
 * Flags a page whose title tag is shorter than 15 characters (but non-empty).
 */
class SWPS_Check_Title_Too_Short extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'title_too_short';
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
		return __( 'Title tags are too short', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The title tag is too short to effectively convey your topic to both users and search engines. Expand it to describe the page content in 15-60 characters.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$title  = (string) ( $page['title'] ?? '' );
		$length = strlen( $title );
		// Only flag if non-empty and shorter than 15 characters.
		// Empty titles are missing_title's job.
		if ( $length > 0 && $length < 15 ) {
			return $this->issue(
				$page['url'],
				array(
					'length' => $length,
					'title'  => $title,
				)
			);
		}
		return null;
	}
}

/**
 * Flags a page missing a viewport meta tag.
 */
class SWPS_Check_Missing_Viewport extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'missing_viewport';
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
		return __( 'Pages missing viewport meta tag', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Add a viewport meta tag to enable responsive design: <meta name="viewport" content="width=device-width, initial-scale=1.0">. This improves mobile usability and search rankings.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( empty( $page['has_viewport'] ) ) {
			return $this->issue( $page['url'] );
		}
		return null;
	}
}

/**
 * Flags a page missing a DOCTYPE declaration.
 */
class SWPS_Check_Missing_Doctype extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'missing_doctype';
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
		return __( 'Pages missing DOCTYPE', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Add a DOCTYPE declaration at the very beginning of the HTML document: <!DOCTYPE html>. This signals to browsers to use standards mode and helps crawlers parse your page correctly.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( empty( $page['has_doctype'] ) ) {
			return $this->issue( $page['url'] );
		}
		return null;
	}
}

/**
 * Flags a page missing a character encoding declaration.
 */
class SWPS_Check_Missing_Charset extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'missing_charset';
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
		return __( 'Pages missing character encoding', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Declare the character encoding in the <head>: <meta charset="utf-8">. UTF-8 is recommended for international content and prevents encoding-related rendering issues.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( empty( $page['has_charset'] ) ) {
			return $this->issue( $page['url'] );
		}
		return null;
	}
}

/**
 * Flags a page missing a language declaration.
 */
class SWPS_Check_Missing_Lang extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'missing_lang';
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
		return __( 'Pages missing language declaration', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Add a language attribute to the <html> tag: <html lang="en">. This helps search engines, screen readers, and browsers understand and properly render your content.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( empty( $page['has_lang'] ) ) {
			return $this->issue( $page['url'] );
		}
		return null;
	}
}

/**
 * Flags a page that served a bot-challenge (CAPTCHA) instead of the actual page content.
 */
class SWPS_Check_Challenge_Page extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'challenge_page_detected';
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
		return __( 'Bot-challenge pages served to the crawler', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The server answered with an anti-bot challenge (CAPTCHA) instead of the page. Your host is likely challenging crawler user agents — whitelist legitimate crawlers with your host, or these URLs will look broken to every audit tool and some search bots.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( ! empty( $page['is_challenge'] ) ) {
			return $this->issue( $page['url'] );
		}
		return null;
	}
}
