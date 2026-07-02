<?php
/**
 * Unit tests for the IndexNow debounce queue.
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
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return $GLOBALS['swps_test_scheduled'][ $hook ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) {
		$GLOBALS['swps_test_scheduled'][ $hook ]     = $ts;
		$GLOBALS['swps_test_schedule_calls']         = ( $GLOBALS['swps_test_schedule_calls'] ?? 0 ) + 1;
		return true;
	}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowQueueTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options']         = array();
		$GLOBALS['swps_test_scheduled']       = array();
		$GLOBALS['swps_test_schedule_calls']  = 0;
	}

	public function test_enqueue_adds_url(): void {
		SWPS_IndexNow::enqueue_url( 'https://example.com/a' );
		$this->assertSame( array( 'https://example.com/a' ), get_option( SWPS_IndexNow::OPT_QUEUE ) );
	}

	public function test_enqueue_dedupes(): void {
		SWPS_IndexNow::enqueue_url( 'https://example.com/a' );
		SWPS_IndexNow::enqueue_url( 'https://example.com/a' );
		$this->assertCount( 1, get_option( SWPS_IndexNow::OPT_QUEUE ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_schedules_flush_once_per_burst(): void {
		SWPS_IndexNow::enqueue_url( 'https://example.com/a' );
		SWPS_IndexNow::enqueue_url( 'https://example.com/b' );
		$this->assertSame( 1, $GLOBALS['swps_test_schedule_calls'] );
		$this->assertArrayHasKey( SWPS_IndexNow::CRON_HOOK, $GLOBALS['swps_test_scheduled'] );
	}

	public function test_enqueue_ignores_empty(): void {
		SWPS_IndexNow::enqueue_url( '   ' );
		$this->assertSame( array(), get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}
}
