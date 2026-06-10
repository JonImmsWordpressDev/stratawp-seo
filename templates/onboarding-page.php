<?php
/**
 * Onboarding wizard page — five-step vertical stepper.
 *
 * Wrapped by SWPS_Admin_Shell. Uses the page-header partial like other pages.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$swps_state    = SWPS_Onboarding::normalize_state( get_option( SWPS_Onboarding::OPTION_STATE ) );
$swps_progress = SWPS_Onboarding::progress( $swps_state );
$swps_steps    = $swps_state['steps'];

$swps_sources       = stratawp_seo()->migration->detect_sources();
$swps_providers     = SWPS_Provider_Factory::get_ai_provider_options();
$swps_provider_now  = get_option( 'swps_ai_provider', 'anthropic' );
$swps_has_key       = '' !== SWPS_Provider_Factory::create_ai_provider()->get_api_key();
$swps_description   = (string) get_option( 'swps_site_description', '' );
$swps_audit_results = stratawp_seo()->seo_audit->get_cached_results();

$swps_issue_count = 0;
foreach ( ( $swps_audit_results['modules'] ?? array() ) as $swps_mod ) {
	if ( 'pass' !== ( $swps_mod['status'] ?? 'fail' ) ) {
		++$swps_issue_count;
	}
}

$swps_migration_url = admin_url( 'admin.php?page=swps-migration' );
$swps_audit_url     = admin_url( 'admin.php?page=swps-seo-audit' );
$swps_generate_url  = admin_url( 'admin.php?page=swps-generate' );
$swps_schema_url    = admin_url( 'admin.php?page=swps-search-appearance' );
$swps_crawl_url     = admin_url( 'admin.php?page=swps-crawl-files' );
?>
<div class="wrap swps-onboarding-wrap">

	<?php
	// The page-header partial expects these exact variable names.
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$title = __( 'Setup Wizard', 'stratawp-seo' );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$subtitle = sprintf(
		/* translators: 1: completed steps, 2: total steps */
		esc_html__( 'Five minutes to a fully configured site — %1$d of %2$d steps done.', 'stratawp-seo' ),
		(int) $swps_progress['done'],
		(int) $swps_progress['total']
	);
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<?php if ( $swps_progress['complete'] ) : ?>
		<!-- Win panel — all steps complete. -->
		<div class="swps-ob-win swps-card">
			<h2><?php esc_html_e( 'Setup complete — nice work!', 'stratawp-seo' ); ?></h2>
			<?php if ( $swps_issue_count > 0 ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of audit issues */
						esc_html( _n( 'Your audit found %d issue you can fix right now.', 'Your audit found %d issues you can fix right now.', $swps_issue_count, 'stratawp-seo' ) ),
						(int) $swps_issue_count
					);
					?>
				</p>
				<a class="swps-btn swps-btn-grad" href="<?php echo esc_url( $swps_audit_url ); ?>"><?php esc_html_e( 'Fix now', 'stratawp-seo' ); ?></a>
			<?php else : ?>
				<p><?php esc_html_e( 'Your audit is clean. Generate your first AI post to put the setup to work.', 'stratawp-seo' ); ?></p>
				<a class="swps-btn swps-btn-grad" href="<?php echo esc_url( $swps_generate_url ); ?>"><?php esc_html_e( 'Generate a post', 'stratawp-seo' ); ?></a>
			<?php endif; ?>
			<p class="swps-ob-win-pointers">
				<?php esc_html_e( 'Go deeper:', 'stratawp-seo' ); ?>
				<a href="<?php echo esc_url( $swps_schema_url ); ?>"><?php esc_html_e( 'Schema & search appearance', 'stratawp-seo' ); ?></a>
				&middot;
				<a href="<?php echo esc_url( $swps_crawl_url ); ?>"><?php esc_html_e( 'llms.txt & robots.txt', 'stratawp-seo' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<div class="swps-ob-stepper" id="swps-ob-stepper">

		<!-- Step 1: Migrate -->
		<div class="swps-ob-step swps-card<?php echo $swps_steps['migrate'] ? ' is-done' : ''; ?>"
			data-step="migrate"
			<?php echo ( empty( $swps_sources ) && ! $swps_steps['migrate'] ) ? 'data-auto-skip="1"' : ''; ?>>
			<div class="swps-ob-step-head">
				<span class="swps-ob-step-num">1</span>
				<h2><?php esc_html_e( 'Migrate from your old SEO plugin', 'stratawp-seo' ); ?></h2>
				<span class="swps-ob-step-check" aria-hidden="true">&#10003;</span>
			</div>
			<div class="swps-ob-step-body">
				<?php if ( empty( $swps_sources ) ) : ?>
					<p><?php esc_html_e( 'Nothing to migrate — no Yoast or Rank Math data was detected. This step completes itself.', 'stratawp-seo' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'We detected SEO data you can import:', 'stratawp-seo' ); ?></p>
					<ul class="swps-ob-sources">
						<?php foreach ( $swps_sources as $swps_slug => $swps_src ) : ?>
							<li>
								<strong><?php echo esc_html( $swps_src['label'] ); ?></strong>
								&mdash;
								<?php
								printf(
									/* translators: %d: number of posts with importable SEO data */
									esc_html( _n( '%d post with SEO data', '%d posts with SEO data', (int) $swps_src['post_count'], 'stratawp-seo' ) ),
									(int) $swps_src['post_count']
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p>
						<a class="swps-btn swps-btn-grad" href="<?php echo esc_url( $swps_migration_url ); ?>"><?php esc_html_e( 'Open the Migration tool', 'stratawp-seo' ); ?></a>
						<button type="button" class="swps-btn swps-btn-secondary swps-ob-refresh-detect"><?php esc_html_e( 'Refresh detection', 'stratawp-seo' ); ?></button>
						<button type="button" class="swps-btn swps-btn-secondary swps-ob-step-complete" data-step="migrate"><?php esc_html_e( "I'm done migrating", 'stratawp-seo' ); ?></button>
					</p>
				<?php endif; ?>
			</div>
			<a href="#" class="swps-ob-skip" data-step="migrate"><?php esc_html_e( 'Skip this step', 'stratawp-seo' ); ?></a>
		</div>

		<!-- Step 2: API key -->
		<div class="swps-ob-step swps-card<?php echo $swps_steps['api_key'] ? ' is-done' : ''; ?>" data-step="api_key">
			<div class="swps-ob-step-head">
				<span class="swps-ob-step-num">2</span>
				<h2><?php esc_html_e( 'Connect your AI provider', 'stratawp-seo' ); ?></h2>
				<span class="swps-ob-step-check" aria-hidden="true">&#10003;</span>
			</div>
			<div class="swps-ob-step-body">
				<?php if ( $swps_has_key ) : ?>
					<p class="swps-ob-note is-ok"><?php esc_html_e( 'An API key is already saved — test a new one below to replace it, or skip ahead.', 'stratawp-seo' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Paste an API key and we will validate it with a live request before saving.', 'stratawp-seo' ); ?></p>
				<?php endif; ?>
				<div class="swps-ob-field-row">
					<select id="swps-ob-provider">
						<?php foreach ( $swps_providers as $swps_slug => $swps_label ) : ?>
							<option value="<?php echo esc_attr( $swps_slug ); ?>" <?php selected( $swps_provider_now, $swps_slug ); ?>><?php echo esc_html( $swps_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="password" id="swps-ob-api-key" autocomplete="off"
						placeholder="<?php esc_attr_e( 'Paste your API key', 'stratawp-seo' ); ?>" />
					<button type="button" class="swps-btn swps-btn-grad" id="swps-ob-test-key"><?php esc_html_e( 'Test & Save', 'stratawp-seo' ); ?></button>
				</div>
				<p class="swps-ob-msg" id="swps-ob-key-msg" role="status"></p>
			</div>
			<a href="#" class="swps-ob-skip" data-step="api_key"><?php esc_html_e( 'Skip this step', 'stratawp-seo' ); ?></a>
		</div>

		<!-- Step 3: Site info -->
		<div class="swps-ob-step swps-card<?php echo $swps_steps['site_info'] ? ' is-done' : ''; ?>" data-step="site_info">
			<div class="swps-ob-step-head">
				<span class="swps-ob-step-num">3</span>
				<h2><?php esc_html_e( 'Describe your site', 'stratawp-seo' ); ?></h2>
				<span class="swps-ob-step-check" aria-hidden="true">&#10003;</span>
			</div>
			<div class="swps-ob-step-body">
				<p><?php esc_html_e( 'A short description helps the AI write content that fits your site. Let AI draft one from your existing posts, then edit and save.', 'stratawp-seo' ); ?></p>
				<textarea id="swps-ob-description" rows="3"
					placeholder="<?php esc_attr_e( 'e.g. A blog about home coffee roasting for hobbyists.', 'stratawp-seo' ); ?>"><?php echo esc_textarea( $swps_description ); ?></textarea>
				<p>
					<button type="button" class="swps-btn swps-btn-secondary" id="swps-ob-suggest"><?php esc_html_e( 'Suggest with AI', 'stratawp-seo' ); ?></button>
					<button type="button" class="swps-btn swps-btn-grad" id="swps-ob-save-info"><?php esc_html_e( 'Save description', 'stratawp-seo' ); ?></button>
				</p>
				<p class="swps-ob-msg" id="swps-ob-info-msg" role="status"></p>
			</div>
			<a href="#" class="swps-ob-skip" data-step="site_info"><?php esc_html_e( 'Skip this step', 'stratawp-seo' ); ?></a>
		</div>

		<!-- Step 4: Audit -->
		<div class="swps-ob-step swps-card<?php echo $swps_steps['audit'] ? ' is-done' : ''; ?>" data-step="audit">
			<div class="swps-ob-step-head">
				<span class="swps-ob-step-num">4</span>
				<h2><?php esc_html_e( 'Run your first SEO audit', 'stratawp-seo' ); ?></h2>
				<span class="swps-ob-step-check" aria-hidden="true">&#10003;</span>
			</div>
			<div class="swps-ob-step-body">
				<p><?php esc_html_e( 'A full technical check of your site — titles, sitemaps, schema, performance signals, and more.', 'stratawp-seo' ); ?></p>
				<p>
					<button type="button" class="swps-btn swps-btn-grad" id="swps-ob-run-audit"><?php esc_html_e( 'Run audit', 'stratawp-seo' ); ?></button>
					<span class="swps-ob-spinner" id="swps-ob-audit-spinner" aria-hidden="true"></span>
				</p>
				<p class="swps-ob-msg" id="swps-ob-audit-msg" role="status"></p>
			</div>
			<a href="#" class="swps-ob-skip" data-step="audit"><?php esc_html_e( 'Skip this step', 'stratawp-seo' ); ?></a>
		</div>

		<!-- Step 5: Preview post -->
		<div class="swps-ob-step swps-card<?php echo $swps_steps['preview'] ? ' is-done' : ''; ?>" data-step="preview">
			<div class="swps-ob-step-head">
				<span class="swps-ob-step-num">5</span>
				<h2><?php esc_html_e( 'Generate a preview post', 'stratawp-seo' ); ?></h2>
				<span class="swps-ob-step-check" aria-hidden="true">&#10003;</span>
			</div>
			<div class="swps-ob-step-body">
				<p><?php esc_html_e( 'See what the generator produces for your site. Leave the topic blank to let the AI pick one.', 'stratawp-seo' ); ?></p>
				<div class="swps-ob-field-row">
					<input type="text" id="swps-ob-topic" placeholder="<?php esc_attr_e( 'Optional topic', 'stratawp-seo' ); ?>" />
					<button type="button" class="swps-btn swps-btn-grad" id="swps-ob-preview"><?php esc_html_e( 'Generate preview', 'stratawp-seo' ); ?></button>
					<span class="swps-ob-spinner" id="swps-ob-preview-spinner" aria-hidden="true"></span>
				</div>
				<p class="swps-ob-cost-note"><?php esc_html_e( 'Uses your API key — one generation.', 'stratawp-seo' ); ?></p>
				<p class="swps-ob-msg" id="swps-ob-preview-msg" role="status"></p>
				<div class="swps-ob-preview-card" id="swps-ob-preview-card" hidden>
					<h3 id="swps-ob-preview-title"></h3>
					<p class="swps-ob-preview-meta" id="swps-ob-preview-meta"></p>
					<div class="swps-ob-preview-content" id="swps-ob-preview-content"></div>
				</div>
			</div>
			<a href="#" class="swps-ob-skip" data-step="preview"><?php esc_html_e( 'Skip this step', 'stratawp-seo' ); ?></a>
		</div>

	</div>
</div>
