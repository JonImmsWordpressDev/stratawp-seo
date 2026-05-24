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

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        $string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
        $string = strip_tags( $string );
        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }
        return trim( $string );
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
