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

	<div class="swps-settings-panel" id="swps-citations-card">
		<h2 style="margin-top:0"><?php esc_html_e( 'AI Citations', 'stratawp-seo' ); ?></h2>
		<p style="color:var(--swps-text-muted);font-size:12px;margin:0 0 12px">
			<?php esc_html_e( 'Track whether AI assistants cite your site for your key prompts. Checks use your API keys; some providers bill per search — estimates only.', 'stratawp-seo' ); ?>
		</p>

		<div class="swps-citations-toolbar" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
			<input type="text" id="swps-citation-prompt-input" class="regular-text" style="flex:1;min-width:240px"
					placeholder="<?php esc_attr_e( 'Add a prompt to track (e.g., best running shoes for flat feet)', 'stratawp-seo' ); ?>" />
			<button class="button" id="swps-citation-add-btn"><?php esc_html_e( 'Add Prompt', 'stratawp-seo' ); ?></button>
			<button class="button" id="swps-citation-seed-btn"><?php esc_html_e( 'Seed from keywords & GSC', 'stratawp-seo' ); ?></button>
			<button class="swps-btn swps-btn-grad" id="swps-citation-run-btn"><?php esc_html_e( 'Run checks now', 'stratawp-seo' ); ?></button>
			<span class="spinner" id="swps-citation-spinner"></span>
		</div>

		<div id="swps-citation-progress" style="display:none;font-size:12px;color:var(--swps-text-muted);margin-bottom:8px"></div>
		<div id="swps-citation-seed-panel" style="display:none;margin-bottom:12px;border:1px solid #e0e0e0;border-radius:4px;padding:10px"></div>

		<table class="widefat striped" id="swps-citations-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Prompt', 'stratawp-seo' ); ?></th>
					<th id="swps-citation-engine-cols"><?php esc_html_e( 'Engines', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Last Check', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td colspan="4" class="swps-loading" style="color:var(--swps-text-muted)"><?php esc_html_e( 'Loading...', 'stratawp-seo' ); ?></td></tr>
			</tbody>
		</table>

		<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:16px">
			<div style="flex:1;min-width:280px">
				<h3 style="font-size:13px;margin:0 0 8px"><?php esc_html_e( 'Share of Voice (30d)', 'stratawp-seo' ); ?></h3>
				<div id="swps-citation-sov"><span style="color:var(--swps-text-muted);font-size:12px"><?php esc_html_e( 'No checks yet.', 'stratawp-seo' ); ?></span></div>
			</div>
			<div style="flex:1;min-width:280px">
				<h3 style="font-size:13px;margin:0 0 8px"><?php esc_html_e( 'Domains AI Cites (30d, top 10)', 'stratawp-seo' ); ?></h3>
				<ol id="swps-citation-breakdown" style="margin:0;padding-left:18px;font-size:12px"></ol>
			</div>
		</div>

		<p id="swps-citation-cap-line" style="color:var(--swps-text-muted);font-size:12px;margin:12px 0 0"></p>
	</div>

	<div id="swps-keyword-history-modal" class="swps-modal" style="display:none;">
		<div class="swps-modal-content">
			<span class="swps-modal-close">&times;</span>
			<h3 id="swps-history-title"></h3>
			<div id="swps-history-chart" class="swps-chart"></div>
		</div>
	</div>
</div>
