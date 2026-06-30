<?php
/**
 * Regression test: the page sitemap must not list the static front page twice.
 *
 * render_post_type_sitemap() prepends the homepage (home_url('/')) to the
 * 'page' sitemap, then loops every published page. When a static front page
 * is configured (show_on_front = 'page'), that page's permalink is also
 * home_url('/'), so it was emitted a second time inside the loop — a
 * duplicate <loc> in the sitemap. The fix skips page_on_front in the loop.
 *
 * Stubs run in a separate process so they do not collide with the suite-wide
 * get_option()/home_url() stubs defined in other test files.
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
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		return $GLOBALS['swps_test_posts'] ?? array();
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return $GLOBALS['swps_test_permalinks'][ $id ] ?? home_url( '/?p=' . $id );
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		return '';
	}
}
if ( ! function_exists( 'get_post_modified_time' ) ) {
	function get_post_modified_time( $format = 'U', $gmt = false, $post = null ) {
		return 1700000000;
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return $url;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_xml' ) ) {
	function esc_xml( $text ) {
		return $text;
	}
}

require_once __DIR__ . '/../../includes/class-sitemap-manager.php';

class SitemapHomepageTest extends TestCase {

	/**
	 * Render the first page of the 'page' sitemap and capture its XML.
	 */
	private function render_page_sitemap(): string {
		$ref    = new ReflectionClass( SWPS_Sitemap_Manager::class );
		$obj    = $ref->newInstanceWithoutConstructor();
		$method = $ref->getMethod( 'render_post_type_sitemap' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $obj, 'page', 1 );
		return (string) ob_get_clean();
	}

	/**
	 * With a static front page, the homepage URL must appear exactly once.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_static_front_page_is_not_listed_twice(): void {
		$GLOBALS['swps_test_options'] = array(
			'show_on_front'               => 'page',
			'page_on_front'               => 2,
			'swps_sitemap_exclude_images' => 1,
		);
		// Front page (ID 2, permalink = home_url('/')) plus a normal page.
		$GLOBALS['swps_test_posts']      = array(
			(object) array( 'ID' => 2 ),
			(object) array( 'ID' => 5 ),
		);
		$GLOBALS['swps_test_permalinks'] = array(
			2 => home_url( '/' ),
			5 => home_url( '/about/' ),
		);

		$xml = $this->render_page_sitemap();

		$this->assertSame(
			1,
			substr_count( $xml, '<loc>' . home_url( '/' ) . '</loc>' ),
			'Homepage URL must appear exactly once in the page sitemap.'
		);
		$this->assertStringContainsString( '<loc>' . home_url( '/about/' ) . '</loc>', $xml );
	}

	/**
	 * With a "latest posts" front page there is no static page to dedupe, so
	 * the loop must still emit every page normally (no over-skipping).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_posts_front_page_does_not_skip_pages(): void {
		$GLOBALS['swps_test_options'] = array(
			'show_on_front'               => 'posts',
			'swps_sitemap_exclude_images' => 1,
		);
		$GLOBALS['swps_test_posts']      = array( (object) array( 'ID' => 5 ) );
		$GLOBALS['swps_test_permalinks'] = array( 5 => home_url( '/about/' ) );

		$xml = $this->render_page_sitemap();

		$this->assertSame(
			1,
			substr_count( $xml, '<loc>' . home_url( '/' ) . '</loc>' ),
			'Homepage is prepended once regardless of front-page type.'
		);
		$this->assertStringContainsString( '<loc>' . home_url( '/about/' ) . '</loc>', $xml );
	}
}
