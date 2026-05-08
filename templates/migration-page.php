<?php
/**
 * Migration tool admin page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var SWPS_Migration $migration */
$migration = stratawp_seo()->migration;

$sources = $migration->detect_sources();
$status  = isset( $_GET['migration'] ) ? sanitize_key( wp_unslash( $_GET['migration'] ) ) : '';
$preview = get_transient( 'swps_migration_preview_' . get_current_user_id() );
$report  = $migration->get_last_report();
?>
<div class="wrap swps-migration-page">
    <h1><?php esc_html_e( 'Migrate from Yoast / Rank Math', 'stratawp-seo' ); ?></h1>
    <p><?php esc_html_e( 'Import per-post SEO meta (titles, descriptions, focus keyword, canonicals, breadcrumb titles, social overrides) from Yoast SEO or Rank Math into StrataWP SEO.', 'stratawp-seo' ); ?></p>

    <?php if ( 'done' === $status && ! empty( $report ) ) : ?>
        <div class="notice notice-success">
            <p>
                <strong><?php esc_html_e( 'Migration complete.', 'stratawp-seo' ); ?></strong>
                <?php
                printf(
                    /* translators: 1: posts 2: fields written 3: fields skipped 4: fields overwritten 5: options written 6: redirects written */
                    esc_html__( 'Processed %1$d posts, wrote %2$d post fields (skipped %3$d, overwrote %4$d), %5$d settings, %6$d redirects.', 'stratawp-seo' ),
                    (int) ( $report['posts_processed'] ?? 0 ),
                    (int) ( $report['fields_written'] ?? 0 ),
                    (int) ( $report['fields_skipped'] ?? 0 ),
                    (int) ( $report['fields_overwritten'] ?? 0 ),
                    (int) ( $report['options_written'] ?? 0 ),
                    (int) ( $report['redirects_written'] ?? 0 )
                );
                ?>
            </p>
            <?php if ( ! empty( $report['redirects_errors'] ) ) : ?>
                <details><summary><?php esc_html_e( 'Redirect issues', 'stratawp-seo' ); ?> (<?php echo (int) count( $report['redirects_errors'] ); ?>)</summary>
                    <ul style="margin-left:16px;list-style:disc;">
                    <?php foreach ( $report['redirects_errors'] as $err ) : ?>
                        <li><code><?php echo esc_html( $err ); ?></code></li>
                    <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php elseif ( 'undone' === $status ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'Migration reverted.', 'stratawp-seo' ); ?></p></div>
    <?php elseif ( 'preview' === $status && is_array( $preview ) ) : ?>
        <div class="notice notice-info">
            <p>
                <strong><?php esc_html_e( 'Preview', 'stratawp-seo' ); ?>:</strong>
                <?php
                printf(
                    /* translators: 1: posts 2: post fields 3: settings 4: redirects */
                    esc_html__( 'Would scan %1$d posts, write %2$d post fields, %3$d settings, %4$d redirects.', 'stratawp-seo' ),
                    (int) ( $preview['stats']['posts_scanned'] ?? 0 ),
                    (int) ( $preview['stats']['fields_to_write'] ?? 0 ),
                    (int) ( $preview['stats']['options_to_write'] ?? 0 ),
                    (int) ( $preview['stats']['redirects_to_write'] ?? 0 )
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ( empty( $sources ) ) : ?>
        <div class="postbox">
            <div class="inside">
                <p><?php esc_html_e( 'No Yoast SEO or Rank Math data was detected on this site. There is nothing to migrate.', 'stratawp-seo' ); ?></p>
            </div>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Detected sources', 'stratawp-seo' ); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Plugin', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Posts with SEO data', 'stratawp-seo' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $sources as $slug => $info ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $info['label'] ); ?></strong></td>
                    <td><?php echo esc_html( number_format_i18n( $info['post_count'] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top:24px;"><?php esc_html_e( 'Run migration', 'stratawp-seo' ); ?></h2>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="swps_run_migration">
        <?php wp_nonce_field( 'swps_run_migration' ); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="swps-migration-source"><?php esc_html_e( 'Source', 'stratawp-seo' ); ?></label></th>
                <td>
                    <select id="swps-migration-source" name="source">
                        <?php foreach ( $sources as $slug => $info ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $info['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'What to migrate', 'stratawp-seo' ); ?></th>
                <td>
                    <label><input type="checkbox" name="phases[]" value="post_meta" checked> <?php esc_html_e( 'Per-post SEO meta (titles, descriptions, focus keyword, canonical, social)', 'stratawp-seo' ); ?></label><br>
                    <label><input type="checkbox" name="phases[]" value="settings" checked> <?php esc_html_e( 'Global settings (title separator, title templates, archive noindex flags)', 'stratawp-seo' ); ?></label><br>
                    <label><input type="checkbox" name="phases[]" value="redirects" checked> <?php esc_html_e( 'Redirects (Yoast Premium / Rank Math Pro)', 'stratawp-seo' ); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'When StrataWP value already exists', 'stratawp-seo' ); ?></th>
                <td>
                    <label><input type="radio" name="conflict_policy" value="skip" checked> <?php esc_html_e( 'Skip — keep existing StrataWP value', 'stratawp-seo' ); ?></label><br>
                    <label><input type="radio" name="conflict_policy" value="overwrite"> <?php esc_html_e( 'Overwrite with imported value', 'stratawp-seo' ); ?></label>
                    <p class="description"><?php esc_html_e( 'A backup of overwritten values is kept so the migration can be undone. (Redirects are always inserted as new rows; undo deletes them.)', 'stratawp-seo' ); ?></p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" name="mode" value="preview" class="button"><?php esc_html_e( 'Preview', 'stratawp-seo' ); ?></button>
            <button type="submit" name="mode" value="run" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Run the migration now? A backup will be saved so this can be undone.', 'stratawp-seo' ) ); ?>');"><?php esc_html_e( 'Run migration', 'stratawp-seo' ); ?></button>
        </p>
    </form>

    <?php if ( $migration->has_backup() ) : ?>
        <h2 style="margin-top:24px;"><?php esc_html_e( 'Undo last migration', 'stratawp-seo' ); ?></h2>
        <p><?php esc_html_e( 'Restores the StrataWP meta values that were overwritten by the most recent migration.', 'stratawp-seo' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="swps_undo_migration">
            <?php wp_nonce_field( 'swps_undo_migration' ); ?>
            <button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Revert the last migration?', 'stratawp-seo' ) ); ?>');"><?php esc_html_e( 'Undo last migration', 'stratawp-seo' ); ?></button>
        </form>
    <?php endif; ?>

    <h2 style="margin-top:24px;"><?php esc_html_e( 'What gets migrated', 'stratawp-seo' ); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Field', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Yoast key', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Rank Math key', 'stratawp-seo' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr><td><?php esc_html_e( 'Meta title', 'stratawp-seo' ); ?></td><td><code>_yoast_wpseo_title</code></td><td><code>rank_math_title</code></td></tr>
            <tr><td><?php esc_html_e( 'Meta description', 'stratawp-seo' ); ?></td><td><code>_yoast_wpseo_metadesc</code></td><td><code>rank_math_description</code></td></tr>
            <tr><td><?php esc_html_e( 'Focus keyword', 'stratawp-seo' ); ?></td><td><code>_yoast_wpseo_focuskw</code></td><td><code>rank_math_focus_keyword</code></td></tr>
            <tr><td><?php esc_html_e( 'Canonical URL', 'stratawp-seo' ); ?></td><td><code>_yoast_wpseo_canonical</code></td><td><code>rank_math_canonical_url</code></td></tr>
            <tr><td><?php esc_html_e( 'Breadcrumb title', 'stratawp-seo' ); ?></td><td><code>_yoast_wpseo_bctitle</code></td><td><code>rank_math_breadcrumb_title</code></td></tr>
            <tr><td><?php esc_html_e( 'Social title', 'stratawp-seo' ); ?></td><td><code>_yoast_wpseo_opengraph-title</code></td><td><code>rank_math_facebook_title</code></td></tr>
            <tr><td><?php esc_html_e( 'Social description', 'stratawp-seo' ); ?></td><td><code>_yoast_wpseo_opengraph-description</code></td><td><code>rank_math_facebook_description</code></td></tr>
            <tr><td><?php esc_html_e( 'Social image', 'stratawp-seo' ); ?></td><td><code>_yoast_wpseo_opengraph-image</code></td><td><code>rank_math_facebook_image</code></td></tr>
        </tbody>
    </table>
    <p class="description" style="margin-top:8px;">
        <?php esc_html_e( 'Global settings imported: title separator, title templates per post type / taxonomy / archive (with template-variable rewriting for Rank Math), and per-post-type noindex flags. Redirects imported from Yoast Premium (wpseo-premium-redirects-base) and Rank Math Pro (wp_rank_math_redirections table); 301/302/307/410 are supported, regex sources are kept as regex.', 'stratawp-seo' ); ?>
    </p>
</div>
