<?php
/**
 * Unit tests for SWPS_Link_Verifier.
 *
 * Covers invented internal and external URLs, source-material matching after
 * normalization, unreachable hosts, the check cap, links that are kept, and
 * filtering the model-reported link lists.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-link-verifier.php';

class LinkVerifierTest extends TestCase {

	/**
	 * Base context: example.com with two known posts, no sources, resolver
	 * and checker that say no.
	 *
	 * @param array $overrides Context overrides.
	 * @return array
	 */
	private function context( array $overrides = array() ): array {
		return array_merge(
			array(
				'site_host'        => 'example.com',
				'internal_urls'    => array( 'https://example.com/known-post/', 'https://example.com/about/' ),
				'source_urls'      => array(),
				'resolve_internal' => static fn( string $href ): bool => false,
				'check_external'   => static fn( string $url ): ?bool => false,
				'max_checks'       => 8,
			),
			$overrides
		);
	}

	public function test_invented_internal_url_is_unwrapped_and_anchor_kept(): void {
		$html   = '<p>See <a href="https://example.com/made-up-post/">our guide</a> for more.</p>';
		$result = SWPS_Link_Verifier::verify( $html, $this->context() );

		$this->assertSame( '<p>See our guide for more.</p>', $result['html'] );
		$this->assertCount( 1, $result['removed'] );
		$this->assertSame( 'https://example.com/made-up-post/', $result['removed'][0]['url'] );
		$this->assertSame( 'our guide', $result['removed'][0]['anchor'] );
		$this->assertSame( SWPS_Link_Verifier::REASON_INTERNAL, $result['removed'][0]['reason'] );
	}

	public function test_listed_internal_url_is_kept_across_www_scheme_and_slash(): void {
		$html   = '<a href="http://www.Example.com/known-post">A</a> <a href="/about">B</a>';
		$result = SWPS_Link_Verifier::verify( $html, $this->context() );

		$this->assertSame( $html, $result['html'] );
		$this->assertSame( array(), $result['removed'] );
	}

	public function test_internal_url_resolved_to_published_post_is_kept(): void {
		$seen   = array();
		$html   = '<a href="https://example.com/older-post/">older</a>';
		$result = SWPS_Link_Verifier::verify(
			$html,
			$this->context(
				array(
					'resolve_internal' => static function ( string $href ) use ( &$seen ): bool {
						$seen[] = $href;
						return true;
					},
				)
			)
		);

		$this->assertSame( $html, $result['html'] );
		$this->assertSame( array( 'https://example.com/older-post/' ), $seen );
	}

	public function test_site_home_is_always_kept(): void {
		$html   = '<a href="https://example.com/">home</a>';
		$result = SWPS_Link_Verifier::verify( $html, $this->context() );

		$this->assertSame( $html, $result['html'] );
	}

	public function test_invented_external_url_is_unwrapped_when_sources_supplied(): void {
		$html   = '<p><a href="https://docs.example.org/invented">docs</a></p>';
		$result = SWPS_Link_Verifier::verify(
			$html,
			$this->context(
				array(
					'source_urls'    => array( 'https://research.example.net/report' ),
					'check_external' => function (): ?bool {
						$this->fail( 'External URLs must not be fetched when sources are supplied.' );
					},
				)
			)
		);

		$this->assertSame( '<p>docs</p>', $result['html'] );
		$this->assertSame( SWPS_Link_Verifier::REASON_NOT_SOURCE, $result['removed'][0]['reason'] );
	}

	public function test_source_url_matches_after_normalization(): void {
		$html   = '<a href="http://www.research.example.net/report/#section-2">the report</a>';
		$result = SWPS_Link_Verifier::verify(
			$html,
			$this->context( array( 'source_urls' => array( 'https://research.example.net/report' ) ) )
		);

		$this->assertSame( $html, $result['html'] );
		$this->assertSame( array(), $result['removed'] );
	}

	public function test_query_string_is_part_of_the_match(): void {
		$html   = '<a href="https://research.example.net/report?id=2">other</a>';
		$result = SWPS_Link_Verifier::verify(
			$html,
			$this->context( array( 'source_urls' => array( 'https://research.example.net/report?id=1' ) ) )
		);

		$this->assertSame( 'other', $result['html'] );
	}

	public function test_unreachable_external_url_is_unwrapped_without_sources(): void {
		$html   = '<a href="https://no-such-host.invalid/page">gone</a> and <a href="https://wordpress.org/documentation/">docs</a>';
		$result = SWPS_Link_Verifier::verify(
			$html,
			$this->context(
				array(
					'check_external' => static fn( string $url ): ?bool => 0 === strpos( $url, 'https://wordpress.org/' ),
				)
			)
		);

		$this->assertSame( 'gone and <a href="https://wordpress.org/documentation/">docs</a>', $result['html'] );
		$this->assertCount( 1, $result['removed'] );
		$this->assertSame( SWPS_Link_Verifier::REASON_UNREACHABLE, $result['removed'][0]['reason'] );
	}

	public function test_external_checks_are_capped_and_cached_per_url(): void {
		$calls  = 0;
		$html   = '<a href="https://a.example.org/">a</a><a href="https://a.example.org">a again</a><a href="https://b.example.org/">b</a>';
		$result = SWPS_Link_Verifier::verify(
			$html,
			$this->context(
				array(
					'max_checks'     => 1,
					'check_external' => static function () use ( &$calls ): ?bool {
						++$calls;
						return true;
					},
				)
			)
		);

		$this->assertSame( 1, $calls );
		$this->assertSame( '<a href="https://a.example.org/">a</a><a href="https://a.example.org">a again</a>b', $result['html'] );
		$this->assertSame( SWPS_Link_Verifier::REASON_NOT_CHECKED, $result['removed'][0]['reason'] );
	}

	public function test_checker_returning_null_is_not_checked(): void {
		$result = SWPS_Link_Verifier::verify(
			'<a href="https://slow.example.org/">slow</a>',
			$this->context( array( 'check_external' => static fn(): ?bool => null ) )
		);

		$this->assertSame( SWPS_Link_Verifier::REASON_NOT_CHECKED, $result['removed'][0]['reason'] );
	}

	public function test_anchors_mailto_tel_and_hrefless_links_are_left_alone(): void {
		$html   = '<a href="#faq">FAQ</a> <a href="mailto:hi@example.com">mail</a> <a href="tel:+15555550100">call</a> <a name="top">top</a>';
		$result = SWPS_Link_Verifier::verify( $html, $this->context() );

		$this->assertSame( $html, $result['html'] );
		$this->assertSame( array(), $result['removed'] );
	}

	public function test_attributes_entities_and_nested_markup(): void {
		$html   = "<a class='x' target=\"_blank\" href='https://example.com/known-post/?a=1&amp;b=2'><strong>ok</strong></a> <a rel=\"nofollow\" href=\"https://example.com/fake\"><em>bad</em> link</a>";
		$result = SWPS_Link_Verifier::verify(
			$html,
			$this->context( array( 'internal_urls' => array( 'https://example.com/known-post/?a=1&b=2' ) ) )
		);

		$this->assertSame( "<a class='x' target=\"_blank\" href='https://example.com/known-post/?a=1&amp;b=2'><strong>ok</strong></a> <em>bad</em> link", $result['html'] );
		$this->assertSame( 'bad link', $result['removed'][0]['anchor'] );
	}

	public function test_filter_link_list_drops_removed_urls_only(): void {
		$removed = array(
			array(
				'url'    => 'https://example.com/made-up/',
				'anchor' => 'x',
				'reason' => SWPS_Link_Verifier::REASON_INTERNAL,
			),
		);
		$links   = array(
			array(
				'anchor_text' => 'x',
				'url'         => 'https://www.example.com/made-up',
			),
			array(
				'anchor_text' => 'y',
				'url'         => 'https://example.com/known-post/',
			),
		);

		$this->assertSame( array( $links[1] ), SWPS_Link_Verifier::filter_link_list( $links, $removed, 'example.com' ) );
		$this->assertSame( 'not-a-list', SWPS_Link_Verifier::filter_link_list( 'not-a-list', $removed, 'example.com' ) );
	}

	public function test_host_key_accepts_url_or_host(): void {
		$this->assertSame( 'example.com', SWPS_Link_Verifier::host_key( 'https://WWW.example.com/blog/' ) );
		$this->assertSame( 'example.com:8080', SWPS_Link_Verifier::host_key( 'http://example.com:8080' ) );
		$this->assertSame( 'example.com', SWPS_Link_Verifier::host_key( 'www.example.com' ) );
	}
}
