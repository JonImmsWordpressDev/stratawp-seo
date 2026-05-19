<?php
/**
 * wp_head cleanup — toggle-based removal of default WordPress output.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Head_Cleanup {

	private const CLEANUP_MAP = array(
		'swps_cleanup_generator' => array( 'wp_generator' ),
		'swps_cleanup_rsd'       => array( 'rsd_link' ),
		'swps_cleanup_wlw'       => array( 'wlwmanifest_link' ),
		'swps_cleanup_shortlink' => array( 'wp_shortlink_wp_head' ),
		'swps_cleanup_rest_api'  => array( 'rest_output_link_wp_head', 'wp_oembed_add_discovery_links' ),
		'swps_cleanup_oembed'    => array( 'wp_oembed_add_host_js' ),
	);

	public function __construct() {
		if ( is_admin() ) {
			return;
		}

		foreach ( self::CLEANUP_MAP as $option => $hooks ) {
			if ( get_option( $option, 0 ) ) {
				foreach ( $hooks as $hook ) {
					remove_action( 'wp_head', $hook );
				}
			}
		}

		if ( get_option( 'swps_cleanup_emoji', 0 ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			add_filter( 'emoji_svg_url', '__return_false' );
		}
	}
}
