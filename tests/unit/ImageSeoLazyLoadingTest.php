<?php
/**
 * Tests for SWPS_Image_SEO::enforce_lazy_loading().
 *
 * Pure string transformation, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-image-seo.php';

/**
 * @covers SWPS_Image_SEO::enforce_lazy_loading
 */
class ImageSeoLazyLoadingTest extends TestCase {

	private function subject(): SWPS_Image_SEO {
		$ref = new ReflectionClass( SWPS_Image_SEO::class );
		return $ref->newInstanceWithoutConstructor();
	}

	// -------------------------------------------------------------------------
	// The regression: core omits loading on the LCP candidate on purpose
	// -------------------------------------------------------------------------

	public function test_leaves_the_lcp_candidate_alone(): void {
		$html = '<img fetchpriority="high" decoding="async" src="hero.png" width="1456" height="837">';
		$this->assertSame( $html, $this->subject()->enforce_lazy_loading( $html ) );
	}

	public function test_leaves_the_lcp_candidate_alone_with_unquoted_attribute(): void {
		$html = '<img fetchpriority=high src="hero.png">';
		$this->assertSame( $html, $this->subject()->enforce_lazy_loading( $html ) );
	}

	public function test_leaves_the_lcp_candidate_alone_with_single_quotes(): void {
		$html = "<img fetchpriority='high' src='hero.png'>";
		$this->assertSame( $html, $this->subject()->enforce_lazy_loading( $html ) );
	}

	public function test_still_lazies_a_low_priority_image(): void {
		$html = '<img fetchpriority="low" src="footer.png">';
		$this->assertStringContainsString( 'loading="lazy"', $this->subject()->enforce_lazy_loading( $html ) );
	}

	// -------------------------------------------------------------------------
	// Existing behaviour must survive
	// -------------------------------------------------------------------------

	public function test_adds_lazy_to_an_image_with_no_hints(): void {
		$out = $this->subject()->enforce_lazy_loading( '<img src="body.png" alt="x">' );
		$this->assertStringContainsString( 'loading="lazy"', $out );
		$this->assertStringContainsString( 'decoding="async"', $out );
	}

	public function test_respects_an_explicit_loading_attribute(): void {
		$html = '<img src="a.png" loading="eager">';
		$this->assertSame( $html, $this->subject()->enforce_lazy_loading( $html ) );
	}

	public function test_only_the_lcp_image_is_skipped_in_a_mixed_run(): void {
		$html = '<img fetchpriority="high" src="hero.png"><p>x</p><img src="second.png">';
		$out  = $this->subject()->enforce_lazy_loading( $html );
		$this->assertStringContainsString( '<img fetchpriority="high" src="hero.png">', $out );
		$this->assertStringContainsString( '<img src="second.png" loading="lazy"', $out );
		$this->assertSame( 1, substr_count( $out, 'loading="lazy"' ) );
	}

	public function test_passes_through_content_with_no_images(): void {
		$this->assertSame( '<p>none</p>', $this->subject()->enforce_lazy_loading( '<p>none</p>' ) );
	}

	public function test_passes_through_empty_content(): void {
		$this->assertSame( '', $this->subject()->enforce_lazy_loading( '' ) );
	}
}
