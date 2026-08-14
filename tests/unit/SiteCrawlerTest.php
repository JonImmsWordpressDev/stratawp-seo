<?php
/**
 * TDD tests for SWPS_Site_Crawler pure static helpers.
 *
 * Pure-PHP, no WordPress. Tests cover:
 * - normalize_url()  : canonicalization, invalid/unsupported schemes
 * - is_internal()    : same-host check
 * - parse_html()     : link/asset/canonical/h1/noindex extraction
 * - classify()       : issue row generation from fetch+page results
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-site-crawler.php';

/**
 * @covers SWPS_Site_Crawler
 */
class SiteCrawlerTest extends TestCase {

	// -------------------------------------------------------------------------
	// normalize_url — basic canonicalization
	// -------------------------------------------------------------------------

	public function test_normalize_strips_fragment(): void {
		$result = SWPS_Site_Crawler::normalize_url( 'https://example.com/page#section', 'example.com' );
		$this->assertSame( '//example.com/page', $result );
	}

	public function test_normalize_strips_trailing_slash_from_path(): void {
		$result = SWPS_Site_Crawler::normalize_url( 'https://example.com/page/', 'example.com' );
		$this->assertSame( '//example.com/page', $result );
	}

	public function test_normalize_keeps_root_slash(): void {
		$result = SWPS_Site_Crawler::normalize_url( 'https://example.com/', 'example.com' );
		$this->assertSame( '//example.com/', $result );
	}

	public function test_normalize_sorts_query_args(): void {
		$result = SWPS_Site_Crawler::normalize_url( 'https://example.com/p?z=3&a=1&m=2', 'example.com' );
		$this->assertSame( '//example.com/p?a=1&m=2&z=3', $result );
	}

	public function test_normalize_handles_http(): void {
		$result = SWPS_Site_Crawler::normalize_url( 'http://example.com/page', 'example.com' );
		$this->assertSame( '//example.com/page', $result );
	}

	public function test_normalize_returns_empty_for_invalid_url(): void {
		$result = SWPS_Site_Crawler::normalize_url( 'not a url %%', 'example.com' );
		$this->assertSame( '', $result );
	}

	public function test_normalize_returns_empty_for_mailto(): void {
		$result = SWPS_Site_Crawler::normalize_url( 'mailto:someone@example.com', 'example.com' );
		$this->assertSame( '', $result );
	}

	public function test_normalize_returns_empty_for_javascript(): void {
		$result = SWPS_Site_Crawler::normalize_url( 'javascript:void(0)', 'example.com' );
		$this->assertSame( '', $result );
	}

	public function test_normalize_handles_no_path(): void {
		$result = SWPS_Site_Crawler::normalize_url( 'https://example.com', 'example.com' );
		$this->assertSame( '//example.com/', $result );
	}

	// -------------------------------------------------------------------------
	// is_internal
	// -------------------------------------------------------------------------

	public function test_is_internal_same_host(): void {
		$this->assertTrue( SWPS_Site_Crawler::is_internal( 'https://example.com/page', 'example.com' ) );
	}

	public function test_is_internal_www_subdomain(): void {
		// www.example.com vs example.com — same registrable host (strip www).
		$this->assertTrue( SWPS_Site_Crawler::is_internal( 'https://www.example.com/page', 'example.com' ) );
	}

	public function test_is_internal_different_host(): void {
		$this->assertFalse( SWPS_Site_Crawler::is_internal( 'https://other.com/page', 'example.com' ) );
	}

	public function test_is_internal_subdomain_is_external(): void {
		$this->assertFalse( SWPS_Site_Crawler::is_internal( 'https://sub.example.com/page', 'example.com' ) );
	}

	public function test_is_internal_mailto_is_false(): void {
		$this->assertFalse( SWPS_Site_Crawler::is_internal( 'mailto:a@b.com', 'example.com' ) );
	}

	// -------------------------------------------------------------------------
	// parse_html — link extraction
	// -------------------------------------------------------------------------

	public function test_parse_html_extracts_anchor_links(): void {
		$html   = '<html><body><a href="/about">About</a><a href="https://example.com/contact">Contact</a></body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/' );
		$this->assertContains( 'https://example.com/about', $result['links'] );
		$this->assertContains( 'https://example.com/contact', $result['links'] );
	}

	public function test_parse_html_extracts_canonical(): void {
		$html   = '<html><head><link rel="canonical" href="https://example.com/page"/></head><body></body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/page' );
		$this->assertSame( 'https://example.com/page', $result['canonical'] );
	}

	public function test_parse_html_canonical_is_null_when_missing(): void {
		$html   = '<html><head></head><body></body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/page' );
		$this->assertNull( $result['canonical'] );
	}

	public function test_parse_html_detects_noindex(): void {
		$html   = '<html><head><meta name="robots" content="noindex,follow"/></head><body></body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/page' );
		$this->assertTrue( $result['has_noindex'] );
	}

	public function test_parse_html_detects_no_noindex_when_absent(): void {
		$html   = '<html><head><meta name="robots" content="index,follow"/></head><body></body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/page' );
		$this->assertFalse( $result['has_noindex'] );
	}

	public function test_parse_html_counts_h1(): void {
		$html   = '<html><body><h1>Title One</h1><p>Para</p><h1>Title Two</h1></body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/page' );
		$this->assertSame( 2, $result['h1_count'] );
	}

	public function test_parse_html_h1_zero_when_absent(): void {
		$html   = '<html><body><p>No heading</p></body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/page' );
		$this->assertSame( 0, $result['h1_count'] );
	}

	public function test_parse_html_detects_mixed_content_img(): void {
		$html   = '<html><body><img src="http://example.com/image.jpg"/></body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/page' );
		$this->assertNotEmpty( $result['mixed'] );
		$this->assertContains( 'http://example.com/image.jpg', $result['mixed'] );
	}

	public function test_parse_html_no_mixed_content_on_http_page(): void {
		// Mixed content only applies to https pages.
		$html   = '<html><body><img src="http://example.com/image.jpg"/></body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'http://example.com/page' );
		$this->assertEmpty( $result['mixed'] );
	}

	public function test_parse_html_tolerates_malformed_html(): void {
		// Should not throw; returns minimal result with empty links.
		$html   = '<html><body><a href="unclosed">';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'links', $result );
	}

	public function test_parse_html_resolves_relative_urls(): void {
		$html   = '<html><body><a href="../other">link</a></body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/section/page' );
		$this->assertContains( 'https://example.com/other', $result['links'] );
	}

	// -------------------------------------------------------------------------
	// classify — issue rows from fetch + page data
	// -------------------------------------------------------------------------

	public function test_classify_broken_link_on_4xx(): void {
		$fetch = array( 'url' => 'https://example.com/gone', 'status' => 404, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => null, 'h1_count' => 1, 'has_noindex' => false, 'mixed' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'broken_link', $types );
	}

	public function test_classify_single_redirect_is_not_a_chain(): void {
		// fetch_url() hops contain ONLY the 3xx responses; a single redirect
		// (A answers 301, B answers 200) yields exactly one hop — no issue.
		$fetch = array(
			'url'      => 'https://example.com/old',
			'status'   => 200,
			'found_on' => 'https://example.com/',
			'hops'     => array(
				array( 'url' => 'https://example.com/old', 'status' => 301 ),
			),
			'loop'     => false,
		);
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => null, 'h1_count' => 1, 'has_noindex' => false, 'mixed' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertNotContains( 'redirect_chain', $types );
		$this->assertNotContains( 'redirect_loop', $types );
	}

	public function test_classify_redirect_chain_at_two_hops(): void {
		// A -> B -> C (two 301 responses before the final 200): chain fires.
		$fetch = array(
			'url'      => 'https://example.com/old',
			'status'   => 200,
			'found_on' => 'https://example.com/',
			'hops'     => array(
				array( 'url' => 'https://example.com/old', 'status' => 301 ),
				array( 'url' => 'https://example.com/mid', 'status' => 301 ),
			),
			'loop'     => false,
		);
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => null, 'h1_count' => 1, 'has_noindex' => false, 'mixed' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'redirect_chain', $types );
	}

	public function test_classify_redirect_loop_is_distinct_from_broken_link(): void {
		// MAX_HOPS (5) exceeded: six 3xx hops, no final response, loop=true.
		$hops = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$hops[] = array( 'url' => 'https://example.com/loop' . $i, 'status' => 302 );
		}
		$fetch = array(
			'url'      => 'https://example.com/loop0',
			'status'   => 0,
			'found_on' => 'https://example.com/',
			'hops'     => $hops,
			'loop'     => true,
		);
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => null, 'h1_count' => 0, 'has_noindex' => false, 'mixed' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'redirect_loop', $types );
		$this->assertNotContains( 'broken_link', $types );
		$this->assertSame( 'error', $rows[0]['severity'] );
	}

	public function test_classify_canonical_mismatch(): void {
		$fetch = array( 'url' => 'https://example.com/page?utm_source=x', 'status' => 200, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => 'https://example.com/page', 'h1_count' => 1, 'has_noindex' => false, 'mixed' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'canonical_mismatch', $types );
	}

	public function test_classify_missing_h1(): void {
		$fetch = array( 'url' => 'https://example.com/page', 'status' => 200, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => null, 'h1_count' => 0, 'has_noindex' => false, 'mixed' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'missing_h1', $types );
	}

	public function test_classify_duplicate_h1(): void {
		$fetch = array( 'url' => 'https://example.com/page', 'status' => 200, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => null, 'h1_count' => 3, 'has_noindex' => false, 'mixed' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'duplicate_h1', $types );
	}

	public function test_classify_mixed_content(): void {
		$fetch = array( 'url' => 'https://example.com/page', 'status' => 200, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => null, 'h1_count' => 1, 'has_noindex' => false, 'mixed' => array( 'http://cdn.example.com/img.jpg' ) );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'mixed_content', $types );
	}

	public function test_classify_no_issues_for_clean_page(): void {
		$fetch = array( 'url' => 'https://example.com/page', 'status' => 200, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array(
			'links'         => array(),
			'images'        => array(),
			'canonical'     => 'https://example.com/page',
			'h1_count'      => 1,
			'has_noindex'   => false,
			'mixed'         => array(),
			'title'         => 'This is an example page title',
			'has_viewport'  => true,
			'has_doctype'   => true,
			'has_charset'   => true,
			'has_lang'      => true,
			'is_challenge'  => false,
			'meta_desc'     => 'A perfectly reasonable meta description for this clean test page.',
			'word_count'    => 900,
			'has_schema'    => true,
			'is_compressed' => true,
		);
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$this->assertEmpty( $rows );
	}

	/**
	 * A non-HTML resource (bare 6-key fallback facts, as process_chunk()
	 * builds for a 2xx response whose content-type is not text/html) must
	 * never trigger the falsy-default content checks. Without the
	 * content-type gate, missing_meta_description/low_word_count/
	 * missing_schema/uncompressed_page would all fire on the absent keys.
	 */
	public function test_classify_skips_page_checks_for_non_html_content_type(): void {
		$fetch = array( 'url' => 'https://example.com/image.png', 'status' => 200, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array(
			'links'        => array(),
			'images'       => array(),
			'canonical'    => null,
			'h1_count'     => 0,
			'has_noindex'  => false,
			'mixed'        => array(),
			'content_type' => 'application/octet-stream',
		);
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$this->assertEmpty( $rows );
		$types = array_column( $rows, 'type' );
		$this->assertNotContains( 'missing_meta_description', $types );
		$this->assertNotContains( 'low_word_count', $types );
		$this->assertNotContains( 'missing_schema', $types );
		$this->assertNotContains( 'uncompressed_page', $types );
	}

	// -------------------------------------------------------------------------
	// classify — noindex_in_sitemap (caller injects post_id + sitemap_excluded)
	// -------------------------------------------------------------------------

	public function test_classify_noindex_in_sitemap(): void {
		$fetch = array( 'url' => 'https://example.com/page', 'status' => 200, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array(
			'links'            => array(),
			'images'           => array(),
			'canonical'        => 'https://example.com/page',
			'h1_count'         => 1,
			'has_noindex'      => true,
			'mixed'            => array(),
			'post_id'          => 42,
			'sitemap_excluded' => false,
		);
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'noindex_in_sitemap', $types );

		$idx = array_search( 'noindex_in_sitemap', $types, true );
		$this->assertSame( 'warning', $rows[ $idx ]['severity'] );
		$this->assertSame( 42, $rows[ $idx ]['detail']['post_id'] );
	}

	public function test_classify_noindex_excluded_post_is_fine(): void {
		// Post is already excluded from the sitemap — noindex is consistent.
		$fetch = array( 'url' => 'https://example.com/page', 'status' => 200, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array(
			'links'            => array(),
			'images'           => array(),
			'canonical'        => 'https://example.com/page',
			'h1_count'         => 1,
			'has_noindex'      => true,
			'mixed'            => array(),
			'post_id'          => 42,
			'sitemap_excluded' => true,
		);
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertNotContains( 'noindex_in_sitemap', $types );
	}

	public function test_classify_noindex_non_post_url_is_skipped(): void {
		// Archive/term pages (url_to_postid == 0) are not flagged.
		$fetch = array( 'url' => 'https://example.com/category/news', 'status' => 200, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array(
			'links'            => array(),
			'images'           => array(),
			'canonical'        => 'https://example.com/category/news',
			'h1_count'         => 1,
			'has_noindex'      => true,
			'mixed'            => array(),
			'post_id'          => 0,
			'sitemap_excluded' => false,
		);
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertNotContains( 'noindex_in_sitemap', $types );
	}

	// -------------------------------------------------------------------------
	// resolve_url — scheme handling and relative resolution
	// -------------------------------------------------------------------------

	public function test_resolve_url_passes_through_absolute(): void {
		$result = SWPS_Site_Crawler::resolve_url( 'https://other.com/page', 'https://example.com/' );
		$this->assertSame( 'https://other.com/page', $result );
	}

	public function test_resolve_url_skips_mailto(): void {
		$result = SWPS_Site_Crawler::resolve_url( 'mailto:someone@example.com', 'https://example.com/blog/' );
		$this->assertSame( '', $result );
	}

	public function test_resolve_url_skips_tel(): void {
		$result = SWPS_Site_Crawler::resolve_url( 'tel:+15551234567', 'https://example.com/contact/' );
		$this->assertSame( '', $result );
	}

	public function test_resolve_url_skips_javascript(): void {
		$result = SWPS_Site_Crawler::resolve_url( 'javascript:void(0)', 'https://example.com/' );
		$this->assertSame( '', $result );
	}

	public function test_resolve_url_root_relative(): void {
		$result = SWPS_Site_Crawler::resolve_url( '/blog/post/', 'https://example.com/old-slug/' );
		$this->assertSame( 'https://example.com/blog/post/', $result );
	}

	public function test_resolve_url_protocol_relative(): void {
		$result = SWPS_Site_Crawler::resolve_url( '//cdn.example.com/a.js', 'https://example.com/' );
		$this->assertSame( 'https://cdn.example.com/a.js', $result );
	}

	public function test_resolve_url_walks_parent_segments(): void {
		$result = SWPS_Site_Crawler::resolve_url( '../other', 'https://example.com/section/page' );
		$this->assertSame( 'https://example.com/other', $result );
	}

	public function test_parse_html_does_not_fabricate_links_from_mailto(): void {
		$html   = '<html><body>'
			. '<a href="mailto:someone@example.com">mail</a>'
			. '<a href="tel:+15551234567">call</a>'
			. '<a href="/real-page/">real</a>'
			. '</body></html>';
		$result = SWPS_Site_Crawler::parse_html( $html, 'https://example.com/blog/' );
		$this->assertContains( 'https://example.com/real-page/', $result['links'] );
		$this->assertNotContains( 'https://example.com/blog/mailto:someone@example.com', $result['links'] );
		$this->assertCount( 1, $result['links'] );
	}

	// -------------------------------------------------------------------------
	// classify — canonical compared against the post-redirect URL
	// -------------------------------------------------------------------------

	public function test_classify_no_canonical_mismatch_when_redirect_target_self_canonicalizes(): void {
		// /old 301s to /new; /new declares itself canonical. That is the
		// redirect working, not a mismatch.
		$fetch = array(
			'url'       => 'https://example.com/old',
			'status'    => 200,
			'found_on'  => 'https://example.com/',
			'hops'      => array(
				array( 'url' => 'https://example.com/old', 'status' => 301 ),
			),
			'loop'      => false,
			'final_url' => 'https://example.com/new/',
		);
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => 'https://example.com/new/', 'h1_count' => 1, 'has_noindex' => false, 'mixed' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertNotContains( 'canonical_mismatch', $types );
	}

	public function test_classify_canonical_mismatch_against_final_url(): void {
		// The redirect target itself declares a DIFFERENT canonical — real issue.
		$fetch = array(
			'url'       => 'https://example.com/old',
			'status'    => 200,
			'found_on'  => 'https://example.com/',
			'hops'      => array(
				array( 'url' => 'https://example.com/old', 'status' => 301 ),
			),
			'loop'      => false,
			'final_url' => 'https://example.com/new/',
		);
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => 'https://example.com/elsewhere/', 'h1_count' => 1, 'has_noindex' => false, 'mixed' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'canonical_mismatch', $types );
	}

	public function test_classify_canonical_falls_back_to_url_without_final_url(): void {
		// Legacy fetch arrays without final_url keep the old comparison.
		$fetch = array( 'url' => 'https://example.com/page?utm_source=x', 'status' => 200, 'found_on' => 'https://example.com/', 'hops' => array() );
		$page  = array( 'links' => array(), 'images' => array(), 'canonical' => 'https://example.com/page', 'h1_count' => 1, 'has_noindex' => false, 'mixed' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'canonical_mismatch', $types );
	}
}
