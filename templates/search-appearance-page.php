<?php
/**
 * Search Appearance admin page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap swps-search-appearance">
    <h1><?php esc_html_e( 'Search Appearance', 'stratawp-seo' ); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields( 'swps_search_appearance' ); ?>

        <h2><?php esc_html_e( 'Title Separator', 'stratawp-seo' ); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Separator', 'stratawp-seo' ); ?></th>
                <td>
                    <?php
                    $current_sep = get_option( 'swps_title_separator', '-' );
                    $separators  = [ '|', '-', '–', '—', '·', '•', '»' ];
                    foreach ( $separators as $sep ) :
                    ?>
                        <label style="margin-right: 15px; cursor: pointer;">
                            <input type="radio" name="swps_title_separator" value="<?php echo esc_attr( $sep ); ?>"
                                <?php checked( $current_sep, $sep ); ?>>
                            <?php echo esc_html( $sep ); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <?php
        // Post types section.
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        foreach ( $post_types as $pt ) :
            if ( 'attachment' === $pt->name ) {
                continue;
            }
        ?>
            <h2><?php echo esc_html( $pt->labels->name ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Title Template', 'stratawp-seo' ); ?></th>
                    <td>
                        <input type="text" class="large-text swps-title-template"
                            name="swps_title_template_<?php echo esc_attr( $pt->name ); ?>"
                            value="<?php echo esc_attr( get_option( "swps_title_template_{$pt->name}", '%%title%% %%sep%% %%sitename%%' ) ); ?>">
                        <p class="description swps-template-preview"></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Description Template', 'stratawp-seo' ); ?></th>
                    <td>
                        <textarea class="large-text" rows="2"
                            name="swps_desc_template_<?php echo esc_attr( $pt->name ); ?>"
                        ><?php echo esc_textarea( get_option( "swps_desc_template_{$pt->name}", '%%excerpt%%' ) ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Show in Search Results', 'stratawp-seo' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="swps_noindex_<?php echo esc_attr( $pt->name ); ?>" value="1"
                                <?php checked( get_option( "swps_noindex_{$pt->name}", 0 ) ); ?>>
                            <?php esc_html_e( 'Noindex this post type (hide from search engines)', 'stratawp-seo' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
        <?php endforeach; ?>

        <?php
        // Taxonomies section.
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        foreach ( $taxonomies as $tax ) :
            if ( 'post_format' === $tax->name ) {
                continue;
            }
        ?>
            <h2><?php echo esc_html( $tax->labels->name ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Title Template', 'stratawp-seo' ); ?></th>
                    <td>
                        <input type="text" class="large-text swps-title-template"
                            name="swps_title_template_<?php echo esc_attr( $tax->name ); ?>"
                            value="<?php echo esc_attr( get_option( "swps_title_template_{$tax->name}", '%%title%% %%sep%% %%sitename%%' ) ); ?>">
                        <p class="description swps-template-preview"></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Show in Search Results', 'stratawp-seo' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="swps_noindex_<?php echo esc_attr( $tax->name ); ?>" value="1"
                                <?php checked( get_option( "swps_noindex_{$tax->name}", 0 ) ); ?>>
                            <?php esc_html_e( 'Noindex this taxonomy', 'stratawp-seo' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
        <?php endforeach; ?>

        <h2><?php esc_html_e( 'Special Pages', 'stratawp-seo' ); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Search Page Title', 'stratawp-seo' ); ?></th>
                <td>
                    <input type="text" class="large-text swps-title-template"
                        name="swps_title_template_search"
                        value="<?php echo esc_attr( get_option( 'swps_title_template_search', 'Search: %%searchphrase%% %%sep%% %%sitename%%' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( '404 Page Title', 'stratawp-seo' ); ?></th>
                <td>
                    <input type="text" class="large-text swps-title-template"
                        name="swps_title_template_404"
                        value="<?php echo esc_attr( get_option( 'swps_title_template_404', 'Page Not Found %%sep%% %%sitename%%' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Author Archive Title', 'stratawp-seo' ); ?></th>
                <td>
                    <input type="text" class="large-text swps-title-template"
                        name="swps_title_template_author"
                        value="<?php echo esc_attr( get_option( 'swps_title_template_author', '%%author%% %%sep%% %%sitename%%' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Date Archive Title', 'stratawp-seo' ); ?></th>
                <td>
                    <input type="text" class="large-text swps-title-template"
                        name="swps_title_template_date"
                        value="<?php echo esc_attr( get_option( 'swps_title_template_date', '%%title%% %%sep%% %%sitename%%' ) ); ?>">
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <div class="swps-template-help">
        <h3><?php esc_html_e( 'Available Variables', 'stratawp-seo' ); ?></h3>
        <ul>
            <li><code>%%title%%</code> — <?php esc_html_e( 'Post/page/term title', 'stratawp-seo' ); ?></li>
            <li><code>%%sitename%%</code> — <?php esc_html_e( 'Site name', 'stratawp-seo' ); ?></li>
            <li><code>%%sep%%</code> — <?php esc_html_e( 'Separator character', 'stratawp-seo' ); ?></li>
            <li><code>%%excerpt%%</code> — <?php esc_html_e( 'Post excerpt', 'stratawp-seo' ); ?></li>
            <li><code>%%category%%</code> — <?php esc_html_e( 'Primary category', 'stratawp-seo' ); ?></li>
            <li><code>%%author%%</code> — <?php esc_html_e( 'Author name', 'stratawp-seo' ); ?></li>
            <li><code>%%date%%</code> — <?php esc_html_e( 'Published date', 'stratawp-seo' ); ?></li>
            <li><code>%%searchphrase%%</code> — <?php esc_html_e( 'Search query', 'stratawp-seo' ); ?></li>
            <li><code>%%page%%</code> — <?php esc_html_e( 'Page number', 'stratawp-seo' ); ?></li>
        </ul>
    </div>
</div>
