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
}
