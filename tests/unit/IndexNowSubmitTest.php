<?php
/**
 * Unit tests for IndexNow HTTP submission + flush.
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['swps_test_last_post'] = array( 'url' => $url, 'args' => $args );
		return $GLOBALS['swps_test_http_response'] ?? array( 'response' => array( 'code' => 200 ), 'body' => '' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return $r['response']['code'] ?? 0;
	}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowSubmitTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options']       = array( SWPS_IndexNow::OPT_KEY => str_repeat( 'a', 32 ) );
		$GLOBALS['swps_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '' );
		unset( $GLOBALS['swps_test_last_post'] );
	}

	public function test_submit_posts_json_and_logs_ok(): void {
		$results = ( new SWPS_IndexNow() )->submit_urls( array( 'https://example.com/a' ), 'manual' );
		$this->assertSame( 'ok', $results[0]['result'] );
		$this->assertSame( SWPS_IndexNow::ENDPOINT, $GLOBALS['swps_test_last_post']['url'] );
		$body = json_decode( $GLOBALS['swps_test_last_post']['args']['body'], true );
		$this->assertSame( 'example.com', $body['host'] );
		$this->assertSame( array( 'https://example.com/a' ), $body['urlList'] );
		$this->assertSame( 'ok', SWPS_IndexNow::get_log()[0]['result'] );
	}

	public function test_submit_without_valid_key_logs_no_key(): void {
		$GLOBALS['swps_test_options'][ SWPS_IndexNow::OPT_KEY ] = '';
		$results = ( new SWPS_IndexNow() )->submit_urls( array( 'https://example.com/a' ) );
		$this->assertSame( array(), $results );
		$this->assertSame( 'no_key', SWPS_IndexNow::get_log()[0]['result'] );
	}

	public function test_wp_error_is_logged_as_error(): void {
		$GLOBALS['swps_test_http_response'] = new WP_Error( 'http', 'down' );
		$results = ( new SWPS_IndexNow() )->submit_urls( array( 'https://example.com/a' ) );
		$this->assertSame( 'error', $results[0]['result'] );
	}

	public function test_flush_drains_queue_and_submits(): void {
		$GLOBALS['swps_test_options'][ SWPS_IndexNow::OPT_QUEUE ] = array( 'https://example.com/a', 'https://example.com/b' );
		( new SWPS_IndexNow() )->flush();
		$this->assertSame( array(), get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
		$body = json_decode( $GLOBALS['swps_test_last_post']['args']['body'], true );
		$this->assertCount( 2, $body['urlList'] );
	}

	public function test_flush_on_empty_queue_does_nothing(): void {
		( new SWPS_IndexNow() )->flush();
		$this->assertArrayNotHasKey( 'swps_test_last_post', $GLOBALS );
	}
}
