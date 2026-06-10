<?php
/**
 * Site Crawl page (v4.19).
 *
 * Wrapped by SWPS_Admin_Shell. Header + summary tiles + settings card +
 * issue groups container (rendered client-side by admin/js/site-crawl.js
 * from the latest stored run — the page itself never crawls).
 *
 * "Rendered" here = server-rendered HTML fetched via wp_remote_get; the
 * crawler does not execute JavaScript.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$swps_crawl_summary = get_option( SWPS_Site_Crawler::OPT_LAST_SUMMARY, array() );
$swps_crawl_state   = SWPS_Site_Crawler::get_state();

$swps_crawl_internal_cap = (int) get_option( 'swps_crawl_internal_cap', SWPS_Site_Crawler::DEFAULT_INTERNAL_CAP );
$swps_crawl_external_cap = (int) get_option( 'swps_crawl_external_cap', SWPS_Site_Crawler::DEFAULT_EXTERNAL_CAP );
$swps_crawl_delay_ms     = (int) round( (int) get_option( 'swps_crawl_delay_us', SWPS_Site_Crawler::DEFAULT_DELAY_US ) / 1000 );
$swps_crawl_weekly       = (int) get_option( 'swps_crawl_weekly_enabled', 0 );
$swps_crawl_ignore       = (string) get_option( 'swps_crawl_ignore_hosts', '' );

$swps_crawl_last_crawled = (int) ( $swps_crawl_summary['crawled'] ?? 0 );
$swps_crawl_last_issues  = 0;
foreach ( (array) ( $swps_crawl_summary['issue_counts'] ?? array() ) as $swps_crawl_count_row ) {
	$swps_crawl_last_issues += (int) ( $swps_crawl_count_row['cnt'] ?? 0 );
}
$swps_crawl_last_at = (string) ( $swps_crawl_summary['completed_at'] ?? '' );
?>
<div class="wrap swps-site-crawl-wrap">

	<?php
	// The page-header partial's contract requires these exact variable names.
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$title    = __( 'Site Crawl', 'stratawp-seo' );
	$subtitle = __( 'Crawl your own site\'s server-rendered HTML (no JavaScript) in polite background batches: broken links, redirect chains, canonical mismatches, H1 problems, and mixed content — with one-click fixes.', 'stratawp-seo' );
	$actions  = array(
		array(
			'label' => __( 'Start crawl', 'stratawp-seo' ),
			'class' => 'swps-btn-grad',
			'attrs' => 'id="swps-crawl-start"',
		),
	);
	// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<div class="swps-summary-tiles">
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Pages crawled', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-grad" id="swps-crawl-tile-crawled"><?php echo (int) $swps_crawl_last_crawled; ?></div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'last completed run', 'stratawp-seo' ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Queued', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num" id="swps-crawl-tile-queued">0</div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'awaiting fetch', 'stratawp-seo' ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Issues found', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-warn" id="swps-crawl-tile-issues"><?php echo (int) $swps_crawl_last_issues; ?></div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'across all types', 'stratawp-seo' ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Last crawl', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num" id="swps-crawl-tile-last" style="font-size:16px;line-height:1.4;padding-top:8px;">
				<?php echo '' !== $swps_crawl_last_at ? esc_html( $swps_crawl_last_at ) : esc_html__( 'Never', 'stratawp-seo' ); ?>
			</div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'UTC', 'stratawp-seo' ); ?></div>
		</div>
	</div>

	<div class="swps-row-card" id="swps-crawl-progress" style="display:none;">
		<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
		<span id="swps-crawl-progress-text"><?php esc_html_e( 'Working…', 'stratawp-seo' ); ?></span>
	</div>

	<div class="swps-row-card swps-crawl-settings">
		<div class="swps-section-h" style="margin-top:0;">
			<h3><?php esc_html_e( 'Crawl settings', 'stratawp-seo' ); ?></h3>
		</div>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="swps-crawl-internal-cap"><?php esc_html_e( 'Internal URL cap', 'stratawp-seo' ); ?></label></th>
				<td>
					<input type="number" id="swps-crawl-internal-cap" min="10" max="2000" step="10" value="<?php echo (int) $swps_crawl_internal_cap; ?>" />
					<p class="description"><?php esc_html_e( 'Maximum internal pages fetched per run (default 500).', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="swps-crawl-external-cap"><?php esc_html_e( 'External check cap', 'stratawp-seo' ); ?></label></th>
				<td>
					<input type="number" id="swps-crawl-external-cap" min="0" max="1000" step="10" value="<?php echo (int) $swps_crawl_external_cap; ?>" />
					<p class="description"><?php esc_html_e( 'Maximum outbound links checked at the end of a run (default 200). Set 0 to skip.', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="swps-crawl-delay"><?php esc_html_e( 'Politeness delay (ms)', 'stratawp-seo' ); ?></label></th>
				<td>
					<input type="number" id="swps-crawl-delay" min="100" max="5000" step="50" value="<?php echo (int) $swps_crawl_delay_ms; ?>" />
					<p class="description"><?php esc_html_e( 'Pause between fetches. Keep at 500ms+ on shared hosting.', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="swps-crawl-ignore-hosts"><?php esc_html_e( 'Ignored external hosts', 'stratawp-seo' ); ?></label></th>
				<td>
					<input type="text" id="swps-crawl-ignore-hosts" class="regular-text" value="<?php echo esc_attr( $swps_crawl_ignore ); ?>" placeholder="linkedin.com, x.com" />
					<p class="description"><?php esc_html_e( 'Comma-separated hosts to skip during external checks (e.g. sites that wall off bots with 403s).', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Weekly re-crawl', 'stratawp-seo' ); ?></th>
				<td>
					<label for="swps-crawl-weekly">
						<input type="checkbox" id="swps-crawl-weekly" value="1" <?php checked( 1, $swps_crawl_weekly ); ?> />
						<?php esc_html_e( 'Automatically re-crawl once a week in the background and flag new issues.', 'stratawp-seo' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<p>
			<button type="button" class="swps-btn swps-btn-secondary" id="swps-crawl-save-settings"><?php esc_html_e( 'Save settings', 'stratawp-seo' ); ?></button>
			<span id="swps-crawl-settings-msg" style="margin-left:8px;"></span>
		</p>
	</div>

	<div class="swps-section-h">
		<h3><?php esc_html_e( 'Issues', 'stratawp-seo' ); ?></h3>
	</div>

	<div id="swps-crawl-issues">
		<div class="swps-row-card">
			<p><?php esc_html_e( 'Loading latest crawl results…', 'stratawp-seo' ); ?></p>
		</div>
	</div>
</div>
