<?php
/**
 * Refresh Queue page — decay-flagged posts sorted by traffic-at-risk.
 *
 * Wrapped by SWPS_Admin_Shell. Clones the Auto-Optimize page structure
 * (page-header partial + .swps-row-card list). All data comes from post meta
 * and the metric history table — zero GSC/AI calls on render.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refresh Queue admin page template.
 *
 * @var SWPS_Refresh_Queue_Admin $swps_rq
 */
$swps_rq    = stratawp_seo()->refresh_queue_admin;
$swps_queue = $swps_rq->get_queue();
?>
<div class="wrap swps-refresh-queue-wrap">

	<?php
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scoped vars consumed by the page-header partial.
	$title    = __( 'Refresh Queue', 'stratawp-seo' );
	$subtitle = __( 'Posts losing search clicks, flagged by the weekly decay scan. Cause labels are heuristic. "Propose refresh" opens the AEO review flow — every AI edit is shown as a diff and applied only on your approval.', 'stratawp-seo' );
	$actions  = array();
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	// phpcs:enable
	?>

	<div class="swps-summary-tiles">
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Flagged posts', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-grad"><?php echo (int) count( $swps_queue ); ?></div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'losing clicks vs prior 28 days', 'stratawp-seo' ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Clicks at risk', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-warn"><?php echo (int) round( array_sum( array_column( $swps_queue, 'traffic_at_risk' ) ) ); ?></div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'prior clicks × decline', 'stratawp-seo' ); ?></div>
		</div>
	</div>

	<div class="swps-refresh-queue" id="swps-refresh-queue">
		<?php if ( empty( $swps_queue ) ) : ?>
			<div class="swps-row-card swps-refresh-empty">
				<p><?php esc_html_e( 'No decayed posts. The weekly scan flags posts whose Search Console clicks drop past the threshold (Settings → Analytics → Content Decay Watchdog).', 'stratawp-seo' ); ?></p>
			</div>
		<?php else : ?>
			<?php foreach ( $swps_queue as $swps_row ) : ?>
				<div class="swps-row-card swps-refresh-row" data-post-id="<?php echo (int) $swps_row['post_id']; ?>">
					<div class="swps-row-head">
						<div class="swps-status-dot swps-status-dot-warn">!</div>
						<div>
							<div class="swps-row-head-name">
								<a href="<?php echo esc_url( $swps_row['edit_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $swps_row['title'] ); ?></a>
								<span class="swps-refresh-reason-badge" style="margin-left:6px;padding:2px 8px;border-radius:10px;font-size:10px;background:#fcf0f1;color:#b32d2e;"
									title="<?php esc_attr_e( 'Heuristic classification — verify before acting.', 'stratawp-seo' ); ?>">
									<?php echo esc_html( SWPS_Refresh_Queue_Admin::reason_label( $swps_row['reason'] ) ); ?>
									<em>(<?php esc_html_e( 'heuristic', 'stratawp-seo' ); ?>)</em>
								</span>
							</div>
							<div class="swps-row-head-msg">
								<?php
								printf(
									/* translators: 1: prior-window clicks, 2: recent-window clicks, 3: decline percent. */
									esc_html__( 'Clicks: %1$d → %2$d (−%3$d%%)', 'stratawp-seo' ),
									(int) $swps_row['prior_clicks'],
									(int) $swps_row['recent_clicks'],
									(int) round( $swps_row['click_change'] * 100 )
								);
								?>
								<?php if ( $swps_row['flagged_at'] > 0 ) : ?>
									&middot;
									<?php
									printf(
										/* translators: %s: human time diff. */
										esc_html__( 'flagged %s ago', 'stratawp-seo' ),
										esc_html( human_time_diff( $swps_row['flagged_at'], time() ) )
									);
									?>
								<?php endif; ?>
								&middot; <a href="<?php echo esc_url( $swps_row['permalink'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'stratawp-seo' ); ?></a>
								<?php foreach ( $swps_row['staleness'] as $swps_flag ) : ?>
									<span class="swps-refresh-stale-chip" style="margin-left:6px;padding:1px 6px;border-radius:8px;font-size:10px;background:#fef8ee;color:#996800;">
										<?php echo esc_html( SWPS_Refresh_Queue_Admin::staleness_label( (string) $swps_flag ) ); ?>
									</span>
								<?php endforeach; ?>
							</div>
							<div class="swps-refresh-sparklines" style="display:flex;gap:16px;margin-top:6px;font-size:10px;color:#666;">
								<?php if ( '' !== $swps_row['clicks_svg'] ) : ?>
									<span style="color:#2271b1;" title="<?php esc_attr_e( 'GSC clicks, last 180 days', 'stratawp-seo' ); ?>">
										<?php esc_html_e( 'Clicks', 'stratawp-seo' ); ?>
										<?php echo $swps_row['clicks_svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built by sparkline() from float data only. ?>
									</span>
								<?php endif; ?>
								<?php if ( '' !== $swps_row['aeo_svg'] ) : ?>
									<span style="color:#00a32a;" title="<?php esc_attr_e( 'AEO score, last 180 days', 'stratawp-seo' ); ?>">
										<?php esc_html_e( 'AEO', 'stratawp-seo' ); ?>
										<?php echo $swps_row['aeo_svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built by sparkline() from float data only. ?>
									</span>
								<?php endif; ?>
							</div>
						</div>
						<div class="swps-row-head-meta">
							<button class="swps-btn swps-btn-grad swps-refresh-propose" data-id="<?php echo (int) $swps_row['post_id']; ?>" style="padding:6px 12px;font-size:11px">
								<?php esc_html_e( 'Propose refresh', 'stratawp-seo' ); ?>
							</button>
							<button class="swps-btn swps-btn-secondary swps-refresh-dismiss" data-id="<?php echo (int) $swps_row['post_id']; ?>" title="<?php esc_attr_e( 'Dismiss (28-day cooldown)', 'stratawp-seo' ); ?>" style="padding:6px 10px;font-size:11px">
								<span class="dashicons dashicons-no-alt"></span>
							</button>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>

<script>
( function( $ ) {
	var nonce = <?php echo wp_json_encode( wp_create_nonce( SWPS_Refresh_Queue_Admin::NONCE ) ); ?>;
	var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

	$( document ).on( 'click', '.swps-refresh-propose', function() {
		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( <?php echo wp_json_encode( __( 'Preparing…', 'stratawp-seo' ) ); ?> );
		$.post( ajaxUrl, {
			action: 'swps_refresh_propose',
			nonce: nonce,
			post_id: $btn.data( 'id' )
		} ).done( function( resp ) {
			if ( resp && resp.success && resp.data.redirect ) {
				window.location = resp.data.redirect;
			} else {
				$btn.prop( 'disabled', false ).text( <?php echo wp_json_encode( __( 'Propose refresh', 'stratawp-seo' ) ); ?> );
				window.alert( ( resp && resp.data && resp.data.message ) || <?php echo wp_json_encode( __( 'Request failed.', 'stratawp-seo' ) ); ?> );
			}
		} ).fail( function() {
			$btn.prop( 'disabled', false ).text( <?php echo wp_json_encode( __( 'Propose refresh', 'stratawp-seo' ) ); ?> );
			window.alert( <?php echo wp_json_encode( __( 'Request failed.', 'stratawp-seo' ) ); ?> );
		} );
	} );

	$( document ).on( 'click', '.swps-refresh-dismiss', function() {
		if ( ! window.confirm( <?php echo wp_json_encode( __( 'Dismiss this post? It will not be re-flagged for 28 days.', 'stratawp-seo' ) ); ?> ) ) {
			return;
		}
		var $btn = $( this );
		var $row = $btn.closest( '.swps-refresh-row' );
		$btn.prop( 'disabled', true );
		$.post( ajaxUrl, {
			action: 'swps_refresh_dismiss',
			nonce: nonce,
			post_id: $btn.data( 'id' )
		} ).done( function( resp ) {
			if ( resp && resp.success ) {
				$row.fadeOut( 200, function() { $row.remove(); } );
			} else {
				$btn.prop( 'disabled', false );
				window.alert( ( resp && resp.data && resp.data.message ) || <?php echo wp_json_encode( __( 'Request failed.', 'stratawp-seo' ) ); ?> );
			}
		} ).fail( function() {
			$btn.prop( 'disabled', false );
			window.alert( <?php echo wp_json_encode( __( 'Request failed.', 'stratawp-seo' ) ); ?> );
		} );
	} );
} )( jQuery );
</script>
