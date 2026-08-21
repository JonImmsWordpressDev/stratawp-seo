<?php
/**
 * Tests for the nofollow-internal-link stripper.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-nofollow.php';

final class FixerNofollowTest extends TestCase {

	public function test_strips_nofollow_from_internal_absolute_link(): void {
		$out = SWPS_Fixer_Nofollow::strip(
			'<a href="https://example.com/about/" rel="nofollow">About</a>',
			'example.com'
		);
		$this->assertSame( '<a href="https://example.com/about/">About</a>', $out['content'] );
		$this->assertSame( 1, $out['changed'] );
	}

	public function test_strips_nofollow_from_relative_link(): void {
		$out = SWPS_Fixer_Nofollow::strip(
			'<a rel="nofollow" href="/contact/">Contact</a>',
			'example.com'
		);
		$this->assertStringNotContainsString( 'nofollow', $out['content'] );
		$this->assertSame( 1, $out['changed'] );
	}

	public function test_preserves_other_rel_tokens(): void {
		$out = SWPS_Fixer_Nofollow::strip(
			'<a href="/x/" rel="nofollow noopener">x</a>',
			'example.com'
		);
		$this->assertStringContainsString( 'rel="noopener"', $out['content'] );
		$this->assertStringNotContainsString( 'nofollow', $out['content'] );
	}

	public function test_external_links_untouched(): void {
		$html = '<a href="https://other.com/" rel="nofollow">ext</a>';
		$out  = SWPS_Fixer_Nofollow::strip( $html, 'example.com' );
		$this->assertSame( $html, $out['content'] );
		$this->assertSame( 0, $out['changed'] );
	}

	public function test_links_without_rel_untouched(): void {
		$html = '<a href="/plain/">plain</a>';
		$out  = SWPS_Fixer_Nofollow::strip( $html, 'example.com' );
		$this->assertSame( $html, $out['content'] );
	}

	public function test_www_prefix_counts_as_internal(): void {
		$out = SWPS_Fixer_Nofollow::strip(
			'<a href="https://www.example.com/p/" rel="nofollow">p</a>',
			'example.com'
		);
		$this->assertSame( 1, $out['changed'] );
	}

	public function test_anchor_without_href_untouched(): void {
		$html = '<a rel="nofollow">no href</a>';
		$out  = SWPS_Fixer_Nofollow::strip( $html, 'example.com' );
		$this->assertSame( $html, $out['content'] );
		$this->assertSame( 0, $out['changed'] );
	}

	public function test_mailto_links_untouched(): void {
		$html = '<a href="mailto:test@example.com" rel="nofollow">mail</a>';
		$out  = SWPS_Fixer_Nofollow::strip( $html, 'example.com' );
		$this->assertSame( $html, $out['content'] );
		$this->assertSame( 0, $out['changed'] );
	}
}
