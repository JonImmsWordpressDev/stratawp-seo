<?php
$has_api_key = ! empty( SWPS_Provider_Factory::create_ai_provider()->get_api_key() );
$has_niche   = ! empty( get_option( 'swps_site_niche' ) );
$schedule    = SWPS_Cron::get_schedule_info();
$cost_stats  = stratawp_seo()->cost_tracker->get_monthly_stats();
$templates   = SWPS_Templates::get_options();

// "What happens when you generate" summary — surfaces the otherwise-hidden
// settings (model, where it lands, length, images, style) so users aren't
// generating blind. See issue #74.
$gen_provider_slug = (string) get_option( 'swps_ai_provider', 'anthropic' );
$gen_provider_name = SWPS_Provider_Factory::create_ai_provider()->get_name();
$gen_model_id      = (string) get_option( 'swps_model', '' );
$gen_models        = SWPS_Provider_Factory::get_models_for_provider( $gen_provider_slug );
$gen_model_label   = '' !== $gen_model_id ? ( $gen_models[ $gen_model_id ] ?? $gen_model_id ) : __( 'Provider default', 'stratawp-seo' );
$gen_status        = (string) get_option( 'swps_post_status', 'draft' );
$gen_status_labels = array(
	'draft'   => __( 'Saved as a draft for your review — not published', 'stratawp-seo' ),
	'pending' => __( 'Saved as Pending Review for your approval', 'stratawp-seo' ),
	'publish' => __( 'Published immediately (live on your site)', 'stratawp-seo' ),
);
$gen_status_label  = $gen_status_labels[ $gen_status ] ?? ucfirst( $gen_status );
$gen_min_words     = (int) get_option( 'swps_word_count_min', 1200 );
$gen_max_words     = (int) get_option( 'swps_word_count_max', 2000 );
$gen_images_on     = (bool) get_option( 'swps_featured_images', false );
$gen_tone          = (string) get_option( 'swps_tone', 'professional' );
$gen_style         = trim( (string) get_option( 'swps_writing_style', '' ) );
$gen_tone_label    = '' !== $gen_style
	? ucfirst( $gen_tone ) . ' — ' . $gen_style
	: ucfirst( $gen_tone );
$gen_settings_url  = admin_url( 'admin.php?page=swps-settings' );
?>
<div class="wrap swps-generate-wrap">
	<?php
	$title    = __( 'Generate Content', 'stratawp-seo' );
	$subtitle = __( 'Create AI-powered, SEO-optimized blog posts. Enter a topic or let the AI pick one based on your site\'s gaps.', 'stratawp-seo' );
	$actions  = array();
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<?php if ( ! $has_api_key ) : ?>
		<div class="notice notice-error">
			<p>
			<?php
			printf(
				__( 'You need to <a href="%s">add your AI provider API key</a> before generating content.', 'stratawp-seo' ),
				esc_url( admin_url( 'admin.php?page=swps-settings' ) )
			);
			?>
			</p>
		</div>
	<?php endif; ?>

	<div class="swps-generate-grid">

		<!-- Generate Card -->
		<div class="swps-card swps-card-generate">
			<h2><?php esc_html_e( 'Generate a New Post', 'stratawp-seo' ); ?></h2>
			<p class="swps-card-desc"><?php esc_html_e( 'Create an SEO-optimized blog post. Enter a specific topic or let the AI choose one based on your site\'s content gaps.', 'stratawp-seo' ); ?></p>

			<div class="swps-form-group">
				<label for="swps-topic"><?php esc_html_e( 'Topic (optional)', 'stratawp-seo' ); ?></label>
				<input
					type="text"
					id="swps-topic"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g., How to Choose the Right Kitchen Countertop — or leave blank for AI suggestion', 'stratawp-seo' ); ?>"
					<?php echo ! $has_api_key ? 'disabled' : ''; ?>
				/>
			</div>

			<div class="swps-form-row">
				<div class="swps-form-group swps-form-group-inline">
					<label for="swps-template"><?php esc_html_e( 'Template', 'stratawp-seo' ); ?></label>
					<select id="swps-template" <?php echo ! $has_api_key ? 'disabled' : ''; ?>>
						<?php foreach ( $templates as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="swps-form-group swps-form-group-inline">
					<label for="swps-bulk-count"><?php esc_html_e( 'Bulk Count', 'stratawp-seo' ); ?></label>
					<input type="number" id="swps-bulk-count" value="1" min="1" max="5" class="small-text" <?php echo ! $has_api_key ? 'disabled' : ''; ?> />
				</div>
			</div>

			<!-- Rate limit indicator -->
			<div id="swps-rate-limit" class="swps-rate-limit" style="display: none;"></div>

			<!-- "What happens when you generate" summary (issue #74) -->
			<div class="swps-gen-summary">
				<p class="swps-gen-summary-title">
					<span class="dashicons dashicons-info-outline"></span>
					<?php esc_html_e( 'What happens when you generate', 'stratawp-seo' ); ?>
				</p>
				<table class="swps-info-table">
					<tr>
						<td><?php esc_html_e( 'Written by:', 'stratawp-seo' ); ?></td>
						<td><?php echo esc_html( $gen_provider_name . ' — ' . $gen_model_label ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'After writing:', 'stratawp-seo' ); ?></td>
						<td><?php echo esc_html( $gen_status_label ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Length:', 'stratawp-seo' ); ?></td>
						<td>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: minimum word count, 2: maximum word count. */
								__( '~%1$s–%2$s words', 'stratawp-seo' ),
								number_format_i18n( $gen_min_words ),
								number_format_i18n( $gen_max_words )
							)
						);
						?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Featured image:', 'stratawp-seo' ); ?></td>
						<td><?php echo $gen_images_on ? esc_html__( 'Added automatically', 'stratawp-seo' ) : esc_html__( 'Not added', 'stratawp-seo' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Tone / style:', 'stratawp-seo' ); ?></td>
						<td><?php echo esc_html( $gen_tone_label ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Language:', 'stratawp-seo' ); ?></td>
						<td><?php esc_html_e( 'English', 'stratawp-seo' ); ?></td>
					</tr>
				</table>
				<p class="swps-gen-summary-foot">
					<?php
					printf(
						/* translators: %s: settings page URL. */
						wp_kses(
							__( 'These come from your <a href="%s">content settings</a>. Generating calls your AI provider and uses API credits. <strong>Preview</strong> shows a draft without saving; <strong>Generate Post</strong> creates the post above.', 'stratawp-seo' ),
							array(
								'a'      => array( 'href' => array() ),
								'strong' => array(),
							)
						),
						esc_url( $gen_settings_url )
					);
					?>
				</p>
			</div>

			<div class="swps-generate-actions">
				<button
					type="button"
					id="swps-generate-btn"
					class="button button-primary button-hero"
					title="<?php esc_attr_e( 'Writes one new SEO-optimized post using the settings shown above, then saves it per your “After writing” setting. Uses API credits.', 'stratawp-seo' ); ?>"
					<?php echo ! $has_api_key ? 'disabled' : ''; ?>
				>
					<span class="dashicons dashicons-edit-large" style="margin-top: 4px;"></span>
					<?php esc_html_e( 'Generate Post', 'stratawp-seo' ); ?>
				</button>

				<button
					type="button"
					id="swps-preview-btn"
					class="button button-secondary button-hero"
					title="<?php esc_attr_e( 'Generates a sample article and shows it in a popup without saving anything. Uses API credits.', 'stratawp-seo' ); ?>"
					<?php echo ! $has_api_key ? 'disabled' : ''; ?>
				>
					<span class="dashicons dashicons-visibility" style="margin-top: 4px;"></span>
					<?php esc_html_e( 'Preview', 'stratawp-seo' ); ?>
				</button>

				<button
					type="button"
					id="swps-bulk-btn"
					class="button button-secondary"
					<?php echo ! $has_api_key ? 'disabled' : ''; ?>
				>
					<span class="dashicons dashicons-controls-repeat" style="margin-top: 4px;"></span>
					<?php esc_html_e( 'Bulk Generate', 'stratawp-seo' ); ?>
				</button>

				<button
					type="button"
					id="swps-analyze-btn"
					class="button button-secondary"
					<?php echo ! $has_api_key ? 'disabled' : ''; ?>
				>
					<span class="dashicons dashicons-chart-bar" style="margin-top: 4px;"></span>
					<?php esc_html_e( 'Analyze My Site', 'stratawp-seo' ); ?>
				</button>
			</div>

			<!-- Progress indicator -->
			<div id="swps-progress" class="swps-progress" style="display: none;">
				<div class="swps-spinner"></div>
				<div class="swps-progress-text">
					<strong id="swps-progress-title"><?php esc_html_e( 'Generating your post...', 'stratawp-seo' ); ?></strong>
					<p id="swps-progress-desc"><?php esc_html_e( 'The AI is analyzing your site content and crafting an optimized post. This usually takes 30-60 seconds.', 'stratawp-seo' ); ?></p>
				</div>
			</div>

			<!-- Error display -->
			<div id="swps-error" class="notice notice-error" style="display: none;">
				<p id="swps-error-message"></p>
			</div>
		</div>

		<!-- Schedule Info Card -->
		<div class="swps-card swps-card-schedule">
			<h2><?php esc_html_e( 'Auto-Publishing', 'stratawp-seo' ); ?></h2>
			<?php if ( $schedule['enabled'] ) : ?>
				<div class="swps-schedule-active">
					<span class="swps-status-dot swps-status-active"></span>
					<strong><?php esc_html_e( 'Active', 'stratawp-seo' ); ?></strong>
				</div>
				<table class="swps-info-table">
					<tr>
						<td><?php esc_html_e( 'Frequency:', 'stratawp-seo' ); ?></td>
						<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $schedule['frequency'] ) ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Next run:', 'stratawp-seo' ); ?></td>
						<td><?php echo esc_html( $schedule['next_run'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Last run:', 'stratawp-seo' ); ?></td>
						<td><?php echo esc_html( $schedule['last_run'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Posts per run:', 'stratawp-seo' ); ?></td>
						<td><?php echo esc_html( $schedule['posts_per'] ); ?></td>
					</tr>
				</table>
			<?php else : ?>
				<div class="swps-schedule-inactive">
					<span class="swps-status-dot swps-status-inactive"></span>
					<strong><?php esc_html_e( 'Inactive', 'stratawp-seo' ); ?></strong>
					<p>
					<?php
					printf(
						__( 'Enable automated posting in <a href="%s">Settings</a>.', 'stratawp-seo' ),
						esc_url( admin_url( 'admin.php?page=swps-settings' ) )
					);
					?>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<!-- Site Stats Card -->
		<div class="swps-card swps-card-stats">
			<h2><?php esc_html_e( 'Site Overview', 'stratawp-seo' ); ?></h2>
			<?php
			$post_count = wp_count_posts( 'post' );
			$generated  = new WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'meta_key'       => '_swps_generated',
					'meta_value'     => '1',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			?>
			<div class="swps-stats-grid">
				<div class="swps-stat">
					<span class="swps-stat-number"><?php echo esc_html( $post_count->publish ); ?></span>
					<span class="swps-stat-label"><?php esc_html_e( 'Published Posts', 'stratawp-seo' ); ?></span>
				</div>
				<div class="swps-stat">
					<span class="swps-stat-number"><?php echo esc_html( $post_count->draft ); ?></span>
					<span class="swps-stat-label"><?php esc_html_e( 'Drafts', 'stratawp-seo' ); ?></span>
				</div>
				<div class="swps-stat">
					<span class="swps-stat-number"><?php echo esc_html( $generated->found_posts ); ?></span>
					<span class="swps-stat-label"><?php esc_html_e( 'AI Generated', 'stratawp-seo' ); ?></span>
				</div>
			</div>
		</div>

		<?php if ( get_option( 'swps_cost_tracking', false ) ) : ?>
		<!-- Cost Stats Card -->
		<div class="swps-card swps-card-cost">
			<h2><?php esc_html_e( 'Cost This Month', 'stratawp-seo' ); ?></h2>
			<div class="swps-stats-grid">
				<div class="swps-stat">
					<span class="swps-stat-number">$<?php echo esc_html( number_format( $cost_stats['total_cost'], 2 ) ); ?></span>
					<span class="swps-stat-label"><?php esc_html_e( 'Spent', 'stratawp-seo' ); ?></span>
				</div>
				<div class="swps-stat">
					<span class="swps-stat-number"><?php echo esc_html( $cost_stats['generation_count'] ); ?></span>
					<span class="swps-stat-label"><?php esc_html_e( 'Generations', 'stratawp-seo' ); ?></span>
				</div>
				<div class="swps-stat">
					<span class="swps-stat-number"><?php echo esc_html( number_format( $cost_stats['total_input_tokens'] + $cost_stats['total_output_tokens'] ) ); ?></span>
					<span class="swps-stat-label"><?php esc_html_e( 'Tokens', 'stratawp-seo' ); ?></span>
				</div>
			</div>
		</div>
		<?php endif; ?>

	</div>

	<!-- Results section -->
	<div id="swps-result" class="swps-card swps-card-result" style="display: none;">
		<h2>
			<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
			<?php esc_html_e( 'Post Generated Successfully!', 'stratawp-seo' ); ?>
		</h2>

		<table class="swps-result-table">
			<tr>
				<td><strong><?php esc_html_e( 'Title:', 'stratawp-seo' ); ?></strong></td>
				<td id="swps-result-title"></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Status:', 'stratawp-seo' ); ?></strong></td>
				<td id="swps-result-status"></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Focus Keyword:', 'stratawp-seo' ); ?></strong></td>
				<td id="swps-result-keyword"></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Meta Description:', 'stratawp-seo' ); ?></strong></td>
				<td id="swps-result-meta"></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Word Count:', 'stratawp-seo' ); ?></strong></td>
				<td id="swps-result-words"></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Internal Links:', 'stratawp-seo' ); ?></strong></td>
				<td id="swps-result-links"></td>
			</tr>
			<tr id="swps-result-cost-row" style="display: none;">
				<td><strong><?php esc_html_e( 'Cost:', 'stratawp-seo' ); ?></strong></td>
				<td id="swps-result-cost"></td>
			</tr>
			<tr id="swps-result-score-row" style="display: none;">
				<td><strong><?php esc_html_e( 'Content Score:', 'stratawp-seo' ); ?></strong></td>
				<td id="swps-result-score"></td>
			</tr>
		</table>

		<div class="swps-result-actions">
			<a id="swps-result-edit" href="#" class="button button-primary" target="_blank">
				<?php esc_html_e( 'Edit Post', 'stratawp-seo' ); ?>
			</a>
			<a id="swps-result-preview" href="#" class="button" target="_blank">
				<?php esc_html_e( 'Preview Post', 'stratawp-seo' ); ?>
			</a>
			<button type="button" id="swps-generate-another" class="button">
				<?php esc_html_e( 'Generate Another', 'stratawp-seo' ); ?>
			</button>
		</div>
	</div>

	<!-- Site Analysis Results -->
	<div id="swps-analysis" class="swps-card" style="display: none;">
		<h2><?php esc_html_e( 'Site Content Analysis', 'stratawp-seo' ); ?></h2>
		<pre id="swps-analysis-data" class="swps-code-block"></pre>
	</div>

	<!-- Preview Modal -->
	<div id="swps-preview-modal" class="swps-modal" style="display: none;">
		<div class="swps-modal-overlay"></div>
		<div class="swps-modal-dialog swps-modal-large">
			<div class="swps-modal-header">
				<h2 id="swps-preview-title"><?php esc_html_e( 'Post Preview', 'stratawp-seo' ); ?></h2>
				<button type="button" class="swps-modal-close" aria-label="Close">&times;</button>
			</div>
			<div class="swps-modal-body">
				<div class="swps-preview-meta">
					<p><strong><?php esc_html_e( 'Focus Keyword:', 'stratawp-seo' ); ?></strong> <span id="swps-preview-keyword"></span></p>
					<p><strong><?php esc_html_e( 'Meta Description:', 'stratawp-seo' ); ?></strong> <span id="swps-preview-meta"></span></p>
				</div>
				<div id="swps-preview-content" class="swps-preview-content"></div>
			</div>
			<div class="swps-modal-footer">
				<button type="button" id="swps-preview-publish" class="button button-primary">
					<span class="dashicons dashicons-yes" style="margin-top: 4px;"></span>
					<?php esc_html_e( 'Publish This', 'stratawp-seo' ); ?>
				</button>
				<button type="button" class="button swps-modal-close">
					<?php esc_html_e( 'Close', 'stratawp-seo' ); ?>
				</button>
			</div>
		</div>
	</div>

</div>
