<?php
/**
 * Unit tests for the shared IndexNow/sitemap eligibility predicate.
 *
 * Tests whose expected result depends on non-default get_option()/
 * get_post_meta()/get_term_meta() (or the other WP stubs defined below)
 * stub behavior run in a separate process so they do not collide with
 * same-named stubs defined in other suite-wide test files (e.g.
 * SchemaGraphTest's get_option() and SitemapHomepageTest's get_post_meta()
 * and get_permalink(), all guarded by function_exists()).
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
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key = '', $single = false ) {
		return $GLOBALS['swps_test_termmeta'][ $term_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array() ) {
		return $GLOBALS['swps_test_post_types'] ?? array();
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		return $GLOBALS['swps_test_post_ids'] ?? array();
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return $GLOBALS['swps_test_post_objects'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return $GLOBALS['swps_test_permalinks'][ $id ] ?? '';
	}
}
if ( ! function_exists( 'get_taxonomies' ) ) {
	function get_taxonomies( $args = array() ) {
		return $GLOBALS['swps_test_taxonomies'] ?? array();
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array() ) {
		return $GLOBALS['swps_test_terms'] ?? array();
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		$id = is_object( $term ) ? $term->term_id : (int) $term;
		return $GLOBALS['swps_test_term_links'][ $id ] ?? '';
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'get_users' ) ) {
	function get_users( $args = array() ) {
		return $GLOBALS['swps_test_users'] ?? array();
	}
}
if ( ! function_exists( 'get_author_posts_url' ) ) {
	function get_author_posts_url( $author_id ) {
		return 'https://example.com/author/' . $author_id . '/';
	}
}

require_once __DIR__ . '/../../includes/class-sitemap-manager.php';

class SitemapIndexableTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options']  = array();
		$GLOBALS['swps_test_postmeta'] = array();
		$GLOBALS['swps_test_termmeta'] = array();
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

	public function test_indexable_term_is_indexable(): void {
		$this->assertTrue( SWPS_Sitemap_Manager::is_term_indexable( 1, 'category' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_excluded_term_meta_blocks_term_indexing(): void {
		$GLOBALS['swps_test_termmeta'][2]['_swps_sitemap_exclude'] = 1;
		$this->assertFalse( SWPS_Sitemap_Manager::is_term_indexable( 2, 'category' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_noindex_robots_term_meta_blocks_term_indexing(): void {
		$GLOBALS['swps_test_termmeta'][3]['_swps_robots'] = 'noindex,follow';
		$this->assertFalse( SWPS_Sitemap_Manager::is_term_indexable( 3, 'category' ) );
	}

	/**
	 * Hidden taxonomy must block term indexing even when the term itself has
	 * no exclude/noindex meta — is_term_indexable() must be self-contained
	 * (taxonomy-aware) so later IndexNow tasks can call it directly on
	 * term-change hooks without re-checking taxonomy visibility themselves.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_hidden_taxonomy_blocks_term_indexing(): void {
		$GLOBALS['swps_test_options']['swps_sitemap_exclude_product_cat'] = 1;
		$this->assertFalse( SWPS_Sitemap_Manager::is_term_indexable( 4, 'product_cat' ) );
	}

	/**
	 * Same as above but via the swps_noindex_{tax} option instead of the
	 * sitemap-exclude option, covering the other half of
	 * is_taxonomy_hidden_from_sitemap()'s OR condition.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_noindex_taxonomy_option_blocks_term_indexing(): void {
		$GLOBALS['swps_test_options']['swps_noindex_product_cat'] = 1;
		$this->assertFalse( SWPS_Sitemap_Manager::is_term_indexable( 5, 'product_cat' ) );
	}

	/**
	 * Focused integration test for get_indexable_urls(): the homepage and an
	 * eligible post permalink must be present; a post excluded via
	 * _swps_sitemap_exclude must not appear. Also exercises the taxonomy
	 * loop's updated is_term_indexable( $term_id, $taxonomy ) call site and
	 * the get_term_link() WP_Error guard — an eligible term (20) whose
	 * get_term_link() resolves normally must appear, while an eligible term
	 * (21) whose get_term_link() returns a WP_Error must be silently
	 * skipped rather than surviving into the URL list (previously this
	 * WP_Error would reach array_unique() and fatal in PHP 8).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_indexable_urls_includes_eligible_excludes_ineligible(): void {
		$GLOBALS['swps_test_postmeta'] = array(
			11 => array( '_swps_sitemap_exclude' => 1 ),
		);

		$GLOBALS['swps_test_post_types']   = array( 'post' );
		$GLOBALS['swps_test_post_ids']     = array( 10, 11 );
		$GLOBALS['swps_test_post_objects'] = array(
			10 => $this->post( 10 ),
			11 => $this->post( 11 ),
		);
		$GLOBALS['swps_test_permalinks'] = array(
			10 => 'https://example.com/eligible-post/',
			11 => 'https://example.com/excluded-post/',
		);

		$GLOBALS['swps_test_taxonomies'] = array( 'category' );
		$GLOBALS['swps_test_terms']      = array(
			(object) array( 'term_id' => 20 ),
			(object) array( 'term_id' => 21 ),
		);
		$GLOBALS['swps_test_term_links'] = array(
			20 => 'https://example.com/category/eligible-term/',
			21 => new WP_Error( 'invalid_term_link', 'no link available' ),
		);

		$GLOBALS['swps_test_users'] = array( 7 );

		$urls = SWPS_Sitemap_Manager::get_indexable_urls();

		$this->assertContains( home_url( '/' ), $urls, 'Homepage must be present.' );
		$this->assertContains( 'https://example.com/eligible-post/', $urls, 'Eligible post permalink must be present.' );
		$this->assertNotContains( 'https://example.com/excluded-post/', $urls, 'Excluded post must not be present.' );
		$this->assertContains( 'https://example.com/category/eligible-term/', $urls, 'Eligible term link must be present.' );
		$this->assertContains( 'https://example.com/author/7/', $urls, 'Author URL must be present.' );

		foreach ( $urls as $url ) {
			$this->assertNotInstanceOf( WP_Error::class, $url, 'A WP_Error must never survive into the URL list.' );
		}
	}
}
