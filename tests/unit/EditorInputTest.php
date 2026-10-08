<?php
/**
 * Tests for the pure helpers in SWPS_Editor_Input.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/editor/class-editor-input.php';

final class EditorInputTest extends TestCase {

	public function test_sanitize_related_trims_dedupes_and_caps(): void {
		$out = SWPS_Editor_Input::sanitize_related( array( '  Cold Brew ', 'cold brew', '', '<b>iced</b> coffee', 'a', 'b', 'c', 'd' ) );

		$this->assertSame( array( 'Cold Brew', 'iced coffee', 'a', 'b' ), $out );
	}

	public function test_sanitize_related_accepts_garbage(): void {
		$this->assertSame( array(), SWPS_Editor_Input::sanitize_related( '' ) );
		$this->assertSame( array(), SWPS_Editor_Input::sanitize_related( null ) );
		$this->assertSame( array( 'one' ), SWPS_Editor_Input::sanitize_related( 'one' ) );
	}

	public function test_secondary_string_is_comma_separated(): void {
		$this->assertSame( 'a, b', SWPS_Editor_Input::secondary_string( array( 'a', 'b' ) ) );
		$this->assertSame( '', SWPS_Editor_Input::secondary_string( array() ) );
	}

	public function test_parse_legacy_secondary_splits_and_sanitizes(): void {
		$this->assertSame(
			array( 'cold brew', 'iced coffee', 'latte' ),
			SWPS_Editor_Input::parse_legacy_secondary( ' cold brew,iced coffee , , Cold Brew,<i>latte</i>' )
		);
		$this->assertSame( array(), SWPS_Editor_Input::parse_legacy_secondary( '' ) );
		$this->assertSame( array(), SWPS_Editor_Input::parse_legacy_secondary( ' , ,' ) );
	}

	public function test_parse_legacy_secondary_caps_at_max_related(): void {
		$this->assertSame( array( 'a', 'b', 'c', 'd' ), SWPS_Editor_Input::parse_legacy_secondary( 'a, b, c, d, e, f' ) );
	}

	public function test_should_mirror_skips_when_list_matches_legacy_string(): void {
		$this->assertFalse( SWPS_Editor_Input::should_mirror( array( 'a', 'b' ), 'a, b' ) );
		$this->assertFalse( SWPS_Editor_Input::should_mirror( array( ' a ', 'b', 'A' ), 'a,b' ) );
		$this->assertFalse( SWPS_Editor_Input::should_mirror( array(), '' ) );
	}

	public function test_should_mirror_preserves_long_legacy_string_until_list_changes(): void {
		// Five legacy keywords parse to the first four, which is what the
		// sidebar shows and sends back unchanged.
		$this->assertFalse( SWPS_Editor_Input::should_mirror( array( 'a', 'b', 'c', 'd' ), 'a, b, c, d, e' ) );
		$this->assertTrue( SWPS_Editor_Input::should_mirror( array( 'a', 'b', 'c' ), 'a, b, c, d, e' ) );
	}

	public function test_should_mirror_when_list_differs(): void {
		$this->assertTrue( SWPS_Editor_Input::should_mirror( array( 'a', 'c' ), 'a, b' ) );
		$this->assertTrue( SWPS_Editor_Input::should_mirror( array(), 'a, b' ) );
		$this->assertTrue( SWPS_Editor_Input::should_mirror( array( 'a' ), '' ) );
	}
}
