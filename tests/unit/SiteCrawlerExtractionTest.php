<?php
// tests/unit/SiteCrawlerExtractionTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-site-crawler.php';

final class SiteCrawlerExtractionTest extends TestCase {

	private const BASE = 'https://example.com/blog/tag/gutenberg/';

	private function full_page(): string {
		return '<!DOCTYPE html><html lang="en-US"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title> Gutenberg Archives - Example </title>'
			. '<meta name="description" content="Posts tagged gutenberg.">'
			. '<link rel="alternate" hreflang="en" href="https://example.com/en/">'
			. '<script type="application/ld+json">{"@context":"https://schema.org"}</script>'
			. '</head><body class="archive tag">'
			. '<h1>gutenberg</h1><p>one two three four five</p>'
			. '<a href="/about/" rel="nofollow">about</a>'
			. '<img src="/a.png"><img src="/b.png" alt="described">'
			. '<script src="/js/app.js"></script>'
			. '<link rel="stylesheet" href="/css/main.css">'
			. '</body></html>';
	}

	public function test_head_facts_extracted(): void {
		$r = SWPS_Site_Crawler::parse_html( $this->full_page(), self::BASE );
		$this->assertSame( 'Gutenberg Archives - Example', $r['title'] );
		$this->assertSame( 'Posts tagged gutenberg.', $r['meta_desc'] );
		$this->assertTrue( $r['has_viewport'] );
		$this->assertTrue( $r['has_doctype'] );
		$this->assertTrue( $r['has_charset'] );
		$this->assertTrue( $r['has_lang'] );
		$this->assertTrue( $r['has_schema'] );
		$this->assertTrue( $r['is_archive'] );
		$this->assertFalse( $r['is_paginated'] );
		$this->assertFalse( $r['is_challenge'] );
	}

	public function test_content_and_asset_facts(): void {
		$r = SWPS_Site_Crawler::parse_html( $this->full_page(), self::BASE );
		$this->assertSame( 6, $r['word_count'] ); // "gutenberg one two three four five"
		$this->assertGreaterThan( 0, $r['text_bytes'] );
		$this->assertSame( array( 'https://example.com/js/app.js' ), $r['script_srcs'] );
		$this->assertSame( array( 'https://example.com/css/main.css' ), $r['style_hrefs'] );
		$this->assertSame( array( 'https://example.com/a.png' ), $r['images_missing_alt'] );
		$this->assertSame( array( array( 'lang' => 'en', 'href' => 'https://example.com/en/' ) ), $r['hreflangs'] );
		$this->assertSame( array( 'https://example.com/about/' ), $r['nofollow_internal'] );
	}

	public function test_challenge_page_detected_from_fixture(): void {
		$html = (string) file_get_contents( __DIR__ . '/../fixtures/sgcaptcha-challenge.html' );
		$r    = SWPS_Site_Crawler::parse_html( $html, self::BASE );
		$this->assertTrue( $r['is_challenge'] );
	}

	public function test_bare_head_without_refresh_is_challenge_too(): void {
		$html = '<html><head></head><body></body></html>';
		$r    = SWPS_Site_Crawler::parse_html( $html, self::BASE );
		$this->assertTrue( $r['is_challenge'] ); // no title+viewport+doctype together
	}

	public function test_paginated_url_flag(): void {
		$r = SWPS_Site_Crawler::parse_html( $this->full_page(), 'https://example.com/blog/page/2/' );
		$this->assertTrue( $r['is_paginated'] );
	}
}
