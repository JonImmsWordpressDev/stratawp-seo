<?php
// tests/unit/SearchAppearanceSocialImageTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-search-appearance.php';

/**
 * Precedence for the social image on non-singular views.
 *
 * The posts page is is_home(), never is_singular(), so Meta Editor's per-post
 * path never runs for it and its stored Social Image was ignored — the blog
 * index shared with the site logo instead.
 */
final class SearchAppearanceSocialImageTest extends TestCase {

	public function test_override_wins_over_fallback(): void {
		$this->assertSame(
			'https://example.com/og-blog.png',
			SWPS_Search_Appearance::resolve_social_image(
				'https://example.com/logo.png',
				'https://example.com/og-blog.png'
			)
		);
	}

	public function test_empty_override_keeps_fallback(): void {
		$this->assertSame(
			'https://example.com/logo.png',
			SWPS_Search_Appearance::resolve_social_image( 'https://example.com/logo.png', '' )
		);
	}

	public function test_whitespace_only_override_keeps_fallback(): void {
		$this->assertSame(
			'https://example.com/logo.png',
			SWPS_Search_Appearance::resolve_social_image( 'https://example.com/logo.png', "  \n\t " )
		);
	}

	public function test_override_is_trimmed(): void {
		$this->assertSame(
			'https://example.com/og-blog.png',
			SWPS_Search_Appearance::resolve_social_image(
				'https://example.com/logo.png',
				'  https://example.com/og-blog.png  '
			)
		);
	}

	public function test_override_applies_even_with_no_fallback(): void {
		$this->assertSame(
			'https://example.com/og-blog.png',
			SWPS_Search_Appearance::resolve_social_image( '', 'https://example.com/og-blog.png' )
		);
	}

	public function test_both_empty_yields_empty(): void {
		$this->assertSame( '', SWPS_Search_Appearance::resolve_social_image( '', '' ) );
	}
}
