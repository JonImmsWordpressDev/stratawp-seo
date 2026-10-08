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
}
