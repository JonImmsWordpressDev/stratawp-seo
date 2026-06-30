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

	/**
	 * The legacy /swps-sitemap.xml URL must 301 to the canonical index. Its
	 * dedicated rewrite resolves to `legacy_redirect`, but the generic
	 * `{type}-sitemap.xml` rule can win the ordering and yield the raw prefix
	 * `swps`. Both must be treated as the legacy redirect so the URL resolves
	 * regardless of which rewrite rule fires first (issue: /swps-sitemap.xml
	 * 404'd in production because the generic rule matched first).
	 */
	public function test_legacy_redirect_type_covers_both_resolutions(): void {
		$this->assertTrue(
			SWPS_Sitemap_Manager::is_legacy_redirect_type( 'legacy_redirect' ),
			'The dedicated legacy rewrite must redirect.'
		);
		$this->assertTrue(
			SWPS_Sitemap_Manager::is_legacy_redirect_type( 'swps' ),
			'The generic rule matching swps-sitemap.xml (type "swps") must redirect too.'
		);
	}

	/**
	 * Real sitemap types must NOT be treated as the legacy redirect.
	 */
	public function test_real_sitemap_types_are_not_redirected(): void {
		foreach ( array( 'index', 'author', 'post', 'page', 'category', 'post_tag', '' ) as $type ) {
			$this->assertFalse(
				SWPS_Sitemap_Manager::is_legacy_redirect_type( $type ),
				"Type '{$type}' must not be treated as a legacy redirect."
			);
		}
	}
}
