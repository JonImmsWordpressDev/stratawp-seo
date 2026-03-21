<?php
/**
 * Redirect Manager — storage, matching, and execution.
 *
 * Intercepts requests via template_redirect at priority 1.
 * Supports exact and regex matching, 301/302/307/410 types.
 * Logs 404s for redirect suggestions.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Redirect_Manager {

    private const REDIRECTS_TABLE = 'swps_redirects';
    private const LOG_TABLE       = 'swps_404_log';
    private const CACHE_KEY       = 'swps_redirects_cache';

    public function __construct() {
        // Execute redirects — must be very early.
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );

        // Log 404s.
        add_action( 'template_redirect', [ $this, 'maybe_log_404' ], 99 );

        // Auto-redirect on slug change.
        if ( get_option( 'swps_auto_redirect_slug_change', 1 ) ) {
            add_action( 'post_updated', [ $this, 'detect_slug_change' ], 10, 3 );
        }

        // AJAX handlers.
        add_action( 'wp_ajax_swps_add_redirect', [ $this, 'ajax_add_redirect' ] );
        add_action( 'wp_ajax_swps_delete_redirect', [ $this, 'ajax_delete_redirect' ] );
        add_action( 'wp_ajax_swps_get_redirects', [ $this, 'ajax_get_redirects' ] );
        add_action( 'wp_ajax_swps_get_404s', [ $this, 'ajax_get_404s' ] );
        add_action( 'wp_ajax_swps_delete_404', [ $this, 'ajax_delete_404' ] );
    }

    /**
     * Check if current request matches a redirect and execute it.
     */
    public function maybe_redirect(): void {
        if ( is_admin() ) {
            return;
        }

        $request_path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        $request_path = rtrim( $request_path, '/' );

        if ( empty( $request_path ) || '/' === $request_path ) {
            return;
        }

        $redirects = $this->get_cached_redirects();

        // Exact match first.
        foreach ( $redirects as $redirect ) {
            if ( ! $redirect->is_regex && rtrim( $redirect->source_url, '/' ) === $request_path ) {
                $this->execute_redirect( $redirect );
                return;
            }
        }

        // Regex match.
        foreach ( $redirects as $redirect ) {
            if ( $redirect->is_regex ) {
                $pattern = '#' . str_replace( '#', '\#', $redirect->source_url ) . '#';
                if ( preg_match( $pattern, $request_path, $matches ) ) {
                    $target = $redirect->target_url;
                    // Replace capture groups.
                    for ( $i = 1; $i < count( $matches ); $i++ ) {
                        $target = str_replace( '$' . $i, $matches[ $i ], $target );
                    }
                    $redirect->target_url = $target;
                    $this->execute_redirect( $redirect );
                    return;
                }
            }
        }
    }

    /**
     * Execute a redirect.
     */
    private function execute_redirect( object $redirect ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::REDIRECTS_TABLE;

        // Update hit counter.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET hits = hits + 1, last_hit = NOW() WHERE id = %d",
            $redirect->id
        ) );

        if ( 410 === (int) $redirect->type ) {
            status_header( 410 );
            nocache_headers();
            echo '<h1>410 Gone</h1><p>This page has been permanently removed.</p>';
            exit;
        }

        wp_redirect( $redirect->target_url, (int) $redirect->type );
        exit;
    }

    /**
     * Log 404 errors.
     */
    public function maybe_log_404(): void {
        if ( ! is_404() ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $url   = sanitize_text_field( $_SERVER['REQUEST_URI'] ?? '' );

        if ( empty( $url ) ) {
            return;
        }

        // Upsert: increment count if exists, insert if not.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE url = %s",
            $url
        ) );

        if ( $existing ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET count = count + 1, last_seen = NOW() WHERE id = %d",
                $existing
            ) );
        } else {
            $wpdb->insert( $table, [
                'url'       => $url,
                'referrer'  => sanitize_text_field( $_SERVER['HTTP_REFERER'] ?? '' ),
                'count'     => 1,
                'last_seen' => current_time( 'mysql' ),
            ], [ '%s', '%s', '%d', '%s' ] );
        }
    }

    /**
     * Detect slug changes and auto-create 301 redirects.
     */
    public function detect_slug_change( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
        if ( 'publish' !== $post_before->post_status || 'publish' !== $post_after->post_status ) {
            return;
        }

        if ( $post_before->post_name === $post_after->post_name ) {
            return;
        }

        // Build old URL path.
        $old_url = str_replace( home_url(), '', get_permalink( $post_before ) );

        // We need the actual old permalink — reconstruct it.
        $old_link = get_permalink( $post_id );
        $old_link = str_replace( $post_after->post_name, $post_before->post_name, $old_link );
        $old_path = wp_parse_url( $old_link, PHP_URL_PATH );
        $new_path = wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );

        if ( $old_path && $new_path && $old_path !== $new_path ) {
            $this->add_redirect( $old_path, $new_path, 301 );
        }
    }

    /**
     * Add a redirect.
     */
    public function add_redirect( string $source, string $target, int $type = 301, bool $is_regex = false ): int|false {
        global $wpdb;
        $table = $wpdb->prefix . self::REDIRECTS_TABLE;

        $result = $wpdb->insert( $table, [
            'source_url' => $source,
            'target_url' => $target,
            'type'       => $type,
            'is_regex'   => $is_regex ? 1 : 0,
            'hits'       => 0,
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ], [ '%s', '%s', '%d', '%d', '%d', '%s', '%s' ] );

        delete_transient( self::CACHE_KEY );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get cached redirects.
     */
    private function get_cached_redirects(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::REDIRECTS_TABLE;
        $redirects = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY is_regex ASC, id ASC" );

        set_transient( self::CACHE_KEY, $redirects ?: [], HOUR_IN_SECONDS );

        return $redirects ?: [];
    }

    // --- AJAX Handlers ---

    public function ajax_add_redirect(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $source   = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
        $target   = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
        $type     = (int) ( $_POST['type'] ?? 301 );
        $is_regex = ! empty( $_POST['is_regex'] );

        if ( empty( $source ) ) {
            wp_send_json_error( 'Source URL is required.' );
        }

        $id = $this->add_redirect( $source, $target, $type, $is_regex );
        wp_send_json_success( [ 'id' => $id ] );
    }

    public function ajax_delete_redirect(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::REDIRECTS_TABLE;
        $id    = (int) ( $_POST['id'] ?? 0 );

        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        delete_transient( self::CACHE_KEY );

        wp_send_json_success();
    }

    public function ajax_get_redirects(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::REDIRECTS_TABLE;
        $redirects = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100" );

        wp_send_json_success( $redirects );
    }

    public function ajax_get_404s(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $logs = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY count DESC LIMIT 100" );

        wp_send_json_success( $logs );
    }

    public function ajax_delete_404(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $id    = (int) ( $_POST['id'] ?? 0 );

        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        wp_send_json_success();
    }

    /**
     * Create database tables. Called on activation.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset    = $wpdb->get_charset_collate();
        $redirects  = $wpdb->prefix . self::REDIRECTS_TABLE;
        $log        = $wpdb->prefix . self::LOG_TABLE;

        $sql_redirects = "CREATE TABLE {$redirects} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url VARCHAR(500) NOT NULL DEFAULT '',
            target_url VARCHAR(500) NOT NULL DEFAULT '',
            type SMALLINT UNSIGNED NOT NULL DEFAULT 301,
            is_regex TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_hit DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_source_url (source_url(191))
        ) {$charset};";

        $sql_log = "CREATE TABLE {$log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url VARCHAR(500) NOT NULL DEFAULT '',
            referrer VARCHAR(500) NOT NULL DEFAULT '',
            count BIGINT UNSIGNED NOT NULL DEFAULT 1,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_url (url(191))
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_redirects );
        dbDelta( $sql_log );
    }

    /**
     * Prune old 404 logs (older than 90 days). Called via cron.
     */
    public static function prune_404_logs(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $wpdb->query( "DELETE FROM {$table} WHERE last_seen < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
    }
}
