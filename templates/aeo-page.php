<?php
/**
 * AEO Optimize admin page.
 *
 * Variables in scope (set by SWPS_AEO_Optimizer::render_page()):
 *   $threshold  int    Current AEO threshold (default 70).
 *   $post_types array  Slugs the optimizer scans.
 *
 * Data populates via AJAX (admin/js/aeo-optimizer.js, Task 16).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current AEO threshold (default 70).
 *
 * @var int $threshold
 */
/**
 * Slugs the optimizer scans.
 *
 * @var string[] $post_types
 */
?>
<div class="wrap swps-page swps-aeo-page">
	<div class="swps-page-header">
		<h1><?php esc_html_e( 'AEO Optimize', 'stratawp-seo' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Score posts on AI-citeability across 4 dimensions. Surface posts below the threshold; generate AI proposals; review diffs; apply with one click.', 'stratawp-seo' ); ?>
		</p>
		<div class="swps-actions">
			<button type="button" class="button button-primary" id="swps-aeo-rescan">
				<?php esc_html_e( 'Re-scan all posts', 'stratawp-seo' ); ?>
			</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-settings#aeo' ) ); ?>" class="button">
				<?php esc_html_e( 'Settings', 'stratawp-seo' ); ?>
			</a>
		</div>
	</div>

	<div class="swps-aeo-progress" id="swps-aeo-progress" style="display:none;">
		<div class="swps-aeo-progress-bar"><span id="swps-aeo-progress-fill"></span></div>
		<p id="swps-aeo-progress-text"></p>
	</div>

	<div class="swps-aeo-tiles">
		<div class="swps-tile" id="swps-aeo-tile-scored"><span class="swps-tile-num">—</span><span class="swps-tile-label"><?php esc_html_e( 'Scored', 'stratawp-seo' ); ?></span></div>
		<div class="swps-tile" id="swps-aeo-tile-below"><span class="swps-tile-num">—</span><span class="swps-tile-label"><?php esc_html_e( 'Below threshold', 'stratawp-seo' ); ?></span></div>
		<div class="swps-tile" id="swps-aeo-tile-avg"><span class="swps-tile-num">—</span><span class="swps-tile-label"><?php esc_html_e( 'Avg score', 'stratawp-seo' ); ?></span></div>
		<div class="swps-tile" id="swps-aeo-tile-low-dim"><span class="swps-tile-num">—</span><span class="swps-tile-label"><?php esc_html_e( 'Weakest dimension', 'stratawp-seo' ); ?></span></div>
	</div>

	<div class="swps-aeo-filters">
		<label>
			<?php esc_html_e( 'Threshold', 'stratawp-seo' ); ?>
			<input type="number" id="swps-aeo-threshold" value="<?php echo esc_attr( (string) $threshold ); ?>" min="50" max="95">
		</label>
		<label>
			<?php esc_html_e( 'Post type', 'stratawp-seo' ); ?>
			<select id="swps-aeo-post-type">
				<option value=""><?php esc_html_e( 'All', 'stratawp-seo' ); ?></option>
				<?php foreach ( $post_types as $swps_pt ) : ?>
					<option value="<?php echo esc_attr( $swps_pt ); ?>"><?php echo esc_html( $swps_pt ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	</div>

	<?php if ( ! empty( $question_candidates ) ) : ?>
		<div class="swps-aeo-question-opportunities" id="swps-aeo-question-opportunities"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'swps_question_coverage' ) ); ?>"
			style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;margin:16px 0;">
			<h2 style="margin:0 0 4px;font-size:14px;"><?php esc_html_e( 'Question opportunities', 'stratawp-seo' ); ?></h2>
			<p class="description" style="margin:0 0 8px;">
				<?php esc_html_e( 'Question searches from Google Search Console with no post answering them. Queue one to draft it.', 'stratawp-seo' ); ?>
			</p>
			<table class="widefat striped" style="border:0;">
				<tbody>
					<?php foreach ( $question_candidates as $swps_candidate ) : ?>
						<?php
						if ( ! is_array( $swps_candidate ) || empty( $swps_candidate['q'] ) ) {
							continue;
						}
						?>
						<tr>
							<td><?php echo esc_html( (string) $swps_candidate['q'] ); ?></td>
							<td style="text-align:right;color:#646970;width:120px;">
								<?php
								printf(
									/* translators: %s: impression count from Search Console. */
									esc_html__( '%s impressions', 'stratawp-seo' ),
									esc_html( number_format_i18n( (int) ( $swps_candidate['impressions'] ?? 0 ) ) )
								);
								?>
							</td>
							<td style="text-align:right;width:110px;">
								<button type="button" class="button button-small swps-queue-question-topic"
									data-question="<?php echo esc_attr( (string) $swps_candidate['q'] ); ?>">
									<?php esc_html_e( 'Queue topic', 'stratawp-seo' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<table class="wp-list-table widefat striped swps-aeo-queue" id="swps-aeo-queue">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Score', 'stratawp-seo' ); ?></th>
				<th><?php esc_html_e( 'Title', 'stratawp-seo' ); ?></th>
				<th><?php esc_html_e( 'Sub-scores', 'stratawp-seo' ); ?></th>
				<th><?php esc_html_e( 'Last crawl', 'stratawp-seo' ); ?></th>
				<th style="text-align:right"><?php esc_html_e( 'AI visits (30d)', 'stratawp-seo' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr class="swps-aeo-empty"><td colspan="6"><?php esc_html_e( 'No posts scored yet. Click "Re-scan all posts" to start.', 'stratawp-seo' ); ?></td></tr>
		</tbody>
	</table>
</div>

<div class="swps-aeo-modal" id="swps-aeo-modal" style="display:none;">
	<div class="swps-aeo-modal-inner"></div>
</div>
