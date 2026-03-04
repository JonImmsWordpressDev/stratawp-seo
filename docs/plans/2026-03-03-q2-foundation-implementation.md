# Q2 Foundation Sprint — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add content scoring (7 analyzers), voice profiles (CPT + prompt compilation), in-content image insertion, and expanded developer hooks to the StrataWP SEO plugin.

**Architecture:** Hook-based modular services. Three new classes (`SWPS_Content_Scorer`, `SWPS_Voice_Profile`, `SWPS_Image_Inserter`) plug into the existing pipeline via WordPress filters/actions. Minimal changes to `SWPS_Generator`. All new code follows existing `SWPS_` prefix conventions.

**Tech Stack:** PHP 8.0+, WordPress 6.0+ APIs (CPT, post meta, Settings API, REST API, hooks)

**Design doc:** `docs/plans/2026-03-03-q2-foundation-sprint-design.md`

---

## Task 1: Content Scorer — Core Class

**Files:**
- Create: `includes/class-content-scorer.php`

**Step 1: Create the content scorer class with all 7 analyzers**

```php
<?php
/**
 * Content scoring pipeline.
 *
 * Scores AI-generated content against SEO best practices using 7 analyzers.
 * Each analyzer returns 0-100, weighted into an overall score.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Content_Scorer {

    /**
     * Default analyzer weights.
     */
    private const DEFAULT_WEIGHTS = [
        'keyword_density'  => 15,
        'readability'      => 20,
        'heading_structure' => 15,
        'meta_quality'     => 15,
        'internal_links'   => 10,
        'content_depth'    => 15,
        'engagement'       => 10,
    ];

    /**
     * Score content from an AI generation result.
     *
     * @param int   $post_id   The WordPress post ID.
     * @param array $ai_result The AI response data (title, content_html, meta_description, etc.).
     * @return array {overall_score: int, details: array, recommendations: string[]}
     */
    public function score( int $post_id, array $ai_result ): array {
        $content       = $ai_result['content_html'] ?? '';
        $title         = $ai_result['title'] ?? '';
        $meta_desc     = $ai_result['meta_description'] ?? '';
        $focus_keyword = $ai_result['focus_keyword'] ?? '';
        $secondary_kw  = $ai_result['secondary_keywords'] ?? [];
        $internal      = $ai_result['internal_links_used'] ?? [];
        $word_count    = $ai_result['estimated_word_count'] ?? str_word_count( wp_strip_all_tags( $content ) );

        $post_type = get_post_type( $post_id ) ?: 'post';
        $weights   = apply_filters( 'swps_score_weights', self::DEFAULT_WEIGHTS, $post_type );

        $details         = [];
        $recommendations = [];

        // Run each analyzer.
        $details['keyword_density']   = $this->analyze_keyword_density( $content, $focus_keyword, $secondary_kw, $word_count, $recommendations );
        $details['readability']       = $this->analyze_readability( $content, $recommendations );
        $details['heading_structure'] = $this->analyze_heading_structure( $content, $focus_keyword, $recommendations );
        $details['meta_quality']      = $this->analyze_meta_quality( $title, $meta_desc, $focus_keyword, $recommendations );
        $details['internal_links']    = $this->analyze_internal_links( $content, $internal, $word_count, $recommendations );
        $details['content_depth']     = $this->analyze_content_depth( $content, $word_count, $recommendations );
        $details['engagement']        = $this->analyze_engagement( $content, $recommendations );

        // Calculate weighted overall score.
        $total_weight = array_sum( $weights );
        $weighted_sum = 0;
        foreach ( $details as $key => $score ) {
            $weight = $weights[ $key ] ?? 0;
            $weighted_sum += $score * $weight;
        }
        $overall = $total_weight > 0 ? (int) round( $weighted_sum / $total_weight ) : 0;

        return [
            'overall_score'   => $overall,
            'details'         => $details,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Analyze keyword density.
     *
     * Target: primary keyword appears 0.5-3% of content. Secondary keywords present.
     */
    private function analyze_keyword_density( string $content, string $keyword, array $secondary, int $word_count, array &$recs ): int {
        if ( empty( $keyword ) || $word_count === 0 ) {
            $recs[] = 'No focus keyword set — cannot analyze keyword density.';
            return 0;
        }

        $text      = strtolower( wp_strip_all_tags( $content ) );
        $keyword_l = strtolower( $keyword );
        $count     = substr_count( $text, $keyword_l );
        $density   = ( $count / $word_count ) * 100;

        $score = 100;

        if ( $density < 0.5 ) {
            $deficit = 0.5 - $density;
            $score -= (int) min( 50, $deficit * 100 );
            $recs[] = sprintf( 'Focus keyword "%s" density is %.1f%% — aim for 0.5-3%%.', $keyword, $density );
        } elseif ( $density > 3.0 ) {
            $excess = $density - 3.0;
            $score -= (int) min( 50, $excess * 20 );
            $recs[] = sprintf( 'Focus keyword "%s" density is %.1f%% — reduce to under 3%% to avoid stuffing.', $keyword, $density );
        }

        // Check secondary keywords.
        $found = 0;
        foreach ( $secondary as $kw ) {
            if ( str_contains( $text, strtolower( $kw ) ) ) {
                $found++;
            }
        }
        $secondary_count = count( $secondary );
        if ( $secondary_count > 0 && $found < $secondary_count ) {
            $missing = $secondary_count - $found;
            $score -= $missing * 5;
            $recs[] = sprintf( '%d of %d secondary keywords missing from content.', $missing, $secondary_count );
        }

        return max( 0, min( 100, $score ) );
    }

    /**
     * Analyze readability using Flesch-Kincaid grade level approximation.
     *
     * Target: grade level 6-10 (accessible but not dumbed down).
     */
    private function analyze_readability( string $content, array &$recs ): int {
        $text = wp_strip_all_tags( $content );
        if ( empty( trim( $text ) ) ) {
            return 0;
        }

        $sentences  = max( 1, preg_match_all( '/[.!?]+/', $text ) );
        $words      = max( 1, str_word_count( $text ) );
        $syllables  = $this->count_syllables( $text );

        $avg_sentence_length = $words / $sentences;
        $avg_syllables       = $syllables / $words;

        // Flesch-Kincaid grade level.
        $grade = ( 0.39 * $avg_sentence_length ) + ( 11.8 * $avg_syllables ) - 15.59;
        $grade = max( 0, $grade );

        $score = 100;

        if ( $grade > 12 ) {
            $score -= (int) min( 40, ( $grade - 12 ) * 10 );
            $recs[] = sprintf( 'Readability grade level is %.1f — simplify sentences for broader audience.', $grade );
        } elseif ( $grade < 5 ) {
            $score -= (int) min( 20, ( 5 - $grade ) * 5 );
            $recs[] = sprintf( 'Readability grade level is %.1f — content may be too simplistic.', $grade );
        }

        if ( $avg_sentence_length > 25 ) {
            $score -= 15;
            $recs[] = sprintf( 'Average sentence length is %.0f words — aim for under 25.', $avg_sentence_length );
        }

        return max( 0, min( 100, $score ) );
    }

    /**
     * Analyze heading structure.
     *
     * Checks H2/H3 hierarchy, keyword presence, no skipped levels.
     */
    private function analyze_heading_structure( string $content, string $keyword, array &$recs ): int {
        $score = 100;

        // Extract headings.
        preg_match_all( '/<h([2-6])[^>]*>(.*?)<\/h\1>/i', $content, $matches, PREG_SET_ORDER );

        if ( empty( $matches ) ) {
            $recs[] = 'No headings found — add H2 sections to structure content.';
            return 20;
        }

        $h2_count = 0;
        $h3_count = 0;
        $has_h1   = (bool) preg_match( '/<h1/i', $content );
        $levels   = [];

        foreach ( $matches as $match ) {
            $level = (int) $match[1];
            $levels[] = $level;
            if ( $level === 2 ) {
                $h2_count++;
            }
            if ( $level === 3 ) {
                $h3_count++;
            }
        }

        // H1 in content is bad (WordPress uses title as H1).
        if ( $has_h1 ) {
            $score -= 20;
            $recs[] = 'Content contains H1 — remove it; WordPress uses the post title as H1.';
        }

        // Should have at least 2 H2s.
        if ( $h2_count < 2 ) {
            $score -= 20;
            $recs[] = 'Only ' . $h2_count . ' H2 heading(s) — add more to structure content.';
        }

        // Check for skipped levels (e.g., H2 → H4 with no H3).
        for ( $i = 1; $i < count( $levels ); $i++ ) {
            if ( $levels[ $i ] > $levels[ $i - 1 ] + 1 ) {
                $score -= 10;
                $recs[] = 'Heading level skipped (H' . $levels[ $i - 1 ] . ' to H' . $levels[ $i ] . ') — maintain proper hierarchy.';
                break;
            }
        }

        // Keyword in at least one heading.
        if ( ! empty( $keyword ) ) {
            $keyword_in_heading = false;
            foreach ( $matches as $match ) {
                if ( str_contains( strtolower( wp_strip_all_tags( $match[2] ) ), strtolower( $keyword ) ) ) {
                    $keyword_in_heading = true;
                    break;
                }
            }
            if ( ! $keyword_in_heading ) {
                $score -= 15;
                $recs[] = 'Focus keyword not found in any heading — include it in at least one H2.';
            }
        }

        return max( 0, min( 100, $score ) );
    }

    /**
     * Analyze meta quality (title tag and meta description).
     */
    private function analyze_meta_quality( string $title, string $meta_desc, string $keyword, array &$recs ): int {
        $score       = 100;
        $title_len   = mb_strlen( $title );
        $desc_len    = mb_strlen( $meta_desc );
        $keyword_l   = strtolower( $keyword );

        // Title length: ideal 50-60 chars.
        if ( $title_len < 30 ) {
            $score -= 20;
            $recs[] = sprintf( 'Title is %d chars — aim for 50-60 for optimal display.', $title_len );
        } elseif ( $title_len > 65 ) {
            $score -= 10;
            $recs[] = sprintf( 'Title is %d chars — may be truncated in search results (aim for 50-60).', $title_len );
        }

        // Meta description length: ideal 150-160 chars.
        if ( $desc_len < 120 ) {
            $score -= 15;
            $recs[] = sprintf( 'Meta description is %d chars — aim for 150-160 to fill the SERP snippet.', $desc_len );
        } elseif ( $desc_len > 165 ) {
            $score -= 10;
            $recs[] = sprintf( 'Meta description is %d chars — may be truncated (aim for 150-160).', $desc_len );
        }

        // Keyword in title.
        if ( ! empty( $keyword_l ) && ! str_contains( strtolower( $title ), $keyword_l ) ) {
            $score -= 15;
            $recs[] = 'Focus keyword not found in the title.';
        }

        // Keyword in meta description.
        if ( ! empty( $keyword_l ) && ! str_contains( strtolower( $meta_desc ), $keyword_l ) ) {
            $score -= 10;
            $recs[] = 'Focus keyword not found in the meta description.';
        }

        return max( 0, min( 100, $score ) );
    }

    /**
     * Analyze internal links.
     */
    private function analyze_internal_links( string $content, array $links, int $word_count, array &$recs ): int {
        $score      = 100;
        $link_count = count( $links );
        $min_links  = (int) get_option( 'swps_internal_links_min', 3 );

        if ( $link_count === 0 ) {
            $recs[] = 'No internal links found — add links to related content.';
            return 20;
        }

        if ( $link_count < $min_links ) {
            $score -= ( $min_links - $link_count ) * 15;
            $recs[] = sprintf( '%d internal link(s) found — minimum target is %d.', $link_count, $min_links );
        }

        // Check for generic anchor text.
        $generic = [ 'click here', 'read more', 'this article', 'this post', 'here', 'link' ];
        foreach ( $links as $link ) {
            $anchor = strtolower( trim( $link['anchor_text'] ?? '' ) );
            if ( in_array( $anchor, $generic, true ) ) {
                $score -= 10;
                $recs[] = sprintf( 'Generic anchor text "%s" — use descriptive text instead.', $anchor );
                break; // Only flag once.
            }
        }

        return max( 0, min( 100, $score ) );
    }

    /**
     * Analyze content depth.
     */
    private function analyze_content_depth( string $content, int $word_count, array &$recs ): int {
        $score    = 100;
        $min_words = (int) get_option( 'swps_word_count_min', 1200 );

        if ( $word_count < $min_words ) {
            $deficit = $min_words - $word_count;
            $score -= (int) min( 40, ( $deficit / $min_words ) * 60 );
            $recs[] = sprintf( 'Word count is %d — target minimum is %d.', $word_count, $min_words );
        }

        // Paragraph count (rough indicator of depth).
        $paragraphs = substr_count( $content, '<p>' ) + substr_count( $content, '<p ' );
        if ( $paragraphs < 5 ) {
            $score -= 15;
            $recs[] = sprintf( 'Only %d paragraphs — add more depth and detail.', $paragraphs );
        }

        // Heading count (indicates topic coverage).
        $heading_count = preg_match_all( '/<h[2-3]/i', $content );
        if ( $heading_count < 3 ) {
            $score -= 10;
            $recs[] = 'Fewer than 3 H2/H3 headings — consider covering more subtopics.';
        }

        return max( 0, min( 100, $score ) );
    }

    /**
     * Analyze engagement signals.
     */
    private function analyze_engagement( string $content, array &$recs ): int {
        $score = 100;
        $text  = wp_strip_all_tags( $content );

        // Questions in content.
        $questions = preg_match_all( '/\?/', $text );
        if ( $questions === 0 ) {
            $score -= 15;
            $recs[] = 'No questions found in content — questions improve reader engagement.';
        }

        // Lists.
        $has_lists = preg_match( '/<[uo]l/i', $content );
        if ( ! $has_lists ) {
            $score -= 15;
            $recs[] = 'No lists found — bullet/numbered lists improve scannability.';
        }

        // Bold/emphasis (indicates key points).
        $has_emphasis = preg_match( '/<(strong|em|b)\b/i', $content );
        if ( ! $has_emphasis ) {
            $score -= 10;
            $recs[] = 'No bold or emphasis text — highlight key points for scannability.';
        }

        // External links (credibility signals).
        $external_links = preg_match_all( '/<a[^>]+href=["\']https?:\/\//i', $content );
        $internal_links = preg_match_all( '/<a[^>]+href/i', $content );
        $ext_count = max( 0, $external_links );
        if ( $ext_count === 0 ) {
            $score -= 10;
            $recs[] = 'No external links — cite authoritative sources for credibility.';
        }

        return max( 0, min( 100, $score ) );
    }

    /**
     * Estimate syllable count for English text.
     */
    private function count_syllables( string $text ): int {
        $words    = preg_split( '/\s+/', strtolower( trim( $text ) ) );
        $syllables = 0;

        foreach ( $words as $word ) {
            $word = preg_replace( '/[^a-z]/', '', $word );
            if ( strlen( $word ) <= 3 ) {
                $syllables += 1;
                continue;
            }

            // Remove silent e.
            $word = preg_replace( '/e$/', '', $word );

            // Count vowel groups.
            $count = preg_match_all( '/[aeiouy]+/', $word );
            $syllables += max( 1, $count );
        }

        return $syllables;
    }
}
```

**Step 2: Commit**

```bash
git add includes/class-content-scorer.php
git commit -m "feat: Add SWPS_Content_Scorer with 7 SEO analyzers"
```

---

## Task 2: Content Scorer — Integration

**Files:**
- Modify: `stratawp-seo.php` (require + instantiate + hook)
- Modify: `includes/class-hooks.php` (add new hook helpers)

**Step 1: Add new hook helpers to SWPS_Hooks**

Add these methods to the end of `SWPS_Hooks` class in `includes/class-hooks.php` (before the closing `}`):

```php
    /**
     * Apply the score weights filter.
     *
     * @param array  $weights   Analyzer weight map.
     * @param string $post_type The post type being scored.
     * @return array Filtered weights.
     */
    public static function filter_score_weights( array $weights, string $post_type ): array {
        return apply_filters( 'swps_score_weights', $weights, $post_type );
    }

    /**
     * Fire the score_complete action.
     *
     * @param array $results Score results array.
     * @param int   $post_id The scored post ID.
     */
    public static function do_score_complete( array $results, int $post_id ): void {
        do_action( 'swps_score_complete', $results, $post_id );
    }
```

**Step 2: Add require and instantiation in stratawp-seo.php**

In `stratawp-seo.php`, add after line 63 (after `class-topic-queue.php` require):

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-content-scorer.php';
```

In the `StrataWP_SEO` class, add a property after line 103 (after `$rest_api`):

```php
    public SWPS_Content_Scorer $content_scorer;
```

In the constructor, after line 120 (after `$this->topic_queue = new SWPS_Topic_Queue();`), add:

```php
        $this->content_scorer = new SWPS_Content_Scorer();
```

Add the scoring hook in the constructor after the `wp_head` hooks (after line 163), add:

```php
        // Content scoring on post creation.
        add_action( 'swps_post_created', [ $this, 'score_generated_post' ], 10, 3 );
```

Add the callback method to the `StrataWP_SEO` class (before the closing `}`):

```php
    /**
     * Score a generated post and store results.
     *
     * @param int   $post_id   The new post ID.
     * @param array $ai_result The AI response data.
     * @param array $post_data The WordPress post data.
     */
    public function score_generated_post( int $post_id, array $ai_result, array $post_data ): void {
        $results = $this->content_scorer->score( $post_id, $ai_result );

        update_post_meta( $post_id, '_swps_content_score', $results['overall_score'] );
        update_post_meta( $post_id, '_swps_score_details', $results['details'] );
        update_post_meta( $post_id, '_swps_score_recommendations', $results['recommendations'] );

        // Optional score gate: force draft if below threshold.
        $min_score = (int) get_option( 'swps_min_content_score', 0 );
        if ( $min_score > 0 && $results['overall_score'] < $min_score ) {
            wp_update_post( [
                'ID'          => $post_id,
                'post_status' => 'draft',
            ] );
            update_post_meta( $post_id, '_swps_score_blocked', true );
        }

        SWPS_Hooks::do_score_complete( $results, $post_id );
    }
```

**Step 3: Add score to the generator return data**

In `includes/class-generator.php`, in the `create_wp_post` method, the return array (around line 441-454) needs the score added. However, since scoring runs as a hook on `swps_post_created` which fires *before* the return, we can read it back. Add after line 439 (`SWPS_Hooks::do_post_created(...)`) and before the `return` statement:

```php
        // Read back content score (set by swps_post_created hook).
        $content_score = get_post_meta( $post_id, '_swps_content_score', true );
```

Then add `'content_score'` to the return array:

```php
            'content_score'    => $content_score ?: null,
```

**Step 4: Commit**

```bash
git add includes/class-hooks.php stratawp-seo.php includes/class-generator.php
git commit -m "feat: Integrate content scorer into generation pipeline"
```

---

## Task 3: Content Scorer — Settings & Admin UI

**Files:**
- Modify: `includes/class-settings.php` (add min score field)
- Modify: `admin/js/admin.js` (display score in results)
- Modify: `admin/css/admin.css` (score badge styles)

**Step 1: Add the minimum score setting**

In `includes/class-settings.php`, in `register_settings()`, after the `cost_tracking` field (around line 275), add:

```php
        $this->add_field( 'min_content_score', __( 'Minimum Content Score', 'stratawp-seo' ), 'number', 'swps_advanced_section', [
            'min'         => 0,
            'max'         => 100,
            'description' => __( 'Posts scoring below this threshold are saved as drafts. Set to 0 to disable.', 'stratawp-seo' ),
        ] );
```

**Step 2: Add score badge CSS to admin.css**

Append to `admin/css/admin.css`:

```css
/* Content Score Badge */
.swps-score-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    color: #fff;
    line-height: 1.4;
}
.swps-score-badge--excellent { background: #00a32a; }
.swps-score-badge--good { background: #dba617; color: #1d2327; }
.swps-score-badge--poor { background: #d63638; }
.swps-score-details {
    margin-top: 8px;
    font-size: 12px;
    color: #646970;
}
.swps-score-details li {
    margin-bottom: 2px;
}
.swps-score-blocked-notice {
    color: #d63638;
    font-weight: 600;
    margin-top: 4px;
}
```

**Step 3: Display score in generation results (admin.js)**

In `admin/js/admin.js`, find the success handler that displays generation results. Add score display logic. The exact location depends on how results are rendered — look for where `data.title` or `data.edit_url` is used in the success callback.

Add a helper function at the top of the admin JS (inside the IIFE or jQuery ready):

```javascript
function swpsScoreBadge(score) {
    if (score === null || score === undefined) return '';
    var cls = score >= 80 ? 'excellent' : (score >= 60 ? 'good' : 'poor');
    return '<span class="swps-score-badge swps-score-badge--' + cls + '">Score: ' + score + '/100</span>';
}
```

Then include `swpsScoreBadge(data.content_score)` in the result output HTML wherever post results are displayed.

**Step 4: Add default option on activation**

In `stratawp-seo.php`, in the `swps_activate()` function's `$defaults` array (around line 488), add:

```php
        'min_content_score'  => 0,
```

**Step 5: Commit**

```bash
git add includes/class-settings.php admin/css/admin.css admin/js/admin.js stratawp-seo.php
git commit -m "feat: Add content score settings, badge UI, and activation default"
```

---

## Task 4: Voice Profile — Core Class

**Files:**
- Create: `includes/class-voice-profile.php`

**Step 1: Create the voice profile class**

```php
<?php
/**
 * Voice Profile management.
 *
 * Custom post type for storing brand voice configurations that shape
 * AI-generated content via system prompt injection.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Voice_Profile {

    public const POST_TYPE = 'swps_voice_profile';

    /**
     * Register hooks.
     */
    public function __construct() {
        add_action( 'init', [ self::class, 'register_post_type' ] );
        add_filter( 'swps_system_prompt', [ $this, 'inject_voice_profile' ], 5, 3 );
    }

    /**
     * Register the custom post type.
     */
    public static function register_post_type(): void {
        register_post_type( self::POST_TYPE, [
            'labels'       => [
                'name'               => __( 'Voice Profiles', 'stratawp-seo' ),
                'singular_name'      => __( 'Voice Profile', 'stratawp-seo' ),
                'add_new_item'       => __( 'Add New Voice Profile', 'stratawp-seo' ),
                'edit_item'          => __( 'Edit Voice Profile', 'stratawp-seo' ),
                'new_item'           => __( 'New Voice Profile', 'stratawp-seo' ),
                'all_items'          => __( 'All Voice Profiles', 'stratawp-seo' ),
                'not_found'          => __( 'No voice profiles found.', 'stratawp-seo' ),
                'not_found_in_trash' => __( 'No voice profiles found in Trash.', 'stratawp-seo' ),
            ],
            'public'       => false,
            'show_ui'      => false,
            'show_in_rest' => false,
            'supports'     => [ 'title' ],
            'capabilities' => [
                'create_posts' => 'manage_options',
            ],
        ] );
    }

    /**
     * Create a voice profile.
     *
     * @param string $name Profile name.
     * @param array  $meta Profile settings.
     * @return int|WP_Error Post ID or error.
     */
    public function create( string $name, array $meta ): int|WP_Error {
        $post_id = wp_insert_post( [
            'post_type'   => self::POST_TYPE,
            'post_title'  => sanitize_text_field( $name ),
            'post_status' => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $this->save_meta( $post_id, $meta );
        return $post_id;
    }

    /**
     * Update a voice profile.
     *
     * @param int    $profile_id Profile post ID.
     * @param string $name       Profile name.
     * @param array  $meta       Profile settings.
     * @return int|WP_Error Post ID or error.
     */
    public function update( int $profile_id, string $name, array $meta ): int|WP_Error {
        $result = wp_update_post( [
            'ID'         => $profile_id,
            'post_title' => sanitize_text_field( $name ),
        ], true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->save_meta( $profile_id, $meta );
        return $profile_id;
    }

    /**
     * Delete a voice profile.
     *
     * @param int $profile_id Profile post ID.
     * @return bool
     */
    public function delete( int $profile_id ): bool {
        // If this was the active profile, clear the option.
        if ( (int) get_option( 'swps_voice_profile', 0 ) === $profile_id ) {
            update_option( 'swps_voice_profile', 0 );
        }
        return false !== wp_delete_post( $profile_id, true );
    }

    /**
     * Get all voice profiles.
     *
     * @return array Array of profile data arrays.
     */
    public function get_all(): array {
        $posts = get_posts( [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        return array_map( [ $this, 'format_profile' ], $posts );
    }

    /**
     * Get a single voice profile.
     *
     * @param int $profile_id Profile post ID.
     * @return array|null Profile data or null.
     */
    public function get( int $profile_id ): ?array {
        $post = get_post( $profile_id );
        if ( ! $post || $post->post_type !== self::POST_TYPE ) {
            return null;
        }
        return $this->format_profile( $post );
    }

    /**
     * Get the active voice profile ID.
     *
     * @return int Profile ID or 0 if none.
     */
    public function get_active_id(): int {
        return (int) get_option( 'swps_voice_profile', 0 );
    }

    /**
     * Compile a voice profile into a system prompt block.
     *
     * @param int $profile_id Profile post ID.
     * @return string Compiled prompt text.
     */
    public function compile( int $profile_id ): string {
        $profile = $this->get( $profile_id );
        if ( ! $profile ) {
            return '';
        }

        $parts = [];

        if ( ! empty( $profile['tone'] ) ) {
            $parts[] = sprintf( 'Write in a %s tone.', $profile['tone'] );
        }

        if ( ! empty( $profile['formality'] ) ) {
            $parts[] = sprintf( 'Formality level: %d/10.', $profile['formality'] );
        }

        if ( ! empty( $profile['sentence_length'] ) ) {
            $parts[] = sprintf( 'Use %s sentences.', $profile['sentence_length'] );
        }

        if ( ! empty( $profile['vocabulary_level'] ) ) {
            $parts[] = sprintf( 'Vocabulary: %s.', $profile['vocabulary_level'] );
        }

        if ( ! empty( $profile['person'] ) ) {
            $label = match ( $profile['person'] ) {
                'first'  => 'first person (I/we)',
                'second' => 'second person (you)',
                'third'  => 'third person (they/one)',
                default  => $profile['person'] . ' person',
            };
            $parts[] = sprintf( 'Write in %s.', $label );
        }

        if ( ! empty( $profile['avoid_phrases'] ) ) {
            $parts[] = sprintf( 'Never use these phrases: %s.', implode( ', ', $profile['avoid_phrases'] ) );
        }

        if ( ! empty( $profile['preferred_phrases'] ) ) {
            $parts[] = sprintf( 'Prefer these expressions: %s.', implode( ', ', $profile['preferred_phrases'] ) );
        }

        if ( ! empty( $profile['example_content'] ) ) {
            $excerpt = mb_substr( $profile['example_content'], 0, 500 );
            $parts[] = sprintf( "Match the writing style of this sample:\n\"%s\"", $excerpt );
        }

        $compiled = "VOICE PROFILE — {$profile['name']}:\n" . implode( ' ', $parts );

        return apply_filters( 'swps_voice_compile', $compiled, $profile_id );
    }

    /**
     * Inject the active voice profile into the system prompt.
     *
     * Hooks into swps_system_prompt at priority 5 (before other filters).
     *
     * @param string $prompt System prompt.
     * @param string $tone   Tone setting.
     * @param string $style  Style setting.
     * @return string Modified prompt.
     */
    public function inject_voice_profile( string $prompt, string $tone, string $style ): string {
        $profile_id = $this->get_active_id();
        if ( $profile_id <= 0 ) {
            return $prompt;
        }

        $voice_block = $this->compile( $profile_id );
        if ( empty( $voice_block ) ) {
            return $prompt;
        }

        // Replace the generic TONE line with the compiled voice profile.
        $prompt = preg_replace( '/^TONE:.*$/m', $voice_block, $prompt, 1 );

        return $prompt;
    }

    /**
     * Get profile options for a select dropdown.
     *
     * @return array [id => name] pairs with 0 => "None".
     */
    public function get_options(): array {
        $options = [ 0 => __( '— None (use tone/style settings) —', 'stratawp-seo' ) ];

        foreach ( $this->get_all() as $profile ) {
            $options[ $profile['id'] ] = $profile['name'];
        }

        return $options;
    }

    /**
     * Save profile meta fields.
     */
    private function save_meta( int $post_id, array $meta ): void {
        $fields = [
            'tone'             => 'sanitize_text_field',
            'formality'        => 'absint',
            'sentence_length'  => 'sanitize_text_field',
            'vocabulary_level' => 'sanitize_text_field',
            'person'           => 'sanitize_text_field',
            'example_content'  => 'sanitize_textarea_field',
            'avoid_phrases'    => null, // JSON array.
            'preferred_phrases' => null, // JSON array.
        ];

        foreach ( $fields as $field => $sanitize ) {
            if ( ! array_key_exists( $field, $meta ) ) {
                continue;
            }

            $value = $meta[ $field ];

            if ( $sanitize ) {
                $value = call_user_func( $sanitize, $value );
            } elseif ( is_string( $value ) ) {
                // Parse comma-separated string into array.
                $value = array_filter( array_map( 'trim', explode( ',', $value ) ) );
            }

            update_post_meta( $post_id, '_swps_vp_' . $field, $value );
        }
    }

    /**
     * Format a profile post into a data array.
     */
    private function format_profile( WP_Post $post ): array {
        return [
            'id'               => $post->ID,
            'name'             => $post->post_title,
            'tone'             => get_post_meta( $post->ID, '_swps_vp_tone', true ) ?: '',
            'formality'        => (int) get_post_meta( $post->ID, '_swps_vp_formality', true ) ?: 5,
            'sentence_length'  => get_post_meta( $post->ID, '_swps_vp_sentence_length', true ) ?: 'varied',
            'vocabulary_level' => get_post_meta( $post->ID, '_swps_vp_vocabulary_level', true ) ?: 'moderate',
            'person'           => get_post_meta( $post->ID, '_swps_vp_person', true ) ?: 'second',
            'example_content'  => get_post_meta( $post->ID, '_swps_vp_example_content', true ) ?: '',
            'avoid_phrases'    => get_post_meta( $post->ID, '_swps_vp_avoid_phrases', true ) ?: [],
            'preferred_phrases' => get_post_meta( $post->ID, '_swps_vp_preferred_phrases', true ) ?: [],
        ];
    }
}
```

**Step 2: Commit**

```bash
git add includes/class-voice-profile.php
git commit -m "feat: Add SWPS_Voice_Profile CPT with prompt compilation"
```

---

## Task 5: Voice Profile — Admin UI

**Files:**
- Create: `templates/voice-profiles-page.php`
- Create: `templates/voice-profile-edit.php`
- Modify: `stratawp-seo.php` (require, instantiate, register menu, AJAX handlers)
- Modify: `includes/class-settings.php` (add voice profile dropdown + menu registration)
- Modify: `admin/js/admin.js` (voice profile CRUD interactions)
- Modify: `admin/css/admin.css` (voice profile form styles)

**Step 1: Create the list page template (`templates/voice-profiles-page.php`)**

```php
<div class="wrap swps-wrap">
    <h1>
        <span class="dashicons dashicons-format-status" style="font-size: 28px; margin-right: 8px;"></span>
        <?php esc_html_e( 'Voice Profiles', 'stratawp-seo' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-voice-profiles&action=new' ) ); ?>" class="page-title-action">
            <?php esc_html_e( 'Add New', 'stratawp-seo' ); ?>
        </a>
    </h1>

    <?php
    $active_id = (int) get_option( 'swps_voice_profile', 0 );
    $profiles  = stratawp_seo()->voice_profile->get_all();
    ?>

    <?php if ( empty( $profiles ) ) : ?>
        <div class="swps-card">
            <p><?php esc_html_e( 'No voice profiles yet. Create one to define a consistent brand voice for your AI-generated content.', 'stratawp-seo' ); ?></p>
        </div>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Tone', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Formality', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Person', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $profiles as $profile ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $profile['name'] ); ?></strong></td>
                    <td><?php echo esc_html( ucfirst( $profile['tone'] ) ); ?></td>
                    <td><?php echo esc_html( $profile['formality'] . '/10' ); ?></td>
                    <td><?php echo esc_html( ucfirst( $profile['person'] ) ); ?></td>
                    <td>
                        <?php if ( $profile['id'] === $active_id ) : ?>
                            <span class="swps-score-badge swps-score-badge--excellent"><?php esc_html_e( 'Active', 'stratawp-seo' ); ?></span>
                        <?php else : ?>
                            <span style="color: #646970;"><?php esc_html_e( 'Inactive', 'stratawp-seo' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-voice-profiles&action=edit&profile_id=' . $profile['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'stratawp-seo' ); ?></a>
                        |
                        <a href="#" class="swps-delete-profile" data-id="<?php echo esc_attr( $profile['id'] ); ?>" style="color: #d63638;"><?php esc_html_e( 'Delete', 'stratawp-seo' ); ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
```

**Step 2: Create the edit/add form template (`templates/voice-profile-edit.php`)**

```php
<?php
$profile_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0;
$is_new     = empty( $profile_id );
$profile    = $is_new ? null : stratawp_seo()->voice_profile->get( $profile_id );

if ( ! $is_new && ! $profile ) {
    echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Voice profile not found.', 'stratawp-seo' ) . '</p></div></div>';
    return;
}
?>
<div class="wrap swps-wrap">
    <h1>
        <?php echo $is_new ? esc_html__( 'Add New Voice Profile', 'stratawp-seo' ) : esc_html__( 'Edit Voice Profile', 'stratawp-seo' ); ?>
    </h1>

    <form id="swps-voice-profile-form" class="swps-card" style="max-width: 700px; padding: 20px;">
        <?php wp_nonce_field( 'swps_nonce', 'nonce' ); ?>
        <input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>" />

        <table class="form-table">
            <tr>
                <th><label for="vp-name"><?php esc_html_e( 'Profile Name', 'stratawp-seo' ); ?></label></th>
                <td><input type="text" id="vp-name" name="name" class="regular-text" required value="<?php echo esc_attr( $profile['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g., Brand Voice, Technical Blog', 'stratawp-seo' ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="vp-tone"><?php esc_html_e( 'Tone', 'stratawp-seo' ); ?></label></th>
                <td>
                    <select id="vp-tone" name="tone">
                        <?php foreach ( [ 'professional', 'casual', 'authoritative', 'friendly', 'formal', 'witty', 'conversational' ] as $t ) : ?>
                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $profile['tone'] ?? 'professional', $t ); ?>><?php echo esc_html( ucfirst( $t ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="vp-formality"><?php esc_html_e( 'Formality', 'stratawp-seo' ); ?></label></th>
                <td>
                    <input type="range" id="vp-formality" name="formality" min="1" max="10" value="<?php echo esc_attr( $profile['formality'] ?? 5 ); ?>" />
                    <span id="vp-formality-label"><?php echo esc_html( $profile['formality'] ?? 5 ); ?>/10</span>
                    <p class="description"><?php esc_html_e( '1 = very casual, 10 = very formal', 'stratawp-seo' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="vp-sentence-length"><?php esc_html_e( 'Sentence Length', 'stratawp-seo' ); ?></label></th>
                <td>
                    <select id="vp-sentence-length" name="sentence_length">
                        <?php foreach ( [ 'short', 'medium', 'long', 'varied' ] as $sl ) : ?>
                            <option value="<?php echo esc_attr( $sl ); ?>" <?php selected( $profile['sentence_length'] ?? 'varied', $sl ); ?>><?php echo esc_html( ucfirst( $sl ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="vp-vocabulary"><?php esc_html_e( 'Vocabulary Level', 'stratawp-seo' ); ?></label></th>
                <td>
                    <select id="vp-vocabulary" name="vocabulary_level">
                        <?php foreach ( [ 'simple', 'moderate', 'advanced', 'technical' ] as $vl ) : ?>
                            <option value="<?php echo esc_attr( $vl ); ?>" <?php selected( $profile['vocabulary_level'] ?? 'moderate', $vl ); ?>><?php echo esc_html( ucfirst( $vl ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="vp-person"><?php esc_html_e( 'Person', 'stratawp-seo' ); ?></label></th>
                <td>
                    <select id="vp-person" name="person">
                        <option value="first" <?php selected( $profile['person'] ?? 'second', 'first' ); ?>><?php esc_html_e( 'First (I/we)', 'stratawp-seo' ); ?></option>
                        <option value="second" <?php selected( $profile['person'] ?? 'second', 'second' ); ?>><?php esc_html_e( 'Second (you)', 'stratawp-seo' ); ?></option>
                        <option value="third" <?php selected( $profile['person'] ?? 'second', 'third' ); ?>><?php esc_html_e( 'Third (they/one)', 'stratawp-seo' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="vp-example"><?php esc_html_e( 'Example Content', 'stratawp-seo' ); ?></label></th>
                <td>
                    <textarea id="vp-example" name="example_content" class="large-text" rows="5" placeholder="<?php esc_attr_e( 'Paste 1-2 paragraphs that represent your ideal writing style...', 'stratawp-seo' ); ?>"><?php echo esc_textarea( $profile['example_content'] ?? '' ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'The AI will use this as a style reference (first 500 characters).', 'stratawp-seo' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="vp-avoid"><?php esc_html_e( 'Avoid Phrases', 'stratawp-seo' ); ?></label></th>
                <td>
                    <textarea id="vp-avoid" name="avoid_phrases" class="large-text" rows="2" placeholder="<?php esc_attr_e( 'game-changer, leverage, synergy, cutting-edge', 'stratawp-seo' ); ?>"><?php echo esc_attr( implode( ', ', $profile['avoid_phrases'] ?? [] ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Comma-separated list of phrases the AI should never use.', 'stratawp-seo' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="vp-prefer"><?php esc_html_e( 'Preferred Phrases', 'stratawp-seo' ); ?></label></th>
                <td>
                    <textarea id="vp-prefer" name="preferred_phrases" class="large-text" rows="2" placeholder="<?php esc_attr_e( 'practical, straightforward, hands-on', 'stratawp-seo' ); ?>"><?php echo esc_attr( implode( ', ', $profile['preferred_phrases'] ?? [] ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Comma-separated list of preferred expressions.', 'stratawp-seo' ); ?></p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary" id="swps-save-profile"><?php echo $is_new ? esc_html__( 'Create Profile', 'stratawp-seo' ) : esc_html__( 'Update Profile', 'stratawp-seo' ); ?></button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-voice-profiles' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'stratawp-seo' ); ?></a>
        </p>
    </form>
</div>
```

**Step 3: Wire up the menu, AJAX handlers, and settings dropdown**

In `includes/class-settings.php`, add a submenu in `register_menu()` after the Generate Content submenu (after line 50):

```php
        add_submenu_page(
            'stratawp-seo',
            __( 'Voice Profiles', 'stratawp-seo' ),
            __( 'Voice Profiles', 'stratawp-seo' ),
            'manage_options',
            'swps-voice-profiles',
            [ $this, 'render_voice_profiles_page' ]
        );
```

Add the render method to `SWPS_Settings` (before the closing `}`):

```php
    /**
     * Render the voice profiles page (list or edit).
     */
    public function render_voice_profiles_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = sanitize_text_field( $_GET['action'] ?? 'list' );

        if ( in_array( $action, [ 'new', 'edit' ], true ) ) {
            include SWPS_PLUGIN_DIR . 'templates/voice-profile-edit.php';
        } else {
            include SWPS_PLUGIN_DIR . 'templates/voice-profiles-page.php';
        }
    }
```

Add the voice profile select field in `register_settings()`, in the Writing Preferences section (after the `tone` field, around line 148):

```php
        $this->add_field( 'voice_profile', __( 'Voice Profile', 'stratawp-seo' ), 'select', 'swps_writing_section', [
            'options'     => stratawp_seo()->voice_profile->get_options(),
            'description' => __( 'Select a voice profile to override tone/style settings. <a href="' . admin_url( 'admin.php?page=swps-voice-profiles' ) . '">Manage profiles</a>', 'stratawp-seo' ),
        ] );
```

In `stratawp-seo.php`, add the require (after the content-scorer require):

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-voice-profile.php';
```

Add the property (after `$content_scorer`):

```php
    public SWPS_Voice_Profile $voice_profile;
```

Instantiate (after `$this->content_scorer` in the constructor):

```php
        $this->voice_profile = new SWPS_Voice_Profile();
```

Add AJAX handlers in the constructor (after the scoring hook):

```php
        // Voice profile AJAX.
        add_action( 'wp_ajax_swps_save_voice_profile', [ $this, 'ajax_save_voice_profile' ] );
        add_action( 'wp_ajax_swps_delete_voice_profile', [ $this, 'ajax_delete_voice_profile' ] );
```

Add the AJAX callback methods to the `StrataWP_SEO` class:

```php
    /**
     * AJAX: Save (create or update) a voice profile.
     */
    public function ajax_save_voice_profile(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $profile_id = absint( $_POST['profile_id'] ?? 0 );
        $name       = sanitize_text_field( $_POST['name'] ?? '' );

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Profile name is required.' ] );
        }

        $meta = [
            'tone'             => sanitize_text_field( $_POST['tone'] ?? 'professional' ),
            'formality'        => absint( $_POST['formality'] ?? 5 ),
            'sentence_length'  => sanitize_text_field( $_POST['sentence_length'] ?? 'varied' ),
            'vocabulary_level' => sanitize_text_field( $_POST['vocabulary_level'] ?? 'moderate' ),
            'person'           => sanitize_text_field( $_POST['person'] ?? 'second' ),
            'example_content'  => sanitize_textarea_field( $_POST['example_content'] ?? '' ),
            'avoid_phrases'    => sanitize_textarea_field( $_POST['avoid_phrases'] ?? '' ),
            'preferred_phrases' => sanitize_textarea_field( $_POST['preferred_phrases'] ?? '' ),
        ];

        if ( $profile_id > 0 ) {
            $result = $this->voice_profile->update( $profile_id, $name, $meta );
        } else {
            $result = $this->voice_profile->create( $name, $meta );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'profile_id' => $result ] );
    }

    /**
     * AJAX: Delete a voice profile.
     */
    public function ajax_delete_voice_profile(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $profile_id = absint( $_POST['profile_id'] ?? 0 );
        $this->voice_profile->delete( $profile_id );

        wp_send_json_success();
    }
```

Add the activation default in `swps_activate()`:

```php
        'voice_profile'      => 0,
```

**Step 4: Add JS for voice profile form and delete**

Append to `admin/js/admin.js` (inside the jQuery ready block):

```javascript
    // Voice Profile form submission.
    $('#swps-voice-profile-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#swps-save-profile').prop('disabled', true).text('Saving...');
        $.post(swpsAdmin.ajax_url, $(this).serialize() + '&action=swps_save_voice_profile', function(res) {
            if (res.success) {
                window.location.href = swpsAdmin.ajax_url.replace('admin-ajax.php', 'admin.php?page=swps-voice-profiles&saved=1');
            } else {
                alert(res.data.message || 'Error saving profile.');
                $btn.prop('disabled', false).text('Save Profile');
            }
        });
    });

    // Voice Profile delete.
    $(document).on('click', '.swps-delete-profile', function(e) {
        e.preventDefault();
        if (!confirm('Delete this voice profile?')) return;
        var id = $(this).data('id');
        $.post(swpsAdmin.ajax_url, { action: 'swps_delete_voice_profile', nonce: swpsAdmin.nonce, profile_id: id }, function(res) {
            if (res.success) location.reload();
        });
    });

    // Formality range slider label.
    $('#vp-formality').on('input', function() {
        $('#vp-formality-label').text(this.value + '/10');
    });
```

**Step 5: Add voice profile page to admin asset enqueue**

In `stratawp-seo.php`, update the `enqueue_admin_assets` method hook check (line 201) to also include the voice profiles page:

Change:
```php
        if ( ! str_contains( $hook, 'stratawp-seo' ) && ! str_contains( $hook, 'swps-generate' ) ) {
```
To:
```php
        if ( ! str_contains( $hook, 'stratawp-seo' ) && ! str_contains( $hook, 'swps-generate' ) && ! str_contains( $hook, 'swps-voice-profiles' ) ) {
```

**Step 6: Commit**

```bash
git add templates/voice-profiles-page.php templates/voice-profile-edit.php includes/class-settings.php stratawp-seo.php admin/js/admin.js
git commit -m "feat: Add voice profile admin UI with CRUD and settings integration"
```

---

## Task 6: Image Inserter — Core Class

**Files:**
- Create: `includes/class-image-inserter.php`

**Step 1: Create the image inserter class**

```php
<?php
/**
 * In-content image insertion.
 *
 * Inserts contextual images at semantic positions within generated post content.
 * Uses the configured image provider to source images.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Image_Inserter {

    private SWPS_Image_Provider $image_provider;

    public function __construct( SWPS_Image_Provider $image_provider ) {
        $this->image_provider = $image_provider;
    }

    /**
     * Insert images into a post's content at semantic positions.
     *
     * @param int   $post_id   The post ID.
     * @param array $ai_result The AI response data.
     */
    public function insert_images( int $post_id, array $ai_result ): void {
        if ( ! get_option( 'swps_insert_content_images', 0 ) ) {
            return;
        }

        $content   = get_post_field( 'post_content', $post_id );
        $max_images = (int) get_option( 'swps_content_images_count', 2 );
        $max_width  = (int) get_option( 'swps_image_max_width', 1200 );

        if ( empty( $content ) || $max_images < 1 ) {
            return;
        }

        // Split content into sections by H2.
        $sections = $this->split_by_headings( $content );

        if ( count( $sections ) < 2 ) {
            return; // Not enough sections for in-content images.
        }

        // Skip the first section (intro — featured image covers it).
        $target_sections = array_slice( $sections, 1 );

        // Pick evenly spaced sections for images.
        $total    = count( $target_sections );
        $to_fill  = min( $max_images, $total );
        $interval = max( 1, (int) floor( $total / $to_fill ) );
        $indices  = [];
        for ( $i = 0; $i < $to_fill; $i++ ) {
            $indices[] = $i * $interval;
        }

        // Extract visual concepts for each target section.
        $queries = [];
        foreach ( $indices as $idx ) {
            $section = $target_sections[ $idx ];
            $queries[ $idx ] = $this->extract_visual_concept( $section );
        }

        $queries = apply_filters( 'swps_content_images_queries', $queries, $post_id );

        // Search and insert images.
        $inserted = 0;
        foreach ( $queries as $idx => $query ) {
            if ( empty( $query ) ) {
                continue;
            }

            $image_url = $this->search_image( $query );
            if ( empty( $image_url ) ) {
                continue;
            }

            // Apply image selection filter.
            $section_heading = $this->extract_heading( $target_sections[ $idx ] );
            $image_data = apply_filters( 'swps_image_selection', [
                'url'     => $image_url,
                'query'   => $query,
                'alt'     => $section_heading ?: $query,
                'post_id' => $post_id,
            ], $post_id, $section_heading );

            if ( empty( $image_data ) || empty( $image_data['url'] ) ) {
                continue;
            }

            // Download and attach.
            $attachment_id = $this->download_image( $image_data['url'], $post_id, $query, $max_width );
            if ( is_wp_error( $attachment_id ) ) {
                continue;
            }

            // Set alt text.
            $alt_text = sanitize_text_field( $image_data['alt'] ?? $query );
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

            // Build the figure HTML.
            $img_src  = wp_get_attachment_url( $attachment_id );
            $metadata = wp_get_attachment_metadata( $attachment_id );
            $width    = $metadata['width'] ?? '';
            $height   = $metadata['height'] ?? '';

            $figure_html = sprintf(
                '<figure class="swps-content-image"><img src="%s" alt="%s" loading="lazy"%s%s /><figcaption>%s</figcaption></figure>',
                esc_url( $img_src ),
                esc_attr( $alt_text ),
                $width ? ' width="' . esc_attr( $width ) . '"' : '',
                $height ? ' height="' . esc_attr( $height ) . '"' : '',
                esc_html( $alt_text )
            );

            // Inject into the section (after first paragraph).
            // The actual index in the full content is $idx + 1 (because we skipped section 0).
            $section_index = $idx + 1;
            $content = $this->inject_into_section( $content, $section_index, $figure_html );

            $inserted++;

            do_action( 'swps_image_inserted', $attachment_id, $post_id, $alt_text, $section_index );

            if ( $inserted >= $max_images ) {
                break;
            }
        }

        // Update the post content if images were inserted.
        if ( $inserted > 0 ) {
            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $content,
            ] );
        }
    }

    /**
     * Split content into sections by H2 tags.
     *
     * @param string $content HTML content.
     * @return array Array of section HTML strings.
     */
    private function split_by_headings( string $content ): array {
        // Split on <h2 but keep the delimiter.
        $parts = preg_split( '/(?=<h2[\s>])/i', $content );
        return array_values( array_filter( $parts, fn( $p ) => trim( $p ) !== '' ) );
    }

    /**
     * Extract a 2-4 word visual concept from a section.
     *
     * @param string $section HTML of a content section.
     * @return string Search query.
     */
    private function extract_visual_concept( string $section ): string {
        // Get heading text.
        $heading = $this->extract_heading( $section );

        // Get first paragraph text.
        $first_para = '';
        if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $section, $match ) ) {
            $first_para = wp_strip_all_tags( $match[1] );
        }

        $text = $heading . ' ' . $first_para;
        $text = strtolower( wp_strip_all_tags( $text ) );

        // Remove stop words.
        $stop_words = [ 'how', 'to', 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'what', 'why', 'when', 'where', 'which', 'who', 'your', 'our', 'their', 'this', 'that', 'for', 'and', 'but', 'with', 'from', 'about', 'into', 'best', 'top', 'guide', 'you', 'can', 'will', 'also', 'more', 'most', 'many', 'some', 'than', 'them', 'then', 'these', 'those', 'have', 'has', 'had', 'not', 'been', 'being', 'does', 'did', 'should', 'would', 'could', 'its', 'it', 'of', 'in', 'on', 'at', 'by' ];

        $words = preg_split( '/\W+/', $text );
        $words = array_filter( $words, function ( $w ) use ( $stop_words ) {
            return strlen( $w ) > 2 && ! in_array( $w, $stop_words, true ) && ! is_numeric( $w );
        } );

        return implode( ' ', array_slice( array_values( $words ), 0, 4 ) );
    }

    /**
     * Extract heading text from a section.
     */
    private function extract_heading( string $section ): string {
        if ( preg_match( '/<h[2-3][^>]*>(.*?)<\/h[2-3]>/i', $section, $match ) ) {
            return wp_strip_all_tags( $match[1] );
        }
        return '';
    }

    /**
     * Search for an image using the configured provider.
     *
     * @param string $query Search query.
     * @return string Image URL or empty string.
     */
    private function search_image( string $query ): string {
        // Use the provider's simplify_query for cleaner search terms.
        // We call the provider's search method directly if available,
        // otherwise fall back to the featured image flow.
        // Since image providers only expose set_featured_image(), we need
        // to access the underlying search. For now, we replicate the search logic.
        $provider_slug = get_option( 'swps_image_provider', 'unsplash' );
        $api_key       = $this->image_provider->get_api_key();

        if ( empty( $api_key ) && $this->image_provider->requires_api_key() ) {
            return '';
        }

        $simplified = $this->simplify_query( $query );

        return match ( $provider_slug ) {
            'unsplash' => $this->search_unsplash( $simplified, $api_key ),
            'pexels'   => $this->search_pexels( $simplified, $api_key ),
            'pixabay'  => $this->search_pixabay( $simplified, $api_key ),
            default    => '',
        };
    }

    /**
     * Search Unsplash for a single image URL.
     */
    private function search_unsplash( string $query, string $api_key ): string {
        $response = wp_remote_get( 'https://api.unsplash.com/search/photos?' . http_build_query( [
            'query'    => $query,
            'per_page' => 1,
            'orientation' => 'landscape',
        ] ), [
            'headers' => [ 'Authorization' => 'Client-ID ' . $api_key ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['results'][0]['urls']['regular'] ?? '';
    }

    /**
     * Search Pexels for a single image URL.
     */
    private function search_pexels( string $query, string $api_key ): string {
        $response = wp_remote_get( 'https://api.pexels.com/v1/search?' . http_build_query( [
            'query'    => $query,
            'per_page' => 1,
            'orientation' => 'landscape',
        ] ), [
            'headers' => [ 'Authorization' => $api_key ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['photos'][0]['src']['large'] ?? '';
    }

    /**
     * Search Pixabay for a single image URL.
     */
    private function search_pixabay( string $query, string $api_key ): string {
        $response = wp_remote_get( 'https://pixabay.com/api/?' . http_build_query( [
            'key'         => $api_key,
            'q'           => $query,
            'per_page'    => 3,
            'image_type'  => 'photo',
            'orientation' => 'horizontal',
        ] ), [
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['hits'][0]['largeImageURL'] ?? '';
    }

    /**
     * Simplify a query by removing stop words.
     */
    private function simplify_query( string $query ): string {
        $stop = [ 'how', 'to', 'the', 'a', 'an', 'is', 'are', 'for', 'and', 'with', 'from', 'about', 'your' ];
        $words = explode( ' ', strtolower( $query ) );
        $words = array_filter( $words, fn( $w ) => ! in_array( $w, $stop, true ) && strlen( $w ) > 2 );
        return implode( ' ', array_slice( array_values( $words ), 0, 4 ) );
    }

    /**
     * Download an image, convert to WebP, and attach to the post.
     *
     * @param string $url      Image URL.
     * @param int    $post_id  Post to attach to.
     * @param string $query    The search query (used for filename).
     * @param int    $max_width Max image width.
     * @return int|WP_Error Attachment ID or error.
     */
    private function download_image( string $url, int $post_id, string $query, int $max_width ): int|WP_Error {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        // Resize if needed.
        $this->resize_image( $tmp, $max_width );

        // Convert to WebP.
        $webp = $this->convert_to_webp( $tmp );
        if ( $webp !== $tmp ) {
            @unlink( $tmp );
            $tmp = $webp;
            $ext = '.webp';
        } else {
            $ext = '.jpg';
        }

        $file_array = [
            'name'     => sanitize_title( $query ) . '-content' . $ext,
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
        }

        return $attachment_id;
    }

    /**
     * Resize an image file to max width.
     */
    private function resize_image( string $file_path, int $max_width ): void {
        if ( ! function_exists( 'imagecreatefromstring' ) ) {
            return;
        }

        $data = file_get_contents( $file_path );
        if ( false === $data ) {
            return;
        }

        $image = @imagecreatefromstring( $data );
        if ( false === $image ) {
            return;
        }

        $orig_width  = imagesx( $image );
        $orig_height = imagesy( $image );

        if ( $orig_width <= $max_width ) {
            imagedestroy( $image );
            return;
        }

        $ratio     = $max_width / $orig_width;
        $new_height = (int) ( $orig_height * $ratio );

        $resized = imagecreatetruecolor( $max_width, $new_height );
        imagealphablending( $resized, false );
        imagesavealpha( $resized, true );
        imagecopyresampled( $resized, $image, 0, 0, 0, 0, $max_width, $new_height, $orig_width, $orig_height );

        imagejpeg( $resized, $file_path, 85 );
        imagedestroy( $image );
        imagedestroy( $resized );
    }

    /**
     * Convert an image to WebP.
     */
    private function convert_to_webp( string $file_path ): string {
        if ( ! function_exists( 'imagewebp' ) ) {
            return $file_path;
        }

        $data = file_get_contents( $file_path );
        if ( false === $data ) {
            return $file_path;
        }

        $image = @imagecreatefromstring( $data );
        if ( false === $image ) {
            return $file_path;
        }

        imagepalettetotruecolor( $image );
        imagealphablending( $image, true );
        imagesavealpha( $image, true );

        $webp_path = $file_path . '.webp';

        if ( ! imagewebp( $image, $webp_path, 85 ) ) {
            imagedestroy( $image );
            return $file_path;
        }

        imagedestroy( $image );
        return $webp_path;
    }

    /**
     * Inject an HTML block after the first paragraph of a specific section.
     *
     * @param string $content       Full post content.
     * @param int    $section_index 0-based section index (split by H2).
     * @param string $html          HTML to inject.
     * @return string Modified content.
     */
    private function inject_into_section( string $content, int $section_index, string $html ): string {
        $sections = $this->split_by_headings( $content );

        if ( ! isset( $sections[ $section_index ] ) ) {
            return $content;
        }

        $section = $sections[ $section_index ];

        // Find first closing </p> in this section and insert after it.
        $p_end = strpos( $section, '</p>' );
        if ( $p_end === false ) {
            // No paragraph — append to end of section.
            $sections[ $section_index ] = $section . "\n" . $html . "\n";
        } else {
            $insert_pos = $p_end + 4; // after </p>
            $sections[ $section_index ] = substr( $section, 0, $insert_pos ) . "\n" . $html . "\n" . substr( $section, $insert_pos );
        }

        return implode( '', $sections );
    }
}
```

**Step 2: Commit**

```bash
git add includes/class-image-inserter.php
git commit -m "feat: Add SWPS_Image_Inserter for in-content image placement"
```

---

## Task 7: Image Inserter — Integration & Settings

**Files:**
- Modify: `stratawp-seo.php` (require, instantiate, hook)
- Modify: `includes/class-settings.php` (add image insertion settings)
- Modify: `includes/class-hooks.php` (add new hook helpers)
- Modify: `admin/css/admin.css` (content image styles)
- Modify: `css/frontend.css` (content image frontend styles)

**Step 1: Add hook helpers to SWPS_Hooks**

Add to `includes/class-hooks.php` (before closing `}`):

```php
    /**
     * Apply the content images queries filter.
     *
     * @param array $queries Visual concept queries keyed by section index.
     * @param int   $post_id Post ID.
     * @return array Filtered queries.
     */
    public static function filter_content_images_queries( array $queries, int $post_id ): array {
        return apply_filters( 'swps_content_images_queries', $queries, $post_id );
    }

    /**
     * Apply the image selection filter.
     *
     * @param array  $image_data     Image data array.
     * @param int    $post_id        Post ID.
     * @param string $section_heading Section heading text.
     * @return array Filtered image data.
     */
    public static function filter_image_selection( array $image_data, int $post_id, string $section_heading ): array {
        return apply_filters( 'swps_image_selection', $image_data, $post_id, $section_heading );
    }

    /**
     * Fire the image_inserted action.
     *
     * @param int    $attachment_id Attachment ID.
     * @param int    $post_id       Post ID.
     * @param string $alt_text      Image alt text.
     * @param int    $position      Section index where image was inserted.
     */
    public static function do_image_inserted( int $attachment_id, int $post_id, string $alt_text, int $position ): void {
        do_action( 'swps_image_inserted', $attachment_id, $post_id, $alt_text, $position );
    }
```

**Step 2: Wire up in stratawp-seo.php**

Add require (after voice-profile require):

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-image-inserter.php';
```

Add property (after `$voice_profile`):

```php
    public SWPS_Image_Inserter $image_inserter;
```

Instantiate (after `$this->voice_profile` in constructor):

```php
        $this->image_inserter = new SWPS_Image_Inserter( $this->images );
```

Add the hook (after the scoring hook):

```php
        // In-content image insertion on post creation.
        add_action( 'swps_post_created', [ $this, 'insert_content_images' ], 20, 3 );
```

Add the callback (in the `StrataWP_SEO` class):

```php
    /**
     * Insert contextual images into generated post content.
     *
     * @param int   $post_id   The new post ID.
     * @param array $ai_result The AI response data.
     * @param array $post_data The WordPress post data.
     */
    public function insert_content_images( int $post_id, array $ai_result, array $post_data ): void {
        $this->image_inserter->insert_images( $post_id, $ai_result );
    }
```

**Step 3: Add settings fields**

In `includes/class-settings.php`, in the Images section (after the DALL-E API key field, around line 118), add:

```php
        $this->add_field( 'insert_content_images', __( 'In-Content Images', 'stratawp-seo' ), 'checkbox', 'swps_images_section', [
            'label' => __( 'Insert contextual images within the post body (in addition to featured image)', 'stratawp-seo' ),
        ] );

        $this->add_field( 'content_images_count', __( 'Images Per Post', 'stratawp-seo' ), 'number', 'swps_images_section', [
            'min'         => 1,
            'max'         => 4,
            'description' => __( 'Maximum number of in-content images to insert (1-4).', 'stratawp-seo' ),
        ] );

        $this->add_field( 'image_max_width', __( 'Image Max Width', 'stratawp-seo' ), 'number', 'swps_images_section', [
            'min'         => 600,
            'max'         => 2400,
            'step'        => 100,
            'description' => __( 'Maximum width in pixels for content images.', 'stratawp-seo' ),
        ] );
```

Add activation defaults in `swps_activate()`:

```php
        'insert_content_images' => 0,
        'content_images_count'  => 2,
        'image_max_width'       => 1200,
```

**Step 4: Add frontend CSS for content images**

Append to `css/frontend.css`:

```css
/* In-Content Images */
.swps-content-image {
    margin: 24px 0;
    text-align: center;
}
.swps-content-image img {
    max-width: 100%;
    height: auto;
    border-radius: 4px;
}
.swps-content-image figcaption {
    margin-top: 8px;
    font-size: 0.875em;
    color: #666;
    font-style: italic;
}
```

**Step 5: Commit**

```bash
git add includes/class-image-inserter.php includes/class-hooks.php includes/class-settings.php stratawp-seo.php css/frontend.css
git commit -m "feat: Integrate image inserter with settings, hooks, and frontend styles"
```

---

## Task 8: REST API — Score & Voice Profile Endpoints

**Files:**
- Modify: `includes/class-rest-api.php`

**Step 1: Add the new endpoints**

In `includes/class-rest-api.php`, add the following route registrations inside `register_routes()` (after the queue DELETE route, around line 94):

```php
        // Score endpoints.
        register_rest_route( self::NAMESPACE, '/score/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_score' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'score_post' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                ],
            ],
        ] );

        // Voice profile endpoints.
        register_rest_route( self::NAMESPACE, '/voice-profiles', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_voice_profiles' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_voice_profile' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'name' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                    'tone' => [ 'type' => 'string', 'default' => 'professional', 'sanitize_callback' => 'sanitize_text_field' ],
                    'formality' => [ 'type' => 'integer', 'default' => 5, 'sanitize_callback' => 'absint' ],
                    'sentence_length' => [ 'type' => 'string', 'default' => 'varied', 'sanitize_callback' => 'sanitize_text_field' ],
                    'vocabulary_level' => [ 'type' => 'string', 'default' => 'moderate', 'sanitize_callback' => 'sanitize_text_field' ],
                    'person' => [ 'type' => 'string', 'default' => 'second', 'sanitize_callback' => 'sanitize_text_field' ],
                    'example_content' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
                    'avoid_phrases' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
                    'preferred_phrases' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/voice-profiles/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_voice_profile' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                    'name' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_voice_profile' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                ],
            ],
        ] );
```

**Step 2: Add the callback methods**

Add these methods to the `SWPS_REST_API` class (before the closing `}`):

```php
    /**
     * GET /score/{id} - Get stored score for a post.
     */
    public function get_score( WP_REST_Request $request ): WP_REST_Response {
        $post_id = $request->get_param( 'id' );

        $score   = get_post_meta( $post_id, '_swps_content_score', true );
        $details = get_post_meta( $post_id, '_swps_score_details', true );
        $recs    = get_post_meta( $post_id, '_swps_score_recommendations', true );

        if ( $score === '' ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'No score found for this post.',
            ], 404 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'overall_score'   => (int) $score,
                'details'         => $details ?: [],
                'recommendations' => $recs ?: [],
            ],
        ] );
    }

    /**
     * POST /score/{id} - Score an existing post.
     */
    public function score_post( WP_REST_Request $request ): WP_REST_Response {
        $post_id = $request->get_param( 'id' );
        $post    = get_post( $post_id );

        if ( ! $post ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Post not found.',
            ], 404 );
        }

        // Build a minimal ai_result from the existing post.
        $ai_result = [
            'title'              => $post->post_title,
            'content_html'       => $post->post_content,
            'meta_description'   => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
                                 ?: get_post_meta( $post_id, 'rank_math_description', true )
                                 ?: '',
            'focus_keyword'      => get_post_meta( $post_id, '_swps_focus_keyword', true )
                                 ?: get_post_meta( $post_id, '_yoast_wpseo_focuskw', true )
                                 ?: get_post_meta( $post_id, 'rank_math_focus_keyword', true )
                                 ?: '',
            'secondary_keywords' => get_post_meta( $post_id, '_swps_secondary_keywords', true ) ?: [],
            'internal_links_used' => get_post_meta( $post_id, '_swps_internal_links', true ) ?: [],
            'estimated_word_count' => str_word_count( wp_strip_all_tags( $post->post_content ) ),
        ];

        $scorer  = stratawp_seo()->content_scorer;
        $results = $scorer->score( $post_id, $ai_result );

        update_post_meta( $post_id, '_swps_content_score', $results['overall_score'] );
        update_post_meta( $post_id, '_swps_score_details', $results['details'] );
        update_post_meta( $post_id, '_swps_score_recommendations', $results['recommendations'] );

        SWPS_Hooks::do_score_complete( $results, $post_id );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $results,
        ] );
    }

    /**
     * GET /voice-profiles - List all voice profiles.
     */
    public function get_voice_profiles(): WP_REST_Response {
        $profiles = stratawp_seo()->voice_profile->get_all();

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $profiles,
            'count'   => count( $profiles ),
        ] );
    }

    /**
     * POST /voice-profiles - Create a voice profile.
     */
    public function create_voice_profile( WP_REST_Request $request ): WP_REST_Response {
        $vp     = stratawp_seo()->voice_profile;
        $result = $vp->create( $request->get_param( 'name' ), $request->get_params() );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 500 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $vp->get( $result ),
        ], 201 );
    }

    /**
     * PUT /voice-profiles/{id} - Update a voice profile.
     */
    public function update_voice_profile( WP_REST_Request $request ): WP_REST_Response {
        $vp     = stratawp_seo()->voice_profile;
        $result = $vp->update( $request->get_param( 'id' ), $request->get_param( 'name' ), $request->get_params() );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 500 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $vp->get( $result ),
        ] );
    }

    /**
     * DELETE /voice-profiles/{id} - Delete a voice profile.
     */
    public function delete_voice_profile( WP_REST_Request $request ): WP_REST_Response {
        $vp      = stratawp_seo()->voice_profile;
        $success = $vp->delete( $request->get_param( 'id' ) );

        return new WP_REST_Response( [
            'success' => $success,
        ] );
    }
```

**Step 3: Commit**

```bash
git add includes/class-rest-api.php
git commit -m "feat: Add REST endpoints for content scoring and voice profiles"
```

---

## Task 9: Final Integration & Polish

**Files:**
- Modify: `admin/js/admin.js` (score display in generation results)
- Modify: `templates/generate-page.php` (if score needs display)

**Step 1: Read the generate page template and admin JS to find exact injection points**

Read `templates/generate-page.php` and `admin/js/admin.js` to find where generation results are rendered (the success callback). Add the `swpsScoreBadge()` call to display the score alongside title, edit_url, etc.

**Step 2: Add score display to the generation result**

In the JS success handler where the result card is built, add after the title/link:

```javascript
(data.content_score !== null ? ' ' + swpsScoreBadge(data.content_score) : '')
```

Also add a "score blocked" notice if applicable (post was forced to draft):

```javascript
if (data.status === 'draft' && data.content_score && data.content_score < parseInt(swpsAdmin.min_content_score || 0)) {
    // Show notice that score was below threshold
}
```

Add `min_content_score` to the localized script data in `stratawp-seo.php`'s `enqueue_admin_assets()`:

```php
            'min_content_score'   => get_option( 'swps_min_content_score', 0 ),
```

**Step 3: Commit**

```bash
git add admin/js/admin.js stratawp-seo.php
git commit -m "feat: Display content score in generation results UI"
```

---

## Summary of All Files

### New Files (5)
- `includes/class-content-scorer.php` — Content scoring pipeline
- `includes/class-voice-profile.php` — Voice profile CPT + prompt compilation
- `includes/class-image-inserter.php` — In-content image insertion
- `templates/voice-profiles-page.php` — Voice profile list admin page
- `templates/voice-profile-edit.php` — Voice profile add/edit form

### Modified Files (8)
- `stratawp-seo.php` — Require new classes, properties, hooks, AJAX handlers
- `includes/class-generator.php` — Add content_score to return array
- `includes/class-hooks.php` — Add 5 new hook helper methods
- `includes/class-settings.php` — Add voice profile dropdown, image settings, min score setting, voice profiles menu
- `includes/class-rest-api.php` — Add score and voice-profile endpoints
- `admin/js/admin.js` — Score badge, voice profile CRUD, formality slider
- `admin/css/admin.css` — Score badge styles
- `css/frontend.css` — Content image styles

### Total Commits: 9
