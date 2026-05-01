<?php
/**
 * Competitors page — scaffold for v4.1.
 *
 * Spec: docs/superpowers/specs/2026-04-30-admin-redesign-design.md §6.2
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-competitors-wrap">
    <?php
    $title    = __( 'Competitors', 'stratawp-seo' );
    $subtitle = __( 'Track competitor sites — schema diff, content velocity, and keyword gaps. Get notified when they ship something new that affects your rankings.', 'stratawp-seo' );
    $actions  = [];
    require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
    ?>

    <div class="swps-tile" style="margin-top:24px;text-align:center;padding:48px 24px">
        <div style="font-size:48px;line-height:1;margin-bottom:16px;background:var(--swps-accent-grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent">◎</div>
        <h2 style="margin:0 0 8px;font-size:18px;font-weight:600">
            <?php esc_html_e( 'Coming in v4.1', 'stratawp-seo' ); ?>
        </h2>
        <p style="color:var(--swps-text-muted);max-width:520px;margin:0 auto 16px;line-height:1.5">
            <?php esc_html_e( 'The MVP will track up to 10 competitor URLs. Each week the plugin scans for new posts, schema additions, title/H1 changes, and publish velocity — and flags anything you should react to. Keyword-gap analysis lands when a paid backlink/keyword data partner is wired up.', 'stratawp-seo' ); ?>
        </p>
        <p style="color:var(--swps-text-faint);font-size:12px">
            <?php esc_html_e( 'Want to be the first to try it? Watch the changelog or follow the release notes.', 'stratawp-seo' ); ?>
        </p>
    </div>
</div>
