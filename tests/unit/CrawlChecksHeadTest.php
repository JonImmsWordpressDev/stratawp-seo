<?php
/**
 * Tests for head-integrity checks and challenge detection.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-checks-head.php';

final class CrawlChecksHeadTest extends TestCase {

	private function facts( array $over = array() ): array {
		return array_merge(
			array(
				'url'          => 'https://x.test/p/',
				'status_code'  => 200,
				'title'        => 'A perfectly reasonable page title here',
				'has_viewport' => true,
				'has_doctype'  => true,
				'has_charset'  => true,
				'has_lang'     => true,
				'is_challenge' => false,
			),
			$over
		);
	}

	public function test_healthy_page_raises_nothing(): void {
		$checks = array(
			new SWPS_Check_Missing_Title(), new SWPS_Check_Title_Too_Long(), new SWPS_Check_Title_Too_Short(),
			new SWPS_Check_Missing_Viewport(), new SWPS_Check_Missing_Doctype(),
			new SWPS_Check_Missing_Charset(), new SWPS_Check_Missing_Lang(), new SWPS_Check_Challenge_Page(),
		);
		foreach ( $checks as $check ) {
			$this->assertNull( $check->check_page( $this->facts() ), $check->id() );
		}
	}

	public function test_missing_and_empty_title(): void {
		$check = new SWPS_Check_Missing_Title();
		$this->assertSame( 'missing_title', $check->check_page( $this->facts( array( 'title' => '' ) ) )['type'] );
		$this->assertNotNull( $check->check_page( $this->facts( array( 'title' => '   ' ) ) ) );
	}

	public function test_title_length_boundaries(): void {
		$long  = new SWPS_Check_Title_Too_Long();
		$short = new SWPS_Check_Title_Too_Short();
		$this->assertNull( $long->check_page( $this->facts( array( 'title' => str_repeat( 'a', 60 ) ) ) ) );
		$this->assertNotNull( $long->check_page( $this->facts( array( 'title' => str_repeat( 'a', 61 ) ) ) ) );
		$this->assertNull( $short->check_page( $this->facts( array( 'title' => str_repeat( 'a', 15 ) ) ) ) );
		$this->assertNotNull( $short->check_page( $this->facts( array( 'title' => str_repeat( 'a', 14 ) ) ) ) );
		$this->assertNull( $short->check_page( $this->facts( array( 'title' => '' ) ) ) ); // missing_title's job
	}

	public function test_flag_checks_fire_when_flag_absent(): void {
		$this->assertSame( 'missing_viewport', ( new SWPS_Check_Missing_Viewport() )->check_page( $this->facts( array( 'has_viewport' => false ) ) )['type'] );
		$this->assertSame( 'missing_doctype', ( new SWPS_Check_Missing_Doctype() )->check_page( $this->facts( array( 'has_doctype' => false ) ) )['type'] );
		$this->assertSame( 'missing_charset', ( new SWPS_Check_Missing_Charset() )->check_page( $this->facts( array( 'has_charset' => false ) ) )['type'] );
		$this->assertSame( 'missing_lang', ( new SWPS_Check_Missing_Lang() )->check_page( $this->facts( array( 'has_lang' => false ) ) )['type'] );
	}

	public function test_challenge_check(): void {
		$check = new SWPS_Check_Challenge_Page();
		$issue = $check->check_page( $this->facts( array( 'is_challenge' => true ) ) );
		$this->assertSame( 'challenge_page_detected', $issue['type'] );
		$this->assertSame( 'error', $issue['severity'] );
	}
}
