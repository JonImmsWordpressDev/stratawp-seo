<?php
/**
 * Unit tests for SWPS_Onboarding pure static helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-onboarding.php';

/**
 * Tests for SWPS_Onboarding::normalize_state() and SWPS_Onboarding::progress().
 */
class OnboardingTest extends TestCase {

	// ---- normalize_state() ----

	/**
	 * Null / non-array raw value normalises to all-false steps, dismissed false.
	 */
	public function test_normalize_null_raw(): void {
		$state = SWPS_Onboarding::normalize_state( null );

		$this->assertSame( array( 'migrate', 'api_key', 'site_info', 'audit', 'preview' ), array_keys( $state['steps'] ) );
		foreach ( $state['steps'] as $v ) {
			$this->assertFalse( $v );
		}
		$this->assertFalse( $state['dismissed'] );
	}

	/**
	 * Scalar raw value normalises cleanly (not an array).
	 */
	public function test_normalize_scalar_raw(): void {
		$state = SWPS_Onboarding::normalize_state( 'garbage' );

		foreach ( $state['steps'] as $v ) {
			$this->assertFalse( $v );
		}
		$this->assertFalse( $state['dismissed'] );
	}

	/**
	 * Unknown/extra keys inside the 'steps' sub-array are dropped silently.
	 */
	public function test_normalize_drops_unknown_step_keys(): void {
		$raw = array(
			'steps' => array(
				'migrate'  => true,
				'unknown'  => true,
				'junk_key' => 1,
			),
		);

		$state = SWPS_Onboarding::normalize_state( $raw );

		$this->assertArrayNotHasKey( 'unknown', $state['steps'] );
		$this->assertArrayNotHasKey( 'junk_key', $state['steps'] );
	}

	/**
	 * Known step keys are preserved; missing ones default to false.
	 */
	public function test_normalize_known_steps_present_missing_false(): void {
		$raw = array(
			'steps' => array(
				'migrate' => true,
				'api_key' => 1,
				// site_info, audit, preview absent.
			),
		);

		$state = SWPS_Onboarding::normalize_state( $raw );

		$this->assertTrue( $state['steps']['migrate'] );
		$this->assertTrue( $state['steps']['api_key'] );
		$this->assertFalse( $state['steps']['site_info'] );
		$this->assertFalse( $state['steps']['audit'] );
		$this->assertFalse( $state['steps']['preview'] );
	}

	/**
	 * Truthy values (1, "yes", non-empty array) are cast to true.
	 */
	public function test_normalize_truthy_values_cast_to_bool_true(): void {
		$raw = array(
			'steps' => array(
				'migrate'   => 1,
				'api_key'   => 'yes',
				'site_info' => array( 'x' ),
			),
		);

		$state = SWPS_Onboarding::normalize_state( $raw );

		$this->assertTrue( $state['steps']['migrate'] );
		$this->assertTrue( $state['steps']['api_key'] );
		$this->assertTrue( $state['steps']['site_info'] );
	}

	/**
	 * Falsy values (0, '', null, false) remain false.
	 */
	public function test_normalize_falsy_values_cast_to_bool_false(): void {
		$raw = array(
			'steps' => array(
				'migrate'   => 0,
				'api_key'   => '',
				'site_info' => null,
				'audit'     => false,
			),
		);

		$state = SWPS_Onboarding::normalize_state( $raw );

		$this->assertFalse( $state['steps']['migrate'] );
		$this->assertFalse( $state['steps']['api_key'] );
		$this->assertFalse( $state['steps']['site_info'] );
		$this->assertFalse( $state['steps']['audit'] );
	}

	/**
	 * The 'dismissed' flag is cast from truthy input.
	 */
	public function test_normalize_dismissed_truthy(): void {
		$state = SWPS_Onboarding::normalize_state( array( 'dismissed' => 1 ) );

		$this->assertTrue( $state['dismissed'] );
	}

	/**
	 * The 'dismissed' flag is false when absent.
	 */
	public function test_normalize_dismissed_absent(): void {
		$state = SWPS_Onboarding::normalize_state( array() );

		$this->assertFalse( $state['dismissed'] );
	}

	/**
	 * Returned array always has exactly the two keys 'steps' and 'dismissed'.
	 */
	public function test_normalize_returns_expected_shape(): void {
		$state = SWPS_Onboarding::normalize_state( array() );

		$this->assertArrayHasKey( 'steps', $state );
		$this->assertArrayHasKey( 'dismissed', $state );
		$this->assertCount( 2, $state );
	}

	// ---- progress() ----

	/**
	 * All steps false → done = 0, next = 'migrate', complete = false.
	 */
	public function test_progress_all_false(): void {
		$state = SWPS_Onboarding::normalize_state( array() );
		$p     = SWPS_Onboarding::progress( $state );

		$this->assertSame( 0, $p['done'] );
		$this->assertSame( 5, $p['total'] );
		$this->assertFalse( $p['complete'] );
		$this->assertSame( 'migrate', $p['next'] );
	}

	/**
	 * First step done → done = 1, next = 'api_key'.
	 */
	public function test_progress_first_step_done(): void {
		$state = SWPS_Onboarding::normalize_state( array( 'steps' => array( 'migrate' => true ) ) );
		$p     = SWPS_Onboarding::progress( $state );

		$this->assertSame( 1, $p['done'] );
		$this->assertSame( 'api_key', $p['next'] );
		$this->assertFalse( $p['complete'] );
	}

	/**
	 * Middle gap: migrate + audit done but api_key pending → next = 'api_key'.
	 */
	public function test_progress_next_is_first_incomplete_in_order(): void {
		$state = SWPS_Onboarding::normalize_state(
			array(
				'steps' => array(
					'migrate' => true,
					'audit'   => true,
					// api_key, site_info, preview pending.
				),
			)
		);
		$p = SWPS_Onboarding::progress( $state );

		$this->assertSame( 2, $p['done'] );
		$this->assertSame( 'api_key', $p['next'] );
	}

	/**
	 * All steps true → complete = true, done = 5, next = null.
	 */
	public function test_progress_all_done(): void {
		$steps = array_fill_keys( SWPS_Onboarding::STEPS, true );
		$state = SWPS_Onboarding::normalize_state( array( 'steps' => $steps ) );
		$p     = SWPS_Onboarding::progress( $state );

		$this->assertSame( 5, $p['done'] );
		$this->assertSame( 5, $p['total'] );
		$this->assertTrue( $p['complete'] );
		$this->assertNull( $p['next'] );
	}

	/**
	 * Total is always the count of SWPS_Onboarding::STEPS.
	 */
	public function test_progress_total_matches_steps_constant(): void {
		$state = SWPS_Onboarding::normalize_state( array() );
		$p     = SWPS_Onboarding::progress( $state );

		$this->assertSame( count( SWPS_Onboarding::STEPS ), $p['total'] );
	}
}
