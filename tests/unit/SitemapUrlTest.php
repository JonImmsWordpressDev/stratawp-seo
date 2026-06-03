<?php
/**
 * Regression tests for the canonical sitemap URL advertised in robots.txt
 * and llms.txt.
 *
 * Issue #48: robots.txt advertised the legacy /swps-sitemap.xml redirect
 * instead of the canonical /sitemap_index.xml that SWPS_Sitemap_Manager
 * actually serves, so crawlers reported the sitemap as missing.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

// Minimal stub: the URL builder only needs home_url().
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}

require_once __DIR__ . '/../../includes/class-sitemap-manager.php';

class SitemapUrlTest extends TestCase {

	/**
	 * The reported scenario: no third-party SEO plugin active, so StrataWP SEO
	 * serves the sitemap itself. robots.txt must advertise the canonical
	 * /sitemap_index.xml, never the legacy /swps-sitemap.xml redirect.
	 */
	public function test_default_advertises_canonical_sitemap_index(): void {
		$url = SWPS_Sitemap_Manager::get_sitemap_url();

		$this->assertSame( 'https://example.com/sitemap_index.xml', $url );
		$this->assertStringNotContainsString( 'swps-sitemap.xml', $url );
	}

	/**
	 * Yoast SEO serves its index at /sitemap_index.xml — defer to it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_yoast_uses_sitemap_index(): void {
		define( 'WPSEO_VERSION', '99.0' );

		$this->assertSame(
			'https://example.com/sitemap_index.xml',
			SWPS_Sitemap_Manager::get_sitemap_url()
		);
	}

	/**
	 * All in One SEO serves its index at /sitemap.xml — defer to it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_aioseo_uses_its_own_sitemap(): void {
		define( 'AIOSEO_VERSION', '4.0' );

		$this->assertSame(
			'https://example.com/sitemap.xml',
			SWPS_Sitemap_Manager::get_sitemap_url()
		);
	}
}
