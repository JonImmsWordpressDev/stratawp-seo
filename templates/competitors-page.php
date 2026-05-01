<?php
/**
 * Competitors page (v4.1).
 *
 * Wrapped by SWPS_Admin_Shell. Uses page-header partial + summary tiles
 * + .swps-row-card list pattern (mirrors auto-optimize-page.php).
 *
 * Spec: docs/superpowers/specs/2026-04-30-admin-redesign-design.md §6.2
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var SWPS_Competitors $competitors */
$competitors = stratawp_seo()->competitors;
$list        = $competitors->get_list();
$summary     = $competitors->get_summary( $list );

/**
 * Render a single competitor row using the data-dense .swps-row-card pattern.
 *
 * Declared up here (before the render loop) because PHP does not hoist
 * conditionally-declared functions — calling a function defined inside an
 * `if (! function_exists(...))` block before that block executes is a fatal.
 */
if ( ! function_exists( 'swps_render_competitor_row' ) ) {
    function swps_render_competitor_row( array $c, array $diff ): void {
        $has_data    = ! empty( $diff['has_data'] );
        $err         = (string) ( $c['last_error'] ?? '' );
        $has_error   = '' !== $err;
        $is_warning  = $has_error && $has_data; // partial scan (homepage worked, no feed/sitemap)
        $is_critical = $has_error && ! $has_data;

        if ( $is_critical ) {
            $dot_class = 'swps-status-dot-crit';
            $dot_glyph = '!';
        } elseif ( $is_warning ) {
            $dot_class = 'swps-status-dot-warn';
            $dot_glyph = '!';
        } else {
            $dot_class = 'swps-status-dot-ok';
            $dot_glyph = '✓';
        }

        $velocity     = (float)  ( $diff['velocity_per_week']  ?? 0 );
        $new_posts    = (int)    ( $diff['new_post_count']     ?? 0 );
        $new_schemas  = (array)  ( $diff['new_schema_types']   ?? [] );
        $title_chg    = ! empty( $diff['homepage_title_changed'] );
        $h1_chg       = ! empty( $diff['homepage_h1_changed']    );
        $latest_snap  = ! empty( $c['snapshots'] ) ? end( $c['snapshots'] ) : null;
        $schemas_now  = is_array( $latest_snap ) ? (array) ( $latest_snap['schema_types'] ?? [] ) : [];
        $last_scanned = (string) ( $c['last_scanned'] ?? '' );
        $source       = (string) ( $c['data_source']  ?? '' );
        ?>
        <div class="swps-row-card swps-comp-row"
             data-id="<?php echo esc_attr( $c['id'] ); ?>"
             data-url="<?php echo esc_attr( $c['url'] ); ?>"
             data-label="<?php echo esc_attr( $c['label'] ); ?>">
            <div class="swps-row-head">
                <div class="swps-status-dot <?php echo esc_attr( $dot_class ); ?>"><?php echo esc_html( $dot_glyph ); ?></div>
                <div>
                    <div class="swps-row-head-name">
                        <a href="<?php echo esc_url( $c['url'] ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( $c['label'] ); ?>
                        </a>
                        <?php if ( '' !== $source && 'none' !== $source ) : ?>
                            <span class="swps-comp-source-pill"><?php echo esc_html( strtoupper( $source ) ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="swps-row-head-msg">
                        <?php echo esc_html( wp_parse_url( $c['url'], PHP_URL_HOST ) ?: $c['url'] ); ?>
                        <?php if ( '' !== $last_scanned ) : ?>
                            &middot; <?php
                            printf(
                                /* translators: %s: time-ago */
                                esc_html__( 'scanned %s ago', 'stratawp-seo' ),
                                esc_html( human_time_diff( strtotime( $last_scanned ), current_time( 'timestamp' ) ) )
                            );
                            ?>
                        <?php else : ?>
                            &middot; <?php esc_html_e( 'never scanned', 'stratawp-seo' ); ?>
                        <?php endif; ?>
                        <?php if ( $has_error ) : ?>
                            &middot; <span class="swps-comp-err"><?php echo esc_html( $err ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="swps-row-head-meta">
                    <div class="swps-comp-stats">
                        <?php if ( $has_data ) : ?>
                            <?php if ( $new_posts > 0 ) : ?>
                                <button type="button" class="swps-comp-newposts swps-comp-newposts--has" data-toggle-newposts>
                                    +<?php echo (int) $new_posts; ?> <?php esc_html_e( 'new', 'stratawp-seo' ); ?>
                                </button>
                            <?php else : ?>
                                <span class="swps-comp-newposts">0 <?php esc_html_e( 'new', 'stratawp-seo' ); ?></span>
                            <?php endif; ?>
                            <span class="swps-comp-velocity">
                                <?php echo esc_html( number_format_i18n( $velocity, 1 ) ); ?>
                                <span class="swps-comp-velocity-unit"><?php esc_html_e( '/wk', 'stratawp-seo' ); ?></span>
                            </span>
                        <?php endif; ?>
                    </div>
                    <button class="swps-btn swps-btn-grad swps-comp-scan" style="padding:6px 12px;font-size:11px"><?php esc_html_e( 'Scan now', 'stratawp-seo' ); ?></button>
                    <button class="swps-btn swps-btn-secondary swps-comp-edit" title="<?php esc_attr_e( 'Edit', 'stratawp-seo' ); ?>" style="padding:6px 10px;font-size:11px">
                        <span class="dashicons dashicons-edit"></span>
                    </button>
                    <button class="swps-btn swps-btn-secondary swps-comp-delete" title="<?php esc_attr_e( 'Delete', 'stratawp-seo' ); ?>" style="padding:6px 10px;font-size:11px">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            </div>

            <?php if ( $has_data && ( ! empty( $schemas_now ) || ! empty( $new_schemas ) || $title_chg || $h1_chg ) ) : ?>
                <div class="swps-comp-row-body">
                    <?php if ( ! empty( $schemas_now ) || ! empty( $new_schemas ) ) : ?>
                        <div class="swps-comp-schema-row">
                            <span class="swps-comp-schema-label"><?php esc_html_e( 'Schema:', 'stratawp-seo' ); ?></span>
                            <?php foreach ( $schemas_now as $type ) : ?>
                                <?php $is_new = in_array( $type, $new_schemas, true ); ?>
                                <span class="swps-comp-schema-chip<?php echo $is_new ? ' is-new' : ''; ?>">
                                    <?php echo esc_html( $type ); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ( $title_chg || $h1_chg ) : ?>
                        <div class="swps-comp-changes">
                            <?php if ( $title_chg ) : ?>
                                <span class="swps-comp-change-pill">
                                    <span class="dashicons dashicons-edit-page"></span>
                                    <?php esc_html_e( 'Title changed', 'stratawp-seo' ); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( $h1_chg ) : ?>
                                <span class="swps-comp-change-pill">
                                    <span class="dashicons dashicons-heading"></span>
                                    <?php esc_html_e( 'H1 changed', 'stratawp-seo' ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( $has_data && $new_posts > 0 ) : ?>
                <div class="swps-comp-newposts-list" style="display:none;">
                    <div class="swps-comp-newposts-h"><?php esc_html_e( 'New posts since last scan', 'stratawp-seo' ); ?></div>
                    <ul>
                        <?php foreach ( $diff['new_posts'] as $post ) : ?>
                            <li>
                                <a href="<?php echo esc_url( $post['url'] ); ?>" target="_blank" rel="noopener">
                                    <?php echo esc_html( $post['title'] !== '' ? $post['title'] : $post['url'] ); ?>
                                </a>
                                <?php if ( ! empty( $post['date'] ) ) : ?>
                                    <span class="swps-comp-newposts-date"><?php echo esc_html( $post['date'] ); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
?>
<div class="wrap swps-competitors-wrap">

    <?php
    $title    = __( 'Competitors', 'stratawp-seo' );
    $subtitle = __( 'Track competitor sites — content velocity, schema diff, and homepage changes. Daily auto-scan, with manual refresh per row.', 'stratawp-seo' );
    $actions  = [
        [ 'label' => __( 'Scan all', 'stratawp-seo' ),       'class' => 'swps-btn-secondary', 'attrs' => 'id="swps-comp-scan-all"' ],
        [ 'label' => __( 'Add competitor', 'stratawp-seo' ), 'class' => 'swps-btn-grad',      'attrs' => 'id="swps-comp-add-toggle"' ],
    ];
    require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
    ?>

    <div class="swps-summary-tiles">
        <div class="swps-summary-tile">
            <div class="swps-summary-tile-h"><?php esc_html_e( 'Tracking', 'stratawp-seo' ); ?></div>
            <div class="swps-summary-tile-num is-grad" id="swps-comp-tile-count"><?php echo (int) $summary['count']; ?></div>
            <div class="swps-summary-tile-foot">
                <?php
                printf(
                    /* translators: %d: max */
                    esc_html__( 'of %d max', 'stratawp-seo' ),
                    (int) SWPS_Competitors::MAX_COMPETITORS
                );
                ?>
            </div>
        </div>
        <div class="swps-summary-tile">
            <div class="swps-summary-tile-h"><?php esc_html_e( 'New posts', 'stratawp-seo' ); ?></div>
            <div class="swps-summary-tile-num is-ok" id="swps-comp-tile-newposts"><?php echo (int) $summary['new_posts_week']; ?></div>
            <div class="swps-summary-tile-foot"><?php esc_html_e( 'since previous scan', 'stratawp-seo' ); ?></div>
        </div>
        <div class="swps-summary-tile">
            <div class="swps-summary-tile-h"><?php esc_html_e( 'Changes detected', 'stratawp-seo' ); ?></div>
            <div class="swps-summary-tile-num is-warn" id="swps-comp-tile-changes"><?php echo (int) $summary['changes']; ?></div>
            <div class="swps-summary-tile-foot"><?php esc_html_e( 'schema, title, H1', 'stratawp-seo' ); ?></div>
        </div>
        <div class="swps-summary-tile">
            <div class="swps-summary-tile-h"><?php esc_html_e( 'Last scan', 'stratawp-seo' ); ?></div>
            <div class="swps-summary-tile-num" id="swps-comp-tile-lastscan" style="font-size:18px;font-weight:600">
                <?php echo $summary['latest_scan'] !== '' ? esc_html( human_time_diff( strtotime( $summary['latest_scan'] ), current_time( 'timestamp' ) ) . ' ago' ) : esc_html__( 'never', 'stratawp-seo' ); ?>
            </div>
            <div class="swps-summary-tile-foot"><?php esc_html_e( 'auto-runs daily', 'stratawp-seo' ); ?></div>
        </div>
    </div>

    <!-- Add / edit form (toggle) -->
    <div class="swps-row-card swps-comp-form" id="swps-comp-form" style="display:none;">
        <div class="swps-comp-form-row">
            <input type="hidden" id="swps-comp-form-id" value="" />
            <div class="swps-comp-form-field">
                <label for="swps-comp-form-url"><strong><?php esc_html_e( 'Competitor URL', 'stratawp-seo' ); ?></strong></label>
                <input type="url" id="swps-comp-form-url" placeholder="https://competitor.com" />
            </div>
            <div class="swps-comp-form-field">
                <label for="swps-comp-form-label"><strong><?php esc_html_e( 'Label', 'stratawp-seo' ); ?></strong></label>
                <input type="text" id="swps-comp-form-label" placeholder="<?php esc_attr_e( 'Optional — defaults to domain', 'stratawp-seo' ); ?>" />
            </div>
            <div class="swps-comp-form-actions">
                <button type="button" class="swps-btn swps-btn-secondary" id="swps-comp-form-cancel"><?php esc_html_e( 'Cancel', 'stratawp-seo' ); ?></button>
                <button type="button" class="swps-btn swps-btn-grad"     id="swps-comp-form-save"><?php esc_html_e( 'Save', 'stratawp-seo' ); ?></button>
            </div>
        </div>
        <div class="swps-comp-progress" id="swps-comp-progress" style="display:none;">
            <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
            <span id="swps-comp-progress-text"><?php esc_html_e( 'Working...', 'stratawp-seo' ); ?></span>
        </div>
    </div>

    <div class="swps-section-h">
        <h3><?php esc_html_e( 'Tracked competitors', 'stratawp-seo' ); ?></h3>
    </div>

    <div class="swps-comp-list" id="swps-comp-list">
        <?php if ( empty( $list ) ) : ?>
            <div class="swps-row-card swps-comp-empty">
                <p><?php esc_html_e( 'No competitors tracked yet. Click "Add competitor" to start.', 'stratawp-seo' ); ?></p>
            </div>
        <?php else : ?>
            <?php foreach ( $list as $c ) : ?>
                <?php swps_render_competitor_row( $c, $competitors->latest_diff( $c ) ); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
