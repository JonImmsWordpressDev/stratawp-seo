# Sitemaps, 404 Detection & Easy Redirects — Design Spec

## Overview

Improve the existing sitemap manager and redirect/404 system with dedicated admin UIs, per-post sitemap controls, inline redirect creation from 404s, smart target suggestions, and bulk actions.

## 1. Sitemap Admin Page

### 1.1 New Submenu Page

A dedicated "Sitemaps" page under the StrataWP SEO admin menu, implemented as `SWPS_Sitemap_Admin` in `includes/class-sitemap-admin.php`.

**Two tabs:**

#### Dashboard Tab

A table listing all active sitemaps:

| Column | Description |
|--------|-------------|
| Sitemap | Human name (e.g., "Posts", "Pages", "Categories") |
| URL | Clickable link to the XML file |
| URLs | Count of URLs in that sitemap |
| Last Modified | Most recent post/term modification date |
| Status | Toggle to include/exclude from index |

Below the table:
- IndexNow key display with copy button
- "Ping Search Engines" button — calls existing `ping_search_engines()` via AJAX

#### Settings Tab

Settings moved from the main Settings page (removed from `SWPS_Settings::register_settings`):
- Exclude images from sitemaps (checkbox)
- Exclude author sitemap (checkbox)
- Auto-redirect on slug change (checkbox)
- URLs per sitemap (number input, currently hardcoded at 1000)

These remain as `swps_*` options, saved via standard WordPress Settings API on this page.

### 1.2 Per-Post Sitemap Controls

Added to the existing `SWPS_Meta_Editor` metabox under a "Sitemap" heading:

- **Exclude from sitemap** — checkbox, saves to `_swps_sitemap_exclude` post meta
- **Priority** — dropdown (0.0 to 1.0 in 0.1 steps), saves to `_swps_sitemap_priority`
- **Change frequency** — dropdown (always/hourly/daily/weekly/monthly/yearly/never), saves to `_swps_sitemap_changefreq`

These meta keys already exist and are read by `SWPS_Sitemap_Manager`. Only the UI and save logic need adding.

### 1.3 AJAX Endpoints

| Action | Purpose |
|--------|---------|
| `swps_get_sitemaps` | Returns list of all sitemaps with URL counts and last modified |
| `swps_toggle_sitemap` | Toggles a post type/taxonomy/author exclusion option |
| `swps_ping_search_engines` | Manually triggers sitemap ping |

## 2. Enhanced 404 Detection

### 2.1 Improved 404 Table

On the existing Redirects page, 404 tab enhancements:

**New columns:**
- **Referrer** — already stored in `swps_404_log.referrer`, not currently displayed
- **Suggested Target** — server-side fuzzy match result (or empty if no match)

**Sortable columns** — URL, Hits, Last Seen (client-side sort via JS).

### 2.2 Smart URL Suggestions

New AJAX endpoint `swps_suggest_redirect_target`:

**Input:** A 404 URL path.

**Logic:**
1. Extract the last path segment (slug) from the URL
2. Strip common file extensions (.html, .php, .htm)
3. Query published posts/pages: `WHERE post_name LIKE '%{slug}%' AND post_status = 'publish'`
4. Also try splitting slug on hyphens and searching for partial matches
5. Return up to 5 results with post title and permalink, ordered by similarity (exact match first, then partial)

**Output:** Array of `{ title, url, permalink }` objects.

This endpoint is called when the inline redirect form opens and populates clickable suggestion chips.

### 2.3 Bulk suggestion on 404 list load

When loading the 404 list, the server also runs suggestions for each 404 URL. The suggestion is included in the `swps_get_404s` response as a `suggested_target` field. This avoids per-row AJAX calls.

## 3. Easy Redirects from 404s

### 3.1 Inline Redirect Creation

When "Redirect" is clicked on a 404 row:
1. A new row expands below the 404 entry
2. Contains: pre-filled source URL (read-only), target URL input, type dropdown (default 301), save/cancel buttons
3. If a suggested target exists, it's pre-filled in the target input
4. Additional suggestions shown as clickable chips below the input
5. On save: calls existing `swps_add_redirect` AJAX, then calls `swps_delete_404` to dismiss the 404 entry, then refreshes the list

### 3.2 Bulk Actions

A toolbar above the 404 table:
- Select-all checkbox in table header
- Per-row checkboxes
- Bulk action dropdown with:
  - **Dismiss Selected** — deletes selected 404 entries
  - **Redirect Selected to...** — shows a target URL input, creates 301 redirects for all selected, then dismisses them

New AJAX endpoints:

| Action | Purpose |
|--------|---------|
| `swps_bulk_delete_404s` | Delete multiple 404 entries by ID array |
| `swps_bulk_redirect_404s` | Create 301 redirects for multiple 404s to a single target, then delete the 404 entries |

## 4. File Changes

### New Files

| File | Purpose |
|------|---------|
| `includes/class-sitemap-admin.php` | Sitemap admin page class with AJAX handlers |
| `templates/sitemaps-page.php` | Sitemap admin page template (dashboard + settings tabs) |
| `admin/js/sitemaps.js` | Sitemap page AJAX interactions |

### Modified Files

| File | Changes |
|------|---------|
| `stratawp-seo.php` | Instantiate `SWPS_Sitemap_Admin`, require the file, register new AJAX hooks |
| `includes/class-settings.php` | Register "Sitemaps" submenu page, remove sitemap settings section (moved to sitemap admin) |
| `includes/class-redirect-manager.php` | Add `suggest_redirect_target()`, `ajax_suggest_redirect_target()`, `ajax_bulk_delete_404s()`, `ajax_bulk_redirect_404s()`, include suggested_target in `ajax_get_404s()` response |
| `templates/redirects-page.php` | Add referrer + suggested target columns, checkboxes, bulk action bar, inline redirect form markup |
| `admin/js/redirects.js` | Inline redirect form logic, suggestion chips, bulk actions, sortable columns |
| `admin/css/admin.css` | Styles for inline forms, suggestion chips, bulk action bar, sitemap page |
| `templates/meta-editor-metabox.php` | Add sitemap fields section (exclude, priority, changefreq) |
| `includes/class-meta-editor.php` | Save the three sitemap meta fields on `save_post` |

## 5. Data Model

No new database tables. All changes use existing tables and WordPress options:

- `swps_404_log` — already has `referrer` column
- `swps_redirects` — unchanged
- Post meta: `_swps_sitemap_exclude`, `_swps_sitemap_priority`, `_swps_sitemap_changefreq` (already read by sitemap manager)
- Options: `swps_sitemap_exclude_{type}`, `swps_sitemap_exclude_images`, `swps_sitemap_exclude_author`, `swps_auto_redirect_slug_change`, new `swps_sitemap_urls_per_page`

## 6. Security

All AJAX handlers follow existing patterns:
- `check_ajax_referer('swps_nonce', 'nonce')`
- `current_user_can('manage_options')` check
- Input sanitization via `sanitize_text_field()` and `wp_unslash()`
- Database queries use `$wpdb->prepare()`
- Suggestion queries use `$wpdb->esc_like()` for LIKE clauses
