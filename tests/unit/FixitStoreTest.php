<?php
/**
 * Tests for SWPS_Fixit_Store pure helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-fixit-store.php';

final class FixitStoreTest extends TestCase {

	public function test_merge_snapshot_keeps_earliest_value_per_field(): void {
		$existing = array(
			'fields'   => array( 'meta_title' => 'Original title' ),
			'taken_at' => 100,
		);
		$merged = SWPS_Fixit_Store::merge_snapshot(
			$existing,
			array(
				'meta_title'       => 'Already-changed title',
				'meta_description' => 'Original description',
			)
		);
		$this->assertSame( 'Original title', $merged['fields']['meta_title'] );
		$this->assertSame( 'Original description', $merged['fields']['meta_description'] );
		$this->assertSame( 100, $merged['taken_at'] );
	}

	public function test_merge_snapshot_from_empty(): void {
		$merged = SWPS_Fixit_Store::merge_snapshot( array(), array( 'meta_title' => 'T' ) );
		$this->assertSame( array( 'meta_title' => 'T' ), $merged['fields'] );
		$this->assertIsInt( $merged['taken_at'] );
	}

	public function test_normalize_draft_accepts_valid_shape(): void {
		$draft = array(
			'check_id'   => 'missing_title',
			'run_id'     => 1700000000,
			'current'    => '',
			'proposed'   => 'A better title',
			'drafted_at' => 1700000100,
			'usage'      => array(),
		);
		$this->assertSame( $draft, SWPS_Fixit_Store::normalize_draft( $draft ) );
	}

	public function test_normalize_draft_rejects_missing_proposed(): void {
		$this->assertNull(
			SWPS_Fixit_Store::normalize_draft(
				array( 'check_id' => 'missing_title', 'current' => '' )
			)
		);
	}

	public function test_normalize_draft_rejects_non_array(): void {
		$this->assertNull( SWPS_Fixit_Store::normalize_draft( 'garbage' ) );
		$this->assertNull( SWPS_Fixit_Store::normalize_draft( null ) );
	}

	public function test_normalize_draft_casts_and_defaults(): void {
		$out = SWPS_Fixit_Store::normalize_draft(
			array(
				'check_id' => 'desc_too_long',
				'proposed' => 'Short desc',
			)
		);
		$this->assertSame( 'desc_too_long', $out['check_id'] );
		$this->assertSame( 'Short desc', $out['proposed'] );
		$this->assertSame( '', $out['current'] );
		$this->assertSame( 0, $out['run_id'] );
		$this->assertSame( array(), $out['usage'] );
	}
}
