<?php
/**
 * Dashboard page template.
 *
 * Expects $data array from SWPS_Dashboard::get_summary_data().
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array $data */
$first_name     = $data['first_name'] ?? '';
$health         = $data['site_health'] ?? array();
$recent         = $data['recent_generations'] ?? array();
$ai_cost        = $data['ai_cost_30d'] ?? array(
	'usd'  => 0,
	'gens' => 0,
);
$posts_30d      = $data['posts_30d'] ?? array(
	'views'     => 0,
	'delta_pct' => null,
);
$issues         = $data['top_issues'] ?? array();
$top_queries    = $data['top_queries'] ?? array();
$auto_q         = $data['auto_optimize_queue'] ?? array();
$competitors    = $data['competitors'] ?? array();
$backlinks_data = $data['backlinks'] ?? array(
	'stats'  => array(),
	'recent' => array(),
);
$bl_stats       = (array) ( $backlinks_data['stats'] ?? array() );
$bl_recent      = (array) ( $backlinks_data['recent'] ?? array() );
$modules        = $data['modules'] ?? array();

$aeo_health     = $data['aeo_health'] ?? array(
	'avg_score'           => 0,
	'above_threshold_pct' => 0,
	'total_scored'        => 0,
	'threshold'           => 70,
);
$score          = (int) ( $health['score'] ?? 0 );
$health_label   = $health['label'] ?? '—';
$score_deg      = (int) round( ( $score / 100 ) * 360 );
$last_audit_ago = ! empty( $health['last_run'] )
	? human_time_diff( (int) $health['last_run'], time() ) . ' ago'
	: esc_html__( 'never', 'stratawp-seo' );

// Welcome line — tailored to top action.
$welcome_msg = sprintf(
	/* translators: 1: site health score, 2: number of issues */
	esc_html__( 'Site Health %1$d. %2$d fixable issues. AI cost this month $%3$s.', 'stratawp-seo' ),
	$score,
	(int) ( $health['crit_count'] ?? 0 ) + (int) ( $health['warn_count'] ?? 0 ),
	number_format( (float) $ai_cost['usd'], 2 )
);
?>
<div class="wrap swps-dashboard-wrap">

	<?php
	$title = sprintf(
		/* translators: %s: user first name */
		esc_html__( 'Welcome back, %s', 'stratawp-seo' ),
		esc_html( $first_name )
	);
	$subtitle = $welcome_msg;
	$actions  = array(
		array(
			'label' => __( 'Generate post', 'stratawp-seo' ),
			'url'   => admin_url( 'admin.php?page=swps-generate' ),
			'class' => 'swps-btn-secondary',
		),
		array(
			'label' => __( 'Run audit', 'stratawp-seo' ),
			'class' => 'swps-btn-grad',
			'attrs' => 'data-swps-action="run-audit"',
		),
	);
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<!-- Row 1 — KPI tiles -->
	<div class="swps-dash-row swps-dash-row-1">

		<div class="swps-tile">
			<div class="swps-tile-h"><?php esc_html_e( 'Site Health', 'stratawp-seo' ); ?></div>
			<div class="swps-dash-health">
				<div class="swps-dash-gauge" style="--swps-dash-gauge-deg:<?php echo (int) $score_deg; ?>deg">
					<span class="swps-dash-gauge-num"><?php echo (int) $score; ?></span>
				</div>
				<div class="swps-dash-health-meta">
					<div class="swps-dash-health-label"><?php echo esc_html( $health_label ); ?></div>
					<div class="swps-dash-health-sub">
						<?php
						printf(
							/* translators: 1: critical count, 2: warning count */
							esc_html__( '%1$d critical, %2$d warning', 'stratawp-seo' ),
							(int) ( $health['crit_count'] ?? 0 ),
							(int) ( $health['warn_count'] ?? 0 )
						);
						?>
						<br><?php echo esc_html( $last_audit_ago ); ?>
					</div>
				</div>
			</div>
		</div>

		<div class="swps-tile">
			<div class="swps-tile-h"><?php esc_html_e( 'Post views (30d)', 'stratawp-seo' ); ?></div>
			<div class="swps-tile-stat"><?php echo number_format( (int) ( $posts_30d['views'] ?? 0 ) ); ?></div>
			<?php
			if ( null !== ( $posts_30d['delta_pct'] ?? null ) ) :
				$delta = (float) $posts_30d['delta_pct'];
				$cls   = $delta >= 0 ? '' : ' is-down';
				$arrow = $delta >= 0 ? '↑' : '↓';
				?>
				<div class="swps-tile-trend<?php echo esc_attr( $cls ); ?>">
					<?php echo esc_html( $arrow ); ?>
					<?php echo esc_html( number_format( abs( $delta ), 1 ) ); ?>%
					<?php esc_html_e( 'vs prev 30d', 'stratawp-seo' ); ?>
				</div>
			<?php else : ?>
				<div class="swps-tile-trend" style="color:var(--swps-text-faint)">
					<?php esc_html_e( 'On-site analytics — no data yet', 'stratawp-seo' ); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="swps-tile">
			<div class="swps-tile-h"><?php esc_html_e( 'Backlinks', 'stratawp-seo' ); ?></div>
			<?php if ( (int) ( $bl_stats['total'] ?? 0 ) > 0 ) : ?>
				<div class="swps-tile-stat"><?php echo number_format( (int) $bl_stats['total'] ); ?></div>
				<div class="swps-tile-trend" style="color:var(--swps-text-muted)">
					<?php
					printf(
						/* translators: 1: live count, 2: lost count, 3: unique domains */
						esc_html__( '%1$d live · %2$d lost · %3$d domains', 'stratawp-seo' ),
						(int) ( $bl_stats['live'] ?? 0 ),
						(int) ( $bl_stats['lost'] ?? 0 ),
						(int) ( $bl_stats['unique_domains'] ?? 0 )
					);
					?>
				</div>
			<?php else : ?>
				<div class="swps-tile-stat" style="font-size:18px;-webkit-text-fill-color:var(--swps-text-muted);background:none;color:var(--swps-text-muted)">
					<?php esc_html_e( '— Add some', 'stratawp-seo' ); ?>
				</div>
				<div class="swps-tile-trend" style="color:var(--swps-text-faint)">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-backlinks' ) ); ?>" style="color:inherit;text-decoration:underline">
						<?php esc_html_e( 'Track inbound links →', 'stratawp-seo' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>

		<div class="swps-tile">
			<div class="swps-tile-h"><?php esc_html_e( 'AI cost (30d)', 'stratawp-seo' ); ?></div>
			<div class="swps-tile-stat">$<?php echo esc_html( number_format( (float) $ai_cost['usd'], 2 ) ); ?></div>
			<div class="swps-tile-trend" style="color:var(--swps-text-muted)">
				<?php
				printf(
					/* translators: %d: number of generations */
					esc_html__( '%d generations', 'stratawp-seo' ),
					(int) $ai_cost['gens']
				);
				?>
			</div>
		</div>

		<?php $autopilot = $data['autopilot'] ?? array(); ?>
		<?php if ( ! empty( $autopilot ) ) : ?>
			<div class="swps-tile">
				<div class="swps-tile-h"><?php esc_html_e( 'Autopilot', 'stratawp-seo' ); ?></div>
				<?php if ( empty( $autopilot['enabled'] ) ) : ?>
					<div class="swps-tile-stat"><?php esc_html_e( 'Off', 'stratawp-seo' ); ?></div>
				<?php else : ?>
					<?php
					$last     = is_array( $autopilot['last_run'] ?? null ) ? $autopilot['last_run'] : array();
					$healthy  = empty( $last ) || empty( $last['failed'] );
					$exceeded = ( $autopilot['budget_state'] ?? 'ok' ) === 'exceeded';
					?>
					<div class="swps-tile-stat">
						<?php
						if ( $exceeded ) {
							esc_html_e( 'Paused — budget', 'stratawp-seo' );
						} elseif ( $healthy ) {
							esc_html_e( 'Healthy', 'stratawp-seo' );
						} else {
							printf(
								/* translators: number of failed generations in the last run */
								esc_html__( '%d failed last run', 'stratawp-seo' ),
								(int) $last['failed']
							);
						}
						?>
					</div>
					<div class="swps-tile-trend" style="color:var(--swps-text-faint)">
						<?php
						if ( ! empty( $last['timestamp'] ) ) {
							printf(
								/* translators: 1: human time diff, 2: succeeded count, 3: failed count */
								esc_html__( 'Last run %1$s ago — %2$d ok, %3$d failed', 'stratawp-seo' ),
								esc_html( human_time_diff( (int) $last['timestamp'], time() ) ),
								(int) ( $last['succeeded'] ?? 0 ),
								(int) ( $last['failed'] ?? 0 )
							);
						}
						if ( ( $autopilot['budget'] ?? 0 ) > 0 ) {
							printf(
								' · %s',
								esc_html(
									sprintf(
										/* translators: 1: spent, 2: budget */
										__( '$%1$s of $%2$s budget', 'stratawp-seo' ),
										number_format_i18n( (float) $autopilot['spent'], 2 ),
										number_format_i18n( (float) $autopilot['budget'], 2 )
									)
								)
							);
						}
						?>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<a class="swps-tile swps-tile-aeo" href="<?php echo esc_url( admin_url( 'admin.php?page=swps-aeo-optimize' ) ); ?>" style="text-decoration:none;color:inherit;display:block">
			<div class="swps-tile-h"><?php esc_html_e( 'AEO health (avg)', 'stratawp-seo' ); ?></div>
			<div class="swps-tile-stat">
				<?php echo $aeo_health['total_scored'] > 0 ? esc_html( (string) $aeo_health['avg_score'] ) : '—'; ?>
			</div>
			<div class="swps-tile-trend" style="color:var(--swps-text-muted)">
				<?php
				if ( $aeo_health['total_scored'] > 0 ) {
					printf(
						/* translators: 1: percent above threshold, 2: total scored. */
						esc_html__( '%1$d%% above threshold · %2$d posts', 'stratawp-seo' ),
						(int) $aeo_health['above_threshold_pct'],
						(int) $aeo_health['total_scored']
					);
				} else {
					esc_html_e( 'Not yet scored', 'stratawp-seo' );
				}
				?>
			</div>
		</a>

	</div>

	<!-- Row 2 — Recent generations + Quick actions -->
	<div class="swps-dash-row swps-dash-row-2">

		<div class="swps-tile">
			<div class="swps-tile-h">
				<span><?php esc_html_e( 'Recent AI generations', 'stratawp-seo' ); ?></span>
			</div>
			<?php if ( empty( $recent ) ) : ?>
				<div class="swps-dash-empty">
					<p><?php esc_html_e( 'No posts generated yet.', 'stratawp-seo' ); ?></p>
					<a class="swps-btn swps-btn-grad" href="<?php echo esc_url( admin_url( 'admin.php?page=swps-generate' ) ); ?>">
						<?php esc_html_e( 'Generate your first post', 'stratawp-seo' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="swps-dash-list">
					<?php
					foreach ( $recent as $r ) :
						$score_class = $r['score'] >= 85 ? 'good' : ( $r['score'] >= 60 ? 'mid' : 'low' );
						?>
						<a class="swps-dash-list-row" href="<?php echo esc_url( $r['edit_link'] ); ?>">
							<div class="swps-dash-list-icon">✦</div>
							<div class="swps-dash-list-main">
								<div class="swps-dash-list-title"><?php echo esc_html( $r['title'] ); ?></div>
								<div class="swps-dash-list-sub">
									<?php
									echo esc_html( ucfirst( $r['template'] ) ) . ' · ';
									printf(
										/* translators: %s: word count formatted */
										esc_html__( '%s words', 'stratawp-seo' ),
										number_format( (int) $r['word_count'] )
									);
									if ( ! empty( $r['model'] ) ) {
										echo ' · ' . esc_html( $r['model'] );
									}
									echo ' · ' . esc_html( $r['status'] ) . ' · ' . esc_html( $r['time_ago'] );
									?>
								</div>
							</div>
							<?php if ( $r['score'] > 0 ) : ?>
								<span class="swps-dash-list-score is-<?php echo esc_attr( $score_class ); ?>">
									<?php echo (int) $r['score']; ?>
								</span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="swps-tile">
			<div class="swps-tile-h"><?php esc_html_e( 'Quick actions', 'stratawp-seo' ); ?></div>
			<div class="swps-dash-actions">
				<a class="swps-dash-action" href="<?php echo esc_url( admin_url( 'admin.php?page=swps-generate' ) ); ?>">
					<div class="swps-dash-action-icon">✦</div>
					<div class="swps-dash-action-label"><?php esc_html_e( 'Generate post', 'stratawp-seo' ); ?></div>
					<div class="swps-dash-action-sub"><?php esc_html_e( 'AI · 60-90s', 'stratawp-seo' ); ?></div>
				</a>
				<a class="swps-dash-action" href="<?php echo esc_url( admin_url( 'admin.php?page=swps-seo-audit' ) ); ?>" data-swps-action="run-audit">
					<div class="swps-dash-action-icon">⚡</div>
					<div class="swps-dash-action-label"><?php esc_html_e( 'Run audit', 'stratawp-seo' ); ?></div>
					<div class="swps-dash-action-sub"><?php esc_html_e( '8 modules', 'stratawp-seo' ); ?></div>
				</a>
				<a class="swps-dash-action" href="<?php echo esc_url( admin_url( 'admin.php?page=swps-auto-optimize' ) ); ?>">
					<div class="swps-dash-action-icon">↻</div>
					<div class="swps-dash-action-label"><?php esc_html_e( 'Auto-Optimize', 'stratawp-seo' ); ?></div>
					<div class="swps-dash-action-sub"><?php esc_html_e( 'Coming soon', 'stratawp-seo' ); ?></div>
				</a>
				<a class="swps-dash-action" href="<?php echo esc_url( admin_url( 'admin.php?page=swps-keywords' ) ); ?>">
					<div class="swps-dash-action-icon">↗</div>
					<div class="swps-dash-action-label"><?php esc_html_e( 'Striking kw', 'stratawp-seo' ); ?></div>
					<div class="swps-dash-action-sub"><?php esc_html_e( 'Pos 8-20', 'stratawp-seo' ); ?></div>
				</a>
			</div>
		</div>

	</div>

	<!-- Row 3 — Issues + Top GSC queries -->
	<div class="swps-dash-row swps-dash-row-2">

		<div class="swps-tile">
			<div class="swps-tile-h"><?php esc_html_e( 'Issues to fix', 'stratawp-seo' ); ?></div>
			<?php if ( empty( $issues ) ) : ?>
				<p style="color:var(--swps-text-muted);font-size:12px;margin:4px 0 0">
					<?php esc_html_e( 'No issues found. Run an audit to refresh.', 'stratawp-seo' ); ?>
				</p>
			<?php else : ?>
				<div>
					<?php foreach ( $issues as $iss ) : ?>
						<div class="swps-dash-issue">
							<span class="swps-pill swps-pill-<?php echo esc_attr( $iss['level'] ); ?>">
								<?php echo $iss['level'] === 'crit' ? esc_html__( 'Critical', 'stratawp-seo' ) : esc_html__( 'Warning', 'stratawp-seo' ); ?>
							</span>
							<div>
								<div class="swps-dash-issue-text"><?php echo esc_html( $iss['name'] ); ?> — <?php echo esc_html( $iss['message'] ); ?></div>
								<a class="swps-dash-issue-cta" href="<?php echo esc_url( $iss['page_url'] ); ?>">
									<?php
									echo $iss['fixable']
										? esc_html__( 'Auto-fix →', 'stratawp-seo' )
										: esc_html__( 'View →', 'stratawp-seo' );
									?>
								</a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="swps-tile">
			<div class="swps-tile-h">
				<span><?php esc_html_e( 'Top GSC queries (7d)', 'stratawp-seo' ); ?></span>
			</div>
			<?php if ( empty( $top_queries ) ) : ?>
				<p style="color:var(--swps-text-muted);font-size:12px;margin:4px 0 0">
					<?php esc_html_e( 'Connect Google Search Console to see top queries.', 'stratawp-seo' ); ?>
				</p>
			<?php else : ?>
				<div class="swps-dash-list">
					<?php foreach ( $top_queries as $q ) : ?>
						<div class="swps-dash-list-row">
							<div class="swps-dash-list-main">
								<div class="swps-dash-list-title"><?php echo esc_html( $q['query'] ?? '' ); ?></div>
								<div class="swps-dash-list-sub">
									<?php echo (int) ( $q['clicks'] ?? 0 ); ?> clicks ·
									<?php echo number_format( (int) ( $q['impressions'] ?? 0 ) ); ?> imp ·
									pos <?php echo number_format( (float) ( $q['position'] ?? 0 ), 1 ); ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

	</div>

	<!-- Row 4 — Competitor watch -->
	<div class="swps-dash-row swps-dash-row-2">
		<div class="swps-tile">
			<div class="swps-tile-h" style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">
				<span><?php esc_html_e( 'Competitor watch', 'stratawp-seo' ); ?></span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-competitors' ) ); ?>"
					style="color:var(--swps-accent-1);font-size:11px;font-weight:600;text-decoration:none">
					<?php esc_html_e( 'Manage →', 'stratawp-seo' ); ?>
				</a>
			</div>
			<?php if ( empty( $competitors ) ) : ?>
				<p style="color:var(--swps-text-muted);font-size:12px;margin:4px 0 0">
					<?php esc_html_e( 'No competitors tracked yet. Add up to 10 sites to monitor for content velocity, schema diff, and homepage changes.', 'stratawp-seo' ); ?>
				</p>
			<?php else : ?>
				<div class="swps-dash-list">
					<?php foreach ( $competitors as $c ) : ?>
						<div class="swps-dash-list-row">
							<div class="swps-dash-list-main">
								<div class="swps-dash-list-title"><?php echo esc_html( $c['label'] ); ?></div>
								<div class="swps-dash-list-sub"><?php echo esc_html( $c['summary'] ); ?></div>
							</div>
							<?php if ( ! empty( $c['has_changes'] ) ) : ?>
								<span class="swps-pill swps-pill-warn"><?php esc_html_e( 'Δ', 'stratawp-seo' ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="swps-tile">
			<div class="swps-tile-h" style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">
				<span><?php esc_html_e( 'Backlinks', 'stratawp-seo' ); ?></span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-backlinks' ) ); ?>"
					style="color:var(--swps-accent-1);font-size:11px;font-weight:600;text-decoration:none">
					<?php esc_html_e( 'Manage →', 'stratawp-seo' ); ?>
				</a>
			</div>
			<?php if ( empty( $bl_recent ) ) : ?>
				<p style="color:var(--swps-text-muted);font-size:12px;margin:4px 0 0">
					<?php esc_html_e( 'No backlinks tracked yet. Add manually or paste a CSV from Google Search Console (Top linking sites).', 'stratawp-seo' ); ?>
				</p>
			<?php else : ?>
				<div class="swps-dash-list">
					<?php
					foreach ( $bl_recent as $bl ) :
						$status_color = 'live' === $bl['status'] ? '#10b981' : ( 'lost' === $bl['status'] ? '#f59e0b' : ( 'broken' === $bl['status'] ? '#ef4444' : '#6b7280' ) );
						?>
						<div class="swps-dash-list-row">
							<div class="swps-dash-list-main">
								<div class="swps-dash-list-title"><?php echo esc_html( $bl['label'] ); ?></div>
								<div class="swps-dash-list-sub">
									<span style="color:<?php echo esc_attr( $status_color ); ?>;font-weight:600;text-transform:uppercase;font-size:10px;letter-spacing:.05em">
										<?php echo esc_html( $bl['status'] ); ?>
									</span>
									<?php if ( ! empty( $bl['when'] ) ) : ?>
										· <?php echo esc_html( human_time_diff( strtotime( $bl['when'] ) ) . ' ago' ); ?>
									<?php endif; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- Modules grid -->
	<div class="swps-dash-section-h">
		<h3><?php esc_html_e( 'Modules', 'stratawp-seo' ); ?></h3>
		<span class="swps-dash-section-h-sub">
			<?php
			$enabled_count = count( array_filter( $modules, fn( $m ) => ! empty( $m['enabled'] ) ) );
			$available     = count( $modules );
			printf(
				/* translators: 1: enabled count, 2: total count */
				esc_html__( 'Toggle features on or off · %1$d enabled, %2$d total', 'stratawp-seo' ),
				$enabled_count,
				$available
			);
			?>
		</span>
	</div>

	<div class="swps-dash-modules">
		<?php
		foreach ( $modules as $mod ) :
			$is_coming = ( ( $mod['badge'] ?? '' ) === 'soon' );
			?>
			<div class="swps-dash-module<?php echo $is_coming ? ' is-coming' : ''; ?>">
				<?php
				if ( ! empty( $mod['badge'] ) ) :
					$bclass = 'swps-badge-' . ( $mod['badge'] === 'soon' ? 'beta' : ( $mod['badge'] === 'ai' ? 'ai' : 'new' ) );
					$blabel = match ( $mod['badge'] ) {
						'soon'  => __( 'Soon', 'stratawp-seo' ),
						'ai'    => __( 'AI New', 'stratawp-seo' ),
						default => __( 'New', 'stratawp-seo' ),
					};
					?>
					<span class="swps-badge <?php echo esc_attr( $bclass ); ?> swps-dash-module-badge">
						<?php echo esc_html( $blabel ); ?>
					</span>
				<?php endif; ?>

				<div class="swps-dash-module-icon"><?php echo esc_html( $mod['icon'] ); ?></div>
				<div class="swps-dash-module-title"><?php echo esc_html( $mod['title'] ); ?></div>
				<div class="swps-dash-module-desc"><?php echo esc_html( $mod['desc'] ); ?></div>
				<div class="swps-dash-module-toggle">
					<?php if ( ! empty( $mod['settings_url'] ) ) : ?>
						<a class="swps-dash-module-link" href="<?php echo esc_url( $mod['settings_url'] ); ?>">
							<?php esc_html_e( 'Settings →', 'stratawp-seo' ); ?>
						</a>
					<?php else : ?>
						<span class="swps-dash-module-link" style="opacity:.5">
							<?php esc_html_e( '— ', 'stratawp-seo' ); ?>
						</span>
					<?php endif; ?>
					<button type="button"
						class="swps-toggle swps-toggle-sm <?php echo ! empty( $mod['enabled'] ) ? 'is-on' : ''; ?>"
						data-swps-module-toggle="<?php echo esc_attr( $mod['slug'] ); ?>"
						data-swps-locked="<?php echo ! empty( $mod['locked'] ) ? '1' : '0'; ?>"
						aria-checked="<?php echo ! empty( $mod['enabled'] ) ? 'true' : 'false'; ?>"
						aria-label="<?php echo esc_attr( sprintf( __( 'Toggle %s', 'stratawp-seo' ), $mod['title'] ) ); ?>"
						<?php disabled( ! empty( $mod['locked'] ) ); ?>
					></button>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

</div>
