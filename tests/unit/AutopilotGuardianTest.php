<?php
/**
 * Unit tests for SWPS_Autopilot_Guardian pure logic.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-autopilot-guardian.php';

class AutopilotGuardianTest extends TestCase {

    // ---- budget_state() ----

    public function test_budget_state_ok_when_no_budget(): void {
        $this->assertSame( 'ok', SWPS_Autopilot_Guardian::budget_state( 50.0, 0.0 ) );
    }

    public function test_budget_state_ok_under_warning_threshold(): void {
        $this->assertSame( 'ok', SWPS_Autopilot_Guardian::budget_state( 7.99, 10.0 ) );
    }

    public function test_budget_state_warning_at_80_percent(): void {
        $this->assertSame( 'warning', SWPS_Autopilot_Guardian::budget_state( 8.0, 10.0 ) );
    }

    public function test_budget_state_exceeded_at_budget(): void {
        $this->assertSame( 'exceeded', SWPS_Autopilot_Guardian::budget_state( 10.0, 10.0 ) );
    }

    public function test_budget_state_exceeded_over_budget(): void {
        $this->assertSame( 'exceeded', SWPS_Autopilot_Guardian::budget_state( 25.5, 10.0 ) );
    }

    // ---- is_transient_error() ----

    public function test_network_failure_is_transient(): void {
        $e = new WP_Error( 'swps_api_request_failed', 'cURL error 28' );
        $this->assertTrue( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_api_error_429_is_transient(): void {
        $e = new WP_Error( 'swps_api_error', 'Rate limited', array( 'status' => 429 ) );
        $this->assertTrue( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_api_error_500_is_transient(): void {
        $e = new WP_Error( 'swps_api_error', 'Server error', array( 'status' => 500 ) );
        $this->assertTrue( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_api_error_401_is_permanent(): void {
        $e = new WP_Error( 'swps_api_error', 'Unauthorized', array( 'status' => 401 ) );
        $this->assertFalse( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_api_error_without_status_is_transient(): void {
        // Back-compat: an error produced before providers attached status data.
        $e = new WP_Error( 'swps_api_error', 'Claude API error (503): overloaded' );
        $this->assertTrue( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_missing_key_is_permanent(): void {
        $e = new WP_Error( 'swps_no_api_key', 'Please enter your API key.' );
        $this->assertFalse( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_invalid_key_is_permanent(): void {
        $e = new WP_Error( 'swps_invalid_key', 'Invalid key.' );
        $this->assertFalse( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_duplicate_is_permanent(): void {
        $e = new WP_Error( 'swps_duplicate', 'Duplicate detected.' );
        $this->assertFalse( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_budget_exceeded_is_permanent(): void {
        $e = new WP_Error( 'swps_budget_exceeded', 'Budget exhausted.' );
        $this->assertFalse( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_empty_response_is_transient(): void {
        $e = new WP_Error( 'swps_empty_response', 'Empty response.' );
        $this->assertTrue( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    // ---- retry_delay() ----

    public function test_retry_delay_first_attempt_15_minutes(): void {
        $this->assertSame( 900, SWPS_Autopilot_Guardian::retry_delay( 1 ) );
    }

    public function test_retry_delay_second_attempt_30_minutes(): void {
        $this->assertSame( 1800, SWPS_Autopilot_Guardian::retry_delay( 2 ) );
    }

    public function test_retry_delay_clamps_minimum_attempt(): void {
        $this->assertSame( 900, SWPS_Autopilot_Guardian::retry_delay( 0 ) );
    }
}
