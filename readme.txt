=== StrataWP SEO ===
Contributors: jonimms
Tags: seo, ai, content generator, structured data, schema
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered SEO content generator that knows your WordPress site. Generate optimized blog posts with internal linking, structured data, and technical SEO.

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

= Developer Features =

* **20+ filters and actions** — extend every part of the generation pipeline
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

== Screenshots ==

1. Settings page — configure AI provider, site details, and writing preferences
2. Generate Content — create posts on demand with topic and template selection
3. SEO Audit — 8-module technical audit with scores and one-click fixes
4. Schema JSON-LD — automatic structured data output in page source

== Changelog ==

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

= 2.1.0 =
Adds automatic JSON-LD structured data for Article, Breadcrumb, WebSite, and Organization/Person schema types. No breaking changes.
