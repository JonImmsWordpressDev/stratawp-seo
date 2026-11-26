<?php
/**
 * Rewrite health check: 404 detection over mocked loopback HTTP (issue #146).
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RewriteHealthTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/../fixtures/rewrite/stubs.php';
		$GLOBALS['swps_test_options']    = array();
		$GLOBALS['swps_test_transients'] = array();
		$GLOBALS['swps_test_requests']   = array();
		$GLOBALS['swps_test_http']       = array();
		$GLOBALS['swps_test_terms']      = array( (object) array( 'slug' => 'news' ) );
	}

	public function test_probes_index_post_type_sitemap_and_category(): void {
		$health = SWPS_Rewrite_Health::check( false );

		$this->assertSame(
			array(
				'https://example.com/sitemap_index.xml',
				'https://example.com/post-sitemap.xml',
				'https://example.com/category/news/',
			),
			$GLOBALS['swps_test_requests']
		);
		$this->assertSame( array(), $health['broken'] );
	}

	public function test_a_404_is_reported_as_broken(): void {
		$GLOBALS['swps_test_http'] = array(
			'https://example.com/post-sitemap.xml' => 404,
			'https://example.com/category/news/'   => 404,
		);

		$health = SWPS_Rewrite_Health::check( false );

		$this->assertSame( array( 'Post type sitemap', 'Category archive' ), $health['broken'] );
		$this->assertSame( 200, $health['checked']['Sitemap index']['code'] );
	}

	public function test_failed_requests_and_other_errors_are_not_404(): void {
		$GLOBALS['swps_test_http'] = array(
			'https://example.com/sitemap_index.xml' => null,
			'https://example.com/post-sitemap.xml'  => 500,
		);

		$health = SWPS_Rewrite_Health::check( false );

		$this->assertSame( array(), $health['broken'] );
		$this->assertNull( $health['checked']['Sitemap index']['code'] );
	}

	public function test_excluded_post_sitemap_falls_back_to_pages_and_no_category_is_skipped(): void {
		$GLOBALS['swps_test_options'] = array( 'swps_sitemap_exclude_post' => 1 );
		$GLOBALS['swps_test_terms']   = array();

		SWPS_Rewrite_Health::check( false );

		$this->assertSame(
			array( 'https://example.com/sitemap_index.xml', 'https://example.com/page-sitemap.xml' ),
			$GLOBALS['swps_test_requests']
		);
	}

	public function test_result_is_cached_until_rebuild(): void {
		$GLOBALS['swps_test_http'] = array( 'https://example.com/sitemap_index.xml' => 404 );
		SWPS_Rewrite_Health::check();
		SWPS_Rewrite_Health::check();
		$this->assertCount( 3, $GLOBALS['swps_test_requests'], 'Second check is served from cache.' );

		SWPS_Rewrite_Health::rebuild();

		$this->assertSame( 1, $GLOBALS['swps_test_flushes'] );
		$this->assertSame( SWPS_VERSION, $GLOBALS['swps_test_options']['swps_rewrite_version'] );
		$this->assertArrayNotHasKey( SWPS_Rewrite_Health::CACHE_KEY, $GLOBALS['swps_test_transients'] );
	}

	public function test_site_health_reports_critical_with_rebuild_action(): void {
		$GLOBALS['swps_test_http'] = array( 'https://example.com/sitemap_index.xml' => 404 );

		$result = ( new ReflectionClass( SWPS_Rewrite_Health::class ) )->newInstanceWithoutConstructor()->site_health_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'https://example.com/sitemap_index.xml', $result['description'] );
		$this->assertStringContainsString( 'action=swps_rebuild_permalinks', $result['actions'] );
	}

	public function test_site_health_good_and_unreachable(): void {
		$health = ( new ReflectionClass( SWPS_Rewrite_Health::class ) )->newInstanceWithoutConstructor();
		$this->assertSame( 'good', $health->site_health_test()['status'] );

		$GLOBALS['swps_test_http'] = array( 'https://example.com/sitemap_index.xml' => null );
		$this->assertSame( 'recommended', $health->site_health_test()['status'] );
	}
}
