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
}
