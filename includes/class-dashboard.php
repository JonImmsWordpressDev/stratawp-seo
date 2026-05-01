<?php
/**
 * Dashboard page — landing surface for StrataWP SEO.
 *
 * Activity hub (KPI tiles, recent generations, queues, issues) above
 * the modules grid. Aggregates data from Audit, Cost Tracker, Analytics,
 * Generator, Internal Links, etc.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Dashboard {

    public function __construct() {
        // Hook earlier than SWPS_Settings (priority 10) so we register the
        // top-level menu first. Settings::register_menu() now skips the
        // top-level add_menu_page and only registers submenus.
        add_action( 'admin_menu', [ $this, 'register_menu' ], 5 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Page-specific CSS/JS for the dashboard.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_stratawp-seo' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'swps-dashboard',
            SWPS_PLUGIN_URL . 'admin/css/pages/dashboard.css',
            [ 'swps-tokens', 'swps-shell', 'swps-components' ],
            SWPS_VERSION
        );
        wp_enqueue_script(
            'swps-dashboard',
            SWPS_PLUGIN_URL . 'admin/js/dashboard.js',
            [ 'swps-shell' ],
            SWPS_VERSION,
            true
        );
    }

    /**
     * Register the top-level menu and Dashboard submenu.
     */
    public function register_menu(): void {
        add_menu_page(
            __( 'StrataWP SEO', 'stratawp-seo' ),
            __( 'StrataWP SEO', 'stratawp-seo' ),
            'manage_options',
            'stratawp-seo',
            [ $this, 'render' ],
            'dashicons-superhero-alt',
            30
        );

        add_submenu_page(
            'stratawp-seo',
            __( 'Dashboard', 'stratawp-seo' ),
            __( 'Dashboard', 'stratawp-seo' ),
            'manage_options',
            'stratawp-seo',
            [ $this, 'render' ]
        );

        // Phase 7-8 scaffolds: Auto-Optimize, Competitors.
        add_submenu_page(
            'stratawp-seo',
            __( 'Auto-Optimize', 'stratawp-seo' ),
            __( 'Auto-Optimize', 'stratawp-seo' ),
            'manage_options',
            'swps-auto-optimize',
            [ $this, 'render_auto_optimize' ]
        );

        add_submenu_page(
            'stratawp-seo',
            __( 'Competitors', 'stratawp-seo' ),
            __( 'Competitors', 'stratawp-seo' ),
            'manage_options',
            'swps-competitors',
            [ $this, 'render_competitors' ]
        );
    }

    /**
     * Render the dashboard page.
     */
    public function render(): void {
        $data = $this->get_summary_data();
        require SWPS_PLUGIN_DIR . 'templates/dashboard-page.php';
    }

    /**
     * Render Auto-Optimize scaffold.
     */
    public function render_auto_optimize(): void {
        require SWPS_PLUGIN_DIR . 'templates/auto-optimize-page.php';
    }

    /**
     * Render Competitors scaffold.
     */
    public function render_competitors(): void {
        require SWPS_PLUGIN_DIR . 'templates/competitors-page.php';
    }

    /**
     * Build everything the dashboard template needs.
     *
     * Each piece is wrapped in try/catch so a single subsystem error
     * doesn't blank the whole page.
     *
     * @return array
     */
    public function get_summary_data(): array {
        $current_user = wp_get_current_user();

        return [
            'first_name'         => $current_user->first_name ?: $current_user->display_name,
            'site_health'        => $this->safe_get_health(),
            'recent_generations' => $this->safe_get_recent_generations(),
            'ai_cost_30d'        => $this->safe_get_ai_cost(),
            'posts_30d'          => $this->safe_get_post_views(),
            'top_issues'         => $this->safe_get_top_issues(),
            'top_queries'        => $this->safe_get_top_gsc_queries(),
            'auto_optimize_queue' => [], // Phase 7 fills in.
            'competitors'        => [],  // Phase 8 fills in.
            'modules'            => $this->get_modules_for_grid(),
        ];
    }

    private function safe_get_health(): array {
        try {
            $audit    = stratawp_seo()->seo_audit;
            $results  = $audit->get_cached_results();
            $last_run = $audit->get_last_run();
            $score    = (int) ( $results['overall_score'] ?? 0 );

            $crit_count = 0;
            $warn_count = 0;
            $pass_count = 0;
            foreach ( ( $results['modules'] ?? [] ) as $mod ) {
                $status = $mod['status'] ?? '';
                if ( 'critical' === $status ) {
                    $crit_count++;
                } elseif ( 'warning' === $status ) {
                    $warn_count++;
                } else {
                    $pass_count++;
                }
            }

            $label = 'Needs work';
            if ( $score >= 80 ) {
                $label = 'Healthy';
            } elseif ( $score >= 50 ) {
                $label = 'Fair';
            }

            return [
                'score'      => $score,
                'label'      => $label,
                'crit_count' => $crit_count,
                'warn_count' => $warn_count,
                'pass_count' => $pass_count,
                'last_run'   => $last_run,
            ];
        } catch ( \Throwable $e ) {
            return [
                'score' => 0, 'label' => '—',
                'crit_count' => 0, 'warn_count' => 0, 'pass_count' => 0,
                'last_run' => 0,
            ];
        }
    }

    private function safe_get_recent_generations(): array {
        try {
            $posts = get_posts( [
                'numberposts' => 3,
                'post_status' => [ 'publish', 'draft', 'future', 'pending' ],
                'meta_key'    => '_swps_generated_at',
                'orderby'     => 'meta_value',
                'order'       => 'DESC',
            ] );
            $out = [];
            foreach ( $posts as $p ) {
                $out[] = [
                    'id'           => $p->ID,
                    'title'        => $p->post_title,
                    'template'     => get_post_meta( $p->ID, '_swps_template', true ) ?: 'auto',
                    'word_count'   => str_word_count( wp_strip_all_tags( $p->post_content ) ),
                    'model'        => get_post_meta( $p->ID, '_swps_model', true ) ?: '',
                    'score'        => (int) get_post_meta( $p->ID, '_swps_content_score', true ),
                    'status'       => $p->post_status,
                    'edit_link'    => get_edit_post_link( $p->ID ),
                    'time_ago'     => human_time_diff( strtotime( $p->post_date_gmt ), time() ) . ' ago',
                ];
            }
            return $out;
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    private function safe_get_ai_cost(): array {
        try {
            $tracker = stratawp_seo()->cost_tracker;
            $stats   = $tracker->get_monthly_stats();
            return [
                'usd'  => (float) ( $stats['total_cost'] ?? 0 ),
                'gens' => (int) ( $stats['total_generations'] ?? 0 ),
            ];
        } catch ( \Throwable $e ) {
            return [ 'usd' => 0.0, 'gens' => 0 ];
        }
    }

    private function safe_get_post_views(): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'swps_analytics';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists !== $table ) {
                return [ 'views' => 0, 'delta_pct' => null ];
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $views_30  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $views_60  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND viewed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
            $delta = ( $views_60 > 0 ) ? round( ( ( $views_30 - $views_60 ) / $views_60 ) * 100, 1 ) : null;
            return [ 'views' => $views_30, 'delta_pct' => $delta ];
        } catch ( \Throwable $e ) {
            return [ 'views' => 0, 'delta_pct' => null ];
        }
    }

    private function safe_get_top_issues(): array {
        try {
            $audit   = stratawp_seo()->seo_audit;
            $results = $audit->get_cached_results();
            $issues  = [];
            foreach ( ( $results['modules'] ?? [] ) as $mod ) {
                $status = $mod['status'] ?? '';
                if ( 'critical' === $status || 'warning' === $status ) {
                    $issues[] = [
                        'level'    => $status === 'critical' ? 'crit' : 'warn',
                        'name'     => $mod['name'] ?? '',
                        'message'  => $mod['message'] ?? '',
                        'fixable'  => ! empty( $mod['fixable'] ),
                        'page_url' => admin_url( 'admin.php?page=swps-seo-audit' ),
                    ];
                }
                if ( count( $issues ) >= 3 ) {
                    break;
                }
            }
            return $issues;
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    private function safe_get_top_gsc_queries(): array {
        try {
            $gsc = stratawp_seo()->search_console;
            if ( ! method_exists( $gsc, 'get_top_queries' ) ) {
                return [];
            }
            $rows = $gsc->get_top_queries( 7, 3 );
            return is_array( $rows ) ? $rows : [];
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    /**
     * Static modules list for the dashboard grid until Phase 6
     * replaces this with the real Modules registry.
     */
    private function get_modules_for_grid(): array {
        return [
            [ 'slug' => 'ai-generation',  'icon' => '✦', 'title' => 'AI Content Generation', 'desc' => 'Site-aware AI posts with internal links, FAQs, schema.', 'badge' => '', 'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-settings' ) ],
            [ 'slug' => 'auto-optimize',  'icon' => '↻', 'title' => 'AI Auto-Optimize',     'desc' => 'Auto-refresh old posts: kw in H2, first paragraph, schema, alt text.', 'badge' => 'ai',  'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-auto-optimize' ) ],
            [ 'slug' => 'competitors',    'icon' => '◎', 'title' => 'Competitors',          'desc' => 'Track sites, schema, keyword gaps, content velocity.', 'badge' => 'new', 'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-competitors' ) ],
            [ 'slug' => 'seo-audit',      'icon' => '⚡', 'title' => 'SEO Audit',           'desc' => '8-module health check with one-click auto-fix.', 'badge' => '',    'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-seo-audit' ) ],
            [ 'slug' => 'schema',         'icon' => '{}', 'title' => 'Schema',              'desc' => 'Article, FAQ, BreadcrumbList, Organization JSON-LD.', 'badge' => '', 'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-settings#schema' ) ],
            [ 'slug' => 'redirects',      'icon' => '⤳', 'title' => 'Redirects',           'desc' => '301/302/307/410, regex, 404 monitor with one-click fix.', 'badge' => '', 'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-redirects' ) ],
            [ 'slug' => 'sitemaps',       'icon' => '⌘', 'title' => 'Sitemaps',            'desc' => 'XML index, image sitemap, IndexNow batch submit.', 'badge' => '', 'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-sitemaps' ) ],
            [ 'slug' => 'internal-links', 'icon' => '⟿', 'title' => 'Internal Links',     'desc' => 'Keyword + AI-suggested internal linking.', 'badge' => '', 'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-internal-links' ) ],
            [ 'slug' => 'meta-editor',    'icon' => '◆', 'title' => 'Meta Editor',         'desc' => 'Per-post title, description, OG, Twitter, robots, canonical.', 'badge' => '', 'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-settings#meta' ) ],
            [ 'slug' => 'analytics',      'icon' => '◔', 'title' => 'Analytics',           'desc' => 'Cookie-free pageviews, time on page, scroll depth.', 'badge' => '', 'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-analytics' ) ],
            [ 'slug' => 'ai-bots',        'icon' => '⌨', 'title' => 'AI Crawlers + llms.txt', 'desc' => 'GPTBot, ClaudeBot, PerplexityBot allowlist + dynamic /llms.txt.', 'badge' => '', 'enabled' => true,  'settings_url' => admin_url( 'admin.php?page=swps-settings#ai-bots' ) ],
            [ 'slug' => 'local-seo',      'icon' => '◉', 'title' => 'Local SEO',           'desc' => 'Google Business profile, NAP, LocalBusiness schema.', 'badge' => 'soon', 'enabled' => false, 'settings_url' => '' ],
            [ 'slug' => 'woocommerce',    'icon' => '⌥', 'title' => 'WooCommerce SEO',     'desc' => 'Product schema, category SEO, OG for variations.', 'badge' => 'soon', 'enabled' => false, 'settings_url' => '' ],
            [ 'slug' => 'image-seo',      'icon' => '◈', 'title' => 'Image SEO',           'desc' => 'Auto-alt, auto-rename, compression, WebP.', 'badge' => 'soon', 'enabled' => false, 'settings_url' => '' ],
        ];
    }
}
