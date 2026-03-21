# StrataWP SEO v3.0.0 — Yoast Replacement Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close all feature gaps with Yoast/RankMath — full sitemap management, search appearance templates, taxonomy SEO, redirect manager, frontend breadcrumbs, RSS optimization, and wp_head cleanup — enabling users to fully uninstall competing SEO plugins.

**Architecture:** Modular extension following existing `SWPS_` prefix, class-per-file pattern. Each feature is a standalone class loaded in `stratawp-seo.php`, initialized in the `StrataWP_SEO` constructor, with settings registered in `SWPS_Settings`. No namespaces. No directory restructuring.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, vanilla JS, WordPress Settings API, `dbDelta` for DB tables.

**Spec:** `docs/superpowers/specs/2026-03-21-v3-yoast-replacement-design.md`

---

## File Structure

### New Files (9 PHP classes + 3 templates + 2 JS)

| File | Class | Responsibility |
|---|---|---|
| `includes/class-sitemap-manager.php` | `SWPS_Sitemap_Manager` | Sitemap index + sub-sitemaps, rewrite rules, search engine pinging, IndexNow |
| `includes/class-sitemap-admin.php` | `SWPS_Sitemap_Admin` | Sitemap settings UI in admin |
| `includes/class-search-appearance.php` | `SWPS_Search_Appearance` | Title/description templates, separator, frontend output for all page types |
| `includes/class-taxonomy-meta.php` | `SWPS_Taxonomy_Meta` | SEO fields on term edit screens, archive meta output |
| `includes/class-redirect-manager.php` | `SWPS_Redirect_Manager` | Redirect storage/matching/execution, 404 logging, auto-redirect on slug change |
| `includes/class-redirect-admin.php` | `SWPS_Redirect_Admin` | Redirects admin page + 404 monitor tab |
| `includes/class-breadcrumbs.php` | `SWPS_Breadcrumbs` | HTML breadcrumb output with inline schema, shortcode, template function |
| `includes/class-rss-optimizer.php` | `SWPS_RSS_Optimizer` | Before/after content in RSS feeds |
| `includes/class-head-cleanup.php` | `SWPS_Head_Cleanup` | Toggle-based removal of WP default head output |
| `templates/search-appearance-page.php` | — | Search Appearance admin page template |
| `templates/redirects-page.php` | — | Redirects admin page template |
| `admin/js/redirects.js` | — | Redirect page AJAX + table interactivity |
| `admin/js/search-appearance.js` | — | Live template variable preview |

### Modified Files

| File | Changes |
|---|---|
| `stratawp-seo.php` | Version bump 2.3.0→3.0.0, `require_once` for 9 new classes, new properties + initialization in constructor, new AJAX handlers, activation hook additions |
| `uninstall.php` | `DROP TABLE` for `wp_swps_redirects` and `wp_swps_404_log`, clean up new term meta and options |
| `includes/class-settings.php` | New admin menu pages (Search Appearance, Redirects), new settings sections (Sitemaps, Head Cleanup, RSS, Title Separator) |
| `includes/class-schema.php` | Gate `breadcrumb_schema()` call — skip when `SWPS_Breadcrumbs` is active |
| `includes/class-meta-editor.php` | Add sitemap exclude/priority/changefreq fields to metabox |
| `includes/audit/class-sitemap-module.php` | Delegate generation to `SWPS_Sitemap_Manager`, update `detect_other_sitemap()` |
| `templates/meta-editor-metabox.php` | Add sitemap fields UI |
| `admin/js/meta-editor.js` | Handle new sitemap fields |
| `admin/css/admin.css` | Styles for new pages |
| `README.md` | Update feature list and changelog |
| `readme.txt` | Update stable tag and changelog |

---

## Chunk 1: Foundation + Simple Features

### Task 1: Version Bump

**Files:**
- Modify: `stratawp-seo.php:3-20`

- [ ] **Step 1: Update version constants and header**

In `stratawp-seo.php`, change the plugin header `Version:` from `2.3.0` to `3.0.0`, and the `SWPS_VERSION` constant from `'2.3.0'` to `'3.0.0'`:

```php
// Line 6:
 * Version: 3.0.0

// Line 20:
define( 'SWPS_VERSION', '3.0.0' );
```

- [ ] **Step 2: Update readme.txt stable tag**

In `readme.txt`, update `Stable tag:` to `3.0.0`.

- [ ] **Step 3: Verify PHP syntax**

Run: `php -l stratawp-seo.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add stratawp-seo.php readme.txt
git commit -m "chore: bump version to 3.0.0"
```

---

### Task 2: Head Cleanup

**Files:**
- Create: `includes/class-head-cleanup.php`
- Modify: `stratawp-seo.php` (require + init)
- Modify: `includes/class-settings.php` (settings fields)

- [ ] **Step 1: Create `includes/class-head-cleanup.php`**

```php
<?php
/**
 * wp_head cleanup — toggle-based removal of default WordPress output.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Head_Cleanup {

    /**
     * Map of option keys to the WordPress hooks they remove.
     */
    private const CLEANUP_MAP = [
        'swps_cleanup_generator' => [ 'wp_generator' ],
        'swps_cleanup_rsd'       => [ 'rsd_link' ],
        'swps_cleanup_wlw'       => [ 'wlwmanifest_link' ],
        'swps_cleanup_shortlink' => [ 'wp_shortlink_wp_head' ],
        'swps_cleanup_rest_api'  => [ 'rest_output_link_wp_head', 'wp_oembed_add_discovery_links' ],
        'swps_cleanup_oembed'    => [ 'wp_oembed_add_host_js' ],
    ];

    public function __construct() {
        if ( is_admin() ) {
            return;
        }

        foreach ( self::CLEANUP_MAP as $option => $hooks ) {
            if ( get_option( $option, 0 ) ) {
                foreach ( $hooks as $hook ) {
                    remove_action( 'wp_head', $hook );
                }
            }
        }

        // Emoji removal is more involved — multiple hooks.
        if ( get_option( 'swps_cleanup_emoji', 0 ) ) {
            remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
            remove_action( 'wp_print_styles', 'print_emoji_styles' );
            remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
            remove_action( 'admin_print_styles', 'print_emoji_styles' );
            add_filter( 'emoji_svg_url', '__return_false' );
        }
    }
}
```

- [ ] **Step 2: Add require_once and initialization in `stratawp-seo.php`**

After the Meta Editor require (line 91), add:

```php
// v3.0 classes.
require_once SWPS_PLUGIN_DIR . 'includes/class-head-cleanup.php';
```

In the `StrataWP_SEO` class, add property:

```php
public SWPS_Head_Cleanup $head_cleanup;
```

In constructor, after `$this->meta_editor` initialization (around line 174):

```php
$this->head_cleanup = new SWPS_Head_Cleanup();
```

- [ ] **Step 3: Register settings in `SWPS_Settings::register_settings()`**

Add a new section at the end of `register_settings()`:

```php
// --- Head Cleanup Section ---
add_settings_section( 'swps_cleanup_section', __( 'Head Cleanup', 'stratawp-seo' ), [ $this, 'render_cleanup_section' ], 'stratawp-seo' );

$cleanup_fields = [
    'cleanup_generator' => __( 'Remove WP Generator Tag', 'stratawp-seo' ),
    'cleanup_rsd'       => __( 'Remove RSD/EditURI Link', 'stratawp-seo' ),
    'cleanup_wlw'       => __( 'Remove Windows Live Writer Link', 'stratawp-seo' ),
    'cleanup_shortlink' => __( 'Remove Shortlink', 'stratawp-seo' ),
    'cleanup_rest_api'  => __( 'Remove REST API Link', 'stratawp-seo' ),
    'cleanup_oembed'    => __( 'Remove oEmbed Discovery', 'stratawp-seo' ),
    'cleanup_emoji'     => __( 'Remove Emoji Scripts & Styles', 'stratawp-seo' ),
];

foreach ( $cleanup_fields as $key => $label ) {
    $this->add_field( $key, $label, 'checkbox', 'swps_cleanup_section' );
}
```

Add the section renderer method to `SWPS_Settings`:

```php
public function render_cleanup_section(): void {
    echo '<p>' . esc_html__( 'Remove unnecessary items from your site\'s &lt;head&gt; section to reduce page size.', 'stratawp-seo' ) . '</p>';
}
```

- [ ] **Step 4: Verify syntax**

Run: `php -l includes/class-head-cleanup.php && php -l stratawp-seo.php && php -l includes/class-settings.php`
Expected: No syntax errors

- [ ] **Step 5: Commit**

```bash
git add includes/class-head-cleanup.php stratawp-seo.php includes/class-settings.php
git commit -m "feat: add wp_head cleanup with toggle-based removal"
```

---

### Task 3: RSS Feed Optimizer

**Files:**
- Create: `includes/class-rss-optimizer.php`
- Modify: `stratawp-seo.php` (require + init)
- Modify: `includes/class-settings.php` (settings fields)

- [ ] **Step 1: Create `includes/class-rss-optimizer.php`**

```php
<?php
/**
 * RSS feed optimization — add content before/after feed items.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_RSS_Optimizer {

    public function __construct() {
        $before = get_option( 'swps_rss_before', '' );
        $after  = get_option( 'swps_rss_after', '' );

        if ( empty( $before ) && empty( $after ) ) {
            return;
        }

        add_filter( 'the_content_feed', [ $this, 'filter_feed_content' ] );
    }

    /**
     * Add configured content before/after feed items.
     */
    public function filter_feed_content( string $content ): string {
        $before = get_option( 'swps_rss_before', '' );
        $after  = get_option( 'swps_rss_after', '' );

        if ( ! empty( $before ) ) {
            $content = '<p>' . $this->replace_variables( $before ) . '</p>' . $content;
        }

        if ( ! empty( $after ) ) {
            $content .= '<p>' . $this->replace_variables( $after ) . '</p>';
        }

        return $content;
    }

    /**
     * Replace template variables in RSS content strings.
     */
    private function replace_variables( string $text ): string {
        $post_link = '<a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a>';
        $blog_link = '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>';
        $blog_name = esc_html( get_bloginfo( 'name' ) );

        return str_replace(
            [ '%%post_link%%', '%%blog_link%%', '%%blog_name%%' ],
            [ $post_link, $blog_link, $blog_name ],
            $text
        );
    }
}
```

- [ ] **Step 2: Add require_once and initialization in `stratawp-seo.php`**

After the head cleanup require:

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-rss-optimizer.php';
```

Add property and constructor init:

```php
public SWPS_RSS_Optimizer $rss_optimizer;
// In constructor:
$this->rss_optimizer = new SWPS_RSS_Optimizer();
```

- [ ] **Step 3: Register settings in `SWPS_Settings::register_settings()`**

```php
// --- RSS Optimization Section ---
add_settings_section( 'swps_rss_section', __( 'RSS Feed', 'stratawp-seo' ), [ $this, 'render_rss_section' ], 'stratawp-seo' );

$this->add_field( 'rss_before', __( 'Content Before Post in RSS', 'stratawp-seo' ), 'textarea', 'swps_rss_section' );
$this->add_field( 'rss_after', __( 'Content After Post in RSS', 'stratawp-seo' ), 'textarea', 'swps_rss_section' );
```

Add renderer:

```php
public function render_rss_section(): void {
    echo '<p>' . esc_html__( 'Add content before or after posts in your RSS feed. Available variables: %%post_link%%, %%blog_link%%, %%blog_name%%', 'stratawp-seo' ) . '</p>';
}
```

- [ ] **Step 4: Set default in activation hook**

In `swps_activate()` defaults array, add:

```php
'rss_before' => '',
'rss_after'  => 'The post %%post_link%% appeared first on %%blog_link%%.',
```

- [ ] **Step 5: Verify syntax**

Run: `php -l includes/class-rss-optimizer.php`
Expected: No syntax errors

- [ ] **Step 6: Commit**

```bash
git add includes/class-rss-optimizer.php stratawp-seo.php includes/class-settings.php
git commit -m "feat: add RSS feed optimization with before/after content"
```

---

## Chunk 2: Search Appearance + Title Separator

### Task 4: Search Appearance Class

**Files:**
- Create: `includes/class-search-appearance.php`
- Create: `templates/search-appearance-page.php`
- Create: `admin/js/search-appearance.js`
- Modify: `stratawp-seo.php` (require + init)
- Modify: `includes/class-settings.php` (admin menu page)

- [ ] **Step 1: Create `includes/class-search-appearance.php`**

```php
<?php
/**
 * Search Appearance — title/description templates and frontend output.
 *
 * Hooks document_title_parts at priority 10 (before Meta Editor at 20)
 * so per-post overrides always win. Handles all non-singular pages
 * (archives, taxonomies, search, 404) and provides template fallbacks
 * for singular pages without per-post meta.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Search_Appearance {

    /**
     * Supported template variables.
     */
    private const VARIABLES = [
        '%%title%%', '%%sitename%%', '%%sep%%', '%%excerpt%%',
        '%%category%%', '%%tag%%', '%%author%%', '%%date%%',
        '%%page%%', '%%searchphrase%%', '%%pt_single%%', '%%pt_plural%%',
    ];

    public function __construct() {
        if ( is_admin() ) {
            return;
        }

        // Conflict detection — defer when competing plugins active.
        if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
            return;
        }

        // Title template — priority 10 (Meta Editor overrides at 20).
        add_filter( 'document_title_parts', [ $this, 'filter_title_parts' ], 10 );
        add_filter( 'document_title_separator', [ $this, 'filter_separator' ], 10 );

        // Meta description for non-singular pages — priority 2 (same as Meta Editor).
        add_action( 'wp_head', [ $this, 'output_meta_description' ], 2 );
    }

    /**
     * Filter the document title parts array.
     */
    public function filter_title_parts( array $title_parts ): array {
        $template = $this->get_title_template();

        if ( empty( $template ) ) {
            return $title_parts;
        }

        $resolved = $this->resolve_variables( $template );
        $title_parts['title'] = $resolved;

        // Remove site name from parts since template includes it.
        unset( $title_parts['site'], $title_parts['tagline'] );

        return $title_parts;
    }

    /**
     * Filter the title separator.
     */
    public function filter_separator( string $sep ): string {
        $custom = get_option( 'swps_title_separator', '-' );
        return ! empty( $custom ) ? $custom : $sep;
    }

    /**
     * Output meta description for non-singular pages.
     * Singular pages are handled by SWPS_Meta_Editor.
     */
    public function output_meta_description(): void {
        // Singular pages: only output if Meta Editor hasn't set one.
        if ( is_singular() ) {
            $post_desc = get_post_meta( get_the_ID(), '_swps_meta_description', true );
            if ( ! empty( $post_desc ) ) {
                return; // Meta Editor handles it.
            }
        }

        $description = $this->get_description();

        if ( ! empty( $description ) ) {
            printf(
                '<meta name="description" content="%s" />' . "\n",
                esc_attr( wp_strip_all_tags( $description ) )
            );
        }
    }

    /**
     * Get the title template for the current page context.
     */
    private function get_title_template(): string {
        if ( is_front_page() && is_home() ) {
            return get_option( 'swps_title_template_homepage', '%%sitename%%' );
        }

        if ( is_singular() ) {
            $post_type = get_post_type();
            return get_option( "swps_title_template_{$post_type}", '%%title%% %%sep%% %%sitename%%' );
        }

        if ( is_category() ) {
            return get_option( 'swps_title_template_category', '%%title%% Archives %%sep%% %%sitename%%' );
        }

        if ( is_tag() ) {
            return get_option( 'swps_title_template_post_tag', '%%title%% %%sep%% %%sitename%%' );
        }

        if ( is_tax() ) {
            $taxonomy = get_queried_object()->taxonomy ?? '';
            return get_option( "swps_title_template_{$taxonomy}", '%%title%% %%sep%% %%sitename%%' );
        }

        if ( is_author() ) {
            return get_option( 'swps_title_template_author', '%%author%% %%sep%% %%sitename%%' );
        }

        if ( is_post_type_archive() ) {
            $post_type = get_query_var( 'post_type' );
            if ( is_array( $post_type ) ) {
                $post_type = reset( $post_type );
            }
            return get_option( "swps_title_template_{$post_type}_archive", '%%pt_plural%% %%sep%% %%sitename%%' );
        }

        if ( is_date() ) {
            return get_option( 'swps_title_template_date', '%%title%% %%sep%% %%sitename%%' );
        }

        if ( is_search() ) {
            return get_option( 'swps_title_template_search', 'Search: %%searchphrase%% %%sep%% %%sitename%%' );
        }

        if ( is_404() ) {
            return get_option( 'swps_title_template_404', 'Page Not Found %%sep%% %%sitename%%' );
        }

        return '';
    }

    /**
     * Get the meta description for the current page context.
     */
    private function get_description(): string {
        if ( is_singular() ) {
            $post_type = get_post_type();
            $template  = get_option( "swps_desc_template_{$post_type}", '%%excerpt%%' );
            return $this->resolve_variables( $template );
        }

        if ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            if ( $term && ! empty( $term->description ) ) {
                return wp_trim_words( $term->description, 30 );
            }
            // Check for per-term meta description (from Taxonomy Meta).
            if ( $term ) {
                $meta_desc = get_term_meta( $term->term_id, '_swps_meta_description', true );
                if ( ! empty( $meta_desc ) ) {
                    return $meta_desc;
                }
            }
        }

        if ( is_author() ) {
            $author = get_queried_object();
            if ( $author ) {
                return wp_trim_words( get_the_author_meta( 'description', $author->ID ), 30 );
            }
        }

        return '';
    }

    /**
     * Resolve template variables to their current values.
     */
    public function resolve_variables( string $template ): string {
        $sep      = get_option( 'swps_title_separator', '-' );
        $sitename = get_bloginfo( 'name' );

        $replacements = [
            '%%sitename%%'     => $sitename,
            '%%sep%%'          => $sep,
            '%%searchphrase%%' => get_search_query(),
        ];

        // Context-specific replacements.
        if ( is_singular() ) {
            $post = get_queried_object();
            if ( $post ) {
                $replacements['%%title%%']    = $post->post_title;
                $replacements['%%excerpt%%']  = wp_trim_words( $post->post_excerpt ?: wp_strip_all_tags( $post->post_content ), 30 );
                $replacements['%%author%%']   = get_the_author_meta( 'display_name', $post->post_author );
                $replacements['%%date%%']     = get_the_date( '', $post );

                $categories = get_the_category( $post->ID );
                $replacements['%%category%%'] = ! empty( $categories ) ? $categories[0]->name : '';

                $tags = get_the_tags( $post->ID );
                $replacements['%%tag%%']      = ! empty( $tags ) ? $tags[0]->name : '';
            }
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            if ( $term ) {
                $replacements['%%title%%'] = $term->name;
            }
        } elseif ( is_author() ) {
            $author = get_queried_object();
            if ( $author ) {
                $replacements['%%title%%']  = $author->display_name;
                $replacements['%%author%%'] = $author->display_name;
            }
        } elseif ( is_post_type_archive() ) {
            $post_type = get_query_var( 'post_type' );
            if ( is_array( $post_type ) ) {
                $post_type = reset( $post_type );
            }
            $pto = get_post_type_object( $post_type );
            if ( $pto ) {
                $replacements['%%title%%']     = $pto->labels->name;
                $replacements['%%pt_single%%'] = $pto->labels->singular_name;
                $replacements['%%pt_plural%%'] = $pto->labels->name;
            }
        } elseif ( is_date() ) {
            if ( is_year() ) {
                $replacements['%%title%%'] = get_the_date( 'Y' );
            } elseif ( is_month() ) {
                $replacements['%%title%%'] = get_the_date( 'F Y' );
            } else {
                $replacements['%%title%%'] = get_the_date();
            }
        }

        // Pagination.
        $paged = get_query_var( 'paged', 0 );
        $replacements['%%page%%'] = $paged > 1 ? sprintf( __( 'Page %d', 'stratawp-seo' ), $paged ) : '';

        $result = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );

        // Clean up empty variables and double separators.
        $result = preg_replace( '/%%\w+%%/', '', $result );
        $result = preg_replace( '/\s+/', ' ', trim( $result ) );

        return $result;
    }
}
```

- [ ] **Step 2: Create `templates/search-appearance-page.php`**

```php
<?php
/**
 * Search Appearance admin page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-search-appearance">
    <h1><?php esc_html_e( 'Search Appearance', 'stratawp-seo' ); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields( 'swps_search_appearance' ); ?>

        <h2><?php esc_html_e( 'Title Separator', 'stratawp-seo' ); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Separator', 'stratawp-seo' ); ?></th>
                <td>
                    <?php
                    $current_sep = get_option( 'swps_title_separator', '-' );
                    $separators  = [ '|', '-', '–', '—', '·', '•', '»' ];
                    foreach ( $separators as $sep ) :
                    ?>
                        <label style="margin-right: 15px; cursor: pointer;">
                            <input type="radio" name="swps_title_separator" value="<?php echo esc_attr( $sep ); ?>"
                                <?php checked( $current_sep, $sep ); ?>>
                            <?php echo esc_html( $sep ); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <?php
        // Post types section.
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        foreach ( $post_types as $pt ) :
            if ( 'attachment' === $pt->name ) {
                continue;
            }
        ?>
            <h2><?php echo esc_html( $pt->labels->name ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Title Template', 'stratawp-seo' ); ?></th>
                    <td>
                        <input type="text" class="large-text swps-title-template"
                            name="swps_title_template_<?php echo esc_attr( $pt->name ); ?>"
                            value="<?php echo esc_attr( get_option( "swps_title_template_{$pt->name}", '%%title%% %%sep%% %%sitename%%' ) ); ?>">
                        <p class="description swps-template-preview"></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Description Template', 'stratawp-seo' ); ?></th>
                    <td>
                        <textarea class="large-text" rows="2"
                            name="swps_desc_template_<?php echo esc_attr( $pt->name ); ?>"
                        ><?php echo esc_textarea( get_option( "swps_desc_template_{$pt->name}", '%%excerpt%%' ) ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Show in Search Results', 'stratawp-seo' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="swps_noindex_<?php echo esc_attr( $pt->name ); ?>" value="1"
                                <?php checked( get_option( "swps_noindex_{$pt->name}", 0 ) ); ?>>
                            <?php esc_html_e( 'Noindex this post type (hide from search engines)', 'stratawp-seo' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
        <?php endforeach; ?>

        <?php
        // Taxonomies section.
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        foreach ( $taxonomies as $tax ) :
            if ( 'post_format' === $tax->name ) {
                continue;
            }
        ?>
            <h2><?php echo esc_html( $tax->labels->name ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Title Template', 'stratawp-seo' ); ?></th>
                    <td>
                        <input type="text" class="large-text swps-title-template"
                            name="swps_title_template_<?php echo esc_attr( $tax->name ); ?>"
                            value="<?php echo esc_attr( get_option( "swps_title_template_{$tax->name}", '%%title%% %%sep%% %%sitename%%' ) ); ?>">
                        <p class="description swps-template-preview"></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Show in Search Results', 'stratawp-seo' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="swps_noindex_<?php echo esc_attr( $tax->name ); ?>" value="1"
                                <?php checked( get_option( "swps_noindex_{$tax->name}", 0 ) ); ?>>
                            <?php esc_html_e( 'Noindex this taxonomy', 'stratawp-seo' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
        <?php endforeach; ?>

        <h2><?php esc_html_e( 'Special Pages', 'stratawp-seo' ); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Search Page Title', 'stratawp-seo' ); ?></th>
                <td>
                    <input type="text" class="large-text swps-title-template"
                        name="swps_title_template_search"
                        value="<?php echo esc_attr( get_option( 'swps_title_template_search', 'Search: %%searchphrase%% %%sep%% %%sitename%%' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( '404 Page Title', 'stratawp-seo' ); ?></th>
                <td>
                    <input type="text" class="large-text swps-title-template"
                        name="swps_title_template_404"
                        value="<?php echo esc_attr( get_option( 'swps_title_template_404', 'Page Not Found %%sep%% %%sitename%%' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Author Archive Title', 'stratawp-seo' ); ?></th>
                <td>
                    <input type="text" class="large-text swps-title-template"
                        name="swps_title_template_author"
                        value="<?php echo esc_attr( get_option( 'swps_title_template_author', '%%author%% %%sep%% %%sitename%%' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Date Archive Title', 'stratawp-seo' ); ?></th>
                <td>
                    <input type="text" class="large-text swps-title-template"
                        name="swps_title_template_date"
                        value="<?php echo esc_attr( get_option( 'swps_title_template_date', '%%title%% %%sep%% %%sitename%%' ) ); ?>">
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <div class="swps-template-help">
        <h3><?php esc_html_e( 'Available Variables', 'stratawp-seo' ); ?></h3>
        <ul>
            <li><code>%%title%%</code> — <?php esc_html_e( 'Post/page/term title', 'stratawp-seo' ); ?></li>
            <li><code>%%sitename%%</code> — <?php esc_html_e( 'Site name', 'stratawp-seo' ); ?></li>
            <li><code>%%sep%%</code> — <?php esc_html_e( 'Separator character', 'stratawp-seo' ); ?></li>
            <li><code>%%excerpt%%</code> — <?php esc_html_e( 'Post excerpt', 'stratawp-seo' ); ?></li>
            <li><code>%%category%%</code> — <?php esc_html_e( 'Primary category', 'stratawp-seo' ); ?></li>
            <li><code>%%author%%</code> — <?php esc_html_e( 'Author name', 'stratawp-seo' ); ?></li>
            <li><code>%%date%%</code> — <?php esc_html_e( 'Published date', 'stratawp-seo' ); ?></li>
            <li><code>%%searchphrase%%</code> — <?php esc_html_e( 'Search query', 'stratawp-seo' ); ?></li>
            <li><code>%%page%%</code> — <?php esc_html_e( 'Page number', 'stratawp-seo' ); ?></li>
        </ul>
    </div>
</div>
```

- [ ] **Step 3: Create `admin/js/search-appearance.js`**

```js
/**
 * Search Appearance — live template preview.
 */
(function () {
    'use strict';

    const sampleData = {
        '%%title%%': 'Sample Post Title',
        '%%sitename%%': document.querySelector('#swps-sitename')?.value || 'My Site',
        '%%sep%%': document.querySelector('input[name="swps_title_separator"]:checked')?.value || '-',
        '%%excerpt%%': 'This is a sample excerpt from a post...',
        '%%category%%': 'Uncategorized',
        '%%tag%%': 'sample',
        '%%author%%': 'Admin',
        '%%date%%': new Date().toLocaleDateString(),
        '%%page%%': '',
        '%%searchphrase%%': 'search query',
        '%%pt_single%%': 'Post',
        '%%pt_plural%%': 'Posts',
    };

    function resolveTemplate(template) {
        let result = template;
        for (const [variable, value] of Object.entries(sampleData)) {
            result = result.replaceAll(variable, value);
        }
        return result.replace(/%%\w+%%/g, '').replace(/\s+/g, ' ').trim();
    }

    function updatePreviews() {
        // Update separator in sample data.
        const sepEl = document.querySelector('input[name="swps_title_separator"]:checked');
        if (sepEl) {
            sampleData['%%sep%%'] = sepEl.value;
        }

        document.querySelectorAll('.swps-title-template').forEach(function (input) {
            const preview = input.parentElement.querySelector('.swps-template-preview');
            if (preview) {
                preview.textContent = 'Preview: ' + resolveTemplate(input.value);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Initial previews.
        updatePreviews();

        // Update on input change.
        document.querySelectorAll('.swps-title-template').forEach(function (input) {
            input.addEventListener('input', updatePreviews);
        });

        // Update on separator change.
        document.querySelectorAll('input[name="swps_title_separator"]').forEach(function (radio) {
            radio.addEventListener('change', updatePreviews);
        });
    });
})();
```

- [ ] **Step 4: Register admin page and settings in `SWPS_Settings`**

Add submenu page in `register_menu()`:

```php
add_submenu_page(
    'stratawp-seo',
    __( 'Search Appearance', 'stratawp-seo' ),
    __( 'Search Appearance', 'stratawp-seo' ),
    'manage_options',
    'swps-search-appearance',
    [ $this, 'render_search_appearance_page' ]
);
```

Add render method:

```php
public function render_search_appearance_page(): void {
    include SWPS_PLUGIN_DIR . 'templates/search-appearance-page.php';
}
```

Register settings in `register_settings()`:

```php
// --- Search Appearance Settings (separate options group) ---
register_setting( 'swps_search_appearance', 'swps_title_separator', [ 'sanitize_callback' => 'sanitize_text_field' ] );

// Dynamic registration for each post type and taxonomy.
foreach ( get_post_types( [ 'public' => true ] ) as $pt ) {
    if ( 'attachment' === $pt ) continue;
    register_setting( 'swps_search_appearance', "swps_title_template_{$pt}", [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'swps_search_appearance', "swps_desc_template_{$pt}", [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
    register_setting( 'swps_search_appearance', "swps_noindex_{$pt}", [ 'sanitize_callback' => 'absint' ] );
}

foreach ( get_taxonomies( [ 'public' => true ] ) as $tax ) {
    if ( 'post_format' === $tax ) continue;
    register_setting( 'swps_search_appearance', "swps_title_template_{$tax}", [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'swps_search_appearance', "swps_noindex_{$tax}", [ 'sanitize_callback' => 'absint' ] );
}

register_setting( 'swps_search_appearance', 'swps_title_template_search', [ 'sanitize_callback' => 'sanitize_text_field' ] );
register_setting( 'swps_search_appearance', 'swps_title_template_404', [ 'sanitize_callback' => 'sanitize_text_field' ] );
register_setting( 'swps_search_appearance', 'swps_title_template_author', [ 'sanitize_callback' => 'sanitize_text_field' ] );
register_setting( 'swps_search_appearance', 'swps_title_template_date', [ 'sanitize_callback' => 'sanitize_text_field' ] );
```

- [ ] **Step 5: Enqueue JS on Search Appearance page**

In `enqueue_admin_assets()` in `stratawp-seo.php`, add:

```php
if ( 'stratawp-seo_page_swps-search-appearance' === $hook ) {
    wp_enqueue_script( 'swps-search-appearance', SWPS_PLUGIN_URL . 'admin/js/search-appearance.js', [], SWPS_VERSION, true );
}
```

- [ ] **Step 6: Add require_once and initialization in `stratawp-seo.php`**

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-search-appearance.php';
```

Property and constructor:

```php
public SWPS_Search_Appearance $search_appearance;
// In constructor:
$this->search_appearance = new SWPS_Search_Appearance();
```

- [ ] **Step 7: Set defaults in activation hook**

In `swps_activate()` defaults array:

```php
'title_separator' => '-',
```

- [ ] **Step 8: Verify syntax**

Run: `php -l includes/class-search-appearance.php && php -l templates/search-appearance-page.php`
Expected: No syntax errors

- [ ] **Step 9: Commit**

```bash
git add includes/class-search-appearance.php templates/search-appearance-page.php admin/js/search-appearance.js stratawp-seo.php includes/class-settings.php
git commit -m "feat: add Search Appearance page with title/description templates and separator"
```

---

## Chunk 3: Taxonomy Meta

### Task 5: Taxonomy Meta Class

**Files:**
- Create: `includes/class-taxonomy-meta.php`
- Modify: `stratawp-seo.php` (require + init)

- [ ] **Step 1: Create `includes/class-taxonomy-meta.php`**

```php
<?php
/**
 * Taxonomy Meta — SEO fields on term edit screens + archive meta output.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Taxonomy_Meta {

    private const META_FIELDS = [
        '_swps_meta_title',
        '_swps_meta_description',
        '_swps_canonical_url',
        '_swps_robots',
        '_swps_og_title',
        '_swps_og_description',
        '_swps_og_image',
        '_swps_focus_keyword',
    ];

    public function __construct() {
        // Register fields on all public taxonomy edit screens.
        $taxonomies = get_taxonomies( [ 'public' => true ] );
        foreach ( $taxonomies as $taxonomy ) {
            if ( 'post_format' === $taxonomy ) {
                continue;
            }
            add_action( "{$taxonomy}_edit_form_fields", [ $this, 'render_fields' ], 10, 2 );
            add_action( "edited_{$taxonomy}", [ $this, 'save_fields' ], 10, 2 );
        }

        // Frontend meta output for archive pages (only when no conflict).
        if ( ! is_admin() && ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'AIOSEO_VERSION' ) ) {
            add_action( 'wp_head', [ $this, 'output_archive_meta' ], 3 );
        }
    }

    /**
     * Render SEO fields on the term edit screen.
     */
    public function render_fields( \WP_Term $term ): void {
        wp_nonce_field( 'swps_taxonomy_meta', 'swps_taxonomy_meta_nonce' );
        ?>
        <tr class="form-field">
            <th colspan="2"><h2><?php esc_html_e( 'StrataWP SEO', 'stratawp-seo' ); ?></h2></th>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="swps-tax-meta-title"><?php esc_html_e( 'Meta Title', 'stratawp-seo' ); ?></label></th>
            <td>
                <input type="text" id="swps-tax-meta-title" name="_swps_meta_title" class="large-text"
                    value="<?php echo esc_attr( get_term_meta( $term->term_id, '_swps_meta_title', true ) ); ?>">
                <p class="description"><?php esc_html_e( 'Leave blank to use the Search Appearance template.', 'stratawp-seo' ); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="swps-tax-meta-desc"><?php esc_html_e( 'Meta Description', 'stratawp-seo' ); ?></label></th>
            <td>
                <textarea id="swps-tax-meta-desc" name="_swps_meta_description" rows="3" class="large-text"
                ><?php echo esc_textarea( get_term_meta( $term->term_id, '_swps_meta_description', true ) ); ?></textarea>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="swps-tax-canonical"><?php esc_html_e( 'Canonical URL', 'stratawp-seo' ); ?></label></th>
            <td>
                <input type="url" id="swps-tax-canonical" name="_swps_canonical_url" class="large-text"
                    value="<?php echo esc_url( get_term_meta( $term->term_id, '_swps_canonical_url', true ) ); ?>">
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e( 'Robots', 'stratawp-seo' ); ?></th>
            <td>
                <?php $robots = get_term_meta( $term->term_id, '_swps_robots', true ); ?>
                <label>
                    <input type="checkbox" name="_swps_robots_noindex" value="1"
                        <?php checked( str_contains( (string) $robots, 'noindex' ) ); ?>>
                    <?php esc_html_e( 'noindex', 'stratawp-seo' ); ?>
                </label>
                <label style="margin-left: 15px;">
                    <input type="checkbox" name="_swps_robots_nofollow" value="1"
                        <?php checked( str_contains( (string) $robots, 'nofollow' ) ); ?>>
                    <?php esc_html_e( 'nofollow', 'stratawp-seo' ); ?>
                </label>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="swps-tax-og-title"><?php esc_html_e( 'OG Title', 'stratawp-seo' ); ?></label></th>
            <td>
                <input type="text" id="swps-tax-og-title" name="_swps_og_title" class="large-text"
                    value="<?php echo esc_attr( get_term_meta( $term->term_id, '_swps_og_title', true ) ); ?>">
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="swps-tax-og-desc"><?php esc_html_e( 'OG Description', 'stratawp-seo' ); ?></label></th>
            <td>
                <textarea id="swps-tax-og-desc" name="_swps_og_description" rows="2" class="large-text"
                ><?php echo esc_textarea( get_term_meta( $term->term_id, '_swps_og_description', true ) ); ?></textarea>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="swps-tax-focus-kw"><?php esc_html_e( 'Focus Keyword', 'stratawp-seo' ); ?></label></th>
            <td>
                <input type="text" id="swps-tax-focus-kw" name="_swps_focus_keyword" class="large-text"
                    value="<?php echo esc_attr( get_term_meta( $term->term_id, '_swps_focus_keyword', true ) ); ?>">
            </td>
        </tr>
        <?php
    }

    /**
     * Save taxonomy SEO fields.
     */
    public function save_fields( int $term_id ): void {
        if ( ! isset( $_POST['swps_taxonomy_meta_nonce'] ) ||
             ! wp_verify_nonce( $_POST['swps_taxonomy_meta_nonce'], 'swps_taxonomy_meta' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }

        // Text fields.
        $text_fields = [ '_swps_meta_title', '_swps_meta_description', '_swps_canonical_url', '_swps_og_title', '_swps_og_description', '_swps_focus_keyword' ];
        foreach ( $text_fields as $field ) {
            $value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
            if ( ! empty( $value ) ) {
                update_term_meta( $term_id, $field, $value );
            } else {
                delete_term_meta( $term_id, $field );
            }
        }

        // Robots directives.
        $robots = [];
        if ( ! empty( $_POST['_swps_robots_noindex'] ) ) {
            $robots[] = 'noindex';
        }
        if ( ! empty( $_POST['_swps_robots_nofollow'] ) ) {
            $robots[] = 'nofollow';
        }
        if ( ! empty( $robots ) ) {
            update_term_meta( $term_id, '_swps_robots', implode( ',', $robots ) );
        } else {
            delete_term_meta( $term_id, '_swps_robots' );
        }
    }

    /**
     * Output meta tags on archive pages using term meta overrides.
     */
    public function output_archive_meta(): void {
        if ( ! is_category() && ! is_tag() && ! is_tax() ) {
            return;
        }

        $term = get_queried_object();
        if ( ! $term instanceof \WP_Term ) {
            return;
        }

        // Canonical URL.
        $canonical = get_term_meta( $term->term_id, '_swps_canonical_url', true );
        if ( ! empty( $canonical ) ) {
            printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
        }

        // Robots.
        $robots = get_term_meta( $term->term_id, '_swps_robots', true );
        if ( ! empty( $robots ) ) {
            printf( '<meta name="robots" content="%s" />' . "\n", esc_attr( $robots ) );
        }

        // OG tags.
        $og_title = get_term_meta( $term->term_id, '_swps_og_title', true );
        $og_desc  = get_term_meta( $term->term_id, '_swps_og_description', true );

        if ( ! empty( $og_title ) ) {
            printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $og_title ) );
        }
        if ( ! empty( $og_desc ) ) {
            printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $og_desc ) );
        }
    }
}
```

- [ ] **Step 2: Add require_once and initialization in `stratawp-seo.php`**

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-taxonomy-meta.php';
```

Property and constructor:

```php
public SWPS_Taxonomy_Meta $taxonomy_meta;
// In constructor:
$this->taxonomy_meta = new SWPS_Taxonomy_Meta();
```

- [ ] **Step 3: Verify syntax**

Run: `php -l includes/class-taxonomy-meta.php`
Expected: No syntax errors

- [ ] **Step 4: Commit**

```bash
git add includes/class-taxonomy-meta.php stratawp-seo.php
git commit -m "feat: add taxonomy/archive SEO meta fields and output"
```

---

## Chunk 4: Sitemap Manager

### Task 6: Sitemap Manager Class

**Files:**
- Create: `includes/class-sitemap-manager.php`
- Modify: `stratawp-seo.php` (require + init + hooks)
- Modify: `includes/audit/class-sitemap-module.php` (delegate to manager)

- [ ] **Step 1: Create `includes/class-sitemap-manager.php`**

```php
<?php
/**
 * Full XML Sitemap Manager.
 *
 * Generates sitemap index with sub-sitemaps for post types, taxonomies,
 * and authors. Supports per-URL priority/changefreq, image entries,
 * and IndexNow pinging.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Sitemap_Manager {

    private const URLS_PER_SITEMAP = 1000;

    public function __construct() {
        // Disable WP core sitemaps to prevent conflict detection from blocking us.
        add_filter( 'wp_sitemaps_enabled', '__return_false' );

        // Rewrite rules.
        add_action( 'init', [ $this, 'register_rewrite_rules' ] );

        // Serve sitemaps.
        add_action( 'template_redirect', [ $this, 'serve_sitemap' ], 1 );

        // Ping on publish/update/delete.
        add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 10, 3 );

        // IndexNow verification file.
        add_action( 'template_redirect', [ $this, 'serve_indexnow_key' ], 1 );
    }

    /**
     * Register rewrite rules for all sitemap URLs.
     */
    public function register_rewrite_rules(): void {
        // Sitemap index.
        add_rewrite_rule( 'sitemap_index\.xml$', 'index.php?swps_sitemap=index', 'top' );

        // Post type sitemaps.
        add_rewrite_rule( '([a-z0-9_-]+)-sitemap(\d*)\.xml$', 'index.php?swps_sitemap=$matches[1]&swps_sitemap_page=$matches[2]', 'top' );

        // Author sitemap.
        add_rewrite_rule( 'author-sitemap\.xml$', 'index.php?swps_sitemap=author', 'top' );

        // Legacy URL redirect.
        add_rewrite_rule( 'swps-sitemap\.xml$', 'index.php?swps_sitemap=legacy_redirect', 'top' );

        add_filter( 'query_vars', function ( array $vars ): array {
            $vars[] = 'swps_sitemap';
            $vars[] = 'swps_sitemap_page';
            return $vars;
        } );
    }

    /**
     * Serve the appropriate sitemap based on the query var.
     */
    public function serve_sitemap(): void {
        $type = get_query_var( 'swps_sitemap', '' );
        if ( empty( $type ) ) {
            return;
        }

        // Skip if Yoast or RankMath active.
        if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) {
            return;
        }

        // Legacy redirect.
        if ( 'legacy_redirect' === $type ) {
            wp_redirect( home_url( '/sitemap_index.xml' ), 301 );
            exit;
        }

        $page = (int) get_query_var( 'swps_sitemap_page', 1 );
        if ( $page < 1 ) {
            $page = 1;
        }

        header( 'Content-Type: application/xml; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex' );

        if ( 'index' === $type ) {
            $this->render_sitemap_index();
        } elseif ( 'author' === $type ) {
            $this->render_author_sitemap();
        } else {
            // Check if it's a taxonomy.
            $taxonomies = get_taxonomies( [ 'public' => true ] );
            if ( in_array( $type, $taxonomies, true ) && ! get_option( "swps_sitemap_exclude_{$type}", 0 ) ) {
                $this->render_taxonomy_sitemap( $type );
            } else {
                // Post type sitemap.
                $post_types = get_post_types( [ 'public' => true ] );
                if ( in_array( $type, $post_types, true ) && ! get_option( "swps_sitemap_exclude_{$type}", 0 ) ) {
                    $this->render_post_type_sitemap( $type, $page );
                } else {
                    status_header( 404 );
                    exit;
                }
            }
        }

        exit;
    }

    /**
     * Render the sitemap index.
     */
    private function render_sitemap_index(): void {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Post type sitemaps.
        $post_types = get_post_types( [ 'public' => true ] );
        foreach ( $post_types as $pt ) {
            if ( 'attachment' === $pt || get_option( "swps_sitemap_exclude_{$pt}", 0 ) ) {
                continue;
            }

            $count = $this->get_post_type_count( $pt );
            $pages = max( 1, (int) ceil( $count / self::URLS_PER_SITEMAP ) );

            for ( $i = 1; $i <= $pages; $i++ ) {
                $suffix = $i > 1 ? $i : '';
                printf(
                    "<sitemap>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n</sitemap>\n",
                    esc_url( home_url( "/{$pt}-sitemap{$suffix}.xml" ) ),
                    $this->get_post_type_lastmod( $pt )
                );
            }
        }

        // Taxonomy sitemaps.
        $taxonomies = get_taxonomies( [ 'public' => true ] );
        foreach ( $taxonomies as $tax ) {
            if ( 'post_format' === $tax || get_option( "swps_sitemap_exclude_{$tax}", 0 ) ) {
                continue;
            }

            $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => true, 'number' => 1 ] );
            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                continue;
            }

            printf(
                "<sitemap>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n</sitemap>\n",
                esc_url( home_url( "/{$tax}-sitemap.xml" ) ),
                gmdate( 'Y-m-d\TH:i:s+00:00' )
            );
        }

        // Author sitemap.
        if ( ! get_option( 'swps_sitemap_exclude_author', 0 ) ) {
            printf(
                "<sitemap>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n</sitemap>\n",
                esc_url( home_url( '/author-sitemap.xml' ) ),
                gmdate( 'Y-m-d\TH:i:s+00:00' )
            );
        }

        echo '</sitemapindex>';
    }

    /**
     * Render a post type sub-sitemap.
     */
    private function render_post_type_sitemap( string $post_type, int $page ): void {
        $offset = ( $page - 1 ) * self::URLS_PER_SITEMAP;

        $posts = get_posts( [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => self::URLS_PER_SITEMAP,
            'offset'         => $offset,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ] );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        // Include homepage on first page of 'page' sitemap.
        if ( 'page' === $post_type && 1 === $page ) {
            printf(
                "<url>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n  <priority>1.0</priority>\n</url>\n",
                esc_url( home_url( '/' ) ),
                gmdate( 'Y-m-d\TH:i:s+00:00' )
            );
        }

        foreach ( $posts as $post ) {
            // Respect exclusions.
            if ( get_post_meta( $post->ID, '_swps_sitemap_exclude', true ) ) {
                continue;
            }

            // Respect noindex.
            $robots = get_post_meta( $post->ID, '_swps_robots', true );
            if ( ! empty( $robots ) && str_contains( (string) $robots, 'noindex' ) ) {
                continue;
            }

            $modified = get_post_modified_time( 'U', true, $post );
            $priority = get_post_meta( $post->ID, '_swps_sitemap_priority', true );
            if ( empty( $priority ) ) {
                $priority = 'page' === $post_type ? '0.8' : '0.6';
            }
            $changefreq = get_post_meta( $post->ID, '_swps_sitemap_changefreq', true ) ?: 'weekly';

            echo "<url>\n";
            printf( "  <loc>%s</loc>\n", esc_url( get_permalink( $post ) ) );
            printf( "  <lastmod>%s</lastmod>\n", gmdate( 'Y-m-d\TH:i:s+00:00', $modified ) );
            printf( "  <changefreq>%s</changefreq>\n", esc_xml( $changefreq ) );
            printf( "  <priority>%s</priority>\n", esc_attr( $priority ) );

            // Image entries.
            if ( ! get_option( 'swps_sitemap_exclude_images', 0 ) ) {
                $this->render_image_entries( $post->ID );
            }

            echo "</url>\n";
        }

        echo '</urlset>';
    }

    /**
     * Render a taxonomy sub-sitemap.
     */
    private function render_taxonomy_sitemap( string $taxonomy ): void {
        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'number'     => self::URLS_PER_SITEMAP,
        ] );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                // Respect exclusions.
                if ( get_term_meta( $term->term_id, '_swps_sitemap_exclude', true ) ) {
                    continue;
                }

                $robots = get_term_meta( $term->term_id, '_swps_robots', true );
                if ( ! empty( $robots ) && str_contains( (string) $robots, 'noindex' ) ) {
                    continue;
                }

                echo "<url>\n";
                printf( "  <loc>%s</loc>\n", esc_url( get_term_link( $term ) ) );
                printf( "  <changefreq>weekly</changefreq>\n" );
                printf( "  <priority>0.4</priority>\n" );
                echo "</url>\n";
            }
        }

        echo '</urlset>';
    }

    /**
     * Render author sub-sitemap.
     */
    private function render_author_sitemap(): void {
        $authors = get_users( [
            'has_published_posts' => true,
            'fields'             => [ 'ID', 'user_nicename' ],
        ] );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ( $authors as $author ) {
            echo "<url>\n";
            printf( "  <loc>%s</loc>\n", esc_url( get_author_posts_url( $author->ID ) ) );
            printf( "  <changefreq>weekly</changefreq>\n" );
            printf( "  <priority>0.3</priority>\n" );
            echo "</url>\n";
        }

        echo '</urlset>';
    }

    /**
     * Render image entries for a post.
     */
    private function render_image_entries( int $post_id ): void {
        // Featured image.
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $img_url = wp_get_attachment_url( $thumb_id );
            if ( $img_url ) {
                echo "  <image:image>\n";
                printf( "    <image:loc>%s</image:loc>\n", esc_url( $img_url ) );
                $alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
                if ( $alt ) {
                    printf( "    <image:title>%s</image:title>\n", esc_xml( $alt ) );
                }
                echo "  </image:image>\n";
            }
        }

        // In-content images.
        $post = get_post( $post_id );
        if ( $post && preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches ) ) {
            $seen = [ $thumb_id ? wp_get_attachment_url( $thumb_id ) : '' ];
            foreach ( $matches[1] as $img_url ) {
                if ( in_array( $img_url, $seen, true ) ) {
                    continue;
                }
                $seen[] = $img_url;
                echo "  <image:image>\n";
                printf( "    <image:loc>%s</image:loc>\n", esc_url( $img_url ) );
                echo "  </image:image>\n";
            }
        }
    }

    /**
     * Ping search engines and IndexNow on post status change.
     */
    public function on_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
            return;
        }

        if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return;
        }

        $this->ping_search_engines();
        $this->ping_indexnow( get_permalink( $post ) );
    }

    /**
     * Ping Google and Bing with the sitemap URL.
     */
    public function ping_search_engines(): void {
        $sitemap_url = home_url( '/sitemap_index.xml' );

        wp_remote_get( 'https://www.google.com/ping?sitemap=' . urlencode( $sitemap_url ), [
            'timeout'   => 5,
            'blocking'  => false,
            'sslverify' => false,
        ] );

        wp_remote_get( 'https://www.bing.com/ping?sitemap=' . urlencode( $sitemap_url ), [
            'timeout'   => 5,
            'blocking'  => false,
            'sslverify' => false,
        ] );
    }

    /**
     * Ping IndexNow with a specific URL.
     */
    private function ping_indexnow( string $url ): void {
        $key = get_option( 'swps_indexnow_key', '' );
        if ( empty( $key ) ) {
            return;
        }

        wp_remote_post( 'https://api.indexnow.org/indexnow', [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( [
                'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
                'key'         => $key,
                'keyLocation' => home_url( "/{$key}.txt" ),
                'urlList'     => [ $url ],
            ] ),
        ] );
    }

    /**
     * Serve the IndexNow verification file.
     */
    public function serve_indexnow_key(): void {
        $key = get_option( 'swps_indexnow_key', '' );
        if ( empty( $key ) ) {
            return;
        }

        $request_uri = trim( $_SERVER['REQUEST_URI'] ?? '', '/' );
        if ( $request_uri === $key . '.txt' ) {
            header( 'Content-Type: text/plain; charset=UTF-8' );
            echo $key;
            exit;
        }
    }

    /**
     * Get post count for a post type (excluding sitemap-excluded posts).
     */
    private function get_post_type_count( string $post_type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            $post_type
        ) );
    }

    /**
     * Get the most recent lastmod for a post type.
     */
    private function get_post_type_lastmod( string $post_type ): string {
        global $wpdb;
        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            $post_type
        ) );
        return $date ? gmdate( 'Y-m-d\TH:i:s+00:00', strtotime( $date ) ) : gmdate( 'Y-m-d\TH:i:s+00:00' );
    }

    /**
     * Generate IndexNow key on activation.
     */
    public static function generate_indexnow_key(): void {
        if ( ! get_option( 'swps_indexnow_key' ) ) {
            update_option( 'swps_indexnow_key', bin2hex( random_bytes( 16 ) ) );
        }
    }
}
```

- [ ] **Step 2: Add require_once and initialization in `stratawp-seo.php`**

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-sitemap-manager.php';
```

Property and constructor:

```php
public SWPS_Sitemap_Manager $sitemap_manager;
// In constructor (before the existing sitemap module hooks):
$this->sitemap_manager = new SWPS_Sitemap_Manager();
```

Remove or comment out the old sitemap hooks in constructor:

```php
// Remove these lines — now handled by SWPS_Sitemap_Manager:
// add_action( 'init', [ SWPS_Sitemap_Module::class, 'register_rewrite_rules' ] );
// add_action( 'template_redirect', [ SWPS_Sitemap_Module::class, 'serve_sitemap' ] );
// add_action( 'publish_post', [ SWPS_Sitemap_Module::class, 'ping_search_engines' ] );
```

In `swps_activate()`, add:

```php
SWPS_Sitemap_Manager::generate_indexnow_key();
flush_rewrite_rules();
```

- [ ] **Step 3: Add sitemap settings to `SWPS_Settings`**

In `register_settings()`:

```php
// --- Sitemap Settings Section ---
add_settings_section( 'swps_sitemap_section', __( 'Sitemaps', 'stratawp-seo' ), [ $this, 'render_sitemap_section' ], 'stratawp-seo' );

$this->add_field( 'sitemap_exclude_images', __( 'Exclude Images from Sitemap', 'stratawp-seo' ), 'checkbox', 'swps_sitemap_section' );
$this->add_field( 'sitemap_exclude_author', __( 'Exclude Author Sitemap', 'stratawp-seo' ), 'checkbox', 'swps_sitemap_section' );
```

Add renderer:

```php
public function render_sitemap_section(): void {
    $index_url = home_url( '/sitemap_index.xml' );
    echo '<p>' . sprintf(
        esc_html__( 'Your sitemap index: %s', 'stratawp-seo' ),
        '<a href="' . esc_url( $index_url ) . '" target="_blank">' . esc_url( $index_url ) . '</a>'
    ) . '</p>';
}
```

- [ ] **Step 4: Add sitemap fields to Meta Editor metabox**

In `includes/class-meta-editor.php`, within the `render_metabox()` method, after the existing fields, add:

```php
// Sitemap controls.
$sitemap_exclude   = get_post_meta( $post->ID, '_swps_sitemap_exclude', true );
$sitemap_priority  = get_post_meta( $post->ID, '_swps_sitemap_priority', true );
$sitemap_changefreq = get_post_meta( $post->ID, '_swps_sitemap_changefreq', true );
?>
<div class="swps-field-group">
    <h4><?php esc_html_e( 'Sitemap', 'stratawp-seo' ); ?></h4>
    <label>
        <input type="checkbox" name="swps_sitemap_exclude" value="1" <?php checked( $sitemap_exclude ); ?>>
        <?php esc_html_e( 'Exclude from sitemap', 'stratawp-seo' ); ?>
    </label>
    <p>
        <label><?php esc_html_e( 'Priority:', 'stratawp-seo' ); ?>
            <select name="swps_sitemap_priority">
                <option value=""><?php esc_html_e( 'Auto', 'stratawp-seo' ); ?></option>
                <?php foreach ( [ '1.0', '0.9', '0.8', '0.7', '0.6', '0.5', '0.4', '0.3', '0.2', '0.1' ] as $p ) : ?>
                    <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $sitemap_priority, $p ); ?>><?php echo esc_html( $p ); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>
    <p>
        <label><?php esc_html_e( 'Change Frequency:', 'stratawp-seo' ); ?>
            <select name="swps_sitemap_changefreq">
                <?php foreach ( [ '' => 'Auto', 'always' => 'Always', 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly', 'never' => 'Never' ] as $val => $label ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $sitemap_changefreq, $val ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>
</div>
<?php
```

In the `save_meta()` method, add:

```php
// Sitemap meta.
update_post_meta( $post_id, '_swps_sitemap_exclude', ! empty( $_POST['swps_sitemap_exclude'] ) ? 1 : 0 );

$priority = isset( $_POST['swps_sitemap_priority'] ) ? sanitize_text_field( $_POST['swps_sitemap_priority'] ) : '';
update_post_meta( $post_id, '_swps_sitemap_priority', $priority );

$changefreq = isset( $_POST['swps_sitemap_changefreq'] ) ? sanitize_text_field( $_POST['swps_sitemap_changefreq'] ) : '';
update_post_meta( $post_id, '_swps_sitemap_changefreq', $changefreq );
```

- [ ] **Step 5: Verify syntax**

Run: `php -l includes/class-sitemap-manager.php`
Expected: No syntax errors

- [ ] **Step 6: Commit**

```bash
git add includes/class-sitemap-manager.php stratawp-seo.php includes/class-settings.php includes/class-meta-editor.php
git commit -m "feat: add full sitemap system with index, sub-sitemaps, and IndexNow"
```

---

## Chunk 5: Redirect Manager

### Task 7: Redirect Manager Class

**Files:**
- Create: `includes/class-redirect-manager.php`
- Create: `includes/class-redirect-admin.php`
- Create: `templates/redirects-page.php`
- Create: `admin/js/redirects.js`
- Modify: `stratawp-seo.php` (require + init + activation)
- Modify: `includes/class-settings.php` (admin menu)
- Modify: `uninstall.php` (cleanup)

- [ ] **Step 1: Create `includes/class-redirect-manager.php`**

```php
<?php
/**
 * Redirect Manager — storage, matching, and execution.
 *
 * Intercepts requests via template_redirect at priority 1.
 * Supports exact and regex matching, 301/302/307/410 types.
 * Logs 404s for redirect suggestions.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Redirect_Manager {

    private const REDIRECTS_TABLE = 'swps_redirects';
    private const LOG_TABLE       = 'swps_404_log';
    private const CACHE_KEY       = 'swps_redirects_cache';

    public function __construct() {
        // Execute redirects — must be very early.
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );

        // Log 404s.
        add_action( 'template_redirect', [ $this, 'maybe_log_404' ], 99 );

        // Auto-redirect on slug change.
        if ( get_option( 'swps_auto_redirect_slug_change', 1 ) ) {
            add_action( 'post_updated', [ $this, 'detect_slug_change' ], 10, 3 );
        }

        // AJAX handlers.
        add_action( 'wp_ajax_swps_add_redirect', [ $this, 'ajax_add_redirect' ] );
        add_action( 'wp_ajax_swps_delete_redirect', [ $this, 'ajax_delete_redirect' ] );
        add_action( 'wp_ajax_swps_get_redirects', [ $this, 'ajax_get_redirects' ] );
        add_action( 'wp_ajax_swps_get_404s', [ $this, 'ajax_get_404s' ] );
        add_action( 'wp_ajax_swps_delete_404', [ $this, 'ajax_delete_404' ] );
    }

    /**
     * Check if current request matches a redirect and execute it.
     */
    public function maybe_redirect(): void {
        if ( is_admin() ) {
            return;
        }

        $request_path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        $request_path = rtrim( $request_path, '/' );

        if ( empty( $request_path ) || '/' === $request_path ) {
            return;
        }

        $redirects = $this->get_cached_redirects();

        // Exact match first.
        foreach ( $redirects as $redirect ) {
            if ( ! $redirect->is_regex && rtrim( $redirect->source_url, '/' ) === $request_path ) {
                $this->execute_redirect( $redirect );
                return;
            }
        }

        // Regex match.
        foreach ( $redirects as $redirect ) {
            if ( $redirect->is_regex ) {
                $pattern = '#' . str_replace( '#', '\#', $redirect->source_url ) . '#';
                if ( preg_match( $pattern, $request_path, $matches ) ) {
                    $target = $redirect->target_url;
                    // Replace capture groups.
                    for ( $i = 1; $i < count( $matches ); $i++ ) {
                        $target = str_replace( '$' . $i, $matches[ $i ], $target );
                    }
                    $redirect->target_url = $target;
                    $this->execute_redirect( $redirect );
                    return;
                }
            }
        }
    }

    /**
     * Execute a redirect.
     */
    private function execute_redirect( object $redirect ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::REDIRECTS_TABLE;

        // Update hit counter.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET hits = hits + 1, last_hit = NOW() WHERE id = %d",
            $redirect->id
        ) );

        if ( 410 === (int) $redirect->type ) {
            status_header( 410 );
            nocache_headers();
            echo '<h1>410 Gone</h1><p>This page has been permanently removed.</p>';
            exit;
        }

        wp_redirect( $redirect->target_url, (int) $redirect->type );
        exit;
    }

    /**
     * Log 404 errors.
     */
    public function maybe_log_404(): void {
        if ( ! is_404() ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $url   = sanitize_text_field( $_SERVER['REQUEST_URI'] ?? '' );

        if ( empty( $url ) ) {
            return;
        }

        // Upsert: increment count if exists, insert if not.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE url = %s",
            $url
        ) );

        if ( $existing ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET count = count + 1, last_seen = NOW() WHERE id = %d",
                $existing
            ) );
        } else {
            $wpdb->insert( $table, [
                'url'       => $url,
                'referrer'  => sanitize_text_field( $_SERVER['HTTP_REFERER'] ?? '' ),
                'count'     => 1,
                'last_seen' => current_time( 'mysql' ),
            ], [ '%s', '%s', '%d', '%s' ] );
        }
    }

    /**
     * Detect slug changes and auto-create 301 redirects.
     */
    public function detect_slug_change( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
        if ( 'publish' !== $post_before->post_status || 'publish' !== $post_after->post_status ) {
            return;
        }

        if ( $post_before->post_name === $post_after->post_name ) {
            return;
        }

        // Build old URL path.
        $old_url = str_replace( home_url(), '', get_permalink( $post_before ) );

        // We need the actual old permalink — reconstruct it.
        $old_link = get_permalink( $post_id );
        $old_link = str_replace( $post_after->post_name, $post_before->post_name, $old_link );
        $old_path = wp_parse_url( $old_link, PHP_URL_PATH );
        $new_path = wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );

        if ( $old_path && $new_path && $old_path !== $new_path ) {
            $this->add_redirect( $old_path, $new_path, 301 );
        }
    }

    /**
     * Add a redirect.
     */
    public function add_redirect( string $source, string $target, int $type = 301, bool $is_regex = false ): int|false {
        global $wpdb;
        $table = $wpdb->prefix . self::REDIRECTS_TABLE;

        $result = $wpdb->insert( $table, [
            'source_url' => $source,
            'target_url' => $target,
            'type'       => $type,
            'is_regex'   => $is_regex ? 1 : 0,
            'hits'       => 0,
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ], [ '%s', '%s', '%d', '%d', '%d', '%s', '%s' ] );

        delete_transient( self::CACHE_KEY );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get cached redirects.
     */
    private function get_cached_redirects(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::REDIRECTS_TABLE;
        $redirects = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY is_regex ASC, id ASC" );

        set_transient( self::CACHE_KEY, $redirects ?: [], HOUR_IN_SECONDS );

        return $redirects ?: [];
    }

    // --- AJAX Handlers ---

    public function ajax_add_redirect(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $source   = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
        $target   = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
        $type     = (int) ( $_POST['type'] ?? 301 );
        $is_regex = ! empty( $_POST['is_regex'] );

        if ( empty( $source ) ) {
            wp_send_json_error( 'Source URL is required.' );
        }

        $id = $this->add_redirect( $source, $target, $type, $is_regex );
        wp_send_json_success( [ 'id' => $id ] );
    }

    public function ajax_delete_redirect(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::REDIRECTS_TABLE;
        $id    = (int) ( $_POST['id'] ?? 0 );

        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        delete_transient( self::CACHE_KEY );

        wp_send_json_success();
    }

    public function ajax_get_redirects(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::REDIRECTS_TABLE;
        $redirects = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100" );

        wp_send_json_success( $redirects );
    }

    public function ajax_get_404s(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $logs = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY count DESC LIMIT 100" );

        wp_send_json_success( $logs );
    }

    public function ajax_delete_404(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $id    = (int) ( $_POST['id'] ?? 0 );

        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        wp_send_json_success();
    }

    /**
     * Create database tables. Called on activation.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset    = $wpdb->get_charset_collate();
        $redirects  = $wpdb->prefix . self::REDIRECTS_TABLE;
        $log        = $wpdb->prefix . self::LOG_TABLE;

        $sql_redirects = "CREATE TABLE {$redirects} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url VARCHAR(500) NOT NULL DEFAULT '',
            target_url VARCHAR(500) NOT NULL DEFAULT '',
            type SMALLINT UNSIGNED NOT NULL DEFAULT 301,
            is_regex TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_hit DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_source_url (source_url(191))
        ) {$charset};";

        $sql_log = "CREATE TABLE {$log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url VARCHAR(500) NOT NULL DEFAULT '',
            referrer VARCHAR(500) NOT NULL DEFAULT '',
            count BIGINT UNSIGNED NOT NULL DEFAULT 1,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_url (url(191))
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_redirects );
        dbDelta( $sql_log );
    }

    /**
     * Prune old 404 logs (older than 90 days). Called via cron.
     */
    public static function prune_404_logs(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $wpdb->query( "DELETE FROM {$table} WHERE last_seen < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
    }
}
```

- [ ] **Step 2: Create `includes/class-redirect-admin.php`**

```php
<?php
/**
 * Redirect Admin page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Redirect_Admin {

    public function __construct() {
        // Admin page rendering is handled by SWPS_Settings menu registration.
    }

    /**
     * Render the redirects admin page.
     */
    public static function render(): void {
        include SWPS_PLUGIN_DIR . 'templates/redirects-page.php';
    }
}
```

- [ ] **Step 3: Create `templates/redirects-page.php`**

```php
<?php
/**
 * Redirects admin page template.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-redirects-page">
    <h1><?php esc_html_e( 'Redirects', 'stratawp-seo' ); ?></h1>

    <h2 class="nav-tab-wrapper">
        <a href="#redirects" class="nav-tab nav-tab-active" data-tab="redirects"><?php esc_html_e( 'Redirects', 'stratawp-seo' ); ?></a>
        <a href="#404s" class="nav-tab" data-tab="404s"><?php esc_html_e( '404 Errors', 'stratawp-seo' ); ?></a>
    </h2>

    <div id="tab-redirects" class="swps-tab-content">
        <h3><?php esc_html_e( 'Add Redirect', 'stratawp-seo' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="swps-redirect-source"><?php esc_html_e( 'Source URL', 'stratawp-seo' ); ?></label></th>
                <td><input type="text" id="swps-redirect-source" class="large-text" placeholder="/old-page"></td>
            </tr>
            <tr>
                <th><label for="swps-redirect-target"><?php esc_html_e( 'Target URL', 'stratawp-seo' ); ?></label></th>
                <td><input type="text" id="swps-redirect-target" class="large-text" placeholder="/new-page"></td>
            </tr>
            <tr>
                <th><label for="swps-redirect-type"><?php esc_html_e( 'Type', 'stratawp-seo' ); ?></label></th>
                <td>
                    <select id="swps-redirect-type">
                        <option value="301"><?php esc_html_e( '301 Permanent', 'stratawp-seo' ); ?></option>
                        <option value="302"><?php esc_html_e( '302 Temporary', 'stratawp-seo' ); ?></option>
                        <option value="307"><?php esc_html_e( '307 Temporary (preserve method)', 'stratawp-seo' ); ?></option>
                        <option value="410"><?php esc_html_e( '410 Gone', 'stratawp-seo' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th></th>
                <td>
                    <label><input type="checkbox" id="swps-redirect-regex"> <?php esc_html_e( 'Regex match', 'stratawp-seo' ); ?></label>
                </td>
            </tr>
        </table>
        <p><button class="button button-primary" id="swps-add-redirect"><?php esc_html_e( 'Add Redirect', 'stratawp-seo' ); ?></button></p>

        <h3><?php esc_html_e( 'Existing Redirects', 'stratawp-seo' ); ?></h3>
        <table class="widefat striped" id="swps-redirects-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Source', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Target', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Hits', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <div id="tab-404s" class="swps-tab-content" style="display:none;">
        <h3><?php esc_html_e( '404 Errors', 'stratawp-seo' ); ?></h3>
        <table class="widefat striped" id="swps-404s-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'URL', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Hits', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Last Seen', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
```

- [ ] **Step 4: Create `admin/js/redirects.js`**

```js
/**
 * Redirects admin page — AJAX interactions.
 */
(function () {
    'use strict';

    const nonce = typeof swps_admin !== 'undefined' ? swps_admin.nonce : '';
    const ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';

    function loadRedirects() {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'swps_get_redirects', nonce: nonce }),
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                const tbody = document.querySelector('#swps-redirects-table tbody');
                tbody.innerHTML = '';
                res.data.forEach(r => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${escHtml(r.source_url)}${r.is_regex === '1' ? ' <em>(regex)</em>' : ''}</td>
                        <td>${escHtml(r.target_url)}</td>
                        <td>${r.type}</td>
                        <td>${r.hits}</td>
                        <td><button class="button button-small swps-delete-redirect" data-id="${r.id}">Delete</button></td>
                    `;
                    tbody.appendChild(tr);
                });
            });
    }

    function load404s() {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'swps_get_404s', nonce: nonce }),
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                const tbody = document.querySelector('#swps-404s-table tbody');
                tbody.innerHTML = '';
                res.data.forEach(r => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${escHtml(r.url)}</td>
                        <td>${r.count}</td>
                        <td>${r.last_seen}</td>
                        <td>
                            <button class="button button-small swps-create-redirect-from-404" data-url="${escAttr(r.url)}">Create Redirect</button>
                            <button class="button button-small swps-delete-404" data-id="${r.id}">Dismiss</button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            });
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;');
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Tab switching.
        document.querySelectorAll('.nav-tab').forEach(tab => {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
                this.classList.add('nav-tab-active');
                document.querySelectorAll('.swps-tab-content').forEach(c => c.style.display = 'none');
                document.getElementById('tab-' + this.dataset.tab).style.display = 'block';

                if (this.dataset.tab === '404s') load404s();
            });
        });

        // Add redirect.
        document.getElementById('swps-add-redirect')?.addEventListener('click', function () {
            const source = document.getElementById('swps-redirect-source').value;
            const target = document.getElementById('swps-redirect-target').value;
            const type = document.getElementById('swps-redirect-type').value;
            const isRegex = document.getElementById('swps-redirect-regex').checked ? 1 : 0;

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'swps_add_redirect', nonce, source, target, type, is_regex: isRegex }),
            })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        document.getElementById('swps-redirect-source').value = '';
                        document.getElementById('swps-redirect-target').value = '';
                        loadRedirects();
                    } else {
                        alert(res.data || 'Error adding redirect');
                    }
                });
        });

        // Delegate clicks for delete and create-from-404.
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('swps-delete-redirect')) {
                const id = e.target.dataset.id;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_delete_redirect', nonce, id }),
                }).then(() => loadRedirects());
            }

            if (e.target.classList.contains('swps-delete-404')) {
                const id = e.target.dataset.id;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_delete_404', nonce, id }),
                }).then(() => load404s());
            }

            if (e.target.classList.contains('swps-create-redirect-from-404')) {
                document.getElementById('swps-redirect-source').value = e.target.dataset.url;
                document.querySelector('[data-tab="redirects"]').click();
                document.getElementById('swps-redirect-target').focus();
            }
        });

        // Initial load.
        loadRedirects();
    });
})();
```

- [ ] **Step 5: Wire up in `stratawp-seo.php`**

Require statements:

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-redirect-manager.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-redirect-admin.php';
```

Properties and constructor:

```php
public SWPS_Redirect_Manager $redirect_manager;
// In constructor:
$this->redirect_manager = new SWPS_Redirect_Manager();
```

In `swps_activate()`:

```php
SWPS_Redirect_Manager::create_tables();
```

Add default in activation:

```php
'auto_redirect_slug_change' => 1,
```

Add cron for 404 pruning in activation:

```php
if ( ! wp_next_scheduled( 'swps_prune_404_logs' ) ) {
    wp_schedule_event( time(), 'daily', 'swps_prune_404_logs' );
}
```

In constructor, add cron hook:

```php
add_action( 'swps_prune_404_logs', [ SWPS_Redirect_Manager::class, 'prune_404_logs' ] );
```

Enqueue redirects JS:

```php
if ( 'stratawp-seo_page_swps-redirects' === $hook ) {
    wp_enqueue_script( 'swps-redirects', SWPS_PLUGIN_URL . 'admin/js/redirects.js', [], SWPS_VERSION, true );
}
```

- [ ] **Step 6: Add Redirects admin page in `SWPS_Settings`**

In `register_menu()`:

```php
add_submenu_page(
    'stratawp-seo',
    __( 'Redirects', 'stratawp-seo' ),
    __( 'Redirects', 'stratawp-seo' ),
    'manage_options',
    'swps-redirects',
    [ SWPS_Redirect_Admin::class, 'render' ]
);
```

Add auto-redirect setting in `register_settings()`:

```php
$this->add_field( 'auto_redirect_slug_change', __( 'Auto-redirect on slug change', 'stratawp-seo' ), 'checkbox', 'swps_sitemap_section' );
```

- [ ] **Step 7: Update `uninstall.php`**

Add after the existing cleanup:

```php
// 7. Drop v3.0 custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}swps_redirects" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}swps_404_log" );

// 8. Remove term meta.
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\_swps\_%'" );

// 9. Unschedule v3.0 cron events.
wp_unschedule_hook( 'swps_prune_404_logs' );
```

- [ ] **Step 8: Verify syntax**

Run: `php -l includes/class-redirect-manager.php && php -l includes/class-redirect-admin.php && php -l templates/redirects-page.php`
Expected: No syntax errors

- [ ] **Step 9: Commit**

```bash
git add includes/class-redirect-manager.php includes/class-redirect-admin.php templates/redirects-page.php admin/js/redirects.js stratawp-seo.php includes/class-settings.php uninstall.php
git commit -m "feat: add redirect manager with 301/302/307/410, 404 monitor, and auto-redirect on slug change"
```

---

## Chunk 6: Frontend Breadcrumbs

### Task 8: Breadcrumbs Class

**Files:**
- Create: `includes/class-breadcrumbs.php`
- Modify: `stratawp-seo.php` (require + init)
- Modify: `includes/class-schema.php` (gate breadcrumb JSON-LD)

- [ ] **Step 1: Create `includes/class-breadcrumbs.php`**

```php
<?php
/**
 * Frontend Breadcrumbs — HTML output with inline schema markup.
 *
 * Provides: swps_breadcrumbs() template function, [swps_breadcrumbs] shortcode.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Breadcrumbs {

    public function __construct() {
        add_shortcode( 'swps_breadcrumbs', [ $this, 'shortcode' ] );
    }

    /**
     * Shortcode handler.
     */
    public function shortcode(): string {
        ob_start();
        $this->render();
        return ob_get_clean();
    }

    /**
     * Render breadcrumbs HTML with schema markup.
     */
    public function render(): void {
        if ( ! get_option( 'swps_breadcrumbs_enabled', 1 ) ) {
            return;
        }

        // Skip on front page.
        if ( is_front_page() ) {
            return;
        }

        $crumbs    = $this->build_crumbs();
        $separator = get_option( 'swps_breadcrumbs_separator', '&raquo;' );
        $class     = get_option( 'swps_breadcrumbs_class', 'swps-breadcrumbs' );

        if ( empty( $crumbs ) ) {
            return;
        }

        echo '<nav aria-label="Breadcrumb" class="' . esc_attr( $class ) . '">';
        echo '<ol itemscope itemtype="https://schema.org/BreadcrumbList">';

        $position = 0;
        $last     = count( $crumbs ) - 1;

        foreach ( $crumbs as $i => $crumb ) {
            $position++;
            echo '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';

            if ( $i < $last && ! empty( $crumb['url'] ) ) {
                printf(
                    '<a itemprop="item" href="%s"><span itemprop="name">%s</span></a>',
                    esc_url( $crumb['url'] ),
                    esc_html( $crumb['label'] )
                );
            } else {
                printf( '<span itemprop="name">%s</span>', esc_html( $crumb['label'] ) );
            }

            printf( '<meta itemprop="position" content="%d">', $position );
            echo '</li>';

            if ( $i < $last ) {
                printf( ' <span class="swps-breadcrumb-sep">%s</span> ', $separator );
            }
        }

        echo '</ol>';
        echo '</nav>';
    }

    /**
     * Build the breadcrumb trail.
     *
     * @return array<array{label: string, url: string}>
     */
    private function build_crumbs(): array {
        $home_label = get_option( 'swps_breadcrumbs_home_label', __( 'Home', 'stratawp-seo' ) );
        $crumbs     = [ [ 'label' => $home_label, 'url' => home_url( '/' ) ] ];

        if ( is_singular() ) {
            $post = get_queried_object();
            if ( ! $post ) {
                return $crumbs;
            }

            // Hierarchical pages: add parent chain.
            if ( is_page() && $post->post_parent ) {
                $ancestors = array_reverse( get_post_ancestors( $post ) );
                foreach ( $ancestors as $ancestor_id ) {
                    $crumbs[] = [
                        'label' => get_the_title( $ancestor_id ),
                        'url'   => get_permalink( $ancestor_id ),
                    ];
                }
            }

            // Posts: add primary category.
            if ( 'post' === $post->post_type ) {
                $categories = get_the_category( $post->ID );
                if ( ! empty( $categories ) ) {
                    // Use Yoast primary category if set, otherwise first.
                    $primary = $categories[0];
                    $crumbs[] = [
                        'label' => $primary->name,
                        'url'   => get_category_link( $primary->term_id ),
                    ];
                }
            }

            // CPTs: add post type archive.
            $post_type = get_post_type_object( $post->post_type );
            if ( $post_type && $post_type->has_archive && 'post' !== $post->post_type ) {
                $crumbs[] = [
                    'label' => $post_type->labels->name,
                    'url'   => get_post_type_archive_link( $post->post_type ),
                ];
            }

            // Current post — use breadcrumb title override if set.
            $breadcrumb_title = get_post_meta( $post->ID, '_swps_breadcrumb_title', true );
            $crumbs[] = [
                'label' => ! empty( $breadcrumb_title ) ? $breadcrumb_title : $post->post_title,
                'url'   => '',
            ];

        } elseif ( is_category() ) {
            $term = get_queried_object();
            // Parent categories.
            if ( $term->parent ) {
                $ancestors = array_reverse( get_ancestors( $term->term_id, 'category' ) );
                foreach ( $ancestors as $ancestor_id ) {
                    $ancestor = get_term( $ancestor_id, 'category' );
                    if ( $ancestor ) {
                        $crumbs[] = [ 'label' => $ancestor->name, 'url' => get_category_link( $ancestor_id ) ];
                    }
                }
            }
            $crumbs[] = [ 'label' => $term->name, 'url' => '' ];

        } elseif ( is_tag() ) {
            $crumbs[] = [ 'label' => single_tag_title( '', false ), 'url' => '' ];

        } elseif ( is_tax() ) {
            $term = get_queried_object();
            $crumbs[] = [ 'label' => $term->name, 'url' => '' ];

        } elseif ( is_author() ) {
            $crumbs[] = [ 'label' => get_the_author(), 'url' => '' ];

        } elseif ( is_date() ) {
            if ( is_year() ) {
                $crumbs[] = [ 'label' => get_the_date( 'Y' ), 'url' => '' ];
            } elseif ( is_month() ) {
                $crumbs[] = [ 'label' => get_the_date( 'Y' ), 'url' => get_year_link( get_the_date( 'Y' ) ) ];
                $crumbs[] = [ 'label' => get_the_date( 'F' ), 'url' => '' ];
            } elseif ( is_day() ) {
                $crumbs[] = [ 'label' => get_the_date( 'Y' ), 'url' => get_year_link( get_the_date( 'Y' ) ) ];
                $crumbs[] = [ 'label' => get_the_date( 'F' ), 'url' => get_month_link( get_the_date( 'Y' ), get_the_date( 'm' ) ) ];
                $crumbs[] = [ 'label' => get_the_date( 'j' ), 'url' => '' ];
            }

        } elseif ( is_search() ) {
            $crumbs[] = [ 'label' => sprintf( __( 'Search: %s', 'stratawp-seo' ), get_search_query() ), 'url' => '' ];

        } elseif ( is_404() ) {
            $crumbs[] = [ 'label' => __( 'Page Not Found', 'stratawp-seo' ), 'url' => '' ];

        } elseif ( is_post_type_archive() ) {
            $crumbs[] = [ 'label' => post_type_archive_title( '', false ), 'url' => '' ];
        }

        return $crumbs;
    }
}

/**
 * Template function — call in theme templates.
 */
function swps_breadcrumbs(): void {
    $plugin = stratawp_seo();
    if ( isset( $plugin->breadcrumbs ) ) {
        $plugin->breadcrumbs->render();
    }
}
```

- [ ] **Step 2: Add require_once and initialization in `stratawp-seo.php`**

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-breadcrumbs.php';
```

Property and constructor:

```php
public SWPS_Breadcrumbs $breadcrumbs;
// In constructor:
$this->breadcrumbs = new SWPS_Breadcrumbs();
```

- [ ] **Step 3: Gate breadcrumb schema in `SWPS_Schema`**

In `includes/class-schema.php`, modify `output_schema()`:

```php
// Change this:
if ( ! is_front_page() ) {
    $this->breadcrumb_schema();
}

// To this:
if ( ! is_front_page() && ! class_exists( 'SWPS_Breadcrumbs' ) ) {
    $this->breadcrumb_schema();
}
```

- [ ] **Step 4: Add breadcrumb settings to Search Appearance page**

In `templates/search-appearance-page.php`, add before the submit button:

```php
<h2><?php esc_html_e( 'Breadcrumbs', 'stratawp-seo' ); ?></h2>
<table class="form-table">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable Breadcrumbs', 'stratawp-seo' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="swps_breadcrumbs_enabled" value="1"
                    <?php checked( get_option( 'swps_breadcrumbs_enabled', 1 ) ); ?>>
                <?php esc_html_e( 'Enable HTML breadcrumb output', 'stratawp-seo' ); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="swps-breadcrumbs-sep"><?php esc_html_e( 'Separator', 'stratawp-seo' ); ?></label></th>
        <td>
            <input type="text" id="swps-breadcrumbs-sep" name="swps_breadcrumbs_separator" class="small-text"
                value="<?php echo esc_attr( get_option( 'swps_breadcrumbs_separator', '&raquo;' ) ); ?>">
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="swps-breadcrumbs-home"><?php esc_html_e( 'Home Label', 'stratawp-seo' ); ?></label></th>
        <td>
            <input type="text" id="swps-breadcrumbs-home" name="swps_breadcrumbs_home_label" class="regular-text"
                value="<?php echo esc_attr( get_option( 'swps_breadcrumbs_home_label', 'Home' ) ); ?>">
        </td>
    </tr>
</table>
<p class="description">
    <?php esc_html_e( 'Use swps_breadcrumbs() in your theme template or the [swps_breadcrumbs] shortcode to display breadcrumbs.', 'stratawp-seo' ); ?>
</p>
```

Register these settings in `SWPS_Settings::register_settings()`:

```php
register_setting( 'swps_search_appearance', 'swps_breadcrumbs_enabled', [ 'sanitize_callback' => 'absint' ] );
register_setting( 'swps_search_appearance', 'swps_breadcrumbs_separator', [ 'sanitize_callback' => 'sanitize_text_field' ] );
register_setting( 'swps_search_appearance', 'swps_breadcrumbs_home_label', [ 'sanitize_callback' => 'sanitize_text_field' ] );
```

- [ ] **Step 5: Set defaults in activation**

```php
'breadcrumbs_enabled'   => 1,
'breadcrumbs_separator' => '&raquo;',
'breadcrumbs_home_label' => 'Home',
```

- [ ] **Step 6: Verify syntax**

Run: `php -l includes/class-breadcrumbs.php`
Expected: No syntax errors

- [ ] **Step 7: Commit**

```bash
git add includes/class-breadcrumbs.php includes/class-schema.php stratawp-seo.php templates/search-appearance-page.php includes/class-settings.php
git commit -m "feat: add frontend breadcrumbs with HTML + schema, shortcode, and template function"
```

---

## Chunk 7: Documentation + Final Integration

### Task 9: Update README and readme.txt

**Files:**
- Modify: `README.md`
- Modify: `readme.txt`

- [ ] **Step 1: Add v3.0.0 changelog entry to README.md**

Add to the changelog section:

```markdown
### 3.0.0 — Yoast Replacement

- **Full Sitemap System** — Sitemap index with post type, taxonomy, and author sub-sitemaps. Per-URL priority/changefreq control. Image sitemap entries. IndexNow support for instant indexing.
- **Search Appearance** — Configurable title/description templates for all content types with template variables. Title separator picker.
- **Taxonomy & Archive SEO** — Meta title, description, canonical URL, robots directives, and OG tags on category/tag/taxonomy edit screens with frontend output on archive pages.
- **Redirect Manager** — 301/302/307/410 redirects with exact and regex matching. 404 error monitoring with one-click redirect creation. Auto-redirect on slug change.
- **Frontend Breadcrumbs** — HTML breadcrumb output with inline schema markup. Template function, shortcode, and configurable separator/home label.
- **RSS Feed Optimization** — Configurable before/after content in RSS feed items with template variables.
- **wp_head Cleanup** — Toggle-based removal of WP generator tag, RSD link, shortlink, REST API link, oEmbed, and emoji scripts.
```

- [ ] **Step 2: Update readme.txt**

Update `Stable tag: 3.0.0` and add changelog entry matching the above.

- [ ] **Step 3: Commit**

```bash
git add README.md readme.txt
git commit -m "docs: update changelog and feature list for v3.0.0"
```

---

### Task 10: Final Admin CSS Updates

**Files:**
- Modify: `admin/css/admin.css`

- [ ] **Step 1: Add styles for new pages**

Append to `admin/css/admin.css`:

```css
/* Search Appearance */
.swps-search-appearance .form-table input.swps-title-template { font-family: monospace; }
.swps-search-appearance .swps-template-preview { color: #666; font-style: italic; }
.swps-search-appearance .swps-template-help { margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; }
.swps-search-appearance .swps-template-help code { background: #eee; padding: 2px 5px; }

/* Redirects */
.swps-redirects-page .nav-tab-wrapper { margin-bottom: 20px; }
.swps-redirects-page #swps-redirects-table td,
.swps-redirects-page #swps-404s-table td { vertical-align: middle; }

/* Breadcrumbs */
.swps-breadcrumbs { font-size: 0.875em; padding: 8px 0; }
.swps-breadcrumbs ol { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.swps-breadcrumbs li { display: inline; }
.swps-breadcrumbs a { color: inherit; text-decoration: none; }
.swps-breadcrumbs a:hover { text-decoration: underline; }
.swps-breadcrumb-sep { color: #999; margin: 0 2px; }
```

- [ ] **Step 2: Commit**

```bash
git add admin/css/admin.css
git commit -m "style: add CSS for search appearance, redirects, and breadcrumbs pages"
```

---

### Task 11: Verify Full Integration

- [ ] **Step 1: Check all PHP files for syntax errors**

Run: `find includes/ templates/ -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'`
Expected: No output (all files pass)

- [ ] **Step 2: Verify all new classes are loaded**

Check `stratawp-seo.php` has `require_once` for all 9 new class files and that each has a corresponding property + constructor initialization.

- [ ] **Step 3: Verify uninstall.php handles all new data**

Confirm `uninstall.php` drops `wp_swps_redirects` and `wp_swps_404_log` tables and cleans up term meta.

- [ ] **Step 4: Final commit if any fixes needed**

```bash
git add -A
git commit -m "fix: address integration issues from final review"
```
