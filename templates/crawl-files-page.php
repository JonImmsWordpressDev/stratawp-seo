<?php
/**
 * Crawlers & Files settings page (v4.2).
 *
 * Edits /llms.txt and /robots.txt with auto/custom toggles.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$plugin = stratawp_seo();
$cf     = $plugin->crawl_files;
$bots   = $plugin->ai_bots;

$llms_mode    = $cf->get_llms_mode();
$llms_custom  = $cf->get_llms_custom();
$robots_mode  = $cf->get_robots_mode();
$robots_custom = $cf->get_robots_custom();

$auto_llms   = $cf->render_auto_llms( $bots );
$auto_robots = $cf->render_auto_robots();

$llms_url   = home_url( '/llms.txt' );
$robots_url = home_url( '/robots.txt' );
?>
<div class="wrap">
    <?php
    $title    = __( 'Crawlers & Files', 'stratawp-seo' );
    $subtitle = __( 'Edit the dynamic /llms.txt and /robots.txt files served by your site. Use auto for the StrataWP-generated defaults, or paste your own content to override.', 'stratawp-seo' );
    $actions  = [
        [
            'label' => __( 'View /llms.txt', 'stratawp-seo' ),
            'url'   => $llms_url,
            'class' => 'swps-btn-secondary',
            'attrs' => 'target="_blank" rel="noopener"',
        ],
        [
            'label' => __( 'View /robots.txt', 'stratawp-seo' ),
            'url'   => $robots_url,
            'class' => 'swps-btn-secondary',
            'attrs' => 'target="_blank" rel="noopener"',
        ],
    ];
    require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
    ?>

    <form method="post" action="options.php" class="swps-crawl-files-form">
        <?php settings_fields( 'swps_crawl_files_group' ); ?>

        <!-- llms.txt -->
        <div class="swps-card swps-crawl-card">
            <div class="swps-crawl-card-head">
                <div>
                    <h2 style="margin:0">/llms.txt</h2>
                    <p class="description" style="margin:4px 0 0">
                        <?php esc_html_e( 'A markdown index that helps AI crawlers (ChatGPT, Claude, Perplexity) understand the structure of your site. By default StrataWP builds it from your most recent posts, top-level pages, and busiest categories.', 'stratawp-seo' ); ?>
                    </p>
                </div>
            </div>

            <fieldset style="margin-top:14px">
                <legend class="screen-reader-text"><?php esc_html_e( 'llms.txt mode', 'stratawp-seo' ); ?></legend>
                <label style="display:inline-flex;gap:8px;align-items:center;margin-right:24px">
                    <input type="radio" name="<?php echo esc_attr( SWPS_Crawl_Files::OPT_LLMS_MODE ); ?>"
                           value="<?php echo esc_attr( SWPS_Crawl_Files::MODE_AUTO ); ?>"
                           <?php checked( $llms_mode, SWPS_Crawl_Files::MODE_AUTO ); ?>
                           data-swps-mode-target="llms" />
                    <strong><?php esc_html_e( 'Auto', 'stratawp-seo' ); ?></strong>
                    <span style="color:var(--swps-text-muted);font-size:12px"><?php esc_html_e( 'Use the generated default', 'stratawp-seo' ); ?></span>
                </label>
                <label style="display:inline-flex;gap:8px;align-items:center">
                    <input type="radio" name="<?php echo esc_attr( SWPS_Crawl_Files::OPT_LLMS_MODE ); ?>"
                           value="<?php echo esc_attr( SWPS_Crawl_Files::MODE_CUSTOM ); ?>"
                           <?php checked( $llms_mode, SWPS_Crawl_Files::MODE_CUSTOM ); ?>
                           data-swps-mode-target="llms" />
                    <strong><?php esc_html_e( 'Custom', 'stratawp-seo' ); ?></strong>
                    <span style="color:var(--swps-text-muted);font-size:12px"><?php esc_html_e( 'Serve the markdown below verbatim', 'stratawp-seo' ); ?></span>
                </label>
            </fieldset>

            <div class="swps-crawl-grid" style="margin-top:14px">
                <div class="swps-crawl-col">
                    <label for="swps-llms-custom" class="swps-crawl-col-label">
                        <?php esc_html_e( 'Your llms.txt', 'stratawp-seo' ); ?>
                    </label>
                    <textarea id="swps-llms-custom" name="<?php echo esc_attr( SWPS_Crawl_Files::OPT_LLMS_CUSTOM ); ?>"
                              class="swps-crawl-textarea"
                              rows="22"
                              spellcheck="false"
                              placeholder="<?php esc_attr_e( 'Paste markdown here. Used only when Custom is selected above.', 'stratawp-seo' ); ?>"><?php echo esc_textarea( $llms_custom ); ?></textarea>
                    <button type="button" class="button-link swps-crawl-copy-default"
                            data-swps-copy-target="swps-llms-custom"
                            data-swps-copy-source="swps-llms-default">
                        <?php esc_html_e( '↑ Copy auto-generated content into the editor', 'stratawp-seo' ); ?>
                    </button>
                </div>
                <div class="swps-crawl-col">
                    <label class="swps-crawl-col-label"><?php esc_html_e( 'Auto preview', 'stratawp-seo' ); ?></label>
                    <textarea id="swps-llms-default" class="swps-crawl-textarea is-readonly" rows="22" readonly><?php echo esc_textarea( $auto_llms ); ?></textarea>
                </div>
            </div>
        </div>

        <!-- robots.txt -->
        <div class="swps-card swps-crawl-card" style="margin-top:16px">
            <div class="swps-crawl-card-head">
                <div>
                    <h2 style="margin:0">/robots.txt</h2>
                    <p class="description" style="margin:4px 0 0">
                        <?php esc_html_e( 'Tells search engines and crawlers which paths are off-limits. The auto baseline is what WordPress would normally serve, plus the AI-bot allow/disallow rules StrataWP adds. Append to extend it; replace to take full control.', 'stratawp-seo' ); ?>
                    </p>
                </div>
            </div>

            <?php if ( file_exists( ABSPATH . 'robots.txt' ) ) : ?>
                <p class="notice notice-warning" style="margin:12px 0 0;padding:8px 12px">
                    <?php esc_html_e( 'A physical robots.txt exists in your site root. WordPress (and this plugin) will be ignored — that file is served instead. Delete it to use the dynamic version.', 'stratawp-seo' ); ?>
                </p>
            <?php endif; ?>

            <fieldset style="margin-top:14px">
                <legend class="screen-reader-text"><?php esc_html_e( 'robots.txt mode', 'stratawp-seo' ); ?></legend>
                <label style="display:inline-flex;gap:8px;align-items:center;margin-right:24px">
                    <input type="radio" name="<?php echo esc_attr( SWPS_Crawl_Files::OPT_ROBOTS_MODE ); ?>"
                           value="<?php echo esc_attr( SWPS_Crawl_Files::MODE_AUTO ); ?>"
                           <?php checked( $robots_mode, SWPS_Crawl_Files::MODE_AUTO ); ?> />
                    <strong><?php esc_html_e( 'Auto', 'stratawp-seo' ); ?></strong>
                </label>
                <label style="display:inline-flex;gap:8px;align-items:center;margin-right:24px">
                    <input type="radio" name="<?php echo esc_attr( SWPS_Crawl_Files::OPT_ROBOTS_MODE ); ?>"
                           value="<?php echo esc_attr( SWPS_Crawl_Files::MODE_APPEND ); ?>"
                           <?php checked( $robots_mode, SWPS_Crawl_Files::MODE_APPEND ); ?> />
                    <strong><?php esc_html_e( 'Append', 'stratawp-seo' ); ?></strong>
                    <span style="color:var(--swps-text-muted);font-size:12px"><?php esc_html_e( 'Auto + your extra rules', 'stratawp-seo' ); ?></span>
                </label>
                <label style="display:inline-flex;gap:8px;align-items:center">
                    <input type="radio" name="<?php echo esc_attr( SWPS_Crawl_Files::OPT_ROBOTS_MODE ); ?>"
                           value="<?php echo esc_attr( SWPS_Crawl_Files::MODE_REPLACE ); ?>"
                           <?php checked( $robots_mode, SWPS_Crawl_Files::MODE_REPLACE ); ?> />
                    <strong><?php esc_html_e( 'Replace', 'stratawp-seo' ); ?></strong>
                    <span style="color:var(--swps-text-muted);font-size:12px"><?php esc_html_e( 'Serve only your content', 'stratawp-seo' ); ?></span>
                </label>
            </fieldset>

            <div class="swps-crawl-grid" style="margin-top:14px">
                <div class="swps-crawl-col">
                    <label for="swps-robots-custom" class="swps-crawl-col-label">
                        <?php esc_html_e( 'Your robots.txt', 'stratawp-seo' ); ?>
                    </label>
                    <textarea id="swps-robots-custom" name="<?php echo esc_attr( SWPS_Crawl_Files::OPT_ROBOTS_CUSTOM ); ?>"
                              class="swps-crawl-textarea"
                              rows="16"
                              spellcheck="false"
                              placeholder="<?php esc_attr_e( "User-agent: *\nDisallow: /private/\n\nSitemap: https://example.com/sitemap.xml", 'stratawp-seo' ); ?>"><?php echo esc_textarea( $robots_custom ); ?></textarea>
                    <button type="button" class="button-link swps-crawl-copy-default"
                            data-swps-copy-target="swps-robots-custom"
                            data-swps-copy-source="swps-robots-default">
                        <?php esc_html_e( '↑ Copy auto-generated content into the editor', 'stratawp-seo' ); ?>
                    </button>
                </div>
                <div class="swps-crawl-col">
                    <label class="swps-crawl-col-label"><?php esc_html_e( 'Auto preview', 'stratawp-seo' ); ?></label>
                    <textarea id="swps-robots-default" class="swps-crawl-textarea is-readonly" rows="16" readonly><?php echo esc_textarea( $auto_robots ); ?></textarea>
                </div>
            </div>
        </div>

        <?php submit_button( __( 'Save changes', 'stratawp-seo' ) ); ?>
    </form>
</div>

<script>
(function () {
    document.querySelectorAll('.swps-crawl-copy-default').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.dataset.swpsCopyTarget);
            var source = document.getElementById(btn.dataset.swpsCopySource);
            if (target && source) {
                target.value = source.value;
                target.focus();
            }
        });
    });
}());
</script>
