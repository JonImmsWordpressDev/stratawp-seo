<?php
/**
 * Calendar page (v4.0).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap swps-calendar-wrap">

	<?php
	$title    = __( 'Content Calendar', 'stratawp-seo' );
	$subtitle = __( 'Plan your content schedule — click any date to add a topic.', 'stratawp-seo' );
	$actions  = array();
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<div id="swps-calendar"></div>

	<div class="swps-calendar-legend">
		<div class="swps-legend-item">
			<span class="swps-legend-dot swps-legend-queued"></span>
			<?php esc_html_e( 'Queued', 'stratawp-seo' ); ?>
		</div>
		<div class="swps-legend-item">
			<span class="swps-legend-dot swps-legend-generating"></span>
			<?php esc_html_e( 'Generating', 'stratawp-seo' ); ?>
		</div>
		<div class="swps-legend-item">
			<span class="swps-legend-dot swps-legend-published"></span>
			<?php esc_html_e( 'AI Published', 'stratawp-seo' ); ?>
		</div>
		<div class="swps-legend-item">
			<span class="swps-legend-dot swps-legend-retrying"></span>
			<?php esc_html_e( 'Retrying', 'stratawp-seo' ); ?>
		</div>
		<div class="swps-legend-item">
			<span class="swps-legend-dot swps-legend-failed"></span>
			<?php esc_html_e( 'Failed', 'stratawp-seo' ); ?>
		</div>
		<div class="swps-legend-item">
			<span class="swps-legend-dot swps-legend-existing"></span>
			<?php esc_html_e( 'Existing Post', 'stratawp-seo' ); ?>
		</div>
	</div>
</div>

<!-- Create Topic Modal -->
<div id="swps-create-modal" class="swps-modal">
	<div class="swps-modal-overlay"></div>
	<div class="swps-modal-content">
		<button class="swps-modal-close" type="button">&times;</button>
		<h2><?php esc_html_e( 'Add Topic to Queue', 'stratawp-seo' ); ?></h2>

		<form id="swps-topic-create-form">
			<div class="swps-form-group">
				<label for="swps-topic-title"><?php esc_html_e( 'Topic / Headline', 'stratawp-seo' ); ?></label>
				<input type="text" id="swps-topic-title" placeholder="<?php esc_attr_e( 'e.g., 10 Best WordPress Security Plugins', 'stratawp-seo' ); ?>" required />
			</div>

			<div class="swps-form-group">
				<label for="swps-topic-date"><?php esc_html_e( 'Scheduled Date', 'stratawp-seo' ); ?></label>
				<input type="date" id="swps-topic-date" />
			</div>

			<div class="swps-form-group">
				<label for="swps-topic-template"><?php esc_html_e( 'Content Template', 'stratawp-seo' ); ?></label>
				<select id="swps-topic-template">
					<?php foreach ( SWPS_Templates::get_options() as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="swps-form-group">
				<label for="swps-topic-notes"><?php esc_html_e( 'Notes (optional)', 'stratawp-seo' ); ?></label>
				<textarea id="swps-topic-notes" rows="3" placeholder="<?php esc_attr_e( 'Any specific instructions for this topic...', 'stratawp-seo' ); ?>"></textarea>
			</div>

			<button type="submit" class="swps-btn swps-btn-grad"><?php esc_html_e( 'Add to Queue', 'stratawp-seo' ); ?></button>
		</form>
	</div>
</div>

<!-- Topic Detail Modal -->
<div id="swps-detail-modal" class="swps-modal">
	<div class="swps-modal-overlay"></div>
	<div class="swps-modal-content">
		<button class="swps-modal-close" type="button">&times;</button>
		<h2 id="swps-detail-title"></h2>

		<table class="swps-detail-table">
			<tr>
				<td><?php esc_html_e( 'Date:', 'stratawp-seo' ); ?></td>
				<td id="swps-detail-date"></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Status:', 'stratawp-seo' ); ?></td>
				<td id="swps-detail-status"></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Template:', 'stratawp-seo' ); ?></td>
				<td id="swps-detail-template"></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Notes:', 'stratawp-seo' ); ?></td>
				<td id="swps-detail-notes"></td>
			</tr>
		</table>

		<div class="swps-modal-actions">
			<button type="button" id="swps-topic-delete" class="swps-btn swps-btn-secondary" data-topic-id="" style="color:var(--swps-crit);border-color:var(--swps-crit)">
				<?php esc_html_e( 'Delete Topic', 'stratawp-seo' ); ?>
			</button>
		</div>
	</div>
</div>
