# StrataWP SEO

**AI-powered SEO content generator that knows your WordPress site.** Generate optimized blog posts with internal linking, structured data, and technical SEO — on autopilot.

[![Version](https://img.shields.io/badge/version-2.1.0-blue.svg)]()
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

### Developer Features
- **20+ filters and actions** — extend every part of the generation pipeline
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

### Does the schema markup support custom post types?

Currently, Article schema outputs on standard `post` type only. Breadcrumb schema works on all post types and taxonomies. You can extend schema output for custom post types using the `swps_schema_article` filter.

---

## Changelog

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
