<?php
/**
 * Tests for SWPS_Crawl_Fixer_Registry.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer-registry.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-meta-title.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-meta-description.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-image-alt.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-mixed-content.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-nofollow.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-sitemap-exclude.php';

final class CrawlFixerRegistryTest extends TestCase {

	public function test_fixable_ids_cover_the_v1_scope(): void {
		$expected = array(
			'missing_title',
			'title_too_long',
			'title_too_short',
			'duplicate_title',
			'missing_meta_description',
			'desc_too_long',
			'duplicate_meta_description',
			'image_missing_alt',
			'mixed_content',
			'nofollow_internal_link',
			'noindex_in_sitemap',
		);
		$actual = SWPS_Crawl_Fixer_Registry::fixable_ids();
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}

	public function test_for_check_returns_fixer_handling_that_id(): void {
		$fixer = SWPS_Crawl_Fixer_Registry::for_check( 'missing_title' );
		$this->assertInstanceOf( SWPS_Crawl_Fixer::class, $fixer );
		$this->assertContains( 'missing_title', $fixer->check_ids() );
	}

	public function test_for_check_unknown_id_returns_null(): void {
		$this->assertNull( SWPS_Crawl_Fixer_Registry::for_check( 'redirect_loop' ) );
	}

	public function test_kinds_are_valid(): void {
		foreach ( SWPS_Crawl_Fixer_Registry::fixable_ids() as $id ) {
			$this->assertContains(
				SWPS_Crawl_Fixer_Registry::kind_of( $id ),
				array( 'draft', 'mechanical' ),
				"kind_of({$id})"
			);
		}
	}

	public function test_meta_checks_are_draft_kind(): void {
		$this->assertSame( 'draft', SWPS_Crawl_Fixer_Registry::kind_of( 'missing_title' ) );
		$this->assertSame( 'draft', SWPS_Crawl_Fixer_Registry::kind_of( 'missing_meta_description' ) );
		$this->assertSame( 'mechanical', SWPS_Crawl_Fixer_Registry::kind_of( 'mixed_content' ) );
	}
}
