# StrataWP SEO

<p align="center">
  <a href="https://github.com/JonImmsWordpressDev/stratawp-seo/releases/latest/download/stratawp-seo.zip">
    <img alt="Download the plugin (latest release)" src="https://img.shields.io/badge/⬇%20Download%20the%20plugin-Install%20on%20WordPress-10b981?style=for-the-badge&labelColor=059669" />
  </a>
</p>

<p align="center">
  <sub>One-click download of the latest production-ready zip. Always points to the most recent release —
  rebuilt automatically on every push.</sub>
</p>

<p align="center">
  <a href="https://github.com/JonImmsWordpressDev/stratawp-seo/releases">All releases</a> ·
  <a href="#installation">Install instructions</a> ·
  <a href="#how-to-guide-page-by-page">How-to guide</a>
</p>

---

**AI-powered SEO content generator that knows your WordPress site.** Generate optimized blog posts with internal linking, structured data, sitemaps, redirects, AI-crawler access control, llms.txt, on-site analytics, GSC integration, a per-post meta editor, **Local SEO** (LocalBusiness schema with NAP and opening hours), **Image SEO** (auto-alt + filename sanitization + lazy-load), **Crawlers & Files** (in-admin editor for /llms.txt and /robots.txt), and **Backlinks** (manual/CSV-import tracker with daily health monitoring) — on autopilot or on demand.

[![Version](https://img.shields.io/badge/version-4.6.5-blue.svg)]()
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)]()
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)]()
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-FFDD00?logo=buymeacoffee&logoColor=black)](https://buymeacoffee.com/jonimmswordpressdev)

---

## Table of Contents

- [What This Plugin Does](#what-this-plugin-does)
- [Features](#features)
- [Architecture](#architecture)
- [How Each Subsystem Works](#how-each-subsystem-works)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [How-To Guide: Page by Page](#how-to-guide-page-by-page)
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
- **AI Bot Analytics** — server-side hit tracking for 15 known AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Applebot-Extended, Bytespider, etc.) with per-bot dashboards, top crawled pages, AEO gap report, and 404 monitoring

### Developer Features
- **30+ filters and actions** — extend every part of the pipeline
- **WP-CLI** commands (`wp swps generate|analyze|status|queue ...`)
- **REST API** — programmatic access to generation, voice profiles, audit, analytics
- **Encrypted secret storage** — sensitive options (GSC client secret, remote endpoint secret) encrypted at rest
- **Debug page** — last failed AI response saved to a transient and viewable from the admin

### AI Auto-Optimize (v4.1) ★

- **Re-score all published posts** — chunked (10 posts per request) with live progress so big sites don't hit PHP timeouts
- **AI proposals** — concrete `find/replace` edits, plus optional new meta title/description and focus keyword, plus a projected score
- **Diff review modal** — see old vs. new for each edit, check/uncheck individual changes before applying
- **One-click apply** — only accepted edits are applied; the post is automatically re-scored after
- **Threshold control** — surface only posts below your score floor (default 75)
- **Per-row dismiss** — hide posts you don't want optimized

### AEO Optimize (v4.6) ★

- **AEO Score with 4 dimensions** — Extractability (paragraphs that quote well + lists/tables/definitions), Markup (Q&A density + correct schema type), Authority (byline + dates + authoritative outbound links + freshness), Coverage (optional LLM-based topic completeness + entity clarity)
- **Bulk AEO Optimize page** — re-scan all posts (chunked, free since 3 of 4 dimensions are heuristic), threshold filter, queue of low-scoring posts, per-row AI proposal with diff review, apply with snapshot/undo
- **Live editor panel** — Gutenberg sidebar plugin + classic-editor metabox show the current score and 4 sub-scores in real time, with one-click "Re-score" and "Improve AEO"
- **Dynamic schema generation** — HowTo, Recipe, Product, Review, QAPage JSON-LD generated by AI based on post content, validated against schema.org required fields, rendered on the front-end (defers to Yoast / RankMath / AIOSEO when those are active)
- **6 REST endpoints** under `/swps/v1/aeo/*` for external/programmatic access
- **5 filter hooks** for extensibility
- **Dashboard tile** showing average AEO score and % above threshold
- **Deep-linked from AI Bot Analytics** — one-click "Optimize for AEO →" from any gap-report row

### Local SEO (v4.2) ★

- **30+ business types** — LocalBusiness, Restaurant, Plumber, Dentist, Hotel, RealEstateAgent, Attorney, AccountingService, BeautySalon, etc.
- **NAP fields** — name, address (street/city/region/postal/country), phone, email, price range
- **Opening hours per day** — 24-hour-format inputs, with a "Closed" tick for off-days
- **Geo coordinates** — latitude/longitude for `GeoCoordinates` schema
- **Area served** — newline-separated list of cities/regions for service-area businesses (plumbers, electricians)
- **Google Place ID** — emits a `hasMap` link when set
- **LocalBusiness JSON-LD** on the homepage with `OpeningHoursSpecification`, `PostalAddress`, `GeoCoordinates`, and the existing `swps_schema_logo` + `swps_schema_social_profiles` woven in as `image`/`logo`/`sameAs`
- **Schema preview** — live JSON-LD preview on the settings page so you can validate before publishing
- **Coexists with Yoast / RankMath / AIOSEO** — defers automatically when those plugins are active

### Image SEO (v4.2) ★

- **Auto-alt on upload** — heuristic mode (free, instant — derives alt from filename + parent post title) or AI mode (uses your configured AI provider for a one-line description; falls back to heuristic on error)
- **Filename sanitization** — strips device prefixes (`IMG_`, `DSC_`, `Screenshot`), lowercases, dash-separates, on the way into the Media Library
- **Lazy-loading enforcement** — adds `loading="lazy" decoding="async"` to any post-content `<img>` still missing it
- **Bulk fix tool** — page-level button that processes the existing library in 20-image AJAX batches with live progress
- **Stats tiles** — total images, missing alt, % covered

### Crawlers & Files (v4.2) ★

- **Edit /llms.txt** — toggle between **Auto** (StrataWP-generated default built from posts/pages/categories) and **Custom** (paste your own markdown, served verbatim)
- **Edit /robots.txt** — toggle between **Auto** (WP default + AI-bot allow/disallow), **Append** (auto + your extra rules), or **Replace** (your content only)
- **Side-by-side editor + preview** — write your version on the left, see the auto-generated default on the right; one-click "copy auto into editor" to use the default as a starting point
- **Physical-file warning** — detects `robots.txt` in the site root and warns that it overrides the dynamic version
- **Hooks into the existing pipeline** — custom mode rides the existing `swps_llms_txt_content` filter; robots filter runs at priority 200 so AI-bot rules added at priority 100 are preserved in Append mode

### Backlinks (v4.2) ★

- **Manual + CSV import** — add backlinks one at a time, or paste/upload a CSV (one URL per line, source/target/anchor CSV, or Google Search Console "Top linking sites" export — all auto-detected)
- **Daily health monitoring** — WP-cron re-fetches every source page once a day, parses anchors, classifies as **Live** / **Lost** / **Broken** (HTTP error)
- **Real anchor text + first/last seen** — captured on every successful verify
- **AJAX bulk verify** — "Verify all" runs in 25-row batches with live progress
- **Per-row re-verify and delete**
- **CSV export** — full table export for offline analysis or to import into another tool
- **Stats tiles** — total tracked, live, lost, broken, +30d gained, 30d lost, unique referring domains
- **Dashboard widget** — live count + 3 most-recently-verified backlinks with status pills
- **No paid index required** — tracks links you give it; pair with the GSC "Top linking sites" CSV for a real-world starting set

### Competitor Watch (v4.1) ★

- **Track up to 10 competitor sites** — paste URL, get auto-discovered RSS / Atom feed, plus homepage + sitemap.xml fallback
- **Daily WP-cron auto-scan** — fetches each site's content at most once per day with polite delays between requests
- **Per-row "Scan now"** — manual refresh whenever you want
- **Diff detection per scan** — new posts since last snapshot, new JSON-LD schema types, title/H1 changes
- **Content velocity** — posts-per-week trend across the snapshot history (last 12 snapshots, ~12 days at daily cadence)
- **Dashboard widget** — 3 most-recently-changed competitors with a one-line summary on the dashboard

### Migration Tool (v4.3 / v4.4) ★

- **Import from Yoast SEO, Yoast SEO Premium, Rank Math, and Rank Math Pro** — auto-detects each source plugin by scanning postmeta + options, shows post counts so users know what they're about to migrate
- **Per-post meta** (Phase 1, v4.3.0) — meta title, meta description, focus keyword, canonical URL, breadcrumb title, social title/description/image. First non-empty source wins, streamed in 100-post batches via SQL so it scales to large sites without timing out
- **Global settings** (Phase 2, v4.4.0) — title separator (Yoast `sc-dash` / `sc-ndash` / etc. codes decoded to literal characters), title templates per post type / taxonomy / archive (author, date, search, 404, post-type-archive), per-post-type noindex flags. Rank Math's single-percent variables (`%title%`, `%sep%`, `%sitename%`, `%term%`, …) are rewritten to StrataWP's `%%var%%` syntax with longest-match-first ordering
- **Redirects** (Phase 3, v4.4.0) — Yoast Premium reads `wpseo-premium-redirects-base` (and the regex variant), Rank Math Pro reads `{prefix}rank_math_redirections` for `status='active'` rows and expands the serialized `sources` array. All inserts go through `SWPS_Redirect_Manager::add_redirect()` so validation/normalization matches manually-created redirects; 301/302/307/410 supported, regex flag preserved
- **Phase checkboxes** — pick any combination of post meta / settings / redirects per run
- **Preview before run** — counts what would change without writing
- **Conflict policy** — when StrataWP already has a value, choose Skip-existing or Overwrite (per run)
- **One-click Undo** — typed backup snapshot reverts post meta (restores previous values or deletes if they didn't exist), restores option values, and DELETEs the redirect rows the migration inserted, all in one click

### Admin Shell (v4.0)

- **Branded shell** — top bar (logo, breadcrumb, search, theme toggle, help) sits above every plugin page
- **Dark mode by default** with light mode via the toggle (per-user preference, persisted)
- **Emerald & Teal palette** — brand identity (v4.1.4 — moved off the original gold/black; semantic colors are distinct from brand to keep status pills readable)
- **Data-dense template** — used by Audit, Redirects, Internal Links, Auto-Optimize, Competitors. Consistent row-card pattern with status dot + name + meta + actions

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
│   ├── class-migration.php            Import settings + post meta + redirects from Yoast / Rank Math
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
│   ├── migration-page.php
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

## How-To Guide: Page by Page

Every admin page in the plugin, what it does, and exactly how to use it. Pages are listed in the order they appear in the StrataWP admin sidebar.

### Dashboard (`StrataWP SEO`)

The landing page. Shows site health score, recent generations, AI cost (30d), post views, top GSC queries, top issues, the **Auto-Optimize queue preview**, and the **Competitor watch widget**. Each tile links to its source page — click "Manage →" on Competitor watch to jump straight to managing tracked sites.

**How to use it:** load it daily as a temperature check. The welcome line summarizes site health, fixable issues, and 30-day AI spend in one sentence. The KPI tiles use the brand emerald gradient for the four primary numbers. The modules grid at the bottom lets you toggle features on/off — turn off Sitemaps if you're using Yoast/RankMath's sitemap, for example.

### Generate Content (`Create → Generate`)

The main AI content generator.

1. Pick a **template** (Auto, Listicle, How-To, Comparison, Case Study, News Analysis, Tutorial)
2. Enter a **topic** (or leave blank to auto-pick from queue)
3. Optionally select a **voice profile** to lock the tone
4. Set **target word count** (or use the global default)
5. Click **Generate** — it'll analyze your site, draft, score, attach images, and save as a draft

**Tip:** if generation takes >2 min, the post is being processed in the background. Check the Calendar to see status.

### Calendar (`Create → Calendar`)

Visual month-view of every scheduled and generated post. Color-coded by status (draft, scheduled, published). Click any cell to see the post detail or jump to the editor.

**How to use it:** drag-and-drop is not supported (yet) — to reschedule, edit the post and change its date.

### Topic Queue (`Create → Topic Queue`)

A custom post type that holds **upcoming topics** for auto-publish. Each entry has a topic, target template, scheduled date, and notes. The cron job picks the next due topic, generates the post, and marks the queue entry as used.

**How to use it:**
1. Add 10-20 topic ideas with target dates
2. **Settings → Auto-Publishing Schedule**: pick "Daily" / "Weekly" / etc.
3. Walk away — the cron generates posts automatically on schedule

### Auto-Optimize (`Create → Auto-Optimize`) ★ v4.1

Finds underperforming published posts, generates AI proposals to fix them, and applies the edits with one click. Manual review queue — every edit is reviewed before it touches your content.

**How to use it:**
1. Click **Re-scan all posts** — scores all published posts/pages in batches of 10 (live progress bar). Posts below the threshold (default 75) appear in the queue.
2. For each row, click **Generate proposal** — AI returns 2-6 concrete `find/replace` edits, plus optional new meta title/description and focus keyword, plus a projected score
3. Click **Review** on a proposal — see the diff (red = old, green = new), check/uncheck individual edits, click **Apply selected edits**
4. Re-score happens automatically after apply — you'll see the new score

**Threshold tuning:** raise the threshold (e.g. 85) to surface more posts, lower it (e.g. 50) to focus only on the worst.

**Dismissing:** the trash icon hides a post from the queue permanently (snapshot history is kept; un-dismiss via WP-CLI).

### Voice Profiles (`Create → Voice Profiles`)

Reusable writing personas. Each profile sets tone, formality (1-10), preferred sentence length, vocabulary level, point of view, avoid/preferred phrases, and a sample text the AI emulates.

**How to use it:**
1. **Add new** → name it (e.g., "Friendly authority", "Conversational developer")
2. Paste a **sample paragraph** that matches the tone you want
3. Mark it **Default** if it should apply to every Generate without picking explicitly
4. In Generate Content, the dropdown lets you override per-post

### SEO Audit (`Optimize → Audit`)

8-module technical SEO check. Each module reports pass/warn/critical with auto-fix where possible.

**How to use it:**
1. Click **Run audit now** — takes 2-10 seconds
2. Each module card shows status; click **Auto-fix** on any module that has it (canonical, OG, Twitter, sitemap)
3. **CSV export** for client reporting

Schedule daily/weekly/monthly runs from **Settings → SEO Audit**. The dashboard widget shows the latest health score.

### Schema (`Optimize → Schema`)

Configures structured data output (JSON-LD).

**How to use it:**
1. Pick **Article schema type** — Article, BlogPosting (default), or NewsArticle
2. **Organization or Person** — pick which entity represents your site
3. Add **logo URL** and **social profiles** (Twitter, Facebook, LinkedIn, etc.) — these become `sameAs` in the Organization schema
4. Toggle **WebSite SearchAction** to enable Google sitelinks searchbox

**Conflict detection:** auto-disables if Yoast/RankMath/AIOSEO is active. Enable the override toggle if you want StrataWP's schema regardless.

### Sitemaps (`Optimize → Sitemaps`)

Configures `/sitemap_index.xml` and sub-sitemaps.

**How to use it:**
1. **Settings tab:** toggle individual post types/taxonomies in/out of the sitemap, set per-type priority and changefreq
2. **Submit tab:** the **Ping Search Engines** button submits 50 most-recently-modified posts to IndexNow (Bing/Yandex/Seznam — Bing's feed powers ChatGPT search). Use after a major content push.
3. **View raw:** click "View sitemap" to see the live XML

### Redirects (`Optimize → Redirects`)

Manages 301/302/307/410 redirects and 404 monitoring.

**How to use it:**
1. **Add a redirect:** From URL → To URL → pick status code → Save
2. **Regex tab** for pattern-based redirects (`^/old/(.*)$ → /new/$1`)
3. **404 Log tab:** every unhandled 404 shows up here; click **Create redirect** to fix it in one click
4. **Auto-redirect on slug change:** enabled by default — when you change a post's slug, a redirect from the old slug is created automatically

### Internal Links (`Optimize → Internal Links`)

Suggests internal links from existing posts to other relevant posts on your site. Two engines:

- **Keyword engine:** finds anchor opportunities by keyword match
- **AI engine:** uses your AI provider for semantic suggestions

**How to use it:**
1. Pick a target post
2. Click **Find suggestions** — engine returns candidate anchors with confidence scores
3. Accept or skip each one — accepted links are inserted into the post body

### Search Appearance (`Optimize → Search Appearance`)

Controls how titles and descriptions appear in search results.

**How to use it:**
1. Set **separator** (`|`, `–`, `·`, etc.)
2. Edit per-content-type templates with variables: `%title%`, `%sitename%`, `%sep%`, `%category%`, `%author%`, `%date%`, etc.
3. Per-post overrides are still available in the post editor's **SEO Meta Editor** metabox

### Analytics (`Insights → Analytics`)

Cookie-free on-site analytics + GSC dashboard.

**How to use it:**
1. **Date range filter** at the top: 7 / 30 / 90 days
2. **Top stats:** Pageviews, Unique visitors, Avg time, Bounce rate
3. **Charts:** Pageviews over time, top posts, top referrers
4. **GSC tab:** clicks, impressions, CTR, position (requires OAuth setup in Settings → Analytics)

### Keywords (`Insights → Keywords`)

Keyword research + rank tracking.

**How to use it:**
1. **Generate keywords:** enter a seed topic, AI suggests 10-50 related keywords
2. **Track keywords:** add to the watchlist — GSC syncs daily and shows position trends
3. **Striking distance:** the "8-20" tab surfaces ranking opportunities — keywords you're already on page 2 for that could be pushed to page 1 with light optimization

### Search Console (`Insights → Search Console`)

Connects Google Search Console for search data.

**How to use it:**
1. **Settings → Analytics:** paste GSC OAuth client ID + secret
2. Click **Authorize** → sign into Google → confirm scope → return
3. Pick the verified property
4. Data syncs daily; available in Dashboard, Analytics, and Keywords pages

### Competitors (`Insights → Competitors`) ★ v4.1

Tracks competitor sites for content velocity, schema diff, title/H1 changes.

**How to use it:**
1. Click **Add competitor** → paste the URL → optional label → Save
2. Click **Scan now** on the row — fetches their RSS (auto-discovered or common-path), homepage, and falls back to sitemap.xml if no feed
3. Daily WP-cron auto-rescans every tracked site
4. **+N new** pill on a row indicates new posts since last scan (click to expand the list)
5. **Schema:** chips show their JSON-LD types; new types are highlighted with a `+ NEW` indicator
6. **Title/H1 changed** pills appear when their homepage `<title>` or `<h1>` flipped between scans
7. Source pill (RSS / SITEMAP) shows which data source the scan succeeded with

**Limit:** 10 competitors. **Storage:** last 12 snapshots per competitor (~12 days of daily history). **Out of scope:** keyword-gap analysis (needs paid backlink API).

### Local SEO (`SEO → Local SEO`) ★ v4.2

LocalBusiness JSON-LD output for brick-and-mortar and service-area sites. Lives at `admin.php?page=swps-local-seo`.

**How to use it:**
1. Tick **Enable Local SEO schema output** at the top of the page. Schema only emits when this is on AND a business name + city are filled.
2. Pick the most specific **Business type** that matches your business — Restaurant, Plumber, Dentist, Hotel, etc. (defaults to generic LocalBusiness). The picker covers 30+ schema.org sub-types.
3. Fill **Business identity**: name, phone, email, price range (`$`/`$$`/`$$$`/`$$$$` or a range like `$10-30`).
4. Fill **Address** fields: street, city, state/region, postal code, country (two-letter ISO preferred — `US`, `GB`, `AU`).
5. **Map & geo (optional but recommended for ranking):** open Google Maps, right-click your location, click the lat/lng to copy, paste into the Latitude/Longitude fields. Optionally paste your **Google Place ID** to emit a `hasMap` link.
6. **Opening hours:** for each day, set Open and Close in 24-hour format (`09:00`, `17:30`), or tick **Closed** for off-days. Days without hours are omitted from the schema.
7. **Area served** (service-area businesses): paste one city/region per line, up to 25.
8. Click **Save**. A **Schema preview** card renders at the bottom showing the exact JSON-LD that will be injected into your homepage `<head>`.
9. Verify the live output with Google's [Rich Results Test](https://search.google.com/test/rich-results) once the homepage is published.

**Coexistence:** if Yoast / RankMath / AIOSEO are active, StrataWP defers all schema output to them — turn this off and use their LocalBusiness panel instead.

### Image SEO (`SEO → Image SEO`) ★ v4.2

Auto-fills missing alt text, sanitizes filenames on upload, and enforces lazy-loading. Lives at `admin.php?page=swps-image-seo`.

**How to use it:**
1. Open the page — the **stats tiles** at the top show total library size, count missing alt text, % covered, and current auto-alt mode.
2. **Bulk fix existing library:** click the **Fix missing alt text** button to write alt for every image that doesn't have any. Runs in 20-image batches via AJAX so you can leave the tab open while it works. Uses the auto-alt mode you have selected below.
3. **Auto-alt on upload** card:
   - Tick **Generate alt text automatically when an image is uploaded** to enable.
   - Pick **Heuristic** (free, instant — derives alt from filename + parent post title) or **AI** (sends filename + post context to your configured AI provider for a one-line description; falls back to heuristic on error or quota).
4. **Filename sanitization** card: tick to clean filenames on upload — `IMG_4523 Vacation.JPG` becomes `vacation.jpg`. Strips device prefixes (`IMG_`, `DSC_`, `DSCN`, `Screenshot`, etc.).
5. **Lazy loading** card: tick to add `loading="lazy" decoding="async"` to any post-content `<img>` still missing those attributes. Improves Core Web Vitals.
6. Click **Save Image SEO settings**.

**Tips:** AI mode costs a fraction of a cent per image (one short call to your configured provider). For most sites, Heuristic mode is plenty good — it's especially strong when your filenames already have meaningful slugs (e.g. `seo-checklist-2026.jpg`).

### Crawlers & Files (`SEO → Crawlers & Files`) ★ v4.2

Edits the dynamic `/llms.txt` and `/robots.txt` files served by your site. Lives at `admin.php?page=swps-crawl-files`.

**How to use the /llms.txt editor:**
1. Pick a **mode**:
   - **Auto** — serve the StrataWP-generated default (built from your most recent 100 posts, top-level pages, and busiest 20 categories with one-line summaries). This is the recommended default.
   - **Custom** — serve the markdown you paste below, verbatim.
2. The page shows two textareas side-by-side: **Your llms.txt** (editable) on the left and **Auto preview** (read-only) on the right.
3. Click **↑ Copy auto-generated content into the editor** to start from the auto version, then tweak.
4. Save — visit `/llms.txt` or click **View /llms.txt** in the page header to confirm.

**How to use the /robots.txt editor:**
1. If a physical `robots.txt` exists in your site root, you'll see a warning — that file always overrides the WordPress dynamic version. Delete it to use the editor.
2. Pick a **mode**:
   - **Auto** — what WordPress would normally serve, plus the AI-bot Allow/Disallow rules StrataWP adds.
   - **Append** — Auto + your extra rules (good for adding `Disallow: /private/` or extra `Sitemap:` entries).
   - **Replace** — serve only your content (full manual control — be careful, since this skips the AI-bot rules).
3. Edit your version on the left; auto preview is on the right; "copy auto into editor" works the same way.
4. Save — visit `/robots.txt` or click **View /robots.txt** to confirm.

**Tip:** Append mode is the safest middle ground for most sites — keeps the StrataWP-managed AI bot allowlist intact while letting you add custom rules.

### Backlinks (`Insights → Backlinks`) ★ v4.2

Tracks pages that link to your site, with daily health monitoring. Lives at `admin.php?page=swps-backlinks`. No paid backlink index required.

**How to seed your initial list:**
- **Option A — manual:** use the **Add backlink** form. Paste the source URL (the page linking to you), optionally the target URL on your site and the anchor text, then click **Add & verify**. The page is fetched immediately so you see Live/Lost/Broken right away.
- **Option B — CSV import (recommended):**
  1. Go to Google Search Console → **Links** → **Top linking sites** → click the export button → save as CSV.
  2. In the **Bulk import** card, paste the CSV (or just one URL per line) into the textarea.
  3. Click **Import**. Existing source URLs are skipped automatically. The page reloads with the new rows pending verification.
  4. Click **Verify all** in the page header to fetch every imported row in 25-row AJAX batches.

**How rows get classified:**
- **Live** (green) — page loaded and contains at least one `<a href="...">` whose href hostname matches yours
- **Lost** (amber) — page loaded fine but no link to your site is on it any more
- **Broken** (red) — page returned an HTTP error (4xx/5xx) or timed out; the HTTP code shows in the pill

**Daily routine:**
- A WP-cron job (`swps_backlinks_daily_verify`) re-checks every backlink once per day with 250ms politeness delays between requests
- Anchor text + first/last-seen dates update automatically when a link is found
- Use the **Lost** count as a link-reclamation list — those are sites that used to link to you and now don't

**Per-row controls:** ⟳ re-verifies one row immediately; ✕ deletes it (history is gone — use Export CSV first if you want a backup).

**Export:** the **Export CSV** button in the page header downloads everything (source, target, anchor, status, http code, first seen, last seen, last checked) — useful for offline analysis or migrating to another tool.

**What this is NOT:** this does not *discover* new backlinks (no paid web index). It tracks the list you give it. To discover new ones, paste the GSC export periodically — that's GSC's view of who links to you.

### Settings (`System → Settings`)

The control panel — split into tabs:

- **AI Provider:** API key, model, image provider keys
- **Site Details:** niche, description, language
- **AI Crawlers:** allowlist for 15 known bots, llms.txt toggle
- **Writing Preferences:** tone, formality, word count
- **Content Settings:** templates, FAQ on/off, Key Takeaways on/off, TOC on/off, internal link min/max, default post status
- **Auto-Publishing:** schedule, time of day, post-type
- **SEO Audit:** schedule
- **Schema:** entity type, social profiles, conflict override
- **SEO Meta Editor:** enable per-post fields
- **Keyword Tracking:** GSC OAuth, retention
- **Analytics:** retention period, GDPR options
- **Advanced:** cost tracking, rate limit cooldown, debug logging, encrypted secrets

### Debug (`System → Debug`)

Shows the **last failed AI response** (raw + cleaned) for diagnosing JSON parse failures. If a generation errored, this is the first place to look.

**How to use it:** click "Refresh" to fetch the most recent failure. Both raw and post-repair JSON are shown side-by-side.

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
| Auto-Optimize | `swps-auto-optimize` | Re-score + AI proposals for low-scoring posts |
| Competitors | `swps-competitors` | Competitor site tracker (RSS/sitemap diff) |
| Local SEO | `swps-local-seo` | LocalBusiness JSON-LD: NAP, hours, geo, area served |
| Image SEO | `swps-image-seo` | Auto-alt, filename sanitization, lazy-load, bulk fix |
| Crawlers & Files | `swps-crawl-files` | Edit `/llms.txt` and `/robots.txt` (auto/custom modes) |
| Backlinks | `swps-backlinks` | Manual + CSV-import backlink tracker with daily verify |
| Migrate | `swps-migration` | Import settings + per-post meta + redirects from Yoast / Rank Math |
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

### Can I migrate from Yoast SEO or Rank Math?

Yes — **StrataWP SEO → Migrate** (added v4.3.0, expanded v4.4.0). Imports per-post SEO meta (title, description, focus keyword, canonical, breadcrumb title, social overrides) from Yoast SEO / Yoast Premium / Rank Math / Rank Math Pro, plus global settings (title separator, title templates with variable rewriting, per-post-type noindex) and redirects (Yoast Premium and Rank Math Pro). Preview before run, choose skip-existing or overwrite on conflicts, and one-click Undo restores everything.

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

### Do I need a paid Ahrefs or Moz account to use Backlinks?

No. The Backlinks page tracks the links you provide it (manually or via CSV) and re-checks them daily. Pair it with the Google Search Console "Top linking sites" CSV export to seed a real-world list — that's GSC's view of who links to you, free. A direct paid-partner integration (Ahrefs/Moz/SE Ranking) is on the roadmap for a future release.

### How does Local SEO interact with my existing schema settings?

The Organization/Person schema configured under **System → Settings → Schema** (name, logo, social profiles) keeps emitting on the homepage. The new LocalBusiness schema is a *separate* JSON-LD block, but it reuses your existing logo (`swps_schema_logo`) as the `image`/`logo` property and your social profiles (`swps_schema_social_profiles`) as the `sameAs` property — so you don't have to re-enter that data. If Yoast/RankMath/AIOSEO is active, both blocks defer.

### What's the difference between Image SEO's Heuristic and AI alt modes?

Heuristic is free and instant — it derives alt text from the image filename plus the parent post's title (e.g. an image called `seo-checklist-2026.jpg` attached to a post titled "10 Steps to Better SEO" gets reasonable alt text without any API call). AI mode sends filename + parent context to your configured AI provider for a one-line description; it costs a fraction of a cent per image and is most useful when filenames are device-camera junk like `IMG_4523.JPG`. AI mode automatically falls back to Heuristic on error or quota.

### Will editing /robots.txt in Crawlers & Files break the AI-bot allowlist?

Only if you choose **Replace** mode — that serves your content verbatim with no auto rules. **Append** mode preserves everything (the WP defaults plus the StrataWP-managed AI-bot Allow/Disallow block) and adds your custom rules underneath. Use Append unless you have a specific reason to fully take over.

---

## Changelog

### v4.6.5 — May 2026
- Fix: AEO Optimize "Request failed." alerts now surface the actual server error. The JS `.fail()` handlers were displaying the generic fallback message regardless of what the response body said — so AI-provider errors (rate limit, invalid API key, JSON parse failure) were being swallowed. New `extractErrorMessage()` helper digs into `jqXHR.responseJSON.data.message`, falls back to the HTTP status, and appends a "Check StrataWP SEO → Debug for the raw AI response" hint for likely AI-side failures. Applies to scan / proposal / apply.

### v4.6.4 — May 2026
- Fix: Schema Generator no longer rejects QAPage (and other nested-shape) posts with `empty required field: mainEntity`. The previous prompt told the AI to "use empty arrays / null for fields you cannot derive" — that instruction conflicted with the validator's reject-empty-required-fields behavior. The prompt now explicitly distinguishes REQUIRED (must populate) from RECOMMENDED (omit if not derivable), and includes per-type shape guidance derived from the schema-fields manifest (e.g. `mainEntity should be a Question object with fields: name, text, acceptedAnswer, ...`) so the AI knows how to construct nested objects. Two new tests cover the prompt contents and the regression case.

### v4.6.3 — May 2026
- Developer: AEO asset enqueues now use `filemtime()` for cache-busting instead of `SWPS_VERSION`. Any edit to `admin/css/aeo.css`, `admin/js/aeo-optimizer.js`, or `admin/js/aeo-editor-panel.js` will automatically invalidate browser caches without needing a plugin-wide version bump. Falls back to `SWPS_VERSION` if the file isn't readable (defensive for unusual deploy / symlink setups).

### v4.6.2 — May 2026
- Fix: AEO Optimize queue persists across page navigation. The scored-posts list is now loaded from post meta server-side on every page render (delivered via `wp_localize_script`'s `swpsAeo.initialResults`) so navigating away and coming back no longer shows an empty queue. Re-scan still works the same way (overwrites with fresh scores).

### v4.6.1 — May 2026
- Fix: AEO Optimize admin page no longer renders a blank dark overlay on load. The modal template now uses inline `style="display:none;"` instead of the `hidden` attribute so jQuery's `.show()` / `.hide()` toggle naturally without fighting the modal's `display: flex` layout rule. Asset version bumped to force browser-cache refresh on existing installs.

### v4.6.0 — May 2026
- New: AEO Optimize (score + bulk page + editor panel + dynamic schema for 5 new types)
- New: 6 REST endpoints under `/swps/v1/aeo/*`
- New: 5 filter hooks for extensibility
- Improvement: Dashboard AEO health tile + Bot Analytics deep-link
- Developer: PHPUnit setup for pure-PHP scorer unit tests

### 4.5.1
- **Security/Fix — encryption hardened:** stored API keys and Google Search Console OAuth secrets now use authenticated **AES-256-GCM** (tamper detection) instead of AES-256-CBC.
- **Fix — fail-safe decryption:** `SWPS_Encryption::decrypt()` previously returned the *still-encrypted* blob when decryption failed, so after a WordPress salt rotation a corrupt value was silently sent to AI / Search Console APIs as the credential. It now returns `''` and logs (under `WP_DEBUG`); the settings field renders blank, prompting re-entry.
- **Fix — no plaintext fallback:** `encrypt()` no longer returns the raw secret (storing it unencrypted) if encryption fails.
- **Compatibility:** legacy AES-256-CBC values written by earlier versions are still transparently decrypted and are upgraded to GCM the next time the owning setting is saved. No migration or action required on upgrade.

### 4.5.0
- **New — AI Bot Analytics:** server-side tracking of hits from 15 known AI crawlers (GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-Web, anthropic-ai, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended, CCBot, Meta-ExternalAgent, Bytespider, Amazonbot, DuckAssistBot). New `SWPS_Bot_Analytics_Tracker` captures on `shutdown` priority 999 so the post ID, `is_404()` state, and `http_response_code()` are settled; writes one row per bot request into `{$wpdb->prefix}swps_bot_hits`, then a daily cron rolls anything older than 7 days into `swps_bot_hits_daily` and prunes per `swps_bot_analytics_retention` (default 90). Excludes admin / AJAX / cron / REST / WP-CLI and configurable path prefixes (`/wp-admin`, `/wp-json`, `/feed`, `/xmlrpc.php` by default). Optional 0–100 % sample rate for high-traffic sites via `swps_bot_analytics_sample_rate`. No IP or referrer storage — keeps the GDPR-friendly stance of the existing pageview tracker.
- **New — "AI Crawlers" section on the Analytics page:** total bot hits (with % delta vs prior 30 days), distinct active bots, 404s to bots, per-bot breakdown with last-seen, top crawled pages with 404 counts, AEO gap report (published posts no AI crawler has fetched in 30 days — actionable for sitemap re-submission and internal linking), and recent bot 404s for redirect candidates.
- **New — REST API:** `GET /wp-json/swps/v1/bot-analytics/summary`, `/top-pages` (with optional `bot` filter), `/gaps`.
- **New — WP-CLI:** `wp swps bot-stats [--days=N] [--bot=KEY] [--format=table|json]`.
- **New — Per-post stats:** the post-edit Analytics metabox now includes bot hit counts (7d / 30d) and last-seen, so editors see "GPTBot crawled this 4× last week" next to human pageview stats.
- **New — Extension points:** `swps_ai_bots_known` filter (add custom bots — shared by robots.txt allowlist and analytics), `swps_bot_analytics_capture` (veto a hit), `swps_bot_analytics_normalize_uri` (custom URI bucketing), and `swps_bot_analytics_hit` action (fires per captured hit).

### 4.4.2
- **Fix — Scheduled posts had no images:** WP-Cron-generated posts were being saved without a featured image or in-content images, and nothing was reaching `debug.log`. Root cause was PHP `max_execution_time` killing the worker mid-image-download — AI text + Gemini featured image + 3 in-content Gemini images stack well past the 30–60s default cron limit, and `error_log()` calls inside the catch paths can't fire when PHP dies inside `wp_remote_post`. `SWPS_Generator::generate_post()` now calls `@set_time_limit(0)`, `wp_raise_memory_limit('admin')`, and `ignore_user_abort(true)` before kicking off the pipeline. Manual generation worked already (admin-ajax has its own request context); the fix covers cron, background processor, REST, CLI, and bulk AJAX entry points. Any real provider errors (blocked prompts, missing keys, decode failures) now surface through the existing handlers in `class-gemini-provider.php` instead of being swallowed by a timeout.

### 4.4.1
- **Fix (#24):** Saving the Google (Gemini) API key from the Settings screen had no effect. The same `swps_google_api_key` field was registered in both the AI Provider and Featured Images sections, so the form contained two inputs with the same name. The hidden Featured Images duplicate (empty by default) was submitted last and overwrote the value the user typed in the AI Provider section. The Featured Images section now shows an info row pointing to the AI Provider key instead of duplicating the input.

### 4.4.0
- **Migration tool (StrataWP SEO → Migrate):** new admin page that imports settings from Yoast SEO, Yoast SEO Premium, Rank Math, and Rank Math Pro into StrataWP SEO. Auto-detects installed source plugins by scanning postmeta + options, shows post counts, offers Preview before Run, lets users choose skip-existing vs overwrite, and keeps a typed backup so any migration can be undone with one click.
  - **Per-post meta:** meta title, meta description, focus keyword, canonical URL, breadcrumb title, social title/description/image. First non-empty source wins; streamed in 100-post batches so it scales to large sites.
  - **Global settings:** title separator (Yoast separator codes like `sc-dash` decoded to literal characters), title templates per post type / taxonomy / archive (author, date, search, 404, post-type-archive), per-post-type noindex flags. Rank Math template variables (`%title%`, `%sep%`, `%sitename%`, `%term%`, `%search_query%`, …) are rewritten to StrataWP's `%%var%%` syntax with longest-match-first ordering to avoid partial replaces.
  - **Redirects:** imports active redirects from Yoast Premium (`wpseo-premium-redirects-base` + regex variant) and Rank Math Pro (`wp_rank_math_redirections` table, `status='active'` rows). All inserts go through `SWPS_Redirect_Manager::add_redirect()` for validation; 301/302/307/410 supported; regex flag preserved.
  - **UI:** phase checkboxes (post meta / settings / redirects), preview and report show counts for all three phase types, and the Undo button reverts everything atomically — restores post meta values (or deletes them if they didn't exist before), restores option values, and DELETEs the redirect rows the migration inserted.

### 4.3.0
- **GitHub-based auto-updates:** when a new release is published on the official GitHub repository, all installs see "Update available" on the WordPress Plugins screen and can one-click update directly — no manual zip download needed. Cached for 12 hours to respect GitHub's rate limits.

### 4.2.3
- **Fix — Sitemap Disable/Enable toggle:** button label and row state were stuck because the JS read the wrong response field. Backend exclusion was already honored. (#20)

### 4.2.2
- **Backlinks (Insights → Backlinks):** new manual + CSV-import backlink tracker. Custom DB table, daily WP-cron health verification (live/lost/broken), AJAX bulk-verify with 25-row batches, per-row re-verify and delete, CSV import (auto-detects Google Search Console "Top linking sites" exports), CSV export, dashboard tile + recent-activity list. Replaces the previous "coming soon" placeholder with a real, working feature.

### 4.2.1
- **Crawlers & Files (SEO → Crawlers & Files):** new page to edit `/llms.txt` (Auto / Custom modes) and `/robots.txt` (Auto / Append / Replace modes) directly from the admin. Side-by-side editor + auto preview, one-click "copy auto into editor", warning when a physical robots.txt would override the dynamic version.

### 4.2.0
- **Local SEO (SEO → Local SEO):** LocalBusiness JSON-LD output for brick-and-mortar and service-area sites. 30+ business types (Restaurant, Plumber, Dentist, Hotel, etc.), full NAP fields, opening hours per day, geo coordinates, area served, Google Place ID. Live schema preview. Defers to Yoast/RankMath/AIOSEO when active.
- **Image SEO (SEO → Image SEO):** auto-alt on upload (Heuristic free mode or AI mode using the configured provider), device-prefix-stripping filename sanitization, lazy-loading enforcement on `the_content`, bulk-fix tool for the existing media library with batched AJAX progress, library coverage stats.
- **Modules registry:** Local SEO and Image SEO unlocked with NEW badges; Backlinks added as a "soon" entry; WooCommerce SEO retained as the only remaining "soon" module.
- **Dashboard:** backlinks tiles updated to honest framing ("BYO API key" / "integration in development") prior to 4.2.2 lighting them up with live data.

### 4.1.7
- **Critical fix:** moved `swps_render_optimize_row()` and `swps_render_competitor_row()` declarations to the top of their templates. PHP doesn't hoist conditionally-declared functions, so calling them from the queue/list loop before the `if ( ! function_exists() )` block ran was a fatal once any post had a cached score. Fixes "There has been a critical error" on Auto-Optimize and Competitors pages.

### 4.1.6
- **Auto-Optimize re-scan now chunked** — scans 10 posts per request with live progress (`Re-scoring 30/137 (22%)`). New `swps_optimize_scan_chunk` AJAX endpoint + `score_chunk()` server method. 90s `set_time_limit` per chunk; per-post try/catch so a single corrupt post doesn't kill the batch. Fixes "Request failed" on busy sites.

### 4.1.5
- Auto-Optimize: added `set_time_limit(180)`, `wp_raise_memory_limit('admin')`, `ignore_user_abort(true)`, and a server-side try/catch to surface real errors instead of generic 500s. JS upgraded from `$.post` to `$.ajax` with a 4-min client timeout and clearer fail messages (HTTP status, "timed out").

### 4.1.4
- **Brand palette overhaul** — switched from gold/black to **Emerald → Teal** (`#10B981 → #14B8A6` dark, `#059669 → #0D9488` light). Brand gold was clashing with status warn/info colors that also used gold tints; the new emerald-teal brand sits cleanly alongside the new semantic palette.
- **Status palette refreshed** — green for good (`#22C55E` / `#16A34A`), red for bad (`#EF4444` / `#DC2626`), orange for warn (`#F97316` / `#C2410C`), blue for info (`#3B82F6` / `#2563EB`), violet for AI (`#8B5CF6` / `#7C3AED`), sky for "new" highlights (`#0EA5E9` / `#0284C7`).
- Killed the colored halo on primary CTAs — `--swps-accent-glow` is now a clean depth shadow instead of a yellow neon glow.
- Cleaned up every hardcoded brand-gold rgba in `tokens.css`, `components.css`, `shell.css`, `templates.css`, `pages/dashboard.css`, `admin.css`.

### 4.1.0–4.1.3
- **NEW: AI Auto-Optimize page** — finds underperforming posts, generates AI edit proposals, applies edits with diff review. Manual review queue: every change is reviewed before it touches content. AJAX-driven, lives at `Create → Auto-Optimize`.
- **NEW: Competitor Watch page** — tracks up to 10 competitor URLs. Daily WP-cron auto-scan via RSS / Atom (auto-discovered) with sitemap.xml fallback; always also fetches homepage for `<title>`, first `<h1>`, and JSON-LD schema types. Diff between last two snapshots surfaces new posts, new schema types, title/H1 changes. Lives at `Insights → Competitors`.
- **Dashboard widget** — Row 4 "Competitor watch" shows the 3 most-recently-changed competitors with one-line summaries, plus a Backlinks placeholder tile.
- **Plugin self-registration** — `SWPS_Auto_Optimize` and `SWPS_Competitors` now register their own admin submenus and AJAX handlers (cleaner than the old dashboard-registered scaffolds).

### 4.0.0–4.0.4
- **Admin shell redesign** — branded top bar (logo, breadcrumb, search, theme toggle, help) on every plugin page, replaces the old in-shell sidebar nav (now uses WP's native admin sidebar). Per-user dark/light theme toggle persisted to user meta.
- **Tokens-based design system** — `admin/css/tokens.css` for `:root` and `[data-swps-theme="light"]` variables; `admin/css/components.css` for buttons, tiles, toggles, badges, chips, status pills; `admin/css/templates.css` for the data-dense template; `admin/css/pages/*.css` for per-page layout.
- **Dashboard rebuild** — KPI tiles, recent generations, AI cost (30d), top issues, top GSC queries, modules grid with on/off toggles per feature, and slot-in widgets for Auto-Optimize and Competitors.
- **IndexNow batch submit** — replaces the dead Google/Bing sitemap ping endpoints (retired 2023). Submits 50 most recently modified posts to `api.indexnow.org` (Bing's IndexNow feed powers ChatGPT search).
- **Yoast replacement** — fully implements the meta-editor + sitemap + breadcrumbs + redirects layer from v3 with conflict detection.

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
