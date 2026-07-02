<?php
/**
 * Unit tests for the shared IndexNow/sitemap eligibility predicate.
 *
 * Tests whose expected result depends on non-default get_option()/
 * get_post_meta() stub behavior run in a separate process so they do not
 * collide with same-named stubs defined in other suite-wide test files
 * (e.g. SchemaGraphTest's get_option() and SitemapHomepageTest's
 * get_post_meta(), both guarded by function_exists()).
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		return $GLOBALS['swps_test_postmeta'][ $id ][ $key ] ?? '';
	}
}

require_once __DIR__ . '/../../includes/class-sitemap-manager.php';

class SitemapIndexableTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options']  = array();
		$GLOBALS['swps_test_postmeta'] = array();
	}

	private function post( int $id, string $status = 'publish', string $type = 'post' ): object {
		return (object) array( 'ID' => $id, 'post_status' => $status, 'post_type' => $type );
	}

	public function test_published_post_is_indexable(): void {
		$this->assertTrue( SWPS_Sitemap_Manager::is_post_indexable( $this->post( 1 ) ) );
	}

	public function test_draft_is_not_indexable(): void {
		$this->assertFalse( SWPS_Sitemap_Manager::is_post_indexable( $this->post( 1, 'draft' ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_excluded_post_meta_blocks_indexing(): void {
		$GLOBALS['swps_test_postmeta'][2]['_swps_sitemap_exclude'] = 1;
		$this->assertFalse( SWPS_Sitemap_Manager::is_post_indexable( $this->post( 2 ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_noindex_robots_meta_blocks_indexing(): void {
		$GLOBALS['swps_test_postmeta'][3]['_swps_robots'] = 'noindex,follow';
		$this->assertFalse( SWPS_Sitemap_Manager::is_post_indexable( $this->post( 3 ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_hidden_post_type_blocks_indexing(): void {
		$GLOBALS['swps_test_options']['swps_sitemap_exclude_product'] = 1;
		$this->assertFalse( SWPS_Sitemap_Manager::is_post_indexable( $this->post( 4, 'publish', 'product' ) ) );
	}
}
