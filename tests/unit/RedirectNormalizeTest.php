<?php
/**
 * Tests for SWPS_Redirect_Manager source/target URL normalization.
 *
 * Pure-PHP, no WordPress. The class is required for its normalization
 * helpers only; the constructor (which needs WP hooks) is never called —
 * instances are created via newInstanceWithoutConstructor() and the
 * private methods invoked through reflection.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = wp_strip_all_tags( $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( $str );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return '2026-08-08 00:00:00';
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! class_exists( 'SWPS_Test_Redirects_WPDB' ) ) {
	/**
	 * Captures insert() payloads so tests can assert on what gets stored.
	 */
	class SWPS_Test_Redirects_WPDB {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public array $inserts = array();

		public function insert( $table, $data, $format = null ) {
			$this->inserts[] = $data;
			$this->insert_id = count( $this->inserts );
			return 1;
		}
	}
}

require_once __DIR__ . '/../../includes/class-redirect-manager.php';

/**
 * @covers SWPS_Redirect_Manager
 */
class RedirectNormalizeTest extends TestCase {

	private function invoke( string $method, ...$args ) {
		$ref      = new ReflectionClass( SWPS_Redirect_Manager::class );
		$instance = $ref->newInstanceWithoutConstructor();
		$m        = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $instance, ...$args );
	}

	// -------------------------------------------------------------------------
	// normalize_source_url
	// -------------------------------------------------------------------------

	public function test_plain_path_is_kept(): void {
		$this->assertSame( '/tag/analytics', $this->invoke( 'normalize_source_url', '/tag/analytics/', false ) );
	}

	public function test_full_url_is_reduced_to_path(): void {
		$this->assertSame( '/tag/analytics', $this->invoke( 'normalize_source_url', 'https://jonimms.com/tag/analytics/', false ) );
	}

	public function test_schemeless_host_url_is_reduced_to_path(): void {
		$this->assertSame( '/tag/analytics', $this->invoke( 'normalize_source_url', 'jonimms.com/tag/analytics/', false ) );
	}

	public function test_schemeless_www_host_url_is_reduced_to_path(): void {
		$this->assertSame( '/tag/analytics', $this->invoke( 'normalize_source_url', 'www.jonimms.com/tag/analytics/', false ) );
	}

	public function test_relative_path_without_leading_slash_gets_one(): void {
		$this->assertSame( '/tag/analytics', $this->invoke( 'normalize_source_url', 'tag/analytics', false ) );
	}

	public function test_bare_domain_normalizes_to_root(): void {
		// Root sources are rejected later by validate_redirect().
		$this->assertSame( '/', $this->invoke( 'normalize_source_url', 'jonimms.com', false ) );
	}

	public function test_regex_source_is_untouched(): void {
		$this->assertSame( '^/tag/(.*)$', $this->invoke( 'normalize_source_url', '^/tag/(.*)$', true ) );
	}

	public function test_regex_source_backslash_sequences_are_preserved(): void {
		// Regression: wp_unslash() inside normalize_source_url() stripped the
		// backslash from \d for input not slashed by WP (programmatic callers),
		// storing a pattern that never matches. Unslashing belongs at the
		// $_POST boundary, which every AJAX handler already does.
		$this->assertSame(
			'^/category/([^/]+)(?:/page/\d+)?$',
			$this->invoke( 'normalize_source_url', '^/category/([^/]+)(?:/page/\d+)?$', true )
		);
	}

	// -------------------------------------------------------------------------
	// add_redirect (storage)
	// -------------------------------------------------------------------------

	public function test_add_redirect_stores_regex_backslash_verbatim(): void {
		global $wpdb;
		$wpdb = new SWPS_Test_Redirects_WPDB();

		$ref      = new ReflectionClass( SWPS_Redirect_Manager::class );
		$instance = $ref->newInstanceWithoutConstructor();

		$id = $instance->add_redirect( '^/foo/(\d+)$', '/bar/$1', 301, true );

		$this->assertSame( 1, $id );
		$this->assertSame( '^/foo/(\d+)$', $wpdb->inserts[0]['source_url'] );
	}

	// -------------------------------------------------------------------------
	// normalize_target_url
	// -------------------------------------------------------------------------

	public function test_target_full_url_is_preserved(): void {
		$this->assertSame( 'https://jonimms.com/blog/', $this->invoke( 'normalize_target_url', 'https://jonimms.com/blog/', 301 ) );
	}

	public function test_target_schemeless_host_gets_https_scheme(): void {
		$this->assertSame( 'https://jonimms.com/blog/', $this->invoke( 'normalize_target_url', 'jonimms.com/blog/', 301 ) );
	}

	public function test_target_relative_path_gets_leading_slash(): void {
		$this->assertSame( '/blog/', $this->invoke( 'normalize_target_url', 'blog/', 301 ) );
	}

	public function test_target_empty_for_410(): void {
		$this->assertSame( '', $this->invoke( 'normalize_target_url', 'https://jonimms.com/blog/', 410 ) );
	}

	public function test_target_rejects_unsafe_scheme(): void {
		$this->assertSame( '', $this->invoke( 'normalize_target_url', 'javascript:alert(1)', 301 ) );
	}
}
