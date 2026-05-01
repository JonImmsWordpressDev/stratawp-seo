=== StrataWP SEO ===
Contributors: jonimms
Tags: seo, ai, content generator, analytics, schema
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 4.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered SEO content generator that knows your WordPress site. Generate optimized blog posts with internal linking, structured data, analytics, keyword tracking, meta editing, and technical SEO.

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
