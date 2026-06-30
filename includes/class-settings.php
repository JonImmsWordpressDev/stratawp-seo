<?php
/**
 * Settings page and admin menu.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Register admin menu pages.
	 *
	 * v4.0: SWPS_Dashboard now owns the top-level menu (registered at
	 * admin_menu priority 5). Settings registers itself as a submenu with
	 * the new slug 'swps-settings' so /admin.php?page=stratawp-seo lands
	 * on the Dashboard, not Settings. Old bookmarks to ?page=stratawp-seo
	 * continue to resolve — they show Dashboard.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Settings', 'stratawp-seo' ),
			__( 'Settings', 'stratawp-seo' ),
			'manage_options',
			'swps-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'stratawp-seo',
			__( 'Generate Content', 'stratawp-seo' ),
			__( 'Generate Content', 'stratawp-seo' ),
			'edit_posts',
			'swps-generate',
			array( $this, 'render_generate_page' )
		);

		add_submenu_page(
			'stratawp-seo',
			__( 'Voice Profiles', 'stratawp-seo' ),
			__( 'Voice Profiles', 'stratawp-seo' ),
			'manage_options',
			'swps-voice-profiles',
			array( $this, 'render_voice_profiles_page' )
		);

		add_submenu_page(
			'stratawp-seo',
			__( 'SEO Audit', 'stratawp-seo' ),
			__( 'SEO Audit', 'stratawp-seo' ),
			'manage_options',
			'swps-seo-audit',
			array( $this, 'render_audit_page' )
		);

		add_submenu_page(
			'stratawp-seo',
			__( 'Search Appearance', 'stratawp-seo' ),
			__( 'Search Appearance', 'stratawp-seo' ),
			'manage_options',
			'swps-search-appearance',
			array( $this, 'render_search_appearance_page' )
		);

		add_submenu_page(
			'stratawp-seo',
			__( 'Redirects', 'stratawp-seo' ),
			__( 'Redirects', 'stratawp-seo' ),
			'manage_options',
			'swps-redirects',
			array( SWPS_Redirect_Admin::class, 'render' )
		);

		add_submenu_page(
			'stratawp-seo',
			__( 'Migrate from Yoast / Rank Math', 'stratawp-seo' ),
			__( 'Migrate', 'stratawp-seo' ),
			'manage_options',
			'swps-migration',
			array( $this, 'render_migration_page' )
		);

		add_submenu_page(
			'stratawp-seo',
			__( 'Debug — Last AI Failure', 'stratawp-seo' ),
			__( 'Debug', 'stratawp-seo' ),
			'manage_options',
			'swps-debug',
			array( $this, 'render_debug_page' )
		);
	}

	/**
	 * Render the migration tool page.
	 */
	public function render_migration_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'stratawp-seo' ) );
		}
		include SWPS_PLUGIN_DIR . 'templates/migration-page.php';
	}

	/**
	 * Render the debug page showing the last failed AI response.
	 */
	public function render_debug_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'stratawp-seo' ) );
		}

		if ( isset( $_POST['swps_clear_failure'] ) && check_admin_referer( 'swps_clear_failure' ) ) {
			delete_transient( 'swps_last_ai_failure' );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Cleared.', 'stratawp-seo' ) . '</p></div>';
		}

		$failure = get_transient( 'swps_last_ai_failure' );

		echo '<div class="wrap swps-debug-wrap">';

		$title    = __( 'Debug', 'stratawp-seo' );
		$subtitle = __( 'Last failed AI response captured by the JSON parser. Use this to diagnose generation errors.', 'stratawp-seo' );
		$actions  = array();
		require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';

		if ( empty( $failure ) ) {
			echo '<div class="swps-tile" style="text-align:center;padding:48px 24px">';
			echo '<div style="font-size:48px;line-height:1;margin-bottom:16px;color:var(--swps-success)">✓</div>';
			echo '<p style="color:var(--swps-text-muted);margin:0">' . esc_html__( 'No recent failures.', 'stratawp-seo' ) . '</p>';
			echo '</div></div>';
			return;
		}

		echo '<div class="swps-tile">';
		echo '<table style="width:100%;border-collapse:collapse">';
		echo '<tr><td style="padding:6px 0;color:var(--swps-text-muted);font-size:12px;width:80px">' . esc_html__( 'Time', 'stratawp-seo' ) . '</td><td style="padding:6px 0;color:var(--swps-text-primary)">' . esc_html( $failure['time'] ?? '' ) . '</td></tr>';
		echo '<tr><td style="padding:6px 0;color:var(--swps-text-muted);font-size:12px">' . esc_html__( 'Error', 'stratawp-seo' ) . '</td><td style="padding:6px 0;color:var(--swps-crit)">' . esc_html( $failure['error'] ?? '' ) . '</td></tr>';
		echo '<tr><td style="padding:6px 0;color:var(--swps-text-muted);font-size:12px">' . esc_html__( 'Length', 'stratawp-seo' ) . '</td><td style="padding:6px 0;color:var(--swps-text-primary)">' . esc_html( number_format( strlen( (string) ( $failure['raw'] ?? '' ) ) ) ) . ' ' . esc_html__( 'chars', 'stratawp-seo' ) . '</td></tr>';
		echo '</table>';

		echo '<form method="post" style="margin: 16px 0 0;">';
		wp_nonce_field( 'swps_clear_failure' );
		echo '<button type="submit" name="swps_clear_failure" class="swps-btn swps-btn-secondary">' . esc_html__( 'Clear', 'stratawp-seo' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '<div class="swps-section-h" style="margin-top:24px"><h3>' . esc_html__( 'Raw response', 'stratawp-seo' ) . '</h3></div>';
		echo '<textarea readonly rows="24" style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;background:var(--swps-bg-input);color:var(--swps-text-body);border:1px solid var(--swps-border-strong);border-radius:var(--swps-radius-sm);padding:14px;line-height:1.5">' . esc_textarea( (string) ( $failure['raw'] ?? '' ) ) . '</textarea>';

		echo '</div>';
	}

	/**
	 * Register all settings.
	 */
	public function register_settings(): void {
		// --- AI Provider Section ---
		add_settings_section( 'swps_ai_section', __( 'AI Provider', 'stratawp-seo' ), array( $this, 'render_ai_section' ), 'stratawp-seo' );

		$this->add_field(
			'ai_provider',
			__( 'AI Provider', 'stratawp-seo' ),
			'select',
			'swps_ai_section',
			array(
				'options' => SWPS_Provider_Factory::get_ai_provider_options(),
			)
		);

		$this->add_field(
			'anthropic_api_key',
			__( 'Anthropic API Key', 'stratawp-seo' ),
			'password',
			'swps_ai_section',
			array(
				'description' => __( 'Get your API key from <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>', 'stratawp-seo' ),
				'row_class'   => 'swps-ai-key-row swps-provider-anthropic',
			)
		);

		$this->add_field(
			'openai_api_key',
			__( 'OpenAI API Key', 'stratawp-seo' ),
			'password',
			'swps_ai_section',
			array(
				'description' => __( 'Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>', 'stratawp-seo' ),
				'row_class'   => 'swps-ai-key-row swps-provider-openai',
			)
		);

		$this->add_field(
			'google_api_key',
			__( 'Google API Key', 'stratawp-seo' ),
			'password',
			'swps_ai_section',
			array(
				'description' => __( 'Get your API key from <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>', 'stratawp-seo' ),
				'row_class'   => 'swps-ai-key-row swps-provider-google',
			)
		);

		$this->add_field(
			'xai_api_key',
			__( 'xAI API Key', 'stratawp-seo' ),
			'password',
			'swps_ai_section',
			array(
				'description' => __( 'Get your API key from <a href="https://console.x.ai/" target="_blank">console.x.ai</a>', 'stratawp-seo' ),
				'row_class'   => 'swps-ai-key-row swps-provider-xai',
			)
		);

		$this->add_field(
			'model',
			__( 'AI Model', 'stratawp-seo' ),
			'select',
			'swps_ai_section',
			array(
				'options'     => $this->get_current_model_options(),
				'description' => __( 'Model list updates automatically when you change providers.', 'stratawp-seo' ),
			)
		);

		// --- Featured Images Section ---
		add_settings_section( 'swps_images_section', __( 'Featured Images', 'stratawp-seo' ), array( $this, 'render_images_section' ), 'stratawp-seo' );

		$this->add_field(
			'featured_images',
			__( 'Auto Featured Images', 'stratawp-seo' ),
			'checkbox',
			'swps_images_section',
			array(
				'label' => __( 'Automatically fetch a relevant featured image for each generated post', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'image_provider',
			__( 'Image Provider', 'stratawp-seo' ),
			'select',
			'swps_images_section',
			array(
				'options' => SWPS_Provider_Factory::get_image_provider_options(),
			)
		);

		$this->add_field(
			'unsplash_api_key',
			__( 'Unsplash API Key', 'stratawp-seo' ),
			'password',
			'swps_images_section',
			array(
				'description' => __( 'Get a free API key from <a href="https://unsplash.com/developers" target="_blank">unsplash.com/developers</a>', 'stratawp-seo' ),
				'row_class'   => 'swps-image-key-row swps-image-provider-unsplash',
			)
		);

		$this->add_field(
			'pexels_api_key',
			__( 'Pexels API Key', 'stratawp-seo' ),
			'password',
			'swps_images_section',
			array(
				'description' => __( 'Get a free API key from <a href="https://www.pexels.com/api/" target="_blank">pexels.com/api</a>', 'stratawp-seo' ),
				'row_class'   => 'swps-image-key-row swps-image-provider-pexels',
			)
		);

		$this->add_field(
			'pixabay_api_key',
			__( 'Pixabay API Key', 'stratawp-seo' ),
			'password',
			'swps_images_section',
			array(
				'description' => __( 'Get a free API key from <a href="https://pixabay.com/api/docs/" target="_blank">pixabay.com/api</a>', 'stratawp-seo' ),
				'row_class'   => 'swps-image-key-row swps-image-provider-pixabay',
			)
		);

		// Note: Gemini image generation reuses the Google API key from the AI Provider
		// section above. We intentionally do NOT register another input for
		// `swps_google_api_key` here — duplicate inputs with the same name cause the
		// hidden row's empty value to clobber the visible row's value on save (see #24).
		add_settings_field(
			'swps_gemini_image_key_info',
			__( 'Google API Key', 'stratawp-seo' ),
			function () {
				echo '<p class="description">' . wp_kses_post( __( 'Gemini image generation uses the Google API Key set in the <strong>AI Provider</strong> section above.', 'stratawp-seo' ) ) . '</p>';
			},
			'stratawp-seo',
			'swps_images_section',
			array( 'class' => 'swps-image-key-row swps-image-provider-gemini' )
		);

		$this->add_field(
			'gemini_image_model',
			__( 'Gemini Image Model', 'stratawp-seo' ),
			'select',
			'swps_images_section',
			array(
				'options'   => ( new SWPS_Model_Discovery() )->get_image_models(),
				'row_class' => 'swps-image-key-row swps-image-provider-gemini',
			)
		);

		$this->add_field(
			'insert_content_images',
			__( 'In-Content Images', 'stratawp-seo' ),
			'checkbox',
			'swps_images_section',
			array(
				'label' => __( 'Insert contextual images within the post body (in addition to featured image)', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'content_images_count',
			__( 'Images Per Post', 'stratawp-seo' ),
			'number',
			'swps_images_section',
			array(
				'min'         => 1,
				'max'         => 4,
				'description' => __( 'Maximum number of in-content images to insert (1-4).', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'image_max_width',
			__( 'Image Max Width', 'stratawp-seo' ),
			'number',
			'swps_images_section',
			array(
				'min'         => 600,
				'max'         => 2400,
				'step'        => 100,
				'description' => __( 'Maximum width in pixels for content images.', 'stratawp-seo' ),
			)
		);

		// --- Site Details Section ---
		add_settings_section( 'swps_site_section', __( 'Site Details', 'stratawp-seo' ), array( $this, 'render_site_section' ), 'stratawp-seo' );

		$this->add_field(
			'site_niche',
			__( 'Site Niche / Industry', 'stratawp-seo' ),
			'text',
			'swps_site_section',
			array(
				'placeholder' => 'e.g., Home Renovation, Digital Marketing, Pet Care',
				'description' => __( 'What is your site about? Be specific.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'site_description',
			__( 'Site Description', 'stratawp-seo' ),
			'textarea',
			'swps_site_section',
			array(
				'placeholder' => 'Describe your site, target audience, and what makes it unique...',
				'description' => __( 'Give the AI context about your site and audience. The more detail, the better the content.', 'stratawp-seo' ),
				'rows'        => 4,
			)
		);

		// --- AI Crawlers Section ---
		add_settings_section( 'swps_ai_crawlers_section', __( 'AI Crawlers', 'stratawp-seo' ), array( $this, 'render_ai_crawlers_section' ), 'stratawp-seo' );

		$bot_options = array();
		foreach ( SWPS_AI_Bots::KNOWN_BOTS as $key => $token ) {
			$bot_options[ $key ] = $token;
		}
		$this->add_field(
			'ai_bots_allowed',
			__( 'Allowed AI Bots', 'stratawp-seo' ),
			'multi_checkbox',
			'swps_ai_crawlers_section',
			array(
				'options'     => $bot_options,
				'default'     => array_keys( $bot_options ),
				'description' => __( 'Allowed bots get an explicit Allow rule in robots.txt; unchecked known bots get a Disallow. View the result at <code>/robots.txt</code>.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'llms_txt_enabled',
			__( 'Generate llms.txt', 'stratawp-seo' ),
			'checkbox',
			'swps_ai_crawlers_section',
			array(
				'default'     => 1,
				'label'       => __( 'Serve a dynamic llms.txt at /llms.txt', 'stratawp-seo' ),
				'description' => sprintf(
					/* translators: %s: link to /llms.txt */
					__( 'Built from your site description and most recent posts (with one-line summaries). Overrides Yoast or other plugin output. <a href="%s" target="_blank">View live</a>.', 'stratawp-seo' ),
					esc_url( home_url( '/llms.txt' ) )
				),
			)
		);

		// --- Crawler Verification Section (Dispatch 1) ---
		add_settings_section( 'swps_crawler_verification_section', __( 'Crawler Verification', 'stratawp-seo' ), array( $this, 'render_crawler_verification_section' ), 'stratawp-seo' );

		$this->add_field(
			'trusted_proxy_header',
			__( 'Trusted Proxy Header', 'stratawp-seo' ),
			'select',
			'swps_crawler_verification_section',
			array(
				'options'     => array(
					'none' => __( 'None (use REMOTE_ADDR)', 'stratawp-seo' ),
					'cf'   => __( 'Cloudflare (CF-Connecting-IP)', 'stratawp-seo' ),
					'xff'  => __( 'X-Forwarded-For (first IP)', 'stratawp-seo' ),
				),
				'default'     => 'none',
				'description' => __( 'Set only when your server sits behind a trusted reverse proxy. Wrong setting allows IP spoofing.', 'stratawp-seo' ),
			)
		);

		$block_options = array();
		foreach ( SWPS_AI_Bots::SEARCH_BOTS as $key => $token ) {
			$block_options[ $key ] = $token;
		}
		foreach ( SWPS_AI_Bots::KNOWN_BOTS as $key => $token ) {
			$block_options[ $key ] = $token;
		}
		$this->add_field(
			'crawler_block_bots',
			__( 'Block at Server (opt-in)', 'stratawp-seo' ),
			'multi_checkbox',
			'swps_crawler_verification_section',
			array(
				'options'     => $block_options,
				'default'     => array(),
				'description' => __( 'Bots checked here will receive a 403 when both the master toggle is on AND they are verified-spoofed or verified-but-disallowed. Requires <strong>Block Spoofed Crawlers</strong> to be enabled. Default: none.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'crawler_block_enabled',
			__( 'Block Spoofed Crawlers', 'stratawp-seo' ),
			'checkbox',
			'swps_crawler_verification_section',
			array(
				'default'     => 0,
				'label'       => __( 'Enable 403 enforcement for spoofed or disallowed bots (master toggle, default off)', 'stratawp-seo' ),
				'description' => __( 'When enabled, bots in the list above that fail CIDR verification will receive a 403 at template_redirect. Unverifiable hits always pass through (fail-open).', 'stratawp-seo' ),
			)
		);

		// --- Writing Preferences Section ---
		add_settings_section( 'swps_writing_section', __( 'Writing Preferences', 'stratawp-seo' ), array( $this, 'render_writing_section' ), 'stratawp-seo' );

		$this->add_field(
			'tone',
			__( 'Tone of Voice', 'stratawp-seo' ),
			'select',
			'swps_writing_section',
			array(
				'options' => array(
					'professional'   => __( 'Professional', 'stratawp-seo' ),
					'conversational' => __( 'Conversational', 'stratawp-seo' ),
					'friendly'       => __( 'Friendly & Approachable', 'stratawp-seo' ),
					'authoritative'  => __( 'Authoritative & Expert', 'stratawp-seo' ),
					'casual'         => __( 'Casual & Relaxed', 'stratawp-seo' ),
					'formal'         => __( 'Formal & Academic', 'stratawp-seo' ),
					'witty'          => __( 'Witty & Entertaining', 'stratawp-seo' ),
				),
			)
		);

		$this->add_field(
			'voice_profile',
			__( 'Voice Profile', 'stratawp-seo' ),
			'select',
			'swps_writing_section',
			array(
				'options'     => stratawp_seo()->voice_profile->get_options(),
				'description' => __( 'Select a voice profile to override tone/style settings. <a href="' . admin_url( 'admin.php?page=swps-voice-profiles' ) . '">Manage profiles</a>', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'writing_style',
			__( 'Custom Style Notes', 'stratawp-seo' ),
			'textarea',
			'swps_writing_section',
			array(
				'placeholder' => 'e.g., Use short paragraphs, include real-world examples, avoid jargon, write for beginners...',
				'description' => __( 'Any specific instructions about how content should be written.', 'stratawp-seo' ),
				'rows'        => 3,
			)
		);

		$this->add_field(
			'target_keywords',
			__( 'Target Keywords', 'stratawp-seo' ),
			'textarea',
			'swps_writing_section',
			array(
				'placeholder' => 'kitchen remodeling tips, bathroom renovation cost, home improvement ideas',
				'description' => __( 'Comma-separated list of keywords you want to target across your content.', 'stratawp-seo' ),
				'rows'        => 3,
			)
		);

		// --- Content Settings Section ---
		add_settings_section( 'swps_content_section', __( 'Content Settings', 'stratawp-seo' ), array( $this, 'render_content_section' ), 'stratawp-seo' );

		$this->add_field(
			'post_status',
			__( 'Default Post Status', 'stratawp-seo' ),
			'select',
			'swps_content_section',
			array(
				'options' => array(
					'draft'   => __( 'Draft (review before publishing)', 'stratawp-seo' ),
					'pending' => __( 'Pending Review', 'stratawp-seo' ),
					'publish' => __( 'Published (use with caution)', 'stratawp-seo' ),
				),
			)
		);

		$this->add_field( 'post_author', __( 'Post Author', 'stratawp-seo' ), 'author_select', 'swps_content_section' );

		$this->add_field(
			'post_category',
			__( 'Default Category', 'stratawp-seo' ),
			'category_select',
			'swps_content_section',
			array(
				'description' => __( 'Default category. The AI may also suggest a better category per post.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'word_count_min',
			__( 'Minimum Word Count', 'stratawp-seo' ),
			'number',
			'swps_content_section',
			array(
				'min'  => 300,
				'max'  => 5000,
				'step' => 100,
			)
		);

		$this->add_field(
			'word_count_max',
			__( 'Maximum Word Count', 'stratawp-seo' ),
			'number',
			'swps_content_section',
			array(
				'min'  => 500,
				'max'  => 8000,
				'step' => 100,
			)
		);

		$this->add_field(
			'internal_links_min',
			__( 'Min Internal Links', 'stratawp-seo' ),
			'number',
			'swps_content_section',
			array(
				'min' => 0,
				'max' => 20,
			)
		);

		$this->add_field(
			'internal_links_max',
			__( 'Max Internal Links', 'stratawp-seo' ),
			'number',
			'swps_content_section',
			array(
				'min' => 1,
				'max' => 30,
			)
		);

		$this->add_field(
			'include_faq_schema',
			__( 'Include FAQ Section + Schema', 'stratawp-seo' ),
			'checkbox',
			'swps_content_section',
			array(
				'label' => __( 'Add a FAQ section with structured data (great for rich snippets)', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'include_toc',
			__( 'Include Table of Contents', 'stratawp-seo' ),
			'checkbox',
			'swps_content_section',
			array(
				'label' => __( 'Add a linked table of contents at the top of each post', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'include_takeaways',
			__( 'Include Key Takeaways', 'stratawp-seo' ),
			'checkbox',
			'swps_content_section',
			array(
				'label' => __( 'Add a key takeaways section after the intro (concise bullet points summarizing the post)', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'takeaways_count',
			__( 'Takeaway Count', 'stratawp-seo' ),
			'number',
			'swps_content_section',
			array(
				'min'         => 3,
				'max'         => 7,
				'description' => __( 'Number of key takeaway bullet points to generate (3-7).', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'takeaways_schema',
			__( 'Takeaways Schema Markup', 'stratawp-seo' ),
			'checkbox',
			'swps_content_section',
			array(
				'label' => __( 'Output ItemList JSON-LD structured data for key takeaways (for rich snippets)', 'stratawp-seo' ),
			)
		);

		// --- Schedule Section ---
		add_settings_section( 'swps_schedule_section', __( 'Auto-Publishing Schedule', 'stratawp-seo' ), array( $this, 'render_schedule_section' ), 'stratawp-seo' );

		$this->add_field(
			'cron_enabled',
			__( 'Enable Scheduled Generation', 'stratawp-seo' ),
			'checkbox',
			'swps_schedule_section',
			array(
				'label' => __( 'Automatically generate posts on a schedule', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'cron_frequency',
			__( 'Frequency', 'stratawp-seo' ),
			'select',
			'swps_schedule_section',
			array(
				'options' => array(
					'daily'        => __( 'Daily', 'stratawp-seo' ),
					'twice_weekly' => __( 'Twice a Week', 'stratawp-seo' ),
					'three_weekly' => __( 'Three Times a Week', 'stratawp-seo' ),
					'weekly'       => __( 'Weekly', 'stratawp-seo' ),
					'biweekly'     => __( 'Every Two Weeks', 'stratawp-seo' ),
					'monthly'      => __( 'Monthly', 'stratawp-seo' ),
				),
			)
		);

		$this->add_field(
			'cron_day',
			__( 'Day of Week', 'stratawp-seo' ),
			'select',
			'swps_schedule_section',
			array(
				'options'     => array(
					'monday'    => __( 'Monday', 'stratawp-seo' ),
					'tuesday'   => __( 'Tuesday', 'stratawp-seo' ),
					'wednesday' => __( 'Wednesday', 'stratawp-seo' ),
					'thursday'  => __( 'Thursday', 'stratawp-seo' ),
					'friday'    => __( 'Friday', 'stratawp-seo' ),
					'saturday'  => __( 'Saturday', 'stratawp-seo' ),
					'sunday'    => __( 'Sunday', 'stratawp-seo' ),
				),
				'description' => __( 'Starting day for the schedule.', 'stratawp-seo' ),
			)
		);

		$this->add_field( 'cron_time', __( 'Time', 'stratawp-seo' ), 'time', 'swps_schedule_section' );

		$this->add_field(
			'cron_posts_per_run',
			__( 'Posts Per Run', 'stratawp-seo' ),
			'number',
			'swps_schedule_section',
			array(
				'min'         => 1,
				'max'         => 5,
				'description' => __( 'Number of posts to generate each time (max 5).', 'stratawp-seo' ),
			)
		);

		// --- SEO Audit Section ---
		add_settings_section( 'swps_audit_section', __( 'SEO Audit', 'stratawp-seo' ), array( $this, 'render_audit_section' ), 'stratawp-seo' );

		$this->add_field(
			'audit_auto_canonical',
			__( 'Auto Canonical Tags', 'stratawp-seo' ),
			'checkbox',
			'swps_audit_section',
			array(
				'label' => __( 'Automatically add canonical tags to posts/pages missing them', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'audit_auto_og',
			__( 'Auto OG/Twitter Tags', 'stratawp-seo' ),
			'checkbox',
			'swps_audit_section',
			array(
				'label' => __( 'Automatically output Open Graph and Twitter Card meta tags', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'audit_auto_sitemap',
			__( 'Sitemap Generation', 'stratawp-seo' ),
			'checkbox',
			'swps_audit_section',
			array(
				'label' => __( 'Generate XML sitemap (only when no other sitemap plugin is active)', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'audit_cron_schedule',
			__( 'Audit Schedule', 'stratawp-seo' ),
			'select',
			'swps_audit_section',
			array(
				'options'     => array(
					'daily'   => __( 'Daily', 'stratawp-seo' ),
					'weekly'  => __( 'Weekly', 'stratawp-seo' ),
					'monthly' => __( 'Monthly', 'stratawp-seo' ),
				),
				'description' => __( 'How often to run the automated SEO audit.', 'stratawp-seo' ),
			)
		);

		// --- Schema / Structured Data Section ---
		add_settings_section( 'swps_schema_section', __( 'Schema / Structured Data', 'stratawp-seo' ), array( $this, 'render_schema_section' ), 'stratawp-seo' );

		$this->add_field(
			'schema_enabled',
			__( 'Enable Schema Markup', 'stratawp-seo' ),
			'checkbox',
			'swps_schema_section',
			array(
				'label' => __( 'Output JSON-LD structured data on all posts and pages (auto-disabled when Yoast, RankMath, or AIOSEO is active)', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'schema_article_type',
			__( 'Article Type', 'stratawp-seo' ),
			'select',
			'swps_schema_section',
			array(
				'options'     => array(
					'Article'     => __( 'Article', 'stratawp-seo' ),
					'BlogPosting' => __( 'BlogPosting', 'stratawp-seo' ),
					'NewsArticle' => __( 'NewsArticle', 'stratawp-seo' ),
				),
				'description' => __( 'Schema type for blog posts. Most sites should use Article.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'schema_searchbox',
			__( 'Sitelinks Searchbox', 'stratawp-seo' ),
			'checkbox',
			'swps_schema_section',
			array(
				'label' => __( 'Add SearchAction to enable Google sitelinks search box on your homepage', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'schema_entity_type',
			__( 'Site Represents', 'stratawp-seo' ),
			'select',
			'swps_schema_section',
			array(
				'options'     => array(
					'Organization' => __( 'Organization', 'stratawp-seo' ),
					'Person'       => __( 'Person', 'stratawp-seo' ),
				),
				'description' => __( 'Does this website represent an organization or an individual?', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'schema_name',
			__( 'Name', 'stratawp-seo' ),
			'text',
			'swps_schema_section',
			array(
				'placeholder' => get_bloginfo( 'name' ),
				'description' => __( 'Organization or person name. Leave blank to use your site name.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'schema_logo',
			__( 'Logo URL', 'stratawp-seo' ),
			'text',
			'swps_schema_section',
			array(
				'placeholder' => 'https://example.com/logo.png',
				'description' => __( 'Full URL to your logo image (minimum 112x112px). Used for Organization schema and Article publisher.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'schema_social_profiles',
			__( 'Social Profiles', 'stratawp-seo' ),
			'textarea',
			'swps_schema_section',
			array(
				'rows'        => 4,
				'placeholder' => "https://facebook.com/yourpage\nhttps://twitter.com/yourhandle\nhttps://linkedin.com/company/yourcompany",
				'description' => __( 'One social profile URL per line. Populates the sameAs property.', 'stratawp-seo' ),
			)
		);

		// --- SEO Meta Section ---
		add_settings_section( 'swps_meta_section', __( 'SEO Meta', 'stratawp-seo' ), array( $this, 'render_meta_section' ), 'stratawp-seo' );

		$this->add_field(
			'meta_editor_enabled',
			__( 'Enable Meta Editor', 'stratawp-seo' ),
			'checkbox',
			'swps_meta_section',
			array(
				'label' => __( 'Show SEO meta fields (title, description, social, robots) on post and page editors', 'stratawp-seo' ),
			)
		);

		// NOTE: The post-type selector for the meta box lives under Search Appearance
		// → SEO Meta Box (swps_meta_editor_post_types, registered below as an array).
		// The legacy comma-separated text field that used to be here was removed: it
		// re-registered the same option with sanitize_text_field, which silently
		// stripped the array value on save (e.g. ticking Products never persisted).

		$this->add_field(
			'meta_auto_generate',
			__( 'Auto-Generate Meta', 'stratawp-seo' ),
			'checkbox',
			'swps_meta_section',
			array(
				'label' => __( 'Automatically generate meta title and description via AI when publishing a post without them', 'stratawp-seo' ),
			)
		);

		// --- Analytics Section ---
		add_settings_section( 'swps_analytics_section', __( 'Analytics', 'stratawp-seo' ), array( $this, 'render_analytics_section' ), 'stratawp-seo' );

		$this->add_field(
			'analytics_enabled',
			__( 'Enable On-Site Tracking', 'stratawp-seo' ),
			'checkbox',
			'swps_analytics_section',
			array(
				'label' => __( 'Track page views, time on page, scroll depth, and bounce rate (cookie-free, GDPR-friendly)', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'analytics_retention',
			__( 'Data Retention', 'stratawp-seo' ),
			'select',
			'swps_analytics_section',
			array(
				'options'     => array(
					'30'  => __( '30 days', 'stratawp-seo' ),
					'90'  => __( '90 days', 'stratawp-seo' ),
					'180' => __( '180 days', 'stratawp-seo' ),
					'365' => __( '365 days', 'stratawp-seo' ),
				),
				'description' => __( 'How long to keep analytics data. Older data is automatically pruned.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'analytics_exclude_admins',
			__( 'Exclude Admins', 'stratawp-seo' ),
			'checkbox',
			'swps_analytics_section',
			array(
				'label' => __( 'Don\'t track visits from logged-in administrators', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'gsc_client_id',
			__( 'Google OAuth Client ID', 'stratawp-seo' ),
			'text',
			'swps_analytics_section',
			array(
				'placeholder' => 'xxxx.apps.googleusercontent.com',
				'description' => __( 'Create OAuth credentials in your <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>. Set the redirect URI to: <code>' . admin_url( 'admin.php?swps_gsc_callback=1' ) . '</code>', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'gsc_client_secret',
			__( 'Google OAuth Client Secret', 'stratawp-seo' ),
			'password',
			'swps_analytics_section',
			array(
				'description' => __( 'Stored encrypted. After saving, connect via the Analytics page.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'keyword_tracking_frequency',
			__( 'Keyword Sync Frequency', 'stratawp-seo' ),
			'select',
			'swps_analytics_section',
			array(
				'options'     => array(
					'daily'   => __( 'Daily', 'stratawp-seo' ),
					'weekly'  => __( 'Weekly', 'stratawp-seo' ),
					'monthly' => __( 'Monthly', 'stratawp-seo' ),
				),
				'description' => __( 'How often to sync tracked keyword positions from Google Search Console.', 'stratawp-seo' ),
			)
		);

		// --- Advanced Section (v2.0) ---
		add_settings_section( 'swps_advanced_section', __( 'Advanced Settings', 'stratawp-seo' ), array( $this, 'render_advanced_section' ), 'stratawp-seo' );

		$this->add_field(
			'default_template',
			__( 'Default Content Template', 'stratawp-seo' ),
			'select',
			'swps_advanced_section',
			array(
				'options'     => SWPS_Templates::get_options(),
				'description' => __( 'Default template for generated content. Can be overridden per generation.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'rate_limit',
			__( 'Rate Limit Cooldown', 'stratawp-seo' ),
			'number',
			'swps_advanced_section',
			array(
				'min'         => 0,
				'max'         => 600,
				'description' => __( 'Seconds to wait between generations (prevents accidental double-clicks). 0 to disable.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'duplicate_check',
			__( 'Duplicate Detection', 'stratawp-seo' ),
			'checkbox',
			'swps_advanced_section',
			array(
				'label' => __( 'Check for duplicate titles before creating posts (85% similarity threshold)', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'cost_tracking',
			__( 'Cost Tracking', 'stratawp-seo' ),
			'checkbox',
			'swps_advanced_section',
			array(
				'label' => __( 'Track token usage and estimated costs per generation', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'min_content_score',
			__( 'Minimum Content Score', 'stratawp-seo' ),
			'number',
			'swps_advanced_section',
			array(
				'min'         => 0,
				'max'         => 100,
				'description' => __( 'Posts scoring below this threshold are saved as drafts. Set to 0 to disable.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'jon_ai_endpoint',
			__( 'Remote Content Endpoint', 'stratawp-seo' ),
			'text',
			'swps_advanced_section',
			array(
				'placeholder' => 'https://example.com/wp-json/jon-ai/v1/create-posts',
				'description' => __( 'Optional: Remote endpoint for duplicate checking against an external site.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'jon_ai_secret',
			__( 'Remote Content Secret', 'stratawp-seo' ),
			'password',
			'swps_advanced_section',
			array(
				'description' => __( 'Authentication secret for the remote content endpoint. Stored encrypted.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'remove_data_on_uninstall',
			__( 'Remove Data on Uninstall', 'stratawp-seo' ),
			'checkbox',
			'swps_advanced_section',
			array(
				'label'       => __( 'Delete all plugin data when the plugin is uninstalled', 'stratawp-seo' ),
				'description' => __( 'When enabled, deleting the plugin permanently removes all settings, custom tables (analytics, keywords, redirects, links, backlinks), topics, and voice profiles. Leave unchecked to keep your data through a delete + reinstall; scheduled tasks and caches are always cleaned up.', 'stratawp-seo' ),
			)
		);

		// --- Head Cleanup Section ---
		add_settings_section( 'swps_cleanup_section', __( 'Head Cleanup', 'stratawp-seo' ), array( $this, 'render_cleanup_section' ), 'stratawp-seo' );

		$cleanup_fields = array(
			'cleanup_generator' => __( 'Remove WP Generator Tag', 'stratawp-seo' ),
			'cleanup_rsd'       => __( 'Remove RSD/EditURI Link', 'stratawp-seo' ),
			'cleanup_wlw'       => __( 'Remove Windows Live Writer Link', 'stratawp-seo' ),
			'cleanup_shortlink' => __( 'Remove Shortlink', 'stratawp-seo' ),
			'cleanup_rest_api'  => __( 'Remove REST API Link', 'stratawp-seo' ),
			'cleanup_oembed'    => __( 'Remove oEmbed Discovery', 'stratawp-seo' ),
			'cleanup_emoji'     => __( 'Remove Emoji Scripts & Styles', 'stratawp-seo' ),
		);

		foreach ( $cleanup_fields as $key => $label ) {
			$this->add_field( $key, $label, 'checkbox', 'swps_cleanup_section' );
		}

		// --- RSS Optimization Section ---
		add_settings_section( 'swps_rss_section', __( 'RSS Feed', 'stratawp-seo' ), array( $this, 'render_rss_section' ), 'stratawp-seo' );

		$this->add_field( 'rss_before', __( 'Content Before Post in RSS', 'stratawp-seo' ), 'textarea', 'swps_rss_section' );
		$this->add_field( 'rss_after', __( 'Content After Post in RSS', 'stratawp-seo' ), 'textarea', 'swps_rss_section' );

		// --- AEO Optimize Section (v4.6) ---
		add_settings_section(
			'swps_aeo_section',
			__( 'AEO Optimize', 'stratawp-seo' ),
			array( $this, 'render_aeo_section' ),
			'stratawp-seo'
		);

		$this->add_field(
			'aeo_threshold',
			__( 'Score threshold', 'stratawp-seo' ),
			'number',
			'swps_aeo_section',
			array(
				'min'         => 50,
				'max'         => 95,
				'default'     => 70,
				'description' => __( 'Posts scoring below this appear in the AEO Optimize queue.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'aeo_coverage_enabled',
			__( 'Coverage scoring', 'stratawp-seo' ),
			'checkbox',
			'swps_aeo_section',
			array(
				'label'       => __( 'Enable Coverage dimension (uses 1 AI call per post — about $0.001–$0.003 each)', 'stratawp-seo' ),
				'description' => __( 'When enabled, the orchestrator calls the active AI provider once per post to evaluate topic completeness and entity clarity.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'aeo_enabled_schema_types',
			__( 'Dynamic schema types', 'stratawp-seo' ),
			'multi_checkbox',
			'swps_aeo_section',
			array(
				'options' => array(
					'howto'   => 'HowTo',
					'recipe'  => 'Recipe',
					'product' => 'Product',
					'review'  => 'Review',
					'faqpage' => 'FAQPage',
				),
				'default'     => array( 'howto', 'recipe', 'product', 'review', 'faqpage' ),
				'description' => __( 'Which dynamic schema types the renderer emits. Defers automatically when Yoast / RankMath / AIOSEO is active.', 'stratawp-seo' ),
			)
		);

		$this->add_field(
			'aeo_post_types',
			__( 'Post types to score', 'stratawp-seo' ),
			'multi_checkbox',
			'swps_aeo_section',
			array(
				'options'     => $this->get_public_post_type_options(),
				'default'     => array( 'post', 'page' ),
				'description' => __( 'AEO scoring only runs on the selected post types.', 'stratawp-seo' ),
			)
		);

		// Weights is a complex (associative array) option — register directly,
		// not via add_field() (which assumes scalar/list options).
		register_setting(
			'stratawp-seo',
			'swps_aeo_weights',
			array(
				'sanitize_callback' => array( $this, 'sanitize_aeo_weights' ),
				'default'           => array(
					'extractability' => 0.30,
					'markup'         => 0.30,
					'authority'      => 0.20,
					'coverage'       => 0.20,
				),
			)
		);
		add_settings_field(
			'swps_aeo_weights',
			__( 'Dimension weights', 'stratawp-seo' ),
			array( $this, 'render_aeo_weights_field' ),
			'stratawp-seo',
			'swps_aeo_section'
		);

		// --- Search Appearance Settings (separate options group) ---
		register_setting( 'swps_search_appearance', 'swps_title_separator', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		foreach ( get_post_types( array( 'public' => true ) ) as $pt ) {
			if ( 'attachment' === $pt ) {
				continue;
			}
			register_setting( 'swps_search_appearance', "swps_title_template_{$pt}", array( 'sanitize_callback' => 'sanitize_text_field' ) );
			register_setting( 'swps_search_appearance', "swps_desc_template_{$pt}", array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
			register_setting( 'swps_search_appearance', "swps_noindex_{$pt}", array( 'sanitize_callback' => 'absint' ) );
		}

		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			if ( 'post_format' === $tax ) {
				continue;
			}
			register_setting( 'swps_search_appearance', "swps_title_template_{$tax}", array( 'sanitize_callback' => 'sanitize_text_field' ) );
			register_setting( 'swps_search_appearance', "swps_noindex_{$tax}", array( 'sanitize_callback' => 'absint' ) );
		}

		register_setting( 'swps_search_appearance', 'swps_title_template_search', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'swps_search_appearance', 'swps_title_template_404', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'swps_search_appearance', 'swps_title_template_author', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'swps_search_appearance', 'swps_title_template_date', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		// Breadcrumb settings.
		register_setting( 'swps_search_appearance', 'swps_breadcrumbs_enabled', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'swps_search_appearance', 'swps_breadcrumbs_separator', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'swps_search_appearance', 'swps_breadcrumbs_home_label', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		// SEO Column settings.
		register_setting(
			'swps_search_appearance',
			'swps_seo_column_post_types',
			array(
				'sanitize_callback' => function ( $value ) {
					return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array( 'post', 'page' );
				},
				'default'           => array( 'post', 'page' ),
			)
		);
		register_setting(
			'swps_search_appearance',
			'swps_seo_score_content_min',
			array(
				'sanitize_callback' => 'absint',
				'default'           => 300,
			)
		);
		// Post types that get the SEO meta box (Meta Title, Focus Words, etc.).
		// Lets users enable the editor on custom post types such as WooCommerce products.
		register_setting(
			'swps_search_appearance',
			'swps_meta_editor_post_types',
			array(
				'sanitize_callback' => function ( $value ) {
					return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array( 'post', 'page' );
				},
				'default'           => array( 'post', 'page' ),
			)
		);

		// Internal Links settings.
		register_setting(
			'swps_settings',
			'swps_internal_links_enabled',
			array(
				'type'    => 'boolean',
				'default' => true,
			)
		);
		register_setting(
			'swps_settings',
			'swps_internal_links_post_types',
			array(
				'type'    => 'array',
				'default' => array( 'post', 'page' ),
			)
		);
		register_setting(
			'swps_settings',
			'swps_link_relevance_threshold',
			array(
				'type'    => 'number',
				'default' => 0.3,
			)
		);
		register_setting(
			'swps_settings',
			'swps_link_max_suggestions',
			array(
				'type'    => 'integer',
				'default' => 10,
			)
		);
		register_setting(
			'swps_settings',
			'swps_internal_links_in_generation',
			array(
				'type'    => 'boolean',
				'default' => true,
			)
		);
		register_setting(
			'swps_settings',
			'swps_link_ai_batch_size',
			array(
				'type'    => 'integer',
				'default' => 10,
			)
		);
	}

	/**
	 * Get model options for the currently active AI provider.
	 */
	private function get_current_model_options(): array {
		$slug = get_option( 'swps_ai_provider', 'anthropic' );
		return SWPS_Provider_Factory::get_models_for_provider( $slug );
	}

	/**
	 * Helper to register a setting field.
	 */
	private function add_field( string $key, string $title, string $type, string $section, array $args = array() ): void {
		$option_name = "swps_{$key}";

		register_setting(
			'stratawp-seo',
			$option_name,
			array(
				'sanitize_callback' => $this->get_sanitize_callback( $type, $key ),
			)
		);

		$field_args = array_merge(
			$args,
			array(
				'key'  => $key,
				'type' => $type,
				'name' => $option_name,
			)
		);

		$extra = array();
		if ( ! empty( $args['row_class'] ) ) {
			$extra['class'] = $args['row_class'];
		}

		add_settings_field(
			$option_name,
			$title,
			array( $this, 'render_field' ),
			'stratawp-seo',
			$section,
			array_merge( $field_args, $extra )
		);
	}

	/**
	 * Get the appropriate sanitize callback for a field type.
	 */
	private function get_sanitize_callback( string $type, string $key = '' ): callable {
		// Encrypt the jon_ai_secret on save.
		if ( $key === 'jon_ai_secret' || $key === 'gsc_client_secret' ) {
			$option_name = "swps_{$key}";
			return function ( $value ) use ( $option_name ) {
				if ( empty( $value ) ) {
					// Preserve existing encrypted value when field submitted empty.
					return get_option( $option_name, '' );
				}
				return SWPS_Encryption::encrypt( sanitize_text_field( $value ) );
			};
		}

		return match ( $type ) {
			'checkbox' => function ( $value ) {
				return $value ? 1 : 0;
			},
			'multi_checkbox' => function ( $value ) {
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_values( array_map( 'sanitize_key', $value ) );
			},
			'number'   => 'absint',
			'textarea' => 'sanitize_textarea_field',
			'password' => function ( $value ) {
				return sanitize_text_field( $value );
			},
			default    => 'sanitize_text_field',
		};
	}

	/**
	 * Render a settings field.
	 */
	public function render_field( array $args ): void {
		$name    = $args['name'];
		$type    = $args['type'];
		$default = $args['default'] ?? '';
		$value   = get_option( $name, $default );
		$desc    = $args['description'] ?? '';

		// Don't display encrypted values — show empty field with saved indicator.
		$has_encrypted_value = false;
		if ( in_array( $args['key'], array( 'jon_ai_secret', 'gsc_client_secret' ), true ) && ! empty( $value ) && SWPS_Encryption::is_encrypted( $value ) ) {
			$has_encrypted_value = true;
			$value               = '';
		}

		switch ( $type ) {
			case 'text':
			case 'password':
				printf(
					'<input type="%s" name="%s" value="%s" class="regular-text" placeholder="%s" />',
					esc_attr( $type ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $args['placeholder'] ?? '' )
				);
				break;

			case 'number':
				printf(
					'<input type="number" name="%s" value="%s" class="small-text" min="%s" max="%s" step="%s" />',
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $args['min'] ?? 0 ),
					esc_attr( $args['max'] ?? 99999 ),
					esc_attr( $args['step'] ?? 1 )
				);
				break;

			case 'textarea':
				printf(
					'<textarea name="%s" class="large-text" rows="%d" placeholder="%s">%s</textarea>',
					esc_attr( $name ),
					$args['rows'] ?? 4,
					esc_attr( $args['placeholder'] ?? '' ),
					esc_textarea( $value )
				);
				break;

			case 'select':
				printf( '<select name="%s" id="%s">', esc_attr( $name ), esc_attr( $name ) );
				foreach ( ( $args['options'] ?? array() ) as $val => $label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $val ),
						selected( $value, $val, false ),
						esc_html( $label )
					);
				}
				echo '</select>';
				break;

			case 'checkbox':
				printf(
					'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
					esc_attr( $name ),
					checked( $value, 1, false ),
					esc_html( $args['label'] ?? '' )
				);
				break;

			case 'multi_checkbox':
				$stored  = is_array( $value ) ? $value : ( $args['default'] ?? array() );
				$options = $args['options'] ?? array();
				echo '<fieldset style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:4px 16px;">';
				foreach ( $options as $val => $label ) {
					printf(
						'<label><input type="checkbox" name="%s[]" value="%s" %s /> %s</label>',
						esc_attr( $name ),
						esc_attr( $val ),
						checked( in_array( $val, $stored, true ), true, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset>';
				break;

			case 'time':
				printf(
					'<input type="time" name="%s" value="%s" />',
					esc_attr( $name ),
					esc_attr( $value ?: '09:00' )
				);
				break;

			case 'author_select':
				wp_dropdown_users(
					array(
						'name'     => $name,
						'selected' => $value ?: get_current_user_id(),
						'who'      => 'authors',
					)
				);
				break;

			case 'category_select':
				wp_dropdown_categories(
					array(
						'name'              => $name,
						'selected'          => $value,
						'show_option_none'  => __( '— AI Decides —', 'stratawp-seo' ),
						'option_none_value' => 0,
						'hide_empty'        => false,
					)
				);
				break;
		}

		if ( $has_encrypted_value ) {
			echo '<span class="dashicons dashicons-yes" style="color:#00a32a;vertical-align:middle;"></span> ';
			echo '<span style="color:#00a32a;">' . esc_html__( 'Saved (encrypted). Leave blank to keep current value.', 'stratawp-seo' ) . '</span>';
		}

		if ( $desc ) {
			printf( '<p class="description">%s</p>', wp_kses_post( $desc ) );
		}
	}

	// --- Section descriptions ---

	public function render_ai_section(): void {
		echo '<p>' . esc_html__( 'Choose your AI provider and enter your API key.', 'stratawp-seo' ) . '</p>';
	}

	public function render_images_section(): void {
		echo '<p>' . esc_html__( 'Configure automatic featured images for generated posts.', 'stratawp-seo' ) . '</p>';
	}

	public function render_site_section(): void {
		echo '<p>' . esc_html__( 'Tell the AI about your site so it can generate relevant, targeted content.', 'stratawp-seo' ) . '</p>';
	}

	public function render_ai_crawlers_section(): void {
		echo '<p>' . esc_html__( 'Control which AI crawlers can access your site and serve a dynamic llms.txt index at /llms.txt.', 'stratawp-seo' ) . '</p>';
	}

	public function render_crawler_verification_section(): void {
		echo '<p>' . esc_html__( 'Verify search-bot and AI-crawler hits against published IP ranges. The CIDR list is refreshed weekly; rDNS confirmation runs hourly for unverifiable hits.', 'stratawp-seo' ) . '</p>';
	}

	public function render_writing_section(): void {
		echo '<p>' . esc_html__( 'Configure how the AI writes content for your site.', 'stratawp-seo' ) . '</p>';
	}

	public function render_content_section(): void {
		echo '<p>' . esc_html__( 'Control the output format and WordPress post settings.', 'stratawp-seo' ) . '</p>';
	}

	public function render_schedule_section(): void {
		$schedule = SWPS_Cron::get_schedule_info();
		echo '<p>' . esc_html__( 'Set up automatic content generation on a schedule.', 'stratawp-seo' ) . '</p>';
		if ( $schedule['enabled'] ) {
			printf(
				'<div class="notice notice-info inline"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Next scheduled run:', 'stratawp-seo' ),
				esc_html( $schedule['next_run'] )
			);
		}
	}

	public function render_audit_section(): void {
		echo '<p>' . esc_html__( 'Configure automatic SEO audit checks and fixes.', 'stratawp-seo' ) . '</p>';
	}

	/**
	 * Render the Schema settings section description.
	 */
	public function render_schema_section(): void {
		echo '<p>' . esc_html__( 'Automatic JSON-LD structured data for rich results in Google. Disabled automatically when Yoast SEO, RankMath, or All in One SEO is active.', 'stratawp-seo' ) . '</p>';
	}

	public function render_meta_section(): void {
		echo '<p>' . esc_html__( 'Per-post SEO meta fields for titles, descriptions, social previews, and robots directives. Auto-disabled when Yoast, RankMath, or AIOSEO is active.', 'stratawp-seo' ) . '</p>';
	}

	public function render_analytics_section(): void {
		echo '<p>' . esc_html__( 'On-site analytics tracking and Google Search Console integration.', 'stratawp-seo' ) . '</p>';
	}

	public function render_advanced_section(): void {
		echo '<p>' . esc_html__( 'Advanced features for duplicate detection, rate limiting, cost tracking, and remote integration.', 'stratawp-seo' ) . '</p>';

		// Show encryption status.
		$secret = get_option( 'swps_jon_ai_secret', '' );
		if ( ! empty( $secret ) ) {
			$is_encrypted = SWPS_Encryption::is_encrypted( $secret );
			printf(
				'<div class="notice notice-%s inline"><p>%s %s</p></div>',
				$is_encrypted ? 'success' : 'warning',
				esc_html__( 'Remote secret:', 'stratawp-seo' ),
				$is_encrypted
					? esc_html__( 'Stored encrypted.', 'stratawp-seo' )
					: esc_html__( 'Not encrypted — save settings to encrypt.', 'stratawp-seo' )
			);
		}
	}

	public function render_cleanup_section(): void {
		echo '<p>' . esc_html__( 'Remove unnecessary items from your site\'s <head> section to reduce page size.', 'stratawp-seo' ) . '</p>';
	}

	public function render_rss_section(): void {
		echo '<p>' . esc_html__( 'Add content before or after posts in your RSS feed. Available variables: %%post_link%%, %%blog_link%%, %%blog_name%%', 'stratawp-seo' ) . '</p>';
	}

	public function render_sitemap_section(): void {
		$index_url = home_url( '/sitemap_index.xml' );
		echo '<p>' . sprintf(
			esc_html__( 'Your sitemap index: %s', 'stratawp-seo' ),
			'<a href="' . esc_url( $index_url ) . '" target="_blank">' . esc_url( $index_url ) . '</a>'
		) . '</p>';
	}

	public function render_aeo_section(): void {
		echo '<p>' . esc_html__( 'Score posts on AI-citeability across 4 dimensions (Extractability, Markup, Authority, Coverage). Surface low-scoring posts in the AEO Optimize queue and generate AI proposals to improve them.', 'stratawp-seo' ) . '</p>';
	}

	/**
	 * Render the weights grid (4 number inputs that share the swps_aeo_weights array option).
	 */
	public function render_aeo_weights_field(): void {
		$defaults = array(
			'extractability' => 0.30,
			'markup'         => 0.30,
			'authority'      => 0.20,
			'coverage'       => 0.20,
		);
		$weights = (array) get_option( 'swps_aeo_weights', $defaults );

		$labels = array(
			'extractability' => __( 'Extractability', 'stratawp-seo' ),
			'markup'         => __( 'Markup', 'stratawp-seo' ),
			'authority'      => __( 'Authority', 'stratawp-seo' ),
			'coverage'       => __( 'Coverage', 'stratawp-seo' ),
		);

		echo '<fieldset style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:6px 16px;">';
		foreach ( $labels as $key => $label ) {
			$val = isset( $weights[ $key ] ) ? (float) $weights[ $key ] : $defaults[ $key ];
			printf(
				'<label>%s <input type="number" name="swps_aeo_weights[%s]" value="%s" step="0.05" min="0" max="1" style="width:80px;margin-left:8px;" /></label>',
				esc_html( $label ),
				esc_attr( $key ),
				esc_attr( (string) $val )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Weights are auto-normalized to sum to 1.0 on save.', 'stratawp-seo' ) . '</p>';
	}

	/**
	 * Sanitize AEO dimension weights: clamp each to 0-1 and re-normalize to sum 1.0.
	 *
	 * @param mixed $value Raw POST value.
	 * @return array<string, float>
	 */
	public function sanitize_aeo_weights( $value ): array {
		$defaults = array(
			'extractability' => 0.30,
			'markup'         => 0.30,
			'authority'      => 0.20,
			'coverage'       => 0.20,
		);
		if ( ! is_array( $value ) ) {
			return $defaults;
		}
		$clean = array();
		foreach ( $defaults as $k => $d ) {
			$clean[ $k ] = isset( $value[ $k ] ) ? max( 0.0, min( 1.0, (float) $value[ $k ] ) ) : $d;
		}
		$sum = array_sum( $clean );
		if ( $sum <= 0 ) {
			return $defaults;
		}
		foreach ( $clean as $k => $v ) {
			$clean[ $k ] = round( $v / $sum, 2 );
		}
		// Correct rounding drift so the stored sum is exactly 1.0.
		$sum_clean = array_sum( $clean );
		if ( abs( $sum_clean - 1.0 ) > 0.001 ) {
			arsort( $clean );
			$largest_key = (string) array_key_first( $clean );
			$clean[ $largest_key ] = round( $clean[ $largest_key ] + ( 1.0 - $sum_clean ), 2 );
			// Restore original key order: extractability, markup, authority, coverage.
			$ordered = array();
			foreach ( array_keys( $defaults ) as $k ) {
				$ordered[ $k ] = $clean[ $k ];
			}
			$clean = $ordered;
		}
		return $clean;
	}

	/**
	 * Public post-type options for the AEO post-types multi_checkbox.
	 *
	 * @return array<string, string>
	 */
	private function get_public_post_type_options(): array {
		$options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			$options[ $pt->name ] = $pt->label . ' (' . $pt->name . ')';
		}
		return $options;
	}

	/**
	 * Tab groups for the settings page. Each tab lists the section IDs it contains.
	 */
	public function get_settings_tabs(): array {
		return array(
			'ai-content'      => array(
				'label'    => __( 'AI & Content', 'stratawp-seo' ),
				'sections' => array(
					'swps_ai_section',
					'swps_site_section',
					'swps_writing_section',
					'swps_content_section',
					'swps_images_section',
				),
			),
			'schedule'        => array(
				'label'    => __( 'Schedule', 'stratawp-seo' ),
				'sections' => array(
					'swps_schedule_section',
					'swps_topic_scout_section',
				),
			),
			'seo'             => array(
				'label'    => __( 'SEO', 'stratawp-seo' ),
				'sections' => array(
					'swps_audit_section',
					'swps_schema_section',
					'swps_meta_section',
					'swps_cleanup_section',
					'swps_rss_section',
				),
			),
			'discoverability' => array(
				'label'    => __( 'AI Crawlers', 'stratawp-seo' ),
				'sections' => array(
					'swps_ai_crawlers_section',
					'swps_crawler_verification_section',
					'swps_citations_section',
				),
			),
			'analytics'       => array(
				'label'    => __( 'Analytics', 'stratawp-seo' ),
				'sections' => array(
					'swps_analytics_section',
					'swps_digest_section',
					'swps_decay_section',
				),
			),
			'aeo'             => array(
				'label'    => __( 'AEO', 'stratawp-seo' ),
				'sections' => array( 'swps_aeo_section' ),
			),
			'advanced'        => array(
				'label'    => __( 'Advanced', 'stratawp-seo' ),
				'sections' => array(
					'swps_advanced_section',
				),
			),
		);
	}

	/**
	 * Render a subset of settings sections — same output as do_settings_sections()
	 * but scoped to a specific list of section IDs.
	 */
	public function render_sections_for_tab( array $section_ids ): void {
		global $wp_settings_sections, $wp_settings_fields;

		$page = 'stratawp-seo';
		if ( empty( $wp_settings_sections[ $page ] ) ) {
			return;
		}

		foreach ( $section_ids as $section_id ) {
			if ( ! isset( $wp_settings_sections[ $page ][ $section_id ] ) ) {
				continue;
			}

			$section = $wp_settings_sections[ $page ][ $section_id ];

			if ( ! empty( $section['title'] ) ) {
				echo '<h2>' . esc_html( $section['title'] ) . '</h2>' . "\n";
			}

			if ( ! empty( $section['callback'] ) ) {
				call_user_func( $section['callback'], $section );
			}

			if ( empty( $wp_settings_fields[ $page ][ $section_id ] ) ) {
				continue;
			}

			echo '<table class="form-table" role="presentation">';
			do_settings_fields( $page, $section_id );
			echo '</table>';
		}
	}

	/**
	 * Render the main settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
			if ( get_option( 'swps_cron_enabled' ) ) {
				SWPS_Cron::schedule();
			} else {
				SWPS_Cron::unschedule();
			}
		}

		include SWPS_PLUGIN_DIR . 'templates/settings-page.php';
	}

	/**
	 * Render the generate content page.
	 */
	public function render_generate_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		include SWPS_PLUGIN_DIR . 'templates/generate-page.php';
	}

	/**
	 * Show admin notices.
	 */
	public function admin_notices(): void {
		$screen = get_current_screen();

		if ( ! $screen || strpos( $screen->id, 'stratawp-seo' ) === false ) {
			return;
		}

		$ai_provider = SWPS_Provider_Factory::create_ai_provider();
		if ( empty( $ai_provider->get_api_key() ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'StrataWP SEO:', 'stratawp-seo' ),
				sprintf(
					esc_html__( 'Please enter your %s API key to get started.', 'stratawp-seo' ),
					esc_html( $ai_provider->get_name() )
				)
			);
		}

		if ( empty( get_option( 'swps_site_niche' ) ) ) {
			printf(
				'<div class="notice notice-info"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'StrataWP SEO:', 'stratawp-seo' ),
				esc_html__( 'Enter your site niche and description for better content generation.', 'stratawp-seo' )
			);
		}
	}

	/**
	 * Render the voice profiles page (list or edit).
	 */
	public function render_voice_profiles_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_text_field( $_GET['action'] ?? 'list' );

		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			include SWPS_PLUGIN_DIR . 'templates/voice-profile-edit.php';
		} else {
			include SWPS_PLUGIN_DIR . 'templates/voice-profiles-page.php';
		}
	}

	public function render_audit_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include SWPS_PLUGIN_DIR . 'templates/audit-page.php';
	}

	public function render_search_appearance_page(): void {
		include SWPS_PLUGIN_DIR . 'templates/search-appearance-page.php';
	}
}
