# StrataWP SEO

**AI-powered SEO content generator that knows your WordPress site.** Generate optimized blog posts with internal linking, structured data, sitemaps, redirects, AI-crawler access control, llms.txt, on-site analytics, GSC integration, and a per-post meta editor — on autopilot or on demand.

[![Version](https://img.shields.io/badge/version-4.0.0-blue.svg)]()
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)]()
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)]()
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Table of Contents

- [What This Plugin Does](#what-this-plugin-does)
- [Features](#features)
- [Architecture](#architecture)
- [How Each Subsystem Works](#how-each-subsystem-works)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration Guide](#configuration-guide)
- [WP-CLI Commands](#wp-cli-commands)
- [REST API](#rest-api)
- [Developer Reference (Hooks)](#developer-reference)
- [FAQ](#frequently-asked-questions)
- [Changelog](#changelog)

---

## What This Plugin Does

StrataWP SEO bundles three things into one plugin:

1. **An AI content engine** that writes site-aware blog posts (Anthropic, OpenAI, Google, xAI) with internal linking, FAQ schema, key takeaways, TOC, and featured/in-content images.
2. **A complete technical SEO layer** — XML sitemaps, redirect manager, breadcrumbs, schema.org JSON-LD, meta editor, search appearance templates, archive SEO, RSS optimization, head cleanup, robots.txt control, and an 8-module audit.
3. **An AI discoverability layer** — robots.txt allowlist for 15 known AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, etc.), a dynamic `llms.txt` index built from your post excerpts, and IndexNow batch submission for instant Bing/ChatGPT indexing.

It's designed to **replace** Yoast/RankMath/AIOSEO if you want to, or **coexist** with them (schema and meta output auto-disable when those plugins are detected).

---

## Features

### AI Content Generation
- **Multi-provider AI** — Anthropic (Claude Opus 4.7, Opus 4.6, Sonnet 4.6/4.5, Haiku 4.5), OpenAI (GPT), Google (Gemini), xAI (Grok)
- **Site-aware generation** — analyzes existing posts, categories, and internal links before writing
- **7 content templates** — Auto, Listicle, How-To Guide, Comparison, Case Study, News Analysis, Tutorial
- **Voice profiles** — reusable writing personas (tone, formality 1-10, sentence length, vocabulary, person, avoid/preferred phrases, sample text)
- **Internal linking** — keyword-based and AI-based engines with min/max controls
- **FAQ sections** — generates FAQ with JSON-LD `FAQPage` schema
- **Key Takeaways** — bullet-point summary with `ItemList` schema
- **Table of Contents** — auto-linked TOC at post top
- **Duplicate detection** — title/content similarity check (configurable threshold)
- **Content scoring** — rates posts on SEO quality; optional gate that holds low-scoring posts as drafts
- **Cost tracking** — per-provider, per-model token + USD tracking
- **Rate limiting** — cooldown prevents accidental double-generation

### Featured & In-Content Images
- **4 image providers** — Unsplash, Pexels, Pixabay (free stock), Gemini (AI-generated)
- **Auto featured image** — fetched per post based on derived query
- **In-content images** — 1-4 contextual images placed within the body, with derived alt text
- **Configurable max width** — keeps file sizes sane

### Automated Publishing
- **Flexible scheduling** — daily, twice/three-times weekly, weekly, biweekly, monthly
- **Topic queue** — pre-load topics with scheduled dates and template preferences (custom post type)
- **Content calendar** — visual calendar of scheduled and generated content
- **Bulk generation** — up to 5 posts in one click
- **Background processor** — long-running generations queued via WP cron

### AI Crawlers & llms.txt
- **AI bot allowlist** — checkbox grid for 15 known crawlers (GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-Web, anthropic-ai, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended, CCBot, Meta-ExternalAgent, Bytespider, Amazonbot, DuckAssistBot)
- **robots.txt injection** — allowed bots get explicit `Allow: /` rules; unchecked known bots get `Disallow: /`
- **Dynamic llms.txt** — served at `/llms.txt`, built from your Site Description (intro) + posts/pages/categories with one-line summaries pulled from meta description → excerpt → trimmed first paragraph
- **Auto sitemap detection** — references Yoast/RankMath/AIOSEO/WP-core sitemap URL automatically

### Full Sitemap System
- **Sitemap index** at `/sitemap_index.xml` with post type, taxonomy, and author sub-sitemaps
- **Per-URL control** — configurable priority and changefreq
- **Image sitemap entries** — image metadata in sitemap output
- **Per-sitemap exclusion toggles** — one-click disable for any sub-sitemap from the Sitemaps admin
- **IndexNow** — batch submits 50 most recently modified posts to Bing/Yandex/Seznam (Bing's IndexNow feed powers ChatGPT search)

### Redirect Manager
- **301/302/307/410** redirects with exact and regex matching
- **404 monitoring** — error log with one-click "create redirect" button
- **Auto-redirect on slug change** — preserves SEO juice when post slugs change
- **Cron-pruned 404 logs** — old entries auto-removed

### Internal Links Engine
- **Keyword engine** — finds keyword-anchor opportunities in existing content
- **AI engine** — uses your configured AI provider to suggest links based on semantic similarity
- **Batch processor** — runs in chunks via background processing
- **Admin UI** — review, accept, or skip suggestions per post

### SEO Meta Editor (per-post)
- **Live SERP preview** with character counters
- **Per-post meta title/description**, focus keyword, canonical URL
- **Robots meta** controls (noindex/nofollow per post)
- **Open Graph + Twitter Card** per-post overrides
- **Breadcrumb title override**
- **AI Generate** button for one-click meta generation
- **SEO checklist** with focus keyword analysis
- **Conflict detection** — auto-disables when Yoast/RankMath/AIOSEO is active

### Search Appearance
- **Title/description templates** for posts, pages, archives, taxonomies, search, 404
- **Template variables** — `%title%`, `%sitename%`, `%sep%`, `%category%`, `%author%`, etc.
- **Title separator picker**

### Taxonomy & Archive SEO
- **Per-term meta** — title, description, canonical, robots, OG image on category/tag/taxonomy edit screens
- **Frontend output** — all archive meta rendered on archive pages

### Frontend Breadcrumbs
- **HTML breadcrumbs** with inline `BreadcrumbList` JSON-LD
- **Template function**, **shortcode**, configurable separator and home label

### RSS Feed Optimization
- Configurable **before/after content** injected around RSS items with template variables

### wp_head Cleanup
- Toggle removal of WP generator tag, RSD link, shortlink, REST API link, oEmbed, emoji scripts

### Technical SEO Audit
- **8 audit modules** — Canonical URLs, XML Sitemap, Open Graph, Twitter Cards, Robots.txt, Meta Robots, Image SEO, Page Speed Hints
- **Auto-fix** — one-click fixes for canonical tags, OG/Twitter meta, sitemap generation
- **Scheduled audits** — daily, weekly, or monthly
- **Dashboard widget** — site health score
- **CSV export**

### Schema / Structured Data
- **Article** (Article, BlogPosting, or NewsArticle) — auto on posts
- **BreadcrumbList** — on posts, pages, archives, author pages
- **WebSite** with optional `SearchAction` for Google sitelinks searchbox
- **Organization or Person** with logo and `sameAs` social profiles
- **FAQPage** and **ItemList** (Key Takeaways)
- **Conflict detection** — auto-disabled when Yoast/RankMath/AIOSEO is active

### Keyword Research & Tracking
- **AI keyword suggestions** from seed topics
- **GSC sync** — automatic keyword position tracking via Google Search Console
- **Striking distance** — surfaces keywords ranking positions 8-20 (best win opportunities)

### Analytics & Search Console
- **On-site analytics** — cookie-free, GDPR-friendly (no external services, no consent banner needed)
- **Time on page**, **scroll depth**, **bounce rate**
- **Google Search Console** OAuth — clicks, impressions, CTR, position
- **Unified dashboard** with Chart.js visualizations and date range filtering (7/30/90 days)
- **Per-post analytics metabox** — views, time, scroll depth, top GSC queries
- **Sortable "Views (30d)" column** in the posts list table
- **Configurable retention** — 30/90/180/365 days with auto-pruning

### Developer Features
- **30+ filters and actions** — extend every part of the pipeline
- **WP-CLI** commands (`wp swps generate|analyze|status|queue ...`)
- **REST API** — programmatic access to generation, voice profiles, audit, analytics
- **Encrypted secret storage** — sensitive options (GSC client secret, remote endpoint secret) encrypted at rest
- **Debug page** — last failed AI response saved to a transient and viewable from the admin

---

## Architecture

### File Map

```
stratawp-seo/
├── stratawp-seo.php              Plugin bootstrap, autoloader, main StrataWP_SEO singleton
├── uninstall.php                 Clean removal on plugin delete
├── readme.txt                    WordPress.org plugin readme
├── README.md                     This file
│
├── includes/                     Core PHP classes (one class per file, swps_ prefix)
│   ├── class-ai-provider.php          Abstract base + JSON parser with 5-stage repair
│   ├── class-ai-bots.php              robots.txt allowlist + dynamic llms.txt
│   ├── class-analyzer.php             Site analysis: posts, categories, link graph
│   ├── class-analytics-dashboard.php  Admin dashboard rendering + Chart.js data
│   ├── class-analytics-tracker.php    Cookie-free pageview/engagement tracker
│   ├── class-api.php                  Internal API helpers
│   ├── class-audit-module.php         Abstract base for audit modules
│   ├── class-background-processor.php Queue + cron for long-running tasks
│   ├── class-breadcrumbs.php          Frontend breadcrumb output + schema
│   ├── class-cache-manager.php        Transient + object-cache abstraction
│   ├── class-calendar.php             Visual calendar for topic queue
│   ├── class-cli.php                  WP-CLI command registration
│   ├── class-content-scorer.php       Quality scoring for generated content
│   ├── class-cost-tracker.php         Per-provider/model token + USD tracking
│   ├── class-cron.php                 Scheduled auto-publish runner
│   ├── class-duplicate-checker.php    Title/content similarity detection
│   ├── class-encryption.php           AES-256 secret storage
│   ├── class-generator.php            The main AI content generation pipeline
│   ├── class-head-cleanup.php         wp_head tag removal (generator, RSD, oEmbed, etc.)
│   ├── class-hooks.php                Centralized filter/action hub (swps_* prefix)
│   ├── class-image-inserter.php       In-content image placement engine
│   ├── class-image-provider.php       Abstract base for image providers
│   ├── class-images.php               Image utility helpers
│   ├── class-internal-links.php       Internal linking orchestrator
│   ├── class-internal-links-admin.php Admin UI for link suggestions
│   ├── class-keyword-tracker.php      GSC sync + striking distance detection
│   ├── class-keywords-page.php        Keyword research admin page
│   ├── class-link-ai-engine.php       AI-powered internal link suggestions
│   ├── class-link-keyword-engine.php  Keyword-anchor link suggestions
│   ├── class-meta-editor.php          Per-post SEO metabox + frontend output
│   ├── class-post-list-seo.php        Posts list table SEO column + bulk edits
│   ├── class-provider-factory.php     Resolves the active AI/image provider class
│   ├── class-rate-limiter.php         Cooldown between generations
│   ├── class-redirect-admin.php       Redirect manager admin UI
│   ├── class-redirect-manager.php     Redirect matching, 404 logging, slug-change auto-redirect
│   ├── class-rest-api.php             REST endpoints (/wp-json/strawp-seo/v1/...)
│   ├── class-rss-optimizer.php        RSS feed before/after content injection
│   ├── class-schema.php               JSON-LD output (Article, Breadcrumb, WebSite, Org/Person, FAQ, ItemList)
│   ├── class-search-appearance.php    Title/description template engine
│   ├── class-search-console.php       GSC OAuth + API client
│   ├── class-seo-audit.php            Audit orchestrator + dashboard widget
│   ├── class-settings.php             All settings registration + admin pages
│   ├── class-sitemap-admin.php        Sitemap admin page + IndexNow ping AJAX
│   ├── class-sitemap-manager.php      Sitemap generation + IndexNow batch submission
│   ├── class-taxonomy-meta.php        Per-term archive meta
│   ├── class-templates.php            Content template definitions (listicle, how-to, etc.)
│   ├── class-topic-queue.php          Topic queue custom post type + helpers
│   ├── class-voice-profile.php        Voice profile CPT + system prompt injection
│   │
│   ├── audit/                    8 audit modules (extend SWPS_Audit_Module)
│   │   ├── class-canonical-module.php
│   │   ├── class-image-seo-module.php
│   │   ├── class-meta-robots-module.php
│   │   ├── class-opengraph-module.php
│   │   ├── class-pagespeed-module.php
│   │   ├── class-robots-module.php
│   │   ├── class-sitemap-module.php
│   │   └── class-twitter-module.php
│   │
│   └── providers/                AI + image provider implementations
│       ├── ai/
│       │   ├── class-anthropic-provider.php
│       │   ├── class-google-provider.php
│       │   ├── class-openai-provider.php
│       │   └── class-xai-provider.php
│       └── images/
│           ├── class-gemini-provider.php
│           ├── class-pexels-provider.php
│           ├── class-pixabay-provider.php
│           └── class-unsplash-provider.php
│
├── templates/                    Admin page view files (PHP templates)
│   ├── analytics-page.php
│   ├── audit-page.php
│   ├── calendar-page.php
│   ├── generate-page.php
│   ├── internal-links-page.php
│   ├── keywords-page.php
│   ├── meta-editor-metabox.php
│   ├── redirects-page.php
│   ├── search-appearance-page.php
│   ├── settings-page.php
│   ├── sitemaps-page.php
│   ├── voice-profile-edit.php
│   └── voice-profiles-page.php
│
├── admin/
│   ├── css/                      admin.css, calendar.css
│   └── js/                       Per-page JS (admin.js shared; sitemaps.js, analytics.js, etc.)
│
├── docs/                         Internal docs and notes
└── screenshots/                  WP plugin screenshots
```

### Boot Sequence

1. **`stratawp-seo.php`** loads — `require_once` chains pull in every `includes/` class (deliberate dependency order: base classes → providers → factory → consumers).
2. **`StrataWP_SEO::instance()`** — singleton runs `__construct()` which:
   - Initializes foundation subsystems (cache, duplicate checker, rate limiter, cost tracker, voice profiles, content scorer)
   - Resolves the active AI and image providers via `SWPS_Provider_Factory`
   - Instantiates ~30 service classes and stores them as public properties (e.g., `$this->generator`, `$this->settings`, `$this->ai_bots`)
   - Hooks into `init`, `admin_menu`, `admin_init`, `wp_enqueue_scripts`, `admin_enqueue_scripts`, `wp_ajax_*`, `rest_api_init`, etc.
3. **CLI commands** registered if `WP_CLI` is defined.
4. **First request** — providers stay lazy (only instantiated when `chat()` / `chat_json()` is called).

### Provider Pattern

Both AI and image subsystems use the same pattern:

```
SWPS_AI_Provider (abstract)
    ├── SWPS_Anthropic_Provider
    ├── SWPS_OpenAI_Provider
    ├── SWPS_Google_Provider
    └── SWPS_XAI_Provider

SWPS_Image_Provider (abstract)
    ├── SWPS_Unsplash_Provider
    ├── SWPS_Pexels_Provider
    ├── SWPS_Pixabay_Provider
    └── SWPS_Gemini_Provider
```

`SWPS_Provider_Factory::create_ai_provider()` reads the `swps_ai_provider` option and returns the right concrete class. Each provider:

- Defines `get_slug()`, `get_name()`, `get_api_key_url()`, `get_available_models()`
- Implements `chat()` (raw text) and inherits `chat_json()` (5-stage JSON parser with quote repair, control-char sanitization, BOM/fence stripping, Unicode separator removal, brace trimming)
- Reports `last_usage` (input/output tokens) and `last_stop_reason` for cost tracking and truncation detection

### The Hooks Hub

`SWPS_Hooks` (in `class-hooks.php`) is the single entry point for the `swps_*` filter and action hooks. The generation pipeline calls `SWPS_Hooks::filter_system_prompt()`, `filter_user_prompt()`, `filter_ai_response()`, `filter_post_data()`, etc. — and any plugin or theme can hook in.

---

## How Each Subsystem Works

### Content Generation Pipeline

`SWPS_Generator::generate( $topic, $template )` walks through:

1. **Rate limit** — block if cooldown active (`SWPS_Rate_Limiter`)
2. **Duplicate check** — refuse if a published post is too similar (`SWPS_Duplicate_Checker`)
3. **Site analysis** — fetch existing posts, categories, internal link candidates (`SWPS_Analyzer`)
4. **Prompt build** — system prompt (with voice profile injection) + user prompt with site context, template instructions, target keywords, internal link list
5. **AI call** — `SWPS_AI_Provider::chat_json()` with 16,384 max tokens and a strict JSON schema requirement
6. **JSON parse** — 5-stage repair pipeline (see `parse_json_response()` in `class-ai-provider.php`). Failures save the raw response to a transient and surface a Debug admin page.
7. **Image fetch** — featured image from configured provider; in-content images placed by `SWPS_Image_Inserter`
8. **Post insert** — `wp_insert_post()` with full schema metadata
9. **Content scoring** — `SWPS_Content_Scorer` rates the result; below `min_content_score` → forced to draft
10. **Hooks fire** — `swps_post_created`, `swps_after_generate`

### AI Crawler Allowlist (`SWPS_AI_Bots`)

- Hooks `robots_txt` filter at priority 100, appends a `# AI crawlers — managed by StrataWP SEO` block with `Allow: /` per allowed bot and `Disallow: /` for known bots the user has unchecked.
- Default behavior (no option saved): all 15 known bots are allowed.
- Option name: `swps_ai_bots_allowed` (array of bot keys from `SWPS_AI_Bots::KNOWN_BOTS`).

### llms.txt Generator (`SWPS_AI_Bots::maybe_serve_llms_txt`)

- Hooks `init` at priority 1 (before Yoast or any other plugin can serve `/llms.txt`).
- Returns `text/markdown` content with `X-Robots-Tag: noindex`.
- Body composition:
  - `# {site_name}` + `> {site_description}` (uses your plugin's Site Description field, falls back to WP tagline)
  - `Site:` and `Sitemap:` lines (auto-detects Yoast/RankMath/AIOSEO/WP-core sitemap URL)
  - `## Posts` — 100 most recent published posts
  - `## Pages` — top-level published pages (excluding home)
  - `## Categories` — 20 most-used categories
- Per-entry summary uses (in order): plugin meta description → Yoast meta → RankMath meta → post excerpt → trimmed first paragraph (~200 chars at sentence boundary).
- Filterable via `swps_llms_txt_content` and `swps_llms_txt_post_limit`.

### Sitemap Manager (`SWPS_Sitemap_Manager`)

- Generates `/sitemap_index.xml` and per-type sub-sitemaps (`{post_type}-sitemap.xml`, `{taxonomy}-sitemap.xml`, `author-sitemap.xml`).
- Each sub-sitemap respects the `swps_sitemap_exclude_{key}` option.
- `urls_per_page` setting controls split point (default 1000, max 50000).
- Image sitemap entries built from post attachments.
- **IndexNow batch submit** — `ping_search_engines()` posts up to 50 most recently modified posts to `api.indexnow.org`, returns `{submitted, status, error}` for the admin UI to display.
- Auto-fires after publish/update via `transition_post_status` (when enabled in audit settings).

### SEO Audit (`SWPS_SEO_Audit`)

- Loads 8 modules, each extending `SWPS_Audit_Module`:
  - `Canonical_Module` — checks `<link rel="canonical">` on key URLs
  - `Sitemap_Module` — verifies sitemap accessibility and indexability
  - `OpenGraph_Module` / `Twitter_Module` — social meta presence
  - `Robots_Module` — robots.txt sanity (disallow rules, sitemap reference)
  - `Meta_Robots_Module` — per-page robots meta
  - `Image_SEO_Module` — alt text, image dimensions
  - `Pagespeed_Module` — basic page speed hints (render-blocking, image weight)
- Each module returns `{score, status, message, issues[], fixable}`.
- Auto-fix runs registered fixers when `audit_auto_*` options are on.
- Dashboard widget shows aggregate score; full report has CSV export.
- Filterable: `swps_audit_modules`, `swps_audit_result`, `swps_audit_complete`.

### Schema Output (`SWPS_Schema`)

- Hooks `wp_head` and outputs JSON-LD blocks for:
  - **Article / BlogPosting / NewsArticle** on posts
  - **BreadcrumbList** on all post types and taxonomies
  - **WebSite** (with `SearchAction` if enabled) on the homepage
  - **Organization** or **Person** site representation with logo + `sameAs` socials
- Auto-disables completely if `WPSEO_VERSION`, `RankMath`, or `AIOSEO_VERSION` is defined.
- Each block runs through a `swps_schema_{type}` filter for customization.

### Meta Editor (`SWPS_Meta_Editor`)

- Adds a metabox to the post editor for selected post types (default `post`, `page`).
- Stores per-post: `_swps_meta_title`, `_swps_meta_description`, `_swps_focus_keyword`, `_swps_canonical_url`, `_swps_robots_noindex`, `_swps_robots_nofollow`, `_swps_og_*`, `_swps_twitter_*`, `_swps_breadcrumb_title`.
- Renders live SERP preview, character counters, SEO checklist (focus keyword presence in title/H1/first paragraph/URL/meta).
- AI Generate button (admin AJAX) calls the active AI provider and fills meta title + description.
- Frontend output via `wp_head` — disabled if Yoast/RankMath/AIOSEO is active.

### On-Site Analytics (`SWPS_Analytics_Tracker`)

- Cookie-free. Tracks: post URL, referrer, time on page (sendBeacon on unload), scroll depth (max %), bounce status (left in <10s without interaction).
- Tracking script enqueued only on singular posts when enabled.
- Server-side filter `swps_analytics_exclude` and data filter `swps_analytics_track` for control.
- Stored in custom table `wp_swps_analytics`. Cron-pruned to retention setting.

### Search Console (`SWPS_Search_Console`)

- OAuth flow stores token in `swps_gsc_token` (refresh token encrypted via `SWPS_Encryption`).
- API client wraps GSC `searchAnalytics.query` for clicks/impressions/CTR/position.
- Used by `SWPS_Keyword_Tracker` (rank tracking) and the analytics dashboard (top queries).

### Voice Profiles (`SWPS_Voice_Profile`)

- Custom post type `swps_voice_profile`.
- Fields: name, tone, formality (1-10), sentence length, vocabulary level, person (first/second/third), avoid_phrases (array), preferred_phrases (array), example_content (≤500 chars sample).
- When active (selected in settings), `inject_voice_profile()` filter prepends a compiled `VOICE PROFILE` block to the system prompt at priority 5 (before other system-prompt filters).

### Internal Links (`SWPS_Internal_Links`)

- Two engines:
  - **Keyword engine** — looks for `target_keywords` settings + post titles in content of other published posts; suggests anchor text.
  - **AI engine** — sends a small batch of post excerpts to the AI for semantic link suggestions.
- Background processor runs in chunks to avoid timeouts.
- Admin UI lets you accept/skip per suggestion.

### Redirect Manager (`SWPS_Redirect_Manager`)

- Stored in custom table with columns: `source`, `target`, `status_code`, `is_regex`, `hits`, `last_hit`.
- Matched on `template_redirect` action (early enough to short-circuit before the page renders).
- 404 logging table feeds the "Create redirect" one-click button on the redirects admin.
- Slug-change hook (`post_updated`) auto-creates 301 from old to new slug when enabled.

### Cron / Auto-Publishing (`SWPS_Cron`)

- Custom WP-Cron schedules registered for biweekly and three-times-weekly cadences.
- `swps_auto_generate` hook fires `SWPS_Generator::generate()` with the next queued topic (or AI-chosen if queue empty).
- Schedule UI shows next run time computed from frequency + day-of-week + time settings.

### Cost Tracking (`SWPS_Cost_Tracker`)

- `PRICING` constant maps `model_id => [input, output]` USD per 1M tokens.
- Records every generation: provider, model, input tokens, output tokens, computed cost.
- Aggregated for the admin dashboard.

### Encryption (`SWPS_Encryption`)

- AES-256-CBC via `openssl_*` functions, key derived from `AUTH_KEY` + `AUTH_SALT`.
- Used for `swps_gsc_client_secret` and `swps_jon_ai_secret`. Saved values prefixed with a sentinel so `is_encrypted()` can detect them.

---

## Installation

### From WordPress Admin
1. Download the plugin ZIP file
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and click **Install Now**
4. Click **Activate**

### Manual Installation
1. Upload the `stratawp-seo` folder to `/wp-content/plugins/`
2. Activate through the **Plugins** menu

### Requirements
- WordPress 6.0 or higher
- PHP 8.0 or higher
- An API key from at least one AI provider (Anthropic, OpenAI, Google, or xAI)

---

## Quick Start

1. **Add your API key** — **StrataWP SEO → Settings**, enter your AI provider API key (Anthropic recommended)
2. **Describe your site** — fill in **Site Niche** and **Site Description** so the AI understands your audience
3. **Set your preferences** — **Tone of Voice**, word count range, **Post Status: Draft** (recommended)
4. **(Optional) Allow AI crawlers** — **AI Crawlers** section is enabled by default; visit `/llms.txt` to see your generated index
5. **Generate your first post** — **StrataWP SEO → Generate Content**, enter a topic, click **Generate**
6. **Review & publish** — edit the generated draft in WordPress, then publish

---

## Configuration Guide

### AI Provider

| Setting | Description |
|---|---|
| **AI Provider** | Anthropic (Claude), OpenAI (GPT), Google (Gemini), or xAI (Grok) |
| **API Key** | Per-provider; only the active provider's key is required |
| **AI Model** | Auto-updates when you switch providers |

**Available Anthropic models:**
- `claude-opus-4-7` — Most powerful, higher cost (default)
- `claude-opus-4-6` — Previous Opus generation
- `claude-sonnet-4-6` — Balanced quality and cost
- `claude-sonnet-4-5-20250929` — Previous generation
- `claude-haiku-4-5-20251001` — Fastest, lowest cost

The plugin handles model-specific quirks automatically — for example, Claude 4.6+ models do not support assistant prefill, so the JSON-coercion prefill is disabled for those.

### Featured Images

| Setting | Description |
|---|---|
| **Auto Featured Images** | Fetch a relevant image per post |
| **Image Provider** | Unsplash, Pexels, Pixabay (free stock), or Gemini (AI-generated) |
| **In-Content Images** | Insert contextual images within the post body |
| **Images Per Post** | Number of in-content images (1-4) |
| **Image Max Width** | Maximum width in pixels (600-2400) |

### Site Details

| Setting | Description |
|---|---|
| **Site Niche** | Your industry or topic area |
| **Site Description** | Detailed description of your site, audience, and unique value proposition |

### AI Crawlers

| Setting | Description |
|---|---|
| **Allowed AI Bots** | Multi-checkbox of 15 known crawlers. Allowed bots get an explicit `Allow: /` rule in robots.txt; unchecked known bots get `Disallow: /` |
| **Generate llms.txt** | Serve a dynamic `llms.txt` at `/llms.txt` (overrides Yoast/other plugin output) |

### Writing Preferences

| Setting | Description |
|---|---|
| **Tone of Voice** | Professional, Conversational, Friendly, Authoritative, Casual, Formal, Witty |
| **Voice Profile** | Reusable persona (manage under StrataWP SEO → Voice Profiles); overrides tone/style |
| **Custom Style Notes** | Free-text instructions ("Use short paragraphs, avoid jargon") |
| **Target Keywords** | Comma-separated keywords woven into generated content + used by the keyword link engine |

### Content Settings

| Setting | Description |
|---|---|
| **Post Status** | Draft (recommended), Pending Review, or Published |
| **Post Author** | WordPress user assigned as author |
| **Default Category** | Fixed category, or AI-decided |
| **Word Count** | Min (300-5000) and max (500-8000) targets |
| **Internal Links** | Min and max number of links to existing posts |
| **FAQ Section** | Generate FAQ with `FAQPage` schema for rich snippets |
| **Table of Contents** | Linked TOC at the top of each post |
| **Key Takeaways** | Bullet-point summary with optional `ItemList` schema |

### Auto-Publishing Schedule

| Setting | Description |
|---|---|
| **Enable** | Turn on scheduled generation |
| **Frequency** | Daily, twice weekly, three times weekly, weekly, biweekly, monthly |
| **Day of Week** | Starting day for the schedule |
| **Time** | Time of day to run |
| **Posts Per Run** | 1-5 posts per scheduled run |

### SEO Audit

| Setting | Description |
|---|---|
| **Auto Canonical Tags** | Add canonical tags where missing |
| **Auto OG/Twitter Tags** | Output OG and Twitter Card meta |
| **Sitemap Generation** | Generate XML sitemap (auto-disabled if another sitemap plugin is active) |
| **Audit Schedule** | Daily, weekly, monthly |

### Schema / Structured Data

| Setting | Description |
|---|---|
| **Enable Schema Markup** | Output JSON-LD (auto-disabled when Yoast/RankMath/AIOSEO active) |
| **Article Type** | Article, BlogPosting, or NewsArticle |
| **Sitelinks Searchbox** | Add `SearchAction` for Google sitelinks |
| **Site Represents** | Organization or Person |
| **Name** | Defaults to site name |
| **Logo URL** | Min 112x112px |
| **Social Profiles** | One URL per line — populates `sameAs` |

### SEO Meta Editor

| Setting | Description |
|---|---|
| **Meta Editor Enabled** | Enable/disable the metabox on post edit screens |
| **Meta Editor Post Types** | Comma-separated post types (default `post,page`) |
| **Auto-Generate Meta** | Auto-generate meta title/description on publish |

### Keyword Tracking

| Setting | Description |
|---|---|
| **Keyword Sync Frequency** | How often to sync GSC data (daily, weekly, monthly) |

### Analytics

| Setting | Description |
|---|---|
| **Enable On-Site Tracking** | Cookie-free pageview/engagement tracking |
| **Data Retention** | 30, 90, 180, or 365 days |
| **Exclude Admins** | Don't track logged-in admins |
| **Google OAuth Client ID/Secret** | For Search Console (secret stored encrypted) |

### Advanced Settings

| Setting | Description |
|---|---|
| **Default Template** | Default content format |
| **Rate Limit** | Cooldown in seconds between generations |
| **Duplicate Detection** | Block posts too similar to existing content |
| **Cost Tracking** | Track tokens + estimated USD per generation |
| **Min Content Score** | Posts scoring below are forced to draft (0 disables) |

---

## Admin Pages

| Page | Path | Purpose |
|---|---|---|
| Settings | `swps-settings` | All plugin configuration |
| Generate Content | `swps-generate` | One-off post generation UI |
| Voice Profiles | `swps-voice-profiles` | Manage reusable personas |
| SEO Audit | `swps-seo-audit` | 8-module audit results + auto-fix |
| Search Appearance | `swps-search-appearance` | Title/description templates |
| Analytics | `swps-analytics` | Unified dashboard |
| Keywords | `swps-keywords` | Keyword research + GSC tracking |
| Calendar | `swps-calendar` | Visual content calendar |
| Topic Queue | `edit.php?post_type=swps_topic` | Topic queue (custom post type) |
| Internal Links | `swps-internal-links` | Link suggestions admin |
| Sitemaps | `swps-sitemaps` | Sitemap status + IndexNow ping |
| Redirects | `swps-redirects` | Redirect manager + 404 log |
| Debug | `swps-debug` | Last failed AI response (raw + cleaned) |

---

## WP-CLI Commands

```bash
# Generate a post
wp swps generate                                    # AI-chosen topic
wp swps generate "WordPress security best practices"
wp swps generate "How to speed up WordPress" --template=how-to

# Analyze site
wp swps analyze                  # JSON
wp swps analyze --format=table

# Plugin status (version, provider, model, schedule, costs, queue size)
wp swps status

# Manage topic queue
wp swps queue list
wp swps queue add "Topic title"
wp swps queue add "Topic" --date="2026-04-01 09:00:00" --template=listicle
wp swps queue remove 123
wp swps queue clear
```

Available templates: `auto`, `listicle`, `how-to`, `comparison`, `case-study`, `news`, `tutorial`

---

## REST API

All endpoints under `/wp-json/swps/v1/`:

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/voice-profiles` | List voice profiles |
| `POST` | `/voice-profiles` | Create voice profile |
| `PUT` | `/voice-profiles/{id}` | Update voice profile |
| `DELETE` | `/voice-profiles/{id}` | Delete voice profile |
| `POST` | `/generate` | Generate a post (topic + template) |
| `GET` | `/audit` | Run full SEO audit |
| `GET` | `/analytics/summary` | Aggregate analytics for date range |

All endpoints require `manage_options` capability and `X-WP-Nonce` header.

---

## Developer Reference

StrataWP SEO provides 30+ filters and actions via `SWPS_Hooks`. All hooks use the `swps_` prefix.

### Filters

#### Content Generation

**`swps_system_prompt`** — Modify the system prompt sent to the AI.

```php
add_filter( 'swps_system_prompt', function( string $prompt, string $tone, string $style ): string {
    $prompt .= "\nAlways include a call-to-action at the end.";
    return $prompt;
}, 10, 3 );
```

**`swps_user_prompt`** — Modify the user prompt sent to the AI.

```php
add_filter( 'swps_user_prompt', function( string $prompt, string $topic, string $site_context ): string {
    return $prompt;
}, 10, 3 );
```

**`swps_ai_response`** — Modify the parsed AI response before post creation.

**`swps_post_data`** — Modify WordPress post data before insertion.

#### llms.txt

**`swps_llms_txt_content`** — Modify the full llms.txt body before output.

```php
add_filter( 'swps_llms_txt_content', function( string $content ): string {
    return $content . "\n## Custom Section\n- ...\n";
} );
```

**`swps_llms_txt_post_limit`** — Number of posts included (default 100).

#### Voice Profiles

**`swps_voice_compile`** — Modify the compiled voice-profile prompt block.

#### Images

**`swps_image_query`** — Modify the image search query.

**`swps_content_images_queries`** — Modify in-content image search queries.

**`swps_image_selection`** — Modify selected image data before insertion.

#### Schema

**`swps_schema_article`** — Modify Article JSON-LD before output.

```php
add_filter( 'swps_schema_article', function( array $schema, int $post_id ): array {
    $schema['author']['sameAs'] = 'https://twitter.com/yourhandle';
    return $schema;
}, 10, 2 );
```

**`swps_schema_breadcrumb`** — Modify BreadcrumbList JSON-LD.

**`swps_schema_organization`** — Modify Organization/Person JSON-LD.

**`swps_faq_schema`** — Modify FAQ JSON-LD.

**`swps_takeaways_schema`** — Modify Key Takeaways ItemList JSON-LD.

#### SEO Audit

**`swps_audit_modules`** — Add or remove audit modules.

```php
add_filter( 'swps_audit_modules', function( array $modules ): array {
    $modules['my_custom_check'] = new My_Custom_Audit_Module();
    return $modules;
} );
```

**`swps_audit_result`** — Modify an individual module's audit result.

#### Analytics

**`swps_analytics_track`** — Filter tracking data before storage. Return empty array to block.

**`swps_analytics_exclude`** — Filter whether to exclude a page from tracking.

**`swps_gsc_data`** — Filter Google Search Console API response data.

#### SEO Meta Editor

**`swps_meta_title`** — Filter the meta title output for a post.

**`swps_meta_description`** — Filter the meta description output for a post.

**`swps_meta_robots`** — Filter the robots meta directives for a post.

**`swps_seo_checklist`** — Filter or add SEO checklist items in the metabox.

#### Keywords

**`swps_keyword_suggestions`** — Filter AI-generated keyword suggestions.

#### Content Scoring

**`swps_score_weights`** — Adjust content scoring weights.

### Actions

**`swps_before_generate`** — Fires before content generation starts.

**`swps_after_generate`** — Fires after successful generation.

**`swps_post_created`** — Fires after the WordPress post is inserted.

```php
add_action( 'swps_post_created', function( int $post_id, array $ai_result, array $post_data ): void {
    // Add custom meta, trigger workflows
}, 10, 3 );
```

**`swps_generation_failed`** — Fires when generation fails.

**`swps_score_complete`** — Fires after content scoring completes.

**`swps_image_inserted`** — Fires after an in-content image is inserted.

**`swps_audit_complete`** — Fires after a full audit run completes.

```php
add_action( 'swps_audit_complete', function( array $results, int $overall_score ): void {
    if ( $overall_score < 50 ) {
        wp_mail( get_option( 'admin_email' ), 'SEO Score Alert', 'Score dropped to ' . $overall_score );
    }
}, 10, 2 );
```

---

## Frequently Asked Questions

### Which AI provider should I use?

Anthropic (Claude) is recommended for the best content quality. Opus 4.7 for highest quality, Sonnet 4.6 for the best price/performance, Haiku 4.5 for high-volume cheap generation. OpenAI and Google are also excellent alternatives.

### Will this plugin conflict with Yoast SEO or RankMath?

No. Schema and meta-tag output **automatically disable** themselves when Yoast, RankMath, or AIOSEO is detected. Sitemaps, content generation, audit, redirects, breadcrumbs, analytics, and AI Crawlers/llms.txt features all work alongside any SEO plugin.

### Does the AI generate unique content?

Yes. Each generation is original, based on your site context. The optional duplicate detection (configurable threshold) prevents content too similar to existing posts.

### Can I review posts before they go live?

Yes — set **Post Status** to "Draft" (recommended) or "Pending Review". Set a **Minimum Content Score** to automatically hold low-quality generations as drafts.

### How much does it cost to run?

The plugin is free. You pay only for AI API usage. A typical 1,500-word post costs **$0.01-0.05** depending on provider/model. Enable **Cost Tracking** to monitor usage.

### My generation failed with a JSON parse error — how do I debug?

Visit **StrataWP SEO → Debug**. The plugin saves the full raw AI response (and the cleaned version) to a transient on every parse failure, so you can see exactly what the model returned.

### Can I extend the SEO audit with custom checks?

Yes — use the `swps_audit_modules` filter to register your own module extending `SWPS_Audit_Module`.

### Does the schema markup support custom post types?

Article schema outputs on the standard `post` type only. Breadcrumb schema works on all post types and taxonomies. Use `swps_schema_article` to extend.

### Is the on-site analytics GDPR compliant?

Yes. Cookie-free, no external services, no personal data stored. No consent banner needed.

### Do I need Google Search Console credentials?

No — GSC integration is optional. On-site analytics works entirely without it.

### What does the AI Crawlers feature actually do?

Two things: (1) writes `Allow: /` rules in robots.txt for the AI bots you've checked, and (2) serves a dynamic `llms.txt` at `/llms.txt` — a markdown index of your site that AI agents read instead of crawling everything. This dramatically improves how often your content shows up in ChatGPT, Claude, Perplexity, and Google AI Overviews.

### How does IndexNow batch submission work?

Click **Sitemaps → Ping Search Engines** and the plugin submits your 50 most recently modified posts to `api.indexnow.org`. Bing, Yandex, and Seznam pick these up within minutes — and Bing's IndexNow feed is what powers ChatGPT's web search.

---

## Changelog

### 3.7.8
- IndexNow batch submission replaces the dead Google/Bing sitemap ping endpoints (retired in 2023). Ping now submits 50 most recent posts and reports real status.
- Fixed Sitemaps admin page: `[object Object]` ping result, blank Settings tab (missing `data-tab` attributes), permanent "Loading..." (wrong nonce object reference).

### 3.7.7
- Sitemaps admin: fixed nonce reference (`swps_admin` → `swpsAdmin`), added `swps-admin` JS dep, fixed tab content `data-tab` attributes.

### 3.7.6
- New **AI Crawlers** settings section with multi-checkbox allowlist for 15 known AI bots (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Applebot-Extended, CCBot, Meta-ExternalAgent, Bytespider, Amazonbot, DuckAssistBot, etc.)
- New `SWPS_AI_Bots` class hooks `robots_txt` to inject `Allow`/`Disallow` rules.
- Dynamic **llms.txt** generator served at `/llms.txt` with site description intro, posts/pages/categories, and one-line summaries from meta description → excerpt → trimmed first paragraph.
- New `multi_checkbox` settings field type and `default` arg support for checkboxes.

### 3.7.5
- New **Debug** admin page (StrataWP SEO → Debug) showing the last failed AI response (raw + cleaned) for diagnosis.
- AI JSON parser: 5th repair attempt combining quote repair + control-char sanitization. Strips Unicode line/paragraph separators (U+2028/U+2029) up front. Persists raw response to a transient on parse failure.

### 3.7.4
- Added **Claude Opus 4.7** to the model dropdown (released April 2026).
- Cost tracker pricing entry for Opus 4.7.

### 3.7.3
- Fixed JSON prefill incorrectly enabled for Claude 4.6+ models. Broadened the "no prefill" check to cover all 4-6, 4-7, 4-8, 4-9, 5-x model ID patterns. Resolves "This model does not support assistant message prefill" error.

### 3.7.0–3.7.2
- Admin visual refresh — Slate & Coral palette across calendar, modals, SERP preview, progress, all component colors. Polish on stats grid, result cards, and previews. Responsive styles + loading states.
- Chart.js v4 fixes (CDN version pinning, canvas height constraint to prevent infinite scroll).
- Updated AI model defaults.

### 3.6.x
- Redirects, sitemaps, and internal linking system added.

### 3.0.0 — Yoast Replacement
- **Full Sitemap System** — Sitemap index with post type, taxonomy, and author sub-sitemaps. Per-URL priority/changefreq control. Image sitemap entries. IndexNow support.
- **Search Appearance** — Title/description templates with template variables. Title separator picker.
- **Taxonomy & Archive SEO** — Per-term meta on category/tag/taxonomy edit screens.
- **Redirect Manager** — 301/302/307/410 with exact and regex matching. 404 monitoring with one-click redirect creation. Auto-redirect on slug change.
- **Frontend Breadcrumbs** — HTML breadcrumbs with inline schema. Template function, shortcode, configurable separator.
- **RSS Feed Optimization** — Configurable before/after content with template variables.
- **wp_head Cleanup** — Toggle removal of generator tag, RSD, shortlink, REST API link, oEmbed, emoji scripts.

### 2.3.0
- **Keyword Research & Tracking** — AI keyword suggestions, GSC rank tracking, striking distance opportunities (positions 8-20).
- **SEO Meta Editor** — Per-post meta title/description, social previews, robots controls, live SERP preview, character counters, SEO checklist with focus keyword analysis, AI Generate button.
- 5 new developer hooks for meta/keyword extensibility.

### 2.2.0
- **On-site analytics** — page views, time on page, scroll depth, bounce rate (cookie-free, GDPR-friendly).
- **Google Search Console OAuth** — clicks, impressions, CTR, position.
- **Unified analytics dashboard** with charts, metric cards, date range filtering.
- **Per-post analytics metabox**, sortable Views (30d) column on posts list.
- Configurable retention (30/90/180/365 days), 3 new analytics hooks.

### 2.1.0
- Schema / Structured Data (Article, Breadcrumb, WebSite, Organization/Person JSON-LD).
- Yoast/RankMath/AIOSEO conflict detection.
- 7 schema settings fields with developer filter hooks.

### 2.0.0
- **Technical SEO Audit** with 8 modules (Canonical, Sitemap, OG, Twitter, Robots.txt, Meta Robots, Image SEO, Page Speed).
- Auto-fix for canonical tags, OG/Twitter meta, sitemap generation.
- Dashboard widget with site health score.
- Voice profiles, in-content image insertion, content scoring with quality gate.
- Topic queue, content calendar, cost tracking, rate limiting, duplicate detection.
- Bulk generation (up to 5 posts), WP-CLI commands, REST API.
- Multi-provider AI and image support, 7 content templates, Key Takeaways with schema.
- 20+ developer hooks.

### 1.0.0
- Initial release. AI content generation with Anthropic Claude. Featured images via Unsplash. FAQ schema. Internal linking. Scheduled publishing.

---

## License

StrataWP SEO is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
