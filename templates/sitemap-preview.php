<?php
/**
 * Sitemaps admin page: Preview tab.
 *
 * Browsers no longer style sitemaps with XSLT, so this shows a sub-sitemap
 * as a table. Crawlers still get the plain XML at the public URLs.
 *
 * Expects $swps_preview_file and $swps_preview_page from sitemaps-page.php.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$swps_preview  = StrataWP_SEO::instance()->sitemap_admin->get_preview( (string) $swps_preview_file, (int) $swps_preview_page );
$swps_base_url = admin_url( 'admin.php?page=swps-sitemaps' );
?>
<div class="postbox">
	<div class="inside">
		<h3><?php esc_html_e( 'Preview a sitemap', 'stratawp-seo' ); ?></h3>
		<?php if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Another SEO plugin is serving your sitemaps, so this preview shows what StrataWP SEO would serve, not what crawlers get now.', 'stratawp-seo' ); ?></p></div>
		<?php endif; ?>
		<?php if ( empty( $swps_preview['sitemaps'] ) ) : ?>
			<p><?php esc_html_e( 'The sitemap index lists no sitemaps.', 'stratawp-seo' ); ?></p>
		<?php else : ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="swps-sitemaps">
				<label for="swps_preview"><?php esc_html_e( 'Sitemap', 'stratawp-seo' ); ?></label>
				<select id="swps_preview" name="swps_preview">
					<option value=""><?php esc_html_e( 'Choose a sitemap', 'stratawp-seo' ); ?></option>
					<?php foreach ( $swps_preview['sitemaps'] as $swps_entry ) : ?>
						<option value="<?php echo esc_attr( $swps_entry['file'] ); ?>" <?php selected( $swps_preview_file, $swps_entry['file'] ); ?>><?php echo esc_html( $swps_entry['file'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Preview', 'stratawp-seo' ), 'secondary', '', false ); ?>
			</form>
		<?php endif; ?>
	</div>
</div>

<?php if ( $swps_preview['selected'] ) : ?>
	<?php
	$swps_page_url = function ( int $paged ) use ( $swps_base_url, $swps_preview ): string {
		return add_query_arg(
			array(
				'swps_preview'      => rawurlencode( $swps_preview['selected']['file'] ),
				'swps_preview_page' => $paged,
			),
			$swps_base_url
		);
	};
	?>
	<p>
		<?php
		printf(
			/* translators: 1: number of URLs, 2: sitemap file name. */
			esc_html( _n( '%1$s URL in %2$s.', '%1$s URLs in %2$s.', (int) $swps_preview['total'], 'stratawp-seo' ) ),
			esc_html( number_format_i18n( (int) $swps_preview['total'] ) ),
			'<code>' . esc_html( $swps_preview['selected']['file'] ) . '</code>'
		);
		?>
		<a href="<?php echo esc_url( $swps_preview['selected']['loc'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View raw XML', 'stratawp-seo' ); ?></a>
	</p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'URL', 'stratawp-seo' ); ?></th>
				<th><?php esc_html_e( 'Last Modified', 'stratawp-seo' ); ?></th>
				<th><?php esc_html_e( 'Images', 'stratawp-seo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $swps_preview['rows'] ) ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'This sitemap has no URLs.', 'stratawp-seo' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $swps_preview['rows'] as $swps_row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( $swps_row['loc'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $swps_row['loc'] ); ?></a></td>
					<td><?php echo esc_html( '' !== $swps_row['lastmod'] ? $swps_row['lastmod'] : '-' ); ?></td>
					<td><?php echo esc_html( (string) $swps_row['images'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $swps_preview['pages'] > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages. */
					esc_html__( 'Page %1$s of %2$s', 'stratawp-seo' ),
					esc_html( number_format_i18n( (int) $swps_preview['paged'] ) ),
					esc_html( number_format_i18n( (int) $swps_preview['pages'] ) )
				);
				?>
			</span>
			<?php if ( $swps_preview['paged'] > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( $swps_page_url( (int) $swps_preview['paged'] - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'stratawp-seo' ); ?></a>
			<?php endif; ?>
			<?php if ( $swps_preview['paged'] < $swps_preview['pages'] ) : ?>
				<a class="button" href="<?php echo esc_url( $swps_page_url( (int) $swps_preview['paged'] + 1 ) ); ?>"><?php esc_html_e( 'Next', 'stratawp-seo' ); ?></a>
			<?php endif; ?>
		</div></div>
	<?php endif; ?>
<?php endif; ?>
