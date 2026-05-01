<?php
/**
 * Competitors — track competitor sites for content velocity, schema diff, and title/H1 changes.
 *
 * MVP scope (v4.1):
 *   - Track up to 10 URLs.
 *   - Daily WP-cron scan + manual "Scan now" per row, "Scan all" button.
 *   - Data sources (in order): RSS/Atom feed (auto-discovered or common-path fallback),
 *     then sitemap.xml fallback. Always also fetches the homepage for <title>,
 *     first <h1>, and JSON-LD schema types.
 *   - Stores last 12 snapshots per competitor; diffs the latest two.
 *
 * Out of scope (deferred): keyword-gap analysis, AI-generated change summaries, email alerts.
 *
 * Spec: docs/superpowers/specs/2026-04-30-admin-redesign-design.md §6.2
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Competitors {

    public const OPTION_LIST       = 'swps_competitors';
    public const CRON_HOOK         = 'swps_competitors_daily_scan';
    public const MAX_COMPETITORS   = 10;
    public const MAX_SNAPSHOTS     = 12;
    public const MAX_RECENT_POSTS  = 20;
    public const HTTP_TIMEOUT      = 12;

    public function __construct() {
        add_action( 'admin_menu',           [ $this, 'register_menu' ], 20 );
        add_action( 'admin_enqueue_scripts',[ $this, 'enqueue_assets' ] );
        add_action( 'init',                 [ $this, 'maybe_schedule_cron' ] );

        add_action( 'wp_ajax_swps_competitor_save',     [ $this, 'ajax_save' ] );
        add_action( 'wp_ajax_swps_competitor_delete',   [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_swps_competitor_scan',     [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_swps_competitor_scan_all', [ $this, 'ajax_scan_all' ] );

        add_action( self::CRON_HOOK, [ $this, 'cron_scan_all' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'stratawp-seo',
            __( 'Competitors', 'stratawp-seo' ),
            __( 'Competitors', 'stratawp-seo' ),
            'manage_options',
            'swps-competitors',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
        }
        require SWPS_PLUGIN_DIR . 'templates/competitors-page.php';
    }

    public function enqueue_assets( string $hook ): void {
        if ( 'stratawp-seo_page_swps-competitors' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'swps-page-competitors',
            SWPS_PLUGIN_URL . 'admin/css/pages/competitors.css',
            [ 'swps-tokens', 'swps-components', 'swps-templates' ],
            SWPS_VERSION
        );
        wp_enqueue_script(
            'swps-competitors',
            SWPS_PLUGIN_URL . 'admin/js/competitors.js',
            [ 'jquery' ],
            SWPS_VERSION,
            true
        );
        wp_localize_script( 'swps-competitors', 'swpsCompetitors', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'swps_nonce' ),
            'maxCompetitors' => self::MAX_COMPETITORS,
            'i18n'           => [
                'scanning'       => __( 'Scanning...', 'stratawp-seo' ),
                'scanningAll'    => __( 'Scanning all competitors...', 'stratawp-seo' ),
                'saving'         => __( 'Saving...', 'stratawp-seo' ),
                'deleting'       => __( 'Deleting...', 'stratawp-seo' ),
                'genericFail'    => __( 'Request failed.', 'stratawp-seo' ),
                'invalidUrl'     => __( 'Please enter a valid URL (https://example.com).', 'stratawp-seo' ),
                'confirmDelete'  => __( 'Stop tracking this competitor? Snapshot history will be deleted.', 'stratawp-seo' ),
                'limitReached'   => sprintf( /* translators: %d: max competitors */ __( 'You can track up to %d competitors.', 'stratawp-seo' ), self::MAX_COMPETITORS ),
                'noChanges'      => __( 'No changes since last scan.', 'stratawp-seo' ),
                'newPosts'       => __( 'new posts', 'stratawp-seo' ),
                'newPost'        => __( 'new post', 'stratawp-seo' ),
                'newSchema'      => __( 'New schema:', 'stratawp-seo' ),
                'titleChanged'   => __( 'Homepage title changed.', 'stratawp-seo' ),
                'h1Changed'      => __( 'Homepage H1 changed.', 'stratawp-seo' ),
                'never'          => __( 'never', 'stratawp-seo' ),
                'noScanYet'      => __( 'Not scanned yet — click "Scan now".', 'stratawp-seo' ),
                'addCompetitor'  => __( 'Add competitor', 'stratawp-seo' ),
                'editCompetitor' => __( 'Edit', 'stratawp-seo' ),
                'cancel'         => __( 'Cancel', 'stratawp-seo' ),
                'save'           => __( 'Save', 'stratawp-seo' ),
                'scanNow'        => __( 'Scan now', 'stratawp-seo' ),
                'delete'         => __( 'Delete', 'stratawp-seo' ),
                'urlPlaceholder' => __( 'https://competitor.com', 'stratawp-seo' ),
                'labelPlaceholder' => __( 'Optional label (defaults to domain)', 'stratawp-seo' ),
                'sourceRss'      => __( 'RSS', 'stratawp-seo' ),
                'sourceSitemap'  => __( 'Sitemap', 'stratawp-seo' ),
                'sourceNone'     => __( 'No data source found', 'stratawp-seo' ),
                'velocity'       => __( 'posts/wk', 'stratawp-seo' ),
                'noNewPosts'     => __( 'No new posts since last scan', 'stratawp-seo' ),
                'recentPosts'    => __( 'Recent posts', 'stratawp-seo' ),
                'schemaTypes'    => __( 'Schema types', 'stratawp-seo' ),
                'lastScan'       => __( 'Last scan', 'stratawp-seo' ),
                'empty'          => __( 'No competitors tracked yet. Click "Add competitor" to start.', 'stratawp-seo' ),
            ],
        ] );
    }

    // ---------- Cron ----------

    public function maybe_schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule_cron(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        wp_unschedule_hook( self::CRON_HOOK );
    }

    public function cron_scan_all(): void {
        $list = $this->get_list();
        $n    = count( $list );
        foreach ( $list as $i => $c ) {
            $list[ $i ] = $this->scan_one( $c );
            if ( $i < $n - 1 ) {
                usleep( 500000 ); // 0.5s — be polite between competitors.
            }
        }
        $this->save_list( $list );
    }

    // ---------- AJAX ----------

    public function ajax_save(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }

        $url   = isset( $_POST['url'] )   ? esc_url_raw( wp_unslash( $_POST['url'] ) )         : '';
        $label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
        $id    = isset( $_POST['id'] )    ? sanitize_key( wp_unslash( $_POST['id'] ) )           : '';

        if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid URL (https://example.com).', 'stratawp-seo' ) ] );
        }

        $url = $this->normalize_url( $url );
        if ( '' === $label ) {
            $label = $this->derive_label( $url );
        }

        $list = $this->get_list();

        if ( '' !== $id ) {
            foreach ( $list as $i => $c ) {
                if ( $c['id'] === $id ) {
                    $list[ $i ]['url']   = $url;
                    $list[ $i ]['label'] = $label;
                    $this->save_list( $list );
                    wp_send_json_success( [ 'list' => $list, 'summary' => $this->get_summary( $list ) ] );
                }
            }
        }

        if ( count( $list ) >= self::MAX_COMPETITORS ) {
            wp_send_json_error( [ 'message' => sprintf( /* translators: %d: max */ __( 'You can track up to %d competitors.', 'stratawp-seo' ), self::MAX_COMPETITORS ) ] );
        }

        $list[] = [
            'id'           => $this->make_id( $url ),
            'url'          => $url,
            'label'        => $label,
            'feed_url'     => '',
            'data_source'  => '',
            'last_scanned' => '',
            'last_error'   => '',
            'snapshots'    => [],
        ];
        $this->save_list( $list );
        wp_send_json_success( [ 'list' => $list, 'summary' => $this->get_summary( $list ) ] );
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }
        $id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        if ( '' === $id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid id.', 'stratawp-seo' ) ] );
        }
        $list = array_values( array_filter( $this->get_list(), fn( $c ) => $c['id'] !== $id ) );
        $this->save_list( $list );
        wp_send_json_success( [ 'list' => $list, 'summary' => $this->get_summary( $list ) ] );
    }

    public function ajax_scan(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }
        $id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        $list = $this->get_list();
        foreach ( $list as $i => $c ) {
            if ( $c['id'] === $id ) {
                $list[ $i ] = $this->scan_one( $c );
                $this->save_list( $list );
                wp_send_json_success( [ 'list' => $list, 'summary' => $this->get_summary( $list ) ] );
            }
        }
        wp_send_json_error( [ 'message' => __( 'Competitor not found.', 'stratawp-seo' ) ] );
    }

    public function ajax_scan_all(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }
        // Allow a slightly longer execution window for the bulk scan.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }
        $list = $this->get_list();
        foreach ( $list as $i => $c ) {
            $list[ $i ] = $this->scan_one( $c );
        }
        $this->save_list( $list );
        wp_send_json_success( [ 'list' => $list, 'summary' => $this->get_summary( $list ) ] );
    }

    // ---------- Public API ----------

    public function get_list(): array {
        $list = get_option( self::OPTION_LIST, [] );
        if ( ! is_array( $list ) ) {
            return [];
        }
        $defaults = [
            'id'           => '',
            'url'          => '',
            'label'        => '',
            'feed_url'     => '',
            'data_source'  => '',
            'last_scanned' => '',
            'last_error'   => '',
            'snapshots'    => [],
        ];
        foreach ( $list as $i => $c ) {
            $list[ $i ] = wp_parse_args( is_array( $c ) ? $c : [], $defaults );
        }
        return $list;
    }

    public function save_list( array $list ): void {
        update_option( self::OPTION_LIST, $list, false );
    }

    /**
     * Public for dashboard widget (3 most-recently-changed competitors with one-line summaries).
     */
    public function get_dashboard_summary( int $limit = 3 ): array {
        $list = $this->get_list();
        $rows = [];
        foreach ( $list as $c ) {
            $diff = $this->latest_diff( $c );
            if ( empty( $diff['has_data'] ) ) { continue; }

            $summary_parts = [];
            if ( $diff['new_post_count'] > 0 ) {
                $summary_parts[] = sprintf(
                    /* translators: %d: count */
                    _n( '%d new post', '%d new posts', $diff['new_post_count'], 'stratawp-seo' ),
                    $diff['new_post_count']
                );
            }
            if ( ! empty( $diff['new_schema_types'] ) ) {
                $summary_parts[] = sprintf(
                    /* translators: %s: schema list */
                    __( 'new schema: %s', 'stratawp-seo' ),
                    implode( ', ', $diff['new_schema_types'] )
                );
            }
            if ( ! empty( $diff['homepage_title_changed'] ) ) {
                $summary_parts[] = __( 'title changed', 'stratawp-seo' );
            }
            if ( ! empty( $summary_parts ) || ! empty( $c['last_scanned'] ) ) {
                $rows[] = [
                    'label'        => $c['label'],
                    'url'          => $c['url'],
                    'summary'      => empty( $summary_parts )
                        ? __( 'No changes since last scan.', 'stratawp-seo' )
                        : implode( ' · ', $summary_parts ),
                    'last_scanned' => $c['last_scanned'],
                    'has_changes'  => ! empty( $summary_parts ),
                ];
            }
        }
        usort( $rows, fn( $a, $b ) => strcmp( $b['last_scanned'], $a['last_scanned'] ) );
        return array_slice( $rows, 0, $limit );
    }

    public function get_summary( ?array $list = null ): array {
        $list           = $list ?? $this->get_list();
        $new_posts_week = 0;
        $changes        = 0;
        $latest_scan    = '';
        foreach ( $list as $c ) {
            $diff = $this->latest_diff( $c );
            if ( empty( $diff['has_data'] ) ) { continue; }
            $new_posts_week += (int) $diff['new_post_count'];
            if ( ! empty( $diff['new_schema_types'] ) )       { $changes++; }
            if ( ! empty( $diff['removed_schema_types'] ) )   { $changes++; }
            if ( ! empty( $diff['homepage_title_changed'] ) ) { $changes++; }
            if ( ! empty( $diff['homepage_h1_changed'] ) )    { $changes++; }
            if ( ! empty( $c['last_scanned'] ) && $c['last_scanned'] > $latest_scan ) {
                $latest_scan = $c['last_scanned'];
            }
        }
        return [
            'count'          => count( $list ),
            'new_posts_week' => $new_posts_week,
            'changes'        => $changes,
            'latest_scan'    => $latest_scan,
        ];
    }

    /**
     * Diff the latest snapshot against the previous one.
     *
     * @return array { has_data, post_count, new_post_count, new_posts[],
     *                new_schema_types[], removed_schema_types[],
     *                homepage_title, homepage_h1, homepage_title_changed, homepage_h1_changed,
     *                velocity_per_week }
     */
    public function latest_diff( array $competitor ): array {
        $snaps = $competitor['snapshots'] ?? [];
        $count = count( $snaps );
        if ( 0 === $count ) {
            return [ 'has_data' => false ];
        }
        $cur  = $snaps[ $count - 1 ];
        $prev = $count > 1 ? $snaps[ $count - 2 ] : null;

        $diff = [
            'has_data'               => true,
            'post_count'             => (int) ( $cur['post_count'] ?? 0 ),
            'new_post_count'         => 0,
            'new_posts'              => [],
            'new_schema_types'       => [],
            'removed_schema_types'   => [],
            'homepage_title'         => (string) ( $cur['homepage_title'] ?? '' ),
            'homepage_h1'            => (string) ( $cur['homepage_h1'] ?? '' ),
            'homepage_title_changed' => false,
            'homepage_h1_changed'    => false,
            'velocity_per_week'      => $this->compute_velocity( $snaps ),
        ];

        if ( $prev ) {
            $prev_urls = array_map( fn( $p ) => $p['url'] ?? '', $prev['recent_posts'] ?? [] );
            foreach ( $cur['recent_posts'] ?? [] as $post ) {
                if ( ! in_array( $post['url'] ?? '', $prev_urls, true ) ) {
                    $diff['new_posts'][] = $post;
                }
            }
            $diff['new_post_count'] = count( $diff['new_posts'] );

            $cur_schema  = $cur['schema_types']  ?? [];
            $prev_schema = $prev['schema_types'] ?? [];
            $diff['new_schema_types']     = array_values( array_diff( $cur_schema, $prev_schema ) );
            $diff['removed_schema_types'] = array_values( array_diff( $prev_schema, $cur_schema ) );

            $diff['homepage_title_changed'] = ( $cur['homepage_title'] ?? '' ) !== ( $prev['homepage_title'] ?? '' );
            $diff['homepage_h1_changed']    = ( $cur['homepage_h1']    ?? '' ) !== ( $prev['homepage_h1']    ?? '' );
        }

        return $diff;
    }

    // ---------- Scan ----------

    /**
     * Fetch + parse one competitor and append a fresh snapshot. Always returns the
     * (possibly updated) competitor array — errors are stored in $c['last_error'].
     */
    public function scan_one( array $c ): array {
        $url = (string) ( $c['url'] ?? '' );
        if ( '' === $url ) {
            $c['last_error']   = __( 'Missing URL.', 'stratawp-seo' );
            $c['last_scanned'] = current_time( 'mysql' );
            return $c;
        }

        $homepage = $this->fetch( $url );
        if ( is_wp_error( $homepage ) ) {
            $c['last_scanned'] = current_time( 'mysql' );
            $c['last_error']   = $homepage->get_error_message();
            return $c;
        }

        $homepage_data = $this->parse_homepage( $homepage, $url );

        $feed_url = '' !== ( $c['feed_url'] ?? '' ) ? $c['feed_url'] : $homepage_data['feed_url'];
        $posts    = [];
        $source   = '';

        if ( '' !== $feed_url ) {
            $feed_posts = $this->fetch_and_parse_feed( $feed_url );
            if ( ! is_wp_error( $feed_posts ) && ! empty( $feed_posts ) ) {
                $posts  = $feed_posts;
                $source = 'rss';
            }
        }

        if ( empty( $posts ) ) {
            foreach ( [ '/feed/', '/feed', '/feed/atom/', '/?feed=rss2', '/rss', '/rss.xml' ] as $path ) {
                $candidate  = $this->join_url( $url, $path );
                $feed_posts = $this->fetch_and_parse_feed( $candidate );
                if ( ! is_wp_error( $feed_posts ) && ! empty( $feed_posts ) ) {
                    $posts    = $feed_posts;
                    $source   = 'rss';
                    $feed_url = $candidate;
                    break;
                }
            }
        }

        if ( empty( $posts ) ) {
            $sitemap_posts = $this->try_sitemap( $url );
            if ( ! is_wp_error( $sitemap_posts ) && ! empty( $sitemap_posts ) ) {
                $posts  = $sitemap_posts;
                $source = 'sitemap';
            }
        }

        $snapshot = [
            'ts'             => current_time( 'mysql' ),
            'post_count'     => count( $posts ),
            'recent_posts'   => array_slice( $posts, 0, self::MAX_RECENT_POSTS ),
            'schema_types'   => $homepage_data['schema_types'],
            'homepage_title' => $homepage_data['title'],
            'homepage_h1'    => $homepage_data['h1'],
        ];

        $snapshots   = $c['snapshots'] ?? [];
        $snapshots[] = $snapshot;
        if ( count( $snapshots ) > self::MAX_SNAPSHOTS ) {
            $snapshots = array_slice( $snapshots, -self::MAX_SNAPSHOTS );
        }

        $c['snapshots']    = $snapshots;
        $c['feed_url']     = $feed_url;
        $c['data_source']  = '' !== $source ? $source : 'none';
        $c['last_scanned'] = current_time( 'mysql' );
        $c['last_error']   = ( '' === $source )
            ? __( 'No RSS feed or sitemap found — only homepage data captured.', 'stratawp-seo' )
            : '';

        return $c;
    }

    // ---------- Fetchers ----------

    /**
     * @return string|WP_Error Body on success.
     */
    private function fetch( string $url ) {
        $resp = wp_remote_get( $url, [
            'timeout'     => self::HTTP_TIMEOUT,
            'user-agent'  => $this->user_agent(),
            'redirection' => 4,
            'headers'     => [ 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ],
        ] );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error( 'swps_http', sprintf( /* translators: %d: HTTP status */ __( 'HTTP %d when fetching the site.', 'stratawp-seo' ), $code ) );
        }
        return (string) wp_remote_retrieve_body( $resp );
    }

    private function fetch_and_parse_feed( string $url ) {
        $body = $this->fetch( $url );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        if ( '' === trim( $body ) ) {
            return [];
        }
        return $this->parse_feed( $body );
    }

    private function parse_feed( string $body ): array {
        libxml_use_internal_errors( true );
        $xml = @simplexml_load_string( $body );
        libxml_clear_errors();
        if ( ! $xml ) {
            return [];
        }

        $posts = [];

        if ( isset( $xml->channel->item ) ) {
            foreach ( $xml->channel->item as $item ) {
                $title = trim( (string) $item->title );
                $link  = trim( (string) $item->link );
                $date  = trim( (string) $item->pubDate );
                if ( '' === $link ) { continue; }
                $posts[] = [
                    'title' => $title,
                    'url'   => $link,
                    'date'  => '' !== $date ? gmdate( 'Y-m-d', strtotime( $date ) ?: time() ) : '',
                ];
            }
        } elseif ( isset( $xml->entry ) ) {
            foreach ( $xml->entry as $entry ) {
                $title = trim( (string) $entry->title );
                $link  = '';
                if ( isset( $entry->link ) ) {
                    foreach ( $entry->link as $l ) {
                        $rel = isset( $l['rel'] ) ? (string) $l['rel'] : '';
                        if ( '' === $rel || 'alternate' === $rel ) {
                            $link = (string) $l['href'];
                            break;
                        }
                    }
                }
                $date = (string) ( $entry->published ?? $entry->updated ?? '' );
                if ( '' === $link ) { continue; }
                $posts[] = [
                    'title' => $title,
                    'url'   => $link,
                    'date'  => '' !== $date ? gmdate( 'Y-m-d', strtotime( $date ) ?: time() ) : '',
                ];
            }
        }

        return $posts;
    }

    private function parse_homepage( string $html, string $base_url ): array {
        $title        = '';
        $h1           = '';
        $feed_url     = '';
        $schema_types = [];

        if ( '' === trim( $html ) ) {
            return compact( 'title', 'h1', 'feed_url', 'schema_types' );
        }

        if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
            $title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
        }

        if ( preg_match( '#<h1[^>]*>(.*?)</h1>#is', $html, $m ) ) {
            $h1 = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
        }

        if ( preg_match_all( '#<link\b[^>]*>#i', $html, $matches ) ) {
            foreach ( $matches[0] as $link_tag ) {
                if ( ! preg_match( '#rel\s*=\s*["\']alternate["\']#i', $link_tag ) ) { continue; }
                if ( ! preg_match( '#type\s*=\s*["\']application/(?:rss|atom)\+xml["\']#i', $link_tag ) ) { continue; }
                if ( preg_match( '#href\s*=\s*["\']([^"\']+)["\']#i', $link_tag, $href ) ) {
                    $feed_url = $this->absolutize( $href[1], $base_url );
                    break;
                }
            }
        }

        if ( preg_match_all( '#<script[^>]+type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks ) ) {
            foreach ( $blocks[1] as $json ) {
                $decoded = json_decode( trim( $json ), true );
                if ( ! $decoded ) { continue; }
                $this->collect_schema_types( $decoded, $schema_types );
            }
        }

        $schema_types = array_values( array_unique( array_filter( $schema_types ) ) );
        sort( $schema_types );

        return compact( 'title', 'h1', 'feed_url', 'schema_types' );
    }

    private function collect_schema_types( $node, array &$bag ): void {
        if ( ! is_array( $node ) ) { return; }
        if ( isset( $node['@type'] ) ) {
            $type = $node['@type'];
            if ( is_array( $type ) ) {
                foreach ( $type as $t ) {
                    if ( is_string( $t ) ) { $bag[] = $t; }
                }
            } elseif ( is_string( $type ) ) {
                $bag[] = $type;
            }
        }
        if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
            foreach ( $node['@graph'] as $sub ) {
                $this->collect_schema_types( $sub, $bag );
            }
        }
        foreach ( $node as $k => $v ) {
            if ( '@graph' === $k || '@type' === $k ) { continue; }
            if ( is_array( $v ) ) {
                $this->collect_schema_types( $v, $bag );
            }
        }
    }

    /**
     * @return array|WP_Error Posts on success.
     */
    private function try_sitemap( string $base_url ) {
        foreach ( [ '/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap-index.xml' ] as $path ) {
            $url     = $this->join_url( $base_url, $path );
            $body    = $this->fetch( $url );
            if ( is_wp_error( $body ) ) { continue; }
            $entries = $this->parse_sitemap( $body );
            if ( ! empty( $entries ) ) {
                return $entries;
            }
        }
        return new WP_Error( 'swps_no_sitemap', __( 'No sitemap found.', 'stratawp-seo' ) );
    }

    private function parse_sitemap( string $body ): array {
        libxml_use_internal_errors( true );
        $xml = @simplexml_load_string( $body );
        libxml_clear_errors();
        if ( ! $xml ) { return []; }

        // Sitemap index → follow first non-image sub-sitemap.
        if ( isset( $xml->sitemap ) ) {
            foreach ( $xml->sitemap as $s ) {
                $loc = trim( (string) $s->loc );
                if ( '' === $loc ) { continue; }
                if ( preg_match( '#(image|video|news)#i', $loc ) ) { continue; }
                $sub_body = $this->fetch( $loc );
                if ( is_wp_error( $sub_body ) ) { continue; }
                $entries = $this->parse_sitemap( $sub_body );
                if ( ! empty( $entries ) ) {
                    return $entries;
                }
            }
            return [];
        }

        $entries = [];
        if ( isset( $xml->url ) ) {
            foreach ( $xml->url as $u ) {
                $loc = trim( (string) $u->loc );
                if ( '' === $loc ) { continue; }
                $lastmod = trim( (string) ( $u->lastmod ?? '' ) );
                $entries[] = [
                    'title' => $this->slug_to_title( $loc ),
                    'url'   => $loc,
                    'date'  => '' !== $lastmod ? gmdate( 'Y-m-d', strtotime( $lastmod ) ?: time() ) : '',
                ];
            }
        }

        // Drop obvious non-post URLs to keep counts comparable across competitors.
        $entries = array_values( array_filter( $entries, function ( $e ) {
            $u = $e['url'];
            if ( preg_match( '#/(category|tag|author|page|product-category|wp-content|wp-includes)/#i', $u ) ) { return false; }
            if ( preg_match( '#\.(jpg|jpeg|png|gif|webp|svg|css|js|pdf)$#i', $u ) ) { return false; }
            return true;
        } ) );

        usort( $entries, fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
        return $entries;
    }

    // ---------- Helpers ----------

    private function slug_to_title( string $url ): string {
        $path = wp_parse_url( $url, PHP_URL_PATH ) ?: '';
        $path = trim( $path, '/' );
        if ( '' === $path ) { return $url; }
        $segments = explode( '/', $path );
        $slug     = end( $segments );
        return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
    }

    private function absolutize( string $href, string $base ): string {
        if ( preg_match( '#^https?://#i', $href ) ) {
            return $href;
        }
        $parts = wp_parse_url( $base );
        if ( ! $parts || empty( $parts['host'] ) ) {
            return $href;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'];
        if ( str_starts_with( $href, '//' ) ) {
            return $scheme . ':' . $href;
        }
        if ( str_starts_with( $href, '/' ) ) {
            return $scheme . '://' . $host . $href;
        }
        return $scheme . '://' . $host . '/' . ltrim( $href, '/' );
    }

    private function join_url( string $base, string $path ): string {
        return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
    }

    private function user_agent(): string {
        return 'StrataWP-SEO/' . SWPS_VERSION . ' (+https://stratawpseo.com)';
    }

    private function compute_velocity( array $snapshots ): float {
        $count = count( $snapshots );
        if ( $count < 2 ) {
            return 0.0;
        }
        $first = $snapshots[0];
        $last  = $snapshots[ $count - 1 ];
        $first_ts = strtotime( $first['ts'] ?? '' );
        $last_ts  = strtotime( $last['ts'] ?? '' );
        if ( ! $first_ts || ! $last_ts || $last_ts <= $first_ts ) {
            return 0.0;
        }
        $days  = max( 1, (int) round( ( $last_ts - $first_ts ) / DAY_IN_SECONDS ) );
        $delta = max( 0, (int) ( $last['post_count'] ?? 0 ) - (int) ( $first['post_count'] ?? 0 ) );
        return round( ( $delta / $days ) * 7, 1 );
    }

    private function normalize_url( string $url ): string {
        $url   = trim( $url );
        $parts = wp_parse_url( $url );
        if ( ! $parts || empty( $parts['host'] ) ) {
            return rtrim( $url, '/' );
        }
        $scheme = strtolower( $parts['scheme'] ?? 'https' );
        $host   = strtolower( $parts['host'] );
        $path   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
        return $scheme . '://' . $host . $path;
    }

    private function derive_label( string $url ): string {
        $host = wp_parse_url( $url, PHP_URL_HOST ) ?: '';
        $host = preg_replace( '#^www\.#i', '', $host );
        return '' !== $host ? $host : $url;
    }

    private function make_id( string $url ): string {
        return substr( md5( $url . microtime() ), 0, 8 );
    }
}
