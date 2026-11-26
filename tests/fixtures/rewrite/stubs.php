<?php
/**
 * WP stubs for RewriteFlushGuardTest and RewriteHealthTest. Required from
 * inside each test, which runs in its own process, so these never shadow
 * other test files' stubs.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'SWPS_VERSION' ) ) {
	define( 'SWPS_VERSION', '9.9.9' );
}

function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}
function admin_url( $path = '' ) {
	return 'https://example.com/wp-admin/' . $path;
}
function get_option( $option, $default_value = false ) {
	return array_key_exists( $option, $GLOBALS['swps_test_options'] ?? array() ) ? $GLOBALS['swps_test_options'][ $option ] : $default_value;
}
function update_option( $option, $value, $autoload = null ) {
	$GLOBALS['swps_test_options'][ $option ] = $value;
	return true;
}
function delete_option( $option ) {
	unset( $GLOBALS['swps_test_options'][ $option ] );
	return true;
}
function get_transient( $key ) {
	return $GLOBALS['swps_test_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['swps_test_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['swps_test_transients'][ $key ] );
	return true;
}
function flush_rewrite_rules( $hard = true ) {
	$GLOBALS['swps_test_flushes'] = ( $GLOBALS['swps_test_flushes'] ?? 0 ) + 1;
}
function is_admin() {
	return ! empty( $GLOBALS['swps_test_ctx']['admin'] );
}
function wp_doing_ajax() {
	return ! empty( $GLOBALS['swps_test_ctx']['ajax'] );
}
function wp_doing_cron() {
	return ! empty( $GLOBALS['swps_test_ctx']['cron'] );
}
function wp_is_serving_rest_request() {
	return ! empty( $GLOBALS['swps_test_ctx']['rest'] );
}
function apply_filters( $hook, $value, ...$args ) {
	return $value;
}
function get_terms( $args = array() ) {
	return $GLOBALS['swps_test_terms'] ?? array();
}
function get_term_link( $term ) {
	return 'https://example.com/category/' . $term->slug . '/';
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['swps_test_requests'][] = $url;
	$http = $GLOBALS['swps_test_http'] ?? array();
	$code = array_key_exists( $url, $http ) ? $http[ $url ] : 200;
	if ( null === $code ) {
		return new WP_Error( 'http_request_failed', 'cURL error 7' );
	}
	return array( 'response' => array( 'code' => $code ) );
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
}
function wp_nonce_url( $url, $action = -1 ) {
	return $url . '&_wpnonce=test';
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}
function esc_url( $url ) {
	return $url;
}

require_once __DIR__ . '/../../../includes/class-sitemap-manager.php';
require_once __DIR__ . '/../../../includes/class-rewrite-health.php';
