<?php
/**
 * Analytics dashboard page template.
 *
 * @var bool   $gsc_connected Whether GSC is connected.
 * @var string $gsc_property  Selected GSC property.
 * @var string $gsc_auth_url  OAuth authorization URL.
 * @var array  $properties    Available GSC properties.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-analytics-wrap">
    <div class="swps-page-header">
        <span class="swps-page-header-orb"></span>
        <span class="swps-page-header-orb"></span>
        <h1><?php esc_html_e( 'Analytics', 'stratawp-seo' ); ?></h1>
        <p><?php esc_html_e( "Track your site's performance and search visibility", 'stratawp-seo' ); ?></p>
    </div>

    <?php if ( $gsc_connected && empty( $gsc_property ) && ! empty( $properties ) ) : ?>
        <div class="notice notice-info">
            <p>
                <strong><?php esc_html_e( 'Select your Search Console property:', 'stratawp-seo' ); ?></strong>
                <select id="swps-gsc-property-select">
                    <option value=""><?php esc_html_e( '— Select —', 'stratawp-seo' ); ?></option>
                    <?php foreach ( $properties as $prop ) : ?>
                        <option value="<?php echo esc_attr( $prop ); ?>"><?php echo esc_html( $prop ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button" id="swps-gsc-save-property"><?php esc_html_e( 'Save', 'stratawp-seo' ); ?></button>
            </p>
        </div>
    <?php endif; ?>

    <!-- Date Range -->
    <div class="swps-analytics-toolbar">
        <div class="swps-date-range">
            <button class="button swps-range-btn" data-days="7"><?php esc_html_e( '7 days', 'stratawp-seo' ); ?></button>
            <button class="button swps-range-btn active" data-days="30"><?php esc_html_e( '30 days', 'stratawp-seo' ); ?></button>
            <button class="button swps-range-btn" data-days="90"><?php esc_html_e( '90 days', 'stratawp-seo' ); ?></button>
        </div>
        <div class="swps-toolbar-right">
            <?php if ( $gsc_connected ) : ?>
                <span class="swps-gsc-status swps-gsc-connected">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php echo esc_html( $gsc_property ); ?>
                </span>
                <button class="button" id="swps-gsc-refresh" title="<?php esc_attr_e( 'Refresh GSC data', 'stratawp-seo' ); ?>">
                    <span class="dashicons dashicons-update"></span>
                </button>
                <button class="button" id="swps-gsc-disconnect"><?php esc_html_e( 'Disconnect', 'stratawp-seo' ); ?></button>
            <?php else : ?>
                <?php if ( ! empty( $gsc_auth_url ) ) : ?>
                    <a href="<?php echo esc_url( $gsc_auth_url ); ?>" class="button button-primary">
                        <span class="dashicons dashicons-google" style="margin-top:4px;"></span>
                        <?php esc_html_e( 'Connect Search Console', 'stratawp-seo' ); ?>
                    </a>
                <?php else : ?>
                    <span class="description"><?php esc_html_e( 'Enter Google OAuth credentials in Settings to connect.', 'stratawp-seo' ); ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Metric Cards -->
    <div class="swps-metric-cards">
        <div class="swps-metric-card">
            <span class="swps-metric-label"><?php esc_html_e( 'Page Views', 'stratawp-seo' ); ?></span>
            <span class="swps-metric-value" id="swps-total-views">—</span>
            <span class="swps-metric-change" id="swps-views-change"></span>
        </div>
        <div class="swps-metric-card">
            <span class="swps-metric-label"><?php esc_html_e( 'Avg Time on Page', 'stratawp-seo' ); ?></span>
            <span class="swps-metric-value" id="swps-avg-time">—</span>
        </div>
        <?php if ( $gsc_connected ) : ?>
        <div class="swps-metric-card">
            <span class="swps-metric-label"><?php esc_html_e( 'Search Clicks', 'stratawp-seo' ); ?></span>
            <span class="swps-metric-value" id="swps-gsc-clicks">—</span>
        </div>
        <div class="swps-metric-card">
            <span class="swps-metric-label"><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></span>
            <span class="swps-metric-value" id="swps-gsc-impressions">—</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Chart -->
    <div class="swps-chart-container">
        <h2><?php esc_html_e( 'Traffic Overview', 'stratawp-seo' ); ?></h2>
        <p class="swps-chart-subtitle"><?php esc_html_e( 'Page views and search clicks over time', 'stratawp-seo' ); ?></p>
        <canvas id="swps-analytics-chart" class="swps-chart"></canvas>
    </div>

    <!-- Top Pages -->
    <div class="swps-analytics-section">
        <h2><?php esc_html_e( 'Top Pages', 'stratawp-seo' ); ?></h2>
        <table class="widefat striped" id="swps-top-pages-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Page', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Views', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Avg Time', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Scroll', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Bounce', 'stratawp-seo' ); ?></th>
                    <?php if ( $gsc_connected ) : ?>
                    <th><?php esc_html_e( 'Clicks', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="8" class="swps-loading"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <?php if ( $gsc_connected ) : ?>
    <!-- Top Queries -->
    <div class="swps-analytics-section">
        <h2><?php esc_html_e( 'Top Search Queries', 'stratawp-seo' ); ?></h2>
        <table class="widefat striped" id="swps-top-queries-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Query', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Clicks', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'CTR', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="5" class="swps-loading"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
