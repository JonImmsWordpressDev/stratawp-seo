<?php
/**
 * PHPStan bootstrap.
 *
 * Defines the plugin constants that classes reference at analysis time,
 * without executing stratawp-seo.php (which exits on the ABSPATH guard).
 *
 * @package StrataWP_SEO
 */

define( 'SWPS_VERSION', '0.0.0-phpstan' );
define( 'SWPS_PLUGIN_DIR', __DIR__ . '/../' );
define( 'SWPS_PLUGIN_URL', 'https://example.test/wp-content/plugins/stratawp-seo/' );
define( 'SWPS_PLUGIN_BASENAME', 'stratawp-seo/stratawp-seo.php' );
