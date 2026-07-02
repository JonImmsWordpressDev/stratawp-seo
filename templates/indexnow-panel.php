<?php
/**
 * IndexNow panel, rendered inside the Sitemaps admin page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$swps_in_key      = (string) get_option( SWPS_IndexNow::OPT_KEY, '' );
$swps_in_enabled  = (bool) get_option( SWPS_IndexNow::OPT_ENABLED, 0 );
$swps_in_auto     = (bool) get_option( SWPS_IndexNow::OPT_AUTO, 1 );
$swps_in_selected = (array) get_option( SWPS_IndexNow::OPT_POST_TYPES, array( 'post', 'page' ) );
$swps_in_skip_env = SWPS_IndexNow::should_skip_environment();
$swps_in_key_url  = $swps_in_key ? home_url( '/' . $swps_in_key . '.txt' ) : '';
?>
<div class="postbox">
	<div class="inside">
		<h3><?php esc_html_e( 'IndexNow', 'stratawp-seo' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Instantly notify Bing, Yandex, Seznam, and Naver when your content changes. (Google does not participate in IndexNow.)', 'stratawp-seo' ); ?>
		</p>

		<?php if ( $swps_in_skip_env ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'IndexNow is paused: this does not look like a production site, so URLs will not be submitted.', 'stratawp-seo' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( SWPS_IndexNow::SETTINGS_GROUP ); ?>
			<table class="form-table">
				<tr>
					<th><label for="swps_indexnow_enabled"><?php esc_html_e( 'Enable IndexNow', 'stratawp-seo' ); ?></label></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( SWPS_IndexNow::OPT_ENABLED ); ?>" value="0">
						<input type="checkbox" id="swps_indexnow_enabled" name="<?php echo esc_attr( SWPS_IndexNow::OPT_ENABLED ); ?>" value="1" <?php checked( $swps_in_enabled ); ?>>
					</td>
				</tr>
				<tr>
					<th><label for="swps_indexnow_auto"><?php esc_html_e( 'Auto-submit on publish/update', 'stratawp-seo' ); ?></label></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( SWPS_IndexNow::OPT_AUTO ); ?>" value="0">
						<input type="checkbox" id="swps_indexnow_auto" name="<?php echo esc_attr( SWPS_IndexNow::OPT_AUTO ); ?>" value="1" <?php checked( $swps_in_auto ); ?>>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Post types', 'stratawp-seo' ); ?></th>
					<td>
						<fieldset>
							<?php
							foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $swps_pt ) {
								if ( 'attachment' === $swps_pt->name ) {
									continue;
								}
								printf(
									'<label style="display:inline-block;min-width:160px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
									esc_attr( SWPS_IndexNow::OPT_POST_TYPES ),
									esc_attr( $swps_pt->name ),
									checked( in_array( $swps_pt->name, $swps_in_selected, true ), true, false ),
									esc_html( $swps_pt->label )
								);
							}
							?>
						</fieldset>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save IndexNow Settings', 'stratawp-seo' ) ); ?>
		</form>

		<hr>
		<h4><?php esc_html_e( 'API Key', 'stratawp-seo' ); ?></h4>
		<p>
			<code id="swps-indexnow-key"><?php echo esc_html( $swps_in_key ?: __( '(not generated yet)', 'stratawp-seo' ) ); ?></code>
			<button type="button" class="button" id="swps-indexnow-generate"><?php esc_html_e( 'Generate / Rotate Key', 'stratawp-seo' ); ?></button>
		</p>
		<p class="description">
			<?php esc_html_e( 'Verification file:', 'stratawp-seo' ); ?>
			<a id="swps-indexnow-key-url" href="<?php echo esc_url( $swps_in_key_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $swps_in_key_url ); ?></a>
		</p>

		<hr>
		<p>
			<button type="button" class="button button-secondary" id="swps-indexnow-resubmit"><?php esc_html_e( 'Resubmit All URLs', 'stratawp-seo' ); ?></button>
			<span id="swps-indexnow-resubmit-status"></span>
		</p>

		<h4><?php esc_html_e( 'Recent Activity', 'stratawp-seo' ); ?></h4>
		<table class="widefat striped" id="swps-indexnow-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Trigger', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'URLs', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Result', 'stratawp-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td colspan="4"><?php esc_html_e( 'Loading…', 'stratawp-seo' ); ?></td></tr>
			</tbody>
		</table>
	</div>
</div>
