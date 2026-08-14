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
require_once __DIR__ . '/../../includes/class-site-crawler.php';

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

	public function test_nofollow_internal_matches_www_and_case_variants(): void {
		// SWPS_Site_Crawler::is_internal() strips a leading "www." and
		// lower-cases the host before comparing — a manual host === $home_host
		// comparison misses both, letting www./mixed-case internal nofollow
		// links slip through unflagged.
		$check = new SWPS_Check_Nofollow_Internal();
		$issue = $check->check_page( $this->facts( array( 'nofollow_internal' => array( 'https://www.x.test/about/' ) ) ) );
		$this->assertNotNull( $issue );
		$this->assertSame( array( 'https://www.x.test/about/' ), $issue['detail']['links'] );

		$issue_case = $check->check_page( $this->facts( array( 'nofollow_internal' => array( 'https://X.TEST/about/' ) ) ) );
		$this->assertNotNull( $issue_case );
	}

	public function test_desc_too_long_counts_characters_not_bytes(): void {
		$check = new SWPS_Check_Desc_Too_Long();

		// 160 characters (157 'a's + 3 em-dashes) but 166 bytes in UTF-8 —
		// byte-based strlen() would wrongly flag this as too long.
		$desc = str_repeat( 'a', 157 ) . str_repeat( '—', 3 );
		$this->assertSame( 160, mb_strlen( $desc ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'meta_desc' => $desc ) ) ) );

		// 161 characters — genuinely too long, and the detail carries a
		// character-based (not byte-based) length.
		$too_long = str_repeat( 'a', 158 ) . str_repeat( '—', 3 );
		$issue    = $check->check_page( $this->facts( array( 'meta_desc' => $too_long ) ) );
		$this->assertNotNull( $issue );
		$this->assertSame( 161, $issue['detail']['length'] );
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
