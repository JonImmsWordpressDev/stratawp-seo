# Schema / Structured Data — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add automatic JSON-LD structured data (Article, Breadcrumb, WebSite, Organization/Person) to all posts and pages, with conflict detection for Yoast/RankMath/AIOSEO.

**Architecture:** Single `SWPS_Schema` class hooked to `wp_head` priority 1. Outputs up to 4 JSON-LD blocks based on page context. Settings stored as `swps_schema_*` options. Filters via `SWPS_Hooks` for developer extensibility.

**Tech Stack:** PHP 8.0+, WordPress Settings API, JSON-LD via `wp_json_encode()`, no external dependencies.

---

### Task 1: Create `SWPS_Schema` Class — Skeleton + Conflict Detection + Output Helper

**Files:**
- Create: `includes/class-schema.php`

**Context:** This is the core class. The constructor checks for competing plugins and registers the `wp_head` hook only when safe. A private `output_jsonld()` helper DRYs the JSON-LD script tag output.

**Code:**

```php
<?php
/**
 * Schema / Structured Data output.
 *
 * Outputs JSON-LD structured data on the frontend for Article,
 * Breadcrumb, WebSite, and Organization/Person schema types.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SWPS_Schema
 *
 * Hooked to wp_head at priority 1. Auto-detects Yoast, RankMath,
 * and AIOSEO — defers entirely when any is active.
 */
class SWPS_Schema {

    /**
     * Constructor — bail if schema is disabled or another SEO plugin handles it.
     */
    public function __construct() {
        if ( is_admin() ) {
            return;
        }

        if ( ! get_option( 'swps_schema_enabled', 1 ) ) {
            return;
        }

        // Defer to other SEO plugins that output their own schema.
        if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
            return;
        }

        add_action( 'wp_head', [ $this, 'output_schema' ], 1 );
    }

    /**
     * Main wp_head callback — dispatches to individual schema methods.
     */
    public function output_schema(): void {
        if ( is_front_page() ) {
            $this->website_schema();
            $this->organization_schema();
        }

        if ( is_singular( 'post' ) ) {
            $this->article_schema();
        }

        if ( ! is_front_page() ) {
            $this->breadcrumb_schema();
        }
    }

    /**
     * Output a JSON-LD script tag from a schema array.
     *
     * @param array $schema Schema data array.
     */
    private function output_jsonld( array $schema ): void {
        if ( empty( $schema ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD script tag.
        echo '<script type="application/ld+json">'
           . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
           . '</script>' . "\n";
    }

    /**
     * Article schema placeholder — implemented in Task 2.
     */
    private function article_schema(): void {}

    /**
     * Breadcrumb schema placeholder — implemented in Task 3.
     */
    private function breadcrumb_schema(): void {}

    /**
     * WebSite schema placeholder — implemented in Task 4.
     */
    private function website_schema(): void {}

    /**
     * Organization/Person schema placeholder — implemented in Task 4.
     */
    private function organization_schema(): void {}
}
```

**Commit:** `feat: Add SWPS_Schema skeleton with conflict detection and JSON-LD helper`

---

### Task 2: Article Schema

**Files:**
- Modify: `includes/class-schema.php` — replace `article_schema()` placeholder

**Context:** Outputs `Article` (or `BlogPosting`/`NewsArticle`) JSON-LD on single posts. Pulls data from WP post object, author, featured image. References the Organization from settings as publisher.

**Code — replace the `article_schema()` method:**

```php
    /**
     * Output Article schema on single posts.
     */
    private function article_schema(): void {
        $post = get_post();

        if ( ! $post ) {
            return;
        }

        $article_type = get_option( 'swps_schema_article_type', 'Article' );
        $author       = get_userdata( $post->post_author );
        $excerpt      = get_the_excerpt( $post );

        if ( empty( $excerpt ) ) {
            $excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 25, '...' );
        }

        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => $article_type,
            'headline'         => get_the_title( $post ),
            'description'      => $excerpt,
            'datePublished'    => get_the_date( 'c', $post ),
            'dateModified'     => get_the_modified_date( 'c', $post ),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => get_permalink( $post ),
            ],
            'wordCount'        => str_word_count( wp_strip_all_tags( $post->post_content ) ),
        ];

        // Author.
        if ( $author ) {
            $schema['author'] = [
                '@type' => 'Person',
                'name'  => $author->display_name,
                'url'   => get_author_posts_url( $author->ID ),
            ];
        }

        // Featured image.
        $thumb_id = get_post_thumbnail_id( $post );

        if ( $thumb_id ) {
            $img_data = wp_get_attachment_image_src( $thumb_id, 'full' );

            if ( $img_data ) {
                $schema['image'] = [
                    '@type'  => 'ImageObject',
                    'url'    => $img_data[0],
                    'width'  => $img_data[1],
                    'height' => $img_data[2],
                ];
            }
        }

        // Publisher — references Organization settings.
        $org_name = get_option( 'swps_schema_name', '' );

        if ( empty( $org_name ) ) {
            $org_name = get_bloginfo( 'name' );
        }

        $publisher = [
            '@type' => 'Organization',
            'name'  => $org_name,
        ];

        $logo_url = get_option( 'swps_schema_logo', '' );

        if ( ! empty( $logo_url ) ) {
            $publisher['logo'] = [
                '@type' => 'ImageObject',
                'url'   => $logo_url,
            ];
        }

        $schema['publisher'] = $publisher;

        /**
         * Filter the Article schema before output.
         *
         * @param array $schema  Article schema array.
         * @param int   $post_id Post ID.
         */
        $schema = SWPS_Hooks::filter_schema_article( $schema, $post->ID );

        $this->output_jsonld( $schema );
    }
```

**Commit:** `feat: Add Article schema output on single posts`

---

### Task 3: Breadcrumb Schema

**Files:**
- Modify: `includes/class-schema.php` — replace `breadcrumb_schema()` placeholder

**Context:** Outputs `BreadcrumbList` on all pages except homepage. Builds trail from WP hierarchy: Home → Category → Post (for posts), Home → Parent → Child (for pages), Home → Archive Name (for archives).

**Code — replace the `breadcrumb_schema()` method:**

```php
    /**
     * Output BreadcrumbList schema on all pages except homepage.
     */
    private function breadcrumb_schema(): void {
        $items = [];

        // Home is always first.
        $items[] = [
            'name' => get_bloginfo( 'name' ),
            'url'  => home_url( '/' ),
        ];

        if ( is_singular( 'post' ) ) {
            $post       = get_post();
            $category   = $this->get_primary_category( $post );

            if ( $category ) {
                $items[] = [
                    'name' => $category->name,
                    'url'  => get_category_link( $category->term_id ),
                ];
            }

            $items[] = [
                'name' => get_the_title( $post ),
                'url'  => get_permalink( $post ),
            ];
        } elseif ( is_page() ) {
            $post      = get_post();
            $ancestors = array_reverse( get_post_ancestors( $post ) );

            foreach ( $ancestors as $ancestor_id ) {
                $items[] = [
                    'name' => get_the_title( $ancestor_id ),
                    'url'  => get_permalink( $ancestor_id ),
                ];
            }

            $items[] = [
                'name' => get_the_title( $post ),
                'url'  => get_permalink( $post ),
            ];
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();

            if ( $term ) {
                $items[] = [
                    'name' => $term->name,
                    'url'  => get_term_link( $term ),
                ];
            }
        } elseif ( is_author() ) {
            $author = get_queried_object();

            if ( $author ) {
                $items[] = [
                    'name' => $author->display_name,
                    'url'  => get_author_posts_url( $author->ID ),
                ];
            }
        }

        // Need at least 2 items (Home + something) for a valid breadcrumb.
        if ( count( $items ) < 2 ) {
            return;
        }

        $list_items = [];

        foreach ( $items as $position => $item ) {
            $list_items[] = [
                '@type'    => 'ListItem',
                'position' => $position + 1,
                'name'     => $item['name'],
                'item'     => $item['url'],
            ];
        }

        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $list_items,
        ];

        /** Filter the Breadcrumb schema before output. */
        $schema = SWPS_Hooks::filter_schema_breadcrumb( $schema );

        $this->output_jsonld( $schema );
    }

    /**
     * Get the primary category for a post.
     *
     * Uses Yoast primary category if set, otherwise falls back to first category.
     *
     * @param WP_Post $post Post object.
     * @return WP_Term|null Category term or null.
     */
    private function get_primary_category( WP_Post $post ): ?WP_Term {
        // Check Yoast primary category first.
        $primary_id = get_post_meta( $post->ID, '_yoast_wpseo_primary_category', true );

        if ( $primary_id ) {
            $term = get_term( (int) $primary_id, 'category' );

            if ( $term && ! is_wp_error( $term ) ) {
                return $term;
            }
        }

        // Fall back to first assigned category.
        $categories = get_the_category( $post->ID );

        if ( ! empty( $categories ) ) {
            return $categories[0];
        }

        return null;
    }
```

**Commit:** `feat: Add Breadcrumb schema with primary category detection`

---

### Task 4: WebSite + Organization Schema

**Files:**
- Modify: `includes/class-schema.php` — replace `website_schema()` and `organization_schema()` placeholders

**Context:** Both output on homepage only. WebSite includes optional SearchAction for sitelinks searchbox. Organization/Person uses settings fields. The entity type dropdown controls `@type`.

**Code — replace both methods:**

```php
    /**
     * Output WebSite schema on the homepage.
     */
    private function website_schema(): void {
        $name = get_option( 'swps_schema_name', '' );

        if ( empty( $name ) ) {
            $name = get_bloginfo( 'name' );
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $name,
            'url'      => home_url( '/' ),
        ];

        // Optional sitelinks searchbox.
        if ( get_option( 'swps_schema_searchbox', 1 ) ) {
            $schema['potentialAction'] = [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => home_url( '/?s={search_term_string}' ),
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        $this->output_jsonld( $schema );
    }

    /**
     * Output Organization or Person schema on the homepage.
     */
    private function organization_schema(): void {
        $entity_type = get_option( 'swps_schema_entity_type', 'Organization' );
        $name        = get_option( 'swps_schema_name', '' );

        if ( empty( $name ) ) {
            $name = get_bloginfo( 'name' );
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => $entity_type,
            'name'     => $name,
            'url'      => home_url( '/' ),
        ];

        // Logo (Organization only, per Google spec).
        if ( 'Organization' === $entity_type ) {
            $logo_url = get_option( 'swps_schema_logo', '' );

            if ( ! empty( $logo_url ) ) {
                $schema['logo'] = [
                    '@type' => 'ImageObject',
                    'url'   => $logo_url,
                ];
            }
        }

        // Social profiles.
        $social_raw = get_option( 'swps_schema_social_profiles', '' );

        if ( ! empty( $social_raw ) ) {
            $urls = array_filter( array_map( 'trim', explode( "\n", $social_raw ) ) );

            if ( ! empty( $urls ) ) {
                $schema['sameAs'] = array_values( $urls );
            }
        }

        /** Filter the Organization/Person schema before output. */
        $schema = SWPS_Hooks::filter_schema_organization( $schema );

        $this->output_jsonld( $schema );
    }
```

**Commit:** `feat: Add WebSite and Organization/Person schema on homepage`

---

### Task 5: Settings — Schema Section

**Files:**
- Modify: `includes/class-settings.php` — add Schema section after Audit section (after line 317)

**Context:** 7 fields in a new "Schema / Structured Data" section. Uses existing `add_field()` helper. All field types (checkbox, select, text, textarea) are already supported by `render_field()`.

**Code — insert after the SEO Audit section (after `audit_cron_schedule` field):**

```php
        // --- Schema / Structured Data Section ---
        add_settings_section( 'swps_schema_section', __( 'Schema / Structured Data', 'stratawp-seo' ), [ $this, 'render_schema_section' ], 'stratawp-seo' );

        $this->add_field( 'schema_enabled', __( 'Enable Schema Markup', 'stratawp-seo' ), 'checkbox', 'swps_schema_section', [
            'label' => __( 'Output JSON-LD structured data on all posts and pages (auto-disabled when Yoast, RankMath, or AIOSEO is active)', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_article_type', __( 'Article Type', 'stratawp-seo' ), 'select', 'swps_schema_section', [
            'options' => [
                'Article'      => __( 'Article', 'stratawp-seo' ),
                'BlogPosting'  => __( 'BlogPosting', 'stratawp-seo' ),
                'NewsArticle'  => __( 'NewsArticle', 'stratawp-seo' ),
            ],
            'description' => __( 'Schema type for blog posts. Most sites should use Article.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_searchbox', __( 'Sitelinks Searchbox', 'stratawp-seo' ), 'checkbox', 'swps_schema_section', [
            'label' => __( 'Add SearchAction to enable Google sitelinks search box on your homepage', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_entity_type', __( 'Site Represents', 'stratawp-seo' ), 'select', 'swps_schema_section', [
            'options' => [
                'Organization' => __( 'Organization', 'stratawp-seo' ),
                'Person'       => __( 'Person', 'stratawp-seo' ),
            ],
            'description' => __( 'Does this website represent an organization or an individual?', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_name', __( 'Name', 'stratawp-seo' ), 'text', 'swps_schema_section', [
            'placeholder' => get_bloginfo( 'name' ),
            'description' => __( 'Organization or person name. Leave blank to use your site name.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_logo', __( 'Logo URL', 'stratawp-seo' ), 'text', 'swps_schema_section', [
            'placeholder' => 'https://example.com/logo.png',
            'description' => __( 'Full URL to your logo image (minimum 112×112px). Used for Organization schema and Article publisher.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_social_profiles', __( 'Social Profiles', 'stratawp-seo' ), 'textarea', 'swps_schema_section', [
            'rows'        => 4,
            'placeholder' => "https://facebook.com/yourpage\nhttps://twitter.com/yourhandle\nhttps://linkedin.com/company/yourcompany",
            'description' => __( 'One social profile URL per line. Populates the sameAs property.', 'stratawp-seo' ),
        ] );
```

**Also add the section render callback** (alongside existing `render_audit_section()`):

```php
    /**
     * Render the Schema settings section description.
     */
    public function render_schema_section(): void {
        echo '<p>' . esc_html__( 'Automatic JSON-LD structured data for rich results in Google. Disabled automatically when Yoast SEO, RankMath, or All in One SEO is active.', 'stratawp-seo' ) . '</p>';
    }
```

**Commit:** `feat: Add Schema settings section with 7 configuration fields`

---

### Task 6: Plugin Integration — Require, Instantiate, Hook

**Files:**
- Modify: `stratawp-seo.php` — 3 changes:
  1. Add `require_once` for `class-schema.php` (after line 78, with audit classes)
  2. Add `public SWPS_Schema $schema;` property (after line 122)
  3. Add `$this->schema = new SWPS_Schema();` instantiation (after line 147, after seo_audit)
  4. Add activation defaults for schema options (in the `get_activation_defaults()` method)

**Code for each change:**

**1. Require (insert after the audit class requires, before "Core classes" comment):**

```php
// Schema structured data.
require_once SWPS_PLUGIN_DIR . 'includes/class-schema.php';
```

**2. Property (insert after `public SWPS_SEO_Audit $seo_audit;`):**

```php
    public SWPS_Schema $schema;
```

**3. Instantiation (insert after `$this->seo_audit = new SWPS_SEO_Audit();`):**

```php
        $this->schema = new SWPS_Schema();
```

**4. Activation defaults — add to the defaults array (after `audit_cron_schedule`):**

```php
            'schema_enabled'         => 1,
            'schema_article_type'    => 'Article',
            'schema_searchbox'       => 1,
            'schema_entity_type'     => 'Organization',
            'schema_name'            => '',
            'schema_logo'            => '',
            'schema_social_profiles' => '',
```

**Commit:** `feat: Wire SWPS_Schema into plugin bootstrap and activation defaults`

---

### Task 7: Developer Hooks — Add Filter Helpers to `SWPS_Hooks`

**Files:**
- Modify: `includes/class-hooks.php` — add 3 static methods before the closing `}`

**Code — append before the closing brace of the class:**

```php
    /**
     * Apply the Article schema filter.
     *
     * @param array $schema  Article schema array.
     * @param int   $post_id Post ID.
     * @return array Filtered schema.
     */
    public static function filter_schema_article( array $schema, int $post_id ): array {
        return apply_filters( 'swps_schema_article', $schema, $post_id );
    }

    /**
     * Apply the Breadcrumb schema filter.
     *
     * @param array $schema BreadcrumbList schema array.
     * @return array Filtered schema.
     */
    public static function filter_schema_breadcrumb( array $schema ): array {
        return apply_filters( 'swps_schema_breadcrumb', $schema );
    }

    /**
     * Apply the Organization/Person schema filter.
     *
     * @param array $schema Organization or Person schema array.
     * @return array Filtered schema.
     */
    public static function filter_schema_organization( array $schema ): array {
        return apply_filters( 'swps_schema_organization', $schema );
    }
```

**Commit:** `feat: Add schema filter helpers to SWPS_Hooks`

---

### Task 8: PHP Lint + Final Review

**Files:**
- All modified files

**Steps:**

1. Run `php -l` on all 4 files:
   ```bash
   php -l includes/class-schema.php
   php -l includes/class-settings.php
   php -l includes/class-hooks.php
   php -l stratawp-seo.php
   ```
   Expected: `No syntax errors detected` for all.

2. Verify the complete `class-schema.php` reads cleanly as a single coherent file.

3. Verify activation defaults are present and match the settings fields.

4. Verify no duplicate option keys or conflicting hook names.

**Commit:** No commit needed unless fixes are required.
