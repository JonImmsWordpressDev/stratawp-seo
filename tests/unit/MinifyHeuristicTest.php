<?php
/**
 * TDD tests for SWPS_Minify_Heuristic.
 *
 * Pure-PHP, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-minify-heuristic.php';

/**
 * @covers SWPS_Minify_Heuristic
 */
final class MinifyHeuristicTest extends TestCase {

	public function test_minified_single_line_sample(): void {
		$this->assertTrue( SWPS_Minify_Heuristic::looks_minified( str_repeat( 'var a=1;', 500 ) ) );
	}

	public function test_readable_source_sample(): void {
		$src = str_repeat( "function doThing(input) {\n    return input + 1; // add one\n}\n\n", 100 );
		$this->assertFalse( SWPS_Minify_Heuristic::looks_minified( $src ) );
	}

	public function test_empty_sample_counts_as_minified(): void {
		$this->assertTrue( SWPS_Minify_Heuristic::looks_minified( '' ) );
	}

	public function test_tiny_readable_sample_counts_as_minified(): void {
		// A sub-2 KB file saves next to nothing when minified (and WP core's
		// small *.min.js wrappers span a few short lines), so it never flags.
		$src = str_repeat( ".a {\n  color: red;\n}\n", 40 );
		$this->assertLessThan( 2048, strlen( $src ) );
		$this->assertTrue( SWPS_Minify_Heuristic::looks_minified( $src ) );
	}

	public function test_readable_sample_just_over_threshold_still_flags(): void {
		$src = str_repeat( ".a {\n  color: red;\n}\n", 120 );
		$this->assertGreaterThan( 2048, strlen( $src ) );
		$this->assertFalse( SWPS_Minify_Heuristic::looks_minified( $src ) );
	}
}
