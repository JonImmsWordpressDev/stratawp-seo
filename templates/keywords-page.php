<?php
/**
 * Keywords page template.
 *
 * @var bool $gsc_connected Whether GSC is connected.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-keywords-wrap">
    <div class="swps-page-header">
        <span class="swps-page-header-orb"></span>
        <span class="swps-page-header-orb"></span>
        <h1><?php esc_html_e( 'Keyword Research & Tracking', 'stratawp-seo' ); ?></h1>
        <p><?php esc_html_e( 'Discover opportunities and track your keyword rankings', 'stratawp-seo' ); ?></p>
    </div>

    <!-- AI Suggestion Panel -->
    <div class="swps-keywords-section swps-suggest-panel">
        <h2><?php esc_html_e( 'Discover Keywords', 'stratawp-seo' ); ?></h2>
        <div class="swps-suggest-form">
            <input type="text" id="swps-seed-topic" class="regular-text"
                   placeholder="<?php esc_attr_e( 'Enter a seed topic (e.g., home renovation tips)', 'stratawp-seo' ); ?>" />
            <button class="button button-primary" id="swps-suggest-btn">
                <?php esc_html_e( 'Get AI Suggestions', 'stratawp-seo' ); ?>
            </button>
            <span class="spinner" id="swps-suggest-spinner"></span>
        </div>
        <table class="widefat striped" id="swps-suggestions-table" style="display:none;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Keyword', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Intent', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Difficulty', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Suggested Title', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <!-- Tracked Keywords -->
    <div class="swps-keywords-section">
        <h2><?php esc_html_e( 'Tracked Keywords', 'stratawp-seo' ); ?></h2>
        <table class="widefat striped" id="swps-tracked-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Keyword', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Clicks', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'CTR', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Linked Post', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="7" class="swps-loading"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <?php if ( $gsc_connected ) : ?>
    <!-- Opportunities -->
    <div class="swps-keywords-section">
        <h2><?php esc_html_e( 'Opportunities (Striking Distance)', 'stratawp-seo' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Keywords ranking position 8-20 with high impressions — optimize these for quick wins.', 'stratawp-seo' ); ?></p>
        <table class="widefat striped" id="swps-opportunities-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Keyword', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'CTR', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="5" class="swps-loading"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Keyword History Modal -->
    <div id="swps-keyword-history-modal" class="swps-modal" style="display:none;">
        <div class="swps-modal-content">
            <span class="swps-modal-close">&times;</span>
            <h3 id="swps-history-title"></h3>
            <div id="swps-history-chart" class="swps-chart"></div>
        </div>
    </div>
</div>
