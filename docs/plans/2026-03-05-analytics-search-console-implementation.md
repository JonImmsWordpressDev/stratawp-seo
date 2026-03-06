# Analytics & Search Console — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add on-site analytics tracking (page views, time on page, scroll depth, bounce rate) and Google Search Console integration with a unified analytics dashboard.

**Architecture:** Three new classes — `SWPS_Analytics_Tracker` (frontend JS + AJAX recorder + custom DB tables + aggregation cron), `SWPS_Search_Console` (Google OAuth flow + GSC API client + caching), `SWPS_Analytics_Dashboard` (admin page + AJAX data endpoints + per-post metabox + post list column). Two new JS files — tracking snippet and dashboard UI.

**Tech Stack:** PHP 8.0+, WordPress Settings API, vanilla JS (~1KB tracker), `$wpdb` for custom tables, Google OAuth 2.0 + Search Console API v1 via `wp_remote_get/post`, SVG charts (no library).

---

### Task 1: Create `SWPS_Analytics_Tracker` — DB Tables + Recording Endpoint

**Files:**
- Create: `includes/class-analytics-tracker.php`

**Context:** This class manages the custom database tables, provides a `nopriv` AJAX endpoint for recording page hits from the frontend, and runs the aggregation/pruning cron. The constructor registers the AJAX handler and cron hooks. A static `create_tables()` method is called on plugin activation.

**Code:**

```php
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
```

**Commit:** `feat: Add SWPS_Analytics_Tracker with DB tables, AJAX recording, and aggregation`

---

### Task 2: Create Frontend Tracking Snippet

**Files:**
- Create: `admin/js/analytics-tracker.js`

**Context:** ~1KB vanilla JS. No jQuery, no cookies, no external calls. Uses `navigator.sendBeacon()` with `fetch` fallback. Tracks page views, time on page (via `visibilitychange`), max scroll depth (debounced), and bounce detection (no interaction within 10s).

**Code:**

```js
/**
 * StrataWP SEO — Frontend Analytics Tracker
 *
 * Lightweight page view tracking. Cookie-free, GDPR-friendly.
 * Sends data via sendBeacon on page unload.
 */
(function () {
    'use strict';

    if ( typeof swpsTracker === 'undefined' ) return;

    var startTime  = Date.now();
    var maxScroll  = 0;
    var interacted = false;
    var sent       = false;

    // Track max scroll depth.
    var scrollTimer = null;
    function onScroll() {
        if ( scrollTimer ) return;
        scrollTimer = setTimeout( function () {
            scrollTimer = null;
            var scrollTop  = window.pageYOffset || document.documentElement.scrollTop;
            var docHeight  = Math.max( document.body.scrollHeight, document.documentElement.scrollHeight );
            var winHeight  = window.innerHeight;
            var scrollable = docHeight - winHeight;

            if ( scrollable > 0 ) {
                var pct = Math.round( ( scrollTop / scrollable ) * 100 );
                if ( pct > maxScroll ) maxScroll = pct;
            }
        }, 200 );
    }

    // Track interaction (not a bounce).
    function onInteract() {
        interacted = true;
        document.removeEventListener( 'click', onInteract );
        document.removeEventListener( 'keydown', onInteract );
    }

    // Bounce timeout — 10 seconds.
    var bounceTimer = setTimeout( function () {
        interacted = true; // Stayed long enough = not a bounce.
    }, 10000 );

    // Send tracking data.
    function sendData() {
        if ( sent ) return;
        sent = true;

        var elapsed = Math.round( ( Date.now() - startTime ) / 1000 );
        var data    = new FormData();

        data.append( 'action', 'swps_track' );
        data.append( 'nonce', swpsTracker.nonce );
        data.append( 'post_id', swpsTracker.post_id );
        data.append( 'page_url', window.location.href );
        data.append( 'referrer', document.referrer || '' );
        data.append( 'time_on_page', elapsed );
        data.append( 'scroll_depth', maxScroll );
        data.append( 'is_bounce', interacted ? 0 : 1 );

        if ( navigator.sendBeacon ) {
            navigator.sendBeacon( swpsTracker.ajax_url, data );
        } else {
            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', swpsTracker.ajax_url, false );
            xhr.send( data );
        }
    }

    // Listeners.
    window.addEventListener( 'scroll', onScroll, { passive: true } );
    document.addEventListener( 'click', onInteract );
    document.addEventListener( 'keydown', onInteract );
    document.addEventListener( 'visibilitychange', function () {
        if ( document.visibilityState === 'hidden' ) sendData();
    } );
    window.addEventListener( 'beforeunload', sendData );
})();
```

**Commit:** `feat: Add frontend analytics tracking snippet (vanilla JS, ~1KB)`

---

### Task 3: Create `SWPS_Search_Console` — OAuth + API Client

**Files:**
- Create: `includes/class-search-console.php`

**Context:** Handles Google OAuth 2.0 flow (user-provided client credentials), token storage/refresh, GSC Search Analytics API queries, and data caching via transients. The admin callback is registered as a WordPress admin action.

**Code:**

```php
<?php
/**
 * Google Search Console integration.
 *
 * OAuth 2.0 flow, token management, and Search Analytics API client.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Search_Console {

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const API_BASE       = 'https://www.googleapis.com/webmasters/v3';
    private const SCOPE          = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const CACHE_PREFIX   = 'swps_gsc_';
    private const CACHE_TTL      = 43200; // 12 hours.
    private const CRON_HOOK      = 'swps_gsc_refresh_data';

    public function __construct() {
        add_action( 'admin_init', [ $this, 'handle_oauth_callback' ] );
        add_action( self::CRON_HOOK, [ $this, 'refresh_cached_data' ] );
    }

    /**
     * Check if GSC is connected.
     */
    public function is_connected(): bool {
        return (bool) get_option( 'swps_gsc_connected', false );
    }

    /**
     * Get the OAuth authorization URL.
     */
    public function get_auth_url(): string {
        $client_id = get_option( 'swps_gsc_client_id', '' );

        if ( empty( $client_id ) ) {
            return '';
        }

        $state = wp_create_nonce( 'swps_gsc_oauth' );
        update_option( 'swps_gsc_oauth_state', $state );

        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ];

        return self::AUTH_ENDPOINT . '?' . http_build_query( $params );
    }

    /**
     * Handle the OAuth callback from Google.
     */
    public function handle_oauth_callback(): void {
        if ( ! isset( $_GET['swps_gsc_callback'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $state = sanitize_text_field( $_GET['state'] ?? '' );
        $saved = get_option( 'swps_gsc_oauth_state', '' );

        if ( empty( $state ) || $state !== $saved ) {
            add_settings_error( 'stratawp-seo', 'gsc_oauth', __( 'Invalid OAuth state. Please try again.', 'stratawp-seo' ) );
            return;
        }

        delete_option( 'swps_gsc_oauth_state' );

        if ( isset( $_GET['error'] ) ) {
            add_settings_error( 'stratawp-seo', 'gsc_oauth', __( 'Google authorization denied.', 'stratawp-seo' ) );
            return;
        }

        $code = sanitize_text_field( $_GET['code'] ?? '' );

        if ( empty( $code ) ) {
            add_settings_error( 'stratawp-seo', 'gsc_oauth', __( 'No authorization code received.', 'stratawp-seo' ) );
            return;
        }

        $tokens = $this->exchange_code( $code );

        if ( is_wp_error( $tokens ) ) {
            add_settings_error( 'stratawp-seo', 'gsc_oauth', $tokens->get_error_message() );
            return;
        }

        update_option( 'swps_gsc_access_token', SWPS_Encryption::encrypt( $tokens['access_token'] ) );
        update_option( 'swps_gsc_refresh_token', SWPS_Encryption::encrypt( $tokens['refresh_token'] ) );
        update_option( 'swps_gsc_token_expiry', time() + (int) $tokens['expires_in'] );
        update_option( 'swps_gsc_connected', 1 );

        // Redirect to settings page.
        wp_safe_redirect( admin_url( 'admin.php?page=stratawp-seo&gsc_connected=1' ) );
        exit;
    }

    /**
     * Disconnect from GSC — clear all tokens and cached data.
     */
    public function disconnect(): void {
        delete_option( 'swps_gsc_access_token' );
        delete_option( 'swps_gsc_refresh_token' );
        delete_option( 'swps_gsc_token_expiry' );
        delete_option( 'swps_gsc_connected' );
        delete_option( 'swps_gsc_property' );
        delete_option( 'swps_gsc_oauth_state' );

        $this->clear_cache();
    }

    /**
     * Get the list of verified GSC properties.
     *
     * @return array Site URLs.
     */
    public function get_properties(): array {
        $response = $this->api_request( '/sites' );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $sites = [];

        foreach ( $response['siteEntry'] ?? [] as $site ) {
            $sites[] = $site['siteUrl'];
        }

        return $sites;
    }

    /**
     * Fetch search analytics data (queries + pages).
     *
     * @param int $days Number of days to fetch.
     * @return array Search data with 'queries', 'pages', 'daily' keys.
     */
    public function get_search_data( int $days = 90 ): array {
        $cache_key = self::CACHE_PREFIX . 'search_data_' . $days;
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return SWPS_Hooks::filter_gsc_data( $cached, $this->get_property() );
        }

        $property  = $this->get_property();
        $start     = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $end       = gmdate( 'Y-m-d', strtotime( '-2 days' ) ); // GSC data has 2-day delay.

        $data = [
            'queries' => $this->fetch_analytics( $property, $start, $end, ['query'] ),
            'pages'   => $this->fetch_analytics( $property, $start, $end, ['page'] ),
            'daily'   => $this->fetch_analytics( $property, $start, $end, ['date'] ),
        ];

        set_transient( $cache_key, $data, self::CACHE_TTL );

        return SWPS_Hooks::filter_gsc_data( $data, $property );
    }

    /**
     * Get search data for a specific page URL.
     *
     * @param string $url  Page URL.
     * @param int    $days Days to look back.
     * @return array Query data for this page.
     */
    public function get_page_queries( string $url, int $days = 90 ): array {
        $property = $this->get_property();
        $start    = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $end      = gmdate( 'Y-m-d', strtotime( '-2 days' ) );

        $body = [
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => ['query'],
            'dimensionFilterGroups' => [
                [
                    'filters' => [
                        [
                            'dimension'  => 'page',
                            'expression' => $url,
                        ],
                    ],
                ],
            ],
            'rowLimit' => 10,
        ];

        $encoded_property = rawurlencode( $property );
        $response = $this->api_request(
            "/sites/{$encoded_property}/searchAnalytics/query",
            'POST',
            $body
        );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        return $response['rows'] ?? [];
    }

    /**
     * Clear all GSC cached data.
     */
    public function clear_cache(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%'
            )
        );

        // Also delete timeout transients.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%'
            )
        );
    }

    /**
     * Cron: Refresh cached data in background.
     */
    public function refresh_cached_data(): void {
        if ( ! $this->is_connected() ) {
            return;
        }

        $this->clear_cache();
        $this->get_search_data( 90 );
    }

    /**
     * Schedule the data refresh cron.
     */
    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
        }
    }

    /**
     * Unschedule the data refresh cron.
     */
    public static function unschedule_cron(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Get the selected GSC property.
     */
    private function get_property(): string {
        return get_option( 'swps_gsc_property', '' );
    }

    /**
     * Get the OAuth redirect URI.
     */
    private function get_redirect_uri(): string {
        return admin_url( 'admin.php?swps_gsc_callback=1' );
    }

    /**
     * Exchange authorization code for tokens.
     *
     * @param string $code Authorization code.
     * @return array|WP_Error Token data or error.
     */
    private function exchange_code( string $code ): array|WP_Error {
        $response = wp_remote_post( self::TOKEN_ENDPOINT, [
            'body' => [
                'code'          => $code,
                'client_id'     => get_option( 'swps_gsc_client_id', '' ),
                'client_secret' => SWPS_Encryption::decrypt( get_option( 'swps_gsc_client_secret', '' ) ),
                'redirect_uri'  => $this->get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'gsc_token_error', $body['error_description'] ?? $body['error'] );
        }

        if ( empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
            return new WP_Error( 'gsc_token_error', __( 'Invalid token response from Google.', 'stratawp-seo' ) );
        }

        return $body;
    }

    /**
     * Refresh the access token using the refresh token.
     *
     * @return bool True if refreshed successfully.
     */
    private function refresh_token(): bool {
        $refresh_token = SWPS_Encryption::decrypt( get_option( 'swps_gsc_refresh_token', '' ) );

        if ( empty( $refresh_token ) ) {
            $this->disconnect();
            return false;
        }

        $response = wp_remote_post( self::TOKEN_ENDPOINT, [
            'body' => [
                'refresh_token' => $refresh_token,
                'client_id'     => get_option( 'swps_gsc_client_id', '' ),
                'client_secret' => SWPS_Encryption::decrypt( get_option( 'swps_gsc_client_secret', '' ) ),
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) || empty( $body['access_token'] ) ) {
            $this->disconnect();
            return false;
        }

        update_option( 'swps_gsc_access_token', SWPS_Encryption::encrypt( $body['access_token'] ) );
        update_option( 'swps_gsc_token_expiry', time() + (int) $body['expires_in'] );

        return true;
    }

    /**
     * Get a valid access token, refreshing if needed.
     *
     * @return string|null Access token or null.
     */
    private function get_access_token(): ?string {
        $expiry = (int) get_option( 'swps_gsc_token_expiry', 0 );

        if ( $expiry < time() + 60 ) {
            if ( ! $this->refresh_token() ) {
                return null;
            }
        }

        return SWPS_Encryption::decrypt( get_option( 'swps_gsc_access_token', '' ) );
    }

    /**
     * Make an authenticated API request to GSC.
     *
     * @param string $endpoint API endpoint path.
     * @param string $method   HTTP method.
     * @param array  $body     Request body for POST.
     * @return array|WP_Error Response data or error.
     */
    private function api_request( string $endpoint, string $method = 'GET', array $body = [] ): array|WP_Error {
        $token = $this->get_access_token();

        if ( ! $token ) {
            return new WP_Error( 'gsc_no_token', __( 'Not connected to Google Search Console.', 'stratawp-seo' ) );
        }

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( self::API_BASE . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code >= 400 ) {
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            $message = $decoded['error']['message'] ?? "HTTP {$code}";
            return new WP_Error( 'gsc_api_error', $message );
        }

        return json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
    }

    /**
     * Fetch search analytics from GSC API.
     *
     * @param string $property   Site URL.
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @param array  $dimensions Dimensions to group by.
     * @return array Rows of analytics data.
     */
    private function fetch_analytics( string $property, string $start_date, string $end_date, array $dimensions ): array {
        $encoded_property = rawurlencode( $property );

        $response = $this->api_request(
            "/sites/{$encoded_property}/searchAnalytics/query",
            'POST',
            [
                'startDate'  => $start_date,
                'endDate'    => $end_date,
                'dimensions' => $dimensions,
                'rowLimit'   => 100,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        return $response['rows'] ?? [];
    }
}
```

**Commit:** `feat: Add SWPS_Search_Console with OAuth flow, API client, and caching`

---

### Task 4: Create `SWPS_Analytics_Dashboard` — Admin Page + AJAX + Metabox

**Files:**
- Create: `includes/class-analytics-dashboard.php`

**Context:** Registers the "Analytics" submenu page, provides AJAX endpoints for dashboard data (daily stats, top pages, top queries), adds a per-post metabox, and adds a "Views (30d)" column to the posts list table.

**Code:**

```php
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

        // Admin menu.
        add_action( 'admin_menu', [ $this, 'register_menu' ] );

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
```

**Commit:** `feat: Add SWPS_Analytics_Dashboard with admin page, AJAX endpoints, metabox, and post column`

---

### Task 5: Create Analytics Dashboard Template

**Files:**
- Create: `templates/analytics-page.php`

**Context:** The admin page HTML template. Contains the GSC connection panel, date range picker, metric cards, chart container, and table placeholders. All data loaded via AJAX after page render.

**Code:**

```php
<?php
/**
 * Analytics dashboard page template.
 *
 * @var bool   $gsc_connected Whether GSC is connected.
 * @var string $gsc_property  Selected GSC property.
 * @var string $gsc_auth_url  OAuth authorization URL.
 * @var array  $properties    Available GSC properties.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-analytics-wrap">
    <h1><?php esc_html_e( 'Analytics', 'stratawp-seo' ); ?></h1>

    <?php if ( $gsc_connected && empty( $gsc_property ) && ! empty( $properties ) ) : ?>
        <div class="notice notice-info">
            <p>
                <strong><?php esc_html_e( 'Select your Search Console property:', 'stratawp-seo' ); ?></strong>
                <select id="swps-gsc-property-select">
                    <option value=""><?php esc_html_e( '— Select —', 'stratawp-seo' ); ?></option>
                    <?php foreach ( $properties as $prop ) : ?>
                        <option value="<?php echo esc_attr( $prop ); ?>"><?php echo esc_html( $prop ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button" id="swps-gsc-save-property"><?php esc_html_e( 'Save', 'stratawp-seo' ); ?></button>
            </p>
        </div>
    <?php endif; ?>

    <!-- Date Range -->
    <div class="swps-analytics-toolbar">
        <div class="swps-date-range">
            <button class="button swps-range-btn" data-days="7"><?php esc_html_e( '7 days', 'stratawp-seo' ); ?></button>
            <button class="button swps-range-btn active" data-days="30"><?php esc_html_e( '30 days', 'stratawp-seo' ); ?></button>
            <button class="button swps-range-btn" data-days="90"><?php esc_html_e( '90 days', 'stratawp-seo' ); ?></button>
        </div>
        <div class="swps-toolbar-right">
            <?php if ( $gsc_connected ) : ?>
                <span class="swps-gsc-status swps-gsc-connected">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php echo esc_html( $gsc_property ); ?>
                </span>
                <button class="button" id="swps-gsc-refresh" title="<?php esc_attr_e( 'Refresh GSC data', 'stratawp-seo' ); ?>">
                    <span class="dashicons dashicons-update"></span>
                </button>
                <button class="button" id="swps-gsc-disconnect"><?php esc_html_e( 'Disconnect', 'stratawp-seo' ); ?></button>
            <?php else : ?>
                <?php if ( ! empty( $gsc_auth_url ) ) : ?>
                    <a href="<?php echo esc_url( $gsc_auth_url ); ?>" class="button button-primary">
                        <span class="dashicons dashicons-google" style="margin-top:4px;"></span>
                        <?php esc_html_e( 'Connect Search Console', 'stratawp-seo' ); ?>
                    </a>
                <?php else : ?>
                    <span class="description"><?php esc_html_e( 'Enter Google OAuth credentials in Settings to connect.', 'stratawp-seo' ); ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Metric Cards -->
    <div class="swps-metric-cards">
        <div class="swps-metric-card">
            <span class="swps-metric-label"><?php esc_html_e( 'Page Views', 'stratawp-seo' ); ?></span>
            <span class="swps-metric-value" id="swps-total-views">—</span>
            <span class="swps-metric-change" id="swps-views-change"></span>
        </div>
        <div class="swps-metric-card">
            <span class="swps-metric-label"><?php esc_html_e( 'Avg Time on Page', 'stratawp-seo' ); ?></span>
            <span class="swps-metric-value" id="swps-avg-time">—</span>
        </div>
        <?php if ( $gsc_connected ) : ?>
        <div class="swps-metric-card">
            <span class="swps-metric-label"><?php esc_html_e( 'Search Clicks', 'stratawp-seo' ); ?></span>
            <span class="swps-metric-value" id="swps-gsc-clicks">—</span>
        </div>
        <div class="swps-metric-card">
            <span class="swps-metric-label"><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></span>
            <span class="swps-metric-value" id="swps-gsc-impressions">—</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Chart -->
    <div class="swps-chart-container">
        <h2><?php esc_html_e( 'Traffic Over Time', 'stratawp-seo' ); ?></h2>
        <div id="swps-analytics-chart" class="swps-chart"></div>
    </div>

    <!-- Top Pages -->
    <div class="swps-analytics-section">
        <h2><?php esc_html_e( 'Top Pages', 'stratawp-seo' ); ?></h2>
        <table class="widefat striped" id="swps-top-pages-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Page', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Views', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Avg Time', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Scroll', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Bounce', 'stratawp-seo' ); ?></th>
                    <?php if ( $gsc_connected ) : ?>
                    <th><?php esc_html_e( 'Clicks', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="8" class="swps-loading"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <?php if ( $gsc_connected ) : ?>
    <!-- Top Queries -->
    <div class="swps-analytics-section">
        <h2><?php esc_html_e( 'Top Search Queries', 'stratawp-seo' ); ?></h2>
        <table class="widefat striped" id="swps-top-queries-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Query', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Clicks', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'CTR', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="5" class="swps-loading"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
```

**Commit:** `feat: Add analytics dashboard page template`

---

### Task 6: Create Analytics Dashboard JavaScript

**Files:**
- Create: `admin/js/analytics.js`

**Context:** Handles dashboard data loading via AJAX, renders the SVG line chart, populates tables, manages date range switching, GSC property selection, disconnect/refresh buttons, and the post metabox data loading.

**Code:**

```js
/**
 * StrataWP SEO — Analytics Dashboard JS
 *
 * Loads dashboard data via AJAX, renders charts and tables.
 */
(function ($) {
    'use strict';

    if (typeof swpsAdmin === 'undefined') return;

    var currentDays = 30;

    // --- Dashboard ---

    function loadOverview() {
        $.post(swpsAdmin.ajax_url, {
            action: 'swps_analytics_overview',
            nonce: swpsAdmin.nonce,
            days: currentDays
        }, function (res) {
            if (!res.success) return;
            var d = res.data;

            $('#swps-total-views').text(d.total_views.toLocaleString());
            $('#swps-avg-time').text(formatSeconds(d.avg_time));

            var changeEl = $('#swps-views-change');
            if (d.views_change !== 0) {
                var arrow = d.views_change > 0 ? '↑' : '↓';
                var cls = d.views_change > 0 ? 'swps-change-up' : 'swps-change-down';
                changeEl.html('<span class="' + cls + '">' + arrow + ' ' + Math.abs(d.views_change) + '%</span>');
            } else {
                changeEl.html('');
            }

            if (d.gsc_clicks !== undefined) {
                $('#swps-gsc-clicks').text(d.gsc_clicks.toLocaleString());
                $('#swps-gsc-impressions').text(d.gsc_impressions.toLocaleString());
            }

            renderChart(d.daily, d.gsc_daily || []);
        });
    }

    function loadTopPages() {
        $.post(swpsAdmin.ajax_url, {
            action: 'swps_analytics_top_pages',
            nonce: swpsAdmin.nonce,
            days: currentDays
        }, function (res) {
            if (!res.success) return;
            var tbody = $('#swps-top-pages-table tbody');
            tbody.empty();

            if (!res.data.length) {
                tbody.html('<tr><td colspan="8">No data yet.</td></tr>');
                return;
            }

            res.data.forEach(function (page) {
                var row = '<tr>';
                row += '<td><a href="' + page.url + '" target="_blank">' + escHtml(page.title) + '</a></td>';
                row += '<td>' + parseInt(page.views).toLocaleString() + '</td>';
                row += '<td>' + formatSeconds(page.avg_time_on_page) + '</td>';
                row += '<td>' + Math.round(page.avg_scroll_depth) + '%</td>';
                row += '<td>' + page.bounce_rate + '%</td>';

                if (page.gsc_clicks !== undefined) {
                    row += '<td>' + (page.gsc_clicks || 0) + '</td>';
                    row += '<td>' + (page.gsc_impressions || 0) + '</td>';
                    row += '<td>' + (page.gsc_position || '—') + '</td>';
                }

                row += '</tr>';
                tbody.append(row);
            });
        });
    }

    function loadTopQueries() {
        $.post(swpsAdmin.ajax_url, {
            action: 'swps_analytics_top_queries',
            nonce: swpsAdmin.nonce,
            days: currentDays
        }, function (res) {
            if (!res.success) return;
            var tbody = $('#swps-top-queries-table tbody');
            tbody.empty();

            if (!res.data.length) {
                tbody.html('<tr><td colspan="5">No data yet.</td></tr>');
                return;
            }

            res.data.forEach(function (q) {
                var row = '<tr>';
                row += '<td>' + escHtml(q.query) + '</td>';
                row += '<td>' + q.clicks + '</td>';
                row += '<td>' + q.impressions.toLocaleString() + '</td>';
                row += '<td>' + q.ctr + '%</td>';
                row += '<td>' + q.position + '</td>';
                row += '</tr>';
                tbody.append(row);
            });
        });
    }

    // --- SVG Chart ---

    function renderChart(dailyViews, gscDaily) {
        var container = document.getElementById('swps-analytics-chart');
        if (!container) return;

        var width = container.offsetWidth || 800;
        var height = 250;
        var padding = { top: 20, right: 20, bottom: 30, left: 50 };
        var chartW = width - padding.left - padding.right;
        var chartH = height - padding.top - padding.bottom;

        if (!dailyViews.length) {
            container.innerHTML = '<p style="text-align:center;color:#646970;padding:40px 0;">No data for this period.</p>';
            return;
        }

        var maxViews = Math.max.apply(null, dailyViews.map(function (d) { return parseInt(d.views) || 0; }));
        if (maxViews === 0) maxViews = 1;

        var points = dailyViews.map(function (d, i) {
            var x = padding.left + (i / Math.max(dailyViews.length - 1, 1)) * chartW;
            var y = padding.top + chartH - ((parseInt(d.views) || 0) / maxViews) * chartH;
            return x + ',' + y;
        });

        var svg = '<svg width="' + width + '" height="' + height + '" xmlns="http://www.w3.org/2000/svg">';

        // Y-axis labels.
        for (var i = 0; i <= 4; i++) {
            var yVal = Math.round((maxViews / 4) * i);
            var yPos = padding.top + chartH - (i / 4) * chartH;
            svg += '<text x="' + (padding.left - 8) + '" y="' + (yPos + 4) + '" text-anchor="end" fill="#646970" font-size="11">' + yVal + '</text>';
            svg += '<line x1="' + padding.left + '" y1="' + yPos + '" x2="' + (width - padding.right) + '" y2="' + yPos + '" stroke="#e0e0e0" />';
        }

        // Views line.
        svg += '<polyline points="' + points.join(' ') + '" fill="none" stroke="#2271b1" stroke-width="2" />';

        svg += '</svg>';
        container.innerHTML = svg;
    }

    // --- Post Metabox ---

    function loadMetabox() {
        var box = document.getElementById('swps-analytics-metabox');
        if (!box) return;

        var postId = box.dataset.postId;
        var nonce = box.dataset.nonce;

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_analytics_post_stats',
            nonce: nonce,
            post_id: postId
        }, function (res) {
            if (!res.success) {
                box.innerHTML = '<p>Unable to load analytics.</p>';
                return;
            }

            var d = res.data;
            var html = '<table class="swps-metabox-stats">';
            html += '<tr><td>Views (7d)</td><td><strong>' + d.views_7d + '</strong></td></tr>';
            html += '<tr><td>Views (30d)</td><td><strong>' + d.views_30d + '</strong></td></tr>';
            html += '<tr><td>Avg Time</td><td>' + formatSeconds(d.avg_time_on_page) + '</td></tr>';
            html += '<tr><td>Scroll Depth</td><td>' + d.avg_scroll_depth + '%</td></tr>';
            html += '<tr><td>Bounce Rate</td><td>' + d.bounce_rate + '%</td></tr>';
            html += '</table>';

            if (d.gsc_queries && d.gsc_queries.length) {
                html += '<h4 style="margin:12px 0 4px;">Top Queries</h4>';
                html += '<table class="swps-metabox-stats">';
                d.gsc_queries.forEach(function (q) {
                    html += '<tr><td>' + escHtml(q.query) + '</td><td>' + q.clicks + ' clicks</td><td>#' + q.position + '</td></tr>';
                });
                html += '</table>';
            }

            box.innerHTML = html;
        });
    }

    // --- Helpers ---

    function formatSeconds(s) {
        s = parseInt(s) || 0;
        var m = Math.floor(s / 60);
        var sec = s % 60;
        return m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // --- Init ---

    $(document).ready(function () {
        // Dashboard page.
        if ($('.swps-analytics-wrap').length) {
            loadOverview();
            loadTopPages();

            if ($('#swps-top-queries-table').length) {
                loadTopQueries();
            }

            // Date range buttons.
            $('.swps-range-btn').on('click', function () {
                $('.swps-range-btn').removeClass('active');
                $(this).addClass('active');
                currentDays = parseInt($(this).data('days'));
                loadOverview();
                loadTopPages();
                if ($('#swps-top-queries-table').length) loadTopQueries();
            });

            // GSC buttons.
            $('#swps-gsc-disconnect').on('click', function () {
                if (!confirm('Disconnect from Google Search Console?')) return;
                $.post(swpsAdmin.ajax_url, {
                    action: 'swps_gsc_disconnect',
                    nonce: swpsAdmin.nonce
                }, function () { location.reload(); });
            });

            $('#swps-gsc-refresh').on('click', function () {
                var btn = $(this);
                btn.prop('disabled', true);
                $.post(swpsAdmin.ajax_url, {
                    action: 'swps_gsc_refresh',
                    nonce: swpsAdmin.nonce
                }, function () {
                    btn.prop('disabled', false);
                    loadOverview();
                    loadTopPages();
                    if ($('#swps-top-queries-table').length) loadTopQueries();
                });
            });

            $('#swps-gsc-save-property').on('click', function () {
                var prop = $('#swps-gsc-property-select').val();
                if (!prop) return;
                $.post(swpsAdmin.ajax_url, {
                    action: 'swps_gsc_save_property',
                    nonce: swpsAdmin.nonce,
                    property: prop
                }, function () { location.reload(); });
            });
        }

        // Post metabox.
        if ($('#swps-analytics-metabox').length) {
            loadMetabox();
        }
    });

})(jQuery);
```

**Commit:** `feat: Add analytics dashboard JavaScript (charts, tables, AJAX, metabox)`

---

### Task 7: Settings Section + Hook Helpers + Plugin Integration

**Files:**
- Modify: `includes/class-settings.php` — add Analytics section before Advanced section (before line 286)
- Modify: `includes/class-hooks.php` — add 3 static methods before closing `}`
- Modify: `stratawp-seo.php` — require, properties, instantiation, activation/deactivation hooks, admin asset enqueue, AJAX handlers

**Code for `class-settings.php` — insert before `// --- Advanced Section (v2.0) ---`:**

```php
        // --- Analytics Section ---
        add_settings_section( 'swps_analytics_section', __( 'Analytics', 'stratawp-seo' ), [ $this, 'render_analytics_section' ], 'stratawp-seo' );

        $this->add_field( 'analytics_enabled', __( 'Enable On-Site Tracking', 'stratawp-seo' ), 'checkbox', 'swps_analytics_section', [
            'label' => __( 'Track page views, time on page, scroll depth, and bounce rate (cookie-free, GDPR-friendly)', 'stratawp-seo' ),
        ] );

        $this->add_field( 'analytics_retention', __( 'Data Retention', 'stratawp-seo' ), 'select', 'swps_analytics_section', [
            'options' => [
                '30'  => __( '30 days', 'stratawp-seo' ),
                '90'  => __( '90 days', 'stratawp-seo' ),
                '180' => __( '180 days', 'stratawp-seo' ),
                '365' => __( '365 days', 'stratawp-seo' ),
            ],
            'description' => __( 'How long to keep analytics data. Older data is automatically pruned.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'analytics_exclude_admins', __( 'Exclude Admins', 'stratawp-seo' ), 'checkbox', 'swps_analytics_section', [
            'label' => __( 'Don\'t track visits from logged-in administrators', 'stratawp-seo' ),
        ] );

        $this->add_field( 'gsc_client_id', __( 'Google OAuth Client ID', 'stratawp-seo' ), 'text', 'swps_analytics_section', [
            'placeholder' => 'xxxx.apps.googleusercontent.com',
            'description' => __( 'Create OAuth credentials in your <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>. Set the redirect URI to: <code>' . admin_url( 'admin.php?swps_gsc_callback=1' ) . '</code>', 'stratawp-seo' ),
        ] );

        $this->add_field( 'gsc_client_secret', __( 'Google OAuth Client Secret', 'stratawp-seo' ), 'password', 'swps_analytics_section', [
            'description' => __( 'Stored encrypted. After saving, connect via the Analytics page.', 'stratawp-seo' ),
        ] );
```

**Also add the section render callback** (alongside other render methods):

```php
    public function render_analytics_section(): void {
        echo '<p>' . esc_html__( 'On-site analytics tracking and Google Search Console integration.', 'stratawp-seo' ) . '</p>';
    }
```

**Code for `class-hooks.php` — add before closing `}`:**

```php
    /**
     * Apply the analytics tracking data filter.
     *
     * @param array $data    Tracking data array.
     * @param int   $post_id Post ID.
     * @return array Filtered data. Return empty array to block.
     */
    public static function filter_analytics_track( array $data, int $post_id ): array {
        return apply_filters( 'swps_analytics_track', $data, $post_id );
    }

    /**
     * Apply the analytics exclude filter.
     *
     * @param bool $exclude Whether to exclude this page.
     * @param int  $post_id Post ID.
     * @return bool
     */
    public static function filter_analytics_exclude( bool $exclude, int $post_id ): bool {
        return apply_filters( 'swps_analytics_exclude', $exclude, $post_id );
    }

    /**
     * Apply the GSC data filter.
     *
     * @param array  $data     GSC response data.
     * @param string $property GSC property URL.
     * @return array Filtered data.
     */
    public static function filter_gsc_data( array $data, string $property ): array {
        return apply_filters( 'swps_gsc_data', $data, $property );
    }
```

**Code for `stratawp-seo.php`:**

**1. Require (insert after `class-image-inserter.php` require, before "Core classes"):**

```php
// Analytics.
require_once SWPS_PLUGIN_DIR . 'includes/class-analytics-tracker.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-search-console.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-analytics-dashboard.php';
```

**2. Properties (insert after `public SWPS_Image_Inserter $image_inserter;`):**

```php
    public SWPS_Analytics_Tracker $analytics_tracker;
    public SWPS_Search_Console $search_console;
    public SWPS_Analytics_Dashboard $analytics_dashboard;
```

**3. Instantiation (insert after `$this->image_inserter = new SWPS_Image_Inserter( $this->images );`):**

```php
        $this->analytics_tracker   = new SWPS_Analytics_Tracker();
        $this->search_console      = new SWPS_Search_Console();
        $this->analytics_dashboard = new SWPS_Analytics_Dashboard( $this->analytics_tracker, $this->search_console );
```

**4. Admin asset enqueue — update the `str_contains` check in `enqueue_admin_assets()` to include `swps-analytics`:**

Change the condition on line 220:
```php
        if ( ! str_contains( $hook, 'stratawp-seo' ) && ! str_contains( $hook, 'swps-generate' ) && ! str_contains( $hook, 'swps-voice-profiles' ) && ! str_contains( $hook, 'swps-analytics' ) ) {
```

**5. Also enqueue the analytics JS on the analytics page and post edit screens — add after the `wp_localize_script` call:**

```php
        // Analytics dashboard JS.
        if ( str_contains( $hook, 'swps-analytics' ) || in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            wp_enqueue_script(
                'swps-analytics',
                SWPS_PLUGIN_URL . 'admin/js/analytics.js',
                [ 'jquery' ],
                SWPS_VERSION,
                true
            );
        }
```

**6. Activation defaults — add to the defaults array (after `jon_ai_secret`):**

```php
        // Analytics defaults.
        'analytics_enabled'       => 1,
        'analytics_retention'     => 90,
        'analytics_exclude_admins' => 1,
        'gsc_client_id'           => '',
        'gsc_client_secret'       => '',
```

**7. Activation hook — add table creation and cron scheduling (after `flush_rewrite_rules()`):**

```php
    SWPS_Analytics_Tracker::create_tables();
    SWPS_Analytics_Tracker::schedule_cron();
    SWPS_Search_Console::schedule_cron();
```

**8. Deactivation hook — add cron unscheduling (after existing `SWPS_Cron::unschedule()`):**

```php
    SWPS_Analytics_Tracker::unschedule_cron();
    SWPS_Search_Console::unschedule_cron();
```

**9. Sanitize callback for `gsc_client_secret` — add to `get_sanitize_callback()` in `class-settings.php`, alongside the existing `jon_ai_secret` check:**

Change the condition:
```php
        if ( $key === 'jon_ai_secret' || $key === 'gsc_client_secret' ) {
```

**Commit:** `feat: Wire analytics into plugin — settings, hooks, bootstrap, activation`

---

### Task 8: Dashboard CSS

**Files:**
- Modify: `admin/css/admin.css` — append analytics styles

**Context:** Styles for the analytics dashboard: metric cards, chart container, toolbar, date range buttons, GSC status, and metabox stats table.

**Code — append to end of `admin/css/admin.css`:**

```css
/* --- Analytics Dashboard --- */

.swps-analytics-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 16px 0;
    gap: 12px;
    flex-wrap: wrap;
}

.swps-date-range {
    display: flex;
    gap: 4px;
}

.swps-range-btn.active {
    background: #2271b1;
    color: #fff;
    border-color: #2271b1;
}

.swps-toolbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

.swps-gsc-status {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
}

.swps-gsc-connected {
    color: #00a32a;
}

.swps-metric-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.swps-metric-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 16px;
    display: flex;
    flex-direction: column;
}

.swps-metric-label {
    font-size: 12px;
    color: #646970;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.swps-metric-value {
    font-size: 28px;
    font-weight: 600;
    color: #1d2327;
    line-height: 1.2;
}

.swps-metric-change {
    font-size: 13px;
    margin-top: 4px;
}

.swps-change-up {
    color: #00a32a;
}

.swps-change-down {
    color: #d63638;
}

.swps-chart-container {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 16px;
    margin-bottom: 24px;
}

.swps-chart {
    width: 100%;
    min-height: 250px;
}

.swps-analytics-section {
    margin-bottom: 24px;
}

.swps-loading {
    color: #646970;
    text-align: center;
    padding: 16px;
}

/* Analytics metabox */
.swps-metabox-stats {
    width: 100%;
    border-collapse: collapse;
}

.swps-metabox-stats td {
    padding: 4px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
}

.swps-metabox-stats td:last-child {
    text-align: right;
}
```

**Commit:** `feat: Add analytics dashboard CSS styles`

---

### Task 9: PHP Lint + Final Review

**Files:**
- All modified and created files

**Steps:**

1. Run `php -l` on all PHP files:
   ```bash
   php -l includes/class-analytics-tracker.php
   php -l includes/class-search-console.php
   php -l includes/class-analytics-dashboard.php
   php -l includes/class-settings.php
   php -l includes/class-hooks.php
   php -l stratawp-seo.php
   ```
   Expected: `No syntax errors detected` for all.

2. Verify the analytics dashboard template renders valid HTML.

3. Verify activation defaults match the settings fields.

4. Verify no duplicate option keys or conflicting hook names.

5. Verify all AJAX actions have proper nonce checks and capability gates.

**Commit:** No commit needed unless fixes are required.
