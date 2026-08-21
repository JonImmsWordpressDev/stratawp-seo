<?php
/**
 * Tests for the image-alt fixer's URL normalization.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-image-alt.php';

final class FixerImageAltTest extends TestCase {

	public function test_strips_wp_size_suffix(): void {
		$this->assertSame(
			'https://example.com/wp-content/uploads/2026/08/photo.jpg',
			SWPS_Fixer_Image_Alt::strip_size_suffix( 'https://example.com/wp-content/uploads/2026/08/photo-300x200.jpg' )
		);
	}

	public function test_leaves_unsuffixed_url_alone(): void {
		$url = 'https://example.com/wp-content/uploads/photo.jpg';
		$this->assertSame( $url, SWPS_Fixer_Image_Alt::strip_size_suffix( $url ) );
	}

	public function test_does_not_mangle_dimensions_in_filename_body(): void {
		$url = 'https://example.com/uploads/board-1920s-history.jpg';
		$this->assertSame( $url, SWPS_Fixer_Image_Alt::strip_size_suffix( $url ) );
	}
}
