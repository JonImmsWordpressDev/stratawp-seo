<?php
/**
 * Analytics dashboard admin page, metabox, and post list column.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Analytics_Dashboard {

    private SWPS_Analytics_Tracker $tracker;
    private SWPS_Search_Console $search_console;

    public function __construct( SWPS_Analytics_Tracker $tracker, SWPS_Search_Console $search_console ) {
        $this->tracker        = $tracker;
        $this->search_console = $search_console;

        // Admin menu — priority 20 so parent menu (registered at 10) exists first.
        add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );

        // AJAX endpoints.
        add_action( 'wp_ajax_swps_analytics_overview', [ $this, 'ajax_overview' ] );
        add_action( 'wp_ajax_swps_analytics_top_pages', [ $this, 'ajax_top_pages' ] );
        add_action( 'wp_ajax_swps_analytics_top_queries', [ $this, 'ajax_top_queries' ] );
        add_action( 'wp_ajax_swps_analytics_post_stats', [ $this, 'ajax_post_stats' ] );
        add_action( 'wp_ajax_swps_gsc_disconnect', [ $this, 'ajax_disconnect_gsc' ] );
        add_action( 'wp_ajax_swps_gsc_refresh', [ $this, 'ajax_refresh_gsc' ] );
        add_action( 'wp_ajax_swps_gsc_save_property', [ $this, 'ajax_save_property' ] );

        // Post list column.
        add_filter( 'manage_posts_columns', [ $this, 'add_views_column' ] );
        add_action( 'manage_posts_custom_column', [ $this, 'render_views_column' ], 10, 2 );
        add_filter( 'manage_edit-post_sortable_columns', [ $this, 'sortable_views_column' ] );

        // Post metabox.
        add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );
    }

    /**
     * Register the Analytics submenu page.
     */
    public function register_menu(): void {
        add_submenu_page(
            'stratawp-seo',
            __( 'Analytics', 'stratawp-seo' ),
            __( 'Analytics', 'stratawp-seo' ),
            'manage_options',
            'swps-analytics',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Render the analytics page.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $gsc_connected = $this->search_console->is_connected();
        $gsc_property  = get_option( 'swps_gsc_property', '' );
        $gsc_auth_url  = $this->search_console->get_auth_url();
        $properties    = $gsc_connected ? $this->search_console->get_properties() : [];

        include SWPS_PLUGIN_DIR . 'templates/analytics-page.php';
    }

    /**
     * AJAX: Get overview data for the dashboard.
     */
    public function ajax_overview(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $days = absint( $_POST['days'] ?? 30 );
        $days = in_array( $days, [ 7, 30, 90 ], true ) ? $days : 30;

        $daily_stats = $this->tracker->get_daily_stats( $days );

        // Calculate totals.
        $total_views   = array_sum( array_column( $daily_stats, 'views' ) );
        $avg_time      = $total_views > 0 ? round( array_sum( array_column( $daily_stats, 'avg_time' ) ) / max( count( $daily_stats ), 1 ) ) : 0;
        $total_bounces = array_sum( array_column( $daily_stats, 'bounces' ) );
        $bounce_rate   = $total_views > 0 ? round( ( $total_bounces / $total_views ) * 100 ) : 0;

        // Previous period for comparison.
        $prev_stats    = $this->tracker->get_daily_stats( $days * 2 );
        $prev_views    = 0;

        foreach ( $prev_stats as $row ) {
            if ( $row['date'] < gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) ) {
                $prev_views += (int) $row['views'];
            }
        }

        $views_change = $prev_views > 0 ? round( ( ( $total_views - $prev_views ) / $prev_views ) * 100 ) : 0;

        $result = [
            'daily'        => $daily_stats,
            'total_views'  => $total_views,
            'avg_time'     => $avg_time,
            'bounce_rate'  => $bounce_rate,
            'views_change' => $views_change,
        ];

        // Add GSC data if connected.
        if ( $this->search_console->is_connected() ) {
            $gsc_data = $this->search_console->get_search_data( $days );

            $total_clicks      = 0;
            $total_impressions = 0;

            foreach ( $gsc_data['daily'] ?? [] as $row ) {
                $total_clicks      += $row['clicks'] ?? 0;
                $total_impressions += $row['impressions'] ?? 0;
            }

            $result['gsc_clicks']      = $total_clicks;
            $result['gsc_impressions'] = $total_impressions;
            $result['gsc_daily']       = $gsc_data['daily'] ?? [];
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Get top pages.
     */
    public function ajax_top_pages(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $days  = absint( $_POST['days'] ?? 30 );
        $pages = $this->tracker->get_top_pages( $days );

        // Enrich with post titles.
        foreach ( $pages as &$page ) {
            $post = get_post( (int) $page['post_id'] );
            $page['title'] = $post ? $post->post_title : __( '(Unknown)', 'stratawp-seo' );
            $page['url']   = $post ? get_permalink( $post ) : '';
            $page['bounce_rate'] = $page['views'] > 0
                ? round( ( $page['bounces'] / $page['views'] ) * 100 )
                : 0;
        }

        // Merge GSC data if connected.
        if ( $this->search_console->is_connected() ) {
            $gsc_data = $this->search_console->get_search_data( $days );

            $gsc_by_url = [];
            foreach ( $gsc_data['pages'] ?? [] as $row ) {
                $url = $row['keys'][0] ?? '';
                $gsc_by_url[ $url ] = $row;
            }

            foreach ( $pages as &$page ) {
                if ( ! empty( $page['url'] ) && isset( $gsc_by_url[ $page['url'] ] ) ) {
                    $gsc_row = $gsc_by_url[ $page['url'] ];
                    $page['gsc_clicks']      = $gsc_row['clicks'] ?? 0;
                    $page['gsc_impressions'] = $gsc_row['impressions'] ?? 0;
                    $page['gsc_position']    = round( $gsc_row['position'] ?? 0, 1 );
                }
            }
        }

        wp_send_json_success( $pages );
    }

    /**
     * AJAX: Get top queries (GSC only).
     */
    public function ajax_top_queries(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        if ( ! $this->search_console->is_connected() ) {
            wp_send_json_error( [ 'message' => 'Search Console not connected.' ] );
        }

        $days     = absint( $_POST['days'] ?? 30 );
        $gsc_data = $this->search_console->get_search_data( $days );
        $queries  = [];

        foreach ( $gsc_data['queries'] ?? [] as $row ) {
            $queries[] = [
                'query'       => $row['keys'][0] ?? '',
                'clicks'      => $row['clicks'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'ctr'         => round( ( $row['ctr'] ?? 0 ) * 100, 1 ),
                'position'    => round( $row['position'] ?? 0, 1 ),
            ];
        }

        wp_send_json_success( $queries );
    }

    /**
     * AJAX: Get stats for a single post (used by metabox).
     */
    public function ajax_post_stats(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
        }

        $stats_7d  = $this->tracker->get_post_stats( $post_id, 7 );
        $stats_30d = $this->tracker->get_post_stats( $post_id, 30 );

        $result = [
            'views_7d'         => $stats_7d['views'],
            'views_30d'        => $stats_30d['views'],
            'avg_time_on_page' => $stats_30d['avg_time_on_page'],
            'avg_scroll_depth' => $stats_30d['avg_scroll_depth'],
            'bounce_rate'      => $stats_30d['bounce_rate'],
        ];

        // GSC queries for this post.
        if ( $this->search_console->is_connected() ) {
            $url     = get_permalink( $post_id );
            $queries = $this->search_console->get_page_queries( $url, 90 );

            $result['gsc_queries'] = array_slice( array_map( function ( $row ) {
                return [
                    'query'    => $row['keys'][0] ?? '',
                    'clicks'   => $row['clicks'] ?? 0,
                    'position' => round( $row['position'] ?? 0, 1 ),
                ];
            }, $queries ), 0, 5 );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Disconnect GSC.
     */
    public function ajax_disconnect_gsc(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $this->search_console->disconnect();

        wp_send_json_success();
    }

    /**
     * AJAX: Refresh GSC data.
     */
    public function ajax_refresh_gsc(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $this->search_console->clear_cache();

        wp_send_json_success();
    }

    /**
     * AJAX: Save selected GSC property.
     */
    public function ajax_save_property(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $property = esc_url_raw( $_POST['property'] ?? '' );
        update_option( 'swps_gsc_property', $property );

        $this->search_console->clear_cache();

        wp_send_json_success();
    }

    /**
     * Add Views column to posts list.
     */
    public function add_views_column( array $columns ): array {
        $columns['swps_views'] = __( 'Views (30d)', 'stratawp-seo' );
        return $columns;
    }

    /**
     * Render the Views column.
     */
    public function render_views_column( string $column, int $post_id ): void {
        if ( 'swps_views' !== $column ) {
            return;
        }

        $stats = $this->tracker->get_post_stats( $post_id, 30 );
        echo esc_html( number_format_i18n( $stats['views'] ) );
    }

    /**
     * Make Views column sortable.
     */
    public function sortable_views_column( array $columns ): array {
        $columns['swps_views'] = 'swps_views';
        return $columns;
    }

    /**
     * Register the analytics metabox on post edit screens.
     */
    public function register_metabox(): void {
        add_meta_box(
            'swps_analytics_metabox',
            __( 'StrataWP Analytics', 'stratawp-seo' ),
            [ $this, 'render_metabox' ],
            'post',
            'side',
            'default'
        );
    }

    /**
     * Render the analytics metabox.
     */
    public function render_metabox( WP_Post $post ): void {
        $nonce = wp_create_nonce( 'swps_nonce' );
        printf(
            '<div id="swps-analytics-metabox" data-post-id="%d" data-nonce="%s">
                <p class="swps-loading">%s</p>
            </div>',
            $post->ID,
            esc_attr( $nonce ),
            esc_html__( 'Loading analytics...', 'stratawp-seo' )
        );
    }
}
