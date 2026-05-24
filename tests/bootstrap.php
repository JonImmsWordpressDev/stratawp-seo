<?php
/**
 * PHPUnit bootstrap for pure-PHP scorer unit tests.
 *
 * These tests do NOT load WordPress. Anything requiring WP functions
 * should be tested via manual WP-CLI smoke (see Task 21).
 *
 * @package StrataWP_SEO
 */

// Minimal WP function stubs the scorers need at parse-time.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp-fake/' );
}

require_once __DIR__ . '/../vendor/autoload.php';
