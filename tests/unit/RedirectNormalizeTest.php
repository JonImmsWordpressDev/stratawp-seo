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
		// Like core's _sanitize_text_fields(), remove percent-encoded octets.
		while ( preg_match( '/%[a-f0-9]{2}/i', $str ) ) {
			$str = preg_replace( '/%[a-f0-9]{2}/i', '', $str );
		}
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

	/**
	 * @dataProvider encoded_source_cases
	 */
	public function test_non_ascii_and_space_sources_keep_their_characters( string $source, string $expected ): void {
		$this->assertSame( $expected, $this->invoke( 'normalize_source_url', $source, false ) );
	}

	public static function encoded_source_cases(): array {
		return array(
			'percent-encoded accent' => array( '/caf%C3%A9', '/café' ),
			'literal accent'         => array( '/café/', '/café' ),
			'encoded spaces'         => array( '/my%20old%20page', '/my old page' ),
			'full url, encoded'      => array( 'https://jonimms.com/caf%C3%A9/', '/café' ),
			'non-latin slug'         => array( '/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82', '/привет' ),
		);
	}

	/**
	 * maybe_redirect() compares clean_path() of the raw request path with the
	 * stored source, so both spellings of a source must match the encoded
	 * path a browser sends.
	 *
	 * @dataProvider request_match_cases
	 */
	public function test_encoded_request_path_matches_stored_source( string $request_path, string $source ): void {
		$stored = $this->invoke( 'normalize_source_url', $source, false );
		$this->assertSame( $stored, rtrim( SWPS_Redirect_Manager::clean_path( $request_path ), '/' ) );
	}

	public static function request_match_cases(): array {
		return array(
			'typed encoded'     => array( '/caf%C3%A9', '/caf%C3%A9' ),
			'typed literal'     => array( '/caf%C3%A9/', '/café' ),
			'spaces'            => array( '/my%20old%20page', '/my old page' ),
			'lowercase escapes' => array( '/caf%c3%a9', '/café' ),
		);
	}

	public function test_clean_path_strips_tags_and_control_characters(): void {
		$this->assertSame( '/foo', SWPS_Redirect_Manager::clean_path( '/<b>foo</b>%0A%00' ) );
	}

	public function test_clean_path_rejects_invalid_utf8(): void {
		$this->assertSame( '', SWPS_Redirect_Manager::clean_path( '/bad%FF' ) );
	}

	public function test_add_redirect_stores_decoded_source(): void {
		global $wpdb;
		$wpdb = new SWPS_Test_Redirects_WPDB();

		$ref      = new ReflectionClass( SWPS_Redirect_Manager::class );
		$instance = $ref->newInstanceWithoutConstructor();

		// 410 skips the target checks, which need WordPress.
		$id = $instance->add_redirect( '/caf%C3%A9', '', 410 );

		$this->assertSame( 1, $id );
		$this->assertSame( '/café', $wpdb->inserts[0]['source_url'] );
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

	// -------------------------------------------------------------------------
	// is_hub_target
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider hub_target_cases
	 */
	public function test_is_hub_target( string $target, bool $expected ): void {
		$this->assertSame(
			$expected,
			SWPS_Redirect_Manager::is_hub_target( $target, array( '/', '/blog/' ), 'jonimms.com' )
		);
	}

	public static function hub_target_cases(): array {
		return array(
			'blog index'                  => array( '/blog/', true ),
			'blog index without slash'    => array( '/blog', true ),
			'blog index full url'         => array( 'https://jonimms.com/blog/', true ),
			'blog index mixed-case host'  => array( 'https://JonImms.com/Blog/', true ),
			'site root'                   => array( '/', true ),
			'site root full url'          => array( 'https://jonimms.com', true ),
			'post under blog'             => array( '/blog/some-post/', false ),
			'tag archive'                 => array( '/blog/tag/php/', false ),
			'other page'                  => array( '/services/', false ),
			'off-site blog index'         => array( 'https://example.com/blog/', false ),
			'empty target (410)'          => array( '', false ),
		);
	}

	public function test_is_hub_target_with_no_posts_page(): void {
		$this->assertFalse( SWPS_Redirect_Manager::is_hub_target( '/blog/', array( '/' ), 'jonimms.com' ) );
	}
}
