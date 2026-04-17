<?php
/**
 * Settings page template with tabbed section groups.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = stratawp_seo()->settings;
$tabs     = $settings->get_settings_tabs();
$tab_keys = array_keys( $tabs );
$default  = $tab_keys[0] ?? 'ai-content';
?>
<div class="wrap swps-wrap swps-settings-wrap">
    <div class="swps-page-header">
        <span class="swps-page-header-orb"></span>
        <span class="swps-page-header-orb"></span>
        <h1>
            <span class="dashicons dashicons-superhero-alt"></span>
            <?php esc_html_e( 'StrataWP SEO — Settings', 'stratawp-seo' ); ?>
        </h1>
        <p><?php esc_html_e( 'Configure your AI-powered SEO content generator', 'stratawp-seo' ); ?></p>
    </div>

    <div class="swps-header-bar">
        <p><?php esc_html_e( 'Fill in your site details and preferences, then head to', 'stratawp-seo' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-generate' ) ); ?>"><?php esc_html_e( 'Generate Content', 'stratawp-seo' ); ?></a>
        <?php esc_html_e( 'to create your first post.', 'stratawp-seo' ); ?></p>
    </div>

    <h2 class="nav-tab-wrapper swps-settings-tabs" role="tablist">
        <?php foreach ( $tabs as $key => $tab ) : ?>
            <a
                href="#<?php echo esc_attr( $key ); ?>"
                class="nav-tab<?php echo $key === $default ? ' nav-tab-active' : ''; ?>"
                data-tab="<?php echo esc_attr( $key ); ?>"
                role="tab"
                aria-selected="<?php echo $key === $default ? 'true' : 'false'; ?>"
            >
                <?php echo esc_html( $tab['label'] ); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <form method="post" action="options.php">
        <?php settings_fields( 'stratawp-seo' ); ?>

        <?php foreach ( $tabs as $key => $tab ) : ?>
            <div
                class="swps-settings-panel"
                data-tab="<?php echo esc_attr( $key ); ?>"
                role="tabpanel"
                <?php echo $key === $default ? '' : 'style="display:none;"'; ?>
            >
                <?php $settings->render_sections_for_tab( $tab['sections'] ); ?>
            </div>
        <?php endforeach; ?>

        <?php submit_button( __( 'Save Settings', 'stratawp-seo' ) ); ?>
    </form>

    <div class="swps-card" style="margin-top: 20px;">
        <h2><?php esc_html_e( 'Cache Management', 'stratawp-seo' ); ?></h2>
        <p><?php esc_html_e( 'The site analyzer caches results for 1 hour to improve performance. Clear the cache if you\'ve made significant content changes and want fresh analysis data.', 'stratawp-seo' ); ?></p>
        <button type="button" id="swps-clear-cache-btn" class="button button-secondary">
            <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span>
            <?php esc_html_e( 'Clear Cache', 'stratawp-seo' ); ?>
        </button>
    </div>

    <?php
    $log = get_option( 'swps_generation_log', [] );
    if ( ! empty( $log ) ) :
    ?>
    <div class="swps-log-section">
        <h2><?php esc_html_e( 'Recent Activity', 'stratawp-seo' ); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Time', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Message', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( array_reverse( $log ) as $entry ) : ?>
                <tr>
                    <td><?php echo esc_html( $entry['time'] ); ?></td>
                    <td><?php echo esc_html( $entry['message'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var wrap = document.querySelector('.swps-settings-wrap');
        if (!wrap) return;

        var tabs = wrap.querySelectorAll('.swps-settings-tabs .nav-tab');
        var panels = wrap.querySelectorAll('.swps-settings-panel');

        function activate(key) {
            var found = false;
            tabs.forEach(function (t) {
                var match = t.dataset.tab === key;
                t.classList.toggle('nav-tab-active', match);
                t.setAttribute('aria-selected', match ? 'true' : 'false');
                if (match) found = true;
            });
            panels.forEach(function (p) {
                p.style.display = p.dataset.tab === key ? '' : 'none';
            });
            if (found && history.replaceState) {
                history.replaceState(null, '', '#' + key);
            }
        }

        tabs.forEach(function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                activate(t.dataset.tab);
            });
        });

        // Deep-link via hash.
        var hash = window.location.hash.replace(/^#/, '');
        if (hash && wrap.querySelector('.swps-settings-tabs .nav-tab[data-tab="' + hash + '"]')) {
            activate(hash);
        }

        // If the user submits and lands on `?settings-updated=true`, preserve the tab they were on.
        var form = wrap.querySelector('form');
        if (form) {
            form.addEventListener('submit', function () {
                var active = wrap.querySelector('.swps-settings-tabs .nav-tab-active');
                if (active && active.dataset.tab) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = '_swps_active_tab';
                    input.value = active.dataset.tab;
                    form.appendChild(input);
                    // Append the tab as a hash so WP's redirect preserves it.
                    form.action = form.action.split('#')[0] + '#' + active.dataset.tab;
                }
            });
        }
    });
})();
</script>
