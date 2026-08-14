<?php
// tests/unit/CrawlChecksAggregateTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-page-flags.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-checks-aggregate.php';

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
		$rows = array(
			$this->row( 'https://x.test/', array( 'internal_links' => array( 'https://x.test/a/', 'https://x.test/b/' ) ) ),
			$this->row( 'https://x.test/a/', array( 'internal_links' => array( 'https://x.test/b/' ) ) ),
			$this->row( 'https://x.test/b/' ),
			$this->row( 'https://x.test/orphan/' ),
		);
		$counts = SWPS_Crawl_Link_Graph::incoming_counts( $rows );
		$this->assertSame( 2, $counts['https://x.test/b/'] );
		$this->assertSame( 1, $counts['https://x.test/a/'] );
		$this->assertArrayNotHasKey( 'https://x.test/orphan/', $counts );
	}

	public function test_self_links_ignored(): void {
		$rows   = array( $this->row( 'https://x.test/a/', array( 'internal_links' => array( 'https://x.test/a/' ) ) ) );
		$this->assertSame( array(), SWPS_Crawl_Link_Graph::incoming_counts( $rows ) );
	}
}
