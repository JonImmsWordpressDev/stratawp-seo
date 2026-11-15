<?php
/**
 * uninstall.php removes every table and option the plugin creates.
 *
 * @package StrataWP_SEO
 */

class UninstallTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The test case rewrites CREATE/DROP to TEMPORARY tables; the plugin's
		// real tables were made at bootstrap, so let the real DROPs through.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'stratawp-seo/stratawp-seo.php' );
		}
	}

	private function swps_tables(): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'swps_' ) . '%' ) );
	}

	public function test_uninstall_keeps_data_without_the_opt_in(): void {
		delete_option( 'swps_remove_data_on_uninstall' );
		$before = $this->swps_tables();
		update_option( 'swps_test_marker', 1 );

		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( $before, $this->swps_tables(), 'tables must survive an uninstall without the opt-in' );
		$this->assertSame( '1', (string) get_option( 'swps_test_marker' ) );
	}

	public function test_uninstall_with_opt_in_leaves_nothing_behind(): void {
		global $wpdb;

		$this->assertGreaterThanOrEqual( 15, count( $this->swps_tables() ), 'activation should have created the plugin tables' );
		update_option( 'swps_test_marker', 1 );
		update_option( 'swps_remove_data_on_uninstall', 1 );

		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( array(), $this->swps_tables(), 'uninstall must drop every swps_ table' );

		$options = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'swps\\_%'" );
		$this->assertSame( 0, $options, 'uninstall must delete every swps_ option' );

		// Put the tables back for any test that runs after this one.
		swps_activate();
	}
}
