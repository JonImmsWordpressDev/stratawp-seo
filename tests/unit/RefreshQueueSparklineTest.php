<?php
/**
 * Tests for SWPS_Refresh_Queue_Admin::sparkline() — pure inline-SVG builder.
 *
 * Pure-PHP, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-refresh-queue-admin.php';

/**
 * @covers SWPS_Refresh_Queue_Admin
 */
class RefreshQueueSparklineTest extends TestCase {

	public function test_empty_values_returns_empty_string(): void {
		$this->assertSame( '', SWPS_Refresh_Queue_Admin::sparkline( array() ) );
	}

	public function test_single_value_returns_empty_string(): void {
		$this->assertSame( '', SWPS_Refresh_Queue_Admin::sparkline( array( 5.0 ) ) );
	}

	public function test_two_values_returns_svg_polyline(): void {
		$svg = SWPS_Refresh_Queue_Admin::sparkline( array( 1.0, 2.0 ) );
		$this->assertStringStartsWith( '<svg', $svg );
		$this->assertStringContainsString( '<polyline', $svg );
		$this->assertStringContainsString( '</svg>', $svg );
	}

	public function test_default_dimensions_in_viewbox(): void {
		$svg = SWPS_Refresh_Queue_Admin::sparkline( array( 1.0, 2.0, 3.0 ) );
		$this->assertStringContainsString( 'viewBox="0 0 120 28"', $svg );
	}

	public function test_custom_dimensions_respected(): void {
		$svg = SWPS_Refresh_Queue_Admin::sparkline( array( 1.0, 2.0 ), 200, 50 );
		$this->assertStringContainsString( 'viewBox="0 0 200 50"', $svg );
	}

	public function test_flat_series_renders_horizontal_midline(): void {
		$svg = SWPS_Refresh_Queue_Admin::sparkline( array( 7.0, 7.0, 7.0 ), 120, 28 );
		// All y coordinates must be identical (a flat line at vertical center).
		preg_match( '/points="([^"]+)"/', $svg, $m );
		$this->assertNotEmpty( $m[1] );
		$ys = array_map(
			static fn( string $pair ): float => (float) explode( ',', trim( $pair ) )[1],
			explode( ' ', trim( $m[1] ) )
		);
		$this->assertCount( 1, array_unique( $ys, SORT_REGULAR ) );
		$this->assertEqualsWithDelta( 14.0, $ys[0], 0.01 );
	}

	public function test_scaling_maps_min_to_bottom_and_max_to_top(): void {
		$svg = SWPS_Refresh_Queue_Admin::sparkline( array( 0.0, 10.0 ), 120, 28 );
		preg_match( '/points="([^"]+)"/', $svg, $m );
		$pairs = explode( ' ', trim( $m[1] ) );
		$first = array_map( 'floatval', explode( ',', $pairs[0] ) );
		$last  = array_map( 'floatval', explode( ',', $pairs[ count( $pairs ) - 1 ] ) );
		// SVG y grows downward: min value → larger y, max value → smaller y.
		$this->assertGreaterThan( $last[1], $first[1] );
		// First x at left edge (with padding), last x at right edge.
		$this->assertLessThan( $last[0], $first[0] );
	}

	public function test_point_count_matches_value_count(): void {
		$values = array( 3.0, 1.0, 4.0, 1.0, 5.0, 9.0, 2.0 );
		$svg    = SWPS_Refresh_Queue_Admin::sparkline( $values );
		preg_match( '/points="([^"]+)"/', $svg, $m );
		$this->assertCount( count( $values ), explode( ' ', trim( $m[1] ) ) );
	}
}
