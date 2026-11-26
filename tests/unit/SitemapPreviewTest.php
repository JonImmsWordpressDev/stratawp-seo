<?php
/**
 * Pure parts of the Sitemaps admin preview (issue #145).
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-sitemap-admin.php';

class SitemapPreviewTest extends TestCase {

	public function test_sitemap_file_request(): void {
		$this->assertSame( array( 'type' => 'post', 'page' => 1 ), SWPS_Sitemap_Admin::sitemap_file_request( 'post-sitemap.xml' ) );
		$this->assertSame( array( 'type' => 'post', 'page' => 3 ), SWPS_Sitemap_Admin::sitemap_file_request( 'post-sitemap3.xml' ) );
		$this->assertSame( array( 'type' => 'google-news', 'page' => 1 ), SWPS_Sitemap_Admin::sitemap_file_request( 'google-news-sitemap.xml' ) );
		$this->assertNull( SWPS_Sitemap_Admin::sitemap_file_request( 'sitemap_index.xml' ) );
		$this->assertNull( SWPS_Sitemap_Admin::sitemap_file_request( '../post-sitemap.xml' ) );
	}

	public function test_parse_index_entries(): void {
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
			. "<sitemap>\n  <loc>https://example.com/post-sitemap.xml</loc>\n  <lastmod>2026-11-01T10:00:00+00:00</lastmod>\n</sitemap>\n"
			. "<sitemap>\n  <loc>https://example.com/post-sitemap2.xml</loc>\n  <lastmod>2026-11-01T10:00:00+00:00</lastmod>\n</sitemap>\n"
			. '</sitemapindex>';

		$entries = SWPS_Sitemap_Admin::parse_index_entries( $xml );

		$this->assertSame( array( 'post-sitemap.xml', 'post-sitemap2.xml' ), array_column( $entries, 'file' ) );
		$this->assertSame( '2026-11-01T10:00:00+00:00', $entries[1]['lastmod'] );
	}

	public function test_parse_url_entries_pages_a_large_sitemap(): void {
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset>\n";
		for ( $i = 1; $i <= 5000; $i++ ) {
			$xml .= "<url>\n  <loc>https://example.com/p/{$i}/?a=1&amp;b=2</loc>\n  <lastmod>2026-11-01T10:00:00+00:00</lastmod>\n";
			if ( 0 === $i % 2 ) {
				$xml .= "  <image:image>\n    <image:loc>https://example.com/{$i}.jpg</image:loc>\n  </image:image>\n";
				$xml .= "  <image:image>\n    <image:loc>https://example.com/{$i}b.jpg</image:loc>\n  </image:image>\n";
			}
			$xml .= "</url>\n";
		}
		$xml .= '</urlset>';

		$page = SWPS_Sitemap_Admin::parse_url_entries( $xml, 4900, 100 );

		$this->assertSame( 5000, $page['total'] );
		$this->assertCount( 100, $page['rows'] );
		$this->assertSame( 'https://example.com/p/4901/?a=1&b=2', $page['rows'][0]['loc'] );
		$this->assertSame( 0, $page['rows'][0]['images'] );
		$this->assertSame( 2, $page['rows'][1]['images'] );
		$this->assertSame( '2026-11-01T10:00:00+00:00', $page['rows'][99]['lastmod'] );
	}

	public function test_parse_url_entries_empty_urlset(): void {
		$this->assertSame( array( 'total' => 0, 'rows' => array() ), SWPS_Sitemap_Admin::parse_url_entries( '<urlset></urlset>', 0, 100 ) );
	}
}
