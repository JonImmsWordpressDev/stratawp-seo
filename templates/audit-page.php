<?php
/**
 * SEO Audit page (v4.0).
 *
 * Wrapped by SWPS_Admin_Shell. Uses page-header partial + summary tiles
 * + the legacy .swps-audit-module-card list (restyled via templates.css).
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
$overall  = (int) ( $results['overall_score'] ?? 0 );

$crit_count = 0;
$warn_count = 0;
$pass_count = 0;
foreach ( $modules as $id => $mod ) {
	$status = $results['modules'][ $id ]['status'] ?? 'fail';
	if ( 'pass' === $status ) {
		++$pass_count;
	} elseif ( 'warning' === $status ) {
		++$warn_count;
	} else {
		++$crit_count;
	}
}
?>
<div class="wrap swps-audit-wrap">

	<?php
	$title    = __( 'SEO Audit', 'stratawp-seo' );
	$subtitle = $last_run
		? sprintf(
			/* translators: %s: human time diff */
			esc_html__( 'Last run %s. Click "Run Audit" to refresh.', 'stratawp-seo' ),
			esc_html( human_time_diff( strtotime( $last_run ), time() ) . ' ago' )
		)
		: esc_html__( 'No audit run yet — click "Run Audit" to start.', 'stratawp-seo' );
	$actions = array(
		array(
			'label' => __( 'Export CSV', 'stratawp-seo' ),
			'class' => 'swps-btn-secondary',
			'attrs' => 'id="swps-export-csv"',
		),
		array(
			'label' => __( 'Fix All', 'stratawp-seo' ),
			'class' => 'swps-btn-secondary',
			'attrs' => 'id="swps-fix-all"',
		),
		array(
			'label' => __( 'Run Audit', 'stratawp-seo' ),
			'class' => 'swps-btn-grad',
			'attrs' => 'id="swps-run-audit"',
		),
	);
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<div class="swps-summary-tiles">
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Overall score', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-grad"><?php echo (int) $overall; ?></div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'out of 100', 'stratawp-seo' ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Critical', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-crit"><?php echo (int) $crit_count; ?></div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'modules failing', 'stratawp-seo' ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Warnings', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-warn"><?php echo (int) $warn_count; ?></div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'minor issues', 'stratawp-seo' ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Passing', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-ok"><?php echo (int) $pass_count; ?></div>
			<div class="swps-summary-tile-foot">
				<?php
				printf(
					/* translators: %d: total modules */
					esc_html__( 'of %d modules', 'stratawp-seo' ),
					count( $modules )
				);
				?>
			</div>
		</div>
	</div>

	<div id="swps-audit-progress" class="swps-progress" style="display: none;">
		<div class="swps-spinner"></div>
		<div class="swps-progress-text">
			<strong id="swps-audit-progress-title"><?php esc_html_e( 'Running audit...', 'stratawp-seo' ); ?></strong>
		</div>
	</div>

	<div class="swps-section-h">
		<h3><?php esc_html_e( 'Module results', 'stratawp-seo' ); ?></h3>
		<div class="swps-filter-chips">
			<button type="button" class="swps-filter-chip is-on" data-swps-audit-filter="all"><?php esc_html_e( 'All', 'stratawp-seo' ); ?></button>
			<button type="button" class="swps-filter-chip" data-swps-audit-filter="issues"><?php esc_html_e( 'Issues only', 'stratawp-seo' ); ?></button>
			<button type="button" class="swps-filter-chip" data-swps-audit-filter="fixable"><?php esc_html_e( 'Auto-fixable', 'stratawp-seo' ); ?></button>
		</div>
	</div>

	<div class="swps-audit-modules">
		<?php
		foreach ( $modules as $id => $module ) :
			$mod_result = $results['modules'][ $id ] ?? null;
			$mod_score  = (int) ( $mod_result['score'] ?? 0 );
			$mod_status = $mod_result['status'] ?? 'fail';
			$issues     = $mod_result['issues'] ?? array();
			$summary    = $mod_result['summary'] ?? __( 'Not yet audited.', 'stratawp-seo' );
			$can_fix    = $module->can_auto_fix();

			$status_dot_class = match ( $mod_status ) {
				'pass'    => 'swps-status-dot-ok',
				'warning' => 'swps-status-dot-warn',
				default   => 'swps-status-dot-crit',
			};
			$status_dot_glyph = match ( $mod_status ) {
				'pass'    => '✓',
				default   => '!',
			};
			?>
		<div class="swps-row-card swps-audit-module-card"
			data-module-id="<?php echo esc_attr( $id ); ?>"
			data-status="<?php echo esc_attr( $mod_status ); ?>"
			data-fixable="<?php echo $can_fix ? '1' : '0'; ?>">
			<div class="swps-row-head">
				<div class="swps-status-dot <?php echo esc_attr( $status_dot_class ); ?>">
					<?php echo esc_html( $status_dot_glyph ); ?>
				</div>
				<div>
					<div class="swps-row-head-name"><?php echo esc_html( $module->get_label() ); ?></div>
					<div class="swps-row-head-msg"><?php echo esc_html( $summary ); ?></div>
				</div>
				<div class="swps-row-head-meta">
					<div class="swps-row-head-score"><?php echo (int) $mod_score; ?> / 100</div>
					<?php if ( $can_fix && ! empty( $issues ) ) : ?>
						<button class="swps-btn swps-btn-grad swps-fix-module" data-module="<?php echo esc_attr( $id ); ?>" style="padding:6px 12px;font-size:11px">
							<?php esc_html_e( 'Auto-fix all', 'stratawp-seo' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( ! empty( $issues ) ) : ?>
						<button type="button" class="swps-btn swps-btn-secondary swps-toggle-issues"
							data-module="<?php echo esc_attr( $id ); ?>"
							style="padding:6px 12px;font-size:11px">
							<?php
							printf(
								/* translators: %d: number of issues */
								esc_html( _n( '%d issue', '%d issues', count( $issues ), 'stratawp-seo' ) ),
								count( $issues )
							);
							?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $issues ) ) : ?>
			<div class="swps-row-detail" id="swps-issues-<?php echo esc_attr( $id ); ?>">
				<table class="widefat">
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
							<td class="swps-issue-fixable" style="width:60px;text-align:right">
								<?php if ( $issue['fixable'] ) : ?>
									<span class="swps-pill swps-pill-info" title="<?php esc_attr_e( 'Auto-fixable', 'stratawp-seo' ); ?>">FIX</span>
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

<script>
(function () {
	'use strict';
	document.addEventListener('DOMContentLoaded', function () {
		var wrap = document.querySelector('.swps-audit-wrap');
		if (!wrap) return;

		// Filter chips
		var chips = wrap.querySelectorAll('[data-swps-audit-filter]');
		var cards = wrap.querySelectorAll('.swps-audit-module-card');
		chips.forEach(function (chip) {
			chip.addEventListener('click', function () {
				chips.forEach(function (c) { c.classList.remove('is-on'); });
				chip.classList.add('is-on');
				var filter = chip.dataset.swpsAuditFilter;
				cards.forEach(function (card) {
					var status = card.dataset.status || 'fail';
					var fixable = card.dataset.fixable === '1';
					var show = true;
					if (filter === 'issues') show = (status !== 'pass');
					else if (filter === 'fixable') show = fixable;
					card.style.display = show ? '' : 'none';
				});
			});
		});

		// Toggle expand/collapse on click of toggle-issues OR row head
		wrap.querySelectorAll('.swps-toggle-issues').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				var card = btn.closest('.swps-row-card');
				if (card) card.classList.toggle('is-open');
			});
		});
		wrap.querySelectorAll('.swps-row-head').forEach(function (head) {
			head.addEventListener('click', function (e) {
				if (e.target.closest('button') || e.target.closest('a')) return;
				var card = head.closest('.swps-row-card');
				if (card) card.classList.toggle('is-open');
			});
		});
	});
})();
</script>
