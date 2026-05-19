<?php
/**
 * Keywords page (v4.0).
 *
 * @var bool $gsc_connected Whether GSC is connected.
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap swps-keywords-wrap">

	<?php
	$title    = __( 'Keyword Research & Tracking', 'stratawp-seo' );
	$subtitle = __( 'Discover opportunities and track your keyword rankings. Striking-distance keywords (positions 8-20) are your fastest wins.', 'stratawp-seo' );
	$actions  = array();
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<div class="swps-settings-panel">
		<h2 style="margin-top:0"><?php esc_html_e( 'Discover Keywords', 'stratawp-seo' ); ?></h2>
		<div class="swps-suggest-form" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
			<input type="text" id="swps-seed-topic" class="regular-text" style="flex:1;min-width:240px"
					placeholder="<?php esc_attr_e( 'Enter a seed topic (e.g., home renovation tips)', 'stratawp-seo' ); ?>" />
			<button class="swps-btn swps-btn-grad" id="swps-suggest-btn">
				<?php esc_html_e( 'Get AI Suggestions', 'stratawp-seo' ); ?>
			</button>
			<span class="spinner" id="swps-suggest-spinner"></span>
		</div>
		<table class="widefat striped" id="swps-suggestions-table" style="display:none;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Keyword', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Intent', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Difficulty', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Suggested Title', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>

	<div class="swps-settings-panel">
		<h2 style="margin-top:0"><?php esc_html_e( 'Tracked Keywords', 'stratawp-seo' ); ?></h2>
		<table class="widefat striped" id="swps-tracked-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Keyword', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'CTR', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Linked Post', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td colspan="7" class="swps-loading" style="color:var(--swps-text-muted)"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<?php if ( $gsc_connected ) : ?>
	<div class="swps-settings-panel">
		<h2 style="margin-top:0"><?php esc_html_e( 'Opportunities (Striking Distance)', 'stratawp-seo' ); ?></h2>
		<p style="color:var(--swps-text-muted);font-size:12px;margin:0 0 12px"><?php esc_html_e( 'Keywords ranking position 8-20 with high impressions — optimize these for quick wins.', 'stratawp-seo' ); ?></p>
		<table class="widefat striped" id="swps-opportunities-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Keyword', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'CTR', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td colspan="5" class="swps-loading" style="color:var(--swps-text-muted)"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

	<div id="swps-keyword-history-modal" class="swps-modal" style="display:none;">
		<div class="swps-modal-content">
			<span class="swps-modal-close">&times;</span>
			<h3 id="swps-history-title"></h3>
			<div id="swps-history-chart" class="swps-chart"></div>
		</div>
	</div>
</div>
