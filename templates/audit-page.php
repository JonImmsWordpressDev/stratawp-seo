<?php
/**
 * SEO Audit admin page template.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$audit    = stratawp_seo()->seo_audit;
$results  = $audit->get_cached_results();
$last_run = $audit->get_last_run();
$modules  = $audit->get_modules();
$overall  = $results['overall_score'] ?? 0;

$score_class = $overall >= 80 ? 'excellent' : ( $overall >= 50 ? 'good' : 'poor' );
?>
<div class="wrap swps-wrap">
	<h1><?php esc_html_e( 'SEO Audit', 'stratawp-seo' ); ?></h1>

	<div class="swps-audit-header">
		<div class="swps-audit-score-circle swps-audit-score--<?php echo esc_attr( $score_class ); ?>">
			<span class="swps-audit-score-number"><?php echo (int) $overall; ?></span>
			<span class="swps-audit-score-label"><?php esc_html_e( 'Site Health', 'stratawp-seo' ); ?></span>
		</div>

		<div class="swps-audit-actions">
			<button id="swps-run-audit" class="button button-primary button-hero">
				<span class="dashicons dashicons-search" style="margin-top: 4px;"></span>
				<?php esc_html_e( 'Run Audit', 'stratawp-seo' ); ?>
			</button>

			<button id="swps-fix-all" class="button button-hero">
				<span class="dashicons dashicons-admin-tools" style="margin-top: 4px;"></span>
				<?php esc_html_e( 'Fix All', 'stratawp-seo' ); ?>
			</button>

			<button id="swps-export-csv" class="button">
				<?php esc_html_e( 'Export CSV', 'stratawp-seo' ); ?>
			</button>

			<?php if ( $last_run ) : ?>
				<span class="swps-audit-last-run">
					<?php
					printf(
						esc_html__( 'Last audited: %s', 'stratawp-seo' ),
						esc_html( human_time_diff( strtotime( $last_run ), current_time( 'timestamp' ) ) . ' ago' )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
	</div>

	<div id="swps-audit-progress" class="swps-progress" style="display: none;">
		<div class="swps-spinner"></div>
		<div class="swps-progress-text">
			<strong id="swps-audit-progress-title"><?php esc_html_e( 'Running audit...', 'stratawp-seo' ); ?></strong>
		</div>
	</div>

	<div class="swps-audit-modules">
		<?php foreach ( $modules as $id => $module ) :
			$mod_result = $results['modules'][ $id ] ?? null;
			$mod_score  = $mod_result['score'] ?? 0;
			$mod_status = $mod_result['status'] ?? 'fail';
			$issues     = $mod_result['issues'] ?? [];
			$summary    = $mod_result['summary'] ?? __( 'Not yet audited.', 'stratawp-seo' );
			$can_fix    = $module->can_auto_fix();

			$status_icon = match ( $mod_status ) {
				'pass'    => '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>',
				'warning' => '<span class="dashicons dashicons-warning" style="color:#dba617;"></span>',
				default   => '<span class="dashicons dashicons-dismiss" style="color:#d63638;"></span>',
			};
		?>
		<div class="swps-audit-module-card swps-card" data-module-id="<?php echo esc_attr( $id ); ?>">
			<div class="swps-audit-module-header">
				<div class="swps-audit-module-info">
					<?php echo $status_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<strong><?php echo esc_html( $module->get_label() ); ?></strong>
					<span class="swps-score-badge swps-score-badge--<?php echo $mod_score >= 80 ? 'excellent' : ( $mod_score >= 50 ? 'good' : 'poor' ); ?>">
						<?php echo (int) $mod_score; ?>/100
					</span>
				</div>
				<div class="swps-audit-module-actions">
					<?php if ( $can_fix && ! empty( $issues ) ) : ?>
						<button class="button swps-fix-module" data-module="<?php echo esc_attr( $id ); ?>">
							<?php esc_html_e( 'Fix', 'stratawp-seo' ); ?>
						</button>
					<?php endif; ?>
					<button class="button swps-toggle-issues" data-module="<?php echo esc_attr( $id ); ?>">
						<?php echo count( $issues ); ?> <?php esc_html_e( 'issues', 'stratawp-seo' ); ?>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
				</div>
			</div>
			<p class="swps-audit-module-summary"><?php echo esc_html( $summary ); ?></p>

			<?php if ( ! empty( $issues ) ) : ?>
			<div class="swps-audit-issues" id="swps-issues-<?php echo esc_attr( $id ); ?>" style="display: none;">
				<table class="widefat striped">
					<tbody>
					<?php foreach ( $issues as $issue ) : ?>
						<tr>
							<td>
								<?php echo esc_html( $issue['message'] ); ?>
								<?php if ( $issue['post_id'] ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $issue['post_id'] ) ); ?>" target="_blank" class="swps-issue-link">
										<?php esc_html_e( 'Edit', 'stratawp-seo' ); ?> &rarr;
									</a>
								<?php endif; ?>
							</td>
							<td class="swps-issue-fixable">
								<?php if ( $issue['fixable'] ) : ?>
									<span class="dashicons dashicons-admin-tools" title="<?php esc_attr_e( 'Auto-fixable', 'stratawp-seo' ); ?>"></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	</div>
</div>
