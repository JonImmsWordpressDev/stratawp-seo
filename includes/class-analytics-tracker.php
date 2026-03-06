<?php
/**
 * On-site analytics tracking.
 *
 * Lightweight page view tracking with time on page, scroll depth,
 * and bounce detection. Cookie-free, GDPR-friendly.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Analytics_Tracker {

    private const RAW_TABLE    = 'swps_analytics';
    private const DAILY_TABLE  = 'swps_analytics_daily';
    private const CRON_HOOK    = 'swps_analytics_aggregate';

    public function __construct() {
        // Record endpoint — available to all visitors (nopriv).
        add_action( 'wp_ajax_swps_track', [ $this, 'ajax_track' ] );
        add_action( 'wp_ajax_nopriv_swps_track', [ $this, 'ajax_track' ] );

        // Frontend tracking script.
        add_action( 'wp_footer', [ $this, 'enqueue_tracker' ], 99 );

        // Aggregation cron.
        add_action( self::CRON_HOOK, [ $this, 'aggregate_and_prune' ] );
    }

    /**
     * Create custom database tables. Called on activation.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $raw     = $wpdb->prefix . self::RAW_TABLE;
        $daily   = $wpdb->prefix . self::DAILY_TABLE;

        $sql_raw = "CREATE TABLE {$raw} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            page_url VARCHAR(500) NOT NULL DEFAULT '',
            referrer VARCHAR(500) NOT NULL DEFAULT '',
            time_on_page SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            scroll_depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
            is_bounce TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_post_id (post_id)
        ) {$charset};";

        $sql_daily = "CREATE TABLE {$daily} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            date DATE NOT NULL,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            avg_time_on_page SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            avg_scroll_depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
            bounces INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY idx_post_date (post_id, date)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_raw );
        dbDelta( $sql_daily );
    }

    /**
     * Schedule the daily aggregation cron.
     */
    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Unschedule the aggregation cron.
     */
    public static function unschedule_cron(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Enqueue the frontend tracking snippet.
     */
    public function enqueue_tracker(): void {
        if ( is_admin() ) {
            return;
        }

        if ( ! get_option( 'swps_analytics_enabled', 1 ) ) {
            return;
        }

        // Exclude admin users if configured.
        if ( get_option( 'swps_analytics_exclude_admins', 1 ) && current_user_can( 'manage_options' ) ) {
            return;
        }

        $post_id = is_singular() ? get_the_ID() : 0;

        wp_enqueue_script(
            'swps-analytics-tracker',
            SWPS_PLUGIN_URL . 'admin/js/analytics-tracker.js',
            [],
            SWPS_VERSION,
            [ 'in_footer' => true, 'strategy' => 'async' ]
        );

        wp_localize_script( 'swps-analytics-tracker', 'swpsTracker', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'post_id'  => $post_id,
            'nonce'    => wp_create_nonce( 'swps_track' ),
        ] );
    }

    /**
     * AJAX handler: Record a page hit.
     */
    public function ajax_track(): void {
        check_ajax_referer( 'swps_track', 'nonce' );

        global $wpdb;

        $post_id       = absint( $_POST['post_id'] ?? 0 );
        $page_url      = esc_url_raw( $_POST['page_url'] ?? '' );
        $referrer      = esc_url_raw( $_POST['referrer'] ?? '' );
        $time_on_page  = min( absint( $_POST['time_on_page'] ?? 0 ), 3600 );
        $scroll_depth  = min( absint( $_POST['scroll_depth'] ?? 0 ), 100 );
        $is_bounce     = absint( $_POST['is_bounce'] ?? 1 ) ? 1 : 0;

        $data = [
            'post_id'       => $post_id,
            'page_url'      => $page_url,
            'referrer'      => $referrer,
            'time_on_page'  => $time_on_page,
            'scroll_depth'  => $scroll_depth,
            'is_bounce'     => $is_bounce,
        ];

        /**
         * Filter tracking data before storage.
         *
         * Return empty array to block this hit.
         *
         * @param array $data    Tracking data.
         * @param int   $post_id Post ID.
         */
        $data = SWPS_Hooks::filter_analytics_track( $data, $post_id );

        if ( empty( $data ) ) {
            wp_send_json_success();
            return;
        }

        $table = $wpdb->prefix . self::RAW_TABLE;

        $wpdb->insert( $table, $data, [
            '%d', '%s', '%s', '%d', '%d', '%d',
        ] );

        wp_send_json_success();
    }

    /**
     * Aggregate raw hits older than 7 days into daily summary, then prune.
     */
    public function aggregate_and_prune(): void {
        global $wpdb;

        $raw   = $wpdb->prefix . self::RAW_TABLE;
        $daily = $wpdb->prefix . self::DAILY_TABLE;

        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

        // Aggregate raw rows into daily summary.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$daily} (post_id, date, views, avg_time_on_page, avg_scroll_depth, bounces)
             SELECT post_id, DATE(created_at), COUNT(*), AVG(time_on_page), AVG(scroll_depth), SUM(is_bounce)
             FROM {$raw}
             WHERE created_at < %s
             GROUP BY post_id, DATE(created_at)
             ON DUPLICATE KEY UPDATE
                views = views + VALUES(views),
                avg_time_on_page = (avg_time_on_page + VALUES(avg_time_on_page)) / 2,
                avg_scroll_depth = (avg_scroll_depth + VALUES(avg_scroll_depth)) / 2,
                bounces = bounces + VALUES(bounces)",
            $cutoff
        ) );

        // Delete aggregated raw rows.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$raw} WHERE created_at < %s",
            $cutoff
        ) );

        // Prune daily summary beyond retention.
        $retention_days = (int) get_option( 'swps_analytics_retention', 90 );
        $prune_date     = gmdate( 'Y-m-d', strtotime( "-{$retention_days} days" ) );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$daily} WHERE date < %s",
            $prune_date
        ) );
    }

    /**
     * Get page view stats for the dashboard.
     *
     * @param int $days Number of days to look back.
     * @return array Daily view data.
     */
    public function get_daily_stats( int $days = 30 ): array {
        global $wpdb;

        $raw   = $wpdb->prefix . self::RAW_TABLE;
        $daily = $wpdb->prefix . self::DAILY_TABLE;
        $since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        // Combine raw (recent) + daily (aggregated) data.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, SUM(views) as views, AVG(avg_time) as avg_time,
                    AVG(avg_scroll) as avg_scroll, SUM(bounces) as bounces
             FROM (
                 SELECT DATE(created_at) as date, 1 as views, time_on_page as avg_time,
                        scroll_depth as avg_scroll, is_bounce as bounces
                 FROM {$raw}
                 WHERE DATE(created_at) >= %s
                 UNION ALL
                 SELECT date, views, avg_time_on_page as avg_time,
                        avg_scroll_depth as avg_scroll, bounces
                 FROM {$daily}
                 WHERE date >= %s
             ) combined
             GROUP BY date
             ORDER BY date ASC",
            $since, $since
        ), ARRAY_A );

        return $results ?: [];
    }

    /**
     * Get top pages by views.
     *
     * @param int $days  Number of days.
     * @param int $limit Max pages.
     * @return array Top pages data.
     */
    public function get_top_pages( int $days = 30, int $limit = 20 ): array {
        global $wpdb;

        $raw   = $wpdb->prefix . self::RAW_TABLE;
        $daily = $wpdb->prefix . self::DAILY_TABLE;
        $since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, SUM(views) as views, AVG(avg_time) as avg_time_on_page,
                    AVG(avg_scroll) as avg_scroll_depth, SUM(bounces) as bounces
             FROM (
                 SELECT post_id, 1 as views, time_on_page as avg_time,
                        scroll_depth as avg_scroll, is_bounce as bounces
                 FROM {$raw}
                 WHERE DATE(created_at) >= %s AND post_id > 0
                 UNION ALL
                 SELECT post_id, views, avg_time_on_page as avg_time,
                        avg_scroll_depth as avg_scroll, bounces
                 FROM {$daily}
                 WHERE date >= %s AND post_id > 0
             ) combined
             GROUP BY post_id
             ORDER BY views DESC
             LIMIT %d",
            $since, $since, $limit
        ), ARRAY_A );

        return $results ?: [];
    }

    /**
     * Get stats for a single post.
     *
     * @param int $post_id Post ID.
     * @param int $days    Number of days.
     * @return array Post stats.
     */
    public function get_post_stats( int $post_id, int $days = 30 ): array {
        global $wpdb;

        $raw   = $wpdb->prefix . self::RAW_TABLE;
        $daily = $wpdb->prefix . self::DAILY_TABLE;
        $since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT SUM(views) as views, AVG(avg_time) as avg_time_on_page,
                    AVG(avg_scroll) as avg_scroll_depth, SUM(bounces) as bounces
             FROM (
                 SELECT 1 as views, time_on_page as avg_time,
                        scroll_depth as avg_scroll, is_bounce as bounces
                 FROM {$raw}
                 WHERE post_id = %d AND DATE(created_at) >= %s
                 UNION ALL
                 SELECT views, avg_time_on_page as avg_time,
                        avg_scroll_depth as avg_scroll, bounces
                 FROM {$daily}
                 WHERE post_id = %d AND date >= %s
             ) combined",
            $post_id, $since, $post_id, $since
        ), ARRAY_A );

        if ( ! $result || ! $result['views'] ) {
            return [
                'views'            => 0,
                'avg_time_on_page' => 0,
                'avg_scroll_depth' => 0,
                'bounce_rate'      => 0,
            ];
        }

        return [
            'views'            => (int) $result['views'],
            'avg_time_on_page' => (int) round( $result['avg_time_on_page'] ),
            'avg_scroll_depth' => (int) round( $result['avg_scroll_depth'] ),
            'bounce_rate'      => $result['views'] > 0
                ? round( ( $result['bounces'] / $result['views'] ) * 100 )
                : 0,
        ];
    }

    /**
     * Drop custom tables. Called on uninstall.
     */
    public static function drop_tables(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::RAW_TABLE );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::DAILY_TABLE );
    }
}
