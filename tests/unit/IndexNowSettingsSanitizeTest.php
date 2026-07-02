<?php
/**
 * Unit tests for IndexNow settings sanitizers (array footgun guard).
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
	}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowSettingsSanitizeTest extends TestCase {

	public function test_checkbox_sanitizes_to_int(): void {
		$this->assertSame( 1, SWPS_IndexNow::sanitize_checkbox( '1' ) );
		$this->assertSame( 1, SWPS_IndexNow::sanitize_checkbox( 'on' ) );
		$this->assertSame( 0, SWPS_IndexNow::sanitize_checkbox( '' ) );
		$this->assertSame( 0, SWPS_IndexNow::sanitize_checkbox( null ) );
	}

	public function test_post_types_stay_an_array(): void {
		$this->assertSame( array( 'post', 'page' ), SWPS_IndexNow::sanitize_post_types( array( 'post', 'page' ) ) );
	}

	public function test_post_types_sanitizes_keys_and_drops_bad_input(): void {
		$this->assertSame( array( 'mycpt' ), SWPS_IndexNow::sanitize_post_types( array( 'My CPT!' ) ) );
		$this->assertSame( array(), SWPS_IndexNow::sanitize_post_types( 'not-an-array' ) );
		$this->assertSame( array(), SWPS_IndexNow::sanitize_post_types( null ) );
	}
}
