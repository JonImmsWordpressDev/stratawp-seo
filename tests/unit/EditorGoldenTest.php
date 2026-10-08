<?php
/**
 * Pins tests/fixtures/editor/golden.json to the PHP engine so the JS mirror is
 * always compared against current PHP behaviour.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

final class EditorGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../fixtures/editor/golden.json';

	public function test_golden_file_matches_the_php_engine(): void {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/../../bin/gen-editor-golden.php' ) . ' --stdout';
		$out = shell_exec( $cmd );

		$this->assertIsString( $out );
		$this->assertFileExists( self::GOLDEN );
		$this->assertSame( file_get_contents( self::GOLDEN ), $out, 'golden.json is stale. Run: php bin/gen-editor-golden.php' );
	}

	public function test_golden_covers_the_review_focus_cases(): void {
		$golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
		$names  = array_column( $golden['cases'], 'name' );

		foreach ( array( 'good-post', 'empty-keyword', 'japanese', 'blocks-and-shortcodes', 'accents', 'stuffed', 'related-keywords', 'long-passive-no-headings' ) as $expected ) {
			$this->assertContains( $expected, $names );
		}
	}
}
