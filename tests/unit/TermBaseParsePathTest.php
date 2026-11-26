<?php
/**
 * Unit tests for SWPS_Term_Base::parse_path().
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}

require_once __DIR__ . '/../../includes/class-term-base.php';

class TermBaseParsePathTest extends TestCase {

	public function test_plain_term_path(): void {
		$this->assertSame( array( 'path' => 'news', 'paged' => 0, 'feed' => '' ), SWPS_Term_Base::parse_path( '/news/' ) );
	}

	public function test_child_term_path(): void {
		$this->assertSame( array( 'path' => 'news/local', 'paged' => 0, 'feed' => '' ), SWPS_Term_Base::parse_path( 'news/local' ) );
	}

	public function test_pagination_suffix(): void {
		$this->assertSame( array( 'path' => 'news/local', 'paged' => 3, 'feed' => '' ), SWPS_Term_Base::parse_path( 'news/local/page/3' ) );
	}

	public function test_feed_suffixes(): void {
		$this->assertSame( array( 'path' => 'news', 'paged' => 0, 'feed' => 'feed' ), SWPS_Term_Base::parse_path( 'news/feed' ) );
		$this->assertSame( array( 'path' => 'news', 'paged' => 0, 'feed' => 'rss2' ), SWPS_Term_Base::parse_path( 'news/feed/rss2' ) );
		$this->assertSame( array( 'path' => 'news', 'paged' => 0, 'feed' => 'atom' ), SWPS_Term_Base::parse_path( 'news/atom' ) );
	}

	public function test_empty_or_suffix_only_paths_are_not_terms(): void {
		$this->assertNull( SWPS_Term_Base::parse_path( '' ) );
		$this->assertNull( SWPS_Term_Base::parse_path( '/' ) );
		$this->assertNull( SWPS_Term_Base::parse_path( 'news//local' ) );
	}
}
