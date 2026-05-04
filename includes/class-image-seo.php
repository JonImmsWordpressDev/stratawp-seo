<?php
/**
 * Image SEO — auto-alt, filename sanitization, lazy-loading enforcement,
 * and bulk fix for the existing Media Library.
 *
 * Auto-alt strategy (v1):
 *   - Heuristic mode (free): derives alt from the image filename + parent post
 *     title. Works offline, instant, no provider call.
 *   - AI mode (optional):    sends filename + parent context to the configured
 *     SWPS_AI_Provider for a one-line description. Falls back to heuristic on
 *     error or quota.
 *
 * Filenames are sanitized to lowercase-with-dashes on upload (preserves the
 * original via `_swps_orig_filename` post meta).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Image_SEO {

    public const OPTION_KEY = 'swps_image_seo';

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ], 20 );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        $opts = $this->get();

        if ( ! empty( $opts['filename_sanitize'] ) ) {
            add_filter( 'sanitize_file_name', [ $this, 'filter_filename' ], 20, 2 );
        }

        if ( ! empty( $opts['auto_alt'] ) ) {
            add_action( 'add_attachment',          [ $this, 'auto_alt_on_upload' ] );
            add_filter( 'wp_generate_attachment_metadata', [ $this, 'auto_alt_on_metadata' ], 10, 2 );
        }

        if ( ! empty( $opts['lazy_load'] ) ) {
            add_filter( 'the_content', [ $this, 'enforce_lazy_loading' ], 99 );
        }

        // Bulk fix AJAX.
        add_action( 'wp_ajax_swps_image_seo_scan',  [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_swps_image_seo_fix',   [ $this, 'ajax_fix' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'stratawp-seo',
            __( 'Image SEO', 'stratawp-seo' ),
            __( 'Image SEO', 'stratawp-seo' ),
            'manage_options',
            'swps-image-seo',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
        }
        require SWPS_PLUGIN_DIR . 'templates/image-seo-page.php';
    }

    public function enqueue_assets( string $hook ): void {
        if ( 'stratawp-seo_page_swps-image-seo' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'swps-page-image-seo',
            SWPS_PLUGIN_URL . 'admin/css/pages/image-seo.css',
            [ 'swps-tokens', 'swps-components', 'swps-templates' ],
            SWPS_VERSION
        );
        wp_enqueue_script(
            'swps-image-seo',
            SWPS_PLUGIN_URL . 'admin/js/image-seo.js',
            [ 'jquery' ],
            SWPS_VERSION,
            true
        );
        wp_localize_script( 'swps-image-seo', 'swpsImageSeo', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'swps_nonce' ),
            'i18n'    => [
                'scanning'  => __( 'Scanning library...', 'stratawp-seo' ),
                'fixing'    => __( 'Generating alt text...', 'stratawp-seo' ),
                'done'      => __( 'Done.', 'stratawp-seo' ),
                'failed'    => __( 'Request failed.', 'stratawp-seo' ),
                'fixedOne'  => __( 'Fixed %d image.', 'stratawp-seo' ),
                'fixedMany' => __( 'Fixed %d images.', 'stratawp-seo' ),
            ],
        ] );
    }

    // ---------- Settings ----------

    public function register_settings(): void {
        register_setting(
            'swps_image_seo_group',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => $this->defaults(),
            ]
        );
    }

    public function defaults(): array {
        return [
            'auto_alt'          => 1,
            'alt_mode'          => 'heuristic', // heuristic | ai
            'filename_sanitize' => 1,
            'lazy_load'         => 1,
        ];
    }

    public function get(): array {
        $stored = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( is_array( $stored ) ? $stored : [], $this->defaults() );
    }

    public function sanitize( $input ): array {
        $clean = $this->defaults();
        if ( ! is_array( $input ) ) {
            return $clean;
        }
        $clean['auto_alt']          = empty( $input['auto_alt'] )          ? 0 : 1;
        $clean['filename_sanitize'] = empty( $input['filename_sanitize'] ) ? 0 : 1;
        $clean['lazy_load']         = empty( $input['lazy_load'] )         ? 0 : 1;
        $clean['alt_mode']          = ( isset( $input['alt_mode'] ) && 'ai' === $input['alt_mode'] ) ? 'ai' : 'heuristic';
        return $clean;
    }

    // ---------- Filename sanitization ----------

    /**
     * Lowercase, dash-separated, strip device-camera prefixes (IMG_, DSC_, etc.).
     * Keeps the existing WP-sanitized name as a base so we don't fight WP.
     */
    public function filter_filename( string $filename, string $original = '' ): string {
        $info = pathinfo( $filename );
        $name = $info['filename'] ?? $filename;
        $ext  = isset( $info['extension'] ) ? '.' . strtolower( $info['extension'] ) : '';

        // Strip common camera-roll prefixes.
        $name = preg_replace( '/^(img|dsc|dscf|dscn|p\d{0,3}|photo|image|screenshot)[-_ ]+/i', '', (string) $name ) ?: $name;
        // Lowercase + dash-separate.
        $name = strtolower( $name );
        $name = preg_replace( '/[^a-z0-9]+/', '-', $name );
        $name = trim( (string) $name, '-' );
        if ( '' === $name ) {
            $name = 'image';
        }
        return $name . $ext;
    }

    // ---------- Auto-alt ----------

    /**
     * Hooked on `add_attachment`. Runs only for images. Skips when alt is
     * already set (e.g., user pasted alt during upload).
     */
    public function auto_alt_on_upload( int $attachment_id ): void {
        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            return;
        }
        $existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        if ( ! empty( $existing ) ) {
            return;
        }
        $alt = $this->generate_alt_for( $attachment_id );
        if ( '' !== $alt ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
        }
    }

    /**
     * Hooked on `wp_generate_attachment_metadata` — covers cases where
     * `add_attachment` fired before metadata existed (some upload flows).
     */
    public function auto_alt_on_metadata( $metadata, int $attachment_id ) {
        $this->auto_alt_on_upload( $attachment_id );
        return $metadata;
    }

    /**
     * Build an alt string for a single attachment. Public so the bulk tool
     * and CLI can reuse it.
     */
    public function generate_alt_for( int $attachment_id ): string {
        $opts = $this->get();
        $mode = $opts['alt_mode'] ?? 'heuristic';

        $context = $this->context_for_attachment( $attachment_id );

        if ( 'ai' === $mode ) {
            $ai = $this->generate_alt_via_ai( $context );
            if ( '' !== $ai ) {
                return $ai;
            }
        }

        return $this->generate_alt_heuristic( $context );
    }

    /**
     * Filename + parent-post context used by both alt strategies.
     *
     * @return array{filename:string, slug_words:string, parent_title:string, post_title:string}
     */
    private function context_for_attachment( int $attachment_id ): array {
        $file = get_attached_file( $attachment_id );
        $base = $file ? basename( $file ) : '';
        $slug = $base ? pathinfo( $base, PATHINFO_FILENAME ) : '';

        $post = get_post( $attachment_id );

        $parent_id    = $post && $post->post_parent ? (int) $post->post_parent : 0;
        $parent_title = $parent_id ? get_the_title( $parent_id ) : '';

        $slug_words = trim( preg_replace( '/[^a-z0-9]+/i', ' ', $slug ) ?: '' );

        return [
            'filename'     => $base,
            'slug_words'   => $slug_words,
            'parent_title' => (string) $parent_title,
            'post_title'   => (string) ( $post->post_title ?? '' ),
        ];
    }

    private function generate_alt_heuristic( array $ctx ): string {
        $words  = $ctx['slug_words'];
        $title  = $ctx['post_title'];
        $parent = $ctx['parent_title'];

        // Prefer the cleanest source: parent title > post title > slug words.
        $base = '';
        if ( '' !== $parent ) {
            $base = $parent;
        } elseif ( '' !== $title && ! preg_match( '/^image[-_ ]?\d*$/i', $title ) ) {
            $base = $title;
        } elseif ( '' !== $words ) {
            $base = ucfirst( $words );
        }

        $base = trim( $base );
        if ( '' === $base ) {
            return '';
        }

        // Truncate at word boundary to ~120 chars (Google guidance: keep alts short + descriptive).
        if ( mb_strlen( $base ) > 120 ) {
            $base = mb_substr( $base, 0, 120 );
            $base = preg_replace( '/\s+\S*$/', '', $base ) ?: $base;
        }
        return $base;
    }

    private function generate_alt_via_ai( array $ctx ): string {
        if ( ! function_exists( 'stratawp_seo' ) ) {
            return '';
        }
        $plugin = stratawp_seo();
        if ( ! isset( $plugin->api ) || ! ( $plugin->api instanceof SWPS_AI_Provider ) ) {
            return '';
        }

        $system = 'You write short, descriptive alt text for images on a WordPress site. '
                . 'Return ONE concise sentence (10-15 words max), no quotes, no trailing period if it is a noun phrase, '
                . 'no "image of", no "photo of", and no marketing fluff. Use only what the context gives you.';

        $user = sprintf(
            "Filename: %s\nFilename words: %s\nParent post title: %s\n\nWrite the alt text:",
            $ctx['filename'],
            $ctx['slug_words'],
            $ctx['parent_title'] ?: '(none)'
        );

        $resp = $plugin->api->chat( $system, $user, 80 );
        if ( is_wp_error( $resp ) || ! is_string( $resp ) ) {
            return '';
        }
        $resp = trim( $resp );
        $resp = trim( $resp, "\"' \t\n\r" );
        // First line only.
        if ( false !== ( $nl = strpos( $resp, "\n" ) ) ) {
            $resp = substr( $resp, 0, $nl );
        }
        return $resp;
    }

    // ---------- Lazy loading ----------

    /**
     * Belt-and-braces lazy-loading on rendered post content. WP core adds
     * loading="lazy" automatically since 5.5 for content images, but only
     * when `width`/`height` are present. This pass adds it to anything
     * that's still missing.
     */
    public function enforce_lazy_loading( string $html ): string {
        if ( '' === trim( $html ) || false === stripos( $html, '<img' ) ) {
            return $html;
        }

        return preg_replace_callback(
            '/<img\b([^>]*)>/i',
            static function ( $m ) {
                $attrs = $m[1];
                if ( preg_match( '/\bloading\s*=/i', $attrs ) ) {
                    return $m[0];
                }
                return '<img' . $attrs . ' loading="lazy" decoding="async">';
            },
            $html
        ) ?: $html;
    }

    // ---------- Bulk fix ----------

    /**
     * Count attachments with missing alt text.
     */
    public function get_missing_alt_count(): int {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $sql = "
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m
              ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
            WHERE p.post_type = 'attachment'
              AND p.post_mime_type LIKE 'image/%'
              AND ( m.meta_value IS NULL OR m.meta_value = '' )
        ";
        return (int) $wpdb->get_var( $sql );
        // phpcs:enable
    }

    /**
     * Get up to $limit attachment IDs missing alt text.
     *
     * @return int[]
     */
    public function get_missing_alt_ids( int $limit = 25 ): array {
        global $wpdb;
        $limit = max( 1, min( 100, $limit ) );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m
              ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
            WHERE p.post_type = 'attachment'
              AND p.post_mime_type LIKE 'image/%%'
              AND ( m.meta_value IS NULL OR m.meta_value = '' )
            ORDER BY p.ID DESC
            LIMIT %d
            ",
            $limit
        );
        return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
        // phpcs:enable
    }

    public function ajax_scan(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $total = (int) $wpdb->get_var( "
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
        " );
        // phpcs:enable
        wp_send_json_success( [
            'total'   => $total,
            'missing' => $this->get_missing_alt_count(),
        ] );
    }

    public function ajax_fix(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }
        $batch = isset( $_POST['batch'] ) ? max( 1, min( 50, (int) $_POST['batch'] ) ) : 20;

        $ids   = $this->get_missing_alt_ids( $batch );
        $fixed = 0;
        foreach ( $ids as $id ) {
            $alt = $this->generate_alt_for( $id );
            if ( '' !== $alt ) {
                update_post_meta( $id, '_wp_attachment_image_alt', $alt );
                $fixed++;
            }
        }
        wp_send_json_success( [
            'fixed'     => $fixed,
            'attempted' => count( $ids ),
            'remaining' => $this->get_missing_alt_count(),
        ] );
    }
}
