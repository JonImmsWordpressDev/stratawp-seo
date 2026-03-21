# StrataWP SEO v3.0.0 — Yoast Replacement Design

**Date:** 2026-03-21
**Version:** 3.0.0
**Goal:** Close all feature gaps with Yoast/RankMath so users can fully uninstall competing SEO plugins and rely solely on StrataWP SEO.

## Architecture Approach

Modular extension — each feature is a separate class following the existing `SWPS_` prefix, class-per-file pattern. No namespaces, no directory restructuring. New files added to `includes/` alongside existing classes.

---

## 1. Full Sitemap System

### New Classes
- **`SWPS_Sitemap_Manager`** — Replaces basic sitemap generation. Handles sitemap index, sub-sitemap generation, rewrite rules, search engine pinging.
- **`SWPS_Sitemap_Admin`** — Admin UI for sitemap configuration and per-URL editing.

### Sitemap Types
- **Sitemap Index** (`/sitemap_index.xml`) — Master index linking to all sub-sitemaps
- **Post Type Sitemaps** (`/post-sitemap.xml`, `/page-sitemap.xml`, etc.) — One per public post type, paginated at 1000 URLs
- **Taxonomy Sitemaps** (`/category-sitemap.xml`, `/post_tag-sitemap.xml`, etc.) — One per public taxonomy
- **Author Sitemap** (`/author-sitemap.xml`) — Authors with published posts
- **Image Sitemap** — `<image:image>` entries embedded within post type sitemaps

### Per-URL Controls (post meta / term meta)
- `_swps_sitemap_exclude` — Boolean, exclude from sitemap
- `_swps_sitemap_priority` — 0.0–1.0 (auto-calculated default based on post type/depth)
- `_swps_sitemap_changefreq` — always/hourly/daily/weekly/monthly/yearly/never

### Admin UI
- New "Sitemaps" tab in Settings page:
  - Toggle per post type / taxonomy (include/exclude entire type)
  - Author sitemap toggle
  - Image sitemap toggle
  - "View Sitemap" links
- Per-post controls in existing Meta Editor metabox (exclude checkbox, priority dropdown)

### Search Engine Submission
- Auto-ping Google and Bing on post publish/update/delete
- IndexNow API support (Bing/Yandex instant indexing):
  - Generate a random API key on plugin activation, stored in option `swps_indexnow_key`
  - Serve verification file at `/{key}.txt` via `template_redirect` handler (same pattern as sitemap serving)
  - Ping `https://api.indexnow.org/indexnow` with key, URL, and key location on post publish/update
- Manual "Submit Sitemap" button in admin

### WP Core Sitemap Handling
- On initialization, `SWPS_Sitemap_Manager` disables WP core sitemaps via `add_filter('wp_sitemaps_enabled', '__return_false')` — this runs before any conflict detection guard
- This ensures our sitemaps are the sole provider and prevents the existing `SWPS_Sitemap_Module::detect_other_sitemap()` from suppressing generation

### Migration
- `SWPS_Sitemap_Module` audit module stays but delegates generation to `SWPS_Sitemap_Manager`
- Existing `/swps-sitemap.xml` redirects to `/sitemap_index.xml`

---

## 2. Search Appearance Defaults

### New Class
- **`SWPS_Search_Appearance`** — Manages default title/description templates for all content types, outputs via `wp_head`.

### Template Variables
- `%%title%%` — Post/page/term title
- `%%sitename%%` — Site name
- `%%sep%%` — Configured separator
- `%%excerpt%%` — Post excerpt (trimmed)
- `%%category%%` — Primary category
- `%%tag%%` — First tag
- `%%author%%` — Author display name
- `%%date%%` — Published date
- `%%page%%` — Page number (for paginated)
- `%%searchphrase%%` — Search query
- `%%pt_single%%` / `%%pt_plural%%` — Post type labels

### Default Templates

| Content Type | Default Title | Default Description |
|---|---|---|
| Posts | `%%title%% %%sep%% %%sitename%%` | `%%excerpt%%` |
| Pages | `%%title%% %%sep%% %%sitename%%` | `%%excerpt%%` |
| Categories | `%%title%% Archives %%sep%% %%sitename%%` | Term description |
| Tags | `%%title%% %%sep%% %%sitename%%` | Auto-generated |
| Author | `%%author%% %%sep%% %%sitename%%` | Author bio excerpt |
| Search | `Search: %%searchphrase%% %%sep%% %%sitename%%` | — |
| 404 | `Page Not Found %%sep%% %%sitename%%` | — |

### Title Separator
- Setting: `swps_title_separator`
- Choices: `|`, `-`, `–`, `—`, `·`, `•`, `»`
- Default: `-`

### Priority Logic & Hook Coordination with Meta Editor
1. Per-post meta title (from Meta Editor) wins if set
2. Search Appearance template as fallback
3. WordPress default as last resort

**Hook details:** `SWPS_Search_Appearance` hooks `document_title_parts` at priority **10** (before `SWPS_Meta_Editor` at priority 20). It applies template-based titles for all page types. `SWPS_Meta_Editor` then overrides at priority 20 for singular posts that have a `_swps_meta_title` value set — this is the existing behavior unchanged. For meta descriptions, `SWPS_Search_Appearance` outputs a `<meta name="description">` tag via `wp_head` at priority 2, but checks `is_singular()` and defers to `SWPS_Meta_Editor` if a per-post `_swps_meta_description` exists. Non-singular pages (archives, taxonomies, search, 404) are handled exclusively by `SWPS_Search_Appearance`.

### Admin UI
- New "Search Appearance" admin page under StrataWP SEO menu
- Sections for each post type + taxonomy with title/description template fields
- Live preview showing how templates resolve
- Title separator picker

---

## 3. Taxonomy & Archive SEO

### New Class
- **`SWPS_Taxonomy_Meta`** — Adds SEO meta fields to taxonomy term edit screens, outputs meta tags on archive pages.

### Term Meta Fields
- Meta title override
- Meta description override
- Canonical URL override
- Robots directives (noindex/nofollow)
- OG title/description/image
- Focus keyword

### Archive Page Meta Output (via `wp_head`)
- Category/tag/custom taxonomy archives
- Author archives
- Date archives (year/month/day)
- Post type archives
- Search results page

### Per-Taxonomy Settings (in Search Appearance page)
- Show/hide in search results (noindex entire taxonomy)
- Default title/description templates
- Show/hide in sitemap

### Admin UI
- Fields on standard WordPress term edit screen (`{taxonomy}_edit_form_fields` hook)
- Quick-edit noindex toggle in term list table
- Character counters and SERP preview (reuse `meta-editor.js`)

### Storage
- WordPress term meta (`update_term_meta` / `get_term_meta`)

---

## 4. Redirect Manager

### New Classes
- **`SWPS_Redirect_Manager`** — Redirect storage, matching, and execution via `template_redirect` at priority 1.
- **`SWPS_Redirect_Admin`** — Admin page for managing redirects.

### Redirect Types
- **301** — Permanent (SEO value passes)
- **302** — Temporary
- **307** — Temporary (preserves HTTP method)
- **410** — Gone (permanently removed)

### Matching Modes
- **Exact URL** — `/old-page` → `/new-page`
- **Regex** — `/blog/(\d{4})/(.*)` → `/articles/$1/$2`

### Auto-Redirect on Slug Change
- Hook into `post_updated` — auto-create 301 when slug changes
- Toggle: `swps_auto_redirect_slug_change`

### Database Table: `wp_swps_redirects`
- `id` (bigint, auto-increment)
- `source_url` (varchar 500, indexed)
- `target_url` (varchar 500)
- `type` (smallint — 301/302/307/410)
- `is_regex` (tinyint)
- `hits` (bigint, default 0)
- `last_hit` (datetime)
- `created_at` (datetime)
- `updated_at` (datetime)

### Performance
- Redirects cached in transient (flushed on add/edit/delete)
- Exact matches checked first, regex only if no exact match
- Early hook priority (`template_redirect` at 1)

### Admin UI
- New "Redirects" admin page under StrataWP SEO menu
- Table: source, target, type, hit count, last hit
- Add/edit form with source URL, target URL, type dropdown, regex toggle
- Bulk delete, import/export CSV, search/filter

### 404 Monitor
- Log 404s to `wp_swps_404_log` table:
  - `id` (bigint, auto-increment, PRIMARY KEY)
  - `url` (varchar 500, UNIQUE KEY)
  - `referrer` (varchar 500)
  - `count` (bigint, default 1)
  - `last_seen` (datetime)
- "404 Errors" tab on Redirects page
- One-click "Create Redirect" from logged 404s
- Auto-prune after 90 days

---

## 5. Frontend Breadcrumbs

### New Class
- **`SWPS_Breadcrumbs`** — HTML breadcrumb output with inline schema markup.

### Output Methods
- `swps_breadcrumbs()` — PHP template function
- `[swps_breadcrumbs]` — Shortcode
- **Gutenberg Block** — "StrataWP Breadcrumbs"

### HTML Structure
```html
<nav aria-label="Breadcrumb" class="swps-breadcrumbs">
  <ol itemscope itemtype="https://schema.org/BreadcrumbList">
    <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
      <a itemprop="item" href="/"><span itemprop="name">Home</span></a>
      <meta itemprop="position" content="1">
    </li>
  </ol>
</nav>
```

### Hierarchy Logic
- **Posts:** Home → Category (primary) → Post Title
- **Pages:** Home → Parent Page → Current Page
- **Categories:** Home → Parent Category → Category
- **Tags:** Home → Tag
- **Author:** Home → Author Name
- **Date:** Home → Year → Month → Day
- **Search:** Home → "Search: query"
- **404:** Home → "Page Not Found"
- **CPTs:** Home → Post Type Archive → Post Title

### Settings (in Search Appearance page)
- Breadcrumb separator (default: `»`)
- Home label (default: "Home")
- Enable/disable (default: enabled)
- CSS class prefix

### Integration
- Replaces schema-only breadcrumbs in `SWPS_Schema` — JSON-LD moves into `SWPS_Breadcrumbs`
- **`SWPS_Schema::output_schema()` must be modified** to skip calling `$this->breadcrumb_schema()` when `SWPS_Breadcrumbs` is active (check via class existence or option). This prevents duplicate BreadcrumbList JSON-LD output.
- Respects existing `_swps_breadcrumb_title` post meta override

---

## 6. RSS Feed Optimization

### New Class
- **`SWPS_RSS_Optimizer`**

### Features
- Configurable content before/after RSS feed items
- Variables: `%%post_link%%`, `%%blog_link%%`, `%%blog_name%%`
- Default after-content: `The post %%post_link%% appeared first on %%blog_link%%.`
- Hooks into `the_content_feed` filter

### Settings
- "Content before post in RSS" textarea
- "Content after post in RSS" textarea
- Located in Settings page under Advanced section

---

## 7. wp_head Cleanup

### New Class
- **`SWPS_Head_Cleanup`**

### Removable Items (each a toggle, all off by default)
- WP Generator tag (`<meta name="generator">`)
- RSD/EditURI link
- Windows Live Writer manifest
- Shortlink (`<link rel="shortlink">`)
- REST API link tag
- oEmbed discovery links
- Emoji scripts and styles

### Settings
- Individual toggles in Settings page → Advanced → "Head Cleanup" section
- Options: `swps_cleanup_generator`, `swps_cleanup_rsd`, `swps_cleanup_wlw`, `swps_cleanup_shortlink`, `swps_cleanup_rest_api`, `swps_cleanup_oembed`, `swps_cleanup_emoji`

---

## New Files Summary

### PHP Classes (9 new files in `includes/`)
1. `class-sitemap-manager.php` — `SWPS_Sitemap_Manager`
2. `class-sitemap-admin.php` — `SWPS_Sitemap_Admin`
3. `class-search-appearance.php` — `SWPS_Search_Appearance`
4. `class-taxonomy-meta.php` — `SWPS_Taxonomy_Meta`
5. `class-redirect-manager.php` — `SWPS_Redirect_Manager`
6. `class-redirect-admin.php` — `SWPS_Redirect_Admin`
7. `class-breadcrumbs.php` — `SWPS_Breadcrumbs`
8. `class-rss-optimizer.php` — `SWPS_RSS_Optimizer`
9. `class-head-cleanup.php` — `SWPS_Head_Cleanup`

### Templates (~3 new files in `templates/`)
10. `search-appearance-page.php`
11. `redirects-page.php`
12. `sitemap-settings.php` (or tab within settings)

### JavaScript (~2 new files in `admin/js/`)
13. `redirects.js` — Redirect admin page interactivity
14. `search-appearance.js` — Live template preview

### Database Changes
- New table: `wp_swps_redirects`
- New table: `wp_swps_404_log`
- Both tables must be created via `SWPS_Redirect_Manager::create_tables()` called from `swps_activate()` in `stratawp-seo.php` (follows existing pattern used by `SWPS_Analytics_Tracker::create_tables()`)
- IndexNow key generated on activation: `swps_indexnow_key` option set to random hex string if not already present

### Modified Files
- `stratawp-seo.php` — Version bump to 3.0.0, load new classes, register new admin pages, wire `SWPS_Redirect_Manager::create_tables()` into `swps_activate()`
- `uninstall.php` — Add `DROP TABLE IF EXISTS` for `wp_swps_redirects` and `wp_swps_404_log`, clean up new `swps_*` options and `_swps_sitemap_*` / `_swps_taxonomy_*` meta
- `includes/class-settings.php` — New settings sections (sitemaps, head cleanup, RSS)
- `includes/class-meta-editor.php` — Add sitemap per-post controls
- `includes/class-schema.php` — Remove breadcrumb JSON-LD (moved to `SWPS_Breadcrumbs`)
- `includes/audit/class-sitemap-module.php` — Delegate to `SWPS_Sitemap_Manager`
- `admin/js/meta-editor.js` — Support new sitemap fields
- `admin/css/admin.css` — Styles for new pages
- `README.md` — Update documentation
- `readme.txt` — Update changelog

---

## Conflict Detection

Existing conflict detection in `SWPS_Schema` and `SWPS_Meta_Editor` checks for Yoast/RankMath/AIOSEO. This pattern extends to all new features:
- Sitemaps: Disables WP core sitemaps proactively (see Section 1); skips generation only if Yoast/RankMath detected as active plugins
- Search Appearance: Skip title/meta output if competing plugin active
- Redirects: No conflict (additive)
- Breadcrumbs: Skip if Yoast breadcrumbs function detected

All conflict detection should degrade gracefully — features simply don't output, with an admin notice explaining why.

---

## Version Bump

- `SWPS_VERSION` constant: `2.3.0` → `3.0.0`
- Plugin header `Version:` field: `3.0.0`
- `readme.txt` stable tag: `3.0.0`
