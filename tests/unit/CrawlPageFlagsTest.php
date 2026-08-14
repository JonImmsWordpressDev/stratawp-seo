<?php
/**
 * Tests for SWPS_Crawl_Page_Flags bitmask class.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-page-flags.php';

/**
 * @covers SWPS_Crawl_Page_Flags
 */
final class CrawlPageFlagsTest extends TestCase {

	/**
	 * Test pack() sets bits for true facts and leaves others unset.
	 */
	public function test_pack_sets_bits_for_true_facts(): void {
		$flags = SWPS_Crawl_Page_Flags::pack(
			array(
				'has_viewport' => true,
				'has_doctype'  => true,
				'has_lang'     => false,
				'is_challenge' => true,
			)
		);
		$this->assertTrue( SWPS_Crawl_Page_Flags::has( $flags, SWPS_Crawl_Page_Flags::HAS_VIEWPORT ) );
		$this->assertTrue( SWPS_Crawl_Page_Flags::has( $flags, SWPS_Crawl_Page_Flags::HAS_DOCTYPE ) );
		$this->assertTrue( SWPS_Crawl_Page_Flags::has( $flags, SWPS_Crawl_Page_Flags::IS_CHALLENGE ) );
		$this->assertFalse( SWPS_Crawl_Page_Flags::has( $flags, SWPS_Crawl_Page_Flags::HAS_LANG ) );
		$this->assertFalse( SWPS_Crawl_Page_Flags::has( $flags, SWPS_Crawl_Page_Flags::HAS_CHARSET ) );
	}

	/**
	 * Test pack() with empty array returns 0.
	 */
	public function test_missing_keys_default_to_unset(): void {
		$this->assertSame( 0, SWPS_Crawl_Page_Flags::pack( array() ) );
	}
}
