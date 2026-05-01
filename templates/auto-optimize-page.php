<?php
/**
 * AI Auto-Optimize page — scaffold for v4.1.
 *
 * Spec: docs/superpowers/specs/2026-04-30-admin-redesign-design.md §6.1
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-auto-optimize-wrap">
    <?php
    $title    = __( 'AI Auto-Optimize', 'stratawp-seo' );
    $subtitle = __( 'Let AI refresh your old posts: keyword in H2 + first paragraph, missing alt text, weak intros, schema gaps. Every edit goes to a review queue you approve before it goes live.', 'stratawp-seo' );
    $actions  = [];
    require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
    ?>

    <div class="swps-tile" style="margin-top:24px;text-align:center;padding:48px 24px">
        <div style="font-size:48px;line-height:1;margin-bottom:16px;background:var(--swps-ai-grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent">↻</div>
        <h2 style="margin:0 0 8px;font-size:18px;font-weight:600">
            <?php esc_html_e( 'Coming in v4.1', 'stratawp-seo' ); ?>
        </h2>
        <p style="color:var(--swps-text-muted);max-width:520px;margin:0 auto 16px;line-height:1.5">
            <?php esc_html_e( 'Auto-Optimize will scan your published posts on a schedule, score them against the same SEO checklist used during generation, and propose AI edits to close any gaps. You always review proposed edits before they apply — or grant per-category trust to skip review for narrow edit types like alt text.', 'stratawp-seo' ); ?>
        </p>
        <p style="color:var(--swps-text-faint);font-size:12px">
            <?php esc_html_e( 'Want to be the first to try it? Watch the changelog or follow the release notes.', 'stratawp-seo' ); ?>
        </p>
    </div>
</div>
