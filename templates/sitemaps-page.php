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

    <div id="tab-dashboard" class="swps-tab-content" data-tab="dashboard">
        <div class="postbox">
            <div class="inside">
                <h3><?php esc_html_e( 'Sitemap Index', 'stratawp-seo' ); ?></h3>
                <p>
                    <a href="<?php echo esc_url( home_url( '/sitemap_index.xml' ) ); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_url( home_url( '/sitemap_index.xml' ) ); ?>
                    </a>
                </p>
            </div>
        </div>

        <div class="postbox">
            <div class="inside">
                <h3><?php esc_html_e( 'IndexNow Status', 'stratawp-seo' ); ?></h3>
                <p id="swps-indexnow-status">
                    <?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?>
                </p>
            </div>
        </div>

        <div class="postbox">
            <div class="inside">
                <h3><?php esc_html_e( 'Search Engines', 'stratawp-seo' ); ?></h3>
                <p>
                    <button class="button button-primary" id="swps-ping-engines"><?php esc_html_e( 'Ping Search Engines', 'stratawp-seo' ); ?></button>
                    <span id="swps-ping-result"></span>
                </p>
            </div>
        </div>

        <h3><?php esc_html_e( 'Sitemaps', 'stratawp-seo' ); ?></h3>
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
                <tr>
                    <td colspan="5"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div id="tab-settings" class="swps-tab-content" data-tab="settings" style="display:none;">
        <form method="post" action="options.php">
            <?php settings_fields( 'swps_sitemap_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="swps_sitemap_exclude_images"><?php esc_html_e( 'Exclude Images', 'stratawp-seo' ); ?></label></th>
                    <td>
                        <input type="hidden" name="swps_sitemap_exclude_images" value="0">
                        <input type="checkbox" id="swps_sitemap_exclude_images" name="swps_sitemap_exclude_images" value="1" <?php checked( get_option( 'swps_sitemap_exclude_images' ), 1 ); ?>>
                    </td>
                </tr>
                <tr>
                    <th><label for="swps_sitemap_exclude_author"><?php esc_html_e( 'Exclude Author Sitemap', 'stratawp-seo' ); ?></label></th>
                    <td>
                        <input type="hidden" name="swps_sitemap_exclude_author" value="0">
                        <input type="checkbox" id="swps_sitemap_exclude_author" name="swps_sitemap_exclude_author" value="1" <?php checked( get_option( 'swps_sitemap_exclude_author' ), 1 ); ?>>
                    </td>
                </tr>
                <tr>
                    <th><label for="swps_auto_redirect_slug_change"><?php esc_html_e( 'Auto-Redirect on Slug Change', 'stratawp-seo' ); ?></label></th>
                    <td>
                        <input type="hidden" name="swps_auto_redirect_slug_change" value="0">
                        <input type="checkbox" id="swps_auto_redirect_slug_change" name="swps_auto_redirect_slug_change" value="1" <?php checked( get_option( 'swps_auto_redirect_slug_change', 1 ), 1 ); ?>>
                    </td>
                </tr>
                <tr>
                    <th><label for="swps_sitemap_urls_per_page"><?php esc_html_e( 'URLs Per Sitemap', 'stratawp-seo' ); ?></label></th>
                    <td>
                        <input type="number" id="swps_sitemap_urls_per_page" name="swps_sitemap_urls_per_page" min="100" max="50000" step="100" value="<?php echo esc_attr( get_option( 'swps_sitemap_urls_per_page', 1000 ) ); ?>" class="small-text">
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
</div>
