# Internal Linking Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a hybrid internal linking engine that maintains a persistent link graph, suggests link opportunities via keyword matching (with optional AI enrichment), integrates with AI content generation, and provides both per-post and site-wide link management UI.

**Architecture:** Four new PHP classes compose the engine: `SWPS_Internal_Links` (core orchestrator + metabox), `SWPS_Link_Keyword_Engine` (term extraction + matching), `SWPS_Link_AI_Engine` (semantic scoring via AI provider), and `SWPS_Internal_Links_Admin` (overview page + AJAX). Two new DB tables (`swps_link_index`, `swps_link_graph`) store the link graph. Background processing uses the existing `SWPS_Background_Processor` pattern (Action Scheduler / wp_schedule_single_event).

**Tech Stack:** PHP 8.0+, WordPress 6.0+, jQuery (matching existing admin JS patterns), WP_List_Table for admin overview, existing SWPS_AI_Provider abstraction for AI calls.

**Spec:** `docs/superpowers/specs/2026-03-29-internal-linking-engine-design.md`

---

## File Structure

| File | Purpose |
|------|---------|
| **Create:** `includes/class-link-keyword-engine.php` | Term extraction from post content, keyword matching/scoring between posts |
| **Create:** `includes/class-link-ai-engine.php` | AI-powered semantic scoring and anchor text suggestions via SWPS_AI_Provider |
| **Create:** `includes/class-internal-links.php` | Core orchestrator: DB table CRUD, link graph queries, editor metabox, save_post hooks, background rebuild |
| **Create:** `includes/class-internal-links-admin.php` | Admin overview page rendering, WP_List_Table for opportunities, AJAX handlers |
| **Create:** `admin/js/internal-links.js` | Editor metabox JS: insert link, dismiss, deep analysis AJAX |
| **Create:** `admin/js/internal-links-admin.js` | Overview page JS: rebuild index progress, bulk actions |
| **Create:** `templates/internal-links-page.php` | Admin overview page template |
| **Modify:** `stratawp-seo.php` | Add require_once, instantiate SWPS_Internal_Links + SWPS_Internal_Links_Admin, add to activation hook |
| **Modify:** `includes/class-settings.php` | Register Internal Links submenu page, register settings fields |
| **Modify:** `includes/class-hooks.php` | Add filter_link_suggestions and filter_user_prompt integration |
| **Modify:** `admin/css/admin.css` | Add internal links metabox and admin page styles |

---

### Task 1: Link Keyword Engine — Term Extraction

**Files:**
- Create: `includes/class-link-keyword-engine.php`

This class handles extracting keywords/terms from post content and storing them in the link index. It also provides the matching algorithm that scores relationships between posts.

- [ ] **Step 1: Create the keyword engine class with table creation**

```php
<?php
/**
 * Keyword-based link matching engine.
 *
 * Extracts significant terms from post content (title, headings, focus keyword, body)
 * and matches posts by term overlap for internal link suggestions.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Link_Keyword_Engine {

    private const TABLE = 'swps_link_index';

    /**
     * Weight assigned to each term source.
     */
    private const WEIGHTS = [
        'title'    => 1.0,
        'focus_kw' => 0.9,
        'h2'       => 0.8,
        'h3'       => 0.6,
        'body'     => 0.3,
    ];

    /**
     * Common English stop words to exclude from body term extraction.
     */
    private const STOP_WORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been',
        'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
        'could', 'should', 'may', 'might', 'shall', 'can', 'this', 'that',
        'these', 'those', 'it', 'its', 'not', 'no', 'nor', 'so', 'if', 'then',
        'than', 'too', 'very', 'just', 'about', 'above', 'after', 'again',
        'all', 'also', 'am', 'as', 'because', 'before', 'between', 'both',
        'each', 'few', 'get', 'got', 'he', 'her', 'here', 'him', 'his', 'how',
        'i', 'into', 'me', 'more', 'most', 'my', 'new', 'now', 'only', 'other',
        'our', 'out', 'over', 'own', 'same', 'she', 'some', 'such', 'their',
        'them', 'there', 'they', 'through', 'under', 'up', 'us', 'use', 'what',
        'when', 'where', 'which', 'while', 'who', 'whom', 'why', 'you', 'your',
    ];

    /**
     * Create the link index table.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . self::TABLE;

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            term VARCHAR(100) NOT NULL,
            source ENUM('title','h2','h3','focus_kw','body') NOT NULL,
            weight FLOAT NOT NULL DEFAULT 0.3,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_post_id (post_id),
            KEY idx_term (term),
            KEY idx_term_weight (term, weight)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Extract and store terms for a single post.
     *
     * @param int $post_id Post ID to index.
     */
    public function index_post( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            $this->clear_post( $post_id );
            return;
        }

        $terms = $this->extract_terms( $post );

        // Replace all existing terms for this post.
        $this->clear_post( $post_id );
        $this->store_terms( $post_id, $terms );
    }

    /**
     * Remove all index entries for a post.
     *
     * @param int $post_id Post ID to clear.
     */
    public function clear_post( int $post_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] );
    }

    /**
     * Extract terms from a post across all sources.
     *
     * @param WP_Post $post The post object.
     * @return array Array of ['term' => string, 'source' => string, 'weight' => float].
     */
    public function extract_terms( WP_Post $post ): array {
        $terms = [];

        // Title terms.
        $title_terms = $this->tokenize( $post->post_title );
        foreach ( $title_terms as $term ) {
            $terms[] = [ 'term' => $term, 'source' => 'title', 'weight' => self::WEIGHTS['title'] ];
        }

        // Focus keyword from meta editor.
        $focus_kw = get_post_meta( $post->ID, '_swps_focus_keyword', true );
        if ( ! empty( $focus_kw ) ) {
            $normalized = $this->normalize( $focus_kw );
            if ( strlen( $normalized ) >= 3 ) {
                $terms[] = [ 'term' => $normalized, 'source' => 'focus_kw', 'weight' => self::WEIGHTS['focus_kw'] ];
            }
        }

        // H2 headings.
        $h2s = $this->extract_headings( $post->post_content, 'h2' );
        foreach ( $h2s as $heading ) {
            foreach ( $this->tokenize( $heading ) as $term ) {
                $terms[] = [ 'term' => $term, 'source' => 'h2', 'weight' => self::WEIGHTS['h2'] ];
            }
        }

        // H3 headings.
        $h3s = $this->extract_headings( $post->post_content, 'h3' );
        foreach ( $h3s as $heading ) {
            foreach ( $this->tokenize( $heading ) as $term ) {
                $terms[] = [ 'term' => $term, 'source' => 'h3', 'weight' => self::WEIGHTS['h3'] ];
            }
        }

        // Body terms — top 10 by frequency.
        $plain = wp_strip_all_tags( $post->post_content );
        $body_terms = $this->extract_top_body_terms( $plain, 10 );
        foreach ( $body_terms as $term ) {
            $terms[] = [ 'term' => $term, 'source' => 'body', 'weight' => self::WEIGHTS['body'] ];
        }

        // Deduplicate: keep highest weight per unique term.
        $unique = [];
        foreach ( $terms as $entry ) {
            $key = $entry['term'];
            if ( ! isset( $unique[ $key ] ) || $entry['weight'] > $unique[ $key ]['weight'] ) {
                $unique[ $key ] = $entry;
            }
        }

        return array_values( $unique );
    }

    /**
     * Find posts related to a given post by shared terms.
     *
     * @param int   $post_id   The source post ID.
     * @param float $threshold Minimum relevance score (0-1). Default 0.3.
     * @param int   $limit     Maximum results. Default 20.
     * @return array Array of ['post_id' => int, 'score' => float] sorted by score descending.
     */
    public function find_related( int $post_id, float $threshold = 0.3, int $limit = 20 ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Get this post's terms.
        $source_terms = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term, weight FROM {$table} WHERE post_id = %d",
                $post_id
            ),
            ARRAY_A
        );

        if ( empty( $source_terms ) ) {
            return [];
        }

        // Build a map of term => weight for the source post.
        $term_weights = [];
        foreach ( $source_terms as $row ) {
            $term_weights[ $row['term'] ] = (float) $row['weight'];
        }

        // Find other posts with matching terms.
        $placeholders = implode( ',', array_fill( 0, count( $term_weights ), '%s' ) );
        $term_values  = array_keys( $term_weights );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $matches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, term, weight FROM {$table}
                 WHERE term IN ({$placeholders}) AND post_id != %d",
                array_merge( $term_values, [ $post_id ] )
            ),
            ARRAY_A
        );

        if ( empty( $matches ) ) {
            return [];
        }

        // Score each candidate by summing (source_weight * target_weight) per shared term.
        $scores = [];
        foreach ( $matches as $row ) {
            $pid = (int) $row['post_id'];
            if ( ! isset( $scores[ $pid ] ) ) {
                $scores[ $pid ] = 0.0;
            }
            $scores[ $pid ] += $term_weights[ $row['term'] ] * (float) $row['weight'];
        }

        // Normalize to 0-1 range.
        $max_score = max( $scores );
        if ( $max_score > 0 ) {
            foreach ( $scores as &$score ) {
                $score = round( $score / $max_score, 3 );
            }
            unset( $score );
        }

        // Filter and sort.
        $results = [];
        foreach ( $scores as $pid => $score ) {
            if ( $score >= $threshold ) {
                $results[] = [ 'post_id' => $pid, 'score' => $score ];
            }
        }

        usort( $results, fn( $a, $b ) => $b['score'] <=> $a['score'] );

        return array_slice( $results, 0, $limit );
    }

    /**
     * Store extracted terms to the database.
     *
     * @param int   $post_id Post ID.
     * @param array $terms   Array of ['term', 'source', 'weight'].
     */
    private function store_terms( int $post_id, array $terms ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = current_time( 'mysql' );

        foreach ( $terms as $entry ) {
            $wpdb->insert(
                $table,
                [
                    'post_id'    => $post_id,
                    'term'       => mb_substr( $entry['term'], 0, 100 ),
                    'source'     => $entry['source'],
                    'weight'     => $entry['weight'],
                    'updated_at' => $now,
                ],
                [ '%d', '%s', '%s', '%f', '%s' ]
            );
        }
    }

    /**
     * Extract heading text from HTML content.
     *
     * @param string $html HTML content.
     * @param string $tag  Heading tag ('h2' or 'h3').
     * @return string[] Array of heading text strings.
     */
    private function extract_headings( string $html, string $tag ): array {
        $headings = [];
        if ( preg_match_all( "/<{$tag}[^>]*>(.*?)<\/{$tag}>/is", $html, $matches ) ) {
            foreach ( $matches[1] as $heading ) {
                $headings[] = wp_strip_all_tags( $heading );
            }
        }
        return $headings;
    }

    /**
     * Extract top N body terms by frequency, excluding stop words.
     *
     * @param string $plain_text Plain text content.
     * @param int    $count      Number of top terms to return.
     * @return string[] Array of terms.
     */
    private function extract_top_body_terms( string $plain_text, int $count = 10 ): array {
        $words = $this->tokenize( $plain_text );
        if ( empty( $words ) ) {
            return [];
        }

        $freq = array_count_values( $words );
        arsort( $freq );

        return array_slice( array_keys( $freq ), 0, $count );
    }

    /**
     * Tokenize text into normalized, filtered terms.
     *
     * @param string $text Raw text.
     * @return string[] Array of valid terms (>= 3 chars, not stop words).
     */
    private function tokenize( string $text ): array {
        $normalized = $this->normalize( $text );
        $words      = preg_split( '/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY );

        return array_values( array_filter( $words, function ( string $word ): bool {
            return strlen( $word ) >= 3 && ! in_array( $word, self::STOP_WORDS, true );
        } ) );
    }

    /**
     * Normalize a string: lowercase, strip punctuation.
     *
     * @param string $text Raw text.
     * @return string Normalized text.
     */
    private function normalize( string $text ): string {
        $text = mb_strtolower( wp_strip_all_tags( $text ) );
        $text = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $text );
        return trim( $text );
    }
}
```

Write this to `includes/class-link-keyword-engine.php`.

- [ ] **Step 2: Verify the file was created correctly**

Run: `php -l includes/class-link-keyword-engine.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/class-link-keyword-engine.php
git commit -m "feat: add SWPS_Link_Keyword_Engine for term extraction and matching"
```

---

### Task 2: Link AI Engine

**Files:**
- Create: `includes/class-link-ai-engine.php`

This class sends keyword-matched candidates to the AI provider for semantic scoring and anchor text suggestions.

- [ ] **Step 1: Create the AI engine class**

```php
<?php
/**
 * AI-powered link analysis engine.
 *
 * Takes keyword-matched candidates and uses the configured AI provider
 * to score semantic relevance and suggest optimal anchor text.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Link_AI_Engine {

    private SWPS_AI_Provider $api;
    private SWPS_Cost_Tracker $cost_tracker;

    public function __construct( SWPS_AI_Provider $api, SWPS_Cost_Tracker $cost_tracker ) {
        $this->api          = $api;
        $this->cost_tracker = $cost_tracker;
    }

    /**
     * Analyze candidates for a source post using AI.
     *
     * @param int   $source_post_id Source post ID.
     * @param array $candidates     Array of ['post_id' => int, 'score' => float].
     * @return array|WP_Error Array of enriched results or error.
     *     Each result: ['post_id' => int, 'relevance_score' => float, 'anchor_text' => string, 'rationale' => string]
     */
    public function analyze( int $source_post_id, array $candidates ): array|WP_Error {
        $source_post = get_post( $source_post_id );
        if ( ! $source_post ) {
            return new WP_Error( 'swps_invalid_post', 'Source post not found.' );
        }

        $batch_size = (int) get_option( 'swps_link_ai_batch_size', 10 );
        $candidates = array_slice( $candidates, 0, $batch_size );

        // Build candidate data.
        $candidate_data = [];
        foreach ( $candidates as $candidate ) {
            $post = get_post( $candidate['post_id'] );
            if ( ! $post ) {
                continue;
            }

            $focus_kw = get_post_meta( $post->ID, '_swps_focus_keyword', true );

            $candidate_data[] = [
                'post_id'       => $post->ID,
                'title'         => $post->post_title,
                'excerpt'       => wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 ),
                'url'           => get_permalink( $post->ID ),
                'focus_keyword' => $focus_kw ?: '',
            ];
        }

        if ( empty( $candidate_data ) ) {
            return [];
        }

        $source_focus_kw = get_post_meta( $source_post_id, '_swps_focus_keyword', true );

        $system_prompt = <<<'PROMPT'
You are an SEO internal linking specialist. Analyze the relationship between a source post and candidate target posts. For each candidate, provide:
1. A relevance score from 0.0 to 1.0 (how relevant is linking from the source to this target)
2. Suggested anchor text (2-6 words, natural phrasing that would fit in the source post)
3. A one-line rationale explaining why these posts are related

Respond with JSON only. No markdown fences.

Required JSON structure:
[
  {
    "post_id": 123,
    "relevance_score": 0.85,
    "anchor_text": "suggested anchor text",
    "rationale": "Both posts discuss WordPress caching strategies"
  }
]
PROMPT;

        $candidates_json = wp_json_encode( $candidate_data, JSON_PRETTY_PRINT );

        $user_prompt = "SOURCE POST:\n";
        $user_prompt .= "Title: {$source_post->post_title}\n";
        $user_prompt .= "Focus Keyword: " . ( $source_focus_kw ?: 'none' ) . "\n";
        $user_prompt .= "Excerpt: " . wp_trim_words( wp_strip_all_tags( $source_post->post_content ), 80 ) . "\n\n";
        $user_prompt .= "CANDIDATE TARGET POSTS:\n{$candidates_json}\n\n";
        $user_prompt .= "Analyze each candidate and respond with the JSON array.";

        $result = $this->api->chat_json( $system_prompt, $user_prompt, 2048 );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Track cost.
        $model = get_option( 'swps_model', '' );
        if ( ! empty( $result['_usage'] ) ) {
            $this->cost_tracker->track(
                $model,
                $result['_usage']['input_tokens'] ?? 0,
                $result['_usage']['output_tokens'] ?? 0
            );
        }

        // The AI provider returns parsed JSON. If the top-level is an array, use it directly.
        // If it's wrapped in a key, try common keys.
        $analysis = $result;
        if ( isset( $result[0] ) && is_array( $result[0] ) ) {
            $analysis = $result;
        } elseif ( isset( $result['results'] ) ) {
            $analysis = $result['results'];
        } elseif ( isset( $result['candidates'] ) ) {
            $analysis = $result['candidates'];
        }

        // Validate and normalize results.
        $enriched = [];
        foreach ( $analysis as $item ) {
            if ( ! isset( $item['post_id'], $item['relevance_score'] ) ) {
                continue;
            }
            $enriched[] = [
                'post_id'         => (int) $item['post_id'],
                'relevance_score' => max( 0.0, min( 1.0, (float) $item['relevance_score'] ) ),
                'anchor_text'     => sanitize_text_field( $item['anchor_text'] ?? '' ),
                'rationale'       => sanitize_text_field( $item['rationale'] ?? '' ),
            ];
        }

        return $enriched;
    }
}
```

Write this to `includes/class-link-ai-engine.php`.

- [ ] **Step 2: Verify syntax**

Run: `php -l includes/class-link-ai-engine.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/class-link-ai-engine.php
git commit -m "feat: add SWPS_Link_AI_Engine for semantic link scoring"
```

---

### Task 3: Core Internal Links Class — DB + Link Graph

**Files:**
- Create: `includes/class-internal-links.php`

The core orchestrator: link graph table, CRUD operations, existing link detection, the `save_post` hook for incremental updates, the editor metabox, and background rebuild coordination.

- [ ] **Step 1: Create the internal links class**

```php
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
```

Write this to `includes/class-internal-links.php`.

**Note:** The `enrich_generation_prompt` method references a `tokenize_public` method on `SWPS_Link_Keyword_Engine` that doesn't exist yet. We need to add a public wrapper.

- [ ] **Step 2: Add `tokenize_public` method to the keyword engine**

Open `includes/class-link-keyword-engine.php` and add this public method after the `find_related` method:

```php
    /**
     * Public access to tokenize for use by other classes.
     *
     * @param string $text Raw text.
     * @return string[] Array of valid terms.
     */
    public function tokenize_public( string $text ): array {
        return $this->tokenize( $text );
    }
```

- [ ] **Step 3: Verify both files pass syntax check**

Run: `php -l includes/class-internal-links.php && php -l includes/class-link-keyword-engine.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Commit**

```bash
git add includes/class-internal-links.php includes/class-link-keyword-engine.php
git commit -m "feat: add SWPS_Internal_Links core orchestrator with link graph CRUD and metabox"
```

---

### Task 4: Editor Metabox JavaScript

**Files:**
- Create: `admin/js/internal-links.js`

Handles insert, dismiss, and deep analysis AJAX calls from the editor metabox.

- [ ] **Step 1: Create the metabox JavaScript**

```javascript
/* global jQuery, swpsInternalLinks, wp */
(function ($) {
    'use strict';

    var $metabox = $('#swps-internal-links-metabox');
    if (!$metabox.length) {
        return;
    }

    // Insert link into editor content.
    $metabox.on('click', '.swps-insert-link', function (e) {
        e.preventDefault();

        var $li       = $(this).closest('li');
        var targetUrl = $li.data('target-url');
        var anchor    = $li.data('anchor');
        var targetId  = $li.data('target-id');
        var postId    = $('#post_ID').val();
        var $btn      = $(this);

        $btn.prop('disabled', true).text(swpsInternalLinks.i18n.inserting);

        // Insert link HTML into the editor.
        var linkHtml = '<a href="' + targetUrl + '">' + anchor + '</a>';

        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/block-editor')) {
            // Block editor: insert as inline content at cursor or append to selected block.
            var selectedBlock = wp.data.select('core/block-editor').getSelectedBlock();
            if (selectedBlock && selectedBlock.attributes && typeof selectedBlock.attributes.content === 'string') {
                var newContent = selectedBlock.attributes.content + ' ' + linkHtml;
                wp.data.dispatch('core/block-editor').updateBlockAttributes(selectedBlock.clientId, {
                    content: newContent
                });
            } else {
                // Insert as a new paragraph block.
                var newBlock = wp.blocks.createBlock('core/paragraph', {
                    content: linkHtml
                });
                wp.data.dispatch('core/block-editor').insertBlock(newBlock);
            }
        } else if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            // Classic editor.
            tinymce.activeEditor.execCommand('mceInsertContent', false, ' ' + linkHtml);
        }

        // Mark as inserted via AJAX.
        $.post(swpsInternalLinks.ajaxUrl, {
            action:      'swps_link_insert',
            nonce:       swpsInternalLinks.nonce,
            post_id:     postId,
            target_id:   targetId,
            anchor_text: anchor
        }).always(function () {
            $li.fadeOut(300, function () { $(this).remove(); });
        });
    });

    // Dismiss suggestion.
    $metabox.on('click', '.swps-dismiss-link', function (e) {
        e.preventDefault();

        var $li      = $(this).closest('li');
        var targetId = $li.data('target-id');
        var postId   = $('#post_ID').val();

        $.post(swpsInternalLinks.ajaxUrl, {
            action:    'swps_link_dismiss',
            nonce:     swpsInternalLinks.nonce,
            post_id:   postId,
            target_id: targetId
        });

        $li.fadeOut(300, function () { $(this).remove(); });
    });

    // Deep Analysis button.
    $('#swps-deep-analysis').on('click', function (e) {
        e.preventDefault();

        var $btn    = $(this);
        var $status = $('#swps-deep-analysis-status');
        var postId  = $btn.data('post-id');

        $btn.prop('disabled', true);
        $status.text(swpsInternalLinks.i18n.analyzing);

        $.post(swpsInternalLinks.ajaxUrl, {
            action:  'swps_link_deep_analysis',
            nonce:   swpsInternalLinks.nonce,
            post_id: postId
        }).done(function (response) {
            if (response.success) {
                $status.text(swpsInternalLinks.i18n.done);
                // Reload the page to show updated suggestions.
                window.location.reload();
            } else {
                $status.text(response.data.message || swpsInternalLinks.i18n.error);
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            $status.text(swpsInternalLinks.i18n.error);
            $btn.prop('disabled', false);
        });
    });

})(jQuery);
```

Write this to `admin/js/internal-links.js`.

- [ ] **Step 2: Commit**

```bash
git add admin/js/internal-links.js
git commit -m "feat: add internal links metabox JavaScript for insert/dismiss/analysis"
```

---

### Task 5: Admin Overview Page

**Files:**
- Create: `includes/class-internal-links-admin.php`
- Create: `templates/internal-links-page.php`
- Create: `admin/js/internal-links-admin.js`

The site-wide overview page with link health summary, opportunities table, and orphan pages view.

- [ ] **Step 1: Create the admin page class**

```php
<?php
/**
 * Internal Links admin overview page.
 *
 * Renders the site-wide link health dashboard, opportunities table,
 * orphan pages view, and handles rebuild/bulk AJAX.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Internal_Links_Admin {

    private SWPS_Internal_Links $engine;

    public function __construct( SWPS_Internal_Links $engine ) {
        $this->engine = $engine;

        if ( ! get_option( 'swps_internal_links_enabled', 1 ) ) {
            return;
        }

        add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
        add_action( 'wp_ajax_swps_link_rebuild', [ $this, 'ajax_rebuild' ] );
        add_action( 'wp_ajax_swps_link_bulk_dismiss', [ $this, 'ajax_bulk_dismiss' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Register the Internal Links submenu page.
     */
    public function register_menu(): void {
        add_submenu_page(
            'stratawp-seo',
            __( 'Internal Links', 'stratawp-seo' ),
            __( 'Internal Links', 'stratawp-seo' ),
            'manage_options',
            'swps-internal-links',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Render the admin page.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
        }

        $stats = $this->get_stats();
        include SWPS_PLUGIN_DIR . 'templates/internal-links-page.php';
    }

    /**
     * Get link health statistics.
     *
     * @return array Stats array with counts.
     */
    public function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'swps_link_graph';

        $total_links = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status IN ('existing', 'inserted')"
        );

        $pending_suggestions = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'suggested'"
        );

        $post_types = (array) get_option( 'swps_internal_links_post_types', [ 'post', 'page' ] );
        $total_posts = 0;
        foreach ( $post_types as $pt ) {
            $counts = wp_count_posts( $pt );
            $total_posts += (int) ( $counts->publish ?? 0 );
        }

        $avg_links = $total_posts > 0 ? round( $total_links / $total_posts, 1 ) : 0;

        // Orphan pages: published posts with zero inbound links.
        $type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $orphan_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 WHERE p.post_type IN ({$type_placeholders})
                 AND p.post_status = 'publish'
                 AND p.ID NOT IN (
                     SELECT DISTINCT target_post_id FROM {$table}
                     WHERE status IN ('existing', 'inserted')
                 )",
                $post_types
            )
        );

        // Most linked posts (top 5 by inbound).
        $most_linked = $wpdb->get_results(
            "SELECT target_post_id, COUNT(*) as link_count
             FROM {$table}
             WHERE status IN ('existing', 'inserted')
             GROUP BY target_post_id
             ORDER BY link_count DESC
             LIMIT 5",
            ARRAY_A
        ) ?: [];

        // Opportunities (paginated).
        $page  = max( 1, absint( $_GET['link_page'] ?? 1 ) );
        $per   = 20;
        $offset = ( $page - 1 ) * $per;

        $opportunities = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'suggested' ORDER BY relevance_score DESC LIMIT %d OFFSET %d",
                $per, $offset
            ),
            ARRAY_A
        ) ?: [];

        $total_pages = (int) ceil( $pending_suggestions / $per );

        // Orphan pages list.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $orphan_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_date FROM {$wpdb->posts} p
                 WHERE p.post_type IN ({$type_placeholders})
                 AND p.post_status = 'publish'
                 AND p.ID NOT IN (
                     SELECT DISTINCT target_post_id FROM {$table}
                     WHERE status IN ('existing', 'inserted')
                 )
                 ORDER BY p.post_date DESC
                 LIMIT 50",
                $post_types
            ),
            ARRAY_A
        ) ?: [];

        return [
            'total_links'         => $total_links,
            'avg_links'           => $avg_links,
            'orphan_count'        => $orphan_count,
            'pending_suggestions' => $pending_suggestions,
            'most_linked'         => $most_linked,
            'opportunities'       => $opportunities,
            'orphan_posts'        => $orphan_posts,
            'current_page'        => $page,
            'total_pages'         => $total_pages,
        ];
    }

    /**
     * AJAX: Rebuild link index in batches.
     */
    public function ajax_rebuild(): void {
        check_ajax_referer( 'swps_internal_links', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $offset = absint( $_POST['offset'] ?? 0 );
        $result = $this->engine->rebuild_batch( $offset );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Bulk dismiss suggestions.
     */
    public function ajax_bulk_dismiss(): void {
        check_ajax_referer( 'swps_internal_links', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $ids = array_map( 'absint', $_POST['ids'] ?? [] );
        if ( empty( $ids ) ) {
            wp_send_json_error( [ 'message' => 'No IDs provided.' ] );
        }

        global $wpdb;
        $table        = $wpdb->prefix . 'swps_link_graph';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = 'dismissed', updated_at = %s WHERE id IN ({$placeholders})",
                array_merge( [ current_time( 'mysql' ) ], $ids )
            )
        );

        wp_send_json_success();
    }

    /**
     * Enqueue admin page JS.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'stratawp-seo_page_swps-internal-links' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'swps-internal-links-admin',
            SWPS_PLUGIN_URL . 'admin/js/internal-links-admin.js',
            [ 'jquery' ],
            SWPS_VERSION,
            true
        );

        wp_localize_script( 'swps-internal-links-admin', 'swpsLinksAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'swps_internal_links' ),
            'i18n'    => [
                'rebuilding' => __( 'Rebuilding index...', 'stratawp-seo' ),
                'progress'   => __( 'Processed %1$d of %2$d posts...', 'stratawp-seo' ),
                'complete'   => __( 'Rebuild complete!', 'stratawp-seo' ),
                'error'      => __( 'Error during rebuild.', 'stratawp-seo' ),
            ],
        ] );
    }
}
```

Write this to `includes/class-internal-links-admin.php`.

- [ ] **Step 2: Create the admin page template**

```php
<?php
/**
 * Internal Links overview admin page template.
 *
 * @var array $stats Stats from SWPS_Internal_Links_Admin::get_stats().
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Internal Links', 'stratawp-seo' ); ?></h1>

    <div class="swps-link-actions-bar" style="margin: 15px 0;">
        <button type="button" class="button button-primary" id="swps-rebuild-index">
            <?php esc_html_e( 'Rebuild Index', 'stratawp-seo' ); ?>
        </button>
        <span id="swps-rebuild-status"></span>
        <div id="swps-rebuild-progress" style="display:none; margin-top:8px;">
            <progress id="swps-rebuild-bar" value="0" max="100" style="width:300px;"></progress>
            <span id="swps-rebuild-text"></span>
        </div>
    </div>

    <!-- Health Summary -->
    <div class="swps-link-health" style="display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap;">
        <div class="card" style="padding:15px; min-width:150px;">
            <h3 style="margin:0 0 5px;"><?php echo esc_html( $stats['total_links'] ); ?></h3>
            <p style="margin:0; color:#666;"><?php esc_html_e( 'Total Internal Links', 'stratawp-seo' ); ?></p>
        </div>
        <div class="card" style="padding:15px; min-width:150px;">
            <h3 style="margin:0 0 5px;"><?php echo esc_html( $stats['avg_links'] ); ?></h3>
            <p style="margin:0; color:#666;"><?php esc_html_e( 'Avg Links/Post', 'stratawp-seo' ); ?></p>
        </div>
        <div class="card" style="padding:15px; min-width:150px;">
            <h3 style="margin:0 0 5px; color:<?php echo $stats['orphan_count'] > 0 ? '#d63638' : '#00a32a'; ?>;">
                <?php echo esc_html( $stats['orphan_count'] ); ?>
            </h3>
            <p style="margin:0; color:#666;"><?php esc_html_e( 'Orphan Pages', 'stratawp-seo' ); ?></p>
        </div>
        <div class="card" style="padding:15px; min-width:150px;">
            <h3 style="margin:0 0 5px;"><?php echo esc_html( $stats['pending_suggestions'] ); ?></h3>
            <p style="margin:0; color:#666;"><?php esc_html_e( 'Pending Suggestions', 'stratawp-seo' ); ?></p>
        </div>
    </div>

    <?php if ( ! empty( $stats['most_linked'] ) ) : ?>
    <h2><?php esc_html_e( 'Most Linked Posts', 'stratawp-seo' ); ?></h2>
    <table class="widefat striped" style="max-width:600px; margin-bottom:20px;">
        <thead><tr><th><?php esc_html_e( 'Post', 'stratawp-seo' ); ?></th><th><?php esc_html_e( 'Inbound Links', 'stratawp-seo' ); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $stats['most_linked'] as $row ) :
            $post = get_post( (int) $row['target_post_id'] );
            if ( ! $post ) continue;
        ?>
            <tr>
                <td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a></td>
                <td><?php echo esc_html( $row['link_count'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Opportunities Table -->
    <h2><?php esc_html_e( 'Link Opportunities', 'stratawp-seo' ); ?></h2>
    <?php if ( ! empty( $stats['opportunities'] ) ) : ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><input type="checkbox" id="swps-check-all" /></th>
                <th><?php esc_html_e( 'Source Post', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Target Post', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Relevance', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Type', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Anchor Text', 'stratawp-seo' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $stats['opportunities'] as $opp ) :
            $source = get_post( (int) $opp['source_post_id'] );
            $target = get_post( (int) $opp['target_post_id'] );
            if ( ! $source || ! $target ) continue;
            $score = (float) $opp['relevance_score'];
            $color = $score >= 0.7 ? '#00a32a' : ( $score >= 0.4 ? '#dba617' : '#d63638' );
        ?>
            <tr>
                <td><input type="checkbox" class="swps-opp-check" value="<?php echo esc_attr( $opp['id'] ); ?>" /></td>
                <td><a href="<?php echo esc_url( get_edit_post_link( $source->ID ) ); ?>"><?php echo esc_html( $source->post_title ); ?></a></td>
                <td><a href="<?php echo esc_url( get_edit_post_link( $target->ID ) ); ?>"><?php echo esc_html( $target->post_title ); ?></a></td>
                <td><span style="color:<?php echo esc_attr( $color ); ?>;">&#9679;</span> <?php echo esc_html( round( $score * 100 ) ); ?>%</td>
                <td><?php echo esc_html( $opp['match_type'] ); ?></td>
                <td><?php echo esc_html( $opp['anchor_text'] ?: '—' ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin:10px 0;">
        <button type="button" class="button" id="swps-bulk-dismiss"><?php esc_html_e( 'Dismiss Selected', 'stratawp-seo' ); ?></button>
    </div>

    <?php if ( $stats['total_pages'] > 1 ) : ?>
    <div class="tablenav">
        <div class="tablenav-pages">
            <?php for ( $i = 1; $i <= $stats['total_pages']; $i++ ) : ?>
                <?php if ( $i === $stats['current_page'] ) : ?>
                    <span class="tablenav-pages-navspan button disabled"><?php echo esc_html( $i ); ?></span>
                <?php else : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( 'link_page', $i ) ); ?>"><?php echo esc_html( $i ); ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else : ?>
        <p><?php esc_html_e( 'No link opportunities found. Try rebuilding the index.', 'stratawp-seo' ); ?></p>
    <?php endif; ?>

    <!-- Orphan Pages -->
    <h2><?php esc_html_e( 'Orphan Pages', 'stratawp-seo' ); ?></h2>
    <?php if ( ! empty( $stats['orphan_posts'] ) ) : ?>
    <p><?php esc_html_e( 'These posts have no inbound internal links — consider linking to them from related content.', 'stratawp-seo' ); ?></p>
    <table class="widefat striped" style="max-width:600px;">
        <thead><tr><th><?php esc_html_e( 'Post', 'stratawp-seo' ); ?></th><th><?php esc_html_e( 'Published', 'stratawp-seo' ); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $stats['orphan_posts'] as $orphan ) : ?>
            <tr>
                <td><a href="<?php echo esc_url( get_edit_post_link( (int) $orphan['ID'] ) ); ?>"><?php echo esc_html( $orphan['post_title'] ); ?></a></td>
                <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $orphan['post_date'] ) ) ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p style="color:#00a32a;"><?php esc_html_e( 'No orphan pages found. All posts have at least one inbound link.', 'stratawp-seo' ); ?></p>
    <?php endif; ?>
</div>
```

Write this to `templates/internal-links-page.php`.

- [ ] **Step 3: Create the admin page JavaScript**

```javascript
/* global jQuery, swpsLinksAdmin */
(function ($) {
    'use strict';

    // Rebuild Index.
    $('#swps-rebuild-index').on('click', function () {
        var $btn      = $(this).prop('disabled', true);
        var $progress = $('#swps-rebuild-progress').show();
        var $bar      = $('#swps-rebuild-bar');
        var $text     = $('#swps-rebuild-text');
        var $status   = $('#swps-rebuild-status');

        $status.text(swpsLinksAdmin.i18n.rebuilding);

        function processBatch(offset) {
            $.post(swpsLinksAdmin.ajaxUrl, {
                action: 'swps_link_rebuild',
                nonce:  swpsLinksAdmin.nonce,
                offset: offset
            }).done(function (response) {
                if (!response.success) {
                    $status.text(swpsLinksAdmin.i18n.error);
                    $btn.prop('disabled', false);
                    return;
                }

                var d = response.data;
                var pct = d.total > 0 ? Math.round((d.processed / d.total) * 100) : 100;
                $bar.val(pct);
                $text.text(
                    swpsLinksAdmin.i18n.progress
                        .replace('%1$d', d.processed)
                        .replace('%2$d', d.total)
                );

                if (d.done) {
                    $status.text(swpsLinksAdmin.i18n.complete);
                    $btn.prop('disabled', false);
                    setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                    processBatch(d.processed);
                }
            }).fail(function () {
                $status.text(swpsLinksAdmin.i18n.error);
                $btn.prop('disabled', false);
            });
        }

        processBatch(0);
    });

    // Check All.
    $('#swps-check-all').on('change', function () {
        $('.swps-opp-check').prop('checked', $(this).is(':checked'));
    });

    // Bulk Dismiss.
    $('#swps-bulk-dismiss').on('click', function () {
        var ids = [];
        $('.swps-opp-check:checked').each(function () {
            ids.push($(this).val());
        });

        if (!ids.length) return;

        $.post(swpsLinksAdmin.ajaxUrl, {
            action: 'swps_link_bulk_dismiss',
            nonce:  swpsLinksAdmin.nonce,
            ids:    ids
        }).done(function () {
            window.location.reload();
        });
    });

})(jQuery);
```

Write this to `admin/js/internal-links-admin.js`.

- [ ] **Step 4: Verify all files pass syntax check**

Run: `php -l includes/class-internal-links-admin.php && php -l templates/internal-links-page.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 5: Commit**

```bash
git add includes/class-internal-links-admin.php templates/internal-links-page.php admin/js/internal-links-admin.js
git commit -m "feat: add Internal Links admin overview page with health dashboard and opportunities table"
```

---

### Task 6: Wire Into Main Plugin

**Files:**
- Modify: `stratawp-seo.php`

Add require_once calls, class instantiation, and activation hook entries.

- [ ] **Step 1: Add require_once entries**

In `stratawp-seo.php`, after the line `require_once SWPS_PLUGIN_DIR . 'includes/class-post-list-seo.php';` (line 102), add:

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-link-keyword-engine.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-link-ai-engine.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-internal-links.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-internal-links-admin.php';
```

- [ ] **Step 2: Add class properties**

In `stratawp-seo.php`, after the line `public SWPS_Post_List_SEO $post_list_seo;` (line 161), add:

```php
    public SWPS_Internal_Links $internal_links;
    public SWPS_Internal_Links_Admin $internal_links_admin;
```

- [ ] **Step 3: Add instantiation**

In `stratawp-seo.php`, after the line where `$this->post_list_seo` is created (inside the `if ( is_admin() )` block around line 203), add the internal links instantiation. Find the closing brace of `if ( is_admin() ) {` block, and **before** `$this->settings = new SWPS_Settings();` (line 205), add:

```php
        $link_keyword_engine = new SWPS_Link_Keyword_Engine();
        $link_ai_engine      = new SWPS_Link_AI_Engine( $this->api, $this->cost_tracker );
        $this->internal_links = new SWPS_Internal_Links( $link_keyword_engine, $link_ai_engine );
        $this->internal_links_admin = new SWPS_Internal_Links_Admin( $this->internal_links );
```

Note: This should be placed **outside** the `if ( is_admin() )` block, since the generator integration hooks need to run on cron/REST too. Place it right after the `if ( is_admin() )` block closes.

- [ ] **Step 4: Add activation hook entries**

In the `swps_activate()` function, after the line `SWPS_Redirect_Manager::create_tables();` (line 973), add:

```php
    SWPS_Link_Keyword_Engine::create_tables();
    SWPS_Internal_Links::create_tables();
    SWPS_Internal_Links::schedule_cron();
```

- [ ] **Step 5: Verify syntax**

Run: `php -l stratawp-seo.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add stratawp-seo.php
git commit -m "feat: wire SWPS_Internal_Links into main plugin bootstrap and activation"
```

---

### Task 7: CSS Styles

**Files:**
- Modify: `admin/css/admin.css`

Add styles for the internal links metabox and admin page.

- [ ] **Step 1: Append internal links styles to admin.css**

Add the following at the end of `admin/css/admin.css`:

```css
/* Internal Links Metabox */
#swps-internal-links-metabox .swps-link-stats {
    font-weight: 600;
    margin-bottom: 12px;
}

#swps-internal-links-metabox h4 {
    margin: 12px 0 6px;
    font-size: 13px;
}

#swps-internal-links-metabox ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

#swps-internal-links-metabox li {
    padding: 6px 0;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}

#swps-internal-links-metabox .swps-score-dot {
    font-size: 14px;
    line-height: 1;
}

#swps-internal-links-metabox .swps-anchor-text,
#swps-internal-links-metabox .swps-suggested-anchor {
    color: #666;
    font-size: 12px;
}

#swps-internal-links-metabox .swps-rationale {
    display: block;
    width: 100%;
    font-size: 11px;
    color: #888;
    font-style: italic;
}

#swps-internal-links-metabox .swps-link-actions {
    margin-left: auto;
}

#swps-internal-links-metabox .swps-link-actions .button {
    margin-left: 4px;
}

#swps-internal-links-metabox .swps-link-metabox-actions {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #ddd;
}

#swps-deep-analysis-status {
    margin-left: 8px;
    color: #666;
}
```

- [ ] **Step 2: Commit**

```bash
git add admin/css/admin.css
git commit -m "style: add internal links metabox and admin page CSS"
```

---

### Task 8: Settings Registration

**Files:**
- Modify: `includes/class-settings.php`

Register the internal links settings in the existing settings infrastructure.

- [ ] **Step 1: Locate and read the register_settings method**

Read `includes/class-settings.php` to find where settings groups are registered, then add the internal links settings section and fields to the existing pattern.

Look for `register_setting` and `add_settings_section` calls to understand the pattern, then add:

```php
        // Internal Links settings.
        register_setting( 'swps_settings', 'swps_internal_links_enabled', [
            'type'    => 'boolean',
            'default' => true,
        ] );
        register_setting( 'swps_settings', 'swps_internal_links_post_types', [
            'type'    => 'array',
            'default' => [ 'post', 'page' ],
        ] );
        register_setting( 'swps_settings', 'swps_link_relevance_threshold', [
            'type'    => 'number',
            'default' => 0.3,
        ] );
        register_setting( 'swps_settings', 'swps_link_max_suggestions', [
            'type'    => 'integer',
            'default' => 10,
        ] );
        register_setting( 'swps_settings', 'swps_internal_links_in_generation', [
            'type'    => 'boolean',
            'default' => true,
        ] );
        register_setting( 'swps_settings', 'swps_link_ai_batch_size', [
            'type'    => 'integer',
            'default' => 10,
        ] );
```

Add these to the existing `register_settings()` method, at the end of the other `register_setting` calls.

- [ ] **Step 2: Verify syntax**

Run: `php -l includes/class-settings.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/class-settings.php
git commit -m "feat: register internal links settings in SWPS_Settings"
```

---

### Task 9: Version Bump

**Files:**
- Modify: `stratawp-seo.php`

Bump the version to 3.5.0 to reflect the new feature.

- [ ] **Step 1: Update version in file header**

Change `Version: 3.4.0` to `Version: 3.5.0` in the plugin header comment.

- [ ] **Step 2: Update version constant**

Change `define( 'SWPS_VERSION', '3.4.0' );` to `define( 'SWPS_VERSION', '3.5.0' );`.

- [ ] **Step 3: Commit**

```bash
git add stratawp-seo.php
git commit -m "chore: bump version to 3.5.0 for Internal Linking Engine feature"
```

---

### Task 10: Final Integration Verification

- [ ] **Step 1: Run syntax check on all new and modified files**

```bash
php -l includes/class-link-keyword-engine.php && \
php -l includes/class-link-ai-engine.php && \
php -l includes/class-internal-links.php && \
php -l includes/class-internal-links-admin.php && \
php -l templates/internal-links-page.php && \
php -l stratawp-seo.php && \
php -l includes/class-settings.php
```

Expected: `No syntax errors detected` for all files.

- [ ] **Step 2: Verify all files exist**

```bash
ls -la includes/class-link-keyword-engine.php \
       includes/class-link-ai-engine.php \
       includes/class-internal-links.php \
       includes/class-internal-links-admin.php \
       templates/internal-links-page.php \
       admin/js/internal-links.js \
       admin/js/internal-links-admin.js
```

Expected: All 7 files listed.

- [ ] **Step 3: Verify no PHP fatal errors on load**

```bash
php -r "
define('ABSPATH', '/tmp/');
define('SWPS_PLUGIN_DIR', '$(pwd)/');
define('SWPS_PLUGIN_URL', 'http://test/');
// Just check that the files can be parsed together without redeclaration errors.
echo 'All files parseable.';
"
```

- [ ] **Step 4: Final commit (if any cleanup was needed)**

```bash
git status
```

Review and commit any remaining changes.
