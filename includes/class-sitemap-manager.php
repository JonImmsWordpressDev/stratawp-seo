<?php
/**
 * Full XML Sitemap Manager.
 *
 * Generates sitemap index with sub-sitemaps for post types, taxonomies,
 * and authors. Supports per-URL priority/changefreq, image entries,
 * and IndexNow pinging.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Sitemap_Manager {

    private function get_urls_per_sitemap(): int {
        return max( 100, min( 50000, (int) get_option( 'swps_sitemap_urls_per_page', 1000 ) ) );
    }
    private function is_post_type_hidden_from_sitemap( string $post_type ): bool {
        return 'attachment' === $post_type
            || (bool) get_option( "swps_sitemap_exclude_{$post_type}", 0 )
            || (bool) get_option( "swps_noindex_{$post_type}", 0 );
    }

    private function is_taxonomy_hidden_from_sitemap( string $taxonomy ): bool {
        return 'post_format' === $taxonomy
            || (bool) get_option( "swps_sitemap_exclude_{$taxonomy}", 0 )
            || (bool) get_option( "swps_noindex_{$taxonomy}", 0 );
    }

    public function __construct() {
        // Disable WP core sitemaps to prevent conflict detection from blocking us.
        add_filter( 'wp_sitemaps_enabled', '__return_false' );

        // Rewrite rules.
        add_action( 'init', [ $this, 'register_rewrite_rules' ] );

        // Auto-flush after a plugin upgrade so cached rewrite_rules pick up
        // new sitemap URLs without the user manually visiting Settings →
        // Permalinks. Runs once per version change.
        add_action( 'wp_loaded', [ $this, 'maybe_flush_rewrites' ] );

        // Serve sitemaps.
        add_action( 'template_redirect', [ $this, 'serve_sitemap' ], 1 );

        // Ping on publish/update/delete.
        add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 10, 3 );

        // IndexNow verification file — hooked early so it works even if rewrite
        // rules haven't been flushed and before any template_redirect handlers
        // (canonical redirects, caching plugins) can intercept the request.
        add_action( 'init', [ $this, 'serve_indexnow_key' ], 1 );

        // REST API fallback for the key — always reachable, bypasses any
        // server-level rules that might block top-level .txt requests.
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /**
     * Register rewrite rules for all sitemap URLs.
     */
    public function register_rewrite_rules(): void {
        // Sitemap index.
        add_rewrite_rule( 'sitemap_index\.xml$', 'index.php?swps_sitemap=index', 'top' );

        // Post type sitemaps.
        add_rewrite_rule( '([a-z0-9_-]+)-sitemap(\d*)\.xml$', 'index.php?swps_sitemap=$matches[1]&swps_sitemap_page=$matches[2]', 'top' );

        // Author sitemap.
        add_rewrite_rule( 'author-sitemap\.xml$', 'index.php?swps_sitemap=author', 'top' );

        // Legacy URL redirect.
        add_rewrite_rule( 'swps-sitemap\.xml$', 'index.php?swps_sitemap=legacy_redirect', 'top' );

        add_filter( 'query_vars', function ( array $vars ): array {
            $vars[] = 'swps_sitemap';
            $vars[] = 'swps_sitemap_page';
            return $vars;
        } );
    }

    /**
     * Flush rewrite rules once per plugin version so users do not have to
     * manually re-save permalinks after every upgrade.
     */
    public function maybe_flush_rewrites(): void {
        if ( get_option( 'swps_rewrite_version' ) === SWPS_VERSION ) {
            return;
        }
        update_option( 'swps_rewrite_version', SWPS_VERSION, false );
        flush_rewrite_rules( false );
    }

    /**
     * Serve the appropriate sitemap based on the query var.
     */
    public function serve_sitemap(): void {
        $type = get_query_var( 'swps_sitemap', '' );
        if ( empty( $type ) ) {
            return;
        }

        // Skip if another SEO plugin is actively handling sitemaps.
        if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
            return;
        }

        // Legacy redirect.
        if ( 'legacy_redirect' === $type ) {
            wp_redirect( home_url( '/sitemap_index.xml' ), 301 );
            exit;
        }

        $page = (int) get_query_var( 'swps_sitemap_page', 1 );
        if ( $page < 1 ) {
            $page = 1;
        }

        header( 'Content-Type: application/xml; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex' );

        if ( 'index' === $type ) {
            $this->render_sitemap_index();
        } elseif ( 'author' === $type ) {
            $this->render_author_sitemap();
        } else {
            // Check if it's a taxonomy.
            $taxonomies = get_taxonomies( [ 'public' => true ] );
            if ( in_array( $type, $taxonomies, true ) && ! $this->is_taxonomy_hidden_from_sitemap( $type ) ) {
                $this->render_taxonomy_sitemap( $type );
            } else {
                // Post type sitemap.
                $post_types = get_post_types( [ 'public' => true ] );
                if ( in_array( $type, $post_types, true ) && ! $this->is_post_type_hidden_from_sitemap( $type ) ) {
                    $this->render_post_type_sitemap( $type, $page );
                } else {
                    status_header( 404 );
                    exit;
                }
            }
        }

        exit;
    }

    /**
     * Render the sitemap index.
     */
    private function render_sitemap_index(): void {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Post type sitemaps.
        $post_types = get_post_types( [ 'public' => true ] );
        foreach ( $post_types as $pt ) {
            if ( $this->is_post_type_hidden_from_sitemap( $pt ) ) {
                continue;
            }

            $count = $this->get_post_type_count( $pt );
            $pages = max( 1, (int) ceil( $count / $this->get_urls_per_sitemap() ) );

            for ( $i = 1; $i <= $pages; $i++ ) {
                $suffix = $i > 1 ? $i : '';
                printf(
                    "<sitemap>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n</sitemap>\n",
                    esc_url( home_url( "/{$pt}-sitemap{$suffix}.xml" ) ),
                    $this->get_post_type_lastmod( $pt )
                );
            }
        }

        // Taxonomy sitemaps.
        $taxonomies = get_taxonomies( [ 'public' => true ] );
        foreach ( $taxonomies as $tax ) {
            if ( $this->is_taxonomy_hidden_from_sitemap( $tax ) ) {
                continue;
            }

            $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => true, 'number' => 1 ] );
            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                continue;
            }

            printf(
                "<sitemap>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n</sitemap>\n",
                esc_url( home_url( "/{$tax}-sitemap.xml" ) ),
                gmdate( 'Y-m-d\TH:i:s+00:00' )
            );
        }

        // Author sitemap.
        if ( ! get_option( 'swps_sitemap_exclude_author', 0 ) ) {
            printf(
                "<sitemap>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n</sitemap>\n",
                esc_url( home_url( '/author-sitemap.xml' ) ),
                gmdate( 'Y-m-d\TH:i:s+00:00' )
            );
        }

        echo '</sitemapindex>';
    }

    /**
     * Render a post type sub-sitemap.
     */
    private function render_post_type_sitemap( string $post_type, int $page ): void {
        if ( $this->is_post_type_hidden_from_sitemap( $post_type ) ) {
            return;
        }

        $offset = ( $page - 1 ) * $this->get_urls_per_sitemap();

        $posts = get_posts( [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $this->get_urls_per_sitemap(),
            'offset'         => $offset,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ] );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        // Include homepage on first page of 'page' sitemap.
        if ( 'page' === $post_type && 1 === $page ) {
            printf(
                "<url>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n  <priority>1.0</priority>\n</url>\n",
                esc_url( home_url( '/' ) ),
                gmdate( 'Y-m-d\TH:i:s+00:00' )
            );
        }

        foreach ( $posts as $post ) {
            // Respect exclusions.
            if ( get_post_meta( $post->ID, '_swps_sitemap_exclude', true ) ) {
                continue;
            }

            // Respect noindex.
            $robots = get_post_meta( $post->ID, '_swps_robots', true );
            if ( ! empty( $robots ) && str_contains( (string) $robots, 'noindex' ) ) {
                continue;
            }

            $modified = get_post_modified_time( 'U', true, $post );
            $priority = get_post_meta( $post->ID, '_swps_sitemap_priority', true );
            if ( empty( $priority ) ) {
                $priority = 'page' === $post_type ? '0.8' : '0.6';
            }
            $changefreq = get_post_meta( $post->ID, '_swps_sitemap_changefreq', true ) ?: 'weekly';

            echo "<url>\n";
            printf( "  <loc>%s</loc>\n", esc_url( get_permalink( $post ) ) );
            printf( "  <lastmod>%s</lastmod>\n", gmdate( 'Y-m-d\TH:i:s+00:00', $modified ) );
            printf( "  <changefreq>%s</changefreq>\n", esc_xml( $changefreq ) );
            printf( "  <priority>%s</priority>\n", esc_attr( $priority ) );

            // Image entries.
            if ( ! get_option( 'swps_sitemap_exclude_images', 0 ) ) {
                $this->render_image_entries( $post->ID );
            }

            echo "</url>\n";
        }

        echo '</urlset>';
    }

    /**
     * Render a taxonomy sub-sitemap.
     */
    private function render_taxonomy_sitemap( string $taxonomy ): void {
        if ( $this->is_taxonomy_hidden_from_sitemap( $taxonomy ) ) {
            return;
        }

        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'number'     => $this->get_urls_per_sitemap(),
        ] );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                // Respect exclusions.
                if ( get_term_meta( $term->term_id, '_swps_sitemap_exclude', true ) ) {
                    continue;
                }

                $robots = get_term_meta( $term->term_id, '_swps_robots', true );
                if ( ! empty( $robots ) && str_contains( (string) $robots, 'noindex' ) ) {
                    continue;
                }

                echo "<url>\n";
                printf( "  <loc>%s</loc>\n", esc_url( get_term_link( $term ) ) );
                printf( "  <changefreq>weekly</changefreq>\n" );
                printf( "  <priority>0.4</priority>\n" );
                echo "</url>\n";
            }
        }

        echo '</urlset>';
    }

    /**
     * Render author sub-sitemap.
     */
    private function render_author_sitemap(): void {
        $authors = get_users( [
            'has_published_posts' => true,
            'fields'             => [ 'ID', 'user_nicename' ],
        ] );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ( $authors as $author ) {
            echo "<url>\n";
            printf( "  <loc>%s</loc>\n", esc_url( get_author_posts_url( $author->ID ) ) );
            printf( "  <changefreq>weekly</changefreq>\n" );
            printf( "  <priority>0.3</priority>\n" );
            echo "</url>\n";
        }

        echo '</urlset>';
    }

    /**
     * Render image entries for a post.
     */
    private function render_image_entries( int $post_id ): void {
        // Featured image.
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $img_url = wp_get_attachment_url( $thumb_id );
            if ( $img_url ) {
                echo "  <image:image>\n";
                printf( "    <image:loc>%s</image:loc>\n", esc_url( $img_url ) );
                $alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
                if ( $alt ) {
                    printf( "    <image:title>%s</image:title>\n", esc_xml( $alt ) );
                }
                echo "  </image:image>\n";
            }
        }

        // In-content images.
        $post = get_post( $post_id );
        if ( $post && preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches ) ) {
            $seen = [ $thumb_id ? wp_get_attachment_url( $thumb_id ) : '' ];
            foreach ( $matches[1] as $img_url ) {
                if ( in_array( $img_url, $seen, true ) ) {
                    continue;
                }
                $seen[] = $img_url;
                echo "  <image:image>\n";
                printf( "    <image:loc>%s</image:loc>\n", esc_url( $img_url ) );
                echo "  </image:image>\n";
            }
        }
    }

    /**
     * Ping search engines and IndexNow on post status change.
     */
    public function on_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
            return;
        }

        if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return;
        }

        $this->ping_search_engines();
        $this->ping_indexnow( get_permalink( $post ) );
    }

    /**
     * Submit recent URLs to IndexNow (Bing/Yandex/Seznam).
     *
     * Google retired the sitemap ping endpoint in 2023; Bing did the same.
     * IndexNow is now the supported push protocol for Bing — and Bing's index
     * powers ChatGPT search.
     *
     * @return array{submitted:int, status:int, error:string, body:string} Result summary.
     */
    public function ping_search_engines(): array {
        $key = get_option( 'swps_indexnow_key', '' );
        if ( empty( $key ) ) {
            return [
                'submitted' => 0,
                'status'    => 0,
                'error'     => __( 'IndexNow key is not set. Generate one to enable pings.', 'stratawp-seo' ),
                'body'      => '',
            ];
        }

        $urls = $this->collect_recent_urls();

        if ( empty( $urls ) ) {
            return [
                'submitted' => 0,
                'status'    => 0,
                'error'     => __( 'No published content to submit.', 'stratawp-seo' ),
                'body'      => '',
            ];
        }

        $response = wp_remote_post( 'https://api.indexnow.org/indexnow', [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
            'body'    => wp_json_encode( [
                'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
                'key'         => $key,
                'keyLocation' => $this->get_key_location( $key ),
                'urlList'     => $urls,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'submitted' => 0,
                'status'    => 0,
                'error'     => $response->get_error_message(),
                'body'      => '',
            ];
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );

        return [
            'submitted' => count( $urls ),
            'status'    => $status,
            'error'     => '',
            'body'      => $body,
        ];
    }

    /**
     * Collect a deduped list of recently modified URLs across public post
     * types (capped at IndexNow's 10,000-URL per-request limit).
     *
     * @return string[]
     */
    private function collect_recent_urls(): array {
        $post_types = get_post_types( [ 'public' => true ] );
        $post_types = array_filter( $post_types, function ( $pt ) {
            return ! $this->is_post_type_hidden_from_sitemap( $pt );
        } );

        if ( empty( $post_types ) ) {
            return [];
        }

        $posts = get_posts( [
            'post_type'      => array_values( $post_types ),
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ] );

        $urls = array_values( array_filter( array_map( 'get_permalink', $posts ) ) );
        $urls = array_values( array_unique( $urls ) );

        /**
         * Filter the URL list submitted to IndexNow.
         *
         * @param string[] $urls Permalinks queued for submission.
         */
        $urls = (array) apply_filters( 'swps_indexnow_url_list', $urls );

        return array_slice( $urls, 0, 10000 );
    }

    /**
     * Build the keyLocation URL. Prefers a pretty-permalink rewrite when
     * available; falls back to the REST endpoint, which is always reachable.
     */
    private function get_key_location( string $key ): string {
        if ( get_option( 'permalink_structure' ) ) {
            return home_url( "/{$key}.txt" );
        }
        return rest_url( 'stratawp-seo/v1/indexnow-key.txt' );
    }

    /**
     * Verify both key locations are reachable. Used by the admin diagnostic.
     *
     * @return array{ok:bool, checks:array<int,array{label:string,url:string,status:int,ok:bool,error:string}>}
     */
    public function verify_key_url(): array {
        $key = get_option( 'swps_indexnow_key', '' );
        if ( empty( $key ) ) {
            return [
                'ok'     => false,
                'checks' => [
                    [
                        'label'  => __( 'Key', 'stratawp-seo' ),
                        'url'    => '',
                        'status' => 0,
                        'ok'     => false,
                        'error'  => __( 'IndexNow key is not set.', 'stratawp-seo' ),
                    ],
                ],
            ];
        }

        $targets = [
            [ 'label' => __( 'Pretty URL', 'stratawp-seo' ), 'url' => home_url( "/{$key}.txt" ) ],
            [ 'label' => __( 'REST fallback', 'stratawp-seo' ), 'url' => rest_url( 'stratawp-seo/v1/indexnow-key.txt' ) ],
        ];

        $checks = [];
        $any_ok = false;

        foreach ( $targets as $target ) {
            $resp = wp_remote_get( $target['url'], [ 'timeout' => 10, 'sslverify' => false ] );
            if ( is_wp_error( $resp ) ) {
                $checks[] = [
                    'label'  => $target['label'],
                    'url'    => $target['url'],
                    'status' => 0,
                    'ok'     => false,
                    'error'  => $resp->get_error_message(),
                ];
                continue;
            }
            $status = (int) wp_remote_retrieve_response_code( $resp );
            $body   = trim( (string) wp_remote_retrieve_body( $resp ) );
            $ok     = ( 200 === $status && $body === $key );
            if ( $ok ) {
                $any_ok = true;
            }
            $error = '';
            if ( 200 !== $status ) {
                /* translators: %d: HTTP status code */
                $error = sprintf( __( 'HTTP %d', 'stratawp-seo' ), $status );
            } elseif ( $body !== $key ) {
                $excerpt = substr( $body, 0, 80 );
                if ( strlen( $body ) > 80 ) {
                    $excerpt .= '…';
                }
                $error = sprintf(
                    /* translators: %s: response body excerpt */
                    __( 'Returned content does not match the key. Got: %s', 'stratawp-seo' ),
                    '"' . $excerpt . '"'
                );
            }
            $checks[] = [
                'label'  => $target['label'],
                'url'    => $target['url'],
                'status' => $status,
                'ok'     => $ok,
                'error'  => $error,
            ];
        }

        return [ 'ok' => $any_ok, 'checks' => $checks ];
    }

    /**
     * Ping IndexNow with a specific URL.
     */
    private function ping_indexnow( string $url ): void {
        $key = get_option( 'swps_indexnow_key', '' );
        if ( empty( $key ) || empty( $url ) ) {
            return;
        }

        wp_remote_post( 'https://api.indexnow.org/indexnow', [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [ 'Content-Type' => 'application/json; charset=utf-8' ],
            'body'     => wp_json_encode( [
                'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
                'key'         => $key,
                'keyLocation' => $this->get_key_location( $key ),
                'urlList'     => [ $url ],
            ] ),
        ] );
    }

    /**
     * Serve the IndexNow verification file.
     *
     * Hooked to `init` so it runs before `template_redirect` (canonical
     * redirects, caching plugins) and even when no rewrite rule matches.
     * Path comparison strips the query string and is case-insensitive.
     */
    public function serve_indexnow_key(): void {
        $key = get_option( 'swps_indexnow_key', '' );
        if ( empty( $key ) ) {
            return;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( '' === $request_uri ) {
            return;
        }

        $path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
        $path = strtolower( trim( $path, '/' ) );

        if ( $path !== strtolower( $key ) . '.txt' ) {
            return;
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex' );
        echo $key;
        exit;
    }

    /**
     * Register REST routes for IndexNow.
     */
    public function register_rest_routes(): void {
        register_rest_route( 'stratawp-seo/v1', '/indexnow-key(?:\.txt)?', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ $this, 'rest_indexnow_key' ],
        ] );
    }

    /**
     * Emit the IndexNow key as raw text for the REST fallback.
     *
     * IndexNow validates the keyLocation by byte-matching the response body
     * against the key. Returning a `WP_REST_Response` would JSON-encode the
     * string (wrapping it in quotes), so we send raw output and exit before
     * the REST controller can serialize anything.
     */
    public function rest_indexnow_key(): void {
        $key = get_option( 'swps_indexnow_key', '' );

        // Drop any output buffers the REST server (or other plugins) started
        // so the body we emit isn't appended to a JSON-encoded payload.
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }

        // Replace the application/json header WP_REST_Server queued before
        // dispatch. header_remove() clears it so our header() call wins on
        // SAPIs that don't honour PHP's default replace flag.
        if ( ! headers_sent() ) {
            header_remove( 'Content-Type' );
            header_remove( 'Cache-Control' );
            header_remove( 'Expires' );
            header_remove( 'Pragma' );
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex' );

        if ( empty( $key ) ) {
            status_header( 404 );
            echo 'IndexNow key is not set.';
            exit;
        }

        status_header( 200 );
        echo $key;
        exit;
    }

    /**
     * Get post count for a post type (excluding sitemap-excluded posts).
     */
    private function get_post_type_count( string $post_type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            $post_type
        ) );
    }

    /**
     * Get the most recent lastmod for a post type.
     */
    private function get_post_type_lastmod( string $post_type ): string {
        global $wpdb;
        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            $post_type
        ) );
        return $date ? gmdate( 'Y-m-d\TH:i:s+00:00', strtotime( $date ) ) : gmdate( 'Y-m-d\TH:i:s+00:00' );
    }

    /**
     * Generate IndexNow key on activation.
     */
    public static function generate_indexnow_key(): void {
        if ( ! get_option( 'swps_indexnow_key' ) ) {
            update_option( 'swps_indexnow_key', bin2hex( random_bytes( 16 ) ) );
        }
    }
}
