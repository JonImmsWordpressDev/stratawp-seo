<?php
/**
 * Aggregate (whole-run) checks: duplicate titles/descriptions, orphan pages,
 * and pages with a single incoming internal link.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groups page rows by a hash column, for duplicate-detection checks.
 *
 * Not itself a check; shared by SWPS_Check_Duplicate_Title and
 * SWPS_Check_Duplicate_Description.
 */
class SWPS_Crawl_Duplicate_Grouper {

	/**
	 * Group rows sharing a non-empty hash value, skipping non-2xx and
	 * challenge-page rows.
	 *
	 * @param array  $rows     Page rows (see SWPS_Crawl_Issues::pages_for_run()).
	 * @param string $hash_key Row key to group by, e.g. 'title_hash'.
	 * @return array<string, string[]> Hash => URLs, for groups of 2 or more.
	 */
	public static function group( array $rows, string $hash_key ): array {
		$by_hash = array();
		foreach ( $rows as $row ) {
			if ( (int) ( $row['status_code'] ?? 0 ) >= 300 ) {
				continue;
			}
			if ( SWPS_Crawl_Page_Flags::has( (int) ( $row['flags'] ?? 0 ), SWPS_Crawl_Page_Flags::IS_CHALLENGE ) ) {
				continue;
			}
			$hash = (string) ( $row[ $hash_key ] ?? '' );
			if ( '' === $hash ) {
				continue;
			}
			$by_hash[ $hash ][] = (string) ( $row['url'] ?? '' );
		}

		$groups = array();
		foreach ( $by_hash as $hash => $urls ) {
			if ( count( $urls ) >= 2 ) {
				$groups[ $hash ] = $urls;
			}
		}
		return $groups;
	}
}

/**
 * Counts incoming internal links per URL across a run's pages.
 *
 * Not itself a check; shared by SWPS_Check_Orphan_Page and
 * SWPS_Check_Single_Incoming_Link.
 */
class SWPS_Crawl_Link_Graph {

	/**
	 * Walk every row's internal_links list, counting references to each URL.
	 * Self-links are ignored.
	 *
	 * @param array $rows Page rows (see SWPS_Crawl_Issues::pages_for_run()).
	 * @return array<string, int> URL => incoming-link count.
	 */
	public static function incoming_counts( array $rows ): array {
		$counts = array();
		foreach ( $rows as $row ) {
			$self = (string) ( $row['url'] ?? '' );
			foreach ( (array) ( $row['internal_links'] ?? array() ) as $target ) {
				$target = (string) $target;
				if ( '' === $target || $target === $self ) {
					continue;
				}
				$counts[ $target ] = ( $counts[ $target ] ?? 0 ) + 1;
			}
		}
		return $counts;
	}
}

/**
 * Flags groups of 2+ pages sharing the same title.
 */
class SWPS_Check_Duplicate_Title extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'duplicate_title';
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
		return __( 'Pages with duplicate titles', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Give each page a unique, descriptive title tag via the Meta Editor.', 'stratawp-seo' );
	}

	/**
	 * Pure core: group rows sharing a non-empty title_hash.
	 *
	 * @param array $rows Page rows.
	 * @return array<string, string[]> title_hash => URLs, for groups of 2+.
	 */
	public static function find_duplicates( array $rows ): array {
		return SWPS_Crawl_Duplicate_Grouper::group( $rows, 'title_hash' );
	}

	/**
	 * Aggregate check over a finished run.
	 *
	 * @param int $run_id Run ID.
	 * @return array[] Issue rows.
	 */
	public function check_run( int $run_id ): array {
		$groups = self::find_duplicates( SWPS_Crawl_Issues::pages_for_run( $run_id ) );
		$issues = array();
		foreach ( $groups as $urls ) {
			foreach ( $urls as $url ) {
				$issues[] = $this->issue( $url, array( 'duplicate_of' => array_values( array_diff( $urls, array( $url ) ) ) ) );
			}
		}
		return $issues;
	}
}

/**
 * Flags groups of 2+ pages sharing the same meta description.
 */
class SWPS_Check_Duplicate_Description extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'duplicate_meta_description';
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
		return __( 'Pages with duplicate meta descriptions', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Give each page a unique meta description via the Meta Editor or Search Appearance description templates.', 'stratawp-seo' );
	}

	/**
	 * Pure core: group rows sharing a non-empty meta_desc_hash.
	 *
	 * @param array $rows Page rows.
	 * @return array<string, string[]> meta_desc_hash => URLs, for groups of 2+.
	 */
	public static function find_duplicates( array $rows ): array {
		return SWPS_Crawl_Duplicate_Grouper::group( $rows, 'meta_desc_hash' );
	}

	/**
	 * Aggregate check over a finished run.
	 *
	 * @param int $run_id Run ID.
	 * @return array[] Issue rows.
	 */
	public function check_run( int $run_id ): array {
		$groups = self::find_duplicates( SWPS_Crawl_Issues::pages_for_run( $run_id ) );
		$issues = array();
		foreach ( $groups as $urls ) {
			foreach ( $urls as $url ) {
				$issues[] = $this->issue( $url, array( 'duplicate_of' => array_values( array_diff( $urls, array( $url ) ) ) ) );
			}
		}
		return $issues;
	}
}

/**
 * Flags 200-status pages with zero incoming internal links (excluding the home URL).
 */
class SWPS_Check_Orphan_Page extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'orphan_page';
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
		return __( 'Orphan pages (no internal links)', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Link to this page from at least one other page or the navigation so crawlers and users can discover it.', 'stratawp-seo' );
	}

	/**
	 * Aggregate check over a finished run.
	 *
	 * @param int $run_id Run ID.
	 * @return array[] Issue rows.
	 */
	public function check_run( int $run_id ): array {
		$rows   = SWPS_Crawl_Issues::pages_for_run( $run_id );
		$counts = SWPS_Crawl_Link_Graph::incoming_counts( $rows );
		$home   = self::normalize_for_compare( home_url( '/' ) );

		$issues = array();
		foreach ( $rows as $row ) {
			if ( 200 !== (int) ( $row['status_code'] ?? 0 ) ) {
				continue;
			}
			if ( SWPS_Crawl_Page_Flags::has( (int) ( $row['flags'] ?? 0 ), SWPS_Crawl_Page_Flags::IS_CHALLENGE ) ) {
				continue;
			}
			$url = (string) ( $row['url'] ?? '' );
			if ( self::normalize_for_compare( $url ) === $home ) {
				continue;
			}
			if ( ( $counts[ $url ] ?? 0 ) > 0 ) {
				continue;
			}
			$issues[] = $this->issue( $url );
		}
		return $issues;
	}

	/**
	 * Strip a trailing slash for loose URL comparison against the home URL.
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized URL.
	 */
	private static function normalize_for_compare( string $url ): string {
		$trimmed = rtrim( $url, '/' );
		return '' === $trimmed ? '/' : $trimmed;
	}
}

/**
 * Flags 200-status pages with exactly one incoming internal link.
 */
class SWPS_Check_Single_Incoming_Link extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'single_incoming_link';
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
		return __( 'Pages with only one incoming internal link', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Add contextual links to this page from other relevant pages to strengthen its internal link equity.', 'stratawp-seo' );
	}

	/**
	 * Aggregate check over a finished run.
	 *
	 * @param int $run_id Run ID.
	 * @return array[] Issue rows.
	 */
	public function check_run( int $run_id ): array {
		$rows   = SWPS_Crawl_Issues::pages_for_run( $run_id );
		$counts = SWPS_Crawl_Link_Graph::incoming_counts( $rows );

		$issues = array();
		foreach ( $rows as $row ) {
			if ( 200 !== (int) ( $row['status_code'] ?? 0 ) ) {
				continue;
			}
			if ( SWPS_Crawl_Page_Flags::has( (int) ( $row['flags'] ?? 0 ), SWPS_Crawl_Page_Flags::IS_CHALLENGE ) ) {
				continue;
			}
			$url = (string) ( $row['url'] ?? '' );
			if ( 1 === ( $counts[ $url ] ?? 0 ) ) {
				$issues[] = $this->issue( $url );
			}
		}
		return $issues;
	}
}
