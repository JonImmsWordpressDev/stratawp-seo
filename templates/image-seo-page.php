<?php
/**
 * Image SEO settings page (v4.2).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$image_seo = stratawp_seo()->image_seo;
$opts      = $image_seo->get();
$missing   = $image_seo->get_missing_alt_count();

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$total = (int) $wpdb->get_var(
	"
    SELECT COUNT(*) FROM {$wpdb->posts}
    WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
"
);
$ok    = max( 0, $total - $missing );
$pct   = $total > 0 ? round( ( $ok / $total ) * 100 ) : 100;
?>
<div class="wrap">
	<?php
	$title    = __( 'Image SEO', 'stratawp-seo' );
	$subtitle = __( 'Auto-fill missing alt text, sanitize uploaded filenames, and keep images lazy-loaded. Helps both search ranking and accessibility.', 'stratawp-seo' );
	$actions  = array();
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<!-- Stats tiles -->
	<div class="swps-image-seo-stats">
		<div class="swps-tile">
			<div class="swps-tile-h"><?php esc_html_e( 'Images in library', 'stratawp-seo' ); ?></div>
			<div class="swps-tile-stat"><?php echo number_format( $total ); ?></div>
		</div>
		<div class="swps-tile">
			<div class="swps-tile-h"><?php esc_html_e( 'Missing alt text', 'stratawp-seo' ); ?></div>
			<div class="swps-tile-stat" id="swps-img-missing"><?php echo number_format( $missing ); ?></div>
			<div class="swps-tile-trend" style="color:var(--swps-text-faint)">
				<?php
				printf(
					/* translators: %d: percent with alt */
					esc_html__( '%d%% have alt text', 'stratawp-seo' ),
					(int) $pct
				);
				?>
			</div>
		</div>
		<div class="swps-tile">
			<div class="swps-tile-h"><?php esc_html_e( 'Auto-alt mode', 'stratawp-seo' ); ?></div>
			<div class="swps-tile-stat" style="font-size:18px">
				<?php echo 'ai' === ( $opts['alt_mode'] ?? 'heuristic' ) ? esc_html__( 'AI', 'stratawp-seo' ) : esc_html__( 'Heuristic', 'stratawp-seo' ); ?>
			</div>
			<div class="swps-tile-trend" style="color:var(--swps-text-faint)">
				<?php echo empty( $opts['auto_alt'] ) ? esc_html__( 'Disabled', 'stratawp-seo' ) : esc_html__( 'Active on upload', 'stratawp-seo' ); ?>
			</div>
		</div>
	</div>

	<!-- Bulk fix -->
	<div class="swps-card" style="margin-bottom:16px">
		<h2 style="margin-top:0"><?php esc_html_e( 'Bulk fix missing alt text', 'stratawp-seo' ); ?></h2>
		<p class="description" style="margin-top:0">
			<?php esc_html_e( 'Process the existing library and write alt text for every image that does not have any. Uses the auto-alt mode you have selected below. Runs in batches of 20 — safe to leave open while it works.', 'stratawp-seo' ); ?>
		</p>
		<div class="swps-image-seo-bulk-actions" style="display:flex;align-items:center;gap:12px;margin-top:12px">
			<button type="button" class="swps-btn swps-btn-grad" id="swps-img-bulk-fix"
					<?php disabled( 0 === $missing ); ?>>
				<?php esc_html_e( 'Fix missing alt text', 'stratawp-seo' ); ?>
			</button>
			<span class="swps-image-seo-status" id="swps-img-status" style="color:var(--swps-text-muted);font-size:13px">
				<?php
				if ( 0 === $missing ) {
					esc_html_e( 'All images have alt text. Nothing to do here.', 'stratawp-seo' );
				} else {
					printf(
						/* translators: %d: count missing */
						esc_html__( '%d images need alt text.', 'stratawp-seo' ),
						(int) $missing
					);
				}
				?>
			</span>
		</div>
	</div>

	<!-- Settings form -->
	<form method="post" action="options.php" class="swps-image-seo-form">
		<?php settings_fields( 'swps_image_seo_group' ); ?>

		<div class="swps-card" style="margin-bottom:16px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Auto-alt on upload', 'stratawp-seo' ); ?></h2>

			<label style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
				<input type="checkbox"
						name="<?php echo esc_attr( SWPS_Image_SEO::OPTION_KEY ); ?>[auto_alt]"
						value="1" <?php checked( ! empty( $opts['auto_alt'] ) ); ?> />
				<span style="font-weight:600"><?php esc_html_e( 'Generate alt text automatically when an image is uploaded', 'stratawp-seo' ); ?></span>
			</label>

			<fieldset style="margin-left:28px;margin-top:8px">
				<legend class="screen-reader-text"><?php esc_html_e( 'Alt text generation mode', 'stratawp-seo' ); ?></legend>
				<label style="display:block;margin-bottom:6px">
					<input type="radio"
							name="<?php echo esc_attr( SWPS_Image_SEO::OPTION_KEY ); ?>[alt_mode]"
							value="heuristic" <?php checked( ( $opts['alt_mode'] ?? 'heuristic' ), 'heuristic' ); ?> />
					<strong><?php esc_html_e( 'Heuristic (free, instant)', 'stratawp-seo' ); ?></strong>
					<span style="color:var(--swps-text-muted);font-size:12px">
						— <?php esc_html_e( 'Derives alt from filename + parent post title. No API calls.', 'stratawp-seo' ); ?>
					</span>
				</label>
				<label style="display:block">
					<input type="radio"
							name="<?php echo esc_attr( SWPS_Image_SEO::OPTION_KEY ); ?>[alt_mode]"
							value="ai" <?php checked( ( $opts['alt_mode'] ?? 'heuristic' ), 'ai' ); ?> />
					<strong><?php esc_html_e( 'AI (uses your configured provider)', 'stratawp-seo' ); ?></strong>
					<span style="color:var(--swps-text-muted);font-size:12px">
						— <?php esc_html_e( 'Sends filename + post context to your AI provider. Falls back to heuristic on error. Each call costs a fraction of a cent.', 'stratawp-seo' ); ?>
					</span>
				</label>
			</fieldset>
		</div>

		<div class="swps-card" style="margin-bottom:16px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Filename sanitization', 'stratawp-seo' ); ?></h2>
			<label style="display:flex;align-items:center;gap:10px">
				<input type="checkbox"
						name="<?php echo esc_attr( SWPS_Image_SEO::OPTION_KEY ); ?>[filename_sanitize]"
						value="1" <?php checked( ! empty( $opts['filename_sanitize'] ) ); ?> />
				<span style="font-weight:600"><?php esc_html_e( 'Clean up filenames on upload', 'stratawp-seo' ); ?></span>
			</label>
			<p class="description" style="margin:6px 0 0 28px">
				<?php esc_html_e( 'Strips device prefixes (IMG_, DSC_), lowercases, and converts spaces/underscores to dashes. Example: "IMG_4523 Vacation.JPG" → "vacation.jpg".', 'stratawp-seo' ); ?>
			</p>
		</div>

		<div class="swps-card" style="margin-bottom:16px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Lazy loading', 'stratawp-seo' ); ?></h2>
			<label style="display:flex;align-items:center;gap:10px">
				<input type="checkbox"
						name="<?php echo esc_attr( SWPS_Image_SEO::OPTION_KEY ); ?>[lazy_load]"
						value="1" <?php checked( ! empty( $opts['lazy_load'] ) ); ?> />
				<span style="font-weight:600"><?php esc_html_e( 'Force loading="lazy" + decoding="async" on all post-content images', 'stratawp-seo' ); ?></span>
			</label>
			<p class="description" style="margin:6px 0 0 28px">
				<?php esc_html_e( 'WordPress already lazy-loads images that have width/height attributes. This adds the attributes to any image still missing them, which improves Core Web Vitals.', 'stratawp-seo' ); ?>
			</p>
		</div>

		<?php submit_button( __( 'Save Image SEO settings', 'stratawp-seo' ) ); ?>
	</form>
</div>
