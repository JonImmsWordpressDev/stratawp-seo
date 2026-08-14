<?php
// tests/unit/CrawlChecksAggregateTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-page-flags.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-checks-aggregate.php';
require_once __DIR__ . '/../../includes/class-site-crawler.php';

final class CrawlChecksAggregateTest extends TestCase {

	private function row( string $url, array $over = array() ): array {
		return array_merge(
			array(
				'url'            => $url,
				'status_code'    => 200,
				'title_hash'     => md5( $url ),
				'meta_desc_hash' => md5( 'd' . $url ),
				'flags'          => 0,
				'internal_links' => array(),
			),
			$over
		);
	}

	public function test_duplicate_titles_grouped(): void {
		$rows = array(
			$this->row( 'https://x.test/a/', array( 'title_hash' => 'same' ) ),
			$this->row( 'https://x.test/b/', array( 'title_hash' => 'same' ) ),
			$this->row( 'https://x.test/c/' ),
		);
		$groups = SWPS_Check_Duplicate_Title::find_duplicates( $rows );
		$this->assertSame( array( 'same' => array( 'https://x.test/a/', 'https://x.test/b/' ) ), $groups );
	}

	public function test_duplicates_skip_challenge_and_redirect_rows(): void {
		$rows = array(
			$this->row( 'https://x.test/a/', array( 'title_hash' => 'same' ) ),
			$this->row( 'https://x.test/b/', array( 'title_hash' => 'same', 'flags' => SWPS_Crawl_Page_Flags::IS_CHALLENGE ) ),
			$this->row( 'https://x.test/c/', array( 'title_hash' => 'same', 'status_code' => 301 ) ),
		);
		$this->assertSame( array(), SWPS_Check_Duplicate_Title::find_duplicates( $rows ) );
	}

	public function test_empty_hash_never_groups(): void {
		$rows = array(
			$this->row( 'https://x.test/a/', array( 'meta_desc_hash' => '' ) ),
			$this->row( 'https://x.test/b/', array( 'meta_desc_hash' => '' ) ),
		);
		$this->assertSame( array(), SWPS_Check_Duplicate_Description::find_duplicates( $rows ) );
	}

	public function test_incoming_counts_and_orphans(): void {
		// internal_links entries are stored in normalize_url()'s real
		// scheme-relative format ('//host/path', no trailing slash), never
		// as the absolute URLs the row itself is keyed by.
		$rows = array(
			$this->row( 'https://x.test/', array( 'internal_links' => array( '//x.test/a', '//x.test/b' ) ) ),
			$this->row( 'https://x.test/a/', array( 'internal_links' => array( '//x.test/b' ) ) ),
			$this->row( 'https://x.test/b/' ),
			$this->row( 'https://x.test/orphan/' ),
		);
		$counts = SWPS_Crawl_Link_Graph::incoming_counts( $rows );
		$this->assertSame( 2, $counts['//x.test/b'] );
		$this->assertSame( 1, $counts['//x.test/a'] );
		$this->assertArrayNotHasKey( '//x.test/orphan', $counts );
	}

	public function test_self_links_ignored(): void {
		// The row's own url ('https://x.test/a/', absolute) must be
		// normalized before comparing it to its self-link ('//x.test/a',
		// already-normalized) — otherwise the two formats never match and
		// the self-link is (wrongly) counted as incoming.
		$rows = array( $this->row( 'https://x.test/a/', array( 'internal_links' => array( '//x.test/a' ) ) ) );
		$this->assertSame( array(), SWPS_Crawl_Link_Graph::incoming_counts( $rows ) );
	}

	/**
	 * Regression for the link-graph URL format contract bug: a page's own
	 * row url is absolute ('https://host/a/') while the count it needs to
	 * look itself up under is keyed by the normalized format another page's
	 * internal_links recorded it in ('//host/a'). count_for_url() is the
	 * exact lookup SWPS_Check_Orphan_Page and SWPS_Check_Single_Incoming_Link
	 * perform in check_run() — proving it here proves the page is NOT
	 * treated as orphaned once normalized.
	 */
	public function test_count_for_url_normalizes_row_url_against_stored_link_format(): void {
		$rows = array(
			$this->row( 'https://host/linker/', array( 'internal_links' => array( '//host/a' ) ) ),
			$this->row( 'https://host/a/' ),
		);
		$counts = SWPS_Crawl_Link_Graph::incoming_counts( $rows );

		// Naive (pre-fix) lookup by the raw absolute url would find nothing.
		$this->assertArrayNotHasKey( 'https://host/a/', $counts );

		// The normalized lookup finds the incoming link, so this page is not orphaned.
		$this->assertSame( 1, SWPS_Crawl_Link_Graph::count_for_url( $counts, 'https://host/a/', 'host' ) );
	}
}
