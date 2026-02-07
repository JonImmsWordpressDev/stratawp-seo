<?php
/**
 * Content Calendar admin page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Calendar {

    private SWPS_Topic_Queue $queue;

    public function __construct( SWPS_Topic_Queue $queue ) {
        $this->queue = $queue;

        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_swps_calendar_events', [ $this, 'ajax_get_events' ] );
        add_action( 'wp_ajax_swps_calendar_create_topic', [ $this, 'ajax_create_topic' ] );
        add_action( 'wp_ajax_swps_calendar_update_topic', [ $this, 'ajax_update_topic' ] );
        add_action( 'wp_ajax_swps_calendar_delete_topic', [ $this, 'ajax_delete_topic' ] );
    }

    /**
     * Register the calendar submenu page.
     */
    public function register_menu(): void {
        add_submenu_page(
            'stratawp-seo',
            __( 'Content Calendar', 'stratawp-seo' ),
            __( 'Content Calendar', 'stratawp-seo' ),
            'edit_posts',
            'swps-calendar',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Enqueue calendar assets.
     */
    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'swps-calendar' ) ) {
            return;
        }

        // FullCalendar CDN.
        wp_enqueue_script(
            'fullcalendar',
            'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js',
            [],
            '6.1.11',
            true
        );

        wp_enqueue_style(
            'swps-calendar',
            SWPS_PLUGIN_URL . 'admin/css/calendar.css',
            [ 'swps-admin' ],
            SWPS_VERSION
        );

        wp_enqueue_script(
            'swps-calendar',
            SWPS_PLUGIN_URL . 'admin/js/calendar.js',
            [ 'fullcalendar', 'jquery' ],
            SWPS_VERSION,
            true
        );

        wp_localize_script( 'swps-calendar', 'swpsCalendar', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'swps_calendar_nonce' ),
            'templates' => SWPS_Templates::get_options(),
        ] );
    }

    /**
     * Render the calendar page.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        include SWPS_PLUGIN_DIR . 'templates/calendar-page.php';
    }

    /**
     * AJAX: Get calendar events.
     */
    public function ajax_get_events(): void {
        check_ajax_referer( 'swps_calendar_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $start = sanitize_text_field( $_GET['start'] ?? '' );
        $end   = sanitize_text_field( $_GET['end'] ?? '' );

        if ( empty( $start ) || empty( $end ) ) {
            wp_send_json_error( [ 'message' => 'Missing date range.' ] );
        }

        $topic_events    = $this->queue->get_calendar_events( $start, $end );
        $published_events = $this->queue->get_published_events( $start, $end );

        wp_send_json_success( array_merge( $topic_events, $published_events ) );
    }

    /**
     * AJAX: Create a new topic.
     */
    public function ajax_create_topic(): void {
        check_ajax_referer( 'swps_calendar_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $title    = sanitize_text_field( $_POST['title'] ?? '' );
        $date     = sanitize_text_field( $_POST['date'] ?? '' );
        $template = sanitize_text_field( $_POST['template'] ?? 'auto' );
        $notes    = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( empty( $title ) ) {
            wp_send_json_error( [ 'message' => 'Topic title is required.' ] );
        }

        $result = $this->queue->create_topic( $title, $date, $template, $notes );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'topic_id' => $result,
            'message'  => 'Topic added to queue.',
        ] );
    }

    /**
     * AJAX: Update a topic (date change via drag-and-drop).
     */
    public function ajax_update_topic(): void {
        check_ajax_referer( 'swps_calendar_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $topic_id = (int) ( $_POST['topic_id'] ?? 0 );
        $date     = sanitize_text_field( $_POST['date'] ?? '' );

        if ( ! $topic_id || empty( $date ) ) {
            wp_send_json_error( [ 'message' => 'Missing topic ID or date.' ] );
        }

        $this->queue->update_date( $topic_id, $date );

        wp_send_json_success( [ 'message' => 'Topic rescheduled.' ] );
    }

    /**
     * AJAX: Delete a topic.
     */
    public function ajax_delete_topic(): void {
        check_ajax_referer( 'swps_calendar_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $topic_id = (int) ( $_POST['topic_id'] ?? 0 );

        if ( ! $topic_id ) {
            wp_send_json_error( [ 'message' => 'Missing topic ID.' ] );
        }

        $this->queue->delete_topic( $topic_id );

        wp_send_json_success( [ 'message' => 'Topic deleted.' ] );
    }
}
