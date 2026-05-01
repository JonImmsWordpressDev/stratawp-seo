# Sitemaps, 404 Detection & Easy Redirects — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dedicated Sitemap admin page, enhance the 404 detection tab with referrer/suggestions/inline redirects/bulk actions.

**Architecture:** New `SWPS_Sitemap_Admin` class for the sitemap dashboard/settings page. Extend `SWPS_Redirect_Manager` with suggestion and bulk AJAX endpoints. Enhance the existing redirects template and JS for inline redirect creation and bulk operations. Per-post sitemap controls already exist — no changes needed there.

**Tech Stack:** PHP 8.0+, WordPress Settings API, vanilla JS (no build step), existing `swps_*` options and `swps_404_log`/`swps_redirects` tables.

---

### Task 1: Create Sitemap Admin Page — PHP Class

**Files:**
- Create: `includes/class-sitemap-admin.php`

- [ ] **Step 1: Create the sitemap admin class with AJAX handlers**

```php
<?php
/**
 * Sitemap Admin — dashboard and settings page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Sitemap_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        add_action( 'wp_ajax_swps_get_sitemaps', [ $this, 'ajax_get_sitemaps' ] );
        add_action( 'wp_ajax_swps_toggle_sitemap', [ $this, 'ajax_toggle_sitemap' ] );
        add_action( 'wp_ajax_swps_ping_search_engines', [ $this, 'ajax_ping_search_engines' ] );
    }

    /**
     * Register the Sitemaps submenu page.
     */
    public function register_menu(): void {
        add_submenu_page(
            'stratawp-seo',
            __( 'Sitemaps', 'stratawp-seo' ),
            __( 'Sitemaps', 'stratawp-seo' ),
            'manage_options',
            'swps-sitemaps',
            [ $this, 'render' ]
        );
    }

    /**
     * Register sitemap settings (moved from main Settings class).
     */
    public function register_settings(): void {
        register_setting( 'swps_sitemap_settings', 'swps_sitemap_exclude_images', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'swps_sitemap_settings', 'swps_sitemap_exclude_author', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'swps_sitemap_settings', 'swps_auto_redirect_slug_change', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'swps_sitemap_settings', 'swps_sitemap_urls_per_page', [
            'sanitize_callback' => function ( $value ) {
                $val = absint( $value );
                return ( $val >= 100 && $val <= 50000 ) ? $val : 1000;
            },
            'default' => 1000,
        ] );
    }

    /**
     * Render the sitemap admin page.
     */
    public function render(): void {
        include SWPS_PLUGIN_DIR . 'templates/sitemaps-page.php';
    }

    /**
     * AJAX: Get all sitemaps with URL counts and last modified dates.
     */
    public function ajax_get_sitemaps(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $sitemaps = [];

        // Post type sitemaps.
        $post_types = get_post_types( [ 'public' => true ] );
        foreach ( $post_types as $pt ) {
            if ( 'attachment' === $pt ) {
                continue;
            }
            $obj   = get_post_type_object( $pt );
            $count = $this->get_post_type_count( $pt );

            $sitemaps[] = [
                'key'       => $pt,
                'name'      => $obj->labels->name,
                'type'      => 'post_type',
                'url'       => home_url( "/{$pt}-sitemap.xml" ),
                'count'     => $count,
                'lastmod'   => $this->get_post_type_lastmod( $pt ),
                'excluded'  => (bool) get_option( "swps_sitemap_exclude_{$pt}", 0 ),
            ];
        }

        // Taxonomy sitemaps.
        $taxonomies = get_taxonomies( [ 'public' => true ] );
        foreach ( $taxonomies as $tax ) {
            if ( 'post_format' === $tax ) {
                continue;
            }
            $obj   = get_taxonomy( $tax );
            $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => true, 'fields' => 'count' ] );
            $count = is_wp_error( $terms ) ? 0 : (int) $terms;

            $sitemaps[] = [
                'key'       => $tax,
                'name'      => $obj->labels->name,
                'type'      => 'taxonomy',
                'url'       => home_url( "/{$tax}-sitemap.xml" ),
                'count'     => $count,
                'lastmod'   => gmdate( 'Y-m-d' ),
                'excluded'  => (bool) get_option( "swps_sitemap_exclude_{$tax}", 0 ),
            ];
        }

        // Author sitemap.
        $authors = get_users( [ 'has_published_posts' => true, 'fields' => 'ID' ] );
        $sitemaps[] = [
            'key'       => 'author',
            'name'      => __( 'Authors', 'stratawp-seo' ),
            'type'      => 'author',
            'url'       => home_url( '/author-sitemap.xml' ),
            'count'     => count( $authors ),
            'lastmod'   => gmdate( 'Y-m-d' ),
            'excluded'  => (bool) get_option( 'swps_sitemap_exclude_author', 0 ),
        ];

        wp_send_json_success( [
            'sitemaps'    => $sitemaps,
            'index_url'   => home_url( '/sitemap_index.xml' ),
            'indexnow_key' => get_option( 'swps_indexnow_key', '' ),
        ] );
    }

    /**
     * AJAX: Toggle a sitemap's excluded status.
     */
    public function ajax_toggle_sitemap(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
        if ( empty( $key ) ) {
            wp_send_json_error( 'Missing key.' );
        }

        $option_name = 'swps_sitemap_exclude_' . $key;
        $current     = (int) get_option( $option_name, 0 );
        $new_value   = $current ? 0 : 1;

        update_option( $option_name, $new_value );
        wp_send_json_success( [ 'excluded' => (bool) $new_value ] );
    }

    /**
     * AJAX: Manually ping search engines.
     */
    public function ajax_ping_search_engines(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $plugin = StrataWP_SEO::instance();
        $plugin->sitemap_manager->ping_search_engines();

        wp_send_json_success( [ 'message' => __( 'Pinged Google and Bing.', 'stratawp-seo' ) ] );
    }

    /**
     * Get published post count for a post type.
     */
    private function get_post_type_count( string $post_type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            $post_type
        ) );
    }

    /**
     * Get most recent modification date for a post type.
     */
    private function get_post_type_lastmod( string $post_type ): string {
        global $wpdb;
        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            $post_type
        ) );
        return $date ? gmdate( 'Y-m-d', strtotime( $date ) ) : gmdate( 'Y-m-d' );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/class-sitemap-admin.php
git commit -m "feat: add SWPS_Sitemap_Admin class with AJAX handlers"
```

---

### Task 2: Create Sitemap Admin Page — Template

**Files:**
- Create: `templates/sitemaps-page.php`

- [ ] **Step 1: Create the sitemaps page template with dashboard and settings tabs**

```php
<?php
/**
 * Sitemaps admin page template.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-sitemaps-page">
    <h1><?php esc_html_e( 'Sitemaps', 'stratawp-seo' ); ?></h1>

    <h2 class="nav-tab-wrapper">
        <a href="#dashboard" class="nav-tab nav-tab-active" data-tab="dashboard"><?php esc_html_e( 'Dashboard', 'stratawp-seo' ); ?></a>
        <a href="#settings" class="nav-tab" data-tab="settings"><?php esc_html_e( 'Settings', 'stratawp-seo' ); ?></a>
    </h2>

    <!-- Dashboard Tab -->
    <div id="tab-dashboard" class="swps-tab-content">
        <div class="swps-card" style="margin-top:16px;">
            <p>
                <strong><?php esc_html_e( 'Sitemap Index:', 'stratawp-seo' ); ?></strong>
                <a href="<?php echo esc_url( home_url( '/sitemap_index.xml' ) ); ?>" target="_blank" id="swps-sitemap-index-url">
                    <?php echo esc_url( home_url( '/sitemap_index.xml' ) ); ?>
                </a>
            </p>
            <p id="swps-indexnow-status"></p>
            <p>
                <button class="button button-primary" id="swps-ping-engines"><?php esc_html_e( 'Ping Search Engines', 'stratawp-seo' ); ?></button>
                <span id="swps-ping-result" style="margin-left:8px;"></span>
            </p>
        </div>

        <table class="widefat striped" id="swps-sitemaps-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Sitemap', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'URL', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'URLs', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Last Modified', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="5"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <!-- Settings Tab -->
    <div id="tab-settings" class="swps-tab-content" style="display:none;">
        <form method="post" action="options.php" style="margin-top:16px;">
            <?php settings_fields( 'swps_sitemap_settings' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Exclude Images', 'stratawp-seo' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="swps_sitemap_exclude_images" value="1" <?php checked( get_option( 'swps_sitemap_exclude_images', 0 ) ); ?>>
                            <?php esc_html_e( 'Do not include image entries in sitemaps', 'stratawp-seo' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Exclude Author Sitemap', 'stratawp-seo' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="swps_sitemap_exclude_author" value="1" <?php checked( get_option( 'swps_sitemap_exclude_author', 0 ) ); ?>>
                            <?php esc_html_e( 'Remove the author sitemap from the index', 'stratawp-seo' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Auto-Redirect on Slug Change', 'stratawp-seo' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="swps_auto_redirect_slug_change" value="1" <?php checked( get_option( 'swps_auto_redirect_slug_change', 1 ) ); ?>>
                            <?php esc_html_e( 'Automatically create a 301 redirect when a post slug changes', 'stratawp-seo' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="swps_sitemap_urls_per_page"><?php esc_html_e( 'URLs Per Sitemap', 'stratawp-seo' ); ?></label></th>
                    <td>
                        <input type="number" id="swps_sitemap_urls_per_page" name="swps_sitemap_urls_per_page"
                               value="<?php echo esc_attr( get_option( 'swps_sitemap_urls_per_page', 1000 ) ); ?>"
                               min="100" max="50000" step="100" class="small-text">
                        <p class="description"><?php esc_html_e( 'Maximum number of URLs per sitemap file (100-50,000).', 'stratawp-seo' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Settings', 'stratawp-seo' ) ); ?>
        </form>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add templates/sitemaps-page.php
git commit -m "feat: add sitemaps admin page template with dashboard and settings tabs"
```

---

### Task 3: Create Sitemap Admin Page — JavaScript

**Files:**
- Create: `admin/js/sitemaps.js`

- [ ] **Step 1: Create the sitemaps page JS with AJAX loading, toggle, and ping**

```javascript
/**
 * Sitemaps admin page — AJAX interactions.
 */
(function () {
    'use strict';

    const nonce = typeof swps_admin !== 'undefined' ? swps_admin.nonce : '';
    const ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';

    function loadSitemaps() {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'swps_get_sitemaps', nonce: nonce }),
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;

                const data = res.data;
                const tbody = document.querySelector('#swps-sitemaps-table tbody');
                tbody.innerHTML = '';

                data.sitemaps.forEach(s => {
                    const tr = document.createElement('tr');
                    if (s.excluded) tr.style.opacity = '0.5';
                    tr.innerHTML = `
                        <td><strong>${escHtml(s.name)}</strong> <span style="color:#646970;font-size:12px;">(${escHtml(s.type)})</span></td>
                        <td><a href="${escAttr(s.url)}" target="_blank">${escHtml(s.url)}</a></td>
                        <td>${s.count}</td>
                        <td>${escHtml(s.lastmod)}</td>
                        <td>
                            <button class="button button-small swps-toggle-sitemap" data-key="${escAttr(s.key)}">
                                ${s.excluded ? 'Enable' : 'Disable'}
                            </button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });

                // IndexNow status.
                const indexNowEl = document.getElementById('swps-indexnow-status');
                if (indexNowEl && data.indexnow_key) {
                    indexNowEl.innerHTML = '<strong>IndexNow Key:</strong> <code>' + escHtml(data.indexnow_key) + '</code>';
                }
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
        document.querySelectorAll('.swps-sitemaps-page .nav-tab').forEach(tab => {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelectorAll('.swps-sitemaps-page .nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
                this.classList.add('nav-tab-active');
                document.querySelectorAll('.swps-sitemaps-page .swps-tab-content').forEach(c => c.style.display = 'none');
                document.getElementById('tab-' + this.dataset.tab).style.display = 'block';
            });
        });

        // Toggle sitemap.
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('swps-toggle-sitemap')) {
                const key = e.target.dataset.key;
                e.target.disabled = true;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_toggle_sitemap', nonce, key }),
                })
                    .then(r => r.json())
                    .then(() => loadSitemaps());
            }
        });

        // Ping search engines.
        document.getElementById('swps-ping-engines')?.addEventListener('click', function () {
            const btn = this;
            const result = document.getElementById('swps-ping-result');
            btn.disabled = true;
            result.textContent = 'Pinging...';

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'swps_ping_search_engines', nonce }),
            })
                .then(r => r.json())
                .then(res => {
                    result.textContent = res.success ? res.data.message : 'Failed.';
                    btn.disabled = false;
                    setTimeout(() => { result.textContent = ''; }, 3000);
                });
        });

        // Initial load.
        if (document.querySelector('.swps-sitemaps-page')) {
            loadSitemaps();
        }
    });
})();
```

- [ ] **Step 2: Commit**

```bash
git add admin/js/sitemaps.js
git commit -m "feat: add sitemaps page JavaScript with AJAX loading and controls"
```

---

### Task 4: Wire Sitemap Admin into Plugin Bootstrap

**Files:**
- Modify: `stratawp-seo.php:97` (add require), `:161` (add property), `:203` (instantiate)
- Modify: `includes/class-settings.php:496-501` (remove old sitemap section)
- Modify: `includes/class-sitemap-manager.php:18` (use configurable URLs per sitemap)

- [ ] **Step 1: Add require for the new class in `stratawp-seo.php`**

After line 101 (`class-redirect-admin.php`), add:

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-sitemap-admin.php';
```

- [ ] **Step 2: Add the property in the main class**

After the `SWPS_Post_List_SEO $post_list_seo` property (line 165), add:

```php
public SWPS_Sitemap_Admin $sitemap_admin;
```

- [ ] **Step 3: Instantiate in the constructor**

After `$this->redirect_manager` instantiation (line 206), add:

```php
$this->sitemap_admin = new SWPS_Sitemap_Admin();
```

- [ ] **Step 4: Remove the old sitemap section from Settings**

In `includes/class-settings.php`, remove lines 496-501 (the sitemap section and its three fields). These settings are now managed by `SWPS_Sitemap_Admin::register_settings()`.

Remove this block:
```php
        // --- Sitemap Settings Section ---
        add_settings_section( 'swps_sitemap_section', __( 'Sitemaps', 'stratawp-seo' ), [ $this, 'render_sitemap_section' ], 'stratawp-seo' );

        $this->add_field( 'sitemap_exclude_images', __( 'Exclude Images from Sitemap', 'stratawp-seo' ), 'checkbox', 'swps_sitemap_section' );
        $this->add_field( 'sitemap_exclude_author', __( 'Exclude Author Sitemap', 'stratawp-seo' ), 'checkbox', 'swps_sitemap_section' );
        $this->add_field( 'auto_redirect_slug_change', __( 'Auto-redirect on slug change', 'stratawp-seo' ), 'checkbox', 'swps_sitemap_section' );
```

- [ ] **Step 5: Make URLS_PER_SITEMAP configurable in sitemap manager**

In `includes/class-sitemap-manager.php`, change line 18 from:

```php
private const URLS_PER_SITEMAP = 1000;
```

To a method that reads the option:

```php
private function get_urls_per_sitemap(): int {
    return (int) get_option( 'swps_sitemap_urls_per_page', 1000 );
}
```

Then replace all `self::URLS_PER_SITEMAP` references (lines 127, 174, 179, 243) with `$this->get_urls_per_sitemap()`.

- [ ] **Step 6: Enqueue the sitemaps JS on the sitemaps admin page**

In `stratawp-seo.php`, find the `enqueue_admin_assets` method. Add the sitemaps JS enqueue alongside the existing page-specific enqueues. Look for the pattern used for `redirects.js` and follow it:

```php
if ( 'stratawp-seo_page_swps-sitemaps' === $hook ) {
    wp_enqueue_script( 'swps-sitemaps', SWPS_PLUGIN_URL . 'admin/js/sitemaps.js', [], SWPS_VERSION, true );
}
```

- [ ] **Step 7: Commit**

```bash
git add stratawp-seo.php includes/class-settings.php includes/class-sitemap-manager.php
git commit -m "feat: wire sitemap admin into plugin bootstrap, move settings to dedicated page"
```

---

### Task 5: Add Redirect Suggestion & Bulk Endpoints to Redirect Manager

**Files:**
- Modify: `includes/class-redirect-manager.php`

- [ ] **Step 1: Register new AJAX handlers in the constructor**

After the existing AJAX registrations (line 39), add:

```php
add_action( 'wp_ajax_swps_suggest_redirect_target', [ $this, 'ajax_suggest_redirect_target' ] );
add_action( 'wp_ajax_swps_bulk_delete_404s', [ $this, 'ajax_bulk_delete_404s' ] );
add_action( 'wp_ajax_swps_bulk_redirect_404s', [ $this, 'ajax_bulk_redirect_404s' ] );
```

- [ ] **Step 2: Add the suggest_redirect_target method**

After the `ajax_delete_404` method (after line 287), add:

```php
    /**
     * Suggest redirect targets by fuzzy-matching a 404 URL against published posts.
     */
    public function ajax_suggest_redirect_target(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $url = sanitize_text_field( wp_unslash( $_POST['url'] ?? '' ) );
        if ( empty( $url ) ) {
            wp_send_json_success( [] );
        }

        $suggestions = $this->find_similar_posts( $url );
        wp_send_json_success( $suggestions );
    }

    /**
     * Find published posts similar to a given URL path.
     */
    private function find_similar_posts( string $url ): array {
        global $wpdb;

        // Extract the last path segment as the slug to match.
        $path = trim( wp_parse_url( $url, PHP_URL_PATH ) ?: $url, '/' );
        $slug = basename( $path );
        // Strip common extensions.
        $slug = preg_replace( '/\.(html?|php|aspx?)$/i', '', $slug );

        if ( empty( $slug ) ) {
            return [];
        }

        $like = '%' . $wpdb->esc_like( $slug ) . '%';
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title, post_name FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post', 'page')
               AND post_name LIKE %s
             ORDER BY CASE WHEN post_name = %s THEN 0 ELSE 1 END, post_date DESC
             LIMIT 5",
            $like,
            $slug
        ) );

        // If no results, try splitting slug on hyphens and searching for parts.
        if ( empty( $results ) && str_contains( $slug, '-' ) ) {
            $parts = explode( '-', $slug );
            // Use the longest part as a search term.
            usort( $parts, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
            $longest = $parts[0];
            if ( strlen( $longest ) >= 3 ) {
                $like = '%' . $wpdb->esc_like( $longest ) . '%';
                $results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, post_title, post_name FROM {$wpdb->posts}
                     WHERE post_status = 'publish'
                       AND post_type IN ('post', 'page')
                       AND post_name LIKE %s
                     ORDER BY post_date DESC
                     LIMIT 5",
                    $like
                ) );
            }
        }

        $suggestions = [];
        foreach ( $results as $row ) {
            $suggestions[] = [
                'title' => $row->post_title,
                'url'   => get_permalink( $row->ID ),
            ];
        }

        return $suggestions;
    }
```

- [ ] **Step 3: Add bulk delete and bulk redirect methods**

After the `find_similar_posts` method, add:

```php
    /**
     * Bulk delete 404 log entries.
     */
    public function ajax_bulk_delete_404s(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : [];
        if ( empty( $ids ) ) {
            wp_send_json_error( 'No IDs provided.' );
        }

        global $wpdb;
        $table        = $wpdb->prefix . self::LOG_TABLE;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE id IN ({$placeholders})",
            ...$ids
        ) );

        wp_send_json_success( [ 'deleted' => count( $ids ) ] );
    }

    /**
     * Bulk create redirects from 404 entries, then delete them.
     */
    public function ajax_bulk_redirect_404s(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : [];
        $target = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );

        if ( empty( $ids ) || empty( $target ) ) {
            wp_send_json_error( 'Missing IDs or target URL.' );
        }

        global $wpdb;
        $log_table    = $wpdb->prefix . self::LOG_TABLE;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // Get the 404 URLs for the selected IDs.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, url FROM {$log_table} WHERE id IN ({$placeholders})",
            ...$ids
        ) );

        $created = 0;
        foreach ( $rows as $row ) {
            $result = $this->add_redirect( $row->url, $target, 301 );
            if ( $result ) {
                $created++;
            }
        }

        // Delete the 404 entries.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$log_table} WHERE id IN ({$placeholders})",
            ...$ids
        ) );

        wp_send_json_success( [ 'created' => $created, 'deleted' => count( $ids ) ] );
    }
```

- [ ] **Step 4: Enhance `ajax_get_404s` to include referrer and suggestions**

Replace the existing `ajax_get_404s` method with:

```php
    public function ajax_get_404s(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $logs = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY count DESC LIMIT 100" );

        // Add suggestions for each 404.
        foreach ( $logs as &$log ) {
            $suggestions = $this->find_similar_posts( $log->url );
            $log->suggested_target = ! empty( $suggestions ) ? $suggestions[0]['url'] : '';
            $log->suggestions      = $suggestions;
        }

        wp_send_json_success( $logs );
    }
```

- [ ] **Step 5: Commit**

```bash
git add includes/class-redirect-manager.php
git commit -m "feat: add redirect suggestion, bulk delete, and bulk redirect AJAX endpoints"
```

---

### Task 6: Enhance Redirects Page Template — 404 Tab

**Files:**
- Modify: `templates/redirects-page.php`

- [ ] **Step 1: Replace the 404 tab section**

Replace the entire `tab-404s` div (lines 66-79) with the enhanced version:

```php
    <div id="tab-404s" class="swps-tab-content" style="display:none;">
        <h3><?php esc_html_e( '404 Errors', 'stratawp-seo' ); ?></h3>

        <!-- Bulk actions bar -->
        <div class="swps-404-bulk-bar" style="display:none; margin-bottom:12px; padding:8px 12px; background:#f0f6fc; border:1px solid #c3c4c7; border-radius:4px;">
            <span id="swps-404-selected-count">0</span> <?php esc_html_e( 'selected', 'stratawp-seo' ); ?> —
            <button class="button button-small" id="swps-bulk-dismiss-404s"><?php esc_html_e( 'Dismiss Selected', 'stratawp-seo' ); ?></button>
            <button class="button button-small" id="swps-bulk-redirect-404s"><?php esc_html_e( 'Redirect Selected to...', 'stratawp-seo' ); ?></button>
            <span id="swps-bulk-redirect-target-wrap" style="display:none;">
                <input type="text" id="swps-bulk-redirect-target" class="regular-text" placeholder="<?php esc_attr_e( '/target-page', 'stratawp-seo' ); ?>">
                <button class="button button-primary button-small" id="swps-bulk-redirect-confirm"><?php esc_html_e( 'Go', 'stratawp-seo' ); ?></button>
                <button class="button button-small" id="swps-bulk-redirect-cancel"><?php esc_html_e( 'Cancel', 'stratawp-seo' ); ?></button>
            </span>
        </div>

        <table class="widefat striped" id="swps-404s-table">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" id="swps-404-select-all"></th>
                    <th><?php esc_html_e( 'URL', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Referrer', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Hits', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Suggested Target', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Last Seen', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
```

- [ ] **Step 2: Commit**

```bash
git add templates/redirects-page.php
git commit -m "feat: enhance 404 tab with bulk actions bar, referrer and suggestion columns"
```

---

### Task 7: Enhance Redirects JavaScript — Inline Redirects, Suggestions, Bulk Actions

**Files:**
- Modify: `admin/js/redirects.js`

- [ ] **Step 1: Replace the entire redirects.js with the enhanced version**

```javascript
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
                    tr.dataset.id = r.id;
                    tr.dataset.url = r.url;

                    const suggestedHtml = r.suggested_target
                        ? `<a href="${escAttr(r.suggested_target)}" target="_blank" style="font-size:12px;">${escHtml(r.suggested_target)}</a>`
                        : '<span style="color:#646970;">—</span>';

                    const referrerHtml = r.referrer
                        ? `<span style="font-size:12px;">${escHtml(r.referrer)}</span>`
                        : '<span style="color:#646970;">—</span>';

                    tr.innerHTML = `
                        <td><input type="checkbox" class="swps-404-checkbox" value="${r.id}"></td>
                        <td>${escHtml(r.url)}</td>
                        <td>${referrerHtml}</td>
                        <td>${r.count}</td>
                        <td>${suggestedHtml}</td>
                        <td>${r.last_seen}</td>
                        <td>
                            <button class="button button-small button-primary swps-inline-redirect-btn" data-url="${escAttr(r.url)}" data-id="${r.id}" data-suggested="${escAttr(r.suggested_target || '')}">Redirect</button>
                            <button class="button button-small swps-delete-404" data-id="${r.id}">Dismiss</button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });

                updateBulkBar();
            });
    }

    /**
     * Show inline redirect form below a 404 row.
     */
    function showInlineRedirectForm(btn) {
        // Remove any existing inline form.
        const existing = document.querySelector('.swps-inline-redirect-row');
        if (existing) existing.remove();

        const tr = btn.closest('tr');
        const sourceUrl = btn.dataset.url;
        const suggested = btn.dataset.suggested;
        const entryId = btn.dataset.id;

        const formRow = document.createElement('tr');
        formRow.className = 'swps-inline-redirect-row';
        formRow.innerHTML = `
            <td></td>
            <td colspan="6" style="background:#f9f9f9; padding:12px;">
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <strong style="white-space:nowrap;">${escHtml(sourceUrl)}</strong>
                    <span style="color:#646970;">→</span>
                    <input type="text" class="regular-text swps-inline-target" value="${escAttr(suggested)}" placeholder="/target-page" style="flex:1; min-width:200px;">
                    <select class="swps-inline-type">
                        <option value="301">301</option>
                        <option value="302">302</option>
                        <option value="307">307</option>
                    </select>
                    <button class="button button-primary button-small swps-inline-save" data-source="${escAttr(sourceUrl)}" data-entry-id="${entryId}">Save</button>
                    <button class="button button-small swps-inline-cancel">Cancel</button>
                </div>
                <div class="swps-suggestion-chips" style="margin-top:8px;"></div>
            </td>
        `;
        tr.after(formRow);

        // Load suggestions.
        loadSuggestions(sourceUrl, formRow.querySelector('.swps-suggestion-chips'), formRow.querySelector('.swps-inline-target'));

        // Focus the target input.
        formRow.querySelector('.swps-inline-target').focus();
    }

    /**
     * Load URL suggestions and display as clickable chips.
     */
    function loadSuggestions(url, container, targetInput) {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'swps_suggest_redirect_target', nonce, url }),
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success || !res.data.length) return;
                container.innerHTML = '<span style="font-size:12px; color:#646970; margin-right:4px;">Suggestions:</span>';
                res.data.forEach(s => {
                    const chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'button button-small';
                    chip.style.cssText = 'margin:2px 4px 2px 0; font-size:11px;';
                    chip.textContent = s.title;
                    chip.title = s.url;
                    chip.addEventListener('click', () => {
                        targetInput.value = s.url;
                    });
                    container.appendChild(chip);
                });
            });
    }

    /**
     * Save inline redirect, dismiss 404, refresh list.
     */
    function saveInlineRedirect(btn) {
        const row = btn.closest('.swps-inline-redirect-row');
        const source = btn.dataset.source;
        const entryId = btn.dataset.entryId;
        const target = row.querySelector('.swps-inline-target').value;
        const type = row.querySelector('.swps-inline-type').value;

        if (!target) {
            row.querySelector('.swps-inline-target').focus();
            return;
        }

        btn.disabled = true;

        // Create redirect.
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'swps_add_redirect', nonce, source, target, type, is_regex: 0 }),
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    alert(res.data || 'Error creating redirect');
                    btn.disabled = false;
                    return;
                }
                // Dismiss the 404.
                return fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_delete_404', nonce, id: entryId }),
                });
            })
            .then(() => {
                load404s();
                loadRedirects();
            });
    }

    /**
     * Update bulk action bar visibility.
     */
    function updateBulkBar() {
        const checked = document.querySelectorAll('.swps-404-checkbox:checked');
        const bar = document.querySelector('.swps-404-bulk-bar');
        const countEl = document.getElementById('swps-404-selected-count');
        if (bar) {
            bar.style.display = checked.length > 0 ? 'block' : 'none';
            if (countEl) countEl.textContent = checked.length;
        }
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function escAttr(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;');
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

        // Delegate all click events.
        document.addEventListener('click', function (e) {
            // Delete redirect.
            if (e.target.classList.contains('swps-delete-redirect')) {
                const id = e.target.dataset.id;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_delete_redirect', nonce, id }),
                }).then(() => loadRedirects());
            }

            // Dismiss single 404.
            if (e.target.classList.contains('swps-delete-404')) {
                const id = e.target.dataset.id;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_delete_404', nonce, id }),
                }).then(() => load404s());
            }

            // Inline redirect button.
            if (e.target.classList.contains('swps-inline-redirect-btn')) {
                showInlineRedirectForm(e.target);
            }

            // Save inline redirect.
            if (e.target.classList.contains('swps-inline-save')) {
                saveInlineRedirect(e.target);
            }

            // Cancel inline redirect.
            if (e.target.classList.contains('swps-inline-cancel')) {
                const row = e.target.closest('.swps-inline-redirect-row');
                if (row) row.remove();
            }
        });

        // Checkbox change — update bulk bar.
        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('swps-404-checkbox') || e.target.id === 'swps-404-select-all') {
                if (e.target.id === 'swps-404-select-all') {
                    document.querySelectorAll('.swps-404-checkbox').forEach(cb => {
                        cb.checked = e.target.checked;
                    });
                }
                updateBulkBar();
            }
        });

        // Bulk dismiss.
        document.getElementById('swps-bulk-dismiss-404s')?.addEventListener('click', function () {
            const ids = [...document.querySelectorAll('.swps-404-checkbox:checked')].map(cb => cb.value);
            if (!ids.length) return;

            this.disabled = true;
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'swps_bulk_delete_404s', nonce, 'ids[]': ids }),
            })
                .then(r => r.json())
                .then(() => {
                    this.disabled = false;
                    load404s();
                });
        });

        // Bulk redirect — show target input.
        document.getElementById('swps-bulk-redirect-404s')?.addEventListener('click', function () {
            document.getElementById('swps-bulk-redirect-target-wrap').style.display = 'inline';
            document.getElementById('swps-bulk-redirect-target').focus();
        });

        // Bulk redirect — cancel.
        document.getElementById('swps-bulk-redirect-cancel')?.addEventListener('click', function () {
            document.getElementById('swps-bulk-redirect-target-wrap').style.display = 'none';
            document.getElementById('swps-bulk-redirect-target').value = '';
        });

        // Bulk redirect — confirm.
        document.getElementById('swps-bulk-redirect-confirm')?.addEventListener('click', function () {
            const ids = [...document.querySelectorAll('.swps-404-checkbox:checked')].map(cb => cb.value);
            const target = document.getElementById('swps-bulk-redirect-target').value;
            if (!ids.length || !target) return;

            this.disabled = true;
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'swps_bulk_redirect_404s', nonce, 'ids[]': ids, target }),
            })
                .then(r => r.json())
                .then(() => {
                    this.disabled = false;
                    document.getElementById('swps-bulk-redirect-target-wrap').style.display = 'none';
                    document.getElementById('swps-bulk-redirect-target').value = '';
                    load404s();
                    loadRedirects();
                });
        });

        // Initial load.
        loadRedirects();
    });
})();
```

- [ ] **Step 2: Commit**

```bash
git add admin/js/redirects.js
git commit -m "feat: enhance redirects JS with inline redirect forms, suggestions, and bulk actions"
```

---

### Task 8: Add CSS for New Components

**Files:**
- Modify: `admin/css/admin.css`

- [ ] **Step 1: Add styles at the end of admin.css**

Append after the existing content (after the internal links metabox styles):

```css

/* === v3.5 Sitemaps Page === */
.swps-sitemaps-page .nav-tab-wrapper { margin-bottom: 0; }
.swps-sitemaps-page #swps-sitemaps-table td { vertical-align: middle; }

/* === v3.5 Enhanced 404 Tab === */
.swps-404-bulk-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.swps-inline-redirect-row td {
    border-left: 3px solid #2271b1;
}

.swps-suggestion-chips .button {
    background: #e7f5ff;
    border-color: #b6d4fe;
    color: #1971c2;
}

.swps-suggestion-chips .button:hover {
    background: #d0ebff;
    border-color: #74c0fc;
}

#swps-404s-table .swps-404-checkbox {
    margin: 0;
}
```

- [ ] **Step 2: Commit**

```bash
git add admin/css/admin.css
git commit -m "style: add CSS for sitemaps page and enhanced 404 redirect UI"
```

---

### Task 9: Handle `ids[]` Array in Bulk AJAX Endpoints

**Files:**
- Modify: `includes/class-redirect-manager.php`

- [ ] **Step 1: Verify the bulk endpoints handle the `ids[]` POST format**

The `$_POST['ids']` from `URLSearchParams` with `'ids[]': ids` sends as separate `ids[]` entries. WordPress receives them as `$_POST['ids']` as an array. The existing code in Task 5 uses `(array) $_POST['ids']` which handles this correctly. No changes needed — this task is a verification checkpoint.

Run the following to verify no syntax errors in the modified redirect manager:

```bash
php -l includes/class-redirect-manager.php
```

Expected: `No syntax errors detected`

- [ ] **Step 2: Verify sitemaps admin class syntax**

```bash
php -l includes/class-sitemap-admin.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit (skip if no changes needed)**

No commit needed for verification-only task.

---

### Task 10: Update the Sitemap Manager to Use Configurable Constant

**Files:**
- Modify: `includes/class-sitemap-manager.php`

- [ ] **Step 1: Verify all `URLS_PER_SITEMAP` references are replaced**

After Task 4's changes, verify by searching:

```bash
grep -n 'URLS_PER_SITEMAP\|get_urls_per_sitemap' includes/class-sitemap-manager.php
```

Expected output should show `get_urls_per_sitemap` at lines 18 (definition), 127, 174, 179, 243. No references to `URLS_PER_SITEMAP` should remain.

- [ ] **Step 2: Verify the entire plugin loads without errors**

```bash
php -l stratawp-seo.php
php -l includes/class-sitemap-admin.php
php -l includes/class-sitemap-manager.php
php -l includes/class-redirect-manager.php
php -l includes/class-settings.php
```

All should report: `No syntax errors detected`

- [ ] **Step 3: Final commit with version bump**

Update version in `stratawp-seo.php` line 7 and line 20:

```php
 * Version: 3.6.0
```
```php
define( 'SWPS_VERSION', '3.6.0' );
```

```bash
git add stratawp-seo.php
git commit -m "chore: bump version to 3.6.0 for sitemap admin and enhanced 404 redirect features"
```
