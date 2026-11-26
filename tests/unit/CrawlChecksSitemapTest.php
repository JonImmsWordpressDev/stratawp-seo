<?php
/**
 * Tests for the sitemap audit checks and SWPS_Sitemap_Audit helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-site-crawler.php';

final class CrawlChecksSitemapTest extends TestCase {

	private const NOW = 1795665000; // 2026-11-26.

	// -------------------------------------------------------------------------
	// parse_sitemap_xml
	// -------------------------------------------------------------------------

	public function test_parse_index_lists_child_sitemaps(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc>https://example.com/post-sitemap.xml</loc><lastmod>2026-11-20T10:00:00+00:00</lastmod></sitemap>
  <sitemap><loc> https://example.com/page-sitemap.xml </loc></sitemap>
</sitemapindex>';
		$parsed = SWPS_Sitemap_Audit::parse_sitemap_xml( $xml );
		$this->assertSame( array( 'https://example.com/post-sitemap.xml', 'https://example.com/page-sitemap.xml' ), $parsed['sitemaps'] );
		$this->assertSame( array(), $parsed['urls'] );
	}

	public function test_parse_urlset_reads_loc_and_lastmod_and_ignores_image_loc(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<url>
  <loc>https://example.com/hello/</loc>
  <lastmod>2026-11-20T10:00:00+00:00</lastmod>
  <image:image><image:loc>https://example.com/a.jpg</image:loc></image:image>
</url>
<url><loc>https://example.com/no-date/</loc></url>
<url><lastmod>2026-11-20</lastmod></url>
</urlset>';
		$parsed = SWPS_Sitemap_Audit::parse_sitemap_xml( $xml );
		$this->assertSame(
			array(
				array( 'loc' => 'https://example.com/hello/', 'lastmod' => '2026-11-20T10:00:00+00:00' ),
				array( 'loc' => 'https://example.com/no-date/', 'lastmod' => '' ),
			),
			$parsed['urls']
		);
	}

	public function test_parse_invalid_xml_returns_empty(): void {
		$empty = array( 'sitemaps' => array(), 'urls' => array() );
		$this->assertSame( $empty, SWPS_Sitemap_Audit::parse_sitemap_xml( '' ) );
		$this->assertSame( $empty, SWPS_Sitemap_Audit::parse_sitemap_xml( '<html><body>Not found</body></html>' ) );
		$this->assertSame( $empty, SWPS_Sitemap_Audit::parse_sitemap_xml( '<urlset><url><loc>x' ) );
	}

	// -------------------------------------------------------------------------
	// lastmod_problem
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider valid_lastmods
	 */
	public function test_valid_lastmod_is_fine( string $lastmod ): void {
		$this->assertSame( '', SWPS_Sitemap_Audit::lastmod_problem( $lastmod, self::NOW ) );
	}

	public static function valid_lastmods(): array {
		return array(
			'empty'          => array( '' ),
			'year'           => array( '2024' ),
			'year-month'     => array( '2024-05' ),
			'date'           => array( '2024-05-31' ),
			'utc z'          => array( '2026-11-20T10:00:00Z' ),
			'offset'         => array( '2026-11-20T10:00:00+00:00' ),
			'no seconds'     => array( '2026-11-20T10:00-05:00' ),
			'fraction'       => array( '2026-11-20T10:00:00.25+01:00' ),
			'just in future' => array( '2026-11-26T12:00:00+00:00' ),
			'year 2000'      => array( '2000-01-01' ),
		);
	}

	/**
	 * @dataProvider bad_lastmods
	 */
	public function test_bad_lastmod_is_flagged( string $lastmod, string $problem ): void {
		$this->assertSame( $problem, SWPS_Sitemap_Audit::lastmod_problem( $lastmod, self::NOW ) );
	}

	public static function bad_lastmods(): array {
		return array(
			'epoch'          => array( '1970-01-01T00:00:00+00:00', 'too_old' ),
			'1999'           => array( '1999-12-31', 'too_old' ),
			'far future'     => array( '2030-01-01T00:00:00+00:00', 'future' ),
			'two days ahead' => array( '2026-11-28T12:00:00+00:00', 'future' ),
			'words'          => array( 'yesterday', 'unparseable' ),
			'us date'        => array( '11/20/2026', 'unparseable' ),
			'no timezone'    => array( '2026-11-20T10:00:00', 'unparseable' ),
			'bad month'      => array( '2026-13-01', 'unparseable' ),
			'bad day'        => array( '2026-02-30', 'unparseable' ),
			'bad hour'       => array( '2026-11-20T25:00:00Z', 'unparseable' ),
		);
	}

	// -------------------------------------------------------------------------
	// classify with sitemap facts
	// -------------------------------------------------------------------------

	private function page( array $extra = array() ): array {
		return array_merge(
			array(
				'links'       => array(),
				'images'      => array(),
				'canonical'   => 'https://example.com/hello/',
				'h1_count'    => 1,
				'has_noindex' => false,
				'mixed'       => array(),
				'sitemap'     => array(
					'lastmod' => '2026-11-20T10:00:00+00:00',
					'post_id' => 7,
				),
			),
			$extra
		);
	}

	private function sitemap_issues( array $fetch, array $page ): array {
		$rows = SWPS_Site_Crawler::classify( $fetch, $page, 'example.com' );
		return array_values(
			array_filter( $rows, static fn( $r ) => in_array( $r['type'], SWPS_Sitemap_Audit::CHECK_IDS, true ) )
		);
	}

	public function test_healthy_self_canonical_sitemap_url_has_no_findings(): void {
		$fetch = array( 'url' => 'https://example.com/hello/', 'status' => 200, 'hops' => array() );
		$this->assertSame( array(), $this->sitemap_issues( $fetch, $this->page() ) );
	}

	public function test_canonical_differing_only_by_slash_scheme_host_case_or_www_is_fine(): void {
		$fetch = array( 'url' => 'https://example.com/hello/', 'status' => 200, 'hops' => array() );
		foreach ( array( 'https://example.com/hello', 'http://example.com/hello/', 'https://EXAMPLE.com/hello/', 'https://www.example.com/hello/' ) as $canonical ) {
			$this->assertSame( array(), $this->sitemap_issues( $fetch, $this->page( array( 'canonical' => $canonical ) ) ), $canonical );
		}
	}

	public function test_url_not_from_sitemap_is_never_flagged(): void {
		$fetch = array( 'url' => 'https://example.com/old/', 'status' => 404, 'hops' => array() );
		$page  = $this->page( array( 'canonical' => 'https://example.com/other/' ) );
		unset( $page['sitemap'] );
		$this->assertSame( array(), $this->sitemap_issues( $fetch, $page ) );
	}

	public function test_sitemap_url_that_redirects_is_flagged_with_post_id(): void {
		$fetch  = array(
			'url'       => 'https://example.com/old/',
			'status'    => 200,
			'hops'      => array( array( 'url' => 'https://example.com/old/', 'status' => 301 ) ),
			'final_url' => 'https://example.com/new/',
		);
		$issues = $this->sitemap_issues( $fetch, $this->page( array( 'content_type' => 'application/x-redirect', 'canonical' => null ) ) );
		$this->assertCount( 1, $issues );
		$this->assertSame( 'sitemap_url_redirects', $issues[0]['type'] );
		$this->assertSame( 'warning', $issues[0]['severity'] );
		$this->assertSame( 'https://example.com/old/', $issues[0]['url'] );
		$this->assertSame( array( 'status' => 301, 'redirect_to' => 'https://example.com/new/', 'post_id' => 7 ), $issues[0]['detail'] );
	}

	public function test_sitemap_url_in_redirect_loop_is_a_redirect_not_an_error(): void {
		$fetch  = array(
			'url'    => 'https://example.com/a/',
			'status' => 0,
			'loop'   => true,
			'hops'   => array( array( 'url' => 'https://example.com/a/', 'status' => 302 ) ),
		);
		$issues = $this->sitemap_issues( $fetch, $this->page() );
		$this->assertSame( array( 'sitemap_url_redirects' ), array_column( $issues, 'type' ) );
	}

	/**
	 * @dataProvider error_statuses
	 */
	public function test_sitemap_url_that_errors_is_recorded( int $status ): void {
		$fetch = array( 'url' => 'https://example.com/gone/', 'status' => $status, 'hops' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $this->page( array( 'canonical' => null ) ), 'example.com' );
		$types = array_column( $rows, 'type' );
		$this->assertContains( 'sitemap_url_error', $types );
		$this->assertContains( 'broken_link', $types, 'the broken-link check still runs' );

		$issue = $rows[ array_search( 'sitemap_url_error', $types, true ) ];
		$this->assertSame( 'error', $issue['severity'] );
		$this->assertSame( array( 'status' => $status, 'post_id' => 7 ), $issue['detail'] );
	}

	public static function error_statuses(): array {
		return array(
			'404'         => array( 404 ),
			'410'         => array( 410 ),
			'500'         => array( 500 ),
			'no response' => array( 0 ),
		);
	}

	public function test_sitemap_url_with_foreign_canonical_is_flagged(): void {
		$fetch  = array( 'url' => 'https://example.com/hello/', 'status' => 200, 'hops' => array() );
		$issues = $this->sitemap_issues( $fetch, $this->page( array( 'canonical' => 'https://example.com/hello-world/' ) ) );
		$this->assertCount( 1, $issues );
		$this->assertSame( 'sitemap_url_noncanonical', $issues[0]['type'] );
		$this->assertSame( array( 'canonical' => 'https://example.com/hello-world/', 'post_id' => 7 ), $issues[0]['detail'] );
	}

	public function test_page_without_canonical_is_not_noncanonical(): void {
		$fetch = array( 'url' => 'https://example.com/hello/', 'status' => 200, 'hops' => array() );
		$this->assertSame( array(), $this->sitemap_issues( $fetch, $this->page( array( 'canonical' => null ) ) ) );
	}

	public function test_bad_lastmod_is_flagged_even_when_the_url_errors(): void {
		$fetch  = array( 'url' => 'https://example.com/gone/', 'status' => 404, 'hops' => array() );
		$page   = $this->page( array( 'sitemap' => array( 'lastmod' => '1970-01-01T00:00:00+00:00', 'post_id' => 0 ) ) );
		$issues = $this->sitemap_issues( $fetch, $page );
		$types  = array_column( $issues, 'type' );
		sort( $types );
		$this->assertSame( array( 'sitemap_bad_lastmod', 'sitemap_url_error' ), $types );

		$lastmod = $issues[ array_search( 'sitemap_bad_lastmod', array_column( $issues, 'type' ), true ) ];
		$this->assertSame( 'notice', $lastmod['severity'] );
		$this->assertSame( array( 'lastmod' => '1970-01-01T00:00:00+00:00', 'problem' => 'too_old' ), $lastmod['detail'], 'no post_id when unknown' );
	}

	public function test_excluded_sitemap_check_does_not_run(): void {
		$fetch = array( 'url' => 'https://example.com/gone/', 'status' => 404, 'hops' => array() );
		$rows  = SWPS_Site_Crawler::classify( $fetch, $this->page(), 'example.com', array( 'sitemap_url_error' ) );
		$this->assertNotContains( 'sitemap_url_error', array_column( $rows, 'type' ) );
	}

	public function test_sitemap_checks_are_registered_with_titles(): void {
		$ids = array();
		foreach ( SWPS_Crawl_Check_Registry::all( array() ) as $check ) {
			$ids[ $check->id() ] = $check;
		}
		foreach ( SWPS_Sitemap_Audit::CHECK_IDS as $id ) {
			$this->assertArrayHasKey( $id, $ids );
			$this->assertNotSame( '', $ids[ $id ]->title() );
			$this->assertNotSame( '', $ids[ $id ]->how_to_fix() );
		}
	}
}
