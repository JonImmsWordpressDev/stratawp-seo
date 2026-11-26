<?php
/**
 * Page-count and offset math shared by post type and taxonomy sitemaps.
 *
 * Issue #139: taxonomy sitemaps were capped at one page, so terms past the
 * URLs-per-sitemap limit were never listed.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-sitemap-manager.php';

class SitemapPaginationTest extends TestCase {

	public function test_page_count_is_one_when_nothing_to_list(): void {
		$this->assertSame( 1, SWPS_Sitemap_Manager::page_count( 0, 1000 ) );
	}

	public function test_page_count_exact_fit_is_one_page(): void {
		$this->assertSame( 1, SWPS_Sitemap_Manager::page_count( 1000, 1000 ) );
	}

	public function test_page_count_one_over_adds_a_page(): void {
		$this->assertSame( 2, SWPS_Sitemap_Manager::page_count( 1001, 1000 ) );
	}

	public function test_page_count_reported_scenario(): void {
		// 150 tags at 100 URLs per sitemap.
		$this->assertSame( 2, SWPS_Sitemap_Manager::page_count( 150, 100 ) );
	}

	public function test_page_count_guards_against_bad_input(): void {
		$this->assertSame( 1, SWPS_Sitemap_Manager::page_count( -5, 100 ) );
		$this->assertSame( 3, SWPS_Sitemap_Manager::page_count( 3, 0 ) );
	}

	public function test_page_offset_first_page_starts_at_zero(): void {
		$this->assertSame( 0, SWPS_Sitemap_Manager::page_offset( 1, 100 ) );
	}

	public function test_page_offset_second_page_skips_one_page(): void {
		$this->assertSame( 100, SWPS_Sitemap_Manager::page_offset( 2, 100 ) );
	}

	public function test_page_offset_clamps_page_below_one(): void {
		$this->assertSame( 0, SWPS_Sitemap_Manager::page_offset( 0, 100 ) );
	}

	public function test_last_page_offset_is_inside_the_count(): void {
		$count = 2001;
		$per   = 1000;
		$last  = SWPS_Sitemap_Manager::page_count( $count, $per );
		$this->assertLessThan( $count, SWPS_Sitemap_Manager::page_offset( $last, $per ) );
		$this->assertGreaterThanOrEqual( $count, SWPS_Sitemap_Manager::page_offset( $last + 1, $per ) );
	}
}
