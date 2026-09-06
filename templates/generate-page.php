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
$gen_status_labels_page = array(
	'draft'   => __( 'Saved as a draft page for your review (not published)', 'stratawp-seo' ),
	'pending' => __( 'Saved as a Pending Review page for your approval', 'stratawp-seo' ),
	'publish' => __( 'Published immediately as a page (live on your site)', 'stratawp-seo' ),
);
$gen_status_label_page = $gen_status_labels_page[ $gen_status ] ?? ucfirst( $gen_status );
$gen_min_words     = (int) get_option( 'swps_word_count_min', 1200 );
$gen_max_words     = (int) get_option( 'swps_word_count_max', 2000 );
$gen_tone          = (string) get_option( 'swps_tone', 'professional' );
$gen_style         = trim( (string) get_option( 'swps_writing_style', '' ) );
// A custom writing style can be a long paragraph; show only a short preview so
// the summary stays compact (the full style still applies during generation).
$gen_style_preview = '' !== $gen_style ? wp_trim_words( $gen_style, 16, '…' ) : '';
$gen_tone_label    = '' !== $gen_style_preview
	? ucfirst( $gen_tone ) . ' — ' . $gen_style_preview
	: ucfirst( $gen_tone );
$gen_settings_url  = admin_url( 'admin.php?page=swps-settings' );

// Custom content brief (optional, multiline) and its "help me write my brief" fields.
$gen_brief_max         = SWPS_Content_Brief::MAX_BRIEF_LENGTH;
$gen_brief_field_max   = SWPS_Content_Brief::MAX_FIELD_LENGTH;
$gen_brief_tones       = SWPS_Settings::get_tone_options();
$gen_brief_placeholder = __( 'Write a guide for small business owners in Omaha about choosing a WordPress maintenance provider. Include updates, backups, security, hosting, and support. Explain what questions to ask before hiring someone. Use a friendly, practical tone and finish with a consultation CTA. Avoid technical jargon and made-up pricing.', 'stratawp-seo' );

// Content type, per-run images and source material.
$gen_image_defaults   = SWPS_Image_Plan::defaults_from_settings();
$gen_image_provider   = SWPS_Provider_Factory::create_image_provider();
$gen_images_available = ! $gen_image_provider->requires_api_key() || '' !== (string) $gen_image_provider->get_api_key();
$gen_sources_max      = SWPS_Source_Material::MAX_TEXT;
$gen_parent_dropdown  = wp_dropdown_pages(
	array(
		'name'              => 'swps_parent',
		'id'                => 'swps-parent',
		'show_option_none'  => __( 'None (top level)', 'stratawp-seo' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- passed to wp_dropdown_pages(), escaped internally by core.
		'option_none_value' => '0',
		'post_status'       => array( 'publish', 'draft', 'pending', 'private' ),
		'sort_column'       => 'menu_order, post_title',
		'echo'              => 0,
	)
);
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
			<h2 id="swps-card-title" data-post="<?php esc_attr_e( 'Generate a New Post', 'stratawp-seo' ); ?>" data-page="<?php esc_attr_e( 'Generate a New Page', 'stratawp-seo' ); ?>"><?php esc_html_e( 'Generate a New Post', 'stratawp-seo' ); ?></h2>
			<p class="swps-card-desc" id="swps-card-desc" data-post="<?php esc_attr_e( 'Create an SEO-optimized blog post. Enter a specific topic or let the AI choose one based on your site\'s content gaps.', 'stratawp-seo' ); ?>" data-page="<?php esc_attr_e( 'Create an SEO-optimized page. Describe the service, offer, place or story it should tell, or leave the topic blank and let the AI pick a page your site is missing.', 'stratawp-seo' ); ?>"><?php esc_html_e( 'Create an SEO-optimized blog post. Enter a specific topic or let the AI choose one based on your site\'s content gaps.', 'stratawp-seo' ); ?></p>

			<?php /* Content type: post (default) or page. Drives template list, parent picker, bulk visibility and labels. */ ?>
			<fieldset id="swps-content-type" class="swps-segmented" <?php echo ! $has_api_key ? 'disabled' : ''; ?>>
				<legend class="swps-segmented-legend"><?php esc_html_e( 'What are you creating?', 'stratawp-seo' ); ?></legend>
				<label class="swps-segmented-option">
					<input type="radio" name="swps_content_type" value="post" checked />
					<span class="dashicons dashicons-admin-post" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Blog post', 'stratawp-seo' ); ?></span>
				</label>
				<label class="swps-segmented-option">
					<input type="radio" name="swps_content_type" value="page" />
					<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Page', 'stratawp-seo' ); ?></span>
				</label>
			</fieldset>

			<?php /* Custom content brief — optional; a blank brief keeps the original topic-only flow. */ ?>
			<div class="swps-form-group swps-brief-group">
				<label for="swps-brief"><?php esc_html_e( 'What would you like to write about?', 'stratawp-seo' ); ?></label>
				<p id="swps-brief-help" class="swps-field-help"><?php esc_html_e( 'Describe your topic, what to include, who it\'s for, and anything to avoid. StrataWP SEO will use your instructions while keeping the content optimized for search.', 'stratawp-seo' ); ?></p>
				<textarea
					id="swps-brief"
					class="swps-brief-textarea"
					rows="6"
					maxlength="<?php echo (int) $gen_brief_max; ?>"
					aria-describedby="swps-brief-help swps-brief-count"
					placeholder="<?php echo esc_attr( $gen_brief_placeholder ); ?>"
					<?php echo ! $has_api_key ? 'disabled' : ''; ?>
				></textarea>
				<div class="swps-brief-meta">
					<span id="swps-brief-count" class="swps-brief-count" aria-live="polite"></span>
					<span class="swps-brief-optional"><?php esc_html_e( 'Optional — leave blank to let the AI pick a topic from your site\'s content gaps.', 'stratawp-seo' ); ?></span>
				</div>
			</div>

			<?php /* Source material — optional URLs and/or notes the AI grounds facts in and cites. */ ?>
			<div class="swps-form-group swps-sources-group">
				<label for="swps-sources"><?php esc_html_e( 'Source material (optional)', 'stratawp-seo' ); ?></label>
				<p id="swps-sources-help" class="swps-field-help"><?php esc_html_e( 'Paste up to 5 URLs (one per line) and/or your own notes. The AI bases its facts on this material and cites the URLs as its external links.', 'stratawp-seo' ); ?></p>
				<textarea
					id="swps-sources"
					class="swps-brief-textarea swps-sources-textarea"
					rows="4"
					maxlength="<?php echo (int) $gen_sources_max; ?>"
					aria-describedby="swps-sources-help swps-sources-count"
					placeholder="<?php esc_attr_e( "https://example.com/pricing\nhttps://example.com/about\nNotes: we have served Omaha since 2015; 24-hour response; no long-term contracts.", 'stratawp-seo' ); ?>"
					<?php echo ! $has_api_key ? 'disabled' : ''; ?>
				></textarea>
				<div class="swps-brief-meta">
					<span id="swps-sources-count" class="swps-brief-count" aria-live="polite"></span>
					<span class="swps-brief-optional"><?php esc_html_e( 'Fetching happens when you generate or preview; failures are reported, never fatal.', 'stratawp-seo' ); ?></span>
				</div>
			</div>

			<details id="swps-brief-helper" class="swps-brief-helper">
				<summary class="swps-brief-helper-summary">
					<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
					<?php esc_html_e( 'Help me write my brief', 'stratawp-seo' ); ?>
					<span class="swps-brief-helper-hint"><?php esc_html_e( 'Optional prompts to sharpen your instructions', 'stratawp-seo' ); ?></span>
				</summary>
				<div class="swps-brief-helper-body">
					<p class="swps-field-help"><?php esc_html_e( 'Fill in any of these and they are added to your brief when you generate. Nothing here is required.', 'stratawp-seo' ); ?></p>
					<div class="swps-brief-helper-grid">
						<div class="swps-form-group">
							<label for="swps-brief-audience"><?php esc_html_e( 'Target audience', 'stratawp-seo' ); ?></label>
							<input type="text" id="swps-brief-audience" data-brief-key="audience" maxlength="<?php echo (int) $gen_brief_field_max; ?>" placeholder="<?php esc_attr_e( 'e.g., first-time landlords in Denver', 'stratawp-seo' ); ?>" <?php echo ! $has_api_key ? 'disabled' : ''; ?> />
						</div>
						<div class="swps-form-group">
							<label for="swps-brief-goal"><?php esc_html_e( 'Content goal', 'stratawp-seo' ); ?></label>
							<input type="text" id="swps-brief-goal" data-brief-key="goal" maxlength="<?php echo (int) $gen_brief_field_max; ?>" placeholder="<?php esc_attr_e( 'e.g., help readers compare options and book a call', 'stratawp-seo' ); ?>" <?php echo ! $has_api_key ? 'disabled' : ''; ?> />
						</div>
						<div class="swps-form-group swps-brief-helper-wide">
							<label for="swps-brief-key-points"><?php esc_html_e( 'Key points or sections to include', 'stratawp-seo' ); ?></label>
							<textarea id="swps-brief-key-points" data-brief-key="key_points" rows="3" maxlength="<?php echo (int) $gen_brief_field_max; ?>" placeholder="<?php esc_attr_e( 'One per line: updates, backups, security, hosting, support, questions to ask before hiring', 'stratawp-seo' ); ?>" <?php echo ! $has_api_key ? 'disabled' : ''; ?>></textarea>
						</div>
						<div class="swps-form-group">
							<label for="swps-brief-cta"><?php esc_html_e( 'Desired call to action', 'stratawp-seo' ); ?></label>
							<input type="text" id="swps-brief-cta" data-brief-key="cta" maxlength="<?php echo (int) $gen_brief_field_max; ?>" placeholder="<?php esc_attr_e( 'e.g., book a free consultation', 'stratawp-seo' ); ?>" <?php echo ! $has_api_key ? 'disabled' : ''; ?> />
						</div>
						<div class="swps-form-group swps-brief-helper-wide">
							<label for="swps-brief-facts"><?php esc_html_e( 'Facts or business details to use', 'stratawp-seo' ); ?></label>
							<textarea id="swps-brief-facts" data-brief-key="facts" rows="3" maxlength="<?php echo (int) $gen_brief_field_max; ?>" placeholder="<?php esc_attr_e( 'Business name, location, years in business, real services, real credentials. Only what is true — the AI is told not to invent details.', 'stratawp-seo' ); ?>" <?php echo ! $has_api_key ? 'disabled' : ''; ?>></textarea>
						</div>
						<div class="swps-form-group swps-brief-helper-wide">
							<label for="swps-brief-avoid"><?php esc_html_e( 'Things to avoid', 'stratawp-seo' ); ?></label>
							<textarea id="swps-brief-avoid" data-brief-key="avoid" rows="2" maxlength="<?php echo (int) $gen_brief_field_max; ?>" placeholder="<?php esc_attr_e( 'e.g., technical jargon, made-up pricing, competitor names', 'stratawp-seo' ); ?>" <?php echo ! $has_api_key ? 'disabled' : ''; ?>></textarea>
						</div>
					</div>

					<div class="swps-brief-improve">
						<button type="button" id="swps-improve-brief-btn" class="button" <?php echo ! $has_api_key ? 'disabled' : ''; ?>>
							<span class="dashicons dashicons-editor-spellcheck" aria-hidden="true"></span>
							<?php esc_html_e( 'Improve my brief', 'stratawp-seo' ); ?>
						</button>
						<span class="swps-field-help swps-brief-improve-note"><?php esc_html_e( 'Makes one extra AI request (uses API credits). Proposes a clearer version of your brief for you to review — it never changes your text on its own and does not write the article.', 'stratawp-seo' ); ?></span>
					</div>

					<div id="swps-brief-proposal" class="swps-brief-proposal" hidden>
						<h3 class="swps-brief-proposal-title"><?php esc_html_e( 'Proposed brief', 'stratawp-seo' ); ?></h3>
						<p class="swps-field-help"><?php esc_html_e( 'Review the suggestion below. Nothing changes until you click "Use this version".', 'stratawp-seo' ); ?></p>
						<textarea id="swps-brief-proposal-text" class="swps-brief-textarea" rows="8" readonly aria-label="<?php esc_attr_e( 'Proposed brief', 'stratawp-seo' ); ?>"></textarea>
						<ul id="swps-brief-proposal-notes" class="swps-brief-proposal-notes"></ul>
						<div class="swps-brief-proposal-actions">
							<button type="button" id="swps-brief-accept" class="button button-primary"><?php esc_html_e( 'Use this version', 'stratawp-seo' ); ?></button>
							<button type="button" id="swps-brief-reject" class="button"><?php esc_html_e( 'Keep my original', 'stratawp-seo' ); ?></button>
						</div>
					</div>
				</div>
			</details>

			<div class="swps-form-group">
				<label for="swps-topic"><?php esc_html_e( 'Title or topic (optional)', 'stratawp-seo' ); ?></label>
				<input
					type="text"
					id="swps-topic"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g., How to Choose the Right Kitchen Countertop — or leave blank and the AI will pick one from your brief or your site\'s gaps', 'stratawp-seo' ); ?>"
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
					<label for="swps-tone"><?php esc_html_e( 'Tone of voice', 'stratawp-seo' ); ?></label>
					<select id="swps-tone" data-brief-key="tone" <?php echo ! $has_api_key ? 'disabled' : ''; ?>>
						<option value=""><?php echo esc_html( sprintf( /* translators: %s: the tone configured in settings. */ __( 'Use my default (%s)', 'stratawp-seo' ), $gen_brief_tones[ $gen_tone ] ?? ucfirst( $gen_tone ) ) ); ?></option>
						<?php foreach ( $gen_brief_tones as $gen_tone_option ) : ?>
							<option value="<?php echo esc_attr( $gen_tone_option ); ?>"><?php echo esc_html( $gen_tone_option ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div id="swps-parent-group" class="swps-form-group swps-form-group-inline" hidden>
					<label for="swps-parent"><?php esc_html_e( 'Parent page', 'stratawp-seo' ); ?></label>
					<?php echo '' !== $gen_parent_dropdown ? $gen_parent_dropdown : '<select id="swps-parent" name="swps_parent"><option value="0">' . esc_html__( 'None (top level)', 'stratawp-seo' ) . '</option></select>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() output is escaped by core; fallback markup is hardcoded and escaped. ?>
				</div>

				<div id="swps-bulk-group" class="swps-form-group swps-form-group-inline">
					<label for="swps-bulk-count"><?php esc_html_e( 'Bulk Count', 'stratawp-seo' ); ?></label>
					<input type="number" id="swps-bulk-count" value="1" min="1" max="5" class="small-text" <?php echo ! $has_api_key ? 'disabled' : ''; ?> />
				</div>
			</div>

			<?php /* Per-run images. Defaults mirror Settings; changing them affects this run only. */ ?>
			<div class="swps-form-row swps-images-row">
				<div class="swps-form-group swps-form-group-inline">
					<label class="swps-checkbox-label" for="swps-featured-image">
						<input type="checkbox" id="swps-featured-image" value="1" <?php checked( $gen_image_defaults['featured'] ); ?> <?php echo ( ! $has_api_key || ! $gen_images_available ) ? 'disabled' : ''; ?> />
						<?php esc_html_e( 'Featured image', 'stratawp-seo' ); ?>
					</label>
				</div>
				<div class="swps-form-group swps-form-group-inline">
					<label for="swps-content-images"><?php esc_html_e( 'In-content images', 'stratawp-seo' ); ?></label>
					<input type="number" id="swps-content-images" class="small-text" min="0" max="<?php echo (int) SWPS_Image_Plan::MAX_CONTENT_IMAGES; ?>" value="<?php echo (int) $gen_image_defaults['content_count']; ?>" <?php echo ( ! $has_api_key || ! $gen_images_available ) ? 'disabled' : ''; ?> />
				</div>
				<p class="swps-field-help swps-images-note">
					<?php if ( $gen_images_available ) : ?>
						<?php esc_html_e( 'Applies to this run only. Defaults come from Settings.', 'stratawp-seo' ); ?>
					<?php else : ?>
						<?php
						printf(
							/* translators: %s: settings page URL. */
							wp_kses( __( 'Add an image provider key in <a href="%s">Settings</a> to use images.', 'stratawp-seo' ), array( 'a' => array( 'href' => array() ) ) ),
							esc_url( $gen_settings_url )
						);
						?>
					<?php endif; ?>
				</p>
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
						<td id="swps-summary-lands" data-post="<?php echo esc_attr( $gen_status_label ); ?>" data-page="<?php echo esc_attr( $gen_status_label_page ); ?>"><?php echo esc_html( $gen_status_label ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Length:', 'stratawp-seo' ); ?></td>
						<td id="swps-summary-length" data-post="<?php echo esc_attr( sprintf( __( '~%1$s–%2$s words', 'stratawp-seo' ), number_format_i18n( $gen_min_words ), number_format_i18n( $gen_max_words ) ) ); ?>">
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
						<td id="swps-summary-featured"><?php echo $gen_image_defaults['featured'] ? esc_html__( 'Added automatically', 'stratawp-seo' ) : esc_html__( 'Not added', 'stratawp-seo' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'In-content images:', 'stratawp-seo' ); ?></td>
						<td id="swps-summary-content-images"><?php echo (int) $gen_image_defaults['content_count']; ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Tone / style:', 'stratawp-seo' ); ?></td>
						<td id="swps-summary-tone" data-default="<?php echo esc_attr( $gen_tone_label ); ?>"><?php echo esc_html( $gen_tone_label ); ?></td>
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
					<span id="swps-generate-btn-label"><?php esc_html_e( 'Generate Post', 'stratawp-seo' ); ?></span>
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
					title="<?php esc_attr_e( 'Generates several posts on AI-chosen topics. Bulk runs do not use the brief above — each post gets its own topic.', 'stratawp-seo' ); ?>"
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
			$page_count = wp_count_posts( 'page' );
			$generated  = new WP_Query(
				array(
					'post_type'      => array( 'post', 'page' ),
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
					<span class="swps-stat-number"><?php echo esc_html( $page_count->publish ); ?></span>
					<span class="swps-stat-label"><?php esc_html_e( 'Published Pages', 'stratawp-seo' ); ?></span>
				</div>
				<div class="swps-stat">
					<span class="swps-stat-number"><?php echo esc_html( $post_count->draft + $page_count->draft ); ?></span>
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
			<span id="swps-result-heading" data-post="<?php esc_attr_e( 'Post Generated Successfully!', 'stratawp-seo' ); ?>" data-page="<?php esc_attr_e( 'Page Generated Successfully!', 'stratawp-seo' ); ?>"><?php esc_html_e( 'Post Generated Successfully!', 'stratawp-seo' ); ?></span>
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
			<tr id="swps-result-sources-row" style="display: none;">
				<td><strong><?php esc_html_e( 'Sources:', 'stratawp-seo' ); ?></strong></td>
				<td><ul id="swps-result-sources" class="swps-result-sources"></ul></td>
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
				<span data-post="<?php esc_attr_e( 'Edit Post', 'stratawp-seo' ); ?>" data-page="<?php esc_attr_e( 'Edit Page', 'stratawp-seo' ); ?>"><?php esc_html_e( 'Edit Post', 'stratawp-seo' ); ?></span>
			</a>
			<a id="swps-result-preview" href="#" class="button" target="_blank">
				<span data-post="<?php esc_attr_e( 'Preview Post', 'stratawp-seo' ); ?>" data-page="<?php esc_attr_e( 'Preview Page', 'stratawp-seo' ); ?>"><?php esc_html_e( 'Preview Post', 'stratawp-seo' ); ?></span>
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
