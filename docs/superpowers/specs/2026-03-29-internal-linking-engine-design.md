# Internal Linking Engine — Design Spec

**Date:** 2026-03-29
**Version:** StrataWP SEO 3.5.0 (target)
**Status:** Approved

## Overview

A reusable internal linking engine that analyzes relationships between posts, suggests link opportunities, and integrates with AI content generation. Uses a hybrid approach: fast keyword-based matching as the default, with optional AI-powered deep analysis for semantic scoring and anchor text suggestions.

## Architecture

Three layers built around a persistent link graph:

```
Background Processor → Keyword Engine → Link Graph (baseline)
                                            ↓
User triggers "Deep Analysis" → AI Engine → Link Graph (enriched)
                                            ↓
Generator ← reads suggestions ← Link Graph → Editor Metabox
                                            → Admin Overview Page
```

### Core Classes

| Class | File | Purpose |
|-------|------|---------|
| `SWPS_Internal_Links` | `includes/class-internal-links.php` | Central engine: link graph CRUD, public query API, orchestration |
| `SWPS_Link_Keyword_Engine` | `includes/class-link-keyword-engine.php` | Term extraction, keyword matching, scoring |
| `SWPS_Link_AI_Engine` | `includes/class-link-ai-engine.php` | AI-powered semantic scoring and anchor text suggestions |
| `SWPS_Internal_Links_Admin` | `includes/class-internal-links-admin.php` | Admin overview page rendering and AJAX handlers |

### Integration Points

- **Generator**: hooks into `swps_before_generate` and `swps_after_generate` actions
- **Editor metabox**: registered via `add_meta_boxes` in `SWPS_Internal_Links`
- **Background processing**: uses existing `SWPS_Background_Processor` for full rebuilds
- **Incremental updates**: hooks into `save_post` for real-time re-indexing

## Data Model

### Table: `swps_link_index`

Keyword/term index per post, used by the keyword engine for fast matching.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `post_id` | BIGINT UNSIGNED NOT NULL | The post this term belongs to |
| `term` | VARCHAR(100) NOT NULL | Extracted keyword/phrase (normalized, lowercase) |
| `source` | ENUM('title','h2','h3','focus_kw','body') NOT NULL | Where the term was found |
| `weight` | FLOAT NOT NULL DEFAULT 0.3 | Importance score |
| `updated_at` | DATETIME NOT NULL | Last time this post's terms were indexed |

**Indexes:** `(post_id)`, `(term)`, `(term, weight DESC)`

**Weight scale:**
- title: 1.0
- focus_kw (from meta editor): 0.9
- h2: 0.8
- h3: 0.6
- body (top terms by frequency, excluding stop words): 0.3

### Table: `swps_link_graph`

The relationship graph between posts.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `source_post_id` | BIGINT UNSIGNED NOT NULL | The post that should contain the link |
| `target_post_id` | BIGINT UNSIGNED NOT NULL | The post being linked to |
| `status` | ENUM('existing','suggested','dismissed','inserted') NOT NULL DEFAULT 'suggested' | Link lifecycle state |
| `match_type` | ENUM('keyword','ai','manual') NOT NULL DEFAULT 'keyword' | How this relationship was discovered |
| `relevance_score` | FLOAT NOT NULL DEFAULT 0 | 0-1 score (keyword overlap or AI confidence) |
| `anchor_text` | VARCHAR(255) DEFAULT NULL | Suggested or actual anchor text |
| `anchor_context` | TEXT DEFAULT NULL | Surrounding sentence or AI rationale |
| `ai_enriched` | TINYINT(1) NOT NULL DEFAULT 0 | Whether AI has scored this relationship |
| `created_at` | DATETIME NOT NULL | When first discovered |
| `updated_at` | DATETIME NOT NULL | Last update |

**Indexes:** `(source_post_id, status)`, `(target_post_id)`, `UNIQUE (source_post_id, target_post_id)`

**Status lifecycle:**
- `existing` — link already present in the post content (detected by HTML parsing)
- `suggested` — engine recommends adding this link
- `dismissed` — user declined this suggestion (won't resurface)
- `inserted` — user accepted and the link was added to the post

## Keyword Engine

### Term Extraction

When a post is indexed, the keyword engine extracts terms from five sources (in weight order):

1. **Post title** (weight 1.0) — split into significant phrases
2. **Focus keyword** from `_swps_focus_keyword` post meta (weight 0.9)
3. **H2 headings** parsed from post content (weight 0.8)
4. **H3 headings** parsed from post content (weight 0.6)
5. **Body terms** — top 10 terms by frequency after stop-word removal (weight 0.3)

All terms are normalized: lowercased, trimmed, stripped of punctuation. Terms under 3 characters are excluded.

### Matching Algorithm

For a given post, the engine:

1. Retrieves the post's terms from `swps_link_index`
2. Queries for other posts sharing those terms
3. Scores each candidate by summing `(source_weight * target_weight)` for all shared terms
4. Normalizes scores to 0-1 range
5. Filters by configurable threshold (default 0.3, setting: `swps_link_relevance_threshold`)
6. Writes results to `swps_link_graph` as `status=suggested, match_type=keyword`

### Existing Link Detection

Before suggesting, the engine parses the post's HTML content for `<a>` tags with `href` attributes pointing to internal URLs (same domain). Each detected link is recorded in the graph as `status=existing` with the actual anchor text. This prevents suggesting links that already exist.

## AI Deep Analysis

Triggered explicitly by user action (never automatic). Uses the configured `SWPS_AI_Provider`.

### Process

1. Takes keyword-matched candidates for a post (up to 10 per batch, configurable via `swps_link_ai_batch_size`)
2. Sends a structured prompt to the AI provider containing:
   - Source post: title, excerpt, focus keyword
   - Each candidate: title, excerpt, focus keyword
3. AI returns per-candidate:
   - Semantic relevance score (0-1)
   - Suggested anchor text
   - One-line rationale explaining the connection
4. Results update the link graph: `relevance_score`, `anchor_text`, `anchor_context`, `ai_enriched=1`

### Cost Management

- Results cached in link graph — AI analysis is one-time per relationship until content changes
- Token usage logged via existing `SWPS_Cost_Tracker`
- Batch size configurable (default 10)
- AI analysis is always explicit, never runs in background cron

### Fallback

If no AI provider is configured or the API call fails, keyword scores remain as-is. The feature degrades gracefully to keyword-only mode with no error state in the UI.

## Generator Integration

### Before Generation (Prompt Enrichment)

Hooks into `swps_before_generate`. When generating a new post:

1. Queries the link graph for top candidates related to the topic (by keyword overlap with the generation topic)
2. Selects up to 5 highest-relevance suggestions
3. Injects a structured section into the AI generation prompt:

```
When writing this post, naturally incorporate internal links to these related posts where relevant:
- "Post Title" (URL) — connection rationale
- "Post Title" (URL) — connection rationale
```

Controlled by setting `swps_internal_links_in_generation` (default: enabled).

### After Generation (Link Verification)

Hooks into `swps_after_generate`. After content is returned:

1. Parses generated HTML for internal `<a>` tags
2. Records each as `status=existing` in the link graph
3. Notes any high-relevance suggestions the AI didn't use (available for manual review in the editor metabox)

## Editor Metabox

A new metabox titled "Internal Links" added below the existing "StrataWP SEO" metabox on post edit screens.

### Sections

1. **Existing Links** — list of internal links already in the post content. Each shows: target post title, anchor text used.

2. **Suggested Links** — ranked list from the link graph (`status=suggested`). Each shows:
   - Target post title (linked to edit screen)
   - Relevance score as colored dot (green >0.7, yellow 0.4-0.7, red <0.4)
   - Suggested anchor text (from AI if enriched, otherwise the target post title)
   - "Insert" button — adds the link to post content via JS, updates status to `inserted`
   - "Dismiss" button — updates status to `dismissed`, removes from list

3. **Deep Analysis button** — triggers AI enrichment for all keyword-matched suggestions for this post. Shows loading state, updates suggestions in-place when complete.

4. **Stats line** — "This post has X internal links. Y suggestions available."

### JavaScript Behavior

- "Insert" button: injects an `<a>` tag into the editor content. For block editor: finds the most relevant paragraph block (by keyword overlap with anchor text) and wraps the matching phrase. For classic editor: inserts at cursor position or appends to first matching paragraph.
- AJAX calls for refresh, insert, dismiss, and deep analysis.
- Nonce-protected endpoints.

## Admin Overview Page

New submenu page "Internal Links" under the StrataWP SEO menu.

### Link Health Summary

Dashboard cards showing:
- **Total internal links** across the site (count of `status=existing` + `status=inserted`)
- **Average links per post** (total links / published post count)
- **Orphan pages** — posts with 0 inbound internal links (count + link to filtered view)
- **Most-linked posts** — top 5 posts by inbound link count
- **Pending suggestions** — count of `status=suggested` relationships

### Opportunities Table

Sortable, filterable WP_List_Table showing all `status=suggested` relationships:

| Column | Description |
|--------|-------------|
| Source Post | Title (linked to editor) |
| Target Post | Title (linked to editor) |
| Relevance | Score with colored indicator |
| Match Type | keyword / ai |
| Anchor Text | Suggested anchor |

**Filters:** match type, minimum relevance score
**Bulk actions:** Dismiss selected, Run deep analysis on selected

### Orphan Pages Tab

Filtered view of posts with zero inbound internal links. Shows post title, date, outbound link count. These represent the highest-value quick wins for internal linking.

### Rebuild Index Button

Triggers a full background re-index of all published posts. Shows progress via AJAX polling during rebuild.

## Settings

Added to the existing Search Appearance settings group (or a new "Internal Links" tab):

| Setting | Key | Default | Description |
|---------|-----|---------|-------------|
| Enable Internal Links | `swps_internal_links_enabled` | `1` | Master toggle for the entire feature |
| Post Types | `swps_internal_links_post_types` | `['post', 'page']` | Which post types to index and analyze |
| Relevance Threshold | `swps_link_relevance_threshold` | `0.3` | Minimum score for keyword matches to become suggestions |
| Max Suggestions Per Post | `swps_link_max_suggestions` | `10` | Cap on suggestions shown per post |
| Include in Generation | `swps_internal_links_in_generation` | `1` | Whether to inject link suggestions into AI generation prompts |
| AI Batch Size | `swps_link_ai_batch_size` | `10` | Max candidates per AI deep analysis request |

## Background Processing

### Full Rebuild

Uses `SWPS_Background_Processor`. Processes all published posts in batches of 50:
1. Extract terms → write to `swps_link_index`
2. Detect existing links → write to `swps_link_graph`
3. Run keyword matching → write suggestions to `swps_link_graph`

Triggered on: plugin activation, manual "Rebuild Index" button.

### Incremental Updates

Hooks into `save_post`:
1. Re-extract terms for the saved post
2. Re-detect existing links in the updated content
3. Re-score relationships involving this post
4. Clean up graph entries for deleted posts

Runs inline (synchronous) since it's only processing one post.

### Scheduled Maintenance

Weekly cron job (`swps_link_maintenance`):
1. Remove graph entries referencing deleted/trashed posts
2. Remove index entries for non-published posts
3. Re-score any relationships where the source or target content has changed since `updated_at`

## Error Handling

- **Missing AI provider**: graceful fallback to keyword-only mode. No error shown unless user explicitly triggers deep analysis, in which case a notice explains that an AI provider must be configured.
- **Large sites**: batch processing prevents timeouts. The opportunities table uses pagination (20 per page). Index rebuild shows progress.
- **Plugin conflicts**: the engine only indexes and suggests. It never modifies post content without explicit user action (clicking "Insert"). Defers to other SEO plugins for meta/schema concerns (same pattern as existing meta editor).
