<?php
/**
 * Backlinks page (v4.2.2).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$bl    = stratawp_seo()->backlinks;
$stats = $bl->get_stats();
$rows  = $bl->get_all();

$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
?>
<div class="wrap">
    <?php
    $title    = __( 'Backlinks', 'stratawp-seo' );
    $subtitle = sprintf(
        /* translators: %s: site host */
        esc_html__( 'Track pages that link to %s. Add backlinks one at a time, or paste a CSV (e.g. the "Top linking sites" export from Google Search Console). StrataWP re-checks each link daily and tells you when one disappears.', 'stratawp-seo' ),
        '<code>' . esc_html( $home_host ) . '</code>'
    );
    $actions  = [
        [
            'label' => __( 'Verify all', 'stratawp-seo' ),
            'url'   => '',
            'class' => 'swps-btn-grad',
            'attrs' => 'id="swps-bl-verify-all"',
        ],
        [
            'label' => __( 'Export CSV', 'stratawp-seo' ),
            'url'   => add_query_arg( [
                'action' => 'swps_backlink_export',
                'nonce'  => wp_create_nonce( 'swps_nonce' ),
            ], admin_url( 'admin-ajax.php' ) ),
            'class' => 'swps-btn-secondary',
        ],
    ];
    require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
    ?>

    <!-- Stats tiles -->
    <div class="swps-bl-stats">
        <div class="swps-tile">
            <div class="swps-tile-h"><?php esc_html_e( 'Total tracked', 'stratawp-seo' ); ?></div>
            <div class="swps-tile-stat" id="swps-bl-stat-total"><?php echo number_format( (int) $stats['total'] ); ?></div>
            <div class="swps-tile-trend" style="color:var(--swps-text-faint)">
                <?php
                printf(
                    /* translators: %d: unique referring domains */
                    esc_html__( '%d unique referring domains', 'stratawp-seo' ),
                    (int) $stats['unique_domains']
                );
                ?>
            </div>
        </div>
        <div class="swps-tile">
            <div class="swps-tile-h"><?php esc_html_e( 'Live', 'stratawp-seo' ); ?></div>
            <div class="swps-tile-stat" id="swps-bl-stat-live" style="color:#10b981"><?php echo number_format( (int) $stats['live'] ); ?></div>
            <div class="swps-tile-trend" style="color:var(--swps-text-faint)">
                <?php
                printf(
                    /* translators: %d: count gained in last 30 days */
                    esc_html__( '+%d in last 30 days', 'stratawp-seo' ),
                    (int) $stats['new_30d']
                );
                ?>
            </div>
        </div>
        <div class="swps-tile">
            <div class="swps-tile-h"><?php esc_html_e( 'Lost', 'stratawp-seo' ); ?></div>
            <div class="swps-tile-stat" id="swps-bl-stat-lost" style="color:#f59e0b"><?php echo number_format( (int) $stats['lost'] ); ?></div>
            <div class="swps-tile-trend" style="color:var(--swps-text-faint)">
                <?php
                printf(
                    /* translators: %d: count lost in last 30 days */
                    esc_html__( '%d lost in last 30 days', 'stratawp-seo' ),
                    (int) $stats['lost_30d']
                );
                ?>
            </div>
        </div>
        <div class="swps-tile">
            <div class="swps-tile-h"><?php esc_html_e( 'Broken', 'stratawp-seo' ); ?></div>
            <div class="swps-tile-stat" id="swps-bl-stat-broken" style="color:#ef4444"><?php echo number_format( (int) $stats['broken'] ); ?></div>
            <div class="swps-tile-trend" style="color:var(--swps-text-faint)">
                <?php esc_html_e( 'Source page returns an error', 'stratawp-seo' ); ?>
            </div>
        </div>
    </div>

    <!-- Add + import row -->
    <div class="swps-bl-tools">
        <div class="swps-card">
            <h2 style="margin-top:0"><?php esc_html_e( 'Add backlink', 'stratawp-seo' ); ?></h2>
            <form id="swps-bl-add" class="swps-bl-add-form" autocomplete="off">
                <label class="swps-bl-field">
                    <span><?php esc_html_e( 'Source URL', 'stratawp-seo' ); ?></span>
                    <input type="url" name="source_url" required placeholder="https://example.com/article-linking-to-you" />
                </label>
                <label class="swps-bl-field">
                    <span><?php esc_html_e( 'Target URL on your site', 'stratawp-seo' ); ?> <em><?php esc_html_e( '(optional)', 'stratawp-seo' ); ?></em></span>
                    <input type="url" name="target_url" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
                </label>
                <label class="swps-bl-field">
                    <span><?php esc_html_e( 'Anchor text', 'stratawp-seo' ); ?> <em><?php esc_html_e( '(optional)', 'stratawp-seo' ); ?></em></span>
                    <input type="text" name="anchor_text" />
                </label>
                <button type="submit" class="swps-btn swps-btn-grad"><?php esc_html_e( 'Add & verify', 'stratawp-seo' ); ?></button>
                <span class="swps-bl-add-status" style="margin-left:8px;color:var(--swps-text-muted);font-size:12px"></span>
            </form>
        </div>

        <div class="swps-card">
            <h2 style="margin-top:0"><?php esc_html_e( 'Bulk import', 'stratawp-seo' ); ?></h2>
            <p class="description" style="margin-top:0">
                <?php esc_html_e( 'Paste rows below: one source URL per line, or a CSV in the format source_url,target_url,anchor_text. The "Top linking sites" CSV export from Google Search Console works directly.', 'stratawp-seo' ); ?>
            </p>
            <form id="swps-bl-import">
                <textarea name="csv" rows="6" class="swps-bl-import-area"
                          placeholder="https://blog.example.com/post-1&#10;https://news.example.org/page-2"></textarea>
                <div style="display:flex;align-items:center;gap:10px;margin-top:8px">
                    <button type="submit" class="swps-btn swps-btn-grad"><?php esc_html_e( 'Import', 'stratawp-seo' ); ?></button>
                    <span class="swps-bl-import-status" style="color:var(--swps-text-muted);font-size:12px"></span>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="swps-card swps-bl-table-card">
        <div class="swps-bl-table-head">
            <h2 style="margin:0;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:var(--swps-text-muted)">
                <?php esc_html_e( 'Tracked backlinks', 'stratawp-seo' ); ?>
            </h2>
            <div class="swps-bl-progress" id="swps-bl-progress" hidden>
                <span></span>
            </div>
        </div>

        <?php if ( empty( $rows ) ) : ?>
            <p style="color:var(--swps-text-muted);font-size:13px;margin:8px 0 0">
                <?php esc_html_e( 'No backlinks yet. Add one above, or paste a CSV from GSC.', 'stratawp-seo' ); ?>
            </p>
        <?php else : ?>
            <table class="widefat striped swps-bl-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'stratawp-seo' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'stratawp-seo' ); ?></th>
                        <th><?php esc_html_e( 'Anchor', 'stratawp-seo' ); ?></th>
                        <th><?php esc_html_e( 'Target', 'stratawp-seo' ); ?></th>
                        <th><?php esc_html_e( 'First seen', 'stratawp-seo' ); ?></th>
                        <th><?php esc_html_e( 'Last checked', 'stratawp-seo' ); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="swps-bl-tbody">
                    <?php foreach ( $rows as $row ) : ?>
                        <?php $status = (string) $row['status']; ?>
                        <tr data-id="<?php echo (int) $row['id']; ?>">
                            <td>
                                <span class="swps-bl-status is-<?php echo esc_attr( $status ); ?>">
                                    <?php echo esc_html( ucfirst( $status ) ); ?>
                                    <?php if ( (int) $row['http_status'] > 0 && in_array( $status, [ 'broken' ], true ) ) : ?>
                                        <small>(<?php echo (int) $row['http_status']; ?>)</small>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( $row['source_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html( $row['source_host'] ?: $row['source_url'] ); ?>
                                </a>
                                <?php if ( ! empty( $row['notes'] ) ) : ?>
                                    <div style="font-size:11px;color:var(--swps-text-muted);margin-top:2px">
                                        <?php echo esc_html( $row['notes'] ); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $row['anchor_text'] ) ) : ?>
                                    <span style="font-size:12px"><?php echo esc_html( $row['anchor_text'] ); ?></span>
                                <?php else : ?>
                                    <span style="color:var(--swps-text-faint);font-size:12px">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $row['target_url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $row['target_url'] ); ?>" target="_blank" rel="noopener" style="font-size:12px">
                                        <?php echo esc_html( wp_parse_url( $row['target_url'], PHP_URL_PATH ) ?: $row['target_url'] ); ?>
                                    </a>
                                <?php else : ?>
                                    <span style="color:var(--swps-text-faint);font-size:12px">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:var(--swps-text-muted)">
                                <?php echo $row['first_seen'] ? esc_html( mysql2date( 'Y-m-d', $row['first_seen'] ) ) : '—'; ?>
                            </td>
                            <td style="font-size:12px;color:var(--swps-text-muted)">
                                <?php echo $row['last_checked'] ? esc_html( human_time_diff( strtotime( $row['last_checked'] ) ) . ' ago' ) : esc_html__( 'never', 'stratawp-seo' ); ?>
                            </td>
                            <td style="text-align:right;white-space:nowrap">
                                <button type="button" class="button-link swps-bl-verify" title="<?php esc_attr_e( 'Re-verify now', 'stratawp-seo' ); ?>">⟳</button>
                                <button type="button" class="button-link swps-bl-delete" title="<?php esc_attr_e( 'Delete', 'stratawp-seo' ); ?>" style="color:#ef4444">✕</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
