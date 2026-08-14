<?php
/**
 * TDD tests for content and asset crawl checks.
 *
 * Pure-PHP, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-checks-content.php';

/**
 * @covers SWPS_Check_Missing_Meta_Description
 * @covers SWPS_Check_Desc_Too_Long
 * @covers SWPS_Check_Low_Word_Count
 * @covers SWPS_Check_Low_Text_Html_Ratio
 * @covers SWPS_Check_Image_Missing_Alt
 * @covers SWPS_Check_Hreflang_Invalid
 * @covers SWPS_Check_Missing_Schema
 * @covers SWPS_Check_Nofollow_Internal
 * @covers SWPS_Check_Uncompressed_Page
 * @covers SWPS_Check_Unminified_Assets
 */
final class CrawlChecksContentTest extends TestCase {

	private function facts( array $over = array() ): array {
		return array_merge(
			array(
				'url'                => 'https://x.test/post/',
				'status_code'        => 200,
				'meta_desc'          => 'A description of sensible length for the test.',
				'is_paginated'       => false,
				'is_archive'         => false,
				'has_noindex'        => false,
				'word_count'         => 900,
				'text_bytes'         => 5000,
				'html_bytes'         => 20000,
				'images_missing_alt' => array(),
				'hreflangs'          => array(),
				'has_schema'         => true,
				'nofollow_internal'  => array(),
				'is_compressed'      => true,
				'unminified_assets'  => array(),
				'home_host'          => 'x.test',
			),
			$over
		);
	}

	public function test_missing_description_fires_but_not_on_paginated_or_noindex(): void {
		$check = new SWPS_Check_Missing_Meta_Description();
		$this->assertNotNull( $check->check_page( $this->facts( array( 'meta_desc' => '' ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'meta_desc' => '', 'is_paginated' => true ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'meta_desc' => '', 'has_noindex' => true ) ) ) );
	}

	public function test_word_count_exempts_archives(): void {
		$check = new SWPS_Check_Low_Word_Count();
		$this->assertNotNull( $check->check_page( $this->facts( array( 'word_count' => 50 ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'word_count' => 50, 'is_archive' => true ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'word_count' => 200 ) ) ) );
	}

	public function test_text_html_ratio(): void {
		$check = new SWPS_Check_Low_Text_Html_Ratio();
		$this->assertNotNull( $check->check_page( $this->facts( array( 'text_bytes' => 500, 'html_bytes' => 20000 ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'text_bytes' => 5000, 'html_bytes' => 20000 ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'html_bytes' => 0 ) ) ) );
	}

	public function test_hreflang_invalid_cases(): void {
		$check = new SWPS_Check_Hreflang_Invalid();
		$this->assertNull( $check->check_page( $this->facts() ) ); // absent = fine
		$ok = array( array( 'lang' => 'en-US', 'href' => 'https://x.test/en/' ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'hreflangs' => $ok ) ) ) );
		$bad = array( array( 'lang' => 'english', 'href' => 'https://x.test/en/' ) );
		$this->assertNotNull( $check->check_page( $this->facts( array( 'hreflangs' => $bad ) ) ) );
		$conflict = array(
			array( 'lang' => 'en', 'href' => 'https://x.test/en/' ),
			array( 'lang' => 'en', 'href' => 'https://x.test/other/' ),
		);
		$this->assertNotNull( $check->check_page( $this->facts( array( 'hreflangs' => $conflict ) ) ) );
	}

	public function test_nofollow_internal_only_counts_same_host(): void {
		$check = new SWPS_Check_Nofollow_Internal();
		$this->assertNull( $check->check_page( $this->facts( array( 'nofollow_internal' => array( 'https://elsewhere.test/x' ) ) ) ) );
		$issue = $check->check_page( $this->facts( array( 'nofollow_internal' => array( 'https://x.test/about/' ) ) ) );
		$this->assertSame( array( 'https://x.test/about/' ), $issue['detail']['links'] );
	}

	public function test_remaining_simple_checks(): void {
		$this->assertNotNull( ( new SWPS_Check_Desc_Too_Long() )->check_page( $this->facts( array( 'meta_desc' => str_repeat( 'a', 161 ) ) ) ) );
		$this->assertNotNull( ( new SWPS_Check_Image_Missing_Alt() )->check_page( $this->facts( array( 'images_missing_alt' => array( 'https://x.test/i.png' ) ) ) ) );
		$this->assertNotNull( ( new SWPS_Check_Missing_Schema() )->check_page( $this->facts( array( 'has_schema' => false ) ) ) );
		$this->assertNull( ( new SWPS_Check_Missing_Schema() )->check_page( $this->facts( array( 'has_schema' => false, 'is_archive' => true ) ) ) );
		$this->assertNotNull( ( new SWPS_Check_Uncompressed_Page() )->check_page( $this->facts( array( 'is_compressed' => false ) ) ) );
		$this->assertNotNull( ( new SWPS_Check_Unminified_Assets() )->check_page( $this->facts( array( 'unminified_assets' => array( 'https://x.test/js/a.js' ) ) ) ) );
	}
}
