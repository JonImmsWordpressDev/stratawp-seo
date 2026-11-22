<?php
/**
 * Google News sitemap: language codes, entry markup, and the opt-in index entry.
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
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'Example Site';
	}
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array() ) {
		return array();
	}
}
if ( ! function_exists( 'get_taxonomies' ) ) {
	function get_taxonomies( $args = array() ) {
		return array();
	}
}
if ( ! function_exists( 'get_lastpostmodified' ) ) {
	function get_lastpostmodified( $timezone = 'server', $post_type = 'any' ) {
		return '2023-11-14 22:13:20';
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return $url;
	}
}
if ( ! function_exists( 'esc_xml' ) ) {
	function esc_xml( $text ) {
		return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}

require_once __DIR__ . '/../../includes/class-sitemap-manager.php';

class SitemapNewsTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_language_code_from_locale(): void {
		$this->assertSame( 'en', SWPS_Sitemap_Manager::news_language_code( 'en_US' ) );
		$this->assertSame( 'pt', SWPS_Sitemap_Manager::news_language_code( 'pt_BR' ) );
		$this->assertSame( 'de', SWPS_Sitemap_Manager::news_language_code( 'de_DE_formal' ) );
		$this->assertSame( 'fr', SWPS_Sitemap_Manager::news_language_code( 'fr' ) );
		$this->assertSame( 'zh-cn', SWPS_Sitemap_Manager::news_language_code( 'zh_CN' ) );
		$this->assertSame( 'zh-tw', SWPS_Sitemap_Manager::news_language_code( 'zh_TW' ) );
		$this->assertSame( 'zh-tw', SWPS_Sitemap_Manager::news_language_code( 'zh_HK' ) );
		$this->assertSame( 'en', SWPS_Sitemap_Manager::news_language_code( '' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_entries_carry_publication_date_and_escaped_title(): void {
		$xml = SWPS_Sitemap_Manager::render_news_entries(
			array(
				array(
					'loc'       => 'https://example.com/hello/',
					'title'     => 'Q&A: <fast> sites',
					'published' => 1700000000,
				),
			),
			'Example & Co',
			'en'
		);

		$this->assertStringContainsString( '<loc>https://example.com/hello/</loc>', $xml );
		$this->assertStringContainsString( '<news:name>Example &amp; Co</news:name>', $xml );
		$this->assertStringContainsString( '<news:language>en</news:language>', $xml );
		$this->assertStringContainsString( '<news:publication_date>2023-11-14T22:13:20+00:00</news:publication_date>', $xml );
		$this->assertStringContainsString( '<news:title>Q&amp;A: &lt;fast&gt; sites</news:title>', $xml );

		$doc = new DOMDocument();
		$this->assertTrue(
			$doc->loadXML( '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . $xml . '</urlset>' ),
			'News entries must be well-formed XML.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_entries_are_capped_at_google_news_limit(): void {
		$items = array_fill(
			0,
			SWPS_Sitemap_Manager::NEWS_MAX_URLS + 5,
			array(
				'loc'       => 'https://example.com/p/',
				'title'     => 'T',
				'published' => 1700000000,
			)
		);
		$xml = SWPS_Sitemap_Manager::render_news_entries( $items, 'Example', 'en' );
		$this->assertSame( SWPS_Sitemap_Manager::NEWS_MAX_URLS, substr_count( $xml, '<url>' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_publication_name_falls_back_to_site_title(): void {
		$GLOBALS['swps_test_options'] = array( 'swps_sitemap_news_name' => '  ' );
		$this->assertSame( 'Example Site', SWPS_Sitemap_Manager::get_news_publication_name() );

		$GLOBALS['swps_test_options'] = array( 'swps_sitemap_news_name' => 'Example Daily' );
		$this->assertSame( 'Example Daily', SWPS_Sitemap_Manager::get_news_publication_name() );
	}

	/**
	 * The index lists the news sitemap only when the setting is on.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_index_lists_news_sitemap_only_when_enabled(): void {
		$GLOBALS['swps_test_options'] = array( 'swps_sitemap_exclude_author' => 1 );
		$this->assertStringNotContainsString( 'google-news-sitemap.xml', $this->render_index() );

		$GLOBALS['swps_test_options'] = array(
			'swps_sitemap_exclude_author' => 1,
			'swps_sitemap_news_enabled'   => 1,
		);
		$this->assertStringContainsString( '<loc>https://example.com/google-news-sitemap.xml</loc>', $this->render_index() );
	}

	private function render_index(): string {
		$ref    = new ReflectionClass( SWPS_Sitemap_Manager::class );
		$obj    = $ref->newInstanceWithoutConstructor();
		$method = $ref->getMethod( 'render_sitemap_index' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $obj );
		return (string) ob_get_clean();
	}
}
