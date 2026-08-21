<?php
/**
 * Tests for SWPS_Crawl_Target pure helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-crawl-target.php';

final class CrawlTargetTest extends TestCase {

	public function test_normalize_strips_pagination_path(): void {
		$this->assertSame(
			'https://example.com/blog/',
			SWPS_Crawl_Target::normalize( 'https://example.com/blog/page/3/' )
		);
	}

	public function test_normalize_strips_paged_query_and_fragment(): void {
		$this->assertSame(
			'https://example.com/blog/',
			SWPS_Crawl_Target::normalize( 'https://example.com/blog/?paged=2#top' )
		);
	}

	public function test_normalize_preserves_other_query_args(): void {
		$this->assertSame(
			'https://example.com/?p=42',
			SWPS_Crawl_Target::normalize( 'https://example.com/?p=42' )
		);
	}

	public function test_normalize_adds_trailing_slash_to_paths(): void {
		$this->assertSame(
			'https://example.com/about/',
			SWPS_Crawl_Target::normalize( 'https://example.com/about' )
		);
	}

	public function test_normalize_preserves_port(): void {
		$this->assertSame(
			'https://example.com:8080/blog/',
			SWPS_Crawl_Target::normalize( 'https://example.com:8080/blog/page/3/' )
		);
	}

	public function test_match_finds_post(): void {
		$maps = array(
			'posts' => array( 'https://example.com/hello-world/' => 7 ),
			'terms' => array(),
			'users' => array(),
		);
		$this->assertSame(
			array( 'object_type' => 'post', 'object_id' => 7 ),
			SWPS_Crawl_Target::match( 'https://example.com/hello-world/', $maps )
		);
	}

	public function test_match_finds_term(): void {
		$maps = array(
			'posts' => array(),
			'terms' => array( 'https://example.com/category/news/' => 12 ),
			'users' => array(),
		);
		$this->assertSame(
			array( 'object_type' => 'term', 'object_id' => 12 ),
			SWPS_Crawl_Target::match( 'https://example.com/category/news/', $maps )
		);
	}

	public function test_match_finds_user(): void {
		$maps = array(
			'posts' => array(),
			'terms' => array(),
			'users' => array( 'https://example.com/author/jon/' => 3 ),
		);
		$this->assertSame(
			array( 'object_type' => 'user', 'object_id' => 3 ),
			SWPS_Crawl_Target::match( 'https://example.com/author/jon/', $maps )
		);
	}

	public function test_match_unknown_url_is_none(): void {
		$maps = array( 'posts' => array(), 'terms' => array(), 'users' => array() );
		$this->assertSame(
			array( 'object_type' => 'none', 'object_id' => 0 ),
			SWPS_Crawl_Target::match( 'https://example.com/mystery/', $maps )
		);
	}

	public function test_match_normalizes_paginated_term_archive(): void {
		$maps = array(
			'posts' => array(),
			'terms' => array( 'https://example.com/category/news/' => 12 ),
			'users' => array(),
		);
		$this->assertSame(
			array( 'object_type' => 'term', 'object_id' => 12 ),
			SWPS_Crawl_Target::match(
				SWPS_Crawl_Target::normalize( 'https://example.com/category/news/page/2/' ),
				$maps
			)
		);
	}
}
