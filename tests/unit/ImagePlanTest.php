<?php
/**
 * Tests for SWPS_Image_Plan — the per-run featured / in-content image choice.
 *
 * No WordPress dependency — runs in the stub bootstrap environment.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-image-plan.php';

/**
 * Tests for SWPS_Image_Plan value class.
 */
final class ImagePlanTest extends TestCase {

	private const DEFAULTS = array(
		'featured'      => true,
		'content_count' => 2,
	);

	/**
	 * Test missing keys take the defaults.
	 */
	public function test_missing_keys_take_the_defaults(): void {
		$plan = SWPS_Image_Plan::from_request( array(), self::DEFAULTS );

		$this->assertSame( self::DEFAULTS, $plan );
	}

	/**
	 * Test featured accepts common truthy and falsy strings.
	 */
	public function test_featured_accepts_common_truthy_and_falsy_strings(): void {
		$this->assertTrue( SWPS_Image_Plan::from_request( array( 'featured_image' => '1' ), self::DEFAULTS )['featured'] );
		$this->assertTrue( SWPS_Image_Plan::from_request( array( 'featured_image' => 'true' ), self::DEFAULTS )['featured'] );
		$this->assertTrue( SWPS_Image_Plan::from_request( array( 'featured_image' => 'on' ), self::DEFAULTS )['featured'] );
		$this->assertFalse( SWPS_Image_Plan::from_request( array( 'featured_image' => '0' ), self::DEFAULTS )['featured'] );
		$this->assertFalse( SWPS_Image_Plan::from_request( array( 'featured_image' => 'false' ), self::DEFAULTS )['featured'] );
		$this->assertFalse( SWPS_Image_Plan::from_request( array( 'featured_image' => '' ), self::DEFAULTS )['featured'] );
	}

	/**
	 * Test content count is clamped to zero through four.
	 */
	public function test_content_count_is_clamped_to_zero_through_four(): void {
		$this->assertSame( 0, SWPS_Image_Plan::from_request( array( 'content_images' => '-3' ), self::DEFAULTS )['content_count'] );
		$this->assertSame( 4, SWPS_Image_Plan::from_request( array( 'content_images' => '9' ), self::DEFAULTS )['content_count'] );
		$this->assertSame( 3, SWPS_Image_Plan::from_request( array( 'content_images' => '3' ), self::DEFAULTS )['content_count'] );
		$this->assertSame( 0, SWPS_Image_Plan::from_request( array( 'content_images' => 'abc' ), self::DEFAULTS )['content_count'] );
	}

	/**
	 * Test non-scalar values fall back to defaults.
	 */
	public function test_non_scalar_values_fall_back_to_defaults(): void {
		$plan = SWPS_Image_Plan::from_request(
			array(
				'featured_image' => array( 1 ),
				'content_images' => array( 2 ),
			),
			self::DEFAULTS
		);

		$this->assertSame( self::DEFAULTS, $plan );
	}

	/**
	 * Test has_request_keys detects either key.
	 */
	public function test_has_request_keys_detects_either_key(): void {
		$this->assertFalse( SWPS_Image_Plan::has_request_keys( array( 'topic' => 'x' ) ) );
		$this->assertTrue( SWPS_Image_Plan::has_request_keys( array( 'featured_image' => '0' ) ) );
		$this->assertTrue( SWPS_Image_Plan::has_request_keys( array( 'content_images' => '2' ) ) );
	}

	/**
	 * Test meta key is stable.
	 */
	public function test_meta_key_is_stable(): void {
		$this->assertSame( '_swps_image_plan', SWPS_Image_Plan::META_KEY );
	}
}
