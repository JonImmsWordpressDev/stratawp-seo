<?php
/**
 * The version-change rewrite flush only runs on a normal admin page load or
 * in WP-CLI (issue #146). Flushing on Heartbeat, REST, cron or front-end
 * requests can drop rules other plugins register only in wp-admin.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RewriteFlushGuardTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/../fixtures/rewrite/stubs.php';
		$GLOBALS['swps_test_options']    = array( 'swps_rewrite_version' => '1.0.0' );
		$GLOBALS['swps_test_flushes']    = 0;
		$GLOBALS['swps_test_ctx']        = array();
		$GLOBALS['swps_test_transients'] = array( SWPS_Rewrite_Health::CACHE_KEY => array( 'checked' => array(), 'broken' => array( 'x' ) ) );
	}

	private function run_flush( array $ctx ): void {
		$GLOBALS['swps_test_ctx'] = $ctx;
		$manager = ( new ReflectionClass( SWPS_Sitemap_Manager::class ) )->newInstanceWithoutConstructor();
		$manager->maybe_flush_rewrites();
	}

	public function contexts_that_skip(): array {
		return array(
			'heartbeat (admin-ajax)' => array( array( 'admin' => true, 'ajax' => true ) ),
			'rest'                   => array( array( 'rest' => true ) ),
			'rest flagged as admin'  => array( array( 'admin' => true, 'rest' => true ) ),
			'cron'                   => array( array( 'cron' => true ) ),
			'cron in admin'          => array( array( 'admin' => true, 'cron' => true ) ),
			'front-end'              => array( array() ),
		);
	}

	/**
	 * @dataProvider contexts_that_skip
	 */
	public function test_unsafe_requests_leave_the_flush_pending( array $ctx ): void {
		$this->run_flush( $ctx );

		$this->assertSame( 0, $GLOBALS['swps_test_flushes'] );
		$this->assertSame( '1.0.0', $GLOBALS['swps_test_options']['swps_rewrite_version'], 'Version must stay old so a later admin load flushes.' );
	}

	public function test_admin_page_load_flushes_once(): void {
		$this->run_flush( array( 'admin' => true ) );
		$this->assertSame( 1, $GLOBALS['swps_test_flushes'] );
		$this->assertSame( SWPS_VERSION, $GLOBALS['swps_test_options']['swps_rewrite_version'] );
		$this->assertArrayNotHasKey( SWPS_Rewrite_Health::CACHE_KEY, $GLOBALS['swps_test_transients'], 'Stale health result is cleared.' );

		$this->run_flush( array( 'admin' => true ) );
		$this->assertSame( 1, $GLOBALS['swps_test_flushes'], 'Second admin load is a no-op.' );
	}

	public function test_pending_flush_runs_on_the_next_admin_page_load(): void {
		$this->run_flush( array( 'admin' => true, 'ajax' => true ) );
		$this->run_flush( array() );
		$this->assertSame( 0, $GLOBALS['swps_test_flushes'] );

		$this->run_flush( array( 'admin' => true ) );
		$this->assertSame( 1, $GLOBALS['swps_test_flushes'] );
	}

	public function test_cli_flushes(): void {
		$this->assertTrue( SWPS_Sitemap_Manager::is_safe_flush_context( array( 'cli' => true ) ) );
		$this->assertTrue( SWPS_Sitemap_Manager::is_safe_flush_context( array( 'cli' => true, 'cron' => true ) ) );
	}

	public function test_current_request_context_reads_wp_state(): void {
		$GLOBALS['swps_test_ctx'] = array( 'admin' => true, 'ajax' => true );
		$ctx                      = SWPS_Sitemap_Manager::current_request_context();
		$this->assertTrue( $ctx['admin'] );
		$this->assertTrue( $ctx['ajax'] );
		$this->assertFalse( $ctx['cron'] );
		$this->assertFalse( $ctx['rest'] );
		$this->assertFalse( $ctx['cli'] );
	}
}
