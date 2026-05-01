<?php
/**
 * Internal Linking Engine — core orchestrator.
 *
 * Manages the link graph (swps_link_graph table), coordinates keyword
 * and AI engines, provides the editor metabox, and handles incremental
 * updates on save_post.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Internal_Links {

    private const GRAPH_TABLE = 'swps_link_graph';
    private const CRON_HOOK   = 'swps_link_maintenance';

    private SWPS_Link_Keyword_Engine $keyword_engine;
    private SWPS_Link_AI_Engine $ai_engine;

    public function __construct( SWPS_Link_Keyword_Engine $keyword_engine, SWPS_Link_AI_Engine $ai_engine ) {
        $this->keyword_engine = $keyword_engine;
        $this->ai_engine      = $ai_engine;

        if ( ! get_option( 'swps_internal_links_enabled', 1 ) ) {
            return;
        }

        // Incremental updates on post save.
        add_action( 'save_post', [ $this, 'on_save_post' ], 20 );
        add_action( 'trashed_post', [ $this, 'on_trash_post' ] );
        add_action( 'deleted_post', [ $this, 'on_trash_post' ] );

        // Editor metabox.
        add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );

        // AJAX endpoints.
        add_action( 'wp_ajax_swps_link_suggestions', [ $this, 'ajax_get_suggestions' ] );
        add_action( 'wp_ajax_swps_link_dismiss', [ $this, 'ajax_dismiss' ] );
        add_action( 'wp_ajax_swps_link_insert', [ $this, 'ajax_insert' ] );
        add_action( 'wp_ajax_swps_link_deep_analysis', [ $this, 'ajax_deep_analysis' ] );

        // Admin assets.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Weekly maintenance cron.
        add_action( self::CRON_HOOK, [ $this, 'run_maintenance' ] );

        // Generator integration.
        add_filter( 'swps_user_prompt', [ $this, 'enrich_generation_prompt' ], 10, 3 );
        add_action( 'swps_post_created', [ $this, 'on_post_generated' ], 10, 3 );
    }

    /**
     * Create the link graph table.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . self::GRAPH_TABLE;

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_post_id BIGINT UNSIGNED NOT NULL,
            target_post_id BIGINT UNSIGNED NOT NULL,
            status ENUM('existing','suggested','dismissed','inserted') NOT NULL DEFAULT 'suggested',
            match_type ENUM('keyword','ai','manual') NOT NULL DEFAULT 'keyword',
            relevance_score FLOAT NOT NULL DEFAULT 0,
            anchor_text VARCHAR(255) DEFAULT NULL,
            anchor_context TEXT DEFAULT NULL,
            ai_enriched TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_source_target (source_post_id, target_post_id),
            KEY idx_source_status (source_post_id, status),
            KEY idx_target (target_post_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Schedule the maintenance cron.
     */
    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
        }
    }

    // -------------------------------------------------------------------------
    // Link Graph CRUD
    // -------------------------------------------------------------------------

    /**
     * Get all link graph entries for a source post.
     *
     * @param int    $post_id Source post ID.
     * @param string $status  Filter by status ('all' for everything).
     * @return array Array of graph row arrays.
     */
    public function get_links( int $post_id, string $status = 'all' ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::GRAPH_TABLE;

        if ( 'all' === $status ) {
            return $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE source_post_id = %d ORDER BY relevance_score DESC", $post_id ),
                ARRAY_A
            ) ?: [];
        }

        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE source_post_id = %d AND status = %s ORDER BY relevance_score DESC", $post_id, $status ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Upsert a link graph entry.
     *
     * @param array $data Graph entry data.
     */
    public function upsert_link( array $data ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::GRAPH_TABLE;
        $now   = current_time( 'mysql' );

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status FROM {$table} WHERE source_post_id = %d AND target_post_id = %d",
                $data['source_post_id'],
                $data['target_post_id']
            ),
            ARRAY_A
        );

        if ( $existing ) {
            // Don't overwrite dismissed entries with new suggestions.
            if ( 'dismissed' === $existing['status'] && 'suggested' === ( $data['status'] ?? 'suggested' ) ) {
                return;
            }

            $wpdb->update(
                $table,
                array_merge( $data, [ 'updated_at' => $now ] ),
                [ 'id' => $existing['id'] ],
            );
        } else {
            $wpdb->insert(
                $table,
                array_merge( $data, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ] ),
            );
        }
    }

    /**
     * Update the status of a link graph entry.
     *
     * @param int    $source_post_id Source post ID.
     * @param int    $target_post_id Target post ID.
     * @param string $status         New status.
     */
    public function update_status( int $source_post_id, int $target_post_id, string $status ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::GRAPH_TABLE;

        $wpdb->update(
            $table,
            [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'source_post_id' => $source_post_id, 'target_post_id' => $target_post_id ],
        );
    }

    // -------------------------------------------------------------------------
    // Post Analysis
    // -------------------------------------------------------------------------

    /**
     * Fully analyze a post: detect existing links, run keyword matching, update graph.
     *
     * @param int $post_id Post to analyze.
     */
    public function analyze_post( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return;
        }

        // Index terms.
        $this->keyword_engine->index_post( $post_id );

        // Detect existing internal links in content.
        $this->detect_existing_links( $post_id, $post->post_content );

        // Find keyword-matched candidates.
        $threshold = (float) get_option( 'swps_link_relevance_threshold', 0.3 );
        $max       = (int) get_option( 'swps_link_max_suggestions', 10 );
        $related   = $this->keyword_engine->find_related( $post_id, $threshold, $max );

        foreach ( $related as $candidate ) {
            $this->upsert_link( [
                'source_post_id' => $post_id,
                'target_post_id' => $candidate['post_id'],
                'status'         => 'suggested',
                'match_type'     => 'keyword',
                'relevance_score' => $candidate['score'],
            ] );
        }
    }

    /**
     * Detect existing internal links in post content and record them.
     *
     * @param int    $post_id Post ID.
     * @param string $content Post HTML content.
     */
    public function detect_existing_links( int $post_id, string $content ): void {
        $home_url = home_url();

        if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
            return;
        }

        foreach ( $matches as $match ) {
            $href        = $match[1];
            $anchor_text = wp_strip_all_tags( $match[2] );

            // Only internal links.
            if ( strpos( $href, $home_url ) !== 0 && strpos( $href, '/' ) !== 0 ) {
                continue;
            }

            $target_id = url_to_postid( $href );
            if ( ! $target_id || $target_id === $post_id ) {
                continue;
            }

            $this->upsert_link( [
                'source_post_id' => $post_id,
                'target_post_id' => $target_id,
                'status'         => 'existing',
                'match_type'     => 'manual',
                'relevance_score' => 1.0,
                'anchor_text'    => sanitize_text_field( mb_substr( $anchor_text, 0, 255 ) ),
            ] );
        }
    }

    /**
     * Rebuild the entire link index and graph for all published posts.
     *
     * Processes posts in batches. Call repeatedly with increasing offset
     * until return value indicates completion.
     *
     * @param int $offset Start from this post index.
     * @param int $batch  Posts per batch.
     * @return array ['processed' => int, 'total' => int, 'done' => bool]
     */
    public function rebuild_batch( int $offset = 0, int $batch = 50 ): array {
        $post_types = (array) get_option( 'swps_internal_links_post_types', [ 'post', 'page' ] );

        $posts = get_posts( [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $batch,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ] );

        foreach ( $posts as $pid ) {
            $this->analyze_post( $pid );
        }

        $total = 0;
        foreach ( $post_types as $pt ) {
            $counts = wp_count_posts( $pt );
            $total += (int) ( $counts->publish ?? 0 );
        }

        return [
            'processed' => $offset + count( $posts ),
            'total'     => $total,
            'done'      => count( $posts ) < $batch,
        ];
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Incremental update when a post is saved.
     */
    public function on_save_post( int $post_id ): void {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Skip during generation to avoid double-processing.
        if ( defined( 'SWPS_GENERATING' ) && SWPS_GENERATING ) {
            return;
        }

        $post_types = (array) get_option( 'swps_internal_links_post_types', [ 'post', 'page' ] );
        if ( ! in_array( get_post_type( $post_id ), $post_types, true ) ) {
            return;
        }

        $this->analyze_post( $post_id );
    }

    /**
     * Clean up graph entries when a post is trashed or deleted.
     */
    public function on_trash_post( int $post_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::GRAPH_TABLE;

        $wpdb->delete( $table, [ 'source_post_id' => $post_id ], [ '%d' ] );
        $wpdb->delete( $table, [ 'target_post_id' => $post_id ], [ '%d' ] );

        $this->keyword_engine->clear_post( $post_id );
    }

    /**
     * Weekly maintenance cron: clean stale entries.
     */
    public function run_maintenance(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::GRAPH_TABLE;

        // Remove entries where source or target post no longer exists or is not published.
        $wpdb->query(
            "DELETE g FROM {$table} g
             LEFT JOIN {$wpdb->posts} p1 ON g.source_post_id = p1.ID AND p1.post_status = 'publish'
             LEFT JOIN {$wpdb->posts} p2 ON g.target_post_id = p2.ID AND p2.post_status = 'publish'
             WHERE p1.ID IS NULL OR p2.ID IS NULL"
        );

        // Clean orphaned index entries.
        $index_table = $wpdb->prefix . 'swps_link_index';
        $wpdb->query(
            "DELETE li FROM {$index_table} li
             LEFT JOIN {$wpdb->posts} p ON li.post_id = p.ID AND p.post_status = 'publish'
             WHERE p.ID IS NULL"
        );
    }

    // -------------------------------------------------------------------------
    // Generator Integration
    // -------------------------------------------------------------------------

    /**
     * Enrich the generation prompt with internal link suggestions.
     *
     * Hooked to swps_user_prompt filter.
     */
    public function enrich_generation_prompt( string $prompt, string $topic, string $site_context ): string {
        if ( ! get_option( 'swps_internal_links_in_generation', 1 ) ) {
            return $prompt;
        }

        if ( empty( $topic ) ) {
            return $prompt;
        }

        // Find posts related to the generation topic by indexing a temporary "virtual post".
        // Instead, we do a simpler approach: search the link index for matching terms.
        $terms = ( new SWPS_Link_Keyword_Engine() )->tokenize_public( $topic );
        if ( empty( $terms ) ) {
            return $prompt;
        }

        global $wpdb;
        $index_table = $wpdb->prefix . 'swps_link_index';

        $placeholders = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $matches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, SUM(weight) as total_weight
                 FROM {$index_table}
                 WHERE term IN ({$placeholders})
                 GROUP BY post_id
                 ORDER BY total_weight DESC
                 LIMIT 5",
                $terms
            ),
            ARRAY_A
        );

        if ( empty( $matches ) ) {
            return $prompt;
        }

        $link_section = "\n=== PRIORITY INTERNAL LINKS ===\n";
        $link_section .= "In addition to the existing pages above, prioritize linking to these highly relevant posts:\n";

        foreach ( $matches as $match ) {
            $post = get_post( (int) $match['post_id'] );
            if ( ! $post ) {
                continue;
            }
            $url   = get_permalink( $post->ID );
            $title = $post->post_title;
            $link_section .= "- \"{$title}\" → {$url}\n";
        }

        return $prompt . $link_section;
    }

    /**
     * After a post is generated, record its internal links in the graph.
     *
     * Hooked to swps_post_created action.
     */
    public function on_post_generated( int $post_id, array $ai_result, array $post_data ): void {
        $content = $post_data['post_content'] ?? '';
        if ( empty( $content ) ) {
            $content = get_post_field( 'post_content', $post_id );
        }

        // Index the new post and detect its links.
        $this->keyword_engine->index_post( $post_id );
        $this->detect_existing_links( $post_id, $content );
    }

    // -------------------------------------------------------------------------
    // Editor Metabox
    // -------------------------------------------------------------------------

    /**
     * Register the Internal Links metabox.
     */
    public function register_metabox(): void {
        $post_types = (array) get_option( 'swps_internal_links_post_types', [ 'post', 'page' ] );

        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'swps_internal_links',
                __( 'Internal Links', 'stratawp-seo' ),
                [ $this, 'render_metabox' ],
                $post_type,
                'normal',
                'default'
            );
        }
    }

    /**
     * Render the Internal Links metabox.
     */
    public function render_metabox( WP_Post $post ): void {
        $existing  = $this->get_links( $post->ID, 'existing' );
        $inserted  = $this->get_links( $post->ID, 'inserted' );
        $suggested = $this->get_links( $post->ID, 'suggested' );

        $all_existing = array_merge( $existing, $inserted );

        wp_nonce_field( 'swps_internal_links', 'swps_internal_links_nonce' );
        ?>
        <div id="swps-internal-links-metabox">
            <p class="swps-link-stats">
                <?php
                printf(
                    esc_html__( 'This post has %1$d internal links. %2$d suggestions available.', 'stratawp-seo' ),
                    count( $all_existing ),
                    count( $suggested )
                );
                ?>
            </p>

            <?php if ( ! empty( $all_existing ) ) : ?>
                <h4><?php esc_html_e( 'Existing Links', 'stratawp-seo' ); ?></h4>
                <ul class="swps-existing-links">
                    <?php foreach ( $all_existing as $link ) :
                        $target = get_post( (int) $link['target_post_id'] );
                        if ( ! $target ) continue;
                    ?>
                        <li>
                            <a href="<?php echo esc_url( get_edit_post_link( $target->ID ) ); ?>" target="_blank">
                                <?php echo esc_html( $target->post_title ); ?>
                            </a>
                            <?php if ( ! empty( $link['anchor_text'] ) ) : ?>
                                <span class="swps-anchor-text">(<?php echo esc_html( $link['anchor_text'] ); ?>)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ( ! empty( $suggested ) ) : ?>
                <h4><?php esc_html_e( 'Suggested Links', 'stratawp-seo' ); ?></h4>
                <ul class="swps-suggested-links">
                    <?php foreach ( $suggested as $link ) :
                        $target = get_post( (int) $link['target_post_id'] );
                        if ( ! $target ) continue;
                        $score = (float) $link['relevance_score'];
                        $color = $score >= 0.7 ? 'green' : ( $score >= 0.4 ? 'orange' : 'red' );
                        $anchor = ! empty( $link['anchor_text'] ) ? $link['anchor_text'] : $target->post_title;
                    ?>
                        <li data-target-id="<?php echo esc_attr( $target->ID ); ?>"
                            data-target-url="<?php echo esc_url( get_permalink( $target->ID ) ); ?>"
                            data-anchor="<?php echo esc_attr( $anchor ); ?>">
                            <span class="swps-score-dot" style="color:<?php echo esc_attr( $color ); ?>;">&#9679;</span>
                            <a href="<?php echo esc_url( get_edit_post_link( $target->ID ) ); ?>" target="_blank">
                                <?php echo esc_html( $target->post_title ); ?>
                            </a>
                            <span class="swps-suggested-anchor"><?php echo esc_html( $anchor ); ?></span>
                            <?php if ( ! empty( $link['anchor_context'] ) ) : ?>
                                <span class="swps-rationale"><?php echo esc_html( $link['anchor_context'] ); ?></span>
                            <?php endif; ?>
                            <span class="swps-link-actions">
                                <button type="button" class="button button-small swps-insert-link"><?php esc_html_e( 'Insert', 'stratawp-seo' ); ?></button>
                                <button type="button" class="button button-small swps-dismiss-link"><?php esc_html_e( 'Dismiss', 'stratawp-seo' ); ?></button>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="swps-link-metabox-actions">
                <button type="button" class="button" id="swps-deep-analysis" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                    <?php esc_html_e( 'Deep Analysis (AI)', 'stratawp-seo' ); ?>
                </button>
                <span id="swps-deep-analysis-status"></span>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    /**
     * AJAX: Get link suggestions for a post.
     */
    public function ajax_get_suggestions(): void {
        check_ajax_referer( 'swps_internal_links', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
        }

        $suggested = $this->get_links( $post_id, 'suggested' );
        $existing  = $this->get_links( $post_id, 'existing' );

        wp_send_json_success( [
            'suggested' => $suggested,
            'existing'  => $existing,
        ] );
    }

    /**
     * AJAX: Dismiss a link suggestion.
     */
    public function ajax_dismiss(): void {
        check_ajax_referer( 'swps_internal_links', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $post_id   = absint( $_POST['post_id'] ?? 0 );
        $target_id = absint( $_POST['target_id'] ?? 0 );

        if ( ! $post_id || ! $target_id ) {
            wp_send_json_error( [ 'message' => 'Invalid IDs.' ] );
        }

        $this->update_status( $post_id, $target_id, 'dismissed' );
        wp_send_json_success();
    }

    /**
     * AJAX: Mark a link as inserted.
     */
    public function ajax_insert(): void {
        check_ajax_referer( 'swps_internal_links', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $post_id     = absint( $_POST['post_id'] ?? 0 );
        $target_id   = absint( $_POST['target_id'] ?? 0 );
        $anchor_text = sanitize_text_field( $_POST['anchor_text'] ?? '' );

        if ( ! $post_id || ! $target_id ) {
            wp_send_json_error( [ 'message' => 'Invalid IDs.' ] );
        }

        $this->upsert_link( [
            'source_post_id' => $post_id,
            'target_post_id' => $target_id,
            'status'         => 'inserted',
            'anchor_text'    => $anchor_text,
        ] );

        wp_send_json_success();
    }

    /**
     * AJAX: Run AI deep analysis for a post's suggestions.
     */
    public function ajax_deep_analysis(): void {
        check_ajax_referer( 'swps_internal_links', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
        }

        // Get current keyword-matched suggestions.
        $suggested = $this->get_links( $post_id, 'suggested' );
        if ( empty( $suggested ) ) {
            wp_send_json_error( [ 'message' => 'No suggestions to analyze.' ] );
        }

        $candidates = array_map( fn( $link ) => [
            'post_id' => (int) $link['target_post_id'],
            'score'   => (float) $link['relevance_score'],
        ], $suggested );

        $result = $this->ai_engine->analyze( $post_id, $candidates );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        // Update graph with AI results.
        foreach ( $result as $enriched ) {
            $this->upsert_link( [
                'source_post_id'  => $post_id,
                'target_post_id'  => $enriched['post_id'],
                'relevance_score' => $enriched['relevance_score'],
                'anchor_text'     => $enriched['anchor_text'],
                'anchor_context'  => $enriched['rationale'],
                'ai_enriched'     => 1,
                'match_type'      => 'ai',
            ] );
        }

        // Return updated suggestions.
        $updated = $this->get_links( $post_id, 'suggested' );
        wp_send_json_success( [ 'suggestions' => $updated ] );
    }

    // -------------------------------------------------------------------------
    // Admin Assets
    // -------------------------------------------------------------------------

    /**
     * Enqueue metabox JS on post edit screens.
     */
    public function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $post_types = (array) get_option( 'swps_internal_links_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $screen->post_type, $post_types, true ) ) {
            return;
        }

        wp_enqueue_script(
            'swps-internal-links',
            SWPS_PLUGIN_URL . 'admin/js/internal-links.js',
            [ 'jquery' ],
            SWPS_VERSION,
            true
        );

        wp_localize_script( 'swps-internal-links', 'swpsInternalLinks', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'swps_internal_links' ),
            'i18n'    => [
                'inserting'  => __( 'Inserting...', 'stratawp-seo' ),
                'analyzing'  => __( 'Running AI analysis...', 'stratawp-seo' ),
                'done'       => __( 'Done!', 'stratawp-seo' ),
                'error'      => __( 'Error occurred.', 'stratawp-seo' ),
                'noProvider' => __( 'Configure an AI provider in Settings to use Deep Analysis.', 'stratawp-seo' ),
            ],
        ] );
    }
}
