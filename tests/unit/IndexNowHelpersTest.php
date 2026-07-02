<?php
/**
 * Unit tests for SWPS_IndexNow pure helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return ( $GLOBALS['swps_test_home'] ?? 'https://example.com' ) . $path;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type() {
		return $GLOBALS['swps_test_env'] ?? 'production';
	}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowHelpersTest extends TestCase {

	public function test_generate_key_is_32_hex_chars(): void {
		$key = SWPS_IndexNow::generate_key();
		$this->assertSame( 32, strlen( $key ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $key );
		$this->assertNotSame( SWPS_IndexNow::generate_key(), $key );
	}

	public function test_is_valid_key(): void {
		$this->assertTrue( SWPS_IndexNow::is_valid_key( str_repeat( 'a', 32 ) ) );
		$this->assertTrue( SWPS_IndexNow::is_valid_key( 'abc1234-DEF' ) );
		$this->assertFalse( SWPS_IndexNow::is_valid_key( 'short' ) );        // < 8
		$this->assertFalse( SWPS_IndexNow::is_valid_key( 'has space here' ) );
		$this->assertFalse( SWPS_IndexNow::is_valid_key( '' ) );
		$this->assertFalse( SWPS_IndexNow::is_valid_key( "aaaaaaaa\n" ) );
	}

	public function test_is_staging_host(): void {
		$this->assertTrue( SWPS_IndexNow::is_staging_host( 'localhost' ) );
		$this->assertTrue( SWPS_IndexNow::is_staging_host( 'jonimms.local' ) );
		$this->assertTrue( SWPS_IndexNow::is_staging_host( 'my-site.test' ) );
		$this->assertTrue( SWPS_IndexNow::is_staging_host( 'staging.example.com' ) );
		$this->assertTrue( SWPS_IndexNow::is_staging_host( 'dev.example.com' ) );
		$this->assertFalse( SWPS_IndexNow::is_staging_host( 'example.com' ) );
		$this->assertFalse( SWPS_IndexNow::is_staging_host( 'www.jonimms.com' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_should_skip_environment_on_non_production(): void {
		$GLOBALS['swps_test_env']  = 'staging';
		$GLOBALS['swps_test_home'] = 'https://example.com';
		$this->assertTrue( SWPS_IndexNow::should_skip_environment() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_should_skip_environment_on_staging_host(): void {
		$GLOBALS['swps_test_env']  = 'production';
		$GLOBALS['swps_test_home'] = 'https://staging.example.com';
		$this->assertTrue( SWPS_IndexNow::should_skip_environment() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_should_not_skip_on_production_live_host(): void {
		$GLOBALS['swps_test_env']  = 'production';
		$GLOBALS['swps_test_home'] = 'https://www.jonimms.com';
		$this->assertFalse( SWPS_IndexNow::should_skip_environment() );
	}

	public function test_build_payload(): void {
		$payload = SWPS_IndexNow::build_payload( 'example.com', 'abcdef1234', array( 'https://example.com/a', 'https://example.com/a' ) );
		$this->assertSame( 'example.com', $payload['host'] );
		$this->assertSame( 'abcdef1234', $payload['key'] );
		$this->assertSame( 'https://example.com/abcdef1234.txt', $payload['keyLocation'] );
		$this->assertSame( array( 'https://example.com/a', 'https://example.com/a' ), $payload['urlList'] );
	}

	public function test_interpret_response_code(): void {
		$this->assertSame( 'ok', SWPS_IndexNow::interpret_response_code( 200 ) );
		$this->assertSame( 'pending', SWPS_IndexNow::interpret_response_code( 202 ) );
		$this->assertSame( 'invalid', SWPS_IndexNow::interpret_response_code( 400 ) );
		$this->assertSame( 'key_not_found', SWPS_IndexNow::interpret_response_code( 403 ) );
		$this->assertSame( 'host_mismatch', SWPS_IndexNow::interpret_response_code( 422 ) );
		$this->assertSame( 'rate_limited', SWPS_IndexNow::interpret_response_code( 429 ) );
		$this->assertSame( 'error', SWPS_IndexNow::interpret_response_code( 500 ) );
	}

	public function test_key_file_path_matches(): void {
		$this->assertTrue( SWPS_IndexNow::key_file_path_matches( '/abc123.txt', 'abc123' ) );
		$this->assertFalse( SWPS_IndexNow::key_file_path_matches( '/other.txt', 'abc123' ) );
		$this->assertFalse( SWPS_IndexNow::key_file_path_matches( '/abc123.txt/', 'abc123' ) );
	}
}
