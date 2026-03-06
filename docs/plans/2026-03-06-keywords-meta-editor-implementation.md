# Keywords & Meta Editor Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add keyword research/tracking and per-post SEO meta editing with AI integration, conflict detection, and GSC-powered rank tracking (v2.3.0).

**Architecture:** Two integrated modules — `SWPS_Keyword_Tracker` manages keyword discovery (AI) and rank tracking (GSC cron), `SWPS_Meta_Editor` handles per-post SEO fields, frontend meta output, and conflict detection. A shared focus keyword concept links them. Both integrate with the existing content generator.

**Tech Stack:** PHP 8.0+, WordPress Settings API, WP-Cron, custom DB table via `$wpdb`/`dbDelta()`, jQuery AJAX, vanilla JS for live SERP preview, existing AI provider abstraction.

---

### Task 1: Keyword Tracker — Database & Core Class

**Files:**
- Create: `includes/class-keyword-tracker.php`

**Context:** This class manages the `swps_keyword_tracking` custom table and provides CRUD + query methods for tracked keywords. Pattern follows `SWPS_Analytics_Tracker` — static `create_tables()` for activation, instance methods for queries. The existing `SWPS_Search_Console` class provides GSC data; this class stores processed keyword-level snapshots.

**Implementation:**

```php
<?php
/**
 * Keyword tracking — DB table, CRUD, GSC sync, AI suggestions.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Keyword_Tracker {

    private const TABLE     = 'swps_keyword_tracking';
    private const CRON_HOOK = 'swps_keyword_sync';

    private SWPS_Search_Console $search_console;

    public function __construct( SWPS_Search_Console $search_console ) {
        $this->search_console = $search_console;
        add_action( self::CRON_HOOK, [ $this, 'sync_from_gsc' ] );
    }

    /**
     * Create the keyword tracking table. Called on activation.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . self::TABLE;

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            post_id BIGINT UNSIGNED DEFAULT NULL,
            position FLOAT DEFAULT NULL,
            clicks INT UNSIGNED NOT NULL DEFAULT 0,
            impressions INT UNSIGNED NOT NULL DEFAULT 0,
            ctr FLOAT NOT NULL DEFAULT 0,
            date DATE NOT NULL,
            PRIMARY KEY (id),
            KEY idx_keyword_date (keyword, date),
            KEY idx_post_id (post_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Schedule the keyword sync cron.
     */
    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $frequency = get_option( 'swps_keyword_tracking_frequency', 'weekly' );
            wp_schedule_event( time(), $frequency, self::CRON_HOOK );
        }
    }

    /**
     * Unschedule the keyword sync cron.
     */
    public static function unschedule_cron(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Reschedule cron with new frequency (called when settings change).
     */
    public static function reschedule_cron(): void {
        self::unschedule_cron();
        self::schedule_cron();
    }

    /**
     * Track a keyword (add to tracking list).
     *
     * @param string $keyword Keyword text.
     * @param int    $post_id Optional linked post ID.
     * @return bool
     */
    public function track_keyword( string $keyword, int $post_id = 0 ): bool {
        global $wpdb;

        $keyword = sanitize_text_field( strtolower( trim( $keyword ) ) );
        if ( empty( $keyword ) ) {
            return false;
        }

        // Check if already tracked.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE . " WHERE keyword = %s LIMIT 1",
            $keyword
        ) );

        if ( $exists ) {
            return true; // Already tracking.
        }

        return (bool) $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            [
                'keyword'     => $keyword,
                'post_id'     => $post_id ?: null,
                'position'    => null,
                'clicks'      => 0,
                'impressions' => 0,
                'ctr'         => 0,
                'date'        => gmdate( 'Y-m-d' ),
            ],
            [ '%s', '%d', '%f', '%d', '%d', '%f', '%s' ]
        );
    }

    /**
     * Untrack a keyword (remove all history).
     *
     * @param string $keyword Keyword text.
     * @return bool
     */
    public function untrack_keyword( string $keyword ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . self::TABLE,
            [ 'keyword' => strtolower( trim( $keyword ) ) ],
            [ '%s' ]
        );
    }

    /**
     * Link a keyword to a post.
     *
     * @param string $keyword Keyword text.
     * @param int    $post_id Post ID.
     * @return bool
     */
    public function link_to_post( string $keyword, int $post_id ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [ 'post_id' => $post_id ],
            [ 'keyword' => strtolower( trim( $keyword ) ) ],
            [ '%d' ],
            [ '%s' ]
        );
    }

    /**
     * Get all tracked keywords with latest data.
     *
     * @param int $limit Max results.
     * @return array
     */
    public function get_tracked_keywords( int $limit = 100 ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // Get the most recent row per keyword.
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT t1.*
             FROM {$table} t1
             INNER JOIN (
                 SELECT keyword, MAX(date) as max_date
                 FROM {$table}
                 GROUP BY keyword
             ) t2 ON t1.keyword = t2.keyword AND t1.date = t2.max_date
             ORDER BY t1.impressions DESC
             LIMIT %d",
            $limit
        ), ARRAY_A );

        return $results ?: [];
    }

    /**
     * Get position history for a specific keyword.
     *
     * @param string $keyword Keyword text.
     * @param int    $days    Days of history.
     * @return array
     */
    public function get_keyword_history( string $keyword, int $days = 90 ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, position, clicks, impressions, ctr
             FROM {$table}
             WHERE keyword = %s AND date >= %s
             ORDER BY date ASC",
            strtolower( trim( $keyword ) ),
            $since
        ), ARRAY_A );

        return $results ?: [];
    }

    /**
     * Get "striking distance" keyword opportunities (position 8-20, high impressions).
     *
     * @param int $limit Max results.
     * @return array
     */
    public function get_opportunities( int $limit = 20 ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT t1.*
             FROM {$table} t1
             INNER JOIN (
                 SELECT keyword, MAX(date) as max_date
                 FROM {$table}
                 GROUP BY keyword
             ) t2 ON t1.keyword = t2.keyword AND t1.date = t2.max_date
             WHERE t1.position BETWEEN 8 AND 20
             AND t1.impressions > 0
             ORDER BY t1.impressions DESC
             LIMIT %d",
            $limit
        ), ARRAY_A );

        return $results ?: [];
    }

    /**
     * Sync tracked keywords with GSC data.
     * Called by WP-Cron on configured schedule.
     */
    public function sync_from_gsc(): void {
        if ( ! $this->search_console->is_connected() ) {
            return;
        }

        $tracked = $this->get_tracked_keywords( 500 );
        if ( empty( $tracked ) ) {
            return;
        }

        // Pull GSC query data for the last 7 days.
        $gsc_data = $this->search_console->get_search_data( 7 );
        $gsc_queries = [];

        foreach ( $gsc_data['queries'] ?? [] as $row ) {
            $query = strtolower( $row['keys'][0] ?? '' );
            if ( $query ) {
                $gsc_queries[ $query ] = $row;
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $today = gmdate( 'Y-m-d' );

        // Get unique tracked keywords.
        $keywords = array_unique( array_column( $tracked, 'keyword' ) );

        foreach ( $keywords as $keyword ) {
            $gsc_row = $gsc_queries[ $keyword ] ?? null;

            $wpdb->insert(
                $table,
                [
                    'keyword'     => $keyword,
                    'post_id'     => $this->find_post_for_keyword( $keyword, $tracked ),
                    'position'    => $gsc_row ? round( $gsc_row['position'] ?? 0, 1 ) : null,
                    'clicks'      => $gsc_row['clicks'] ?? 0,
                    'impressions' => $gsc_row['impressions'] ?? 0,
                    'ctr'         => $gsc_row ? round( ( $gsc_row['ctr'] ?? 0 ) * 100, 1 ) : 0,
                    'date'        => $today,
                ],
                [ '%s', '%d', '%f', '%d', '%d', '%f', '%s' ]
            );
        }
    }

    /**
     * Generate keyword suggestions via AI.
     *
     * @param string $seed_topic Seed topic for suggestions.
     * @return array|WP_Error Array of keyword suggestions or error.
     */
    public function suggest_keywords( string $seed_topic ): array|WP_Error {
        $api   = SWPS_Provider_Factory::create_ai_provider();
        $niche = get_option( 'swps_site_niche', '' );
        $desc  = get_option( 'swps_site_description', '' );

        $prompt = sprintf(
            "You are an SEO keyword research expert. Generate 15 keyword suggestions for a website in the \"%s\" niche.\n\n"
            . "Site description: %s\n\n"
            . "Seed topic: %s\n\n"
            . "For each keyword, provide:\n"
            . "- keyword: the exact search phrase (lowercase)\n"
            . "- intent: informational, transactional, or navigational\n"
            . "- difficulty: low, medium, or high (estimate)\n"
            . "- suggested_title: a blog post title targeting this keyword\n\n"
            . "Return JSON array only. No markdown, no explanation.\n"
            . "Example: [{\"keyword\":\"best running shoes\",\"intent\":\"transactional\",\"difficulty\":\"high\",\"suggested_title\":\"10 Best Running Shoes for Every Budget in 2026\"}]",
            $niche,
            $desc,
            $seed_topic
        );

        $response = $api->generate( $prompt, 'You are an SEO keyword research expert. Return only valid JSON.' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $text = $response['content'] ?? '';

        // Extract JSON from response.
        $json_match = [];
        if ( preg_match( '/\[.*\]/s', $text, $json_match ) ) {
            $suggestions = json_decode( $json_match[0], true );
            if ( is_array( $suggestions ) ) {
                return apply_filters( 'swps_keyword_suggestions', $suggestions, $seed_topic );
            }
        }

        return new WP_Error( 'swps_keyword_parse', __( 'Failed to parse keyword suggestions from AI response.', 'stratawp-seo' ) );
    }

    /**
     * Find the linked post_id for a keyword from tracked data.
     */
    private function find_post_for_keyword( string $keyword, array $tracked ): int {
        foreach ( $tracked as $row ) {
            if ( $row['keyword'] === $keyword && ! empty( $row['post_id'] ) ) {
                return (int) $row['post_id'];
            }
        }
        return 0;
    }

    /**
     * Drop the keyword tracking table. Called on uninstall.
     */
    public static function drop_tables(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::TABLE );
    }
}
```

**Verify:** `php -l includes/class-keyword-tracker.php`

**Commit:** `git add includes/class-keyword-tracker.php && git commit -m "feat: Add SWPS_Keyword_Tracker with DB table, CRUD, GSC sync, and AI suggestions"`

---

### Task 2: Keywords Admin Page — Template & AJAX

**Files:**
- Create: `templates/keywords-page.php`
- Create: `includes/class-keywords-page.php`

**Context:** Admin page registered under the StrataWP SEO menu. Shows: AI suggestion form, tracked keywords table with position/change/clicks/impressions, opportunities section. Uses AJAX to load data and manage keywords. Follows the same pattern as `SWPS_Analytics_Dashboard` — class registers menu + AJAX handlers, template renders the HTML.

**Implementation for `includes/class-keywords-page.php`:**

```php
<?php
/**
 * Keywords admin page — AI suggestions, tracked keywords, opportunities.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Keywords_Page {

    private SWPS_Keyword_Tracker $tracker;

    public function __construct( SWPS_Keyword_Tracker $tracker ) {
        $this->tracker = $tracker;

        // Register after parent menu (priority 20).
        add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );

        // AJAX endpoints.
        add_action( 'wp_ajax_swps_suggest_keywords', [ $this, 'ajax_suggest' ] );
        add_action( 'wp_ajax_swps_track_keyword', [ $this, 'ajax_track' ] );
        add_action( 'wp_ajax_swps_untrack_keyword', [ $this, 'ajax_untrack' ] );
        add_action( 'wp_ajax_swps_link_keyword', [ $this, 'ajax_link' ] );
        add_action( 'wp_ajax_swps_keyword_history', [ $this, 'ajax_history' ] );
        add_action( 'wp_ajax_swps_get_keywords', [ $this, 'ajax_get_keywords' ] );
        add_action( 'wp_ajax_swps_get_opportunities', [ $this, 'ajax_get_opportunities' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'stratawp-seo',
            __( 'Keywords', 'stratawp-seo' ),
            __( 'Keywords', 'stratawp-seo' ),
            'manage_options',
            'swps-keywords',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $gsc_connected = stratawp_seo()->search_console->is_connected();
        include SWPS_PLUGIN_DIR . 'templates/keywords-page.php';
    }

    public function ajax_suggest(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $seed = sanitize_text_field( $_POST['seed_topic'] ?? '' );
        if ( empty( $seed ) ) {
            wp_send_json_error( [ 'message' => 'Please enter a seed topic.' ] );
        }

        $suggestions = $this->tracker->suggest_keywords( $seed );
        if ( is_wp_error( $suggestions ) ) {
            wp_send_json_error( [ 'message' => $suggestions->get_error_message() ] );
        }

        wp_send_json_success( $suggestions );
    }

    public function ajax_track(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
        $post_id = absint( $_POST['post_id'] ?? 0 );

        if ( empty( $keyword ) ) {
            wp_send_json_error( [ 'message' => 'Keyword is required.' ] );
        }

        $this->tracker->track_keyword( $keyword, $post_id );
        wp_send_json_success();
    }

    public function ajax_untrack(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
        $this->tracker->untrack_keyword( $keyword );
        wp_send_json_success();
    }

    public function ajax_link(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
        $post_id = absint( $_POST['post_id'] ?? 0 );
        $this->tracker->link_to_post( $keyword, $post_id );
        wp_send_json_success();
    }

    public function ajax_history(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
        $days    = absint( $_POST['days'] ?? 90 );
        $history = $this->tracker->get_keyword_history( $keyword, $days );
        wp_send_json_success( $history );
    }

    public function ajax_get_keywords(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $keywords = $this->tracker->get_tracked_keywords();

        // Enrich with post titles.
        foreach ( $keywords as &$kw ) {
            if ( ! empty( $kw['post_id'] ) ) {
                $post = get_post( (int) $kw['post_id'] );
                $kw['post_title'] = $post ? $post->post_title : '';
                $kw['post_url']   = $post ? get_edit_post_link( $post->ID, 'raw' ) : '';
            }
        }

        wp_send_json_success( $keywords );
    }

    public function ajax_get_opportunities(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $opportunities = $this->tracker->get_opportunities();

        foreach ( $opportunities as &$opp ) {
            if ( ! empty( $opp['post_id'] ) ) {
                $post = get_post( (int) $opp['post_id'] );
                $opp['post_title'] = $post ? $post->post_title : '';
            }
        }

        wp_send_json_success( $opportunities );
    }
}
```

**Implementation for `templates/keywords-page.php`:**

```php
<?php
/**
 * Keywords page template.
 *
 * @var bool $gsc_connected Whether GSC is connected.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-keywords-wrap">
    <h1><?php esc_html_e( 'Keyword Research & Tracking', 'stratawp-seo' ); ?></h1>

    <!-- AI Suggestion Panel -->
    <div class="swps-keywords-section swps-suggest-panel">
        <h2><?php esc_html_e( 'Discover Keywords', 'stratawp-seo' ); ?></h2>
        <div class="swps-suggest-form">
            <input type="text" id="swps-seed-topic" class="regular-text"
                   placeholder="<?php esc_attr_e( 'Enter a seed topic (e.g., home renovation tips)', 'stratawp-seo' ); ?>" />
            <button class="button button-primary" id="swps-suggest-btn">
                <?php esc_html_e( 'Get AI Suggestions', 'stratawp-seo' ); ?>
            </button>
            <span class="spinner" id="swps-suggest-spinner"></span>
        </div>
        <table class="widefat striped" id="swps-suggestions-table" style="display:none;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Keyword', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Intent', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Difficulty', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Suggested Title', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <!-- Tracked Keywords -->
    <div class="swps-keywords-section">
        <h2><?php esc_html_e( 'Tracked Keywords', 'stratawp-seo' ); ?></h2>
        <table class="widefat striped" id="swps-tracked-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Keyword', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Clicks', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'CTR', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Linked Post', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="7" class="swps-loading"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <?php if ( $gsc_connected ) : ?>
    <!-- Opportunities -->
    <div class="swps-keywords-section">
        <h2><?php esc_html_e( 'Opportunities (Striking Distance)', 'stratawp-seo' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Keywords ranking position 8-20 with high impressions — optimize these for quick wins.', 'stratawp-seo' ); ?></p>
        <table class="widefat striped" id="swps-opportunities-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Keyword', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'CTR', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="5" class="swps-loading"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Keyword History Modal -->
    <div id="swps-keyword-history-modal" class="swps-modal" style="display:none;">
        <div class="swps-modal-content">
            <span class="swps-modal-close">&times;</span>
            <h3 id="swps-history-title"></h3>
            <div id="swps-history-chart" class="swps-chart"></div>
        </div>
    </div>
</div>
```

**Verify:** `php -l includes/class-keywords-page.php && php -l templates/keywords-page.php`

**Commit:** `git add includes/class-keywords-page.php templates/keywords-page.php && git commit -m "feat: Add Keywords admin page with AJAX endpoints and template"`

---

### Task 3: Keywords Page JavaScript

**Files:**
- Create: `admin/js/keywords.js`

**Context:** jQuery-based JS for the keywords admin page. Loads tracked keywords and opportunities via AJAX on page ready, handles AI suggestion form, track/untrack/link buttons, and keyword history chart modal. Reuses SVG chart pattern from `admin/js/analytics.js`. Depends on `swpsAdmin` localized data (ajax_url, nonce) from `swps-admin` script.

**Implementation:**

```js
/**
 * StrataWP SEO — Keywords Page JS
 */
(function ($) {
    'use strict';

    if (typeof swpsAdmin === 'undefined') return;

    // --- Load tracked keywords ---
    function loadTracked() {
        $.post(swpsAdmin.ajax_url, {
            action: 'swps_get_keywords',
            nonce: swpsAdmin.nonce
        }, function (res) {
            if (!res.success) return;
            var tbody = $('#swps-tracked-table tbody');
            tbody.empty();

            if (!res.data.length) {
                tbody.html('<tr><td colspan="7">No tracked keywords yet. Use AI suggestions above to discover and track keywords.</td></tr>');
                return;
            }

            res.data.forEach(function (kw) {
                var postLink = kw.post_title
                    ? '<a href="' + kw.post_url + '">' + escHtml(kw.post_title) + '</a>'
                    : '<em>None</em>';
                var pos = kw.position !== null ? parseFloat(kw.position).toFixed(1) : '—';
                var row = '<tr>';
                row += '<td><a href="#" class="swps-kw-history" data-keyword="' + escAttr(kw.keyword) + '">' + escHtml(kw.keyword) + '</a></td>';
                row += '<td>' + pos + '</td>';
                row += '<td>' + parseInt(kw.clicks).toLocaleString() + '</td>';
                row += '<td>' + parseInt(kw.impressions).toLocaleString() + '</td>';
                row += '<td>' + parseFloat(kw.ctr).toFixed(1) + '%</td>';
                row += '<td>' + postLink + '</td>';
                row += '<td><button class="button button-small swps-untrack-btn" data-keyword="' + escAttr(kw.keyword) + '">Untrack</button></td>';
                row += '</tr>';
                tbody.append(row);
            });
        });
    }

    // --- Load opportunities ---
    function loadOpportunities() {
        if (!$('#swps-opportunities-table').length) return;
        $.post(swpsAdmin.ajax_url, {
            action: 'swps_get_opportunities',
            nonce: swpsAdmin.nonce
        }, function (res) {
            if (!res.success) return;
            var tbody = $('#swps-opportunities-table tbody');
            tbody.empty();

            if (!res.data.length) {
                tbody.html('<tr><td colspan="5">No striking distance keywords found yet. Track keywords and sync with GSC.</td></tr>');
                return;
            }

            res.data.forEach(function (opp) {
                var row = '<tr>';
                row += '<td>' + escHtml(opp.keyword) + '</td>';
                row += '<td>' + parseFloat(opp.position).toFixed(1) + '</td>';
                row += '<td>' + parseInt(opp.impressions).toLocaleString() + '</td>';
                row += '<td>' + parseFloat(opp.ctr).toFixed(1) + '%</td>';
                row += '<td>';
                row += '<a href="' + swpsAdmin.generate_url + '&topic=' + encodeURIComponent(opp.keyword) + '" class="button button-small">Generate Post</a>';
                row += '</td>';
                row += '</tr>';
                tbody.append(row);
            });
        });
    }

    // --- AI Suggestions ---
    $('#swps-suggest-btn').on('click', function () {
        var seed = $('#swps-seed-topic').val().trim();
        if (!seed) return;

        var btn = $(this);
        var spinner = $('#swps-suggest-spinner');
        btn.prop('disabled', true);
        spinner.addClass('is-active');

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_suggest_keywords',
            nonce: swpsAdmin.nonce,
            seed_topic: seed
        }, function (res) {
            btn.prop('disabled', false);
            spinner.removeClass('is-active');

            if (!res.success) {
                alert(res.data.message || 'Failed to get suggestions.');
                return;
            }

            var table = $('#swps-suggestions-table');
            var tbody = table.find('tbody');
            tbody.empty();
            table.show();

            res.data.forEach(function (s) {
                var row = '<tr>';
                row += '<td><strong>' + escHtml(s.keyword) + '</strong></td>';
                row += '<td><span class="swps-intent-badge swps-intent-' + escAttr(s.intent) + '">' + escHtml(s.intent) + '</span></td>';
                row += '<td>' + escHtml(s.difficulty) + '</td>';
                row += '<td>' + escHtml(s.suggested_title) + '</td>';
                row += '<td><button class="button button-small swps-track-btn" data-keyword="' + escAttr(s.keyword) + '">Track</button></td>';
                row += '</tr>';
                tbody.append(row);
            });
        });
    });

    // --- Track keyword ---
    $(document).on('click', '.swps-track-btn', function () {
        var btn = $(this);
        var keyword = btn.data('keyword');
        btn.prop('disabled', true).text('Tracking...');

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_track_keyword',
            nonce: swpsAdmin.nonce,
            keyword: keyword
        }, function () {
            btn.text('Tracked').addClass('disabled');
            loadTracked();
        });
    });

    // --- Untrack keyword ---
    $(document).on('click', '.swps-untrack-btn', function () {
        var keyword = $(this).data('keyword');
        if (!confirm('Stop tracking "' + keyword + '"?')) return;

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_untrack_keyword',
            nonce: swpsAdmin.nonce,
            keyword: keyword
        }, function () { loadTracked(); });
    });

    // --- Keyword history modal ---
    $(document).on('click', '.swps-kw-history', function (e) {
        e.preventDefault();
        var keyword = $(this).data('keyword');
        $('#swps-history-title').text(keyword);
        $('#swps-keyword-history-modal').show();

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_keyword_history',
            nonce: swpsAdmin.nonce,
            keyword: keyword,
            days: 90
        }, function (res) {
            if (!res.success || !res.data.length) {
                $('#swps-history-chart').html('<p>No history data yet.</p>');
                return;
            }
            renderPositionChart(res.data);
        });
    });

    $('.swps-modal-close').on('click', function () {
        $(this).closest('.swps-modal').hide();
    });

    // --- Position chart (SVG) ---
    function renderPositionChart(data) {
        var container = document.getElementById('swps-history-chart');
        if (!container) return;

        var width = container.offsetWidth || 600;
        var height = 200;
        var pad = { top: 20, right: 20, bottom: 30, left: 50 };
        var cW = width - pad.left - pad.right;
        var cH = height - pad.top - pad.bottom;

        // Position is inverted — lower is better.
        var positions = data.map(function (d) { return parseFloat(d.position) || 100; });
        var maxPos = Math.max.apply(null, positions);
        var minPos = Math.min.apply(null, positions);
        if (maxPos === minPos) maxPos = minPos + 10;

        var points = data.map(function (d, i) {
            var x = pad.left + (i / Math.max(data.length - 1, 1)) * cW;
            var pos = parseFloat(d.position) || 100;
            var y = pad.top + ((pos - minPos) / (maxPos - minPos)) * cH;
            return x + ',' + y;
        });

        var svg = '<svg width="' + width + '" height="' + height + '" xmlns="http://www.w3.org/2000/svg">';
        svg += '<polyline points="' + points.join(' ') + '" fill="none" stroke="#2271b1" stroke-width="2" />';

        // Y-axis: position labels (inverted).
        for (var i = 0; i <= 4; i++) {
            var yVal = Math.round(minPos + ((maxPos - minPos) / 4) * i);
            var yPos = pad.top + (i / 4) * cH;
            svg += '<text x="' + (pad.left - 8) + '" y="' + (yPos + 4) + '" text-anchor="end" fill="#646970" font-size="11">#' + yVal + '</text>';
            svg += '<line x1="' + pad.left + '" y1="' + yPos + '" x2="' + (width - pad.right) + '" y2="' + yPos + '" stroke="#e0e0e0" />';
        }

        svg += '</svg>';
        container.innerHTML = svg;
    }

    // --- Helpers ---
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function escAttr(str) {
        return (str || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // --- Init ---
    $(document).ready(function () {
        if ($('.swps-keywords-wrap').length) {
            loadTracked();
            loadOpportunities();
        }
    });

})(jQuery);
```

**Verify:** File created, no PHP syntax to check (JS file).

**Commit:** `git add admin/js/keywords.js && git commit -m "feat: Add keywords page JavaScript (AI suggestions, tracking, history chart)"`

---

### Task 4: Meta Editor — Core Class with Frontend Output

**Files:**
- Create: `includes/class-meta-editor.php`

**Context:** Handles saving/retrieving per-post SEO meta fields, outputs `<title>`, `<meta>`, OG, Twitter Card, canonical, and robots tags in `wp_head`. Auto-disables when Yoast/RankMath/AIOSEO detected (same pattern as `SWPS_Schema` line 36). The existing `SWPS_OpenGraph_Module::output_meta_tags()` handles OG output for the audit — the meta editor should take precedence when its fields are populated, and coordinate with the OG module to avoid duplicates.

**Implementation:**

```php
<?php
/**
 * SEO Meta Editor — per-post meta fields and frontend output.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Meta_Editor {

    private bool $conflict = false;

    public function __construct() {
        // Detect conflicting SEO plugins.
        if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
            $this->conflict = true;
        }

        if ( ! get_option( 'swps_meta_editor_enabled', 1 ) ) {
            return;
        }

        // Admin: register metabox and save hook.
        add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );
        add_action( 'save_post', [ $this, 'save_meta' ], 10, 2 );

        // AJAX: AI-generate meta.
        add_action( 'wp_ajax_swps_generate_meta', [ $this, 'ajax_generate_meta' ] );

        // Frontend output — only when no conflict.
        if ( ! $this->conflict && ! is_admin() ) {
            add_action( 'wp_head', [ $this, 'output_meta_tags' ], 2 );
            add_filter( 'pre_get_document_title', [ $this, 'filter_document_title' ], 20 );
            add_filter( 'document_title_parts', [ $this, 'filter_title_parts' ], 20 );
        }

        // Admin notice if conflict detected.
        if ( $this->conflict ) {
            add_action( 'admin_notices', [ $this, 'conflict_notice' ] );
        }
    }

    /**
     * Check if there's an SEO plugin conflict.
     */
    public function has_conflict(): bool {
        return $this->conflict;
    }

    /**
     * Register the SEO metabox on configured post types.
     */
    public function register_metabox(): void {
        $post_types = $this->get_enabled_post_types();

        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'swps_meta_editor',
                __( 'StrataWP SEO', 'stratawp-seo' ),
                [ $this, 'render_metabox' ],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    /**
     * Render the SEO metabox.
     */
    public function render_metabox( WP_Post $post ): void {
        wp_nonce_field( 'swps_meta_editor', 'swps_meta_editor_nonce' );

        $meta_title        = get_post_meta( $post->ID, '_swps_meta_title', true );
        $meta_description  = get_post_meta( $post->ID, '_swps_meta_description', true );
        $focus_keyword     = get_post_meta( $post->ID, '_swps_focus_keyword', true );
        $secondary_kws     = get_post_meta( $post->ID, '_swps_secondary_keywords', true );
        $canonical_url     = get_post_meta( $post->ID, '_swps_canonical_url', true );
        $robots            = get_post_meta( $post->ID, '_swps_robots', true );
        $breadcrumb_title  = get_post_meta( $post->ID, '_swps_breadcrumb_title', true );
        $social_title      = get_post_meta( $post->ID, '_swps_social_title', true );
        $social_desc       = get_post_meta( $post->ID, '_swps_social_description', true );
        $social_image      = get_post_meta( $post->ID, '_swps_social_image', true );

        if ( $this->conflict ) {
            echo '<div class="notice notice-warning inline"><p>';
            esc_html_e( 'Meta output is disabled because another SEO plugin is active. Fields are saved but not output on the frontend.', 'stratawp-seo' );
            echo '</p></div>';
        }

        include SWPS_PLUGIN_DIR . 'templates/meta-editor-metabox.php';
    }

    /**
     * Save meta fields on post save.
     */
    public function save_meta( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST['swps_meta_editor_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['swps_meta_editor_nonce'], 'swps_meta_editor' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $fields = [
            '_swps_meta_title'          => 'sanitize_text_field',
            '_swps_meta_description'    => 'sanitize_textarea_field',
            '_swps_focus_keyword'       => 'sanitize_text_field',
            '_swps_secondary_keywords'  => 'sanitize_text_field',
            '_swps_canonical_url'       => 'esc_url_raw',
            '_swps_robots'              => 'sanitize_text_field',
            '_swps_breadcrumb_title'    => 'sanitize_text_field',
            '_swps_social_title'        => 'sanitize_text_field',
            '_swps_social_description'  => 'sanitize_textarea_field',
            '_swps_social_image'        => 'esc_url_raw',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            if ( isset( $_POST[ $key ] ) ) {
                $value = call_user_func( $sanitizer, $_POST[ $key ] );
                if ( ! empty( $value ) ) {
                    update_post_meta( $post_id, $key, $value );
                } else {
                    delete_post_meta( $post_id, $key );
                }
            }
        }
    }

    /**
     * Output meta tags in wp_head.
     */
    public function output_meta_tags(): void {
        if ( ! is_singular() ) {
            return;
        }

        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return;
        }

        $meta_desc  = get_post_meta( $post_id, '_swps_meta_description', true );
        $canonical  = get_post_meta( $post_id, '_swps_canonical_url', true );
        $robots     = get_post_meta( $post_id, '_swps_robots', true );

        // Meta description.
        $meta_desc = apply_filters( 'swps_meta_description', $meta_desc, $post_id );
        if ( ! empty( $meta_desc ) ) {
            printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $meta_desc ) );
        }

        // Canonical.
        if ( ! empty( $canonical ) ) {
            printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
            // Remove WP default canonical.
            remove_action( 'wp_head', 'rel_canonical' );
        }

        // Robots.
        $robots = apply_filters( 'swps_meta_robots', $robots, $post_id );
        if ( ! empty( $robots ) && 'index, follow' !== $robots ) {
            printf( '<meta name="robots" content="%s" />' . "\n", esc_attr( $robots ) );
        }

        // Open Graph.
        $this->output_og_tags( $post_id );

        // Twitter Card.
        $this->output_twitter_tags( $post_id );
    }

    /**
     * Filter the document title for our custom meta title.
     */
    public function filter_document_title( string $title ): string {
        if ( ! is_singular() ) {
            return $title;
        }

        $custom = get_post_meta( get_the_ID(), '_swps_meta_title', true );
        $custom = apply_filters( 'swps_meta_title', $custom, get_the_ID() );

        return ! empty( $custom ) ? $custom : $title;
    }

    /**
     * Filter document title parts.
     */
    public function filter_title_parts( array $parts ): array {
        if ( ! is_singular() ) {
            return $parts;
        }

        $custom = get_post_meta( get_the_ID(), '_swps_meta_title', true );
        $custom = apply_filters( 'swps_meta_title', $custom, get_the_ID() );

        if ( ! empty( $custom ) ) {
            $parts['title'] = $custom;
            // Remove site name suffix if custom title is set.
            unset( $parts['site'] );
        }

        return $parts;
    }

    /**
     * AJAX: Generate meta title and description using AI.
     */
    public function ajax_generate_meta(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $post_id       = absint( $_POST['post_id'] ?? 0 );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => 'Post not found.' ] );
        }

        $content = wp_strip_all_tags( $post->post_content );
        $content = mb_substr( $content, 0, 2000 ); // Limit to keep prompt short.

        $prompt = sprintf(
            "Generate an SEO-optimized meta title (50-60 chars) and meta description (140-160 chars) for this blog post.\n\n"
            . "Post title: %s\n"
            . "Focus keyword: %s\n"
            . "Content excerpt: %s\n\n"
            . "Requirements:\n"
            . "- Include the focus keyword naturally in both title and description\n"
            . "- Meta title: compelling, under 60 characters\n"
            . "- Meta description: actionable, includes a call to read, under 160 characters\n\n"
            . "Return JSON only: {\"meta_title\":\"...\",\"meta_description\":\"...\"}",
            $post->post_title,
            $focus_keyword ?: '(none specified)',
            $content
        );

        $api      = SWPS_Provider_Factory::create_ai_provider();
        $response = $api->generate( $prompt, 'You are an SEO copywriting expert. Return only valid JSON.' );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $text = $response['content'] ?? '';
        $json_match = [];
        if ( preg_match( '/\{.*\}/s', $text, $json_match ) ) {
            $result = json_decode( $json_match[0], true );
            if ( is_array( $result ) ) {
                wp_send_json_success( $result );
            }
        }

        wp_send_json_error( [ 'message' => 'Failed to parse AI response.' ] );
    }

    /**
     * Show admin notice when SEO plugin conflict detected.
     */
    public function conflict_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'stratawp-seo' ) === false ) {
            return;
        }

        echo '<div class="notice notice-info"><p>';
        esc_html_e( 'StrataWP SEO: Meta tag output is disabled because another SEO plugin (Yoast, RankMath, or AIOSEO) is active. The meta editor fields are still saved for reference.', 'stratawp-seo' );
        echo '</p></div>';
    }

    /**
     * Get post types where the meta editor is enabled.
     */
    private function get_enabled_post_types(): array {
        $saved = get_option( 'swps_meta_editor_post_types', '' );
        if ( ! empty( $saved ) ) {
            return array_map( 'sanitize_text_field', explode( ',', $saved ) );
        }
        return [ 'post', 'page' ];
    }

    /**
     * Output Open Graph tags.
     */
    private function output_og_tags( int $post_id ): void {
        $title = get_post_meta( $post_id, '_swps_social_title', true )
              ?: get_post_meta( $post_id, '_swps_meta_title', true )
              ?: get_the_title( $post_id );

        $desc = get_post_meta( $post_id, '_swps_social_description', true )
             ?: get_post_meta( $post_id, '_swps_meta_description', true )
             ?: get_the_excerpt( $post_id );

        $image = get_post_meta( $post_id, '_swps_social_image', true )
              ?: get_the_post_thumbnail_url( $post_id, 'large' );

        printf( '<meta property="og:type" content="article" />' . "\n" );
        printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
        printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $desc ) );
        printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( get_permalink( $post_id ) ) );
        if ( $image ) {
            printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
        }
        printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
    }

    /**
     * Output Twitter Card tags.
     */
    private function output_twitter_tags( int $post_id ): void {
        $title = get_post_meta( $post_id, '_swps_social_title', true )
              ?: get_post_meta( $post_id, '_swps_meta_title', true )
              ?: get_the_title( $post_id );

        $desc = get_post_meta( $post_id, '_swps_social_description', true )
             ?: get_post_meta( $post_id, '_swps_meta_description', true )
             ?: get_the_excerpt( $post_id );

        $image = get_post_meta( $post_id, '_swps_social_image', true )
              ?: get_the_post_thumbnail_url( $post_id, 'large' );

        printf( '<meta name="twitter:card" content="%s" />' . "\n", $image ? 'summary_large_image' : 'summary' );
        printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );
        printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $desc ) );
        if ( $image ) {
            printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $image ) );
        }
    }
}
```

**Verify:** `php -l includes/class-meta-editor.php`

**Commit:** `git add includes/class-meta-editor.php && git commit -m "feat: Add SWPS_Meta_Editor with per-post fields, frontend output, and conflict detection"`

---

### Task 5: Meta Editor Metabox Template

**Files:**
- Create: `templates/meta-editor-metabox.php`

**Context:** Renders the SEO metabox inside the post editor. Variables `$meta_title`, `$meta_description`, `$focus_keyword`, etc. are set by `SWPS_Meta_Editor::render_metabox()` before this template is included. Includes: Google SERP preview (live-updating), character counters, social preview tabs, SEO checklist, and AI Generate button.

**Implementation:**

```php
<?php
/**
 * Meta editor metabox template.
 *
 * Variables are set by SWPS_Meta_Editor::render_metabox().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="swps-meta-editor" data-post-id="<?php echo esc_attr( $post->ID ); ?>">

    <!-- Google Preview -->
    <div class="swps-serp-preview">
        <h4><?php esc_html_e( 'Google Preview', 'stratawp-seo' ); ?></h4>
        <div class="swps-serp-mock">
            <div class="swps-serp-title" id="swps-serp-title"><?php echo esc_html( $meta_title ?: $post->post_title ); ?></div>
            <div class="swps-serp-url"><?php echo esc_url( get_permalink( $post->ID ) ); ?></div>
            <div class="swps-serp-desc" id="swps-serp-desc"><?php echo esc_html( $meta_description ?: wp_trim_words( $post->post_content, 25, '...' ) ); ?></div>
        </div>
    </div>

    <!-- Focus Keyword -->
    <div class="swps-meta-row">
        <label for="_swps_focus_keyword"><strong><?php esc_html_e( 'Focus Keyword', 'stratawp-seo' ); ?></strong></label>
        <input type="text" name="_swps_focus_keyword" id="_swps_focus_keyword"
               value="<?php echo esc_attr( $focus_keyword ); ?>" class="widefat"
               placeholder="<?php esc_attr_e( 'e.g., best running shoes', 'stratawp-seo' ); ?>" />
    </div>

    <div class="swps-meta-row">
        <label for="_swps_secondary_keywords"><?php esc_html_e( 'Secondary Keywords', 'stratawp-seo' ); ?></label>
        <input type="text" name="_swps_secondary_keywords" id="_swps_secondary_keywords"
               value="<?php echo esc_attr( $secondary_kws ); ?>" class="widefat"
               placeholder="<?php esc_attr_e( 'Comma-separated: running gear, marathon training', 'stratawp-seo' ); ?>" />
    </div>

    <!-- Meta Title -->
    <div class="swps-meta-row">
        <label for="_swps_meta_title">
            <?php esc_html_e( 'Meta Title', 'stratawp-seo' ); ?>
            <span class="swps-char-count" id="swps-title-count">0</span>
        </label>
        <input type="text" name="_swps_meta_title" id="_swps_meta_title"
               value="<?php echo esc_attr( $meta_title ); ?>" class="widefat"
               placeholder="<?php echo esc_attr( $post->post_title ); ?>" />
        <button type="button" class="button button-small" id="swps-ai-generate-meta">
            <?php esc_html_e( 'AI Generate', 'stratawp-seo' ); ?>
        </button>
    </div>

    <!-- Meta Description -->
    <div class="swps-meta-row">
        <label for="_swps_meta_description">
            <?php esc_html_e( 'Meta Description', 'stratawp-seo' ); ?>
            <span class="swps-char-count" id="swps-desc-count">0</span>
        </label>
        <textarea name="_swps_meta_description" id="_swps_meta_description" class="widefat" rows="3"
                  placeholder="<?php esc_attr_e( 'Compelling description for search results...', 'stratawp-seo' ); ?>"><?php echo esc_textarea( $meta_description ); ?></textarea>
    </div>

    <!-- SEO Checklist -->
    <div class="swps-seo-checklist" id="swps-seo-checklist">
        <h4><?php esc_html_e( 'SEO Checklist', 'stratawp-seo' ); ?></h4>
        <ul>
            <li data-check="keyword-in-title"><span class="swps-check-icon"></span> <?php esc_html_e( 'Focus keyword in meta title', 'stratawp-seo' ); ?></li>
            <li data-check="keyword-in-desc"><span class="swps-check-icon"></span> <?php esc_html_e( 'Focus keyword in meta description', 'stratawp-seo' ); ?></li>
            <li data-check="title-length"><span class="swps-check-icon"></span> <?php esc_html_e( 'Meta title length (50-60 chars)', 'stratawp-seo' ); ?></li>
            <li data-check="desc-length"><span class="swps-check-icon"></span> <?php esc_html_e( 'Meta description length (140-160 chars)', 'stratawp-seo' ); ?></li>
            <li data-check="keyword-in-content"><span class="swps-check-icon"></span> <?php esc_html_e( 'Focus keyword in first paragraph', 'stratawp-seo' ); ?></li>
            <li data-check="keyword-in-h2"><span class="swps-check-icon"></span> <?php esc_html_e( 'Focus keyword in at least one H2', 'stratawp-seo' ); ?></li>
        </ul>
    </div>

    <!-- Advanced: Canonical, Robots, Breadcrumb -->
    <details class="swps-meta-advanced">
        <summary><?php esc_html_e( 'Advanced', 'stratawp-seo' ); ?></summary>

        <div class="swps-meta-row">
            <label for="_swps_canonical_url"><?php esc_html_e( 'Canonical URL', 'stratawp-seo' ); ?></label>
            <input type="url" name="_swps_canonical_url" id="_swps_canonical_url"
                   value="<?php echo esc_url( $canonical_url ); ?>" class="widefat"
                   placeholder="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" />
        </div>

        <div class="swps-meta-row">
            <label for="_swps_robots"><?php esc_html_e( 'Robots Meta', 'stratawp-seo' ); ?></label>
            <select name="_swps_robots" id="_swps_robots">
                <option value="" <?php selected( $robots, '' ); ?>><?php esc_html_e( 'Default (index, follow)', 'stratawp-seo' ); ?></option>
                <option value="noindex, follow" <?php selected( $robots, 'noindex, follow' ); ?>><?php esc_html_e( 'noindex, follow', 'stratawp-seo' ); ?></option>
                <option value="index, nofollow" <?php selected( $robots, 'index, nofollow' ); ?>><?php esc_html_e( 'index, nofollow', 'stratawp-seo' ); ?></option>
                <option value="noindex, nofollow" <?php selected( $robots, 'noindex, nofollow' ); ?>><?php esc_html_e( 'noindex, nofollow', 'stratawp-seo' ); ?></option>
            </select>
        </div>

        <div class="swps-meta-row">
            <label for="_swps_breadcrumb_title"><?php esc_html_e( 'Breadcrumb Title', 'stratawp-seo' ); ?></label>
            <input type="text" name="_swps_breadcrumb_title" id="_swps_breadcrumb_title"
                   value="<?php echo esc_attr( $breadcrumb_title ); ?>" class="widefat"
                   placeholder="<?php echo esc_attr( $post->post_title ); ?>" />
        </div>
    </details>

    <!-- Social Preview -->
    <details class="swps-meta-social">
        <summary><?php esc_html_e( 'Social Previews', 'stratawp-seo' ); ?></summary>

        <div class="swps-meta-row">
            <label for="_swps_social_title"><?php esc_html_e( 'Social Title', 'stratawp-seo' ); ?></label>
            <input type="text" name="_swps_social_title" id="_swps_social_title"
                   value="<?php echo esc_attr( $social_title ); ?>" class="widefat"
                   placeholder="<?php esc_attr_e( 'Falls back to meta title', 'stratawp-seo' ); ?>" />
        </div>

        <div class="swps-meta-row">
            <label for="_swps_social_description"><?php esc_html_e( 'Social Description', 'stratawp-seo' ); ?></label>
            <textarea name="_swps_social_description" id="_swps_social_description" class="widefat" rows="2"
                      placeholder="<?php esc_attr_e( 'Falls back to meta description', 'stratawp-seo' ); ?>"><?php echo esc_textarea( $social_desc ); ?></textarea>
        </div>

        <div class="swps-meta-row">
            <label for="_swps_social_image"><?php esc_html_e( 'Social Image URL', 'stratawp-seo' ); ?></label>
            <input type="url" name="_swps_social_image" id="_swps_social_image"
                   value="<?php echo esc_url( $social_image ); ?>" class="widefat"
                   placeholder="<?php esc_attr_e( 'Falls back to featured image', 'stratawp-seo' ); ?>" />
        </div>

        <!-- Facebook Preview Mock -->
        <div class="swps-social-preview swps-fb-preview">
            <h5><?php esc_html_e( 'Facebook Preview', 'stratawp-seo' ); ?></h5>
            <div class="swps-social-card">
                <div class="swps-social-image" id="swps-fb-image"></div>
                <div class="swps-social-text">
                    <div class="swps-social-domain"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
                    <div class="swps-social-title" id="swps-fb-title"></div>
                    <div class="swps-social-desc" id="swps-fb-desc"></div>
                </div>
            </div>
        </div>
    </details>
</div>
```

**Verify:** `php -l templates/meta-editor-metabox.php`

**Commit:** `git add templates/meta-editor-metabox.php && git commit -m "feat: Add meta editor metabox template with SERP preview, checklist, and social previews"`

---

### Task 6: Meta Editor JavaScript

**Files:**
- Create: `admin/js/meta-editor.js`

**Context:** Runs on post edit screens. Handles: live SERP preview (updates as user types), character counter colors (green 50-60 / 140-160, yellow near, red over), SEO checklist evaluation, AI generate button, and social preview updates. Depends on `swpsAdmin` localized data. Reads post content from the WordPress editor (classic or block).

**Implementation:**

```js
/**
 * StrataWP SEO — Meta Editor JS
 *
 * Live SERP preview, character counters, SEO checklist, AI generate.
 */
(function ($) {
    'use strict';

    if (typeof swpsAdmin === 'undefined') return;

    var $metaTitle, $metaDesc, $focusKw, $serpTitle, $serpDesc;
    var $titleCount, $descCount;

    function init() {
        $metaTitle  = $('#_swps_meta_title');
        $metaDesc   = $('#_swps_meta_description');
        $focusKw    = $('#_swps_focus_keyword');
        $serpTitle   = $('#swps-serp-title');
        $serpDesc    = $('#swps-serp-desc');
        $titleCount  = $('#swps-title-count');
        $descCount   = $('#swps-desc-count');

        if (!$metaTitle.length) return;

        // Live preview.
        $metaTitle.on('input', updatePreview);
        $metaDesc.on('input', updatePreview);
        $focusKw.on('input', updateChecklist);

        // Initial state.
        updatePreview();
        updateChecklist();

        // AI Generate.
        $('#swps-ai-generate-meta').on('click', aiGenerate);

        // Social preview updates.
        $('#_swps_social_title, #_swps_social_description, #_swps_social_image').on('input', updateSocialPreview);
        updateSocialPreview();
    }

    function updatePreview() {
        var title = $metaTitle.val() || $metaTitle.attr('placeholder') || '';
        var desc  = $metaDesc.val() || $metaDesc.attr('placeholder') || '';

        // Truncate for display.
        $serpTitle.text(title.length > 60 ? title.substring(0, 57) + '...' : title);
        $serpDesc.text(desc.length > 160 ? desc.substring(0, 157) + '...' : desc);

        // Character counters.
        updateCounter($titleCount, $metaTitle.val().length, 50, 60);
        updateCounter($descCount, $metaDesc.val().length, 140, 160);

        updateChecklist();
    }

    function updateCounter($el, len, min, max) {
        $el.text(len + '/' + max);
        $el.removeClass('swps-count-green swps-count-yellow swps-count-red');
        if (len === 0) return;
        if (len >= min && len <= max) {
            $el.addClass('swps-count-green');
        } else if (len > max) {
            $el.addClass('swps-count-red');
        } else {
            $el.addClass('swps-count-yellow');
        }
    }

    function updateChecklist() {
        var keyword = $focusKw.val().toLowerCase().trim();
        if (!keyword) {
            $('#swps-seo-checklist li .swps-check-icon').html('—').attr('class', 'swps-check-icon swps-check-neutral');
            return;
        }

        var title     = $metaTitle.val().toLowerCase();
        var desc      = $metaDesc.val().toLowerCase();
        var titleLen  = $metaTitle.val().length;
        var descLen   = $metaDesc.val().length;

        // Get post content from editor.
        var content = getEditorContent().toLowerCase();
        var firstParagraph = content.split(/\n\n|\r\n\r\n/)[0] || '';

        // Get H2 headings.
        var h2s = content.match(/<h2[^>]*>(.*?)<\/h2>/gi) || [];
        var h2Text = h2s.join(' ').toLowerCase();

        setCheck('keyword-in-title', title.indexOf(keyword) !== -1);
        setCheck('keyword-in-desc', desc.indexOf(keyword) !== -1);
        setCheck('title-length', titleLen >= 50 && titleLen <= 60);
        setCheck('desc-length', descLen >= 140 && descLen <= 160);
        setCheck('keyword-in-content', firstParagraph.indexOf(keyword) !== -1);
        setCheck('keyword-in-h2', h2Text.indexOf(keyword) !== -1);
    }

    function setCheck(name, pass) {
        var $li = $('[data-check="' + name + '"]');
        var $icon = $li.find('.swps-check-icon');
        if (pass) {
            $icon.html('&#10003;').attr('class', 'swps-check-icon swps-check-pass');
        } else {
            $icon.html('&#10007;').attr('class', 'swps-check-icon swps-check-fail');
        }
    }

    function getEditorContent() {
        // Block editor.
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            var content = wp.data.select('core/editor').getEditedPostContent();
            if (content) return content;
        }
        // Classic editor.
        if (typeof tinymce !== 'undefined') {
            var editor = tinymce.get('content');
            if (editor) return editor.getContent();
        }
        // Fallback: textarea.
        var $textarea = $('#content');
        return $textarea.length ? $textarea.val() : '';
    }

    function aiGenerate() {
        var btn = $(this);
        var postId = $('.swps-meta-editor').data('post-id');
        var keyword = $focusKw.val();

        btn.prop('disabled', true).text('Generating...');

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_generate_meta',
            nonce: swpsAdmin.nonce,
            post_id: postId,
            focus_keyword: keyword
        }, function (res) {
            btn.prop('disabled', false).text('AI Generate');

            if (!res.success) {
                alert(res.data.message || 'Failed to generate meta.');
                return;
            }

            if (res.data.meta_title) {
                $metaTitle.val(res.data.meta_title);
            }
            if (res.data.meta_description) {
                $metaDesc.val(res.data.meta_description);
            }

            updatePreview();
        });
    }

    function updateSocialPreview() {
        var title = $('#_swps_social_title').val() || $metaTitle.val() || $metaTitle.attr('placeholder') || '';
        var desc  = $('#_swps_social_description').val() || $metaDesc.val() || '';
        var image = $('#_swps_social_image').val() || '';

        $('#swps-fb-title').text(title);
        $('#swps-fb-desc').text(desc);

        var $fbImage = $('#swps-fb-image');
        if (image) {
            $fbImage.css('background-image', 'url(' + image + ')').show();
        } else {
            $fbImage.hide();
        }
    }

    $(document).ready(init);

})(jQuery);
```

**Verify:** JS file, no syntax check needed.

**Commit:** `git add admin/js/meta-editor.js && git commit -m "feat: Add meta editor JavaScript (SERP preview, char counters, checklist, AI generate)"`

---

### Task 7: Settings, Hooks & Bootstrap Integration

**Files:**
- Modify: `includes/class-settings.php` — add SEO Meta and Keyword Tracking settings sections
- Modify: `includes/class-hooks.php` — add 5 new hook methods
- Modify: `stratawp-seo.php` — require new files, instantiate classes, enqueue scripts, add activation/deactivation hooks, bump version to 2.3.0

**Context for settings:** Add new settings fields between the Schema section and Advanced section. New "SEO Meta" section with: `meta_editor_enabled` (checkbox), `meta_editor_post_types` (text, comma-separated), `meta_auto_generate` (checkbox). Under existing Analytics section add: `keyword_tracking_frequency` (select: daily/weekly/monthly).

**Settings additions (add after Schema section, before Analytics section in `register_settings()`):**

```php
// --- SEO Meta Section ---
add_settings_section( 'swps_meta_section', __( 'SEO Meta', 'stratawp-seo' ), [ $this, 'render_meta_section' ], 'stratawp-seo' );

$this->add_field( 'meta_editor_enabled', __( 'Enable Meta Editor', 'stratawp-seo' ), 'checkbox', 'swps_meta_section', [
    'label' => __( 'Show SEO meta fields (title, description, social, robots) on post and page editors', 'stratawp-seo' ),
] );

$this->add_field( 'meta_editor_post_types', __( 'Post Types', 'stratawp-seo' ), 'text', 'swps_meta_section', [
    'placeholder' => 'post,page',
    'description' => __( 'Comma-separated post types to show the meta editor on. Default: post,page', 'stratawp-seo' ),
] );

$this->add_field( 'meta_auto_generate', __( 'Auto-Generate Meta', 'stratawp-seo' ), 'checkbox', 'swps_meta_section', [
    'label' => __( 'Automatically generate meta title and description via AI when publishing a post without them', 'stratawp-seo' ),
] );
```

Add `keyword_tracking_frequency` field to the Analytics section:

```php
$this->add_field( 'keyword_tracking_frequency', __( 'Keyword Sync Frequency', 'stratawp-seo' ), 'select', 'swps_analytics_section', [
    'options' => [
        'daily'   => __( 'Daily', 'stratawp-seo' ),
        'weekly'  => __( 'Weekly', 'stratawp-seo' ),
        'monthly' => __( 'Monthly', 'stratawp-seo' ),
    ],
    'description' => __( 'How often to sync tracked keyword positions from Google Search Console.', 'stratawp-seo' ),
] );
```

Add section description method:

```php
public function render_meta_section(): void {
    echo '<p>' . esc_html__( 'Per-post SEO meta fields for titles, descriptions, social previews, and robots directives. Auto-disabled when Yoast, RankMath, or AIOSEO is active.', 'stratawp-seo' ) . '</p>';
}
```

**Hooks additions (add before closing `}` of `SWPS_Hooks` class):**

```php
/**
 * Apply the meta title filter.
 */
public static function filter_meta_title( string $title, int $post_id ): string {
    return apply_filters( 'swps_meta_title', $title, $post_id );
}

/**
 * Apply the meta description filter.
 */
public static function filter_meta_description( string $description, int $post_id ): string {
    return apply_filters( 'swps_meta_description', $description, $post_id );
}

/**
 * Apply the meta robots filter.
 */
public static function filter_meta_robots( string $robots, int $post_id ): string {
    return apply_filters( 'swps_meta_robots', $robots, $post_id );
}

/**
 * Apply the keyword suggestions filter.
 */
public static function filter_keyword_suggestions( array $suggestions, string $seed ): array {
    return apply_filters( 'swps_keyword_suggestions', $suggestions, $seed );
}

/**
 * Apply the SEO checklist filter.
 */
public static function filter_seo_checklist( array $items, int $post_id ): array {
    return apply_filters( 'swps_seo_checklist', $items, $post_id );
}
```

**Main plugin file changes (`stratawp-seo.php`):**

1. Add require_once after Analytics section (~line 86):
```php
// Keywords & Meta Editor.
require_once SWPS_PLUGIN_DIR . 'includes/class-keyword-tracker.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-keywords-page.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-meta-editor.php';
```

2. Add public properties after `$analytics_dashboard` (~line 134):
```php
public SWPS_Keyword_Tracker $keyword_tracker;
public SWPS_Keywords_Page $keywords_page;
public SWPS_Meta_Editor $meta_editor;
```

3. Add instantiation after `$this->analytics_dashboard` (~line 163):
```php
$this->keyword_tracker = new SWPS_Keyword_Tracker( $this->search_console );
$this->keywords_page   = new SWPS_Keywords_Page( $this->keyword_tracker );
$this->meta_editor     = new SWPS_Meta_Editor();
```

4. Update `enqueue_admin_assets()` — add `swps-keywords` to the hook check (~line 273):
```php
&& ! str_contains( $hook, 'swps-keywords' )
```

5. Add keywords JS enqueue (after analytics JS block, ~line 311):
```php
// Keywords page JS.
if ( str_contains( $hook, 'swps-keywords' ) ) {
    wp_enqueue_script(
        'swps-keywords',
        SWPS_PLUGIN_URL . 'admin/js/keywords.js',
        [ 'jquery', 'swps-admin' ],
        SWPS_VERSION,
        true
    );

    wp_localize_script( 'swps-keywords', 'swpsKeywords', [
        'generate_url' => admin_url( 'admin.php?page=swps-generate' ),
    ] );
}

// Meta editor JS on post edit screens.
if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
    wp_enqueue_script(
        'swps-meta-editor',
        SWPS_PLUGIN_URL . 'admin/js/meta-editor.js',
        [ 'jquery', 'swps-admin' ],
        SWPS_VERSION,
        true
    );
}
```

6. Update `swpsAdmin` localize to include `generate_url`:
Add to the `wp_localize_script` array:
```php
'generate_url' => admin_url( 'admin.php?page=swps-generate' ),
```

7. Add activation defaults (in `swps_activate()` defaults array):
```php
// Keywords & Meta defaults.
'meta_editor_enabled'          => 1,
'meta_editor_post_types'       => 'post,page',
'meta_auto_generate'           => 0,
'keyword_tracking_frequency'   => 'weekly',
```

8. Add to activation function (after `SWPS_Search_Console::schedule_cron()`):
```php
SWPS_Keyword_Tracker::create_tables();
SWPS_Keyword_Tracker::schedule_cron();
```

9. Add to deactivation function:
```php
SWPS_Keyword_Tracker::unschedule_cron();
```

10. Bump version: Change `SWPS_VERSION` from `'2.2.0'` to `'2.3.0'` and update the plugin header Version to `2.3.0`.

11. Coordinate OG output: When meta editor is active and not in conflict, disable the audit module's OG output to prevent duplicates. Add after the existing `SWPS_OpenGraph_Module::output_meta_tags` hook registration (~line 216):
```php
// Disable audit OG output when meta editor handles it.
if ( get_option( 'swps_meta_editor_enabled', 1 ) && ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'AIOSEO_VERSION' ) ) {
    remove_action( 'wp_head', [ SWPS_OpenGraph_Module::class, 'output_meta_tags' ], 5 );
}
```

**Verify:** `php -l includes/class-settings.php && php -l includes/class-hooks.php && php -l stratawp-seo.php`

**Commit:** `git add includes/class-settings.php includes/class-hooks.php stratawp-seo.php && git commit -m "feat: Wire keywords & meta editor into plugin — settings, hooks, bootstrap, v2.3.0"`

---

### Task 8: Generator Integration — Save Meta on Post Creation

**Files:**
- Modify: `includes/class-generator.php` — save `_swps_meta_title`, `_swps_meta_description`, `_swps_focus_keyword` when generating posts

**Context:** The generator already saves `meta_description` and `focus_keyword` to Yoast/RankMath meta keys (lines 386-392). Add our own `_swps_*` keys alongside them. Also generate a meta title from the AI response.

**Add after line 394 (`update_post_meta( $post_id, '_swps_generated', true );`):**

```php
// Store StrataWP SEO meta fields.
update_post_meta( $post_id, '_swps_meta_description', $meta_desc );
update_post_meta( $post_id, '_swps_focus_keyword', $focus_keyword );

// Generate meta title (shortened post title if AI didn't provide one).
$meta_title = sanitize_text_field( $ai_result['meta_title'] ?? '' );
if ( empty( $meta_title ) ) {
    $meta_title = mb_substr( $ai_result['title'], 0, 60 );
}
update_post_meta( $post_id, '_swps_meta_title', $meta_title );
```

**Verify:** `php -l includes/class-generator.php`

**Commit:** `git add includes/class-generator.php && git commit -m "feat: Save _swps_ meta fields on post generation"`

---

### Task 9: CSS — Keywords Page & Meta Editor Styles

**Files:**
- Modify: `admin/css/admin.css` — append styles for keywords page and meta editor metabox

**Append these styles:**

```css
/* === Keywords Page === */
.swps-keywords-section { margin-bottom: 30px; }
.swps-suggest-form { display: flex; gap: 8px; align-items: center; margin-bottom: 16px; }
.swps-suggest-form .regular-text { flex: 1; }
.swps-intent-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; }
.swps-intent-informational { background: #e7f5ff; color: #1971c2; }
.swps-intent-transactional { background: #e6fcf5; color: #087f5b; }
.swps-intent-navigational { background: #fff3e0; color: #e65100; }

/* Keywords modal */
.swps-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; display: flex; align-items: center; justify-content: center; }
.swps-modal-content { background: #fff; border-radius: 8px; padding: 24px; max-width: 700px; width: 90%; position: relative; }
.swps-modal-close { position: absolute; top: 12px; right: 16px; font-size: 24px; cursor: pointer; color: #646970; }

/* === Meta Editor Metabox === */
.swps-meta-editor { max-width: 100%; }
.swps-meta-row { margin-bottom: 12px; }
.swps-meta-row label { display: block; margin-bottom: 4px; font-weight: 600; }
.swps-meta-row .widefat { width: 100%; }

/* SERP Preview */
.swps-serp-preview { margin-bottom: 16px; }
.swps-serp-mock { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px; max-width: 600px; }
.swps-serp-title { color: #1a0dab; font-size: 18px; line-height: 1.3; margin-bottom: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.swps-serp-url { color: #006621; font-size: 13px; margin-bottom: 2px; }
.swps-serp-desc { color: #545454; font-size: 13px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

/* Character counters */
.swps-char-count { font-weight: normal; font-size: 12px; margin-left: 8px; }
.swps-count-green { color: #00a32a; }
.swps-count-yellow { color: #dba617; }
.swps-count-red { color: #d63638; }

/* SEO Checklist */
.swps-seo-checklist { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 16px; margin: 16px 0; }
.swps-seo-checklist h4 { margin: 0 0 8px; }
.swps-seo-checklist ul { margin: 0; padding: 0; list-style: none; }
.swps-seo-checklist li { padding: 4px 0; font-size: 13px; }
.swps-check-icon { display: inline-block; width: 16px; text-align: center; margin-right: 4px; }
.swps-check-pass { color: #00a32a; }
.swps-check-fail { color: #d63638; }
.swps-check-neutral { color: #646970; }

/* Advanced & Social collapsible */
.swps-meta-advanced, .swps-meta-social { margin-top: 12px; }
.swps-meta-advanced summary, .swps-meta-social summary { cursor: pointer; font-weight: 600; padding: 8px 0; }

/* Social Preview */
.swps-social-preview { margin-top: 12px; }
.swps-social-card { border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; max-width: 500px; }
.swps-social-image { height: 150px; background-size: cover; background-position: center; background-color: #f0f0f0; }
.swps-social-text { padding: 10px 12px; }
.swps-social-domain { font-size: 11px; color: #646970; text-transform: uppercase; }
.swps-social-title { font-size: 14px; font-weight: 600; margin: 2px 0; }
.swps-social-desc { font-size: 12px; color: #646970; }
```

**Verify:** Visual check (CSS file).

**Commit:** `git add admin/css/admin.css && git commit -m "feat: Add keywords page and meta editor CSS styles"`

---

### Task 10: Documentation — Update README and readme.txt

**Files:**
- Modify: `README.md` — add Keywords & Meta Editor feature sections, settings, hooks, FAQ, changelog
- Modify: `readme.txt` — add feature sections, FAQ, changelog, stable tag bump to 2.3.0

**Key additions:**
- Feature section: "Keyword Research & Tracking" and "SEO Meta Editor"
- Settings table entries for new fields
- Developer hooks table entries for 5 new hooks
- FAQ: "Can I use the meta editor alongside Yoast/RankMath?" (auto-disables output, fields still saved)
- Changelog for v2.3.0
- Bump stable tag to 2.3.0

**Commit:** `git add README.md readme.txt && git commit -m "docs: Update README, readme.txt, and version to 2.3.0 for Keywords & Meta Editor release"`

---

Plan complete and saved to `docs/plans/2026-03-06-keywords-meta-editor-implementation.md`. Two execution options:

**1. Subagent-Driven (this session)** - I dispatch fresh subagent per task, review between tasks, fast iteration

**2. Parallel Session (separate)** - Open new session with executing-plans, batch execution with checkpoints

Which approach?
