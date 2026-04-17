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
        return (int) get_option( 'swps_sitemap_urls_per_page', 1000 );
    }

    public function __construct() {
        // Disable WP core sitemaps to prevent conflict detection from blocking us.
        add_filter( 'wp_sitemaps_enabled', '__return_false' );

        // Rewrite rules.
        add_action( 'init', [ $this, 'register_rewrite_rules' ] );

        // Serve sitemaps.
        add_action( 'template_redirect', [ $this, 'serve_sitemap' ], 1 );

        // Ping on publish/update/delete.
        add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 10, 3 );

        // IndexNow verification file.
        add_action( 'template_redirect', [ $this, 'serve_indexnow_key' ], 1 );
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
     * Serve the appropriate sitemap based on the query var.
     */
    public function serve_sitemap(): void {
        $type = get_query_var( 'swps_sitemap', '' );
        if ( empty( $type ) ) {
            return;
        }

        // Skip if Yoast or RankMath active.
        if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) {
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
            if ( in_array( $type, $taxonomies, true ) && ! get_option( "swps_sitemap_exclude_{$type}", 0 ) ) {
                $this->render_taxonomy_sitemap( $type );
            } else {
                // Post type sitemap.
                $post_types = get_post_types( [ 'public' => true ] );
                if ( in_array( $type, $post_types, true ) && ! get_option( "swps_sitemap_exclude_{$type}", 0 ) ) {
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
            if ( 'attachment' === $pt || get_option( "swps_sitemap_exclude_{$pt}", 0 ) ) {
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
            if ( 'post_format' === $tax || get_option( "swps_sitemap_exclude_{$tax}", 0 ) ) {
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
     * Submit recent post URLs to IndexNow (Bing/Yandex/Seznam).
     *
     * Google retired the sitemap ping endpoint in 2023; Bing did the same.
     * IndexNow is now the supported push protocol for Bing — and Bing's index
     * powers ChatGPT search.
     *
     * @return array{submitted:int, status:int, error:string} Result summary.
     */
    public function ping_search_engines(): array {
        $key = get_option( 'swps_indexnow_key', '' );
        if ( empty( $key ) ) {
            return [
                'submitted' => 0,
                'status'    => 0,
                'error'     => __( 'IndexNow key is not set. Generate one to enable pings.', 'stratawp-seo' ),
            ];
        }

        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ] );

        if ( empty( $posts ) ) {
            return [
                'submitted' => 0,
                'status'    => 0,
                'error'     => __( 'No published posts to submit.', 'stratawp-seo' ),
            ];
        }

        $urls = array_values( array_filter( array_map( 'get_permalink', $posts ) ) );

        $response = wp_remote_post( 'https://api.indexnow.org/indexnow', [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
                'key'         => $key,
                'keyLocation' => home_url( "/{$key}.txt" ),
                'urlList'     => $urls,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'submitted' => 0,
                'status'    => 0,
                'error'     => $response->get_error_message(),
            ];
        }

        return [
            'submitted' => count( $urls ),
            'status'    => (int) wp_remote_retrieve_response_code( $response ),
            'error'     => '',
        ];
    }

    /**
     * Ping IndexNow with a specific URL.
     */
    private function ping_indexnow( string $url ): void {
        $key = get_option( 'swps_indexnow_key', '' );
        if ( empty( $key ) ) {
            return;
        }

        wp_remote_post( 'https://api.indexnow.org/indexnow', [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( [
                'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
                'key'         => $key,
                'keyLocation' => home_url( "/{$key}.txt" ),
                'urlList'     => [ $url ],
            ] ),
        ] );
    }

    /**
     * Serve the IndexNow verification file.
     */
    public function serve_indexnow_key(): void {
        $key = get_option( 'swps_indexnow_key', '' );
        if ( empty( $key ) ) {
            return;
        }

        $request_uri = trim( $_SERVER['REQUEST_URI'] ?? '', '/' );
        if ( $request_uri === $key . '.txt' ) {
            header( 'Content-Type: text/plain; charset=UTF-8' );
            echo $key;
            exit;
        }
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
