<?php
/**
 * Unit tests for SWPS_Social_Image pure helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-social-image.php';

class SocialImageTest extends TestCase {

	public function test_jpeg_png_gif_are_social_safe(): void {
		$this->assertTrue( SWPS_Social_Image::is_social_safe( 'image/jpeg' ) );
		$this->assertTrue( SWPS_Social_Image::is_social_safe( 'image/png' ) );
		$this->assertTrue( SWPS_Social_Image::is_social_safe( 'image/gif' ) );
	}

	public function test_webp_and_avif_are_not_social_safe(): void {
		$this->assertFalse( SWPS_Social_Image::is_social_safe( 'image/webp' ) );
		$this->assertFalse( SWPS_Social_Image::is_social_safe( 'image/avif' ) );
	}

	public function test_choose_rendition_returns_null_for_empty_list(): void {
		$this->assertNull( SWPS_Social_Image::choose_rendition( array() ) );
	}

	public function test_choose_rendition_prefers_safe_mime_over_webp(): void {
		$webp = array(
			'url'    => 'a.webp',
			'width'  => 1200,
			'height' => 800,
			'mime'   => 'image/webp',
		);
		$png  = array(
			'url'    => 'a.png',
			'width'  => 768,
			'height' => 512,
			'mime'   => 'image/png',
		);

		$chosen = SWPS_Social_Image::choose_rendition( array( $webp, $png ) );
		$this->assertSame( 'a.png', $chosen['url'] );
	}

	public function test_choose_rendition_picks_width_closest_to_1200(): void {
		$small = array(
			'url'    => 's.png',
			'width'  => 300,
			'height' => 200,
			'mime'   => 'image/png',
		);
		$mid   = array(
			'url'    => 'm.png',
			'width'  => 1024,
			'height' => 683,
			'mime'   => 'image/png',
		);
		$huge  = array(
			'url'    => 'h.png',
			'width'  => 2560,
			'height' => 1707,
			'mime'   => 'image/png',
		);

		$chosen = SWPS_Social_Image::choose_rendition( array( $small, $huge, $mid ) );
		$this->assertSame( 'm.png', $chosen['url'] );
	}

	public function test_choose_rendition_prefers_larger_on_equal_distance(): void {
		$below = array(
			'url'    => 'b.png',
			'width'  => 1100,
			'height' => 733,
			'mime'   => 'image/png',
		);
		$above = array(
			'url'    => 'a.png',
			'width'  => 1300,
			'height' => 867,
			'mime'   => 'image/png',
		);

		$chosen = SWPS_Social_Image::choose_rendition( array( $below, $above ) );
		$this->assertSame( 'a.png', $chosen['url'] );
	}

	public function test_choose_rendition_falls_back_to_webp_when_no_safe_candidate(): void {
		$w1 = array(
			'url'    => 'w1.webp',
			'width'  => 300,
			'height' => 200,
			'mime'   => 'image/webp',
		);
		$w2 = array(
			'url'    => 'w2.webp',
			'width'  => 1024,
			'height' => 683,
			'mime'   => 'image/webp',
		);

		$chosen = SWPS_Social_Image::choose_rendition( array( $w1, $w2 ) );
		$this->assertSame( 'w2.webp', $chosen['url'] );
	}

	public function test_choose_rendition_ignores_candidates_without_url_or_width(): void {
		$bad  = array(
			'url'    => '',
			'width'  => 1200,
			'height' => 800,
			'mime'   => 'image/png',
		);
		$bad2 = array(
			'url'    => 'x.png',
			'width'  => 0,
			'height' => 0,
			'mime'   => 'image/png',
		);
		$good = array(
			'url'    => 'ok.png',
			'width'  => 640,
			'height' => 427,
			'mime'   => 'image/png',
		);

		$chosen = SWPS_Social_Image::choose_rendition( array( $bad, $bad2, $good ) );
		$this->assertSame( 'ok.png', $chosen['url'] );

		$this->assertNull( SWPS_Social_Image::choose_rendition( array( $bad, $bad2 ) ) );
	}

	public function test_mime_from_path_maps_common_extensions(): void {
		$this->assertSame( 'image/jpeg', SWPS_Social_Image::mime_from_path( '/up/img.jpg' ) );
		$this->assertSame( 'image/jpeg', SWPS_Social_Image::mime_from_path( 'img.JPEG' ) );
		$this->assertSame( 'image/png', SWPS_Social_Image::mime_from_path( 'https://x.com/a-1024x683.png?v=2' ) );
		$this->assertSame( 'image/webp', SWPS_Social_Image::mime_from_path( 'a.webp' ) );
		$this->assertSame( 'image/avif', SWPS_Social_Image::mime_from_path( 'a.avif' ) );
		$this->assertSame( 'image/gif', SWPS_Social_Image::mime_from_path( 'a.gif' ) );
		$this->assertSame( '', SWPS_Social_Image::mime_from_path( 'a.svg' ) );
		$this->assertSame( '', SWPS_Social_Image::mime_from_path( 'no-extension' ) );
	}
}
