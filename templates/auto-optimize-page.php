<?php
/**
 * AI Auto-Optimize page (v4.1).
 *
 * Wrapped by SWPS_Admin_Shell. Uses page-header partial + summary tiles
 * + .swps-row-card list to match the data-dense template (audit page pattern).
 *
 * Spec: docs/superpowers/specs/2026-04-30-admin-redesign-design.md §6.1
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var SWPS_Auto_Optimize $auto */
$auto      = stratawp_seo()->auto_optimize;
$threshold = $auto->get_threshold();
$queue     = $auto->get_queue( $threshold, 50 );
$stats     = $auto->get_stats( $queue );

/**
 * Render a single queue row using the data-dense .swps-row-card pattern.
 *
 * Declared here (before the render loop) because PHP does not hoist
 * conditionally-declared functions — calling a function defined inside an
 * `if (! function_exists(...))` block before that block executes is a fatal.
 *
 * @param array $row Queue row from SWPS_Auto_Optimize::get_queue().
 */
if ( ! function_exists( 'swps_render_optimize_row' ) ) {
	function swps_render_optimize_row( array $row ): void {
		$score        = (int) $row['current_score'];
		$score_class  = $score >= 80 ? 'swps-status-dot-ok' : ( $score >= 50 ? 'swps-status-dot-warn' : 'swps-status-dot-crit' );
		$score_glyph  = $score >= 80 ? '✓' : '!';
		$has_proposal = ! empty( $row['has_proposal'] );
		$projected    = $has_proposal ? (int) ( $row['proposal']['projected_score'] ?? 0 ) : null;
		$top_recs     = (array) ( $row['top_recs'] ?? array() );
		$reason       = ! empty( $top_recs ) ? (string) $top_recs[0] : '';
		?>
		<div class="swps-row-card swps-optimize-row"
			data-post-id="<?php echo (int) $row['post_id']; ?>"
			data-current-score="<?php echo (int) $score; ?>"
			data-status="<?php echo $has_proposal ? 'proposed' : 'needs'; ?>">
			<div class="swps-row-head">
				<div class="swps-status-dot <?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $score_glyph ); ?></div>
				<div>
					<div class="swps-row-head-name">
						<a href="<?php echo esc_url( $row['edit_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['title'] ); ?></a>
					</div>
					<div class="swps-row-head-msg">
						<?php echo esc_html( '' !== $reason ? $reason : __( 'Click "Generate proposal" for AI suggestions.', 'stratawp-seo' ) ); ?>
						&middot; <?php echo (int) $row['word_count']; ?> <?php esc_html_e( 'words', 'stratawp-seo' ); ?>
						&middot; <a href="<?php echo esc_url( $row['permalink'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'stratawp-seo' ); ?></a>
					</div>
				</div>
				<div class="swps-row-head-meta">
					<div class="swps-row-head-score">
						<?php echo (int) $score; ?>
						<?php if ( null !== $projected ) : ?>
							<span class="swps-optimize-arrow">&rarr;</span>
							<span class="swps-optimize-projected"><?php echo (int) $projected; ?></span>
						<?php endif; ?>
					</div>
					<?php if ( $has_proposal ) : ?>
						<button class="swps-btn swps-btn-grad swps-optimize-review" style="padding:6px 12px;font-size:11px"><?php esc_html_e( 'Review', 'stratawp-seo' ); ?></button>
						<button class="swps-btn swps-btn-secondary swps-optimize-propose" title="<?php esc_attr_e( 'Re-generate proposal', 'stratawp-seo' ); ?>" style="padding:6px 10px;font-size:11px">
							<span class="dashicons dashicons-update"></span>
						</button>
					<?php else : ?>
						<button class="swps-btn swps-btn-grad swps-optimize-propose" style="padding:6px 12px;font-size:11px"><?php esc_html_e( 'Generate proposal', 'stratawp-seo' ); ?></button>
					<?php endif; ?>
					<button class="swps-btn swps-btn-secondary swps-optimize-dismiss" title="<?php esc_attr_e( 'Dismiss', 'stratawp-seo' ); ?>" style="padding:6px 10px;font-size:11px">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
			</div>
		</div>
		<?php
	}
}
?>
<div class="wrap swps-auto-optimize-wrap">

	<?php
	$title    = __( 'AI Auto-Optimize', 'stratawp-seo' );
	$subtitle = __( 'Find low-scoring posts, generate AI edit proposals, review the diff, then apply with one click. Every edit is reviewed before it touches your content.', 'stratawp-seo' );
	$actions  = array(
		array(
			'label' => __( 'Refresh queue', 'stratawp-seo' ),
			'class' => 'swps-btn-secondary',
			'attrs' => 'id="swps-optimize-refresh"',
		),
		array(
			'label' => __( 'Re-scan all posts', 'stratawp-seo' ),
			'class' => 'swps-btn-grad',
			'attrs' => 'id="swps-optimize-rescan"',
		),
	);
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<div class="swps-summary-tiles">
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Below threshold', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-grad" id="swps-optimize-tile-count"><?php echo (int) $stats['count']; ?></div>
			<div class="swps-summary-tile-foot">
				<?php
				printf(
					/* translators: %d: threshold */
					esc_html__( 'posts under %d', 'stratawp-seo' ),
					(int) $threshold
				);
				?>
			</div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Proposals ready', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-ok" id="swps-optimize-tile-proposed"><?php echo (int) $stats['with_proposal']; ?></div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'awaiting review', 'stratawp-seo' ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Avg projected lift', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-warn" id="swps-optimize-tile-lift">+<?php echo (int) $stats['avg_lift']; ?></div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'points per post', 'stratawp-seo' ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Est. cost', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num" id="swps-optimize-tile-cost">$<?php echo esc_html( number_format( (float) $stats['est_cost'], 4 ) ); ?></div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'all queued proposals', 'stratawp-seo' ); ?></div>
		</div>
	</div>

	<div class="swps-row-card swps-optimize-controls">
		<div class="swps-optimize-controls-row">
			<div class="swps-optimize-control-field">
				<label for="swps-optimize-threshold"><strong><?php esc_html_e( 'Score threshold', 'stratawp-seo' ); ?></strong></label>
				<p class="swps-optimize-control-help"><?php esc_html_e( 'Posts below this score appear in the queue.', 'stratawp-seo' ); ?></p>
			</div>
			<input type="number" id="swps-optimize-threshold" min="0" max="100" step="1" value="<?php echo (int) $threshold; ?>" />
			<div class="swps-optimize-progress" id="swps-optimize-progress" style="display:none;">
				<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
				<span id="swps-optimize-progress-text"><?php esc_html_e( 'Working...', 'stratawp-seo' ); ?></span>
			</div>
		</div>
	</div>

	<div class="swps-section-h">
		<h3><?php esc_html_e( 'Optimization queue', 'stratawp-seo' ); ?></h3>
		<div class="swps-filter-chips">
			<button type="button" class="swps-filter-chip is-on" data-swps-filter="all"><?php esc_html_e( 'All', 'stratawp-seo' ); ?></button>
			<button type="button" class="swps-filter-chip" data-swps-filter="proposed"><?php esc_html_e( 'Has proposal', 'stratawp-seo' ); ?></button>
			<button type="button" class="swps-filter-chip" data-swps-filter="needs"><?php esc_html_e( 'Needs proposal', 'stratawp-seo' ); ?></button>
		</div>
	</div>

	<div class="swps-optimize-queue" id="swps-optimize-queue">
		<?php if ( empty( $queue ) ) : ?>
			<div class="swps-row-card swps-optimize-empty">
				<p><?php esc_html_e( 'Nothing to optimize. No published posts are below the threshold. Lower the threshold or run a re-scan to refresh scores.', 'stratawp-seo' ); ?></p>
			</div>
		<?php else : ?>
			<?php foreach ( $queue as $row ) : ?>
				<?php swps_render_optimize_row( $row ); ?>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>

<!-- Review modal -->
<div class="swps-optimize-modal" id="swps-optimize-modal" style="display:none;" aria-hidden="true">
	<div class="swps-optimize-modal-backdrop" data-close></div>
	<div class="swps-optimize-modal-panel" role="dialog" aria-modal="true" aria-labelledby="swps-optimize-modal-title">
		<div class="swps-optimize-modal-head">
			<h2 id="swps-optimize-modal-title"><?php esc_html_e( 'Review proposal', 'stratawp-seo' ); ?></h2>
			<button class="swps-optimize-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'stratawp-seo' ); ?>">&times;</button>
		</div>
		<div class="swps-optimize-modal-body" id="swps-optimize-modal-body"></div>
		<div class="swps-optimize-modal-foot">
			<button class="swps-btn swps-btn-secondary" data-close><?php esc_html_e( 'Cancel', 'stratawp-seo' ); ?></button>
			<button class="swps-btn swps-btn-grad" id="swps-optimize-apply"><?php esc_html_e( 'Apply selected edits', 'stratawp-seo' ); ?></button>
		</div>
	</div>
</div>
