<?php
/**
 * Unit tests for the IndexNow activity-log ring buffer.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['swps_test_options'][ $option ] = $value;
		return true;
	}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowLogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options'] = array();
	}

	public function test_append_prepends_newest_first(): void {
		SWPS_IndexNow::append_log( array( 'result' => 'first' ) );
		SWPS_IndexNow::append_log( array( 'result' => 'second' ) );
		$log = SWPS_IndexNow::get_log();
		$this->assertSame( 'second', $log[0]['result'] );
		$this->assertSame( 'first', $log[1]['result'] );
	}

	public function test_log_caps_at_max(): void {
		for ( $i = 0; $i < SWPS_IndexNow::MAX_LOG + 20; $i++ ) {
			SWPS_IndexNow::append_log( array( 'result' => "e{$i}" ) );
		}
		$this->assertCount( SWPS_IndexNow::MAX_LOG, SWPS_IndexNow::get_log() );
	}

	public function test_get_log_returns_empty_array_when_unset(): void {
		$this->assertSame( array(), SWPS_IndexNow::get_log() );
	}
}
