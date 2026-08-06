<?php
/**
 * Tests for SWPS_Backlinks::normalize_source_url() — the pure static helper
 * that reduces a URL to its duplicate-detection identity.
 *
 * Pure-PHP, no WordPress. The class is required for its static method only;
 * the constructor (which needs WP) is never called.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-backlinks.php';

/**
 * @covers SWPS_Backlinks
 */
class BacklinksNormalizeTest extends TestCase {

	public function test_identical_urls_normalize_identically(): void {
		$this->assertSame(
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page' )
		);
	}

	public function test_scheme_is_ignored(): void {
		$this->assertSame(
			SWPS_Backlinks::normalize_source_url( 'http://example.com/page' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page' )
		);
	}

	public function test_www_prefix_is_ignored(): void {
		$this->assertSame(
			SWPS_Backlinks::normalize_source_url( 'https://www.example.com/page' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page' )
		);
	}

	public function test_trailing_slash_is_ignored(): void {
		$this->assertSame(
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page/' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page' )
		);
	}

	public function test_bare_domain_equals_root_path(): void {
		$this->assertSame(
			SWPS_Backlinks::normalize_source_url( 'https://example.com' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/' )
		);
	}

	public function test_fragment_is_ignored(): void {
		$this->assertSame(
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page#section' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page' )
		);
	}

	public function test_host_case_is_ignored(): void {
		$this->assertSame(
			SWPS_Backlinks::normalize_source_url( 'https://EXAMPLE.com/page' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page' )
		);
	}

	public function test_path_case_is_preserved(): void {
		$this->assertNotSame(
			SWPS_Backlinks::normalize_source_url( 'https://example.com/Page' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page' )
		);
	}

	public function test_different_paths_stay_distinct(): void {
		$this->assertNotSame(
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page-one' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page-two' )
		);
	}

	public function test_query_string_stays_distinct(): void {
		$this->assertNotSame(
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page?p=1' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page?p=2' )
		);
	}

	public function test_query_string_is_preserved(): void {
		$this->assertSame(
			'example.com/page?p=1',
			SWPS_Backlinks::normalize_source_url( 'https://www.example.com/page?p=1' )
		);
	}

	public function test_non_standard_port_stays_distinct(): void {
		$this->assertNotSame(
			SWPS_Backlinks::normalize_source_url( 'https://example.com:8080/page' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page' )
		);
	}

	public function test_subdomain_stays_distinct(): void {
		// Only the www. prefix is collapsed — real subdomains are different hosts.
		$this->assertNotSame(
			SWPS_Backlinks::normalize_source_url( 'https://blog.example.com/page' ),
			SWPS_Backlinks::normalize_source_url( 'https://example.com/page' )
		);
	}

	public function test_unparseable_input_falls_back_to_lowercased_string(): void {
		$this->assertSame( 'not a url', SWPS_Backlinks::normalize_source_url( ' Not a URL ' ) );
	}
}
