<?php
/**
 * Analytics dashboard page (v4.0).
 *
 * @var bool   $gsc_connected Whether GSC is connected.
 * @var string $gsc_property  Selected GSC property.
 * @var string $gsc_auth_url  OAuth authorization URL.
 * @var array  $properties    Available GSC properties.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-analytics-wrap">

    <?php
    $title    = __( 'Analytics', 'stratawp-seo' );
    $subtitle = __( "Track your site's performance and search visibility. Cookie-free and GDPR-friendly.", 'stratawp-seo' );
    $actions  = [];
    if ( $gsc_connected ) {
        $actions[] = [ 'label' => __( 'Refresh GSC', 'stratawp-seo' ), 'class' => 'swps-btn-secondary', 'attrs' => 'id="swps-gsc-refresh" title="Refresh GSC data"' ];
        $actions[] = [ 'label' => __( 'Disconnect', 'stratawp-seo' ), 'class' => 'swps-btn-secondary', 'attrs' => 'id="swps-gsc-disconnect"' ];
    } elseif ( ! empty( $gsc_auth_url ) ) {
        $actions[] = [ 'label' => __( 'Connect Search Console', 'stratawp-seo' ), 'url' => $gsc_auth_url, 'class' => 'swps-btn-grad' ];
    }
    require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
    ?>

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
                <button class="swps-btn swps-btn-secondary" id="swps-gsc-save-property"><?php esc_html_e( 'Save', 'stratawp-seo' ); ?></button>
            </p>
        </div>
    <?php elseif ( ! $gsc_connected && empty( $gsc_auth_url ) ) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'Enter Google OAuth credentials in Settings to connect Search Console.', 'stratawp-seo' ); ?></p>
        </div>
    <?php endif; ?>

    <div class="swps-analytics-toolbar" style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px">
        <div class="swps-date-range" style="display:inline-flex;gap:4px;background:var(--swps-bg-surface);border:1px solid var(--swps-border);border-radius:var(--swps-radius-sm);padding:2px">
            <button class="swps-btn swps-range-btn" data-days="7" style="padding:6px 12px;font-size:11px"><?php esc_html_e( '7 days', 'stratawp-seo' ); ?></button>
            <button class="swps-btn swps-btn-grad swps-range-btn active" data-days="30" style="padding:6px 12px;font-size:11px"><?php esc_html_e( '30 days', 'stratawp-seo' ); ?></button>
            <button class="swps-btn swps-range-btn" data-days="90" style="padding:6px 12px;font-size:11px"><?php esc_html_e( '90 days', 'stratawp-seo' ); ?></button>
        </div>
        <?php if ( $gsc_connected ) : ?>
            <span class="swps-gsc-status swps-gsc-connected" style="color:var(--swps-success);font-size:12px">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php echo esc_html( $gsc_property ); ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="swps-summary-tiles">
        <div class="swps-summary-tile">
            <div class="swps-summary-tile-h"><?php esc_html_e( 'Page Views', 'stratawp-seo' ); ?></div>
            <div class="swps-summary-tile-num is-grad" id="swps-total-views">—</div>
            <div class="swps-summary-tile-foot" id="swps-views-change"></div>
        </div>
        <div class="swps-summary-tile">
            <div class="swps-summary-tile-h"><?php esc_html_e( 'Avg Time on Page', 'stratawp-seo' ); ?></div>
            <div class="swps-summary-tile-num is-grad" id="swps-avg-time">—</div>
        </div>
        <?php if ( $gsc_connected ) : ?>
        <div class="swps-summary-tile">
            <div class="swps-summary-tile-h"><?php esc_html_e( 'Search Clicks', 'stratawp-seo' ); ?></div>
            <div class="swps-summary-tile-num is-grad" id="swps-gsc-clicks">—</div>
        </div>
        <div class="swps-summary-tile">
            <div class="swps-summary-tile-h"><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></div>
            <div class="swps-summary-tile-num is-grad" id="swps-gsc-impressions">—</div>
        </div>
        <?php endif; ?>
    </div>

    <div class="swps-tile" style="margin-bottom:16px">
        <div class="swps-tile-h"><?php esc_html_e( 'Traffic Overview', 'stratawp-seo' ); ?></div>
        <p style="color:var(--swps-text-muted);font-size:12px;margin:0 0 12px"><?php esc_html_e( 'Page views and search clicks over time', 'stratawp-seo' ); ?></p>
        <canvas id="swps-analytics-chart" class="swps-chart" style="max-height:320px"></canvas>
    </div>

    <div class="swps-section-h">
        <h3><?php esc_html_e( 'Top Pages', 'stratawp-seo' ); ?></h3>
    </div>
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
            <tr><td colspan="8" class="swps-loading" style="color:var(--swps-text-muted)"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
        </tbody>
    </table>

    <?php if ( $gsc_connected ) : ?>
    <div class="swps-section-h" style="margin-top:32px">
        <h3><?php esc_html_e( 'Top Search Queries', 'stratawp-seo' ); ?></h3>
    </div>
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
            <tr><td colspan="5" class="swps-loading" style="color:var(--swps-text-muted)"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
        </tbody>
    </table>
    <?php endif; ?>
</div>
