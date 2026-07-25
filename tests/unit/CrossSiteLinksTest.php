<?php
/**
 * Unit tests for SWPS_Cross_Site_Links pure helpers.
 *
 * Covers origin normalization, the swps_owned_domains sanitizer, owned-URL
 * matching, REST inventory parsing, and cross-site candidate scoring.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-link-keyword-engine.php';
require_once __DIR__ . '/../../includes/class-cross-site-links.php';

class CrossSiteLinksTest extends TestCase {

	// ---------------------------------------------------------------------
	// normalize_origin()
	// ---------------------------------------------------------------------

	public function test_bare_host_normalizes_to_https_origin(): void {
		$this->assertSame( 'https://stratawpdev.com', SWPS_Cross_Site_Links::normalize_origin( 'stratawpdev.com' ) );
	}

	public function test_trailing_slash_and_whitespace_stripped(): void {
		$this->assertSame( 'https://jonimms.com', SWPS_Cross_Site_Links::normalize_origin( ' https://jonimms.com/ ' ) );
	}

	public function test_path_and_query_stripped_scheme_preserved(): void {
		$this->assertSame( 'http://example.org', SWPS_Cross_Site_Links::normalize_origin( 'http://example.org/path/page?x=1' ) );
	}

	public function test_port_preserved(): void {
		$this->assertSame( 'https://example.org:8443', SWPS_Cross_Site_Links::normalize_origin( 'https://example.org:8443/x' ) );
	}

	public function test_host_lowercased(): void {
		$this->assertSame( 'https://example.org', SWPS_Cross_Site_Links::normalize_origin( 'Example.ORG' ) );
	}

	public function test_non_http_scheme_rejected(): void {
		$this->assertNull( SWPS_Cross_Site_Links::normalize_origin( 'ftp://example.org' ) );
	}

	public function test_empty_and_invalid_input_rejected(): void {
		$this->assertNull( SWPS_Cross_Site_Links::normalize_origin( '' ) );
		$this->assertNull( SWPS_Cross_Site_Links::normalize_origin( 'https://' ) );
		$this->assertNull( SWPS_Cross_Site_Links::normalize_origin( 'not a url' ) );
	}

	// ---------------------------------------------------------------------
	// sanitize_owned_domains()
	// ---------------------------------------------------------------------

	public function test_textarea_string_sanitizes_to_origin_list(): void {
		$input = "stratawpdev.com\nhttps://jonimms.com/\n\nnot a url\n";
		$this->assertSame(
			array( 'https://stratawpdev.com', 'https://jonimms.com' ),
			SWPS_Cross_Site_Links::sanitize_owned_domains( $input )
		);
	}

	public function test_array_input_normalized_and_deduped(): void {
		$this->assertSame(
			array( 'https://example.org' ),
			SWPS_Cross_Site_Links::sanitize_owned_domains( array( 'Example.ORG', 'https://example.org/path' ) )
		);
	}

	public function test_non_string_non_array_input_returns_empty(): void {
		$this->assertSame( array(), SWPS_Cross_Site_Links::sanitize_owned_domains( null ) );
		$this->assertSame( array(), SWPS_Cross_Site_Links::sanitize_owned_domains( 42 ) );
	}

	// ---------------------------------------------------------------------
	// url_matches_domains()
	// ---------------------------------------------------------------------

	public function test_url_on_owned_host_matches(): void {
		$this->assertTrue(
			SWPS_Cross_Site_Links::url_matches_domains( 'https://jonimms.com/post/abc/', array( 'https://jonimms.com' ) )
		);
	}

	public function test_scheme_mismatch_still_matches(): void {
		$this->assertTrue(
			SWPS_Cross_Site_Links::url_matches_domains( 'http://jonimms.com/post/', array( 'https://jonimms.com' ) )
		);
	}

	public function test_www_prefix_ignored_both_directions(): void {
		$this->assertTrue(
			SWPS_Cross_Site_Links::url_matches_domains( 'https://www.jonimms.com/x/', array( 'https://jonimms.com' ) )
		);
		$this->assertTrue(
			SWPS_Cross_Site_Links::url_matches_domains( 'https://jonimms.com/x/', array( 'https://www.jonimms.com' ) )
		);
	}

	public function test_subdomain_does_not_match(): void {
		$this->assertFalse(
			SWPS_Cross_Site_Links::url_matches_domains( 'https://blog.jonimms.com/x/', array( 'https://jonimms.com' ) )
		);
	}

	public function test_other_host_does_not_match(): void {
		$this->assertFalse(
			SWPS_Cross_Site_Links::url_matches_domains( 'https://google.com/x', array( 'https://jonimms.com' ) )
		);
	}

	public function test_port_must_match(): void {
		$this->assertTrue(
			SWPS_Cross_Site_Links::url_matches_domains( 'https://example.org:8443/x', array( 'https://example.org:8443' ) )
		);
		$this->assertFalse(
			SWPS_Cross_Site_Links::url_matches_domains( 'https://example.org/x', array( 'https://example.org:8443' ) )
		);
	}

	public function test_relative_url_and_empty_list_do_not_match(): void {
		$this->assertFalse( SWPS_Cross_Site_Links::url_matches_domains( '/about/', array( 'https://jonimms.com' ) ) );
		$this->assertFalse( SWPS_Cross_Site_Links::url_matches_domains( 'https://jonimms.com/x', array() ) );
	}

	public function test_protocol_relative_url_matches_by_host(): void {
		$this->assertTrue(
			SWPS_Cross_Site_Links::url_matches_domains( '//jonimms.com/post/', array( 'https://jonimms.com' ) )
		);
	}

	// ---------------------------------------------------------------------
	// parse_inventory_response()
	// ---------------------------------------------------------------------

	public function test_valid_rest_response_parsed_to_inventory_items(): void {
		$body = wp_json_encode_test_helper(
			array(
				array(
					'title'   => array( 'rendered' => 'Hello &amp; World' ),
					'link'    => 'https://jonimms.com/hello/',
					'excerpt' => array( 'rendered' => '<p>Some <b>excerpt</b> &amp; more</p>' ),
				),
				array( 'title' => array( 'rendered' => 'No link item' ) ),
				'garbage',
			)
		);

		$items = SWPS_Cross_Site_Links::parse_inventory_response( $body, 'https://jonimms.com' );

		$this->assertCount( 1, $items );
		$this->assertSame(
			array(
				'url'     => 'https://jonimms.com/hello/',
				'title'   => 'Hello & World',
				'excerpt' => 'Some excerpt & more',
				'domain'  => 'https://jonimms.com',
			),
			$items[0]
		);
	}

	public function test_invalid_json_returns_empty(): void {
		$this->assertSame( array(), SWPS_Cross_Site_Links::parse_inventory_response( 'not json', 'https://jonimms.com' ) );
	}

	public function test_rest_error_object_returns_empty(): void {
		$body = '{"code":"rest_forbidden","message":"nope"}';
		$this->assertSame( array(), SWPS_Cross_Site_Links::parse_inventory_response( $body, 'https://jonimms.com' ) );
	}

	// ---------------------------------------------------------------------
	// score_candidates()
	// ---------------------------------------------------------------------

	private function tokenizer(): callable {
		return array( new SWPS_Link_Keyword_Engine(), 'tokenize_public' );
	}

	private function sample_inventory(): array {
		return array(
			array(
				'url'     => 'https://jonimms.com/caching/',
				'title'   => 'WordPress caching guide',
				'excerpt' => 'Speed things up.',
				'domain'  => 'https://jonimms.com',
			),
			array(
				'url'     => 'https://jonimms.com/garden/',
				'title'   => 'Gardening tips',
				'excerpt' => 'A wordpress aside',
				'domain'  => 'https://jonimms.com',
			),
			array(
				'url'     => 'https://jonimms.com/pasta/',
				'title'   => 'Cooking pasta',
				'excerpt' => 'Boil water.',
				'domain'  => 'https://jonimms.com',
			),
		);
	}

	public function test_scores_normalized_and_thresholded(): void {
		$terms = array(
			'wordpress' => 1.0,
			'caching'   => 0.9,
		);

		$results = SWPS_Cross_Site_Links::score_candidates( $terms, $this->sample_inventory(), $this->tokenizer(), 0.3, 10 );

		$this->assertCount( 1, $results );
		$this->assertSame( 'https://jonimms.com/caching/', $results[0]['url'] );
		$this->assertSame( 1.0, $results[0]['score'] );
		$this->assertTrue( $results[0]['cross_site'] );
		$this->assertSame( 'WordPress caching guide', $results[0]['title'] );
		$this->assertSame( 'https://jonimms.com', $results[0]['domain'] );
	}

	public function test_excerpt_matches_weighted_lower_and_ordering_desc(): void {
		$terms = array(
			'wordpress' => 1.0,
			'caching'   => 0.9,
		);

		$results = SWPS_Cross_Site_Links::score_candidates( $terms, $this->sample_inventory(), $this->tokenizer(), 0.1, 10 );

		$this->assertCount( 2, $results );
		$this->assertSame( 'https://jonimms.com/caching/', $results[0]['url'] );
		$this->assertSame( 'https://jonimms.com/garden/', $results[1]['url'] );
		// Raw: caching post = 1.0*1.0 + 0.9*1.0 = 1.9; garden = 1.0*0.3 (excerpt) = 0.3.
		$this->assertSame( round( 0.3 / 1.9, 3 ), $results[1]['score'] );
	}

	public function test_limit_applied_after_ordering(): void {
		$terms = array(
			'wordpress' => 1.0,
			'caching'   => 0.9,
		);

		$results = SWPS_Cross_Site_Links::score_candidates( $terms, $this->sample_inventory(), $this->tokenizer(), 0.1, 1 );

		$this->assertCount( 1, $results );
		$this->assertSame( 'https://jonimms.com/caching/', $results[0]['url'] );
	}

	public function test_excluded_urls_skipped_before_normalization(): void {
		$terms = array(
			'wordpress' => 1.0,
			'caching'   => 0.9,
		);

		$results = SWPS_Cross_Site_Links::score_candidates(
			$terms,
			$this->sample_inventory(),
			$this->tokenizer(),
			0.3,
			10,
			array( 'https://jonimms.com/caching/' )
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'https://jonimms.com/garden/', $results[0]['url'] );
		$this->assertSame( 1.0, $results[0]['score'] );
	}

	public function test_empty_terms_or_inventory_return_empty(): void {
		$this->assertSame( array(), SWPS_Cross_Site_Links::score_candidates( array(), $this->sample_inventory(), $this->tokenizer() ) );
		$this->assertSame( array(), SWPS_Cross_Site_Links::score_candidates( array( 'x' => 1.0 ), array(), $this->tokenizer() ) );
	}
}

/**
 * json_encode helper so the test reads clearly (wp_json_encode is a WP function).
 *
 * @param mixed $data Data to encode.
 * @return string JSON.
 */
function wp_json_encode_test_helper( $data ): string {
	return (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
