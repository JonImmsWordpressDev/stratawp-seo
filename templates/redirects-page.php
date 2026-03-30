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
</div>
