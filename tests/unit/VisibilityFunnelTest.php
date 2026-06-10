<?php
/**
 * Tests for SWPS_Visibility_Funnel::stage_rates() — pure helper, no WP.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

// Stub WordPress i18n functions — not loaded in unit context.
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

require_once __DIR__ . '/../../includes/class-visibility-funnel.php';

/**
 * @covers SWPS_Visibility_Funnel::stage_rates
 */
class VisibilityFunnelTest extends TestCase {

	// -------------------------------------------------------------------------
	// Normal cases
	// -------------------------------------------------------------------------

	public function test_all_stages_present_calculates_rates(): void {
		$result = SWPS_Visibility_Funnel::stage_rates( 100, 60, 20 );

		$this->assertSame( 100, $result[0]['count'] );
		$this->assertSame( 60,  $result[1]['count'] );
		$this->assertSame( 20,  $result[2]['count'] );

		// Stage 0 (published): always 100 % of itself.
		$this->assertSame( 100, $result[0]['pct'] );
		// Stage 1 (crawled): 60 % of published (60/100).
		$this->assertSame( 60,  $result[1]['pct'] );
		// Stage 2 (visited): 33 % of crawled (20/60, rounded).
		$this->assertSame( 33,  $result[2]['pct'] );
	}

	public function test_returns_three_stages(): void {
		$result = SWPS_Visibility_Funnel::stage_rates( 50, 25, 10 );
		$this->assertCount( 3, $result );
	}

	public function test_stage_keys_present(): void {
		$result = SWPS_Visibility_Funnel::stage_rates( 10, 5, 2 );
		foreach ( $result as $stage ) {
			$this->assertArrayHasKey( 'count', $stage );
			$this->assertArrayHasKey( 'pct',   $stage );
			$this->assertArrayHasKey( 'label', $stage );
		}
	}

	public function test_pct_clamped_at_100_when_crawled_exceeds_published(): void {
		// Crawled > published should not produce > 100 %.
		$result = SWPS_Visibility_Funnel::stage_rates( 10, 15, 5 );
		$this->assertLessThanOrEqual( 100, $result[1]['pct'] );
	}

	// -------------------------------------------------------------------------
	// Zero-denominator cases
	// -------------------------------------------------------------------------

	public function test_zero_published_returns_zero_pcts(): void {
		$result = SWPS_Visibility_Funnel::stage_rates( 0, 0, 0 );
		$this->assertSame( 0, $result[0]['pct'] );
		$this->assertSame( 0, $result[1]['pct'] );
		$this->assertSame( 0, $result[2]['pct'] );
	}

	public function test_zero_crawled_makes_visited_pct_zero(): void {
		// visited/crawled with crawled = 0 must not divide by zero.
		$result = SWPS_Visibility_Funnel::stage_rates( 50, 0, 0 );
		$this->assertSame( 0, $result[2]['pct'] );
	}
}
