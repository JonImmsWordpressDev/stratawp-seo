# Post List SEO Column Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a colored SEO score circle with hover tooltip to the WordPress posts list table, driven by a 14-check scoring system.

**Architecture:** Extend `SWPS_Content_Scorer` with a `score_post()` method for the 14 checks. New `SWPS_Post_List_SEO` class handles column registration, rendering, and AJAX refresh. Scores cached as post meta, invalidated on save, recalculated only via AJAX.

**Tech Stack:** WordPress Plugin API (columns, AJAX, settings), PHP 8.0, vanilla JS

**Spec:** `docs/superpowers/specs/2026-03-22-post-list-seo-column-design.md`

---

## Chunk 1: Content Scorer Extension

### Task 1: Add `score_post()` method to Content Scorer

**Files:**
- Modify: `includes/class-content-scorer.php` (add method after line 631, before closing `}`)

- [ ] **Step 1: Add the `score_post()` public method**

Add this method at the end of the `SWPS_Content_Scorer` class (before the closing `}`):

```php
/**
 * Score an existing post's SEO quality across 14 checks.
 *
 * Unlike score() which evaluates AI-generated content at creation time,
 * this method evaluates any published/drafted post against SEO best practices.
 *
 * @param int $post_id The WordPress post ID.
 * @return array {
 *     @type int    $score   Overall score 0-100.
 *     @type string $status  'good' | 'needs_work' | 'poor' | 'no_keyword'
 *     @type array  $checks  Keyed array of check results.
 * }
 */
public function score_post( int $post_id ): array {
    $post = get_post( $post_id );

    if ( ! $post ) {
        return [
            'score'  => 0,
            'status' => 'poor',
            'checks' => [],
        ];
    }

    $focus_keyword   = get_post_meta( $post_id, '_swps_focus_keyword', true );
    $meta_title      = get_post_meta( $post_id, '_swps_meta_title', true );
    $meta_desc       = get_post_meta( $post_id, '_swps_meta_description', true );
    $social_image    = get_post_meta( $post_id, '_swps_social_image', true );
    $content_html    = $post->post_content;
    $plain_text      = wp_strip_all_tags( $content_html );
    $keyword_lower   = mb_strtolower( trim( $focus_keyword ) );
    $min_words       = (int) get_option( 'swps_seo_score_content_min', 300 );

    // Check 1: Focus keyword set.
    $has_keyword = ! empty( $keyword_lower );
    $checks = [];

    $checks['focus_keyword_set'] = [
        'pass'  => $has_keyword,
        'label' => __( 'Focus keyword set', 'stratawp-seo' ),
    ];

    if ( ! $has_keyword ) {
        // Cannot evaluate keyword-dependent checks without a keyword.
        $result = [
            'score'  => 0,
            'status' => 'no_keyword',
            'checks' => $checks,
        ];
        update_post_meta( $post_id, '_swps_seo_score', $result );
        update_post_meta( $post_id, '_swps_seo_score_value', 0 );
        return $result;
    }

    // Check 2: Keyword in meta title.
    $checks['keyword_in_title'] = [
        'pass'  => ! empty( $meta_title ) && false !== mb_strpos( mb_strtolower( $meta_title ), $keyword_lower ),
        'label' => __( 'Keyword in meta title', 'stratawp-seo' ),
    ];

    // Check 3: Keyword in meta description.
    $checks['keyword_in_desc'] = [
        'pass'  => ! empty( $meta_desc ) && false !== mb_strpos( mb_strtolower( $meta_desc ), $keyword_lower ),
        'label' => __( 'Keyword in meta description', 'stratawp-seo' ),
    ];

    // Check 4: Title length (50-60 chars).
    $title_len = mb_strlen( $meta_title );
    $checks['title_length'] = [
        'pass'  => $title_len >= 50 && $title_len <= 60,
        'label' => __( 'Meta title length (50-60 chars)', 'stratawp-seo' ),
        'value' => sprintf( '%d chars', $title_len ),
    ];

    // Check 5: Description length (150-160 chars).
    $desc_len = mb_strlen( $meta_desc );
    $checks['desc_length'] = [
        'pass'  => $desc_len >= 150 && $desc_len <= 160,
        'label' => __( 'Meta description length (150-160 chars)', 'stratawp-seo' ),
        'value' => sprintf( '%d chars', $desc_len ),
    ];

    // Check 6: Keyword in first paragraph.
    $first_para_pass = false;
    if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content_html, $p_match ) ) {
        $first_para_text = mb_strtolower( wp_strip_all_tags( $p_match[1] ) );
        $first_para_pass = false !== mb_strpos( $first_para_text, $keyword_lower );
    }
    $checks['keyword_first_para'] = [
        'pass'  => $first_para_pass,
        'label' => __( 'Keyword in first paragraph', 'stratawp-seo' ),
    ];

    // Check 7: Keyword in at least one H2.
    $h2_pass = false;
    if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $content_html, $h2_matches ) ) {
        foreach ( $h2_matches[1] as $h2_text ) {
            if ( false !== mb_strpos( mb_strtolower( wp_strip_all_tags( $h2_text ) ), $keyword_lower ) ) {
                $h2_pass = true;
                break;
            }
        }
    }
    $checks['keyword_in_h2'] = [
        'pass'  => $h2_pass,
        'label' => __( 'Keyword in an H2 heading', 'stratawp-seo' ),
    ];

    // Check 8: Content length.
    $word_count = str_word_count( $plain_text );
    $checks['content_length'] = [
        'pass'  => $word_count >= $min_words,
        'label' => sprintf( __( 'Content length (min %d words)', 'stratawp-seo' ), $min_words ),
        'value' => sprintf( '%s words', number_format_i18n( $word_count ) ),
    ];

    // Check 9: Internal links present.
    $site_url        = home_url();
    $has_internal     = false;
    $has_external     = false;
    $has_alt_keyword  = false;

    if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>/is', $content_html, $link_matches ) ) {
        foreach ( $link_matches[1] as $href ) {
            $href = trim( $href );
            if ( empty( $href ) || '#' === $href[0] || 0 === strpos( $href, 'mailto:' ) ) {
                continue;
            }
            $is_external = preg_match( '/^https?:\/\//i', $href ) && 0 !== mb_strpos( $href, $site_url );
            if ( $is_external ) {
                $has_external = true;
            } else {
                $has_internal = true;
            }
        }
    }

    $checks['internal_links'] = [
        'pass'  => $has_internal,
        'label' => __( 'Internal link present', 'stratawp-seo' ),
    ];

    // Check 10: External links present.
    $checks['external_links'] = [
        'pass'  => $has_external,
        'label' => __( 'External link present', 'stratawp-seo' ),
    ];

    // Check 11: Image alt text contains keyword.
    if ( preg_match_all( '/<img\s[^>]*alt=["\']([^"\']*)["\'][^>]*>/is', $content_html, $img_matches ) ) {
        foreach ( $img_matches[1] as $alt ) {
            if ( false !== mb_strpos( mb_strtolower( $alt ), $keyword_lower ) ) {
                $has_alt_keyword = true;
                break;
            }
        }
    }
    $checks['image_alt_keyword'] = [
        'pass'  => $has_alt_keyword,
        'label' => __( 'Image alt text contains keyword', 'stratawp-seo' ),
    ];

    // Check 12: Slug contains keyword.
    $slug_words   = explode( ' ', $keyword_lower );
    $slug         = $post->post_name;
    $slug_pass    = true;
    foreach ( $slug_words as $word ) {
        $word = trim( $word );
        if ( ! empty( $word ) && false === mb_strpos( $slug, sanitize_title( $word ) ) ) {
            $slug_pass = false;
            break;
        }
    }
    $checks['slug_keyword'] = [
        'pass'  => $slug_pass,
        'label' => __( 'Slug contains keyword', 'stratawp-seo' ),
    ];

    // Check 13: OG image set.
    $checks['og_image_set'] = [
        'pass'  => ! empty( $social_image ),
        'label' => __( 'OG image is set', 'stratawp-seo' ),
    ];

    // Check 14: Readability (Flesch-Kincaid grade 6-10).
    $readability_pass = false;
    $grade_value      = '';
    if ( str_word_count( $plain_text ) >= 10 ) {
        $recs = [];
        $readability_score = $this->analyze_readability( $plain_text, $recs );
        // Score of 70+ means grade level is roughly in range.
        // Recalculate grade directly for accurate pass/fail.
        $sentences      = preg_split( '/[.!?]+/', $plain_text, -1, PREG_SPLIT_NO_EMPTY );
        $sentence_count = max( count( $sentences ), 1 );
        $wc             = str_word_count( $plain_text );
        $syllables      = $this->count_syllables( $plain_text );
        $grade          = max( 0, ( 0.39 * ( $wc / $sentence_count ) ) + ( 11.8 * ( $syllables / $wc ) ) - 15.59 );
        $readability_pass = ( $grade >= 6 && $grade <= 10 );
        $grade_value      = sprintf( 'Grade %.0f', $grade );
    }
    $checks['readability'] = [
        'pass'  => $readability_pass,
        'label' => __( 'Readability score OK', 'stratawp-seo' ),
        'value' => $grade_value,
    ];

    // Calculate score: each check worth equal weight.
    $passed = 0;
    foreach ( $checks as $check ) {
        if ( $check['pass'] ) {
            $passed++;
        }
    }
    $total_checks = count( $checks );
    $score        = ( $total_checks > 0 ) ? (int) round( ( $passed / $total_checks ) * 100 ) : 0;

    // Determine status.
    if ( $score >= 80 ) {
        $status = 'good';
    } elseif ( $score >= 50 ) {
        $status = 'needs_work';
    } else {
        $status = 'poor';
    }

    $result = [
        'score'  => $score,
        'status' => $status,
        'checks' => $checks,
    ];

    // Cache the result.
    update_post_meta( $post_id, '_swps_seo_score', $result );
    update_post_meta( $post_id, '_swps_seo_score_value', $score );

    return $result;
}
```

- [ ] **Step 2: Verify the method is syntactically correct**

Run: `php -l includes/class-content-scorer.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/class-content-scorer.php
git commit -m "feat: add score_post() method for 14-check SEO analysis"
```

---

## Chunk 2: Post List SEO Column Class

### Task 2: Create the `SWPS_Post_List_SEO` class

**Files:**
- Create: `includes/class-post-list-seo.php`

- [ ] **Step 1: Create the new class file**

```php
<?php
/**
 * SEO score column for the WordPress posts list table.
 *
 * Adds a colored circle with hover tooltip showing a 14-item
 * SEO checklist. Scores are cached as post meta and refreshed via AJAX.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Post_List_SEO {

	private SWPS_Content_Scorer $scorer;

	public function __construct( SWPS_Content_Scorer $scorer ) {
		$this->scorer = $scorer;

		$enabled_types = (array) get_option( 'swps_seo_column_post_types', [ 'post', 'page' ] );

		foreach ( $enabled_types as $post_type ) {
			// Column header hooks differ for built-in vs custom post types.
			if ( 'post' === $post_type ) {
				add_filter( 'manage_posts_columns', [ $this, 'add_column' ] );
				add_action( 'manage_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
			} elseif ( 'page' === $post_type ) {
				add_filter( 'manage_pages_columns', [ $this, 'add_column' ] );
				add_action( 'manage_pages_custom_column', [ $this, 'render_column' ], 10, 2 );
			} else {
				add_filter( "manage_{$post_type}_posts_columns", [ $this, 'add_column' ] );
				add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
			}

			add_filter( "manage_edit-{$post_type}_sortable_columns", [ $this, 'sortable_column' ] );
		}

		add_action( 'pre_get_posts', [ $this, 'handle_orderby' ] );
		add_action( 'save_post', [ $this, 'invalidate_score' ] );
		add_action( 'wp_ajax_swps_refresh_seo_score', [ $this, 'ajax_refresh' ] );
		add_action( 'wp_ajax_swps_bulk_refresh_seo_scores', [ $this, 'ajax_bulk_refresh' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add the SEO column header.
	 */
	public function add_column( array $columns ): array {
		$columns['swps_seo'] = __( 'SEO', 'stratawp-seo' );
		return $columns;
	}

	/**
	 * Render the SEO score circle and tooltip for a post.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'swps_seo' !== $column ) {
			return;
		}

		$cached = get_post_meta( $post_id, '_swps_seo_score', true );

		if ( empty( $cached ) || ! is_array( $cached ) ) {
			// No score calculated yet.
			echo '<div class="swps-seo-indicator" data-post-id="' . esc_attr( $post_id ) . '">';
			echo '<span class="swps-seo-circle swps-seo--no_keyword" title="' . esc_attr__( 'Not scored', 'stratawp-seo' ) . '">&mdash;</span>';
			echo '<div class="swps-seo-tooltip">';
			echo '<div class="swps-seo-tooltip__header">' . esc_html__( 'SEO Score', 'stratawp-seo' ) . '</div>';
			echo '<p>' . esc_html__( 'Score not calculated yet. Click Refresh to score this post.', 'stratawp-seo' ) . '</p>';
			echo '<div class="swps-seo-tooltip__actions">';
			echo '<a href="#" class="swps-seo-refresh" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( '🔄 Refresh', 'stratawp-seo' ) . '</a>';
			echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '#swps-meta-editor">' . esc_html__( '✏️ Edit SEO', 'stratawp-seo' ) . '</a>';
			echo '</div></div></div>';
			return;
		}

		$score  = (int) ( $cached['score'] ?? 0 );
		$status = $cached['status'] ?? 'poor';
		$checks = $cached['checks'] ?? [];

		if ( 'no_keyword' === $status ) {
			echo '<div class="swps-seo-indicator" data-post-id="' . esc_attr( $post_id ) . '">';
			echo '<span class="swps-seo-circle swps-seo--no_keyword" title="' . esc_attr__( 'No keyword', 'stratawp-seo' ) . '">&mdash;</span>';
			echo '<div class="swps-seo-tooltip">';
			echo '<div class="swps-seo-tooltip__header">' . esc_html__( 'SEO Score', 'stratawp-seo' ) . '</div>';
			echo '<p>' . esc_html__( 'Set a focus keyword to get your SEO score.', 'stratawp-seo' ) . '</p>';
			echo '<div class="swps-seo-tooltip__actions">';
			echo '<a href="#" class="swps-seo-refresh" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( '🔄 Refresh', 'stratawp-seo' ) . '</a>';
			echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '#swps-meta-editor">' . esc_html__( '✏️ Edit SEO', 'stratawp-seo' ) . '</a>';
			echo '</div></div></div>';
			return;
		}

		echo '<div class="swps-seo-indicator" data-post-id="' . esc_attr( $post_id ) . '">';
		echo '<span class="swps-seo-circle swps-seo--' . esc_attr( $status ) . '" title="' . esc_attr( sprintf( __( 'SEO: %d/100', 'stratawp-seo' ), $score ) ) . '">';
		echo esc_html( $score );
		echo '</span>';

		// Tooltip.
		echo '<div class="swps-seo-tooltip">';
		echo '<div class="swps-seo-tooltip__header">';
		echo '<span class="swps-seo-circle swps-seo--' . esc_attr( $status ) . '">' . esc_html( $score ) . '</span> ';
		echo esc_html__( 'SEO Score', 'stratawp-seo' );
		echo '</div>';

		if ( ! empty( $checks ) ) {
			echo '<ul class="swps-seo-tooltip__checks">';
			foreach ( $checks as $check ) {
				$icon  = $check['pass'] ? '✅' : '❌';
				$class = $check['pass'] ? 'swps-check--pass' : 'swps-check--fail';
				$label = esc_html( $check['label'] );
				if ( ! empty( $check['value'] ) ) {
					$label .= ' <span class="swps-check-value">(' . esc_html( $check['value'] ) . ')</span>';
				}
				echo '<li class="' . esc_attr( $class ) . '">' . $icon . ' ' . $label . '</li>';
			}
			echo '</ul>';
		}

		echo '<div class="swps-seo-tooltip__actions">';
		echo '<a href="#" class="swps-seo-refresh" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( '🔄 Refresh', 'stratawp-seo' ) . '</a>';
		echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '#swps-meta-editor">' . esc_html__( '✏️ Edit SEO', 'stratawp-seo' ) . '</a>';
		echo '</div>';

		echo '</div></div>';
	}

	/**
	 * Make the SEO column sortable.
	 */
	public function sortable_column( array $columns ): array {
		$columns['swps_seo'] = 'swps_seo_score';
		return $columns;
	}

	/**
	 * Handle orderby for SEO score column.
	 */
	public function handle_orderby( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'swps_seo_score' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_swps_seo_score_value' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Invalidate cached score on post save.
	 */
	public function invalidate_score( int $post_id ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		delete_post_meta( $post_id, '_swps_seo_score' );
		delete_post_meta( $post_id, '_swps_seo_score_value' );
	}

	/**
	 * AJAX: Refresh a single post's SEO score.
	 */
	public function ajax_refresh(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
		}

		$result = $this->scorer->score_post( $post_id );

		// Return the rendered HTML for the column cell.
		ob_start();
		$this->render_column( 'swps_seo', $post_id );
		$html = ob_get_clean();

		wp_send_json_success( [
			'html'  => $html,
			'score' => $result['score'],
		] );
	}

	/**
	 * AJAX: Bulk refresh all post SEO scores in batches.
	 */
	public function ajax_bulk_refresh(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$offset = absint( $_POST['offset'] ?? 0 );
		$batch  = 20;

		$enabled_types = (array) get_option( 'swps_seo_column_post_types', [ 'post', 'page' ] );

		$posts = get_posts( [
			'post_type'      => $enabled_types,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => $batch,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );

		foreach ( $posts as $pid ) {
			$this->scorer->score_post( $pid );
		}

		$total = 0;
		foreach ( $enabled_types as $pt ) {
			$counts = wp_count_posts( $pt );
			$total += ( $counts->publish ?? 0 ) + ( $counts->draft ?? 0 );
		}

		wp_send_json_success( [
			'processed' => $offset + count( $posts ),
			'total'     => $total,
			'done'      => count( $posts ) < $batch,
		] );
	}

	/**
	 * Enqueue CSS/JS on edit.php screens only.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$enabled_types = (array) get_option( 'swps_seo_column_post_types', [ 'post', 'page' ] );
		if ( ! in_array( $screen->post_type, $enabled_types, true ) ) {
			return;
		}

		// Enqueue admin CSS (not loaded on edit.php by the main plugin).
		wp_enqueue_style(
			'swps-admin',
			SWPS_PLUGIN_URL . 'admin/css/admin.css',
			[],
			SWPS_VERSION
		);

		wp_enqueue_script(
			'swps-post-list-seo',
			SWPS_PLUGIN_URL . 'admin/js/post-list-seo.js',
			[ 'jquery' ],
			SWPS_VERSION,
			true
		);

		wp_localize_script( 'swps-post-list-seo', 'swpsPostListSeo', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'swps_nonce' ),
		] );
	}
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l includes/class-post-list-seo.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/class-post-list-seo.php
git commit -m "feat: add SWPS_Post_List_SEO class for column UI and AJAX"
```

---

## Chunk 3: CSS and JavaScript

### Task 3: Add CSS styles for the SEO column

**Files:**
- Modify: `admin/css/admin.css` (append after line 734)

- [ ] **Step 1: Append SEO column styles**

Add at the end of `admin/css/admin.css`:

```css
/* === v3.1 Post List SEO Column === */
.swps-seo-indicator { position: relative; display: inline-block; }

.swps-seo-circle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    color: #fff;
    font-weight: 700;
    font-size: 11px;
    cursor: pointer;
    line-height: 1;
}
.swps-seo--good { background: #00a32a; }
.swps-seo--needs_work { background: #dba617; }
.swps-seo--poor { background: #d63638; }
.swps-seo--no_keyword { background: #8c8f94; }

.swps-seo-tooltip {
    display: none;
    position: absolute;
    left: 50%;
    bottom: 100%;
    transform: translateX(-50%);
    margin-bottom: 8px;
    width: 280px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    padding: 12px;
    z-index: 9999;
    font-size: 12px;
    line-height: 1.4;
}
.swps-seo-tooltip--below {
    bottom: auto;
    top: 100%;
    margin-bottom: 0;
    margin-top: 8px;
}
.swps-seo-indicator:hover .swps-seo-tooltip { display: block; }

.swps-seo-tooltip__header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 8px;
}
.swps-seo-tooltip__header .swps-seo-circle {
    width: 24px;
    height: 24px;
    font-size: 10px;
}

.swps-seo-tooltip__checks {
    list-style: none;
    margin: 0;
    padding: 0;
}
.swps-seo-tooltip__checks li {
    padding: 3px 0;
    font-size: 12px;
}
.swps-check--pass { color: #00a32a; }
.swps-check--fail { color: #d63638; }
.swps-check-value { color: #666; font-weight: 400; }

.swps-seo-tooltip__actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #e0e0e0;
}
.swps-seo-tooltip__actions a {
    text-decoration: none;
    font-size: 12px;
}

/* Bulk refresh bar */
.swps-bulk-refresh-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    background: #f0f6fc;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    margin-bottom: 12px;
}
.swps-bulk-refresh-bar .swps-progress {
    flex: 1;
    height: 6px;
    background: #ddd;
    border-radius: 3px;
    overflow: hidden;
    display: none;
}
.swps-bulk-refresh-bar .swps-progress-fill {
    height: 100%;
    background: #00a32a;
    width: 0;
    transition: width 0.3s;
}

/* Loading spinner on refresh */
.swps-seo-circle.swps-loading {
    background: #8c8f94;
    animation: swps-pulse 1s infinite;
}
@keyframes swps-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
```

- [ ] **Step 2: Commit**

```bash
git add admin/css/admin.css
git commit -m "style: add SEO column circle, tooltip, and bulk refresh CSS"
```

### Task 4: Create the post list SEO JavaScript

**Files:**
- Create: `admin/js/post-list-seo.js`

- [ ] **Step 1: Create the JavaScript file**

```javascript
/**
 * Post List SEO Column — tooltip positioning, refresh, bulk refresh.
 *
 * @package StrataWP_SEO
 */
(function ($) {
    'use strict';

    var config = window.swpsPostListSeo || {};

    /**
     * Reposition tooltip above or below depending on viewport space.
     */
    function positionTooltip($indicator) {
        var $tooltip = $indicator.find('.swps-seo-tooltip');
        if (!$tooltip.length) return;

        $tooltip.removeClass('swps-seo-tooltip--below');
        var rect = $indicator[0].getBoundingClientRect();
        var tooltipHeight = $tooltip.outerHeight();

        if (rect.top < tooltipHeight + 20) {
            $tooltip.addClass('swps-seo-tooltip--below');
        }
    }

    // Position tooltips on hover.
    $(document).on('mouseenter', '.swps-seo-indicator', function () {
        positionTooltip($(this));
    });

    /**
     * Single post refresh.
     */
    $(document).on('click', '.swps-seo-refresh', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $link = $(this);
        var postId = $link.data('post-id');
        var $indicator = $link.closest('.swps-seo-indicator');
        var $circle = $indicator.find('.swps-seo-circle').first();

        // Show loading state.
        var originalText = $circle.text();
        $circle.text('…').addClass('swps-loading');

        $.post(config.ajaxUrl, {
            action: 'swps_refresh_seo_score',
            nonce: config.nonce,
            post_id: postId
        })
        .done(function (response) {
            if (response.success && response.data.html) {
                $indicator.replaceWith(response.data.html);
            } else {
                $circle.text(originalText).removeClass('swps-loading');
            }
        })
        .fail(function () {
            $circle.text(originalText).removeClass('swps-loading');
        });
    });

    /**
     * Bulk refresh all SEO scores.
     */
    $(document).on('click', '#swps-bulk-refresh-btn', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var $bar = $btn.closest('.swps-bulk-refresh-bar');
        var $progress = $bar.find('.swps-progress');
        var $fill = $bar.find('.swps-progress-fill');
        var $status = $bar.find('.swps-bulk-status');

        $btn.prop('disabled', true);
        $progress.show();
        $fill.css('width', '0%');

        function processBatch(offset) {
            $.post(config.ajaxUrl, {
                action: 'swps_bulk_refresh_seo_scores',
                nonce: config.nonce,
                offset: offset
            })
            .done(function (response) {
                if (!response.success) {
                    $status.text('Error refreshing scores.');
                    $btn.prop('disabled', false);
                    return;
                }

                var data = response.data;
                var pct = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 100;
                $fill.css('width', pct + '%');
                $status.text(data.processed + ' / ' + data.total + ' scored');

                if (data.done) {
                    $status.text('Complete! Reloading…');
                    setTimeout(function () {
                        window.location.reload();
                    }, 500);
                } else {
                    processBatch(data.processed);
                }
            })
            .fail(function () {
                $status.text('Error refreshing scores.');
                $btn.prop('disabled', false);
            });
        }

        processBatch(0);
    });

    /**
     * Inject bulk refresh bar above the posts table.
     */
    $(function () {
        var $table = $('.wp-list-table');
        if ($table.length && $table.find('th#swps_seo, th.column-swps_seo').length) {
            var bar = '<div class="swps-bulk-refresh-bar">' +
                '<button type="button" id="swps-bulk-refresh-btn" class="button">' +
                'Refresh All SEO Scores</button>' +
                '<span class="swps-bulk-status"></span>' +
                '<div class="swps-progress"><div class="swps-progress-fill"></div></div>' +
                '</div>';
            $table.before(bar);
        }
    });

})(jQuery);
```

- [ ] **Step 2: Verify syntax**

Run: `node -e "require('fs').readFileSync('admin/js/post-list-seo.js','utf8')" && echo "File exists and is readable"`
Expected: `File exists and is readable` (basic sanity check — full JS validation happens in browser)

- [ ] **Step 3: Commit**

```bash
git add admin/js/post-list-seo.js
git commit -m "feat: add post list SEO JS for tooltips, refresh, bulk refresh"
```

---

## Chunk 4: Settings, Wiring, and Version Bump

### Task 5: Register new settings

**Files:**
- Modify: `includes/class-settings.php` (add after line 527, before the closing `}` of `register_settings()`)

- [ ] **Step 1: Add settings registrations**

Add after the breadcrumb settings block (after line 527) and before the closing `}` of `register_settings()`:

```php
// SEO Column settings.
register_setting( 'swps_search_appearance', 'swps_seo_column_post_types', [
    'sanitize_callback' => function ( $value ) {
        return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : [ 'post', 'page' ];
    },
    'default' => [ 'post', 'page' ],
] );
register_setting( 'swps_search_appearance', 'swps_seo_score_content_min', [
    'sanitize_callback' => 'absint',
    'default' => 300,
] );
```

- [ ] **Step 2: Verify syntax**

Run: `php -l includes/class-settings.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/class-settings.php
git commit -m "feat: register SEO column settings in search appearance group"
```

### Task 6: Add settings UI to Search Appearance page

**Files:**
- Modify: `templates/search-appearance-page.php` (add section before `submit_button()` on line 177)

- [ ] **Step 1: Add the Post List SEO Column section**

Insert before line 177 (`<?php submit_button(); ?>`):

```php
<h2><?php esc_html_e( 'Post List SEO Column', 'stratawp-seo' ); ?></h2>
<table class="form-table">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable SEO Column', 'stratawp-seo' ); ?></th>
        <td>
            <?php
            $enabled_types = (array) get_option( 'swps_seo_column_post_types', [ 'post', 'page' ] );
            $all_types     = get_post_types( [ 'public' => true ], 'objects' );
            foreach ( $all_types as $pt ) :
                if ( 'attachment' === $pt->name ) continue;
            ?>
                <label style="display: block; margin-bottom: 6px;">
                    <input type="checkbox" name="swps_seo_column_post_types[]"
                        value="<?php echo esc_attr( $pt->name ); ?>"
                        <?php checked( in_array( $pt->name, $enabled_types, true ) ); ?>>
                    <?php echo esc_html( $pt->labels->name ); ?>
                </label>
            <?php endforeach; ?>
            <p class="description"><?php esc_html_e( 'Show the SEO score column on these post type list screens.', 'stratawp-seo' ); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="swps-seo-content-min"><?php esc_html_e( 'Minimum Content Length', 'stratawp-seo' ); ?></label></th>
        <td>
            <input type="number" id="swps-seo-content-min" name="swps_seo_score_content_min"
                value="<?php echo esc_attr( get_option( 'swps_seo_score_content_min', 300 ) ); ?>"
                min="100" max="5000" step="50" class="small-text">
            <span><?php esc_html_e( 'words', 'stratawp-seo' ); ?></span>
            <p class="description"><?php esc_html_e( 'Minimum word count for the content length check in the SEO score.', 'stratawp-seo' ); ?></p>
        </td>
    </tr>
</table>
```

- [ ] **Step 2: Commit**

```bash
git add templates/search-appearance-page.php
git commit -m "feat: add SEO column settings UI to Search Appearance page"
```

### Task 7: Wire up the class and bump version

**Files:**
- Modify: `stratawp-seo.php` (3 edits: require_once, property, instantiation, version)

- [ ] **Step 1: Add require_once**

Add after line 101 (`require_once SWPS_PLUGIN_DIR . 'includes/class-redirect-admin.php';`):

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-post-list-seo.php';
```

- [ ] **Step 2: Add class property**

Add after line 159 (`public SWPS_Redirect_Manager $redirect_manager;`):

```php
public SWPS_Post_List_SEO $post_list_seo;
```

- [ ] **Step 3: Add instantiation in constructor**

Add after line 198 (`$this->redirect_manager   = new SWPS_Redirect_Manager();`):

```php
if ( is_admin() ) {
    $this->post_list_seo = new SWPS_Post_List_SEO( $this->content_scorer );
}
```

- [ ] **Step 4: Bump version to 3.1.0**

Change line 6:
```
 * Version: 3.1.0
```

Change line 20:
```php
define( 'SWPS_VERSION', '3.1.0' );
```

- [ ] **Step 5: Verify syntax**

Run: `php -l stratawp-seo.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add stratawp-seo.php
git commit -m "feat: wire up SWPS_Post_List_SEO, bump version to 3.1.0"
```

---

## Chunk 5: Final Verification

### Task 8: Full syntax check and zip

- [ ] **Step 1: Run syntax check on all changed files**

Run:
```bash
php -l includes/class-content-scorer.php && \
php -l includes/class-post-list-seo.php && \
php -l includes/class-settings.php && \
php -l templates/search-appearance-page.php && \
php -l stratawp-seo.php
```
Expected: All files report `No syntax errors detected`

- [ ] **Step 2: Create the deployment zip**

Run:
```bash
cd /Users/jon.imms/StrataWP-projects && \
rm -f stratawp-seo.zip && \
zip -r stratawp-seo.zip stratawp-seo/ \
  -x "stratawp-seo/.git/*" \
  -x "stratawp-seo/.claude/*" \
  -x "stratawp-seo/.idea/*" \
  -x "stratawp-seo/docs/superpowers/*"
```

- [ ] **Step 3: Verify the zip contains the new files**

Run:
```bash
unzip -l /Users/jon.imms/StrataWP-projects/stratawp-seo.zip | grep -E "post-list-seo|class-post-list"
```
Expected: Shows both `includes/class-post-list-seo.php` and `admin/js/post-list-seo.js`
