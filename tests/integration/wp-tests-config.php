<?php
/**
 * WordPress test-suite config for the integration tests.
 *
 * Database comes from the environment so CI and local runs differ only in
 * env vars. The database is wiped on every run — never point this at a
 * database you care about.
 *
 *   WP_TESTS_DB_NAME  (default swps_tests)
 *   WP_TESTS_DB_USER  (default root)
 *   WP_TESTS_DB_PASS  (default '')
 *   WP_TESTS_DB_HOST  (default 127.0.0.1)
 *
 * @package StrataWP_SEO
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/vendor/roots/wordpress-no-content/' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'swps_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASS' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'AUTH_KEY', 'swps-tests' );
define( 'SECURE_AUTH_KEY', 'swps-tests' );
define( 'LOGGED_IN_KEY', 'swps-tests' );
define( 'NONCE_KEY', 'swps-tests' );
define( 'AUTH_SALT', 'swps-tests' );
define( 'SECURE_AUTH_SALT', 'swps-tests' );
define( 'LOGGED_IN_SALT', 'swps-tests' );
define( 'NONCE_SALT', 'swps-tests' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'StrataWP SEO Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
