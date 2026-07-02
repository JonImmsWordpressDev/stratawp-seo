<?php
/**
 * Scaffold test: the IndexNow class and its public contract exist.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowScaffoldTest extends TestCase {

	public function test_class_and_constants_exist(): void {
		$this->assertTrue( class_exists( 'SWPS_IndexNow' ) );
		$this->assertSame( 'swps_indexnow_flush', SWPS_IndexNow::CRON_HOOK );
		$this->assertSame( 'https://api.indexnow.org/indexnow', SWPS_IndexNow::ENDPOINT );
		$this->assertSame( 50, SWPS_IndexNow::MAX_LOG );
		$this->assertSame( 10000, SWPS_IndexNow::MAX_URLS_PER_REQUEST );
		$this->assertSame( 60, SWPS_IndexNow::DEBOUNCE_SECONDS );
	}
}
