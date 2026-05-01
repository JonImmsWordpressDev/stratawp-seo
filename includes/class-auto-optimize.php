<?php
/**
 * Auto-Optimize — scans existing posts, proposes AI edits, applies on approval.
 *
 * Pipeline:
 *   1. scan_candidates() — re-scores published posts via SWPS_Content_Scorer
 *      and returns those below the configured threshold.
 *   2. propose_edits()  — asks the AI for concrete find/replace edits + new
 *      meta + projected score, stored per-post.
 *   3. apply_edits()    — applies an approved subset of edits to the post.
 *
 * v4.1 scope: manual review queue. Trust-pattern auto-apply is deferred.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Auto_Optimize {

    public const META_PROPOSAL    = '_swps_optimize_proposal';
    public const META_DISMISSED   = '_swps_optimize_dismissed';
    public const META_PROPOSED_AT = '_swps_optimize_proposed_at';
    public const META_APPLIED_AT  = '_swps_optimize_applied_at';

    public const OPTION_THRESHOLD = 'swps_optimize_threshold';
    public const DEFAULT_THRESHOLD = 75;

    private SWPS_Content_Scorer $scorer;

    public function __construct( SWPS_Content_Scorer $scorer ) {
        $this->scorer = $scorer;

        add_action( 'admin_menu',           [ $this, 'register_menu' ], 20 );
        add_action( 'admin_enqueue_scripts',[ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_swps_optimize_scan',       [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_swps_optimize_scan_chunk', [ $this, 'ajax_scan_chunk' ] );
        add_action( 'wp_ajax_swps_optimize_propose',    [ $this, 'ajax_propose' ] );
        add_action( 'wp_ajax_swps_optimize_apply',      [ $this, 'ajax_apply' ] );
        add_action( 'wp_ajax_swps_optimize_dismiss',    [ $this, 'ajax_dismiss' ] );
        add_action( 'wp_ajax_swps_optimize_undismiss',  [ $this, 'ajax_undismiss' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'stratawp-seo',
            __( 'Auto-Optimize', 'stratawp-seo' ),
            __( 'Auto-Optimize', 'stratawp-seo' ),
            'manage_options',
            'swps-auto-optimize',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
        }
        require SWPS_PLUGIN_DIR . 'templates/auto-optimize-page.php';
    }

    public function enqueue_assets( string $hook ): void {
        if ( 'stratawp-seo_page_swps-auto-optimize' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'swps-page-auto-optimize',
            SWPS_PLUGIN_URL . 'admin/css/pages/auto-optimize.css',
            [ 'swps-tokens', 'swps-components', 'swps-templates' ],
            SWPS_VERSION
        );
        wp_enqueue_script(
            'swps-auto-optimize',
            SWPS_PLUGIN_URL . 'admin/js/auto-optimize.js',
            [ 'jquery' ],
            SWPS_VERSION,
            true
        );
        wp_localize_script( 'swps-auto-optimize', 'swpsOptimize', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'swps_nonce' ),
            'i18n'    => [
                'scoring'     => __( 'Re-scoring all posts...', 'stratawp-seo' ),
                'loading'     => __( 'Loading queue...', 'stratawp-seo' ),
                'proposing'   => __( 'Generating proposal — calling AI...', 'stratawp-seo' ),
                'applying'    => __( 'Applying edits...', 'stratawp-seo' ),
                'dismissing'  => __( 'Dismissing...', 'stratawp-seo' ),
                'confirmDismiss' => __( 'Dismiss this post? It will be hidden from the queue.', 'stratawp-seo' ),
                'noEdits'     => __( 'The AI did not return any actionable edits.', 'stratawp-seo' ),
                'genericFail' => __( 'Request failed.', 'stratawp-seo' ),
                'metaTitle'   => __( 'Meta title', 'stratawp-seo' ),
                'metaDesc'    => __( 'Meta description', 'stratawp-seo' ),
                'focusKw'     => __( 'Focus keyword', 'stratawp-seo' ),
                'metaSection' => __( 'Metadata changes', 'stratawp-seo' ),
                'editsSection'=> __( 'Content edits', 'stratawp-seo' ),
                'estCost'     => __( 'est. cost', 'stratawp-seo' ),
                'words'       => __( 'words', 'stratawp-seo' ),
                'view'        => __( 'View', 'stratawp-seo' ),
                'reGenerate'  => __( 'Re-generate', 'stratawp-seo' ),
                'review'      => __( 'Review', 'stratawp-seo' ),
                'generate'    => __( 'Generate proposal', 'stratawp-seo' ),
                'dismiss'     => __( 'Dismiss', 'stratawp-seo' ),
                'empty'       => __( 'Nothing to optimize. No published posts are below the threshold. Lower the threshold or re-scan.', 'stratawp-seo' ),
                'countOne'    => __( '1 post below threshold', 'stratawp-seo' ),
                'countMany'   => __( '%d posts below threshold', 'stratawp-seo' ),
            ],
        ] );
    }

    /**
     * Scan published posts, re-score, return queue sorted by lowest-score-first.
     *
     * @param int  $threshold         Skip posts at or above this score.
     * @param int  $limit             Max posts to scan.
     * @param bool $include_dismissed Whether to include dismissed posts.
     * @return array<int, array>      Queue rows.
     */
    public function scan_candidates( int $threshold = self::DEFAULT_THRESHOLD, int $limit = 50, bool $include_dismissed = false ): array {
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => max( 1, $limit ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $rows = [];

        foreach ( $posts as $post ) {
            if ( ! $include_dismissed && get_post_meta( $post->ID, self::META_DISMISSED, true ) ) {
                continue;
            }

            try {
                $results = $this->score_existing_post( $post );
            } catch ( \Throwable $e ) {
                error_log( sprintf(
                    '[StrataWP SEO] score failed for post %d: %s',
                    $post->ID,
                    $e->getMessage()
                ) );
                continue;
            }

            update_post_meta( $post->ID, '_swps_content_score', $results['overall_score'] );
            update_post_meta( $post->ID, '_swps_score_details', $results['details'] );
            update_post_meta( $post->ID, '_swps_score_recommendations', $results['recommendations'] );

            if ( $results['overall_score'] >= $threshold ) {
                continue;
            }

            $rows[] = $this->build_queue_row( $post, $results );
        }

        usort( $rows, fn( $a, $b ) => $a['current_score'] <=> $b['current_score'] );
        return $rows;
    }

    /**
     * Get the current queue without re-scoring (uses cached scores).
     */
    public function get_queue( int $threshold = self::DEFAULT_THRESHOLD, int $limit = 50, bool $include_dismissed = false ): array {
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => max( 1, $limit ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $rows = [];

        foreach ( $posts as $post ) {
            if ( ! $include_dismissed && get_post_meta( $post->ID, self::META_DISMISSED, true ) ) {
                continue;
            }

            $cached = get_post_meta( $post->ID, '_swps_content_score', true );
            if ( '' === $cached ) {
                continue;
            }
            $score = (int) $cached;
            if ( $score >= $threshold ) {
                continue;
            }

            $details = get_post_meta( $post->ID, '_swps_score_details', true ) ?: [];
            $recs    = get_post_meta( $post->ID, '_swps_score_recommendations', true ) ?: [];

            $rows[] = $this->build_queue_row( $post, [
                'overall_score'   => $score,
                'details'         => $details,
                'recommendations' => $recs,
            ] );
        }

        usort( $rows, fn( $a, $b ) => $a['current_score'] <=> $b['current_score'] );
        return $rows;
    }

    /**
     * Aggregate stats for the summary tile row.
     *
     * @param array $rows Queue rows from get_queue() / scan_candidates().
     */
    public function get_stats( array $rows ): array {
        $with_proposal   = 0;
        $proj_lift_total = 0;
        $proj_lift_count = 0;
        $est_cost_total  = 0.0;

        foreach ( $rows as $row ) {
            if ( ! empty( $row['has_proposal'] ) ) {
                $with_proposal++;
                $cur  = (int) ( $row['current_score'] ?? 0 );
                $proj = (int) ( $row['proposal']['projected_score'] ?? 0 );
                if ( $proj > $cur ) {
                    $proj_lift_total += ( $proj - $cur );
                    $proj_lift_count++;
                }
                $est_cost_total += (float) ( $row['proposal']['estimated_cost'] ?? 0 );
            }
        }

        return [
            'count'         => count( $rows ),
            'with_proposal' => $with_proposal,
            'avg_lift'      => $proj_lift_count > 0 ? (int) round( $proj_lift_total / $proj_lift_count ) : 0,
            'est_cost'      => round( $est_cost_total, 4 ),
        ];
    }

    /**
     * Ask the AI for concrete edits + projected score.
     *
     * @return array|WP_Error Stored proposal payload.
     */
    public function propose_edits( int $post_id ): array|WP_Error {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'swps_post_missing', __( 'Post not found.', 'stratawp-seo' ) );
        }

        $current = $this->score_existing_post( $post );
        $recs    = $current['recommendations'] ?? [];

        $focus_keyword   = (string) get_post_meta( $post_id, '_swps_focus_keyword', true );
        $secondary       = get_post_meta( $post_id, '_swps_secondary_keywords', true );
        $secondary_list  = is_array( $secondary )
            ? $secondary
            : array_filter( array_map( 'trim', explode( ',', (string) $secondary ) ) );
        $existing_meta_t = (string) get_post_meta( $post_id, '_swps_meta_title', true );
        $existing_meta_d = (string) get_post_meta( $post_id, '_swps_meta_description', true );

        $body_excerpt = $this->extract_body_excerpt( $post->post_content, 6000 );

        $system = 'You are an SEO editor. You return ONLY valid JSON conforming to the schema. '
                . 'Edits must be safe, minimal, and grounded in the post content. '
                . 'Each find_text MUST appear verbatim in the post content (case-sensitive). '
                . 'Do not invent quotes, URLs, or claims.';

        $schema = '{
  "summary": "one-sentence rationale",
  "projected_score": 0-100 integer,
  "meta_title": "string or null",
  "meta_description": "string or null",
  "focus_keyword": "string or null",
  "edits": [
    {
      "type": "replace_text" | "append_paragraph" | "prepend_paragraph",
      "reason": "short explanation",
      "find_text": "exact substring from post (required for replace_text)",
      "replace_text": "new text"
    }
  ]
}';

        $prompt = sprintf(
            "Optimize this post for SEO. Current score: %d/100.\n\n"
            . "POST TITLE: %s\n"
            . "FOCUS KEYWORD: %s\n"
            . "SECONDARY KEYWORDS: %s\n"
            . "CURRENT META TITLE: %s\n"
            . "CURRENT META DESCRIPTION: %s\n\n"
            . "RECOMMENDATIONS FROM SCORER:\n- %s\n\n"
            . "POST CONTENT (HTML, may be truncated):\n%s\n\n"
            . "Return JSON only matching this schema:\n%s\n\n"
            . "Rules:\n"
            . "- Propose 2-6 concrete edits.\n"
            . "- Prefer small targeted changes (one paragraph, one heading, the intro).\n"
            . "- For replace_text edits: find_text MUST be a verbatim substring of the post content.\n"
            . "- meta_title 50-60 chars; meta_description 140-160 chars.\n"
            . "- projected_score should be a realistic estimate after applying ALL listed edits.",
            $current['overall_score'],
            $post->post_title,
            $focus_keyword !== '' ? $focus_keyword : '(none set)',
            ! empty( $secondary_list ) ? implode( ', ', $secondary_list ) : '(none set)',
            $existing_meta_t !== '' ? $existing_meta_t : '(none set)',
            $existing_meta_d !== '' ? $existing_meta_d : '(none set)',
            ! empty( $recs ) ? implode( "\n- ", $recs ) : '(none)',
            $body_excerpt,
            $schema
        );

        $api      = SWPS_Provider_Factory::create_ai_provider();
        $response = $api->chat_json( $system, $prompt, 4096 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $proposal = $this->normalize_proposal( $response, $post );

        $stored = [
            'current_score'   => $current['overall_score'],
            'projected_score' => $proposal['projected_score'],
            'summary'         => $proposal['summary'],
            'meta_title'      => $proposal['meta_title'],
            'meta_description'=> $proposal['meta_description'],
            'focus_keyword'   => $proposal['focus_keyword'],
            'edits'           => $proposal['edits'],
            'estimated_cost'  => $this->estimate_cost( $response['_usage'] ?? null ),
            'usage'           => $response['_usage'] ?? null,
        ];

        update_post_meta( $post_id, self::META_PROPOSAL, $stored );
        update_post_meta( $post_id, self::META_PROPOSED_AT, current_time( 'mysql' ) );
        delete_post_meta( $post_id, self::META_DISMISSED );

        return $stored;
    }

    /**
     * Apply approved edits to the post.
     *
     * @param array $accepted {
     *     @type bool   $meta_title
     *     @type bool   $meta_description
     *     @type bool   $focus_keyword
     *     @type int[]  $edits  Indices into the proposal->edits array.
     * }
     */
    public function apply_edits( int $post_id, array $accepted ): array|WP_Error {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'swps_post_missing', __( 'Post not found.', 'stratawp-seo' ) );
        }

        $proposal = get_post_meta( $post_id, self::META_PROPOSAL, true );
        if ( ! is_array( $proposal ) || empty( $proposal ) ) {
            return new WP_Error( 'swps_no_proposal', __( 'No proposal found for this post. Generate one first.', 'stratawp-seo' ) );
        }

        $changed     = [];
        $skipped     = [];
        $new_content = $post->post_content;

        if ( ! empty( $accepted['meta_title'] ) && ! empty( $proposal['meta_title'] ) ) {
            update_post_meta( $post_id, '_swps_meta_title', sanitize_text_field( $proposal['meta_title'] ) );
            $changed[] = 'meta_title';
        }
        if ( ! empty( $accepted['meta_description'] ) && ! empty( $proposal['meta_description'] ) ) {
            update_post_meta( $post_id, '_swps_meta_description', sanitize_textarea_field( $proposal['meta_description'] ) );
            $changed[] = 'meta_description';
        }
        if ( ! empty( $accepted['focus_keyword'] ) && ! empty( $proposal['focus_keyword'] ) ) {
            update_post_meta( $post_id, '_swps_focus_keyword', sanitize_text_field( $proposal['focus_keyword'] ) );
            $changed[] = 'focus_keyword';
        }

        $accepted_idx = isset( $accepted['edits'] ) && is_array( $accepted['edits'] )
            ? array_map( 'intval', $accepted['edits'] )
            : [];

        foreach ( ( $proposal['edits'] ?? [] ) as $i => $edit ) {
            if ( ! in_array( $i, $accepted_idx, true ) ) {
                continue;
            }
            $applied = $this->apply_single_edit( $new_content, $edit );
            if ( $applied['ok'] ) {
                $new_content = $applied['content'];
                $changed[]   = 'edit#' . $i;
            } else {
                $skipped[] = [ 'index' => $i, 'reason' => $applied['reason'] ];
            }
        }

        if ( $new_content !== $post->post_content ) {
            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $new_content,
            ] );
        }

        $post_after = get_post( $post_id );
        $rescored   = $this->score_existing_post( $post_after );
        update_post_meta( $post_id, '_swps_content_score', $rescored['overall_score'] );
        update_post_meta( $post_id, '_swps_score_details', $rescored['details'] );
        update_post_meta( $post_id, '_swps_score_recommendations', $rescored['recommendations'] );

        update_post_meta( $post_id, self::META_APPLIED_AT, current_time( 'mysql' ) );
        delete_post_meta( $post_id, self::META_PROPOSAL );

        return [
            'changed'        => $changed,
            'skipped'        => $skipped,
            'new_score'      => $rescored['overall_score'],
            'previous_score' => (int) ( $proposal['current_score'] ?? 0 ),
        ];
    }

    public function dismiss( int $post_id ): void {
        update_post_meta( $post_id, self::META_DISMISSED, 1 );
        delete_post_meta( $post_id, self::META_PROPOSAL );
    }

    public function undismiss( int $post_id ): void {
        delete_post_meta( $post_id, self::META_DISMISSED );
    }

    public function get_threshold(): int {
        $val = (int) get_option( self::OPTION_THRESHOLD, self::DEFAULT_THRESHOLD );
        return max( 0, min( 100, $val ) );
    }

    public function set_threshold( int $value ): void {
        update_option( self::OPTION_THRESHOLD, max( 0, min( 100, $value ) ) );
    }

    // ---------- AJAX ----------

    public function ajax_scan(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }

        $threshold = isset( $_POST['threshold'] ) ? (int) $_POST['threshold'] : $this->get_threshold();
        $limit     = isset( $_POST['limit'] ) ? max( 1, min( 200, (int) $_POST['limit'] ) ) : 50;
        $rescore   = ! empty( $_POST['rescore'] );

        $this->set_threshold( $threshold );

        // Re-scoring 50 posts × 3 meta writes can easily exceed shared-host PHP
        // timeouts. Give it room and don't fall over if a single post errors.
        if ( $rescore ) {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 180 );
            }
            if ( function_exists( 'wp_raise_memory_limit' ) ) {
                wp_raise_memory_limit( 'admin' );
            }
            ignore_user_abort( true );
        }

        try {
            $rows = $rescore
                ? $this->scan_candidates( $threshold, $limit )
                : $this->get_queue( $threshold, $limit );
        } catch ( \Throwable $e ) {
            error_log( '[StrataWP SEO] auto-optimize scan failed: ' . $e->getMessage() );
            wp_send_json_error( [
                'message' => sprintf(
                    /* translators: %s: error message */
                    __( 'Scan failed: %s', 'stratawp-seo' ),
                    $e->getMessage()
                ),
            ] );
        }

        wp_send_json_success( [
            'threshold' => $threshold,
            'count'     => count( $rows ),
            'rows'      => $rows,
            'stats'     => $this->get_stats( $rows ),
        ] );
    }

    /**
     * Score a single chunk of posts. Used by JS to walk the published-posts
     * list one batch at a time so a large site doesn't hit a 30/60s PHP
     * timeout. Each chunk is its own AJAX request.
     */
    public function ajax_scan_chunk(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }

        $offset     = isset( $_POST['offset'] )     ? max( 0, (int) $_POST['offset'] )                           : 0;
        $chunk_size = isset( $_POST['chunk_size'] ) ? max( 1, min( 25, (int) $_POST['chunk_size'] ) )            : 10;

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 90 );
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }
        ignore_user_abort( true );

        try {
            $result = $this->score_chunk( $offset, $chunk_size );
        } catch ( \Throwable $e ) {
            error_log( '[StrataWP SEO] auto-optimize chunk failed at offset ' . $offset . ': ' . $e->getMessage() );
            wp_send_json_error( [
                'message' => sprintf(
                    /* translators: %s: error message */
                    __( 'Chunk scan failed: %s', 'stratawp-seo' ),
                    $e->getMessage()
                ),
            ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Score one chunk of published posts. Stores the new score on each post's
     * meta and returns counts for the JS progress UI.
     *
     * @return array { scanned, fetched, next_offset, has_more, total }
     */
    public function score_chunk( int $offset, int $chunk_size ): array {
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => $chunk_size,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $scanned = 0;
        foreach ( $posts as $post ) {
            try {
                $results = $this->score_existing_post( $post );
            } catch ( \Throwable $e ) {
                error_log( sprintf(
                    '[StrataWP SEO] score failed for post %d: %s',
                    $post->ID,
                    $e->getMessage()
                ) );
                continue;
            }

            update_post_meta( $post->ID, '_swps_content_score', $results['overall_score'] );
            update_post_meta( $post->ID, '_swps_score_details', $results['details'] );
            update_post_meta( $post->ID, '_swps_score_recommendations', $results['recommendations'] );
            $scanned++;
        }

        $fetched = count( $posts );

        return [
            'scanned'     => $scanned,
            'fetched'     => $fetched,
            'next_offset' => $offset + $fetched,
            'has_more'    => $fetched === $chunk_size,
            'total'       => $this->count_published(),
        ];
    }

    private function count_published(): int {
        $post = wp_count_posts( 'post' );
        $page = wp_count_posts( 'page' );
        return (int) ( ( $post->publish ?? 0 ) + ( $page->publish ?? 0 ) );
    }

    public function ajax_propose(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'stratawp-seo' ) ] );
        }

        $result = $this->propose_edits( $post_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    public function ajax_apply(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }

        $post_id  = absint( $_POST['post_id'] ?? 0 );
        $accepted = isset( $_POST['accepted'] ) ? (array) $_POST['accepted'] : [];

        $accepted_clean = [
            'meta_title'       => ! empty( $accepted['meta_title'] ),
            'meta_description' => ! empty( $accepted['meta_description'] ),
            'focus_keyword'    => ! empty( $accepted['focus_keyword'] ),
            'edits'            => isset( $accepted['edits'] ) ? array_map( 'intval', (array) $accepted['edits'] ) : [],
        ];

        $result = $this->apply_edits( $post_id, $accepted_clean );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    public function ajax_dismiss(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'stratawp-seo' ) ] );
        }
        $this->dismiss( $post_id );
        wp_send_json_success();
    }

    public function ajax_undismiss(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ] );
        }
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'stratawp-seo' ) ] );
        }
        $this->undismiss( $post_id );
        wp_send_json_success();
    }

    // ---------- Internals ----------

    private function score_existing_post( WP_Post $post ): array {
        $ai_result = [
            'title'                => $post->post_title,
            'content_html'         => $post->post_content,
            'meta_description'     => get_post_meta( $post->ID, '_swps_meta_description', true )
                                   ?: get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true )
                                   ?: get_post_meta( $post->ID, 'rank_math_description', true )
                                   ?: '',
            'focus_keyword'        => get_post_meta( $post->ID, '_swps_focus_keyword', true )
                                   ?: get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true )
                                   ?: get_post_meta( $post->ID, 'rank_math_focus_keyword', true )
                                   ?: '',
            'secondary_keywords'   => get_post_meta( $post->ID, '_swps_secondary_keywords', true ) ?: [],
            'internal_links_used'  => get_post_meta( $post->ID, '_swps_internal_links', true ) ?: [],
            'estimated_word_count' => str_word_count( wp_strip_all_tags( $post->post_content ) ),
        ];
        return $this->scorer->score( $post->ID, $ai_result );
    }

    private function build_queue_row( WP_Post $post, array $score ): array {
        $proposal     = get_post_meta( $post->ID, self::META_PROPOSAL, true );
        $has_proposal = is_array( $proposal ) && ! empty( $proposal );

        return [
            'post_id'       => $post->ID,
            'title'         => $post->post_title,
            'edit_url'      => get_edit_post_link( $post->ID, 'raw' ),
            'permalink'     => get_permalink( $post->ID ),
            'current_score' => (int) ( $score['overall_score'] ?? 0 ),
            'top_recs'      => array_slice( (array) ( $score['recommendations'] ?? [] ), 0, 3 ),
            'has_proposal'  => $has_proposal,
            'proposal'      => $has_proposal ? $proposal : null,
            'dismissed'     => (bool) get_post_meta( $post->ID, self::META_DISMISSED, true ),
            'word_count'    => str_word_count( wp_strip_all_tags( $post->post_content ) ),
        ];
    }

    private function extract_body_excerpt( string $content, int $max_chars ): string {
        if ( strlen( $content ) <= $max_chars ) {
            return $content;
        }
        $head_len = (int) floor( $max_chars * 0.7 );
        $tail_len = $max_chars - $head_len - 32;
        return mb_substr( $content, 0, $head_len )
             . "\n\n[... content truncated ...]\n\n"
             . mb_substr( $content, -$tail_len );
    }

    private function normalize_proposal( array $response, WP_Post $post ): array {
        $edits     = [];
        $raw_edits = $response['edits'] ?? [];
        if ( is_array( $raw_edits ) ) {
            foreach ( $raw_edits as $edit ) {
                if ( ! is_array( $edit ) ) {
                    continue;
                }
                $type = (string) ( $edit['type'] ?? '' );
                if ( ! in_array( $type, [ 'replace_text', 'append_paragraph', 'prepend_paragraph' ], true ) ) {
                    continue;
                }
                $find_text = isset( $edit['find_text'] ) ? (string) $edit['find_text'] : '';
                $replace   = isset( $edit['replace_text'] ) ? (string) $edit['replace_text'] : '';
                $reason    = isset( $edit['reason'] ) ? (string) $edit['reason'] : '';

                // Block hallucinated finds.
                if ( 'replace_text' === $type && '' !== $find_text ) {
                    if ( strpos( $post->post_content, $find_text ) === false ) {
                        continue;
                    }
                }

                $edits[] = [
                    'type'         => $type,
                    'reason'       => $reason,
                    'find_text'    => $find_text,
                    'replace_text' => $replace,
                ];
            }
        }

        return [
            'summary'          => (string) ( $response['summary'] ?? '' ),
            'projected_score'  => max( 0, min( 100, (int) ( $response['projected_score'] ?? 0 ) ) ),
            'meta_title'       => isset( $response['meta_title'] ) && '' !== trim( (string) $response['meta_title'] )
                                    ? sanitize_text_field( $response['meta_title'] ) : null,
            'meta_description' => isset( $response['meta_description'] ) && '' !== trim( (string) $response['meta_description'] )
                                    ? sanitize_textarea_field( $response['meta_description'] ) : null,
            'focus_keyword'    => isset( $response['focus_keyword'] ) && '' !== trim( (string) $response['focus_keyword'] )
                                    ? sanitize_text_field( $response['focus_keyword'] ) : null,
            'edits'            => $edits,
        ];
    }

    /**
     * @return array{ok: bool, content: string, reason: string}
     */
    private function apply_single_edit( string $content, array $edit ): array {
        $type = $edit['type'] ?? '';
        $repl = (string) ( $edit['replace_text'] ?? '' );

        switch ( $type ) {
            case 'replace_text':
                $find = (string) ( $edit['find_text'] ?? '' );
                if ( '' === $find || strpos( $content, $find ) === false ) {
                    return [ 'ok' => false, 'content' => $content, 'reason' => 'find_text not found' ];
                }
                $pos = strpos( $content, $find );
                $new = substr( $content, 0, $pos ) . $repl . substr( $content, $pos + strlen( $find ) );
                return [ 'ok' => true, 'content' => $new, 'reason' => '' ];

            case 'append_paragraph':
                if ( '' === trim( $repl ) ) {
                    return [ 'ok' => false, 'content' => $content, 'reason' => 'empty paragraph' ];
                }
                return [ 'ok' => true, 'content' => $content . "\n\n" . wp_kses_post( wpautop( $repl ) ), 'reason' => '' ];

            case 'prepend_paragraph':
                if ( '' === trim( $repl ) ) {
                    return [ 'ok' => false, 'content' => $content, 'reason' => 'empty paragraph' ];
                }
                return [ 'ok' => true, 'content' => wp_kses_post( wpautop( $repl ) ) . "\n\n" . $content, 'reason' => '' ];
        }

        return [ 'ok' => false, 'content' => $content, 'reason' => 'unknown edit type' ];
    }

    private function estimate_cost( ?array $usage ): float {
        if ( ! is_array( $usage ) ) {
            return 0.0;
        }
        $in  = (int) ( $usage['input_tokens'] ?? 0 );
        $out = (int) ( $usage['output_tokens'] ?? 0 );
        return round( ( $in * 0.000003 ) + ( $out * 0.000015 ), 4 );
    }
}
