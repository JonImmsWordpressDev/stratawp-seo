<?php
/**
 * Redirect Admin page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Redirect_Admin {

    public function __construct() {
        // Admin page rendering is handled by SWPS_Settings menu registration.
    }

    /**
     * Render the redirects admin page.
     */
    public static function render(): void {
        include SWPS_PLUGIN_DIR . 'templates/redirects-page.php';
    }
}
