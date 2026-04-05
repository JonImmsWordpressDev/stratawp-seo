# Post List SEO Column — Design Spec

**Date:** 2026-03-22
**Status:** Approved

## Overview

Add an SEO quality indicator column to the WordPress posts list table (edit.php). Shows a colored circle (green/orange/red/grey) with a numeric score (0-100). Hovering reveals a tooltip with a 14-item checklist breakdown, a refresh link, and an edit link. Configurable per post type.

## Approach

**Split into scorer + column (Approach B):**
- Extend `SWPS_Content_Scorer` with a `score_post()` method for the 14-check analysis
- New `SWPS_Post_List_SEO` class handles column UI, tooltip rendering, and AJAX

## Section 1: Content Scorer Extension

**File:** `includes/class-content-scorer.php` (existing)

### New method: `score_post( $post_id )`

Runs 14 checks against a post and returns a structured result:

```php
[
    'score'   => 72,
    'status'  => 'needs_work', // 'good' | 'needs_work' | 'poor' | 'no_keyword'
    'checks'  => [
        'focus_keyword_set'    => ['pass' => true,  'label' => 'Focus keyword set'],
        'keyword_in_title'     => ['pass' => true,  'label' => 'Keyword in meta title'],
        'keyword_in_desc'      => ['pass' => false, 'label' => 'Keyword in meta description'],
        'title_length'         => ['pass' => true,  'label' => 'Meta title length (50-60 chars)', 'value' => '55 chars'],
        'desc_length'          => ['pass' => false, 'label' => 'Meta description length (150-160 chars)', 'value' => '98 chars'],
        'keyword_first_para'   => ['pass' => true,  'label' => 'Keyword in first paragraph'],
        'keyword_in_h2'        => ['pass' => false, 'label' => 'Keyword in an H2 heading'],
        'content_length'       => ['pass' => true,  'label' => 'Content length (min 300 words)', 'value' => '1,240 words'],
        'internal_links'       => ['pass' => true,  'label' => 'Internal link present'],
        'external_links'       => ['pass' => false, 'label' => 'External link present'],
        'image_alt_keyword'    => ['pass' => true,  'label' => 'Image alt text contains keyword'],
        'slug_keyword'         => ['pass' => true,  'label' => 'Slug contains keyword'],
        'og_image_set'         => ['pass' => false, 'label' => 'OG image is set'],
        'readability'          => ['pass' => true,  'label' => 'Readability score OK', 'value' => 'Grade 8'],
    ],
]
```

### Scoring Formula

Each of the 14 checks is worth equal weight (~7.14 points). Pass = full points, fail = 0. Total rounded to nearest integer.

### Status Thresholds

- No focus keyword → `no_keyword` (grey)
- 80–100 → `good` (green)
- 50–79 → `needs_work` (orange)
- 0–49 → `poor` (red)

### Caching

Two meta keys stored per post:
- `_swps_seo_score` — Serialized array containing the full result (score, status, checks)
- `_swps_seo_score_value` — Flat integer (0-100) for sortable column queries

On `save_post`: both meta keys are **deleted** (invalidated only). The column displays "—" with a "Score not calculated" message until the user clicks Refresh. This keeps saves fast and avoids running the full 14-check analysis on every save/autosave.

Recalculation only happens via:
- AJAX single-post refresh (click "Refresh" in tooltip)
- AJAX bulk refresh (click "Refresh All SEO Scores" button)

### Check Details

| # | Key | What it checks |
|---|-----|----------------|
| 1 | `focus_keyword_set` | `_swps_focus_keyword` meta is non-empty |
| 2 | `keyword_in_title` | Focus keyword appears in `_swps_meta_title` (case-insensitive) |
| 3 | `keyword_in_desc` | Focus keyword appears in `_swps_meta_description` |
| 4 | `title_length` | `_swps_meta_title` is 50–60 characters |
| 5 | `desc_length` | `_swps_meta_description` is 150–160 characters (matches existing `analyze_meta_quality()` threshold) |
| 6 | `keyword_first_para` | Focus keyword in first `<p>` of `post_content` |
| 7 | `keyword_in_h2` | Focus keyword in at least one `<h2>` in `post_content` |
| 8 | `content_length` | `post_content` word count >= configurable min (default 300) |
| 9 | `internal_links` | At least 1 `<a>` linking to same domain in `post_content` |
| 10 | `external_links` | At least 1 `<a>` linking to external domain in `post_content` |
| 11 | `image_alt_keyword` | At least one `<img>` in `post_content` has `alt` containing keyword |
| 12 | `slug_keyword` | `post_name` contains the keyword (hyphenated) |
| 13 | `og_image_set` | `_swps_social_image` meta is non-empty |
| 14 | `readability` | Flesch-Kincaid grade level between 6–10 (calls `analyze_readability()` directly within the class; `post_content` must be run through `wp_strip_all_tags()` before passing to strip block/HTML markup) |

## Section 2: Post List Column UI

**New file:** `includes/class-post-list-seo.php`
**New class:** `SWPS_Post_List_SEO`

### Constructor

Receives `SWPS_Content_Scorer` instance as dependency.

### Hooks

Registered in `__construct()` (matching the pattern used by `SWPS_Analytics_Dashboard`), looping through enabled post types from `swps_seo_column_post_types` option:

**Column registration** — WordPress uses different hooks for built-in vs custom post types:
- For `post`: `manage_posts_columns` + `manage_posts_custom_column`
- For `page`: `manage_pages_columns` + `manage_pages_custom_column`
- For custom post types: `manage_{$post_type}_posts_columns` + `manage_{$post_type}_posts_custom_column`

The class must detect the post type and register the correct hook variant. All types use `manage_edit-{$post_type}_sortable_columns` for sortability.

- `pre_get_posts` — Handles orderby using `_swps_seo_score_value` flat meta key (numeric sort via `meta_value_num`)
- `wp_ajax_swps_refresh_seo_score` — Single post refresh (returns new HTML)
- `wp_ajax_swps_bulk_refresh_seo_scores` — Bulk refresh in batches of 20
- `admin_enqueue_scripts` — Loads CSS/JS on `edit.php` screens only

### Column HTML Output

```html
<div class="swps-seo-indicator" data-post-id="123">
  <span class="swps-seo-circle swps-seo--needs_work" title="SEO: 72/100">
    72
  </span>
  <div class="swps-seo-tooltip">
    <div class="swps-seo-tooltip__header">
      <span class="swps-seo-circle swps-seo--needs_work">72</span>
      SEO Score
    </div>
    <ul class="swps-seo-tooltip__checks">
      <li class="swps-check--pass">✅ Focus keyword set</li>
      <li class="swps-check--fail">❌ Keyword in meta description</li>
      <!-- ... all 14 checks -->
    </ul>
    <div class="swps-seo-tooltip__actions">
      <a href="#" class="swps-seo-refresh" data-post-id="123">🔄 Refresh</a>
      <a href="/wp-admin/post.php?post=123&action=edit#swps-meta-editor">✏️ Edit SEO</a>
    </div>
  </div>
</div>
```

### No-Keyword State

Circle is grey, displays "—", tooltip says "Set a focus keyword to get your SEO score."

### Bulk Refresh

Admin notice bar at top of posts list with "Refresh All SEO Scores" button. AJAX processes in batches of 20 with a progress indicator. Reloads page on completion.

### Performance

Column reads from cached `_swps_seo_score` meta — no scoring on page load. Only AJAX refresh triggers recalculation.

## Section 3: CSS & JavaScript

### CSS additions to `admin/css/admin.css`

**Circle:**
- `.swps-seo-circle` — 28px diameter, border-radius 50%, white text, bold, font-size 11px, centered, inline-flex, cursor pointer
- `.swps-seo--good` — background `#00a32a`
- `.swps-seo--needs_work` — background `#dba617`
- `.swps-seo--poor` — background `#d63638`
- `.swps-seo--no_keyword` — background `#8c8f94`

**Tooltip:**
- `.swps-seo-tooltip` — hidden by default, absolute, white bg, box-shadow, border-radius 6px, width 280px, z-index 9999, padding 12px
- `.swps-seo-indicator:hover .swps-seo-tooltip` — display block
- `.swps-seo-tooltip__checks li` — padding 3px 0, font-size 12px
- `.swps-check--pass` — color `#00a32a`
- `.swps-check--fail` — color `#d63638`
- `.swps-seo-tooltip__actions` — border-top, flex, gap 12px

### New file: `admin/js/post-list-seo.js`

- **Tooltip positioning** — detect viewport overflow, show above/below circle as needed
- **Refresh click** — AJAX to `swps_refresh_seo_score`, replace circle + tooltip HTML, spinner during load
- **Bulk refresh** — AJAX to `swps_bulk_refresh_seo_scores`, batch processing, progress bar, page reload on completion
- Enqueued only on `edit.php` for enabled post types

## Section 4: Settings Integration

### Location

Existing Search Appearance settings page (`templates/search-appearance-page.php`).

### New Section: "Post List SEO Column"

**Settings:**
- `swps_seo_column_post_types` — Array of post type slugs. Default: `['post', 'page']`. UI: checkbox list of all public post types.
- `swps_seo_score_content_min` — Minimum word count for content length check. Default: `300`. This is intentionally separate from `swps_word_count_min` (which defaults to 1200 for AI-generated content). The column check uses its own setting only — no fallback to `swps_word_count_min`.

### Plugin Initialization

In `stratawp-seo.php` `__construct()` (following the existing instantiation pattern — there is no `init_classes()` method):
- `new SWPS_Post_List_SEO( $this->content_scorer )` — passes the already-instantiated scorer
- Only on `is_admin()`

### Save Hook

On `save_post`, delete `_swps_seo_score` and `_swps_seo_score_value` meta (invalidate only). This is handled in `SWPS_Post_List_SEO` via its own `save_post` hook, not in `SWPS_Meta_Editor` — keeping the dependency clean. Score recalculation only happens via AJAX refresh.

### AJAX Security

Both AJAX handlers use `check_ajax_referer( 'swps_nonce', 'nonce' )` matching the existing pattern across all other AJAX handlers in the plugin. The nonce is passed to JavaScript via `wp_localize_script()` when enqueuing `post-list-seo.js`.

## Files Changed

| File | Action |
|------|--------|
| `includes/class-content-scorer.php` | Add `score_post()` method |
| `includes/class-post-list-seo.php` | **New** — column UI, AJAX, hooks |
| `includes/class-meta-editor.php` | No changes needed (score invalidation handled by `SWPS_Post_List_SEO`) |
| `admin/css/admin.css` | Add circle + tooltip styles |
| `admin/js/post-list-seo.js` | **New** — tooltip, refresh, bulk AJAX |
| `templates/search-appearance-page.php` | Add column settings section |
| `includes/class-settings.php` | Register new settings under `swps_search_appearance` group (same form group used by the Search Appearance page) |
| `stratawp-seo.php` | Instantiate `SWPS_Post_List_SEO`, bump version |
