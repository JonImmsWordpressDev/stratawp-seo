# StrataWP SEO

**AI-powered SEO content generator that knows your WordPress site.** Generate optimized blog posts with internal linking, structured data, and technical SEO — on autopilot.

[![Version](https://img.shields.io/badge/version-3.0.0-blue.svg)]()
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)]()
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)]()
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Screenshots

<!-- Replace these placeholders with actual screenshots -->

| Settings Page | Generate Content |
|---|---|
| ![Settings](screenshots/settings-page.png) | ![Generate](screenshots/generate-page.png) |

| SEO Audit Dashboard | Schema JSON-LD Output |
|---|---|
| ![Audit](screenshots/audit-page.png) | ![Schema](screenshots/schema-output.png) |

| Analytics Dashboard | Post Analytics Metabox |
|---|---|
| ![Analytics](screenshots/analytics-page.png) | ![Metabox](screenshots/analytics-metabox.png) |

---

## Features

### AI Content Generation
- **Multi-provider AI** — Anthropic (Claude), OpenAI (GPT), Google (Gemini), xAI (Grok)
- **Site-aware generation** — analyzes your existing content, categories, and internal links before writing
- **7 content templates** — Auto, Listicle, How-To Guide, Comparison, Case Study, News Analysis, Tutorial
- **Voice profiles** — create reusable writing personas with tone, formality, vocabulary, and style preferences
- **Internal linking** — automatically links to your existing posts (configurable min/max)
- **FAQ sections** — generates FAQ with JSON-LD structured data for rich snippets
- **Key Takeaways** — optional bullet-point summary with ItemList schema
- **Table of Contents** — linked TOC at the top of each post
- **Duplicate detection** — prevents generating content too similar to existing posts (85% threshold)
- **Content scoring** — rates generated posts on SEO quality; optionally blocks low-scoring posts as drafts

### Featured & In-Content Images
- **4 image providers** — Unsplash, Pexels, Pixabay, DALL-E (AI-generated)
- **Auto featured images** — fetches a relevant image for each generated post
- **In-content images** — inserts contextual images within the post body (1-4 per post)

### Automated Publishing
- **Flexible scheduling** — daily, twice weekly, three times weekly, weekly, biweekly, or monthly
- **Topic queue** — pre-load topics with scheduled dates and template preferences
- **Content calendar** — visual overview of scheduled and generated content
- **Bulk generation** — generate up to 5 posts in one click

### Full Sitemap System
- **Sitemap index** — post type, taxonomy, and author sub-sitemaps
- **Per-URL control** — configurable priority and changefreq per URL
- **Image sitemap entries** — image metadata included in sitemap output
- **IndexNow support** — instant indexing notification on publish/update

### Search Appearance
- **Title/description templates** — configurable templates for all content types with template variables
- **Title separator picker** — choose your preferred title separator character

### Taxonomy & Archive SEO
- **Archive meta** — meta title, description, canonical URL, robots directives, and OG tags on category/tag/taxonomy edit screens
- **Frontend output** — all archive meta rendered on archive pages

### Redirect Manager
- **301/302/307/410 redirects** — exact and regex matching
- **404 monitoring** — error log with one-click redirect creation
- **Auto-redirect** — automatic redirect on slug change

### Frontend Breadcrumbs
- **HTML breadcrumbs** — output with inline schema markup
- **Flexible integration** — template function, shortcode, and configurable separator/home label

### RSS Feed Optimization
- **Before/after content** — configurable content injected around RSS feed items with template variables

### wp_head Cleanup
- **Toggle-based removal** — WP generator tag, RSD link, shortlink, REST API link, oEmbed, and emoji scripts

### Technical SEO Audit
- **8 audit modules** — Canonical URLs, XML Sitemap, Open Graph, Twitter Cards, Robots.txt, Meta Robots, Image SEO, Page Speed Hints
- **Auto-fix** — one-click fixes for canonical tags, OG/Twitter meta, sitemap generation
- **Scheduled audits** — daily, weekly, or monthly automated checks
- **Dashboard widget** — site health score at a glance
- **CSV export** — download audit results for reporting

### Schema / Structured Data
- **Article schema** — automatic JSON-LD on all posts (Article, BlogPosting, or NewsArticle)
- **Breadcrumb schema** — BreadcrumbList on posts, pages, archives, and author pages
- **WebSite schema** — with optional SearchAction for Google sitelinks searchbox
- **Organization/Person schema** — configurable entity type with logo and social profiles
- **Conflict detection** — auto-disables when Yoast SEO, RankMath, or All in One SEO is active

### Keyword Research & Tracking
- **AI-powered keyword suggestions** — generate keyword ideas from seed topics using your configured AI provider
- **GSC-powered rank tracking** — sync keyword rankings from Google Search Console automatically
- **Striking distance opportunities** — identify keywords ranking in positions 8-20 with the best potential for quick wins

### SEO Meta Editor
- **Per-post meta title/description** — set custom SEO titles and descriptions on every post and page
- **Live SERP preview** — real-time Google search result preview as you type
- **Character counters** — visual indicators for optimal meta title and description length
- **SEO checklist** — focus keyword analysis with actionable recommendations in the post editor
- **Social previews** — Open Graph and Twitter Card meta with per-post overrides
- **Canonical URL** — set a custom canonical URL per post
- **Robots meta** — per-post noindex/nofollow controls
- **Breadcrumb title override** — customize the breadcrumb label per post
- **AI Generate button** — one-click AI-powered meta title and description generation
- **Conflict detection** — auto-disables meta tag output when Yoast SEO, RankMath, or All in One SEO is active

### Analytics & Search Console
- **On-site analytics** — cookie-free, GDPR-friendly page view tracking (no external services)
- **Time on page** — tracks how long visitors spend on each page
- **Scroll depth** — measures how far visitors scroll through your content
- **Bounce rate** — detects visitors who leave without interacting
- **Google Search Console** — OAuth integration for clicks, impressions, CTR, and search position data
- **Unified dashboard** — site-wide overview with SVG charts, metric cards, and date range filtering (7/30/90 days)
- **Top pages & queries** — see your best-performing content and search terms at a glance
- **Per-post analytics** — metabox on every post editor with views, time, scroll depth, and top GSC queries
- **Views column** — sortable "Views (30d)" column in the posts list table
- **Configurable retention** — keep data for 30, 90, 180, or 365 days with automatic pruning

### Developer Features
- **25+ filters and actions** — extend every part of the generation pipeline
- **WP-CLI commands** — generate, analyze, manage queue, and check status from the terminal
- **REST API** — programmatic access to generation and audit features
- **Cost tracking** — monitor token usage and estimated API costs

---

## Installation

### From WordPress Admin
1. Download the plugin ZIP file
2. Go to **Plugins > Add New > Upload Plugin**
3. Upload the ZIP and click **Install Now**
4. Click **Activate**

### Manual Installation
1. Upload the `stratawp-seo` folder to `/wp-content/plugins/`
2. Activate through the **Plugins** menu in WordPress

### Requirements
- WordPress 6.0 or higher
- PHP 8.0 or higher
- An API key from at least one AI provider (Anthropic, OpenAI, Google, or xAI)

---

## Quick Start

1. **Add your API key** — Go to **StrataWP SEO > Settings** and enter your AI provider API key (Anthropic recommended)
2. **Describe your site** — Fill in your site niche and description so the AI understands your audience
3. **Set your preferences** — Choose tone, word count range, and post status (draft recommended to start)
4. **Generate your first post** — Go to **StrataWP SEO > Generate Content**, enter a topic, and click Generate
5. **Review and publish** — Edit the generated draft in WordPress, then publish when ready

---

## Configuration Guide

### AI Provider

| Setting | Description |
|---|---|
| **AI Provider** | Choose between Anthropic (Claude), OpenAI (GPT), Google (Gemini), or xAI (Grok) |
| **API Key** | Your provider's API key. Only the active provider's key is required |
| **AI Model** | Model list updates automatically when you switch providers |

### Featured Images

| Setting | Description |
|---|---|
| **Auto Featured Images** | Automatically fetch a relevant featured image for each post |
| **Image Provider** | Unsplash, Pexels, Pixabay (free stock photos), or DALL-E (AI-generated) |
| **In-Content Images** | Insert contextual images within the post body |
| **Images Per Post** | Number of in-content images (1-4) |
| **Image Max Width** | Maximum width in pixels (600-2400) |

### Site Details

| Setting | Description |
|---|---|
| **Site Niche** | Your industry or topic area (e.g., "Home Renovation", "Digital Marketing") |
| **Site Description** | Detailed description of your site, audience, and unique value proposition |

The more detail you provide here, the more relevant and targeted your generated content will be.

### Writing Preferences

| Setting | Description |
|---|---|
| **Tone of Voice** | Professional, Conversational, Friendly, Authoritative, Casual, Formal, or Witty |
| **Voice Profile** | Override tone with a reusable voice profile (create profiles under Voice Profiles) |
| **Custom Style Notes** | Free-text instructions (e.g., "Use short paragraphs, avoid jargon") |
| **Target Keywords** | Comma-separated keywords to weave into generated content |

### Content Settings

| Setting | Description |
|---|---|
| **Post Status** | Draft (recommended), Pending Review, or Published |
| **Post Author** | WordPress user to assign as author |
| **Default Category** | Fixed category, or let the AI choose the best fit |
| **Word Count** | Minimum (300-5000) and maximum (500-8000) word targets |
| **Internal Links** | Min and max number of links to your existing posts |
| **FAQ Section** | Generate FAQ with JSON-LD schema for rich snippets |
| **Table of Contents** | Linked TOC at the top of each post |
| **Key Takeaways** | Bullet-point summary after the intro, with optional schema markup |

### Auto-Publishing Schedule

| Setting | Description |
|---|---|
| **Enable** | Turn on scheduled generation |
| **Frequency** | Daily, twice weekly, three times weekly, weekly, biweekly, or monthly |
| **Day of Week** | Starting day for the schedule |
| **Time** | Time of day to run |
| **Posts Per Run** | Number of posts per scheduled run (1-5) |

### SEO Audit

| Setting | Description |
|---|---|
| **Auto Canonical Tags** | Add canonical tags to posts/pages missing them |
| **Auto OG/Twitter Tags** | Output Open Graph and Twitter Card meta tags |
| **Sitemap Generation** | Generate XML sitemap (disabled if another sitemap plugin is active) |
| **Audit Schedule** | How often to run automated audits (daily, weekly, monthly) |

### Schema / Structured Data

| Setting | Description |
|---|---|
| **Enable Schema Markup** | Output JSON-LD structured data (auto-disabled when Yoast/RankMath/AIOSEO is active) |
| **Article Type** | Article, BlogPosting, or NewsArticle for blog posts |
| **Sitelinks Searchbox** | Add SearchAction for Google sitelinks search box on your homepage |
| **Site Represents** | Organization or Person |
| **Name** | Organization or person name (defaults to site name) |
| **Logo URL** | Full URL to logo image (min 112x112px) |
| **Social Profiles** | One URL per line — populates the `sameAs` property |

### SEO Meta Editor

| Setting | Description |
|---|---|
| **Meta Editor Enabled** (`swps_meta_editor_enabled`) | Enable/disable the meta editor metabox on post edit screens |
| **Meta Editor Post Types** (`swps_meta_editor_post_types`) | Comma-separated list of post types that show the meta editor (default: `post,page`) |
| **Auto-Generate Meta** (`swps_meta_auto_generate`) | Automatically generate meta title and description when a post is published |

### Keyword Tracking

| Setting | Description |
|---|---|
| **Keyword Sync Frequency** (`swps_keyword_tracking_frequency`) | How often to sync keyword data from Google Search Console (daily, weekly, or monthly) |

### Analytics

| Setting | Description |
|---|---|
| **Enable On-Site Tracking** | Track page views, time on page, scroll depth, and bounce rate (cookie-free, GDPR-friendly) |
| **Data Retention** | How long to keep analytics data: 30, 90, 180, or 365 days |
| **Exclude Admins** | Don't track visits from logged-in administrators |
| **Google OAuth Client ID** | OAuth client ID from Google Cloud Console for Search Console integration |
| **Google OAuth Client Secret** | OAuth client secret (stored encrypted) |

After saving your Google OAuth credentials, visit **StrataWP SEO > Analytics** to connect your Search Console property.

### Advanced Settings

| Setting | Description |
|---|---|
| **Default Template** | Default content format for generation |
| **Rate Limit** | Cooldown in seconds between generations (prevents double-clicks) |
| **Duplicate Detection** | Block posts with titles too similar to existing content |
| **Cost Tracking** | Track token usage and estimated costs per generation |
| **Min Content Score** | Posts scoring below this are forced to draft status (0 to disable) |

---

## WP-CLI Commands

StrataWP SEO registers the `wp swps` command namespace.

### Generate a post

```bash
# Generate with AI-chosen topic
wp swps generate

# Generate with a specific topic
wp swps generate "Best practices for WordPress security"

# Generate with a specific template
wp swps generate --template=listicle
wp swps generate "How to speed up WordPress" --template=how-to
```

Available templates: `auto`, `listicle`, `how-to`, `comparison`, `case-study`, `news`, `tutorial`

### Analyze site content

```bash
# JSON output (default)
wp swps analyze

# Table format
wp swps analyze --format=table
```

### Check plugin status

```bash
wp swps status
```

Shows version, AI provider, model, schedule status, cost stats, and queue count.

### Manage topic queue

```bash
# List queued topics
wp swps queue list

# Add a topic
wp swps queue add "WordPress security best practices"

# Add with scheduled date and template
wp swps queue add "Top 10 SEO tools" --date="2026-04-01 09:00:00" --template=listicle

# Remove a topic by ID
wp swps queue remove 123

# Clear all queued topics
wp swps queue clear
```

---

## Developer Reference

StrataWP SEO provides filters and actions throughout the generation pipeline via the `SWPS_Hooks` class. All hooks use the `swps_` prefix.

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

```php
add_filter( 'swps_ai_response', function( array $response, string $topic ): array {
    return $response;
}, 10, 2 );
```

**`swps_post_data`** — Modify WordPress post data before insertion.

```php
add_filter( 'swps_post_data', function( array $post_data, array $ai_result ): array {
    $post_data['post_status'] = 'pending';
    return $post_data;
}, 10, 2 );
```

#### Images

**`swps_image_query`** — Modify the image search query.

```php
add_filter( 'swps_image_query', function( string $query, int $post_id ): string {
    return $query;
}, 10, 2 );
```

**`swps_content_images_queries`** — Modify in-content image search queries.

```php
add_filter( 'swps_content_images_queries', function( array $queries, int $post_id ): array {
    return $queries;
}, 10, 2 );
```

**`swps_image_selection`** — Modify selected image data before insertion.

```php
add_filter( 'swps_image_selection', function( array $image_data, int $post_id, string $heading ): array {
    return $image_data;
}, 10, 3 );
```

#### Schema / Structured Data

**`swps_schema_article`** — Modify Article JSON-LD before output.

```php
add_filter( 'swps_schema_article', function( array $schema, int $post_id ): array {
    $schema['author']['sameAs'] = 'https://twitter.com/yourhandle';
    return $schema;
}, 10, 2 );
```

**`swps_schema_breadcrumb`** — Modify BreadcrumbList JSON-LD.

```php
add_filter( 'swps_schema_breadcrumb', function( array $schema ): array {
    return $schema;
} );
```

**`swps_schema_organization`** — Modify Organization/Person JSON-LD.

```php
add_filter( 'swps_schema_organization', function( array $schema ): array {
    $schema['contactPoint'] = [
        '@type'     => 'ContactPoint',
        'telephone' => '+1-800-555-1234',
    ];
    return $schema;
} );
```

**`swps_faq_schema`** — Modify FAQ JSON-LD.

```php
add_filter( 'swps_faq_schema', function( array $schema, string $title, array $faq_items ): array {
    return $schema;
}, 10, 3 );
```

**`swps_takeaways_schema`** — Modify Key Takeaways ItemList JSON-LD.

```php
add_filter( 'swps_takeaways_schema', function( array $schema, array $takeaways ): array {
    return $schema;
}, 10, 2 );
```

#### SEO Audit

**`swps_audit_modules`** — Add or remove audit modules.

```php
add_filter( 'swps_audit_modules', function( array $modules ): array {
    $modules['my_custom_check'] = new My_Custom_Audit_Module();
    return $modules;
} );
```

**`swps_audit_result`** — Modify an individual module's audit result.

```php
add_filter( 'swps_audit_result', function( array $result, string $module_id ): array {
    return $result;
}, 10, 2 );
```

#### Analytics

**`swps_analytics_track`** — Filter tracking data before storage. Return empty array to block the hit.

```php
add_filter( 'swps_analytics_track', function( array $data, int $post_id ): array {
    // Block tracking for specific post types
    if ( get_post_type( $post_id ) === 'page' ) {
        return [];
    }
    return $data;
}, 10, 2 );
```

**`swps_analytics_exclude`** — Filter whether to exclude a page from tracking.

```php
add_filter( 'swps_analytics_exclude', function( bool $exclude, int $post_id ): bool {
    return $exclude;
}, 10, 2 );
```

**`swps_gsc_data`** — Filter Google Search Console API response data.

```php
add_filter( 'swps_gsc_data', function( array $data, string $property ): array {
    return $data;
}, 10, 2 );
```

#### SEO Meta Editor

**`swps_meta_title`** — Filter the meta title output for a post.

```php
add_filter( 'swps_meta_title', function( string $title, int $post_id ): string {
    return $title . ' | My Brand';
}, 10, 2 );
```

**`swps_meta_description`** — Filter the meta description output for a post.

```php
add_filter( 'swps_meta_description', function( string $description, int $post_id ): string {
    return $description;
}, 10, 2 );
```

**`swps_meta_robots`** — Filter the robots meta directives for a post.

```php
add_filter( 'swps_meta_robots', function( string $robots, int $post_id ): string {
    if ( get_post_type( $post_id ) === 'landing_page' ) {
        return 'noindex, nofollow';
    }
    return $robots;
}, 10, 2 );
```

**`swps_seo_checklist`** — Filter or add SEO checklist items in the meta editor metabox.

```php
add_filter( 'swps_seo_checklist', function( array $items, int $post_id ): array {
    $items[] = [
        'label'  => 'Has a CTA in the first paragraph',
        'status' => 'pass',
    ];
    return $items;
}, 10, 2 );
```

#### Keyword Research

**`swps_keyword_suggestions`** — Filter AI-generated keyword suggestions.

```php
add_filter( 'swps_keyword_suggestions', function( array $keywords, string $seed_topic ): array {
    // Remove branded terms
    return array_filter( $keywords, fn( $kw ) => stripos( $kw, 'brand' ) === false );
}, 10, 2 );
```

#### Content Scoring

**`swps_score_weights`** — Adjust content scoring weights.

```php
add_filter( 'swps_score_weights', function( array $weights, int $post_id, string $post_type ): array {
    $weights['readability'] = 0.3;
    return $weights;
}, 10, 3 );
```

### Actions

**`swps_before_generate`** — Fires before content generation starts.

```php
add_action( 'swps_before_generate', function( string $topic, string $template ): void {
    // Log generation attempt
}, 10, 2 );
```

**`swps_after_generate`** — Fires after successful generation.

```php
add_action( 'swps_after_generate', function( array $result, string $topic, string $template ): void {
    // Notify Slack, send email, etc.
}, 10, 3 );
```

**`swps_post_created`** — Fires after the WordPress post is inserted.

```php
add_action( 'swps_post_created', function( int $post_id, array $ai_result, array $post_data ): void {
    // Add custom meta, trigger workflows
}, 10, 3 );
```

**`swps_generation_failed`** — Fires when generation fails.

```php
add_action( 'swps_generation_failed', function( WP_Error $error, string $topic, string $template ): void {
    error_log( 'SWPS generation failed: ' . $error->get_error_message() );
}, 10, 3 );
```

**`swps_score_complete`** — Fires after content scoring completes.

```php
add_action( 'swps_score_complete', function( array $results, int $post_id ): void {
    if ( $results['overall_score'] >= 90 ) {
        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
    }
}, 10, 2 );
```

**`swps_image_inserted`** — Fires after an in-content image is inserted.

```php
add_action( 'swps_image_inserted', function( int $attachment_id, int $post_id, string $alt_text, int $position ): void {
    // Track image insertions
}, 10, 4 );
```

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

Anthropic (Claude) is recommended for the best content quality. OpenAI and Google are also excellent alternatives. All providers produce SEO-optimized content — choose based on your preference and existing API keys.

### Will this plugin conflict with Yoast SEO or RankMath?

No. StrataWP SEO's schema output automatically disables itself when Yoast, RankMath, or All in One SEO is detected. The content generation and audit features work alongside any SEO plugin.

### Does the AI generate unique content?

Yes. Each generation produces original content based on your site context, niche, and preferences. The optional duplicate detection feature (85% similarity threshold) prevents generating content too similar to your existing posts.

### Can I review posts before they go live?

Yes — set the **Default Post Status** to "Draft" (recommended) or "Pending Review". You can also set a **Minimum Content Score** to automatically hold low-quality generations as drafts.

### How much does it cost to run?

The plugin itself is free/included. You pay only for AI API usage. A typical 1,500-word post costs approximately $0.01-0.05 depending on your provider and model. Enable **Cost Tracking** in Advanced Settings to monitor usage.

### Can I extend the SEO audit with custom checks?

Yes — use the `swps_audit_modules` filter to register your own audit module. Your module should extend the `SWPS_Audit_Module` abstract class.

### Can I use the meta editor alongside Yoast/RankMath?

Yes! StrataWP SEO automatically detects these plugins and disables its own meta tag output to prevent conflicts. The meta editor fields are still available for reference, but frontend output is deferred to the other plugin.

### Does the schema markup support custom post types?

Currently, Article schema outputs on standard `post` type only. Breadcrumb schema works on all post types and taxonomies. You can extend schema output for custom post types using the `swps_schema_article` filter.

### Is the on-site analytics GDPR compliant?

Yes. The built-in analytics tracker is cookie-free and does not use any external tracking services. No personal data is stored — it records only page URL, referrer, time on page, scroll depth, and bounce status. No consent banner is required.

### Do I need Google Search Console credentials?

No — GSC integration is optional. The on-site analytics (page views, time on page, scroll depth, bounce rate) works entirely without any external services. Add Google OAuth credentials only if you want to see search clicks, impressions, CTR, and ranking data alongside your on-site metrics.

---

## Changelog

### 3.0.0 — Yoast Replacement

- **Full Sitemap System** — Sitemap index with post type, taxonomy, and author sub-sitemaps. Per-URL priority/changefreq control. Image sitemap entries. IndexNow support for instant indexing.
- **Search Appearance** — Configurable title/description templates for all content types with template variables. Title separator picker.
- **Taxonomy & Archive SEO** — Meta title, description, canonical URL, robots directives, and OG tags on category/tag/taxonomy edit screens with frontend output on archive pages.
- **Redirect Manager** — 301/302/307/410 redirects with exact and regex matching. 404 error monitoring with one-click redirect creation. Auto-redirect on slug change.
- **Frontend Breadcrumbs** — HTML breadcrumb output with inline schema markup. Template function, shortcode, and configurable separator/home label.
- **RSS Feed Optimization** — Configurable before/after content in RSS feed items with template variables.
- **wp_head Cleanup** — Toggle-based removal of WP generator tag, RSD link, shortlink, REST API link, oEmbed, and emoji scripts.

### 2.3.0
- Added: Keyword Research & Tracking — AI-powered keyword suggestions and GSC rank tracking
- Added: SEO Meta Editor — per-post meta title, description, social previews, and robots controls
- Added: Live SERP preview with character counters in post editor
- Added: SEO checklist with focus keyword analysis
- Added: Striking distance keyword opportunities (position 8-20)
- Added: AI-powered meta title/description generation
- Added: Conflict detection — auto-disables meta output when Yoast/RankMath/AIOSEO is active
- Added: 5 new developer hooks for meta/keyword extensibility

### 2.2.0
- Added on-site analytics tracking (page views, time on page, scroll depth, bounce rate)
- Added Google Search Console OAuth integration (clicks, impressions, CTR, position)
- Added unified analytics dashboard with SVG charts, metric cards, and date range filtering
- Added per-post analytics metabox on the post editor
- Added sortable "Views (30d)" column on the posts list table
- Added configurable data retention (30/90/180/365 days) with automatic pruning
- Added 3 new developer hooks (`swps_analytics_track`, `swps_analytics_exclude`, `swps_gsc_data`)

### 2.1.0
- Added Schema / Structured Data (Article, Breadcrumb, WebSite, Organization/Person JSON-LD)
- Added conflict detection for Yoast, RankMath, and AIOSEO schema
- Added 7 schema settings fields with developer filter hooks
- Version bump for cache busting after SEO Audit release

### 2.0.0
- Added Technical SEO Audit with 8 modules (Canonical, Sitemap, OG, Twitter, Robots.txt, Meta Robots, Image SEO, Page Speed)
- Added auto-fix for canonical tags, OG/Twitter meta, and sitemap generation
- Added dashboard widget with site health score
- Added voice profiles for reusable writing personas
- Added in-content image insertion (1-4 images per post)
- Added content scoring with quality gate
- Added topic queue and content calendar
- Added cost tracking and rate limiting
- Added duplicate detection
- Added bulk generation (up to 5 posts)
- Added WP-CLI commands (`generate`, `analyze`, `status`, `queue`)
- Added REST API
- Added multi-provider support (Anthropic, OpenAI, Google, xAI)
- Added multi-provider image support (Unsplash, Pexels, Pixabay, DALL-E)
- Added 7 content templates
- Added Key Takeaways with schema markup
- Added background processing
- Added 20+ developer hooks

### 1.0.0
- Initial release
- AI content generation with Anthropic Claude
- Featured images via Unsplash
- FAQ schema generation
- Internal linking
- Scheduled publishing

---

## License

StrataWP SEO is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
