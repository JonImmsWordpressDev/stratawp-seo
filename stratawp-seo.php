<?php
/**
 * Plugin Name: StrataWP SEO
 * Plugin URI: https://stratawpseo.com
 * Description: AI-powered SEO content generator that knows your WordPress site. Generate optimized blog posts with internal linking, on autopilot.
 * Version: 1.0.0
 * Author: Jon Imms
 * Author URI: https://jonimms.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stratawp-seo
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SWPS_VERSION', '1.0.0' );
define( 'SWPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SWPS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload plugin classes.
 */
require_once SWPS_PLUGIN_DIR . 'includes/class-api.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-settings.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-analyzer.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-generator.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-cron.php';

/**
 * Main plugin class.
 */
final class StrataWP_SEO {

    private static ?StrataWP_SEO $instance = null;

    public SWPS_Settings $settings;
    public SWPS_Generator $generator;
    public SWPS_Cron $cron;
    public SWPS_Analyzer $analyzer;
    public SWPS_API $api;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api       = new SWPS_API();
        $this->settings  = new SWPS_Settings();
        $this->analyzer  = new SWPS_Analyzer();
        $this->generator = new SWPS_Generator( $this->api, $this->analyzer );
        $this->cron      = new SWPS_Cron( $this->generator );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_swps_generate_post', [ $this, 'ajax_generate_post' ] );
        add_action( 'wp_ajax_swps_analyze_site', [ $this, 'ajax_analyze_site' ] );

        // Add settings link to plugins page.
        add_filter( 'plugin_action_links_' . SWPS_PLUGIN_BASENAME, [ $this, 'add_settings_link' ] );
    }

    /**
     * Enqueue admin CSS and JS on our settings page only.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'stratawp-seo' ) && ! str_contains( $hook, 'swps-generate' ) ) {
            return;
        }

        wp_enqueue_style(
            'swps-admin',
            SWPS_PLUGIN_URL . 'admin/css/admin.css',
            [],
            SWPS_VERSION
        );

        wp_enqueue_script(
            'swps-admin',
            SWPS_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            SWPS_VERSION,
            true
        );

        wp_localize_script( 'swps-admin', 'swpsAdmin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'swps_nonce' ),
        ] );
    }

    /**
     * AJAX handler: Generate a single post.
     */
    public function ajax_generate_post(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $topic = sanitize_text_field( $_POST['topic'] ?? '' );

        $result = $this->generator->generate_post( $topic );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler: Analyze site content.
     */
    public function ajax_analyze_site(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $analysis = $this->analyzer->get_site_summary();

        wp_send_json_success( $analysis );
    }

    /**
     * Add settings link on plugins list page.
     */
    public function add_settings_link( array $links ): array {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=stratawp-seo' ) . '">' . __( 'Settings', 'stratawp-seo' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}

/**
 * Activation hook.
 */
function swps_activate(): void {
    // Set default options.
    $defaults = [
        'api_key'            => '',
        'site_niche'         => '',
        'site_description'   => '',
        'tone'               => 'professional',
        'writing_style'      => '',
        'target_keywords'    => '',
        'post_category'      => 0,
        'post_author'        => get_current_user_id(),
        'post_status'        => 'draft',
        'word_count_min'     => 1200,
        'word_count_max'     => 2000,
        'include_faq_schema' => 1,
        'include_toc'        => 1,
        'internal_links_min' => 3,
        'internal_links_max' => 6,
        'cron_enabled'       => 0,
        'cron_frequency'     => 'weekly',
        'cron_day'           => 'monday',
        'cron_time'          => '09:00',
        'cron_posts_per_run' => 1,
    ];

    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( "swps_{$key}" ) ) {
            update_option( "swps_{$key}", $value );
        }
    }

    // Schedule cron if enabled.
    if ( get_option( 'swps_cron_enabled' ) ) {
        SWPS_Cron::schedule();
    }
}
register_activation_hook( __FILE__, 'swps_activate' );

/**
 * Deactivation hook.
 */
function swps_deactivate(): void {
    SWPS_Cron::unschedule();
}
register_deactivation_hook( __FILE__, 'swps_deactivate' );

/**
 * Initialize plugin.
 */
function stratawp_seo(): StrataWP_SEO {
    return StrataWP_SEO::instance();
}
add_action( 'plugins_loaded', 'stratawp_seo' );
