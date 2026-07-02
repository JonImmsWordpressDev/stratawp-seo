<?php
/**
 * Unit tests for the IndexNow auto-enqueue eligibility gate.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['swps_test_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		return $GLOBALS['swps_test_postmeta'][ $id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['swps_test_postmeta'][ $id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return 'https://example.com/?p=' . $id;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $c = -1 ) {
		return parse_url( $url, $c );
	}
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type() {
		return 'production';
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) {
		return true;
	}
}

require_once __DIR__ . '/../../includes/class-sitemap-manager.php';
require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowEligibilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options']  = array(
			SWPS_IndexNow::OPT_ENABLED    => 1,
			SWPS_IndexNow::OPT_AUTO       => 1,
			SWPS_IndexNow::OPT_POST_TYPES => array( 'post', 'page' ),
		);
		$GLOBALS['swps_test_postmeta'] = array();
	}

	private function post( int $id, string $type = 'post', string $modified = '2026-07-02 00:00:00' ): object {
		return (object) array( 'ID' => $id, 'post_status' => 'publish', 'post_type' => $type, 'post_modified_gmt' => $modified );
	}

	public function test_eligible_post_is_enqueued(): void {
		( new SWPS_IndexNow() )->maybe_enqueue_post( $this->post( 10 ) );
		$this->assertContains( 'https://example.com/?p=10', get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}

	public function test_disabled_feature_skips(): void {
		$GLOBALS['swps_test_options'][ SWPS_IndexNow::OPT_ENABLED ] = 0;
		( new SWPS_IndexNow() )->maybe_enqueue_post( $this->post( 10 ) );
		$this->assertSame( array(), get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}

	public function test_unselected_post_type_skips(): void {
		( new SWPS_IndexNow() )->maybe_enqueue_post( $this->post( 10, 'product' ) );
		$this->assertSame( array(), get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}

	public function test_noop_resave_is_suppressed(): void {
		$in = new SWPS_IndexNow();
		$in->maybe_enqueue_post( $this->post( 10, 'post', '2026-07-02 00:00:00' ) );
		$GLOBALS['swps_test_options'][ SWPS_IndexNow::OPT_QUEUE ] = array(); // simulate a flush
		$in->maybe_enqueue_post( $this->post( 10, 'post', '2026-07-02 00:00:00' ) ); // identical modified
		$this->assertSame( array(), get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}

	public function test_edited_post_resubmits(): void {
		$in = new SWPS_IndexNow();
		$in->maybe_enqueue_post( $this->post( 10, 'post', '2026-07-02 00:00:00' ) );
		$GLOBALS['swps_test_options'][ SWPS_IndexNow::OPT_QUEUE ] = array();
		$in->maybe_enqueue_post( $this->post( 10, 'post', '2026-07-03 12:00:00' ) ); // changed
		$this->assertContains( 'https://example.com/?p=10', get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}
}
