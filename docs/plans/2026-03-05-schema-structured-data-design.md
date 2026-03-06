# Schema / Structured Data — Design Document

**Date:** 2026-03-05
**Scope:** Site-wide JSON-LD structured data output for Article, Breadcrumb, WebSite, and Organization/Person schema types.
**Approach:** Single coordinator class (`SWPS_Schema`) hooked to `wp_head`, with auto-detection of competing SEO plugins.

---

## 1. Overview

Add automatic JSON-LD structured data to every post and page. Four schema types target the rich results that matter most: Article (post content), Breadcrumb (navigation hierarchy), WebSite (sitelinks searchbox), and Organization/Person (site identity + publisher).

Applies to **all posts and pages** — not limited to SWPS-generated content. Existing FAQ and Key Takeaways schema (post-meta based, AI-generated content only) remain unchanged.

---

## 2. New Class: `SWPS_Schema`

**File:** `includes/class-schema.php`

Single class, one public entry point hooked to `wp_head` at priority 1. Outputs up to 4 `<script type="application/ld+json">` blocks depending on page context.

### Conflict Detection

Constructor checks before registering the `wp_head` hook:

1. `swps_schema_enabled` option — master toggle, default on
2. `WPSEO_VERSION` constant (Yoast SEO)
3. `RANK_MATH_VERSION` constant (RankMath)
4. `AIOSEO_VERSION` constant (All in One SEO)

If the master toggle is off or any competing plugin is active, the class does not hook into `wp_head`. No schema output.

### Output Method

The `wp_head` callback calls each schema method based on page context:

| Schema Type | Condition | Method |
|---|---|---|
| Article | `is_singular('post')` | `article_schema()` |
| Breadcrumb | All pages except `is_front_page()` | `breadcrumb_schema()` |
| WebSite | `is_front_page()` | `website_schema()` |
| Organization/Person | `is_front_page()` | `organization_schema()` |

Each method builds an array, applies its filter, then outputs via `wp_json_encode()` with `JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT`.

---

## 3. Article Schema

**Outputs on:** `is_singular('post')`

Fields populated from standard WordPress data:

| JSON-LD Field | Source |
|---|---|
| `@type` | Setting: `swps_schema_article_type` (default `Article`) |
| `headline` | `get_the_title()` |
| `description` | Excerpt, or auto-trimmed content (160 chars) |
| `author` | `@type: Person`, author display name, author archive URL |
| `datePublished` | Post date, ISO 8601 |
| `dateModified` | Post modified date, ISO 8601 |
| `image` | Featured image URL + width + height (omitted if none) |
| `publisher` | References Organization from settings |
| `mainEntityOfPage` | Canonical URL (`get_permalink()`) |
| `wordCount` | `str_word_count( wp_strip_all_tags( $content ) )` |

**Setting:** `swps_schema_article_type` — dropdown: `Article` (default), `BlogPosting`, `NewsArticle`.

**Filter:** `swps_schema_article` — receives schema array and post ID.

---

## 4. Breadcrumb Schema

**Outputs on:** All pages except homepage.

Builds `BreadcrumbList` with `ListItem` entries automatically from WordPress hierarchy:

**Posts:** Home → Primary Category → Post Title
- Primary category: first assigned category, or Yoast primary category (`_yoast_wpseo_primary_category` meta) if set.

**Pages:** Home → Parent Page → Child Page
- Follows WP `post_parent` hierarchy.

**Category/Tag archives:** Home → Archive Name

Each `ListItem` includes `position`, `name`, and `item` (URL).

**No settings needed.** Schema-only — no visible frontend breadcrumb trail.

**Filter:** `swps_schema_breadcrumb` — receives schema array.

---

## 5. WebSite Schema

**Outputs on:** `is_front_page()` only.

| JSON-LD Field | Source |
|---|---|
| `@type` | `WebSite` |
| `name` | `swps_schema_name` setting (falls back to `get_bloginfo('name')`) |
| `url` | `home_url('/')` |
| `potentialAction` | `SearchAction` with target `site_url('/?s={search_term_string}')` |

**Setting:** `swps_schema_searchbox` — checkbox, default on. When off, the `potentialAction` is omitted.

---

## 6. Organization / Person Schema

**Outputs on:** `is_front_page()` only.

| JSON-LD Field | Source |
|---|---|
| `@type` | `swps_schema_entity_type` setting: `Organization` or `Person` |
| `name` | `swps_schema_name` setting |
| `logo` | `swps_schema_logo` setting (URL, min 112×112px) |
| `url` | `home_url('/')` |
| `sameAs` | `swps_schema_social_profiles` setting (one URL per line → array) |

Also serves as the `publisher` reference in Article schema.

**Filter:** `swps_schema_organization` — receives schema array.

---

## 7. Settings

New section "Schema / Structured Data" on the StrataWP SEO settings page.

| Option Key | Type | Default | Purpose |
|---|---|---|---|
| `swps_schema_enabled` | Checkbox | `1` (on) | Master toggle for all schema output |
| `swps_schema_article_type` | Select | `Article` | Article, BlogPosting, or NewsArticle |
| `swps_schema_searchbox` | Checkbox | `1` (on) | Enable sitelinks SearchAction |
| `swps_schema_entity_type` | Select | `Organization` | Organization or Person |
| `swps_schema_name` | Text | (empty, falls back to site name) | Entity name |
| `swps_schema_logo` | Text (URL) | (empty) | Logo image URL |
| `swps_schema_social_profiles` | Textarea | (empty) | Social profile URLs, one per line |

Total: 7 fields in one section.

---

## 8. Developer Hooks

| Filter | Parameters | Fires When |
|---|---|---|
| `swps_schema_article` | `$schema, $post_id` | Before Article JSON-LD output |
| `swps_schema_breadcrumb` | `$schema` | Before BreadcrumbList JSON-LD output |
| `swps_schema_organization` | `$schema` | Before Organization/Person JSON-LD output |

---

## 9. Files

**Create (1):**
- `includes/class-schema.php` — `SWPS_Schema` class (~250–300 lines)

**Modify (2):**
- `stratawp-seo.php` — `require_once`, instantiate `SWPS_Schema`, hook to `wp_head` priority 1
- `includes/class-settings.php` — add Schema section with 7 fields

**No changes to:**
- Existing FAQ schema (`output_faq_schema()`)
- Existing Takeaways schema (`output_takeaways_schema()`)
- Audit modules, REST API, admin JS/CSS
