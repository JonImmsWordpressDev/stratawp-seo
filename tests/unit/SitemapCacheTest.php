<?php
/**
 * Cached sitemap generation: hits, invalidation, identical output, and
 * batched rendering for large post type sitemaps (issue #145).
 *
 * Every test runs in its own process and loads its stubs there.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SitemapCacheTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/../fixtures/sitemap/cache-stubs.php';
		$GLOBALS['swps_test_options']      = array( 'swps_sitemap_exclude_images' => 1 );
		$GLOBALS['swps_test_transients']   = array();
		$GLOBALS['swps_test_post_objects'] = array();
		foreach ( array( 3, 5, 8 ) as $id ) {
			$GLOBALS['swps_test_post_objects'][ $id ] = (object) array( 'ID' => $id );
		}
	}

	private function manager(): SWPS_Sitemap_Manager {
		return ( new ReflectionClass( SWPS_Sitemap_Manager::class ) )->newInstanceWithoutConstructor();
	}

	private function render_uncached( SWPS_Sitemap_Manager $manager, string $type, int $page ): string {
		$method = new ReflectionMethod( SWPS_Sitemap_Manager::class, 'render_type' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( $manager, $type, $page );
		return (string) ob_get_clean();
	}

	private function add_posts( int $count ): void {
		$GLOBALS['swps_test_post_objects'] = array();
		for ( $id = 1; $id <= $count; $id++ ) {
			$GLOBALS['swps_test_post_objects'][ $id ] = (object) array( 'ID' => $id );
		}
	}

	public function test_second_request_is_served_from_cache(): void {
		$manager = $this->manager();

		$first = $manager->get_sitemap_xml( 'post', 1 );
		$this->assertSame( 1, $GLOBALS['swps_test_get_posts_calls'] );
		$this->assertCount( 1, $GLOBALS['swps_test_transients'] );

		$second = $manager->get_sitemap_xml( 'post', 1 );
		$this->assertSame( 1, $GLOBALS['swps_test_get_posts_calls'], 'A cache hit must not query posts again.' );
		$this->assertSame( $first, $second );
	}

	public function test_cached_xml_is_byte_identical_to_uncached(): void {
		$manager  = $this->manager();
		$uncached = array(
			'post'     => $this->render_uncached( $manager, 'post', 1 ),
			'index'    => $this->render_uncached( $manager, 'index', 1 ),
		);

		foreach ( $uncached as $type => $xml ) {
			$this->assertSame( $xml, $manager->get_sitemap_xml( $type, 1 ), "$type: cache miss output" );
			$this->assertSame( $xml, $manager->get_sitemap_xml( $type, 1 ), "$type: cache hit output" );
		}
		$this->assertStringContainsString( '<loc>https://example.com/?p=5</loc>', $uncached['post'] );
	}

	public function test_save_post_invalidates_the_cache(): void {
		$manager = $this->manager();
		$before  = $manager->get_sitemap_xml( 'post', 1 );

		$GLOBALS['swps_test_permalinks'] = array( 5 => 'https://example.com/renamed/' );
		$this->assertSame( $before, $manager->get_sitemap_xml( 'post', 1 ), 'Still cached before the save hook.' );

		$manager->on_save_post( 5, (object) array( 'ID' => 5, 'post_type' => 'post' ) );

		$after = $manager->get_sitemap_xml( 'post', 1 );
		$this->assertNotSame( $before, $after );
		$this->assertStringContainsString( '<loc>https://example.com/renamed/</loc>', $after );
	}

	public function test_revision_save_does_not_invalidate(): void {
		$manager = $this->manager();
		$key     = SWPS_Sitemap_Manager::cache_key( 'post', 1 );

		$manager->on_save_post( 9, (object) array( 'ID' => 9, 'post_type' => 'revision' ) );

		$this->assertSame( $key, SWPS_Sitemap_Manager::cache_key( 'post', 1 ) );
	}

	public function test_status_change_meta_and_option_changes_invalidate(): void {
		$cases = array(
			'status change' => fn( $m ) => $m->on_transition_post_status( 'trash', 'publish' ),
			'exclude meta'  => fn( $m ) => $m->on_meta_change( 1, 5, '_swps_sitemap_exclude' ),
			'option'        => fn( $m ) => $m->on_option_change( 'swps_sitemap_urls_per_page' ),
			'noindex'       => fn( $m ) => $m->on_option_change( 'swps_noindex_post' ),
			'permalinks'    => fn( $m ) => $m->on_option_change( 'permalink_structure' ),
		);
		foreach ( $cases as $label => $change ) {
			$manager = $this->manager();
			$key     = SWPS_Sitemap_Manager::cache_key( 'post', 1 );
			$change( $manager );
			$this->assertNotSame( $key, SWPS_Sitemap_Manager::cache_key( 'post', 1 ), $label );
		}
	}

	public function test_unrelated_changes_keep_the_cache(): void {
		$manager = $this->manager();
		$key     = SWPS_Sitemap_Manager::cache_key( 'post', 1 );

		$manager->on_transition_post_status( 'publish', 'publish' );
		$manager->on_meta_change( 1, 5, '_edit_lock' );
		$manager->on_option_change( 'blogdescription' );
		$manager->on_option_change( SWPS_Sitemap_Manager::CACHE_GEN_OPTION );

		$this->assertSame( $key, SWPS_Sitemap_Manager::cache_key( 'post', 1 ) );
	}

	public function test_pages_past_the_last_are_not_cached(): void {
		$manager = $this->manager();

		$xml = $manager->get_sitemap_xml( 'post', 99 );

		// Past the last page is a 404 (issue #139), and nothing is cached.
		$this->assertNull( $xml );
		$this->assertSame( array(), $GLOBALS['swps_test_transients'] );
	}

	public function test_news_sitemap_is_not_cached(): void {
		$this->assertFalse( SWPS_Sitemap_Manager::is_cacheable_type( SWPS_Sitemap_Manager::NEWS_TYPE ) );
		$this->assertTrue( SWPS_Sitemap_Manager::is_cacheable_type( 'post' ) );
	}

	public function test_unknown_type_is_a_404(): void {
		$this->assertNull( $this->manager()->get_sitemap_xml( 'nope', 1 ) );
	}

	public function test_large_sitemap_renders_in_batches_and_releases_them(): void {
		$this->add_posts( 450 );
		$manager = $this->manager();

		$xml = $manager->get_sitemap_xml( 'post', 1 );

		$this->assertSame( 450, substr_count( (string) $xml, '<url>' ) );
		$batches = array_map( 'count', $GLOBALS['swps_test_primed'] );
		$this->assertSame( array( 200, 200, 50 ), $batches );
		$this->assertContains( 'posts:1', $GLOBALS['swps_test_cache_deletes'] );
		$this->assertContains( 'post_meta:450', $GLOBALS['swps_test_cache_deletes'] );
	}

	public function test_entries_keep_query_order_across_batches(): void {
		$this->add_posts( 250 );

		$xml = (string) $this->manager()->get_sitemap_xml( 'post', 1 );

		preg_match_all( '#<loc>https://example.com/\?p=(\d+)</loc>#', $xml, $m );
		$this->assertSame( range( 1, 250 ), array_map( 'intval', $m[1] ) );
	}
}
