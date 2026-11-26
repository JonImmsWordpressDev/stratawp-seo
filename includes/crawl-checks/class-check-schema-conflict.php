<?php
/**
 * Crawl check: conflicting JSON-LD on a page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags pages whose JSON-LD blocks disagree: two Organization or WebSite
 * entities, one @id with conflicting @types, same-site @id references to
 * nothing, a Person publisher next to an Organization publisher, or blocks
 * that fail to parse.
 */
class SWPS_Check_Schema_Conflict extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'schema_conflict';
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
		return __( 'Pages have conflicting structured data', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The JSON-LD blocks on the page disagree, usually because more than one plugin or the theme outputs schema. Each finding names the block number (in page order) and its first characters, so you can find the plugin that emits it. Turn off schema output in the plugin you do not want, or make both use the same @id for the site Organization and WebSite.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$blocks = $page['jsonld_blocks'] ?? array();
		if ( ! is_array( $blocks ) || array() === $blocks ) {
			return null;
		}

		$url    = (string) ( $page['url'] ?? '' );
		$result = SWPS_Schema_Conflict_Detector::analyze( $blocks, $url );
		if ( array() === $result['conflicts'] && array() === $result['invalid_blocks'] ) {
			return null;
		}

		return $this->issue(
			$url,
			array(
				'summary'        => SWPS_Schema_Conflict_Detector::summarize( $result ),
				'conflicts'      => $result['conflicts'],
				'invalid_blocks' => $result['invalid_blocks'],
				'block_count'    => count( $blocks ),
			)
		);
	}
}
