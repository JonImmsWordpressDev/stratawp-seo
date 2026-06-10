<?php
/**
 * Tests for SWPS_Decay_Watchdog pure static helpers: assess() and staleness_flags().
 *
 * Pure-PHP, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-decay-watchdog.php';

/**
 * @covers SWPS_Decay_Watchdog
 */
class DecayWatchdogTest extends TestCase {

	// -------------------------------------------------------------------------
	// assess() — min-impressions floor
	// -------------------------------------------------------------------------

	public function test_not_decayed_when_prior_impressions_below_floor(): void {
		$recent = array( 'clicks' => 5,  'impressions' => 100,  'ctr' => 0.05, 'position' => 5.0 );
		$prior  = array( 'clicks' => 10, 'impressions' => 150,  'ctr' => 0.07, 'position' => 4.0 );
		$result = SWPS_Decay_Watchdog::assess( $recent, $prior, array( 'min_impressions' => 200 ) );
		$this->assertFalse( $result['decayed'] );
		$this->assertSame( '', $result['reason'] );
	}

	// -------------------------------------------------------------------------
	// assess() — zero prior clicks (divide-safety)
	// -------------------------------------------------------------------------

	public function test_not_decayed_when_prior_clicks_zero(): void {
		$recent = array( 'clicks' => 5,  'impressions' => 500, 'ctr' => 0.01, 'position' => 6.0 );
		$prior  = array( 'clicks' => 0,  'impressions' => 500, 'ctr' => 0.00, 'position' => 6.0 );
		$result = SWPS_Decay_Watchdog::assess( $recent, $prior );
		$this->assertFalse( $result['decayed'] );
		$this->assertSame( '', $result['reason'] );
	}

	// -------------------------------------------------------------------------
	// assess() — below threshold (no decay)
	// -------------------------------------------------------------------------

	public function test_not_decayed_when_click_decline_below_threshold(): void {
		// 15% decline, default threshold 20%.
		$recent = array( 'clicks' => 85,  'impressions' => 600, 'ctr' => 0.14, 'position' => 5.0 );
		$prior  = array( 'clicks' => 100, 'impressions' => 600, 'ctr' => 0.17, 'position' => 5.0 );
		$result = SWPS_Decay_Watchdog::assess( $recent, $prior );
		$this->assertFalse( $result['decayed'] );
	}

	// -------------------------------------------------------------------------
	// assess() — exact threshold boundary (should decay)
	// -------------------------------------------------------------------------

	public function test_decayed_at_exact_threshold(): void {
		// Exactly 20% decline: (100 - 80) / 100 = 0.20.
		$recent = array( 'clicks' => 80,  'impressions' => 600, 'ctr' => 0.13, 'position' => 5.0 );
		$prior  = array( 'clicks' => 100, 'impressions' => 600, 'ctr' => 0.17, 'position' => 5.0 );
		$result = SWPS_Decay_Watchdog::assess( $recent, $prior );
		$this->assertTrue( $result['decayed'] );
		$this->assertEqualsWithDelta( 0.20, $result['click_change'], 0.0001 );
	}

	// -------------------------------------------------------------------------
	// assess() — custom threshold
	// -------------------------------------------------------------------------

	public function test_custom_threshold_respected(): void {
		// 15% decline, threshold set to 10% → should decay.
		$recent = array( 'clicks' => 85,  'impressions' => 600, 'ctr' => 0.14, 'position' => 5.0 );
		$prior  = array( 'clicks' => 100, 'impressions' => 600, 'ctr' => 0.17, 'position' => 5.0 );
		$result = SWPS_Decay_Watchdog::assess( $recent, $prior, array( 'threshold' => 0.10 ) );
		$this->assertTrue( $result['decayed'] );
	}

	// -------------------------------------------------------------------------
	// assess() — reason: position_drop
	// -------------------------------------------------------------------------

	public function test_reason_position_drop(): void {
		// Clicks fell 30%, position worsened by 5 spots.
		$recent = array( 'clicks' => 70,  'impressions' => 600, 'ctr' => 0.12, 'position' => 9.0 );
		$prior  = array( 'clicks' => 100, 'impressions' => 600, 'ctr' => 0.17, 'position' => 4.0 );
		$result = SWPS_Decay_Watchdog::assess( $recent, $prior );
		$this->assertTrue( $result['decayed'] );
		$this->assertSame( 'position_drop', $result['reason'] );
	}

	// -------------------------------------------------------------------------
	// assess() — reason: demand_drop
	// -------------------------------------------------------------------------

	public function test_reason_demand_drop(): void {
		// Clicks fell 30%, position stable (<3 worse), impressions also fell 25%.
		$recent = array( 'clicks' => 70,  'impressions' => 450, 'ctr' => 0.16, 'position' => 5.0 );
		$prior  = array( 'clicks' => 100, 'impressions' => 600, 'ctr' => 0.17, 'position' => 5.0 );
		$result = SWPS_Decay_Watchdog::assess( $recent, $prior );
		$this->assertTrue( $result['decayed'] );
		$this->assertSame( 'demand_drop', $result['reason'] );
	}

	// -------------------------------------------------------------------------
	// assess() — reason: ctr_drop
	// -------------------------------------------------------------------------

	public function test_reason_ctr_drop(): void {
		// Clicks fell 30%, position stable, impressions stable, CTR fell 25%.
		$recent = array( 'clicks' => 70,  'impressions' => 600, 'ctr' => 0.117, 'position' => 5.2 );
		$prior  = array( 'clicks' => 100, 'impressions' => 600, 'ctr' => 0.167, 'position' => 5.0 );
		$result = SWPS_Decay_Watchdog::assess( $recent, $prior );
		$this->assertTrue( $result['decayed'] );
		$this->assertSame( 'ctr_drop', $result['reason'] );
	}

	// -------------------------------------------------------------------------
	// assess() — reason: unknown
	// -------------------------------------------------------------------------

	public function test_reason_unknown_when_no_cause_matches(): void {
		// Clicks fell 30%, but position, impressions, CTR all stable.
		$recent = array( 'clicks' => 70,  'impressions' => 600, 'ctr' => 0.167, 'position' => 5.1 );
		$prior  = array( 'clicks' => 100, 'impressions' => 600, 'ctr' => 0.167, 'position' => 5.0 );
		$result = SWPS_Decay_Watchdog::assess( $recent, $prior );
		$this->assertTrue( $result['decayed'] );
		$this->assertSame( 'unknown', $result['reason'] );
	}

	// -------------------------------------------------------------------------
	// assess() — click_change sign (positive = decline)
	// -------------------------------------------------------------------------

	public function test_click_change_is_positive_fraction_when_decayed(): void {
		$recent = array( 'clicks' => 60,  'impressions' => 600, 'ctr' => 0.10, 'position' => 10.0 );
		$prior  = array( 'clicks' => 100, 'impressions' => 600, 'ctr' => 0.17, 'position' => 5.0 );
		$result = SWPS_Decay_Watchdog::assess( $recent, $prior );
		$this->assertGreaterThan( 0.0, $result['click_change'] );
		$this->assertEqualsWithDelta( 0.40, $result['click_change'], 0.0001 );
	}

	// -------------------------------------------------------------------------
	// staleness_flags() — not_fresh when published > 24 months ago
	// -------------------------------------------------------------------------

	public function test_not_fresh_when_published_over_24_months(): void {
		$published = strtotime( '-25 months' );
		$modified  = strtotime( '-25 months' );
		$flags     = SWPS_Decay_Watchdog::staleness_flags( $published, $modified, '<p>Some content.</p>' );
		$this->assertContains( 'not_fresh', $flags );
	}

	// -------------------------------------------------------------------------
	// staleness_flags() — not_fresh requires BOTH modified > 12mo AND published > 24mo
	// -------------------------------------------------------------------------

	public function test_not_fresh_requires_both_modified_and_published_stale(): void {
		// Published 30 months ago (>24mo) AND modified 14 months ago (>12mo) → not_fresh.
		$published = strtotime( '-30 months' );
		$modified  = strtotime( '-14 months' );
		$flags     = SWPS_Decay_Watchdog::staleness_flags( $published, $modified, '<p>Some content.</p>' );
		$this->assertContains( 'not_fresh', $flags );
	}

	// -------------------------------------------------------------------------
	// staleness_flags() — not not_fresh when published < 24 months (even if old modified)
	// -------------------------------------------------------------------------

	public function test_not_not_fresh_when_published_under_24_months(): void {
		// Published 6 months ago → fresh by published rule, even though modified is old.
		$published = strtotime( '-6 months' );
		$modified  = strtotime( '-13 months' );
		$flags     = SWPS_Decay_Watchdog::staleness_flags( $published, $modified, '<p>Some content.</p>' );
		$this->assertNotContains( 'not_fresh', $flags );
	}

	// -------------------------------------------------------------------------
	// staleness_flags() — no not_fresh when recently modified
	// -------------------------------------------------------------------------

	public function test_fresh_when_recently_modified(): void {
		$published = strtotime( '-30 months' );
		$modified  = strtotime( '-3 months' );
		$flags     = SWPS_Decay_Watchdog::staleness_flags( $published, $modified, '<p>Some content.</p>' );
		$this->assertNotContains( 'not_fresh', $flags );
	}

	// -------------------------------------------------------------------------
	// staleness_flags() — no_current_year when year mention missing
	// -------------------------------------------------------------------------

	public function test_no_current_year_flag_when_current_year_absent(): void {
		$published = strtotime( '-6 months' );
		$modified  = strtotime( '-1 month' );
		$html      = '<p>Updated in 2022 and 2021.</p>';
		$flags     = SWPS_Decay_Watchdog::staleness_flags( $published, $modified, $html );
		$this->assertContains( 'no_current_year', $flags );
	}

	// -------------------------------------------------------------------------
	// staleness_flags() — no no_current_year flag when year present
	// -------------------------------------------------------------------------

	public function test_no_no_current_year_when_year_present(): void {
		$published = strtotime( '-6 months' );
		$modified  = strtotime( '-1 month' );
		$year      = (string) gmdate( 'Y' );
		$html      = "<p>Updated in {$year}.</p>";
		$flags     = SWPS_Decay_Watchdog::staleness_flags( $published, $modified, $html );
		$this->assertNotContains( 'no_current_year', $flags );
	}

	// -------------------------------------------------------------------------
	// staleness_flags() — empty flags when fresh and year present
	// -------------------------------------------------------------------------

	public function test_empty_flags_when_no_staleness(): void {
		$published = strtotime( '-3 months' );
		$modified  = strtotime( '-2 months' );
		$year      = (string) gmdate( 'Y' );
		$html      = "<p>Published this year {$year}.</p>";
		$flags     = SWPS_Decay_Watchdog::staleness_flags( $published, $modified, $html );
		$this->assertSame( array(), $flags );
	}
}
