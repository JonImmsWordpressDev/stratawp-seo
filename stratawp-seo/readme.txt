=== StrataWP SEO ===
Contributors: jonimms
Tags: seo, ai, content-generator, blog, internal-linking
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered SEO content generator that knows your WordPress site. Generate optimized blog posts with smart internal linking, on autopilot.

== Description ==

StrataWP SEO uses Claude AI to generate SEO-optimized blog posts that are aware of your existing site content. Unlike generic AI writing tools, StrataWP SEO reads your published posts, understands your niche, and creates content with natural internal links to your existing pages.

**Features:**

* **Site-Aware Content Generation** — The AI reads your existing posts and creates content that fits your site
* **Smart Internal Linking** — Every generated post includes natural internal links to your existing content
* **SEO Optimization** — Proper heading hierarchy, meta descriptions, focus keywords, and FAQ schema
* **Yoast & RankMath Compatible** — Automatically populates SEO plugin meta fields
* **Scheduled Auto-Publishing** — Set it and forget it with WP-Cron powered scheduling
* **Customizable Voice** — Configure tone, style, and writing preferences
* **FAQ Schema** — Automatic FAQ structured data for rich snippets

== Installation ==

1. Upload the `stratawp-seo` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to StrataWP SEO → Settings and enter your Anthropic API key
4. Configure your site details and writing preferences
5. Go to StrataWP SEO → Generate Content to create your first post

== Frequently Asked Questions ==

= Do I need an Anthropic API key? =

Yes. StrataWP SEO uses the Claude AI API. You can get an API key at console.anthropic.com. Typical cost per generated post is $0.05-0.10.

= Will it publish posts automatically? =

By default, posts are saved as drafts for your review. You can change this to auto-publish in settings, or enable scheduled generation.

= Does it work with Yoast SEO / RankMath? =

Yes! Generated posts automatically populate meta descriptions and focus keywords for both Yoast SEO and RankMath.

== Changelog ==

= 1.0.0 =
* Initial release
