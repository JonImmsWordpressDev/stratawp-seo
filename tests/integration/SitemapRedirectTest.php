<?php
/**
 * /sitemap.xml and /wp-sitemap.xml reach the plugin's index in one 301.
 *
 * @package StrataWP_SEO
 */

class SitemapRedirectTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		StrataWP_SEO::instance()->sitemap_manager->register_rewrite_rules();
		flush_rewrite_rules( false );
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
		parent::tear_down();
	}

	/**
	 * Stops the request at the first redirect, before exit.
	 *
	 * @param string $location Redirect target.
	 * @param int    $status   HTTP status.
	 * @throws RuntimeException Always.
	 */
	public function capture_redirect( $location, $status ) {
		throw new RuntimeException( $status . ' ' . $location );
	}

	/**
	 * Runs the full template_redirect chain (the plugin, then core's
	 * redirect_canonical) and returns the first redirect.
	 */
	private function first_redirect( string $path ): string {
		$this->go_to( home_url( $path ) );
		try {
			do_action( 'template_redirect' );
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_sitemap_xml_redirects_to_the_index_in_one_hop(): void {
		$this->assertSame( '301 ' . home_url( '/sitemap_index.xml' ), $this->first_redirect( '/sitemap.xml' ) );
	}

	public function test_wp_sitemap_xml_redirects_to_the_index_in_one_hop(): void {
		$this->assertSame( '301 ' . home_url( '/sitemap_index.xml' ), $this->first_redirect( '/wp-sitemap.xml' ) );
	}

	public function test_the_redirect_target_is_the_plugin_index(): void {
		$this->go_to( home_url( '/sitemap_index.xml' ) );
		$this->assertSame( 'index', get_query_var( 'swps_sitemap' ) );
		$this->assertSame( '', (string) get_query_var( 'sitemap' ) );
	}
}
