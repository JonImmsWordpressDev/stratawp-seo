<?php
/**
 * Tests for the pure helpers in SWPS_Editor_Snapshots.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/editor/class-editor-snapshots.php';

final class EditorSnapshotsTest extends TestCase {

	public function test_add_pending_is_unique_and_ordered(): void {
		$list = SWPS_Editor_Snapshots::add_pending( array( 'kw_in_slug' ), 'kw_in_title' );
		$list = SWPS_Editor_Snapshots::add_pending( $list, 'kw_in_slug' );

		$this->assertSame( array( 'kw_in_slug', 'kw_in_title' ), $list );
	}

	public function test_add_pending_caps_at_twenty_newest(): void {
		$list = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$list = SWPS_Editor_Snapshots::add_pending( $list, 'fix_' . $i );
		}

		$this->assertCount( 20, $list );
		$this->assertSame( 'fix_6', $list[0] );
		$this->assertSame( 'fix_25', $list[19] );
	}

	public function test_add_pending_accepts_garbage(): void {
		$this->assertSame( array( 'a' ), SWPS_Editor_Snapshots::add_pending( null, 'a' ) );
		$this->assertSame( array( 'a' ), SWPS_Editor_Snapshots::add_pending( 'junk', 'a' ) );
	}

	public function test_build_dedupes_fixes_and_keeps_nulls(): void {
		$snap = SWPS_Editor_Snapshots::build( 82, array( 'a', 'b', 'a' ), null, null, 1700000000 );

		$this->assertSame(
			array(
				'time'  => 1700000000,
				'score' => 82,
				'fixes' => array( 'a', 'b' ),
				'aeo'   => null,
				'gsc'   => null,
			),
			$snap
		);
	}

	public function test_append_keeps_only_the_newest_snapshots(): void {
		$list = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$list = SWPS_Editor_Snapshots::append( $list, array( 'time' => $i ), 3 );
		}

		$this->assertSame( array( 3, 4, 5 ), array_column( $list, 'time' ) );
		$this->assertSame( array( array( 'time' => 1 ) ), SWPS_Editor_Snapshots::append( 'junk', array( 'time' => 1 ) ) );
	}

	public function test_pick_keyword_row_matches_case_insensitively(): void {
		$rows = array(
			array( 'keys' => array( 'iced coffee' ), 'clicks' => 3, 'impressions' => 90, 'position' => 12.34 ),
			array( 'keys' => array( 'Cold Brew Coffee' ), 'clicks' => 11, 'impressions' => 400, 'position' => 8.76 ),
		);

		$this->assertSame(
			array( 'clicks' => 11, 'impressions' => 400, 'position' => 8.8 ),
			SWPS_Editor_Snapshots::pick_keyword_row( $rows, 'cold brew coffee' )
		);
		$this->assertNull( SWPS_Editor_Snapshots::pick_keyword_row( $rows, 'nitro' ) );
		$this->assertNull( SWPS_Editor_Snapshots::pick_keyword_row( array(), 'nitro' ) );
		$this->assertNull( SWPS_Editor_Snapshots::pick_keyword_row( array( array( 'keys' => array( '' ), 'clicks' => 1 ) ), '' ) );
		$this->assertNull( SWPS_Editor_Snapshots::pick_keyword_row( array( array( 'keys' => array( '' ), 'clicks' => 1 ) ), '  ' ) );
	}
}
