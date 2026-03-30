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
        add_action( 'wp_ajax_swps_suggest_redirect_target', [ $this, 'ajax_suggest_redirect_target' ] );
        add_action( 'wp_ajax_swps_bulk_delete_404s', [ $this, 'ajax_bulk_delete_404s' ] );
        add_action( 'wp_ajax_swps_bulk_redirect_404s', [ $this, 'ajax_bulk_redirect_404s' ] );
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

        foreach ( $logs as $log ) {
            $suggestions             = $this->find_similar_posts( $log->url );
            $log->suggested_target   = ! empty( $suggestions ) ? $suggestions[0]['url'] : '';
            $log->suggestions        = $suggestions;
        }

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
     * Find existing posts/pages similar to a given 404 URL.
     *
     * @param string $url The 404 URL to find matches for.
     * @return array<int, array{title: string, url: string}>
     */
    private function find_similar_posts( string $url ): array {
        global $wpdb;

        // Extract slug from the last path segment.
        $path = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
        $slug = basename( $path );

        // Strip common extensions.
        $slug = preg_replace( '/\.(html?|php|aspx)$/i', '', $slug );
        $slug = sanitize_title( $slug );

        if ( empty( $slug ) ) {
            return [];
        }

        $results = $this->query_posts_by_slug( $slug );

        // Fallback: split on hyphens and use the longest part (min 3 chars).
        if ( empty( $results ) && strpos( $slug, '-' ) !== false ) {
            $parts       = explode( '-', $slug );
            $longest     = '';
            foreach ( $parts as $part ) {
                if ( strlen( $part ) >= 3 && strlen( $part ) > strlen( $longest ) ) {
                    $longest = $part;
                }
            }
            if ( ! empty( $longest ) ) {
                $results = $this->query_posts_by_slug( $longest );
            }
        }

        $suggestions = [];
        foreach ( array_slice( $results, 0, 5 ) as $post ) {
            $suggestions[] = [
                'title' => $post->post_title,
                'url'   => get_permalink( $post ),
            ];
        }

        return $suggestions;
    }

    /**
     * Query published posts/pages whose post_name matches a slug pattern.
     *
     * @param string $slug Slug to match against.
     * @return array<int, \WP_Post>
     */
    private function query_posts_by_slug( string $slug ): array {
        global $wpdb;

        $like = '%' . $wpdb->esc_like( $slug ) . '%';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post', 'page')
               AND post_name LIKE %s
             ORDER BY (post_name = %s) DESC, post_date DESC
             LIMIT 5",
            $like,
            $slug
        ) ) ?? [];
    }

    public function ajax_suggest_redirect_target(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $url         = sanitize_text_field( wp_unslash( $_POST['url'] ?? '' ) );
        $suggestions = $this->find_similar_posts( $url );

        wp_send_json_success( $suggestions );
    }

    public function ajax_bulk_delete_404s(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $ids = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
        $ids = array_filter( $ids );

        if ( empty( $ids ) ) {
            wp_send_json_error( 'No IDs provided.' );
        }

        global $wpdb;
        $table        = $wpdb->prefix . self::LOG_TABLE;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE id IN ({$placeholders})",
            $ids
        ) );

        wp_send_json_success();
    }

    public function ajax_bulk_redirect_404s(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $ids    = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
        $ids    = array_filter( $ids );
        $target = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );

        if ( empty( $ids ) || empty( $target ) ) {
            wp_send_json_error( 'IDs and target are required.' );
        }

        global $wpdb;
        $table        = $wpdb->prefix . self::LOG_TABLE;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, url FROM {$table} WHERE id IN ({$placeholders})",
            $ids
        ) );

        foreach ( $rows as $row ) {
            $source = wp_parse_url( $row->url, PHP_URL_PATH );
            if ( $source ) {
                $this->add_redirect( $source, $target, 301 );
            }
        }

        // Delete the processed 404 entries.
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE id IN ({$placeholders})",
            $ids
        ) );

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
