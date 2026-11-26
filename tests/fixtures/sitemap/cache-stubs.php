<?php
/**
 * WP stubs for SitemapCacheTest. Required from inside each test, which runs
 * in its own process, so these never shadow other test files' stubs.
 *
 * @package StrataWP_SEO
 */

function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}
function get_option( $option, $default_value = false ) {
	return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
}
function update_option( $option, $value, $autoload = null ) {
	$GLOBALS['swps_test_options'][ $option ] = $value;
	return true;
}
function get_transient( $key ) {
	$GLOBALS['swps_test_transient_gets'] = ( $GLOBALS['swps_test_transient_gets'] ?? 0 ) + 1;
	return $GLOBALS['swps_test_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['swps_test_transients'][ $key ] = $value;
	return true;
}
function get_taxonomies( $args = array() ) {
	return array( 'category' => 'category' );
}
function get_terms( $args = array() ) {
	return array();
}
function is_wp_error( $thing ) {
	return false;
}
function get_post_types( $args = array() ) {
	return array( 'post' => 'post', 'page' => 'page' );
}
function get_posts( $args = array() ) {
	$GLOBALS['swps_test_get_posts_calls'] = ( $GLOBALS['swps_test_get_posts_calls'] ?? 0 ) + 1;
	$ids = array_keys( $GLOBALS['swps_test_post_objects'] ?? array() );
	$ids = array_slice( $ids, (int) ( $args['offset'] ?? 0 ), (int) ( $args['posts_per_page'] ?? 10 ) );
	return 'ids' === ( $args['fields'] ?? '' ) ? $ids : array_map( 'get_post', $ids );
}
function get_post( $id ) {
	return $GLOBALS['swps_test_post_objects'][ $id ] ?? null;
}
function _prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true ) {
	$GLOBALS['swps_test_primed'][] = $ids;
}
function wp_using_ext_object_cache( $using = null ) {
	return false;
}
function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['swps_test_cache_deletes'][] = $group . ':' . $key;
	return true;
}
function get_post_meta( $id, $key = '', $single = false ) {
	return '';
}
function get_post_modified_time( $format = 'U', $gmt = false, $post = null ) {
	return 1700000000 + (int) $post->ID;
}
function get_permalink( $post = 0 ) {
	$id = is_object( $post ) ? $post->ID : (int) $post;
	return $GLOBALS['swps_test_permalinks'][ $id ] ?? home_url( '/?p=' . $id );
}
function get_lastpostmodified( $timezone = 'server', $post_type = 'any' ) {
	return '2023-11-14 22:13:20';
}
function esc_url( $url ) {
	return $url;
}
function esc_attr( $text ) {
	return $text;
}
function esc_xml( $text ) {
	return $text;
}

/**
 * Minimal $wpdb: published post counts come from the test posts.
 */
class SWPS_Test_Sitemap_Wpdb {
	public $posts = 'wp_posts';
	public function prepare( $query, ...$args ) {
		return $query;
	}
	public function get_var( $query ) {
		if ( str_contains( $query, 'COUNT(*)' ) ) {
			return count( $GLOBALS['swps_test_post_objects'] ?? array() );
		}
		return '2023-11-14 22:13:20';
	}
}
$GLOBALS['wpdb'] = new SWPS_Test_Sitemap_Wpdb();

require_once __DIR__ . '/../../../includes/class-sitemap-manager.php';
