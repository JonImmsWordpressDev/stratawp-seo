<?php
/**
 * Bootstrap for the integration suite: real WordPress, real database.
 *
 * The unit suite (tests/bootstrap.php) stubs a handful of WP functions and
 * covers pure logic. This suite boots WordPress itself, loads the plugin,
 * runs its activation routine (tables, crons), and exercises the parts the
 * unit suite can't reach: locks, AJAX/REST handlers, permissions, uninstall.
 *
 * @package StrataWP_SEO
 */

$swps_root = dirname( __DIR__, 2 );

require_once $swps_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv( 'WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

$swps_tests_dir = $swps_root . '/vendor/wp-phpunit/wp-phpunit';

require_once $swps_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $swps_root ): void {
		require $swps_root . '/stratawp-seo.php';
	}
);

// Create the plugin's tables once, the way activation does on a real site.
tests_add_filter(
	'plugins_loaded',
	static function (): void {
		swps_activate();
	},
	20
);

require $swps_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-swps-stub-ai-provider.php';
