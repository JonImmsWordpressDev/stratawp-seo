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
					$separators  = array( '|', '-', '–', '—', '·', '•', '»' );
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
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
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
							<input type="hidden" name="swps_noindex_<?php echo esc_attr( $pt->name ); ?>" value="0">
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
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
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
							<input type="hidden" name="swps_noindex_<?php echo esc_attr( $tax->name ); ?>" value="0">
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
					<label style="display:block;margin-top:6px;">
						<input type="hidden" name="swps_noindex_author" value="0">
						<input type="checkbox" name="swps_noindex_author" value="1"
							<?php checked( get_option( 'swps_noindex_author', 0 ) ); ?>>
						<?php esc_html_e( 'Noindex author archives (recommended for single-author sites — the author archive duplicates the blog index)', 'stratawp-seo' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Date Archive Title', 'stratawp-seo' ); ?></th>
				<td>
					<input type="text" class="large-text swps-title-template"
						name="swps_title_template_date"
						value="<?php echo esc_attr( get_option( 'swps_title_template_date', '%%title%% %%sep%% %%sitename%%' ) ); ?>">
					<label style="display:block;margin-top:6px;">
						<input type="hidden" name="swps_noindex_date" value="0">
						<input type="checkbox" name="swps_noindex_date" value="1"
							<?php checked( get_option( 'swps_noindex_date', 0 ) ); ?>>
						<?php esc_html_e( 'Noindex date archives (thin, near-duplicate listings)', 'stratawp-seo' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Homepage Meta Description', 'stratawp-seo' ); ?></th>
				<td>
					<textarea class="large-text" rows="2"
						name="swps_desc_template_homepage"><?php echo esc_textarea( get_option( 'swps_desc_template_homepage', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Used on a "latest posts" homepage and the posts page. Leave blank to fall back to the site tagline. Template variables supported.', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Breadcrumbs', 'stratawp-seo' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable Breadcrumbs', 'stratawp-seo' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="swps_breadcrumbs_enabled" value="1"
							<?php checked( get_option( 'swps_breadcrumbs_enabled', 1 ) ); ?>>
						<?php esc_html_e( 'Enable HTML breadcrumb output', 'stratawp-seo' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Breadcrumb Structured Data', 'stratawp-seo' ); ?></th>
				<td>
					<label>
						<input type="hidden" name="swps_breadcrumbs_jsonld" value="0">
						<input type="checkbox" name="swps_breadcrumbs_jsonld" value="1"
							<?php checked( get_option( 'swps_breadcrumbs_jsonld', 1 ) ); ?>>
						<?php esc_html_e( 'Emit BreadcrumbList JSON-LD in the schema graph (recommended; disable only if your theme renders the HTML breadcrumb trail, which carries its own microdata)', 'stratawp-seo' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="swps-breadcrumbs-sep"><?php esc_html_e( 'Separator', 'stratawp-seo' ); ?></label></th>
				<td>
					<input type="text" id="swps-breadcrumbs-sep" name="swps_breadcrumbs_separator" class="small-text"
						value="<?php echo esc_attr( get_option( 'swps_breadcrumbs_separator', '&raquo;' ) ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="swps-breadcrumbs-home"><?php esc_html_e( 'Home Label', 'stratawp-seo' ); ?></label></th>
				<td>
					<input type="text" id="swps-breadcrumbs-home" name="swps_breadcrumbs_home_label" class="regular-text"
						value="<?php echo esc_attr( get_option( 'swps_breadcrumbs_home_label', 'Home' ) ); ?>">
				</td>
			</tr>
		</table>
		<p class="description">
			<?php esc_html_e( 'Use swps_breadcrumbs() in your theme template or the [swps_breadcrumbs] shortcode to display breadcrumbs.', 'stratawp-seo' ); ?>
		</p>

		<h2><?php esc_html_e( 'SEO Meta Box', 'stratawp-seo' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Show on post types', 'stratawp-seo' ); ?></th>
				<td>
					<?php
					$meta_types = (array) get_option( 'swps_meta_editor_post_types', array( 'post', 'page' ) );
					$all_types  = get_post_types( array( 'public' => true ), 'objects' );
					foreach ( $all_types as $pt ) :
						if ( 'attachment' === $pt->name ) {
							continue;
						}
						?>
						<label style="display: block; margin-bottom: 6px;">
							<input type="checkbox" name="swps_meta_editor_post_types[]"
								value="<?php echo esc_attr( $pt->name ); ?>"
								<?php checked( in_array( $pt->name, $meta_types, true ) ); ?>>
							<?php echo esc_html( $pt->labels->name ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Add the StrataWP SEO meta box (Meta Title, Description, Focus Words, social & sitemap controls) to these post types. Enable Products here to use it with WooCommerce.', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Post List SEO Column', 'stratawp-seo' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable SEO Column', 'stratawp-seo' ); ?></th>
				<td>
					<?php
					$enabled_types = (array) get_option( 'swps_seo_column_post_types', array( 'post', 'page' ) );
					$all_types     = get_post_types( array( 'public' => true ), 'objects' );
					foreach ( $all_types as $pt ) :
						if ( 'attachment' === $pt->name ) {
							continue;
						}
						?>
						<label style="display: block; margin-bottom: 6px;">
							<input type="checkbox" name="swps_seo_column_post_types[]"
								value="<?php echo esc_attr( $pt->name ); ?>"
								<?php checked( in_array( $pt->name, $enabled_types, true ) ); ?>>
							<?php echo esc_html( $pt->labels->name ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Show the SEO score column on these post type list screens.', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="swps-seo-content-min"><?php esc_html_e( 'Minimum Content Length', 'stratawp-seo' ); ?></label></th>
				<td>
					<input type="number" id="swps-seo-content-min" name="swps_seo_score_content_min"
						value="<?php echo esc_attr( get_option( 'swps_seo_score_content_min', 300 ) ); ?>"
						min="100" max="5000" step="50" class="small-text">
					<span><?php esc_html_e( 'words', 'stratawp-seo' ); ?></span>
					<p class="description"><?php esc_html_e( 'Minimum word count for the content length check in the SEO score.', 'stratawp-seo' ); ?></p>
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
