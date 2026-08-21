<?php
/**
 * Tests for the mixed-content rewriter.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-mixed-content.php';

final class FixerMixedContentTest extends TestCase {

	public function test_rewrites_img_src(): void {
		$out = SWPS_Fixer_Mixed_Content::rewrite( '<img src="http://example.com/a.jpg" alt="">' );
		$this->assertSame( '<img src="https://example.com/a.jpg" alt="">', $out['content'] );
		$this->assertSame( 1, $out['changed'] );
	}

	public function test_rewrites_srcset_with_multiple_urls(): void {
		$out = SWPS_Fixer_Mixed_Content::rewrite(
			'<img srcset="http://a.com/1.jpg 1x, http://a.com/2.jpg 2x" src="http://a.com/1.jpg">'
		);
		$this->assertStringNotContainsString( 'http://', $out['content'] );
		$this->assertSame( 3, $out['changed'] );
	}

	public function test_leaves_anchor_hrefs_alone(): void {
		$html = '<a href="http://example.com/page/">link</a>';
		$out  = SWPS_Fixer_Mixed_Content::rewrite( $html );
		$this->assertSame( $html, $out['content'] );
		$this->assertSame( 0, $out['changed'] );
	}

	public function test_leaves_plain_text_urls_alone(): void {
		$html = '<p>Visit http://example.com for more.</p>';
		$out  = SWPS_Fixer_Mixed_Content::rewrite( $html );
		$this->assertSame( $html, $out['content'] );
	}

	public function test_rewrites_script_and_iframe_and_source(): void {
		$html = '<script src="http://cdn.example.com/x.js"></script>'
			. '<iframe src="http://example.com/embed"></iframe>'
			. '<source src="http://example.com/v.mp4">';
		$out  = SWPS_Fixer_Mixed_Content::rewrite( $html );
		$this->assertStringNotContainsString( 'http://', $out['content'] );
		$this->assertSame( 3, $out['changed'] );
	}

	public function test_already_https_untouched(): void {
		$html = '<img src="https://example.com/a.jpg">';
		$out  = SWPS_Fixer_Mixed_Content::rewrite( $html );
		$this->assertSame( $html, $out['content'] );
		$this->assertSame( 0, $out['changed'] );
	}

	public function test_hyphen_suffixed_attributes_untouched(): void {
		$html = '<img data-original-src="http://example.com/a.jpg" background-src="http://example.com/b.jpg">';
		$out  = SWPS_Fixer_Mixed_Content::rewrite( $html );
		$this->assertSame( $html, $out['content'] );
		$this->assertSame( 0, $out['changed'] );

		// Verify data-src itself still gets rewritten.
		$html2 = '<img data-src="http://example.com/c.jpg">';
		$out2  = SWPS_Fixer_Mixed_Content::rewrite( $html2 );
		$this->assertSame( '<img data-src="https://example.com/c.jpg">', $out2['content'] );
		$this->assertSame( 1, $out2['changed'] );
	}
}
