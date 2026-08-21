<?php
// tests/unit/CrawlScoreTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-score.php';

final class CrawlScoreTest extends TestCase {
	public function test_no_issues_is_100(): void {
		$this->assertSame( 100, SWPS_Crawl_Score::calculate( array( 'error' => 0, 'warning' => 0, 'notice' => 0 ), 50 ) );
	}
	public function test_notices_are_free(): void {
		$this->assertSame( 100, SWPS_Crawl_Score::calculate( array( 'error' => 0, 'warning' => 0, 'notice' => 40 ), 50 ) );
	}
	public function test_errors_weigh_five_warnings_one(): void {
		// 10 errors on 50 pages: 1 - 50/250 = 0.8.
		$this->assertSame( 80, SWPS_Crawl_Score::calculate( array( 'error' => 10, 'warning' => 0, 'notice' => 0 ), 50 ) );
		// 50 warnings on 50 pages: 1 - 50/250 = 0.8.
		$this->assertSame( 80, SWPS_Crawl_Score::calculate( array( 'error' => 0, 'warning' => 50, 'notice' => 0 ), 50 ) );
	}
	public function test_clamps_at_zero_and_handles_zero_pages(): void {
		$this->assertSame( 0, SWPS_Crawl_Score::calculate( array( 'error' => 999, 'warning' => 0, 'notice' => 0 ), 10 ) );
		$this->assertSame( 100, SWPS_Crawl_Score::calculate( array( 'error' => 0, 'warning' => 0, 'notice' => 0 ), 0 ) );
	}
	public function test_project_subtracts_fixed_counts(): void {
		$base = SWPS_Crawl_Score::calculate( array( 'error' => 2, 'warning' => 10 ), 10 );
		$proj = SWPS_Crawl_Score::project(
			array( 'error' => 2, 'warning' => 10 ),
			array( 'warning' => 6 ),
			10
		);
		$this->assertGreaterThan( $base, $proj );
		$this->assertSame( SWPS_Crawl_Score::calculate( array( 'error' => 2, 'warning' => 4 ), 10 ), $proj );
	}
	public function test_project_clamps_negative_counts(): void {
		$proj = SWPS_Crawl_Score::project(
			array( 'error' => 1 ),
			array( 'error' => 5, 'warning' => 5 ),
			10
		);
		$this->assertSame( SWPS_Crawl_Score::calculate( array(), 10 ), $proj );
	}
}
