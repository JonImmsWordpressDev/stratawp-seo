=== StrataWP SEO ===
Contributors: jonimms
Tags: seo, ai, content generator, analytics, schema
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 4.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered SEO content generator that knows your WordPress site. Generate optimized blog posts with internal linking, structured data, analytics, keyword tracking, meta editing, technical SEO, Local SEO (LocalBusiness schema with NAP and opening hours), Image SEO (auto-alt + filename sanitization + lazy-load), in-admin /llms.txt and /robots.txt editors, and a manual/CSV-import backlink tracker with daily health monitoring.

== Description ==

StrataWP SEO is an AI-powered content generation and technical SEO plugin for WordPress. It analyzes your existing site content, then generates SEO-optimized blog posts with internal linking, FAQ schema, and featured images — on autopilot or on demand.

= AI Content Generation =

* **Multi-provider AI** — Anthropic (Claude), OpenAI (GPT), Google (Gemini), xAI (Grok)
* **Site-aware generation** — analyzes your existing content, categories, and internal links before writing
* **7 content templates** — Auto, Listicle, How-To Guide, Comparison, Case Study, News Analysis, Tutorial
* **Voice profiles** — create reusable writing personas with tone, formality, vocabulary, and style preferences
* **Internal linking** — automatically links to your existing posts
* **FAQ sections** — generates FAQ with JSON-LD structured data for rich snippets
* **Key Takeaways** — optional bullet-point summary with ItemList schema
* **Table of Contents** — linked TOC at the top of each post
* **Duplicate detection** — prevents generating content too similar to existing posts
* **Content scoring** — rates generated posts on SEO quality

= Featured & In-Content Images =

* **4 image providers** — Unsplash, Pexels, Pixabay, DALL-E (AI-generated)
* **Auto featured images** — fetches a relevant image for each generated post
* **In-content images** — inserts contextual images within the post body (1-4 per post)

= Automated Publishing =

* **Flexible scheduling** — daily, twice weekly, three times weekly, weekly, biweekly, or monthly
* **Topic queue** — pre-load topics with scheduled dates and template preferences
* **Content calendar** — visual overview of scheduled and generated content
* **Bulk generation** — generate up to 5 posts in one click

= Technical SEO Audit =

* **8 audit modules** — Canonical URLs, XML Sitemap, Open Graph, Twitter Cards, Robots.txt, Meta Robots, Image SEO, Page Speed Hints
* **Auto-fix** — one-click fixes for canonical tags, OG/Twitter meta, sitemap generation
* **Scheduled audits** — daily, weekly, or monthly automated checks
* **Dashboard widget** — site health score at a glance
* **CSV export** — download audit results for reporting

= Schema / Structured Data =

* **Article schema** — automatic JSON-LD on all posts (Article, BlogPosting, or NewsArticle)
* **Breadcrumb schema** — BreadcrumbList on posts, pages, archives, and author pages
* **WebSite schema** — with optional SearchAction for Google sitelinks searchbox
* **Organization/Person schema** — configurable entity type with logo and social profiles
* **Conflict detection** — auto-disables when Yoast SEO, RankMath, or All in One SEO is active

= Keyword Research & Tracking =

* **AI-powered keyword suggestions** — generate keyword ideas from seed topics using your configured AI provider
* **GSC-powered rank tracking** — sync keyword rankings from Google Search Console automatically
* **Striking distance opportunities** — identify keywords ranking in positions 8-20 with the best potential for quick wins

= SEO Meta Editor =

* **Per-post meta title/description** — set custom SEO titles and descriptions on every post and page
* **Live SERP preview** — real-time Google search result preview as you type
* **Character counters** — visual indicators for optimal meta title and description length
* **SEO checklist** — focus keyword analysis with actionable recommendations in the post editor
* **Social previews** — Open Graph and Twitter Card meta with per-post overrides
* **Canonical URL & robots meta** — per-post canonical URL and noindex/nofollow controls
* **Breadcrumb title override** — customize the breadcrumb label per post
* **AI Generate button** — one-click AI-powered meta title and description generation
* **Conflict detection** — auto-disables meta tag output when Yoast SEO, RankMath, or All in One SEO is active

= Analytics & Search Console =

* **On-site analytics** — cookie-free, GDPR-friendly page view tracking (no external services)
* **Time on page & scroll depth** — understand how visitors engage with your content
* **Bounce rate detection** — identify pages losing visitors within 10 seconds
* **Google Search Console** — OAuth integration for clicks, impressions, CTR, and search position data
* **Unified dashboard** — site-wide overview with charts, metric cards, and date range filtering
* **Per-post analytics** — metabox on every post editor with views, time, scroll depth, and top queries
* **Views column** — sortable "Views (30d)" column in the posts list table
* **Configurable retention** — keep data for 30, 90, 180, or 365 days
* **AI Bot Analytics** — server-side tracking of GPTBot, ClaudeBot, PerplexityBot, Google-Extended and 11 more AI crawlers, with per-bot hit counts, top crawled pages, AEO gap report (posts no AI engine has fetched), and 404 monitoring

= Full Sitemap System =

* **Sitemap index** — post type, taxonomy, and author sub-sitemaps
* **Per-URL control** — configurable priority and changefreq per URL
* **Image sitemap entries** — image metadata included in sitemap output
* **IndexNow support** — instant indexing notification on publish/update

= Search Appearance =

* **Title/description templates** — configurable templates for all content types with template variables
* **Title separator picker** — choose your preferred title separator character

= Taxonomy & Archive SEO =

* **Archive meta** — meta title, description, canonical URL, robots directives, and OG tags on category/tag/taxonomy edit screens
* **Frontend output** — all archive meta rendered on archive pages

= Redirect Manager =

* **301/302/307/410 redirects** — exact and regex matching
* **404 monitoring** — error log with one-click redirect creation
* **Auto-redirect** — automatic redirect on slug change

= Frontend Breadcrumbs =

* **HTML breadcrumbs** — output with inline schema markup
* **Flexible integration** — template function, shortcode, and configurable separator/home label

= RSS Feed Optimization =

* **Before/after content** — configurable content injected around RSS feed items with template variables

= wp_head Cleanup =

* **Toggle-based removal** — WP generator tag, RSD link, shortlink, REST API link, oEmbed, and emoji scripts

= AI Crawlers & llms.txt =

* **AI bot allowlist** — checkbox grid for 15 known AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, etc.)
* **robots.txt injection** — allowed bots get explicit Allow rules; unchecked known bots get Disallow
* **Dynamic llms.txt** — served at /llms.txt, built from your site description and posts/pages/categories with one-line summaries

= Local SEO (v4.2) =

* **30+ business types** — Restaurant, Plumber, Dentist, Hotel, RealEstateAgent, Attorney, BeautySalon, etc.
* **NAP fields** — name, address, phone, email, price range
* **Opening hours per day** with "Closed" tick for off-days
* **Geo coordinates** — latitude/longitude for `GeoCoordinates` schema
* **Area served** — newline-separated cities/regions for service-area businesses
* **Google Place ID** — emits a `hasMap` link
* **LocalBusiness JSON-LD** on the homepage with `OpeningHoursSpecification`, `PostalAddress`, `GeoCoordinates`
* **Schema preview** card on the settings page
* Defers automatically to Yoast / RankMath / AIOSEO when those are active

= Image SEO (v4.2) =

* **Auto-alt on upload** — Heuristic mode (free, derives from filename + parent post title) or AI mode (uses your configured provider; falls back on error)
* **Filename sanitization** — strips device prefixes (IMG_, DSC_, Screenshot), lowercases, dash-separates
* **Lazy-loading enforcement** — adds `loading="lazy" decoding="async"` to any post-content image still missing it
* **Bulk fix tool** — processes the existing media library in 20-image AJAX batches with live progress
* **Stats tiles** — total images, missing alt, % covered

= Crawlers & Files (v4.2) =

* **/llms.txt editor** — Auto (StrataWP-generated) or Custom (your markdown served verbatim)
* **/robots.txt editor** — Auto, Append (auto + your rules), or Replace (your content only)
* **Side-by-side editor + auto preview** — one-click "copy auto into editor"
* **Physical-file warning** — detects a real `robots.txt` in the site root and warns it would override the dynamic version
* **Hooks into the existing AI-bot allowlist** — Append mode preserves the StrataWP-managed AI bot rules

= Backlinks (v4.2) =

* **Manual + CSV import** — add backlinks one at a time, or paste/upload a CSV (auto-detects Google Search Console "Top linking sites" exports)
* **Daily WP-cron health monitoring** — re-fetches every source page once a day; classifies as Live, Lost, or Broken
* **Real anchor text + first/last seen** captured on every successful verify
* **AJAX bulk verify** — runs in 25-row batches with progress
* **Per-row re-verify and delete**
* **CSV export**
* **Stats tiles** — total tracked, live, lost, broken, +30d gained, 30d lost, unique referring domains
* **Dashboard widget** — live count + 3 most-recently-verified backlinks with status pills
* No paid backlink index required — tracks the list you give it

= AI Auto-Optimize (v4.1) =

* **Re-score all published posts** — chunked scanning (10 posts per batch) with live progress; works on busy sites without hitting PHP timeouts
* **AI edit proposals** — concrete find/replace edits, optional new meta title/description and focus keyword, with a projected score
* **Diff review modal** — see old vs. new for each edit, check/uncheck individual changes before applying
* **One-click apply** — only the edits you accepted are applied; the post is re-scored automatically after
* **Threshold control** — surface only posts below your score floor (default 75)

= Competitor Watch (v4.1) =

* **Track up to 10 competitor sites** — paste URL, get auto-discovered RSS/Atom feed plus homepage and sitemap.xml fallback
* **Daily WP-cron auto-scan** — fetches each site at most once per day with polite delays
* **Diff detection** — new posts since last snapshot, new JSON-LD schema types, homepage title/H1 changes
* **Content velocity** — posts-per-week trend across snapshot history
* **Per-row "Scan now"** — manual refresh whenever you want
* **Dashboard widget** — 3 most-recently-changed competitors with one-line summary

= Admin Shell (v4.0) =

* **Branded top bar** — logo, breadcrumb, search, theme toggle, help — sits above every plugin page
* **Dark mode by default** — light mode via the toggle (per-user preference, persisted)
* **Emerald & Teal palette** — distinct brand identity with semantic status colors (green = good, red = bad, orange = warn, blue = info, violet = AI features, sky = "new" highlights)
* **Data-dense template** — Audit, Redirects, Internal Links, Auto-Optimize, and Competitors all use the same row-card pattern

= Developer Features =

* **25+ filters and actions** — extend every part of the generation pipeline
* **WP-CLI commands** — generate, analyze, manage queue, and check status from the terminal
* **REST API** — programmatic access to generation and audit features
* **Cost tracking** — monitor token usage and estimated API costs

== Installation ==

1. Upload the `stratawp-seo` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **StrataWP SEO > Settings** and enter your AI provider API key
4. Fill in your site niche and description
5. Go to **StrataWP SEO > Generate Content** to create your first post

= Requirements =

* WordPress 6.0 or higher
* PHP 8.0 or higher
* An API key from at least one AI provider (Anthropic, OpenAI, Google, or xAI)

== Frequently Asked Questions ==

= Which AI provider should I use? =

Anthropic (Claude) is recommended for the best content quality. OpenAI and Google are also excellent alternatives. All providers produce SEO-optimized content.

= Will this plugin conflict with Yoast SEO or RankMath? =

No. StrataWP SEO's schema output automatically disables itself when Yoast, RankMath, or All in One SEO is detected. The content generation and audit features work alongside any SEO plugin.

= Does the AI generate unique content? =

Yes. Each generation produces original content based on your site context, niche, and preferences. The optional duplicate detection feature prevents generating content too similar to your existing posts.

= Can I review posts before they go live? =

Yes. Set the Default Post Status to "Draft" (recommended) or "Pending Review". You can also set a Minimum Content Score to automatically hold low-quality generations as drafts.

= How much does it cost to run? =

The plugin itself is free/included. You pay only for AI API usage. A typical 1,500-word post costs approximately $0.01-0.05 depending on your provider and model. Enable Cost Tracking in Advanced Settings to monitor usage.

= Can I extend the SEO audit with custom checks? =

Yes. Use the `swps_audit_modules` filter to register your own audit module. Your module should extend the `SWPS_Audit_Module` abstract class.

= Can I use the meta editor alongside Yoast/RankMath? =

Yes! StrataWP SEO automatically detects these plugins and disables its own meta tag output to prevent conflicts. The meta editor fields are still available for reference, but frontend output is deferred to the other plugin.

= Is the on-site analytics GDPR compliant? =

Yes. The built-in analytics tracker is cookie-free and does not use any external tracking services. No personal data is stored. No consent banner is required.

= Do I need Google Search Console credentials? =

No. GSC integration is optional. The on-site analytics works entirely without any external services. Add Google OAuth credentials only if you want search clicks, impressions, and ranking data.

== Changelog ==

= 4.6.0 — 2026-05-24 =
* New: AEO Optimize — score posts across 4 AI-citeability dimensions (Extractability, Markup, Authority, Coverage), generate AI proposals, review diffs, apply with one click. Mirrors Auto-Optimize UX.
* New: Live AEO Score panel in the post editor (Gutenberg sidebar + classic metabox).
* New: Dynamic JSON-LD schema generation for HowTo, Recipe, Product, Review, and QAPage. Coexists with Yoast / RankMath / AIOSEO (defers automatically; per-site override via swps_schema_override option).
* New: 6 REST endpoints under /swps/v1/aeo/* for external/programmatic access.
* New: 5 filter hooks for extensibility (swps_aeo_score, swps_aeo_subscores, swps_aeo_proposal, swps_aeo_schema_json, swps_aeo_dimensions).
* Improvement: Dashboard "AEO health (avg)" tile shows average score and % above threshold.
* Improvement: AI Bot Analytics gap-report rows now deep-link into the AEO Optimizer for one-click optimization.
* Developer: Minimal PHPUnit setup for unit-testable pure-PHP scorers. Run `composer test`.

= 4.5.1 =
* Security/Fix: Hardened encryption of stored API keys and Google Search Console OAuth secrets. New values now use authenticated AES-256-GCM (tampering is detected) instead of AES-256-CBC.
* Fix: `decrypt()` previously returned the still-encrypted value if decryption failed — so after a WordPress security-salt rotation a corrupt string was silently sent to AI / Search Console APIs as the credential. It now fails safe (returns empty and logs when `WP_DEBUG` is on); the affected settings field renders blank, prompting you to re-enter the secret.
* Fix: `encrypt()` no longer falls back to storing the secret in plain text if encryption fails.
* Compatibility: Secrets written by earlier versions (AES-256-CBC) are still read transparently and upgrade to AES-256-GCM the next time you save that setting. No action required on upgrade.

= 4.5.0 =
* New: AI Bot Analytics. Server-side tracking of hits from known AI crawlers (GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-Web, anthropic-ai, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended, CCBot, Meta-ExternalAgent, Bytespider, Amazonbot, DuckAssistBot). Captures on `shutdown` so post ID and status code are accurate, batches into a raw table for 7 days, then aggregates into a daily summary with configurable retention (default 90 days). Mirrors the existing analytics pattern — no JS, no external services, GDPR-friendly (no IP or referrer storage). Excludes admin/AJAX/REST/cron/CLI by default and supports a configurable path exclusion list and 0–100% sample rate for high-traffic sites.
* New: "AI Crawlers" section on the Analytics page. Shows bot hit totals (with delta vs prior period), per-bot breakdown with last-seen, top crawled pages with 404 counts, an **AEO Gap report** (published posts that no AI bot has fetched), and recent bot 404s for redirect candidates.
* New: REST endpoints — `GET /wp-json/swps/v1/bot-analytics/summary`, `/top-pages`, `/gaps`.
* New: WP-CLI — `wp swps bot-stats [--days=N] [--bot=KEY] [--format=table|json]`.
* New: Per-post analytics widget now includes bot hit counts (7d / 30d) and last-seen alongside human pageview stats.
* New: Filters `swps_ai_bots_known`, `swps_bot_analytics_capture`, `swps_bot_analytics_normalize_uri` and action `swps_bot_analytics_hit` for extending the pipeline.

= 4.4.2 =
* Fix: Scheduled (WP-Cron) posts were created without a featured image or in-content images, with nothing logged to debug.log. Root cause was PHP `max_execution_time` killing the worker mid-image-download — the AI text call plus Gemini image generation easily exceeds the 30–60s default. `SWPS_Generator::generate_post()` now calls `@set_time_limit(0)`, raises the memory limit, and sets `ignore_user_abort(true)` before kicking off the pipeline. Manual generation was unaffected; only cron/background paths benefit.

= 4.4.1 =
* Fix: Saving the Google (Gemini) API key from the Settings screen had no effect. The same field was registered in both the AI Provider and Featured Images sections, so a hidden duplicate input overwrote the value with an empty string on save. The Featured Images section now references the AI Provider key instead of duplicating the input. (#24)

= 4.4.0 =
* New: Migration tool (StrataWP SEO → Migrate). Imports settings from Yoast SEO, Yoast SEO Premium, Rank Math, and Rank Math Pro into StrataWP SEO. Auto-detects installed source plugins, shows post counts, offers Preview before Run, lets users choose skip-existing vs overwrite, and keeps a typed backup so any migration can be undone with one click. Migrates per-post meta (title, description, focus keyword, canonical, breadcrumb title, social title/description/image — streamed in 100-post batches), global settings (title separator with Yoast sc-* codes decoded, title templates per post type / taxonomy / archive with Rank Math %var% → StrataWP %%var%% rewriting, per-post-type noindex flags), and redirects from Yoast Premium (wpseo-premium-redirects-base) and Rank Math Pro (wp_rank_math_redirections active rows) — 301/302/307/410 supported, regex preserved. UI has phase checkboxes (post meta / settings / redirects); Undo reverts everything atomically.

= 4.3.0 =
* New: GitHub-based auto-updates. When a new release is published on the official GitHub repository, all installs see "Update available" on the WordPress Plugins screen and can one-click update directly — no manual zip download needed. Cached for 12 hours to respect GitHub's rate limits.

= 4.2.3 =
* Fix: Sitemap Disable/Enable toggle now correctly reflects status in the admin UI (button label and row state were stuck because the JS read the wrong response field). Backend exclusion was already honored. (#20)

= 4.2.2 =
* New: Backlinks page (Insights → Backlinks). Manual + CSV-import backlink tracker. Daily WP-cron classifies each link as Live, Lost, or Broken; captures real anchor text and first/last seen dates. AJAX bulk-verify in 25-row batches, per-row re-verify and delete, CSV import (auto-detects Google Search Console "Top linking sites" exports), CSV export. Backed by a custom DB table created via dbDelta with a runtime upgrade path so existing installs get the table without re-activation. Replaces the previous "coming soon" placeholder with a real, working feature.
* Dashboard tiles light up with live backlink stats (total / live / lost / domains, plus 3 most-recently-verified).

= 4.2.1 =
* New: Crawlers & Files page (SEO → Crawlers & Files). Edit /llms.txt (Auto / Custom modes) and /robots.txt (Auto / Append / Replace modes) directly from the admin. Side-by-side editor + auto preview, one-click "copy auto into editor", warning when a physical robots.txt would override the dynamic version. Hooks into the existing pipeline so the AI-bot allowlist is preserved in Append mode.

= 4.2.0 =
* New: Local SEO page (SEO → Local SEO). LocalBusiness JSON-LD output for brick-and-mortar and service-area sites. 30+ business types (Restaurant, Plumber, Dentist, Hotel, etc.), full NAP fields, opening hours per day, geo coordinates, area served, Google Place ID. Live schema preview. Defers to Yoast / RankMath / AIOSEO when active.
* New: Image SEO page (SEO → Image SEO). Auto-alt on upload (Heuristic free mode or AI mode using the configured provider), device-prefix-stripping filename sanitization, lazy-loading enforcement on the_content, bulk-fix tool for the existing media library with batched AJAX progress, library coverage stats.
* Modules registry: Local SEO and Image SEO unlocked with NEW badges; Backlinks added as a "soon" entry; WooCommerce SEO retained as the only remaining "soon" module.

= 4.1.7 =
* Critical fix: render functions for the Auto-Optimize queue and Competitors list moved to the top of their templates. PHP doesn't hoist conditionally-declared functions, so the prior placement caused a fatal error ("There has been a critical error") once any post had a cached score.

= 4.1.6 =
* Auto-Optimize re-scan is now chunked: 10 posts per request with live progress (e.g. "Re-scoring 30/137"). Fixes "Request failed" on busy sites.
* New `swps_optimize_scan_chunk` AJAX endpoint; per-post try/catch so a corrupt post doesn't kill the batch.

= 4.1.5 =
* Auto-Optimize: server now sets a 180s time limit, raises the admin memory cap, ignores user abort, and surfaces real error messages instead of generic 500s. Client uses $.ajax with a 4-min timeout and reports HTTP status / "timed out" in the alert.

= 4.1.4 =
* Brand palette overhaul: Emerald → Teal replaces the gold/black brand. Status colors refreshed for clarity (green = good, red = bad, orange = warn, blue = info, violet = AI, sky = "new").
* Removed the colored halo on primary CTAs — replaced with a clean depth shadow.
* Cleaned up every hardcoded brand-gold rgba across the CSS files.

= 4.1.0–4.1.3 =
* New: AI Auto-Optimize page — finds underperforming posts, generates AI edit proposals, applies edits with diff review.
* New: Competitor Watch page — tracks up to 10 competitor URLs with daily WP-cron scans (RSS / Atom auto-discovery, sitemap.xml fallback, homepage scrape for title/H1/schema).
* Dashboard widget for Competitor Watch (3 most-recently-changed sites with one-line summary).
* Auto-Optimize and Competitors classes self-register their admin submenus, AJAX handlers, and cron events.

= 4.0.0–4.0.4 =
* Admin shell redesign: branded top bar (logo, breadcrumb, search, theme toggle, help) on every plugin page.
* Per-user dark/light theme toggle persisted to user meta.
* Tokens-based design system: tokens.css (root + light variants), components.css, templates.css, per-page CSS files.
* Dashboard rebuild: KPI tiles, recent generations, AI cost (30d), top issues, top GSC queries, modules grid.
* IndexNow batch submit replaces dead Google/Bing sitemap ping endpoints.
* Yoast replacement layer fully implemented (meta editor, sitemap, breadcrumbs, redirects with conflict detection).

= 3.7.8 =
* IndexNow batch submission replaces the dead Google/Bing sitemap ping endpoints (retired 2023). Ping now submits 50 most recent posts and reports real status.
* Fixed Sitemaps admin: [object Object] ping result, blank Settings tab, permanent "Loading..." (wrong nonce reference).

= 3.7.7 =
* Sitemaps admin: fixed nonce reference (swps_admin -> swpsAdmin), added swps-admin JS dependency, fixed tab content data-tab attributes.

= 3.7.6 =
* New AI Crawlers settings section with multi-checkbox allowlist for 15 known AI bots.
* New SWPS_AI_Bots class hooks robots_txt to inject Allow/Disallow rules.
* Dynamic llms.txt generator served at /llms.txt with site description intro, posts/pages/categories, and one-line summaries.
* New multi_checkbox settings field type and default arg support for checkboxes.

= 3.7.5 =
* New Debug admin page showing the last failed AI response (raw + cleaned) for diagnosis.
* AI JSON parser: 5th repair attempt combining quote repair + control-char sanitization. Strips Unicode line/paragraph separators. Persists raw response on parse failure.

= 3.7.4 =
* Added Claude Opus 4.7 to the model dropdown (released April 2026).
* Cost tracker pricing entry for Opus 4.7.

= 3.7.3 =
* Fixed JSON prefill incorrectly enabled for Claude 4.6+ models. Resolves "This model does not support assistant message prefill" error.

= 3.7.0 - 3.7.2 =
* Admin visual refresh (Slate & Coral palette across calendar, modals, SERP preview, all components).
* Chart.js v4 fixes (CDN version pinning, canvas height constraint).
* Updated AI model defaults.

= 3.6.x =
* Redirects, sitemaps, and internal linking system added.

= 3.0.0 =
* Added: Full Sitemap System — sitemap index with post type, taxonomy, and author sub-sitemaps. Per-URL priority/changefreq control. Image sitemap entries. IndexNow support for instant indexing.
* Added: Search Appearance — configurable title/description templates for all content types with template variables. Title separator picker.
* Added: Taxonomy & Archive SEO — meta title, description, canonical URL, robots directives, and OG tags on category/tag/taxonomy edit screens with frontend output on archive pages.
* Added: Redirect Manager — 301/302/307/410 redirects with exact and regex matching. 404 error monitoring with one-click redirect creation. Auto-redirect on slug change.
* Added: Frontend Breadcrumbs — HTML breadcrumb output with inline schema markup. Template function, shortcode, and configurable separator/home label.
* Added: RSS Feed Optimization — configurable before/after content in RSS feed items with template variables.
* Added: wp_head Cleanup — toggle-based removal of WP generator tag, RSD link, shortlink, REST API link, oEmbed, and emoji scripts.

= 2.3.0 =
* Added: Keyword Research & Tracking — AI-powered keyword suggestions and GSC rank tracking
* Added: SEO Meta Editor — per-post meta title, description, social previews, and robots controls
* Added: Live SERP preview with character counters in post editor
* Added: SEO checklist with focus keyword analysis
* Added: Striking distance keyword opportunities (position 8-20)
* Added: AI-powered meta title/description generation
* Added: Conflict detection — auto-disables meta output when Yoast/RankMath/AIOSEO is active
* Added: 5 new developer hooks for meta/keyword extensibility

= 2.2.0 =
* Added on-site analytics tracking (page views, time on page, scroll depth, bounce rate)
* Added Google Search Console OAuth integration (clicks, impressions, CTR, position)
* Added unified analytics dashboard with charts, metric cards, and date range filtering
* Added per-post analytics metabox on the post editor
* Added sortable "Views (30d)" column on the posts list table
* Added configurable data retention (30/90/180/365 days) with automatic pruning
* Added 3 new developer hooks for analytics extensibility

= 2.1.0 =
* Added Schema / Structured Data (Article, Breadcrumb, WebSite, Organization/Person JSON-LD)
* Added conflict detection for Yoast, RankMath, and AIOSEO schema
* Added 7 schema settings fields with developer filter hooks
* Version bump for cache busting after SEO Audit release

= 2.0.0 =
* Added Technical SEO Audit with 8 modules (Canonical, Sitemap, OG, Twitter, Robots.txt, Meta Robots, Image SEO, Page Speed)
* Added auto-fix for canonical tags, OG/Twitter meta, and sitemap generation
* Added dashboard widget with site health score
* Added voice profiles for reusable writing personas
* Added in-content image insertion (1-4 images per post)
* Added content scoring with quality gate
* Added topic queue and content calendar
* Added cost tracking and rate limiting
* Added duplicate detection
* Added bulk generation (up to 5 posts)
* Added WP-CLI commands (generate, analyze, status, queue)
* Added REST API
* Added multi-provider support (Anthropic, OpenAI, Google, xAI)
* Added multi-provider image support (Unsplash, Pexels, Pixabay, DALL-E)
* Added 7 content templates
* Added Key Takeaways with schema markup
* Added background processing
* Added 20+ developer hooks

= 1.0.0 =
* Initial release
* AI content generation with Anthropic Claude
* Featured images via Unsplash
* FAQ schema generation
* Internal linking
* Scheduled publishing

== Upgrade Notice ==

= 4.2.2 =
Adds the Backlinks tracker (Insights → Backlinks) — manual + CSV-import with daily health monitoring (Live / Lost / Broken). Imports the Google Search Console "Top linking sites" CSV directly. Runs DB migration on first load.

= 4.2.1 =
New Crawlers & Files page lets you edit /llms.txt and /robots.txt from the admin with auto/custom modes and side-by-side preview.

= 4.2.0 =
Two new modules: Local SEO (LocalBusiness JSON-LD with NAP, hours, geo, area served) and Image SEO (auto-alt, filename sanitization, lazy-load, bulk-fix tool). No breaking changes.

= 4.1.7 =
Critical fix for Auto-Optimize and Competitors pages — fatal error when displaying queue rows. Strongly recommended.

= 4.1.4 =
Brand palette refresh (Emerald & Teal). All semantic status colors are now distinct from the brand. Hard-reload the admin to clear cached CSS.

= 4.1.0 =
Major release: AI Auto-Optimize and Competitor Watch ship. New admin shell with dark/light theme toggle. Yoast replacement layer is fully featured.

= 3.7.8 =
Replaces the dead Google/Bing sitemap ping endpoints with IndexNow batch submission. Fixes Sitemaps admin display bugs.

= 3.7.6 =
Adds AI Crawlers settings section (robots.txt allowlist for 15 known AI bots) and dynamic llms.txt generator at /llms.txt.

= 3.7.5 =
Adds Debug admin page for AI JSON parse failures. Improves JSON parser robustness.

= 3.7.3 =
Fixes "assistant message prefill" error on Claude 4.6+ models. Strongly recommended.

= 3.0.0 =
Major release adding full sitemap system, search appearance templates, taxonomy/archive SEO, redirect manager, frontend breadcrumbs, RSS feed optimization, and wp_head cleanup. No breaking changes to existing features.

= 2.3.0 =
Adds Keyword Research & Tracking with AI-powered suggestions and GSC rank tracking, plus a full SEO Meta Editor with SERP preview, social previews, and conflict detection. No breaking changes.

= 2.2.0 =
Adds on-site analytics tracking and Google Search Console integration with a unified dashboard. Cookie-free, GDPR-friendly. No breaking changes.

= 2.1.0 =
Adds automatic JSON-LD structured data for Article, Breadcrumb, WebSite, and Organization/Person schema types. No breaking changes.
