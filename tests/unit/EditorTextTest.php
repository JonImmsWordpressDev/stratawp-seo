<?php
/**
 * Tests for SWPS_Editor_Text::plain(), the PHP source of truth for the JS mirror.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/editor/class-editor-text.php';

final class EditorTextTest extends TestCase {

	public function test_block_closers_become_newlines(): void {
		$this->assertSame( "One.\nTwo.", SWPS_Editor_Text::plain( '<p>One.</p><p>Two.</p>' ) );
		$this->assertSame( "Title\nBody\nline", SWPS_Editor_Text::plain( '<h2>Title</h2><p>Body<br>line</p>' ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function reforming_provider(): array {
		return array(
			'comment split'    => array( '<scr<!-- c -->ipt>alert(1)</script>' ),
			'script in script' => array( '<scr<script></script>ipt>alert(1)</script>' ),
		);
	}

	/**
	 * @dataProvider reforming_provider
	 */
	public function test_reforming_markup_leaves_nothing_behind( string $html ): void {
		$out = SWPS_Editor_Text::plain( $html );
		$this->assertStringNotContainsString( '<', $out );
	}

	public function test_nested_tag_leaves_no_angle_bracket(): void {
		$this->assertStringNotContainsString( '<', SWPS_Editor_Text::plain( '<<b>script>alert(1)</<b>script>x' ) );
	}

	public function test_comment_split_tag_is_stripped(): void {
		$this->assertSame( 'alert(1)x', SWPS_Editor_Text::plain( '<scr<!-- c -->ipt>alert(1)</script>x' ) );
	}
}
