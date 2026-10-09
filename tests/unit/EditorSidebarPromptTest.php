<?php
/**
 * Tests for SWPS_Editor_Sidebar::prompt_script(), the pure builder of the
 * in-editor "Turn it on" notice script.
 *
 * Pure-PHP, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/editor/class-editor-sidebar.php';

/**
 * @covers SWPS_Editor_Sidebar::prompt_script
 */
class EditorSidebarPromptTest extends TestCase {

	private const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
	private const URL   = 'https://example.test/wp-admin/admin-post.php?action=x&enable=1&_wpnonce=abc';

	public function test_contains_notice_call_guard_and_id(): void {
		$js = SWPS_Editor_Sidebar::prompt_script( 'Hello', 'Turn it on', self::URL );

		$this->assertStringContainsString( 'createInfoNotice(', $js );
		$this->assertStringContainsString( 'swpsTurnOnShown', $js );
		$this->assertStringContainsString( 'swps-turn-on-sidebar', $js );
		$this->assertStringContainsString( json_encode( 'Turn it on', self::FLAGS ), $js );
	}

	public function test_message_is_never_emitted_raw(): void {
		$message = 'A </script><script>alert(1)</script> & "quoted" \'single\'';
		$js      = SWPS_Editor_Sidebar::prompt_script( $message, 'Go', self::URL );

		$this->assertStringNotContainsString( '</script>', $js );
		$this->assertStringNotContainsString( '<', $js );
		$this->assertStringContainsString( json_encode( $message, self::FLAGS ), $js );
	}

	public function test_url_is_escaped_and_round_trips(): void {
		$js = SWPS_Editor_Sidebar::prompt_script( 'm', 'l', self::URL );

		$this->assertStringContainsString( '\\u0026enable=1', $js );
		$this->assertStringNotContainsString( 'action=x&enable', $js );
		$this->assertSame( 1, preg_match( '/url:\s*("(?:[^"\\\\]|\\\\.)*")/', $js, $m ) );
		$this->assertSame( self::URL, json_decode( $m[1] ) );
	}

	public function test_empty_strings_do_not_throw(): void {
		$js = SWPS_Editor_Sidebar::prompt_script( '', '', '' );

		$this->assertStringContainsString( 'createInfoNotice(', $js );
	}
}
