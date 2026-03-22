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
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
    }

    /**
     * Register admin menu pages.
     */
    public function register_menu(): void {
        add_menu_page(
            __( 'StrataWP SEO', 'stratawp-seo' ),
            __( 'StrataWP SEO', 'stratawp-seo' ),
            'manage_options',
            'stratawp-seo',
            [ $this, 'render_settings_page' ],
            'dashicons-superhero-alt',
            30
        );

        add_submenu_page(
            'stratawp-seo',
            __( 'Settings', 'stratawp-seo' ),
            __( 'Settings', 'stratawp-seo' ),
            'manage_options',
            'stratawp-seo',
            [ $this, 'render_settings_page' ]
        );

        add_submenu_page(
            'stratawp-seo',
            __( 'Generate Content', 'stratawp-seo' ),
            __( 'Generate Content', 'stratawp-seo' ),
            'edit_posts',
            'swps-generate',
            [ $this, 'render_generate_page' ]
        );

        add_submenu_page(
            'stratawp-seo',
            __( 'Voice Profiles', 'stratawp-seo' ),
            __( 'Voice Profiles', 'stratawp-seo' ),
            'manage_options',
            'swps-voice-profiles',
            [ $this, 'render_voice_profiles_page' ]
        );

        add_submenu_page(
            'stratawp-seo',
            __( 'SEO Audit', 'stratawp-seo' ),
            __( 'SEO Audit', 'stratawp-seo' ),
            'manage_options',
            'swps-seo-audit',
            [ $this, 'render_audit_page' ]
        );

        add_submenu_page(
            'stratawp-seo',
            __( 'Search Appearance', 'stratawp-seo' ),
            __( 'Search Appearance', 'stratawp-seo' ),
            'manage_options',
            'swps-search-appearance',
            [ $this, 'render_search_appearance_page' ]
        );

        add_submenu_page(
            'stratawp-seo',
            __( 'Redirects', 'stratawp-seo' ),
            __( 'Redirects', 'stratawp-seo' ),
            'manage_options',
            'swps-redirects',
            [ SWPS_Redirect_Admin::class, 'render' ]
        );
    }

    /**
     * Register all settings.
     */
    public function register_settings(): void {
        // --- AI Provider Section ---
        add_settings_section( 'swps_ai_section', __( 'AI Provider', 'stratawp-seo' ), [ $this, 'render_ai_section' ], 'stratawp-seo' );

        $this->add_field( 'ai_provider', __( 'AI Provider', 'stratawp-seo' ), 'select', 'swps_ai_section', [
            'options' => SWPS_Provider_Factory::get_ai_provider_options(),
        ] );

        $this->add_field( 'anthropic_api_key', __( 'Anthropic API Key', 'stratawp-seo' ), 'password', 'swps_ai_section', [
            'description' => __( 'Get your API key from <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>', 'stratawp-seo' ),
            'row_class'   => 'swps-ai-key-row swps-provider-anthropic',
        ] );

        $this->add_field( 'openai_api_key', __( 'OpenAI API Key', 'stratawp-seo' ), 'password', 'swps_ai_section', [
            'description' => __( 'Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>', 'stratawp-seo' ),
            'row_class'   => 'swps-ai-key-row swps-provider-openai',
        ] );

        $this->add_field( 'google_api_key', __( 'Google API Key', 'stratawp-seo' ), 'password', 'swps_ai_section', [
            'description' => __( 'Get your API key from <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>', 'stratawp-seo' ),
            'row_class'   => 'swps-ai-key-row swps-provider-google',
        ] );

        $this->add_field( 'xai_api_key', __( 'xAI API Key', 'stratawp-seo' ), 'password', 'swps_ai_section', [
            'description' => __( 'Get your API key from <a href="https://console.x.ai/" target="_blank">console.x.ai</a>', 'stratawp-seo' ),
            'row_class'   => 'swps-ai-key-row swps-provider-xai',
        ] );

        $this->add_field( 'model', __( 'AI Model', 'stratawp-seo' ), 'select', 'swps_ai_section', [
            'options'     => $this->get_current_model_options(),
            'description' => __( 'Model list updates automatically when you change providers.', 'stratawp-seo' ),
        ] );

        // --- Featured Images Section ---
        add_settings_section( 'swps_images_section', __( 'Featured Images', 'stratawp-seo' ), [ $this, 'render_images_section' ], 'stratawp-seo' );

        $this->add_field( 'featured_images', __( 'Auto Featured Images', 'stratawp-seo' ), 'checkbox', 'swps_images_section', [
            'label' => __( 'Automatically fetch a relevant featured image for each generated post', 'stratawp-seo' ),
        ] );

        $this->add_field( 'image_provider', __( 'Image Provider', 'stratawp-seo' ), 'select', 'swps_images_section', [
            'options' => SWPS_Provider_Factory::get_image_provider_options(),
        ] );

        $this->add_field( 'unsplash_api_key', __( 'Unsplash API Key', 'stratawp-seo' ), 'password', 'swps_images_section', [
            'description' => __( 'Get a free API key from <a href="https://unsplash.com/developers" target="_blank">unsplash.com/developers</a>', 'stratawp-seo' ),
            'row_class'   => 'swps-image-key-row swps-image-provider-unsplash',
        ] );

        $this->add_field( 'pexels_api_key', __( 'Pexels API Key', 'stratawp-seo' ), 'password', 'swps_images_section', [
            'description' => __( 'Get a free API key from <a href="https://www.pexels.com/api/" target="_blank">pexels.com/api</a>', 'stratawp-seo' ),
            'row_class'   => 'swps-image-key-row swps-image-provider-pexels',
        ] );

        $this->add_field( 'pixabay_api_key', __( 'Pixabay API Key', 'stratawp-seo' ), 'password', 'swps_images_section', [
            'description' => __( 'Get a free API key from <a href="https://pixabay.com/api/docs/" target="_blank">pixabay.com/api</a>', 'stratawp-seo' ),
            'row_class'   => 'swps-image-key-row swps-image-provider-pixabay',
        ] );

        $this->add_field( 'google_api_key', __( 'Google API Key', 'stratawp-seo' ), 'password', 'swps_images_section', [
            'description' => __( 'Uses your Google API key. Get one from <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>', 'stratawp-seo' ),
            'row_class'   => 'swps-image-key-row swps-image-provider-gemini',
        ] );

        $this->add_field( 'insert_content_images', __( 'In-Content Images', 'stratawp-seo' ), 'checkbox', 'swps_images_section', [
            'label' => __( 'Insert contextual images within the post body (in addition to featured image)', 'stratawp-seo' ),
        ] );

        $this->add_field( 'content_images_count', __( 'Images Per Post', 'stratawp-seo' ), 'number', 'swps_images_section', [
            'min'         => 1,
            'max'         => 4,
            'description' => __( 'Maximum number of in-content images to insert (1-4).', 'stratawp-seo' ),
        ] );

        $this->add_field( 'image_max_width', __( 'Image Max Width', 'stratawp-seo' ), 'number', 'swps_images_section', [
            'min'         => 600,
            'max'         => 2400,
            'step'        => 100,
            'description' => __( 'Maximum width in pixels for content images.', 'stratawp-seo' ),
        ] );

        // --- Site Details Section ---
        add_settings_section( 'swps_site_section', __( 'Site Details', 'stratawp-seo' ), [ $this, 'render_site_section' ], 'stratawp-seo' );

        $this->add_field( 'site_niche', __( 'Site Niche / Industry', 'stratawp-seo' ), 'text', 'swps_site_section', [
            'placeholder'  => 'e.g., Home Renovation, Digital Marketing, Pet Care',
            'description'  => __( 'What is your site about? Be specific.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'site_description', __( 'Site Description', 'stratawp-seo' ), 'textarea', 'swps_site_section', [
            'placeholder'  => 'Describe your site, target audience, and what makes it unique...',
            'description'  => __( 'Give the AI context about your site and audience. The more detail, the better the content.', 'stratawp-seo' ),
            'rows'         => 4,
        ] );

        // --- Writing Preferences Section ---
        add_settings_section( 'swps_writing_section', __( 'Writing Preferences', 'stratawp-seo' ), [ $this, 'render_writing_section' ], 'stratawp-seo' );

        $this->add_field( 'tone', __( 'Tone of Voice', 'stratawp-seo' ), 'select', 'swps_writing_section', [
            'options' => [
                'professional'    => __( 'Professional', 'stratawp-seo' ),
                'conversational'  => __( 'Conversational', 'stratawp-seo' ),
                'friendly'        => __( 'Friendly & Approachable', 'stratawp-seo' ),
                'authoritative'   => __( 'Authoritative & Expert', 'stratawp-seo' ),
                'casual'          => __( 'Casual & Relaxed', 'stratawp-seo' ),
                'formal'          => __( 'Formal & Academic', 'stratawp-seo' ),
                'witty'           => __( 'Witty & Entertaining', 'stratawp-seo' ),
            ],
        ] );

        $this->add_field( 'voice_profile', __( 'Voice Profile', 'stratawp-seo' ), 'select', 'swps_writing_section', [
            'options'     => stratawp_seo()->voice_profile->get_options(),
            'description' => __( 'Select a voice profile to override tone/style settings. <a href="' . admin_url( 'admin.php?page=swps-voice-profiles' ) . '">Manage profiles</a>', 'stratawp-seo' ),
        ] );

        $this->add_field( 'writing_style', __( 'Custom Style Notes', 'stratawp-seo' ), 'textarea', 'swps_writing_section', [
            'placeholder' => 'e.g., Use short paragraphs, include real-world examples, avoid jargon, write for beginners...',
            'description' => __( 'Any specific instructions about how content should be written.', 'stratawp-seo' ),
            'rows'        => 3,
        ] );

        $this->add_field( 'target_keywords', __( 'Target Keywords', 'stratawp-seo' ), 'textarea', 'swps_writing_section', [
            'placeholder' => 'kitchen remodeling tips, bathroom renovation cost, home improvement ideas',
            'description' => __( 'Comma-separated list of keywords you want to target across your content.', 'stratawp-seo' ),
            'rows'        => 3,
        ] );

        // --- Content Settings Section ---
        add_settings_section( 'swps_content_section', __( 'Content Settings', 'stratawp-seo' ), [ $this, 'render_content_section' ], 'stratawp-seo' );

        $this->add_field( 'post_status', __( 'Default Post Status', 'stratawp-seo' ), 'select', 'swps_content_section', [
            'options' => [
                'draft'   => __( 'Draft (review before publishing)', 'stratawp-seo' ),
                'pending' => __( 'Pending Review', 'stratawp-seo' ),
                'publish' => __( 'Published (use with caution)', 'stratawp-seo' ),
            ],
        ] );

        $this->add_field( 'post_author', __( 'Post Author', 'stratawp-seo' ), 'author_select', 'swps_content_section' );

        $this->add_field( 'post_category', __( 'Default Category', 'stratawp-seo' ), 'category_select', 'swps_content_section', [
            'description' => __( 'Default category. The AI may also suggest a better category per post.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'word_count_min', __( 'Minimum Word Count', 'stratawp-seo' ), 'number', 'swps_content_section', [
            'min' => 300, 'max' => 5000, 'step' => 100,
        ] );

        $this->add_field( 'word_count_max', __( 'Maximum Word Count', 'stratawp-seo' ), 'number', 'swps_content_section', [
            'min' => 500, 'max' => 8000, 'step' => 100,
        ] );

        $this->add_field( 'internal_links_min', __( 'Min Internal Links', 'stratawp-seo' ), 'number', 'swps_content_section', [
            'min' => 0, 'max' => 20,
        ] );

        $this->add_field( 'internal_links_max', __( 'Max Internal Links', 'stratawp-seo' ), 'number', 'swps_content_section', [
            'min' => 1, 'max' => 30,
        ] );

        $this->add_field( 'include_faq_schema', __( 'Include FAQ Section + Schema', 'stratawp-seo' ), 'checkbox', 'swps_content_section', [
            'label' => __( 'Add a FAQ section with structured data (great for rich snippets)', 'stratawp-seo' ),
        ] );

        $this->add_field( 'include_toc', __( 'Include Table of Contents', 'stratawp-seo' ), 'checkbox', 'swps_content_section', [
            'label' => __( 'Add a linked table of contents at the top of each post', 'stratawp-seo' ),
        ] );

        $this->add_field( 'include_takeaways', __( 'Include Key Takeaways', 'stratawp-seo' ), 'checkbox', 'swps_content_section', [
            'label' => __( 'Add a key takeaways section after the intro (concise bullet points summarizing the post)', 'stratawp-seo' ),
        ] );

        $this->add_field( 'takeaways_count', __( 'Takeaway Count', 'stratawp-seo' ), 'number', 'swps_content_section', [
            'min'         => 3,
            'max'         => 7,
            'description' => __( 'Number of key takeaway bullet points to generate (3-7).', 'stratawp-seo' ),
        ] );

        $this->add_field( 'takeaways_schema', __( 'Takeaways Schema Markup', 'stratawp-seo' ), 'checkbox', 'swps_content_section', [
            'label' => __( 'Output ItemList JSON-LD structured data for key takeaways (for rich snippets)', 'stratawp-seo' ),
        ] );

        // --- Schedule Section ---
        add_settings_section( 'swps_schedule_section', __( 'Auto-Publishing Schedule', 'stratawp-seo' ), [ $this, 'render_schedule_section' ], 'stratawp-seo' );

        $this->add_field( 'cron_enabled', __( 'Enable Scheduled Generation', 'stratawp-seo' ), 'checkbox', 'swps_schedule_section', [
            'label' => __( 'Automatically generate posts on a schedule', 'stratawp-seo' ),
        ] );

        $this->add_field( 'cron_frequency', __( 'Frequency', 'stratawp-seo' ), 'select', 'swps_schedule_section', [
            'options' => [
                'daily'        => __( 'Daily', 'stratawp-seo' ),
                'twice_weekly' => __( 'Twice a Week', 'stratawp-seo' ),
                'three_weekly' => __( 'Three Times a Week', 'stratawp-seo' ),
                'weekly'       => __( 'Weekly', 'stratawp-seo' ),
                'biweekly'     => __( 'Every Two Weeks', 'stratawp-seo' ),
                'monthly'      => __( 'Monthly', 'stratawp-seo' ),
            ],
        ] );

        $this->add_field( 'cron_day', __( 'Day of Week', 'stratawp-seo' ), 'select', 'swps_schedule_section', [
            'options' => [
                'monday'    => __( 'Monday', 'stratawp-seo' ),
                'tuesday'   => __( 'Tuesday', 'stratawp-seo' ),
                'wednesday' => __( 'Wednesday', 'stratawp-seo' ),
                'thursday'  => __( 'Thursday', 'stratawp-seo' ),
                'friday'    => __( 'Friday', 'stratawp-seo' ),
                'saturday'  => __( 'Saturday', 'stratawp-seo' ),
                'sunday'    => __( 'Sunday', 'stratawp-seo' ),
            ],
            'description' => __( 'Starting day for the schedule.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'cron_time', __( 'Time', 'stratawp-seo' ), 'time', 'swps_schedule_section' );

        $this->add_field( 'cron_posts_per_run', __( 'Posts Per Run', 'stratawp-seo' ), 'number', 'swps_schedule_section', [
            'min'         => 1,
            'max'         => 5,
            'description' => __( 'Number of posts to generate each time (max 5).', 'stratawp-seo' ),
        ] );

        // --- SEO Audit Section ---
        add_settings_section( 'swps_audit_section', __( 'SEO Audit', 'stratawp-seo' ), [ $this, 'render_audit_section' ], 'stratawp-seo' );

        $this->add_field( 'audit_auto_canonical', __( 'Auto Canonical Tags', 'stratawp-seo' ), 'checkbox', 'swps_audit_section', [
            'label' => __( 'Automatically add canonical tags to posts/pages missing them', 'stratawp-seo' ),
        ] );

        $this->add_field( 'audit_auto_og', __( 'Auto OG/Twitter Tags', 'stratawp-seo' ), 'checkbox', 'swps_audit_section', [
            'label' => __( 'Automatically output Open Graph and Twitter Card meta tags', 'stratawp-seo' ),
        ] );

        $this->add_field( 'audit_auto_sitemap', __( 'Sitemap Generation', 'stratawp-seo' ), 'checkbox', 'swps_audit_section', [
            'label' => __( 'Generate XML sitemap (only when no other sitemap plugin is active)', 'stratawp-seo' ),
        ] );

        $this->add_field( 'audit_cron_schedule', __( 'Audit Schedule', 'stratawp-seo' ), 'select', 'swps_audit_section', [
            'options' => [
                'daily'   => __( 'Daily', 'stratawp-seo' ),
                'weekly'  => __( 'Weekly', 'stratawp-seo' ),
                'monthly' => __( 'Monthly', 'stratawp-seo' ),
            ],
            'description' => __( 'How often to run the automated SEO audit.', 'stratawp-seo' ),
        ] );

        // --- Schema / Structured Data Section ---
        add_settings_section( 'swps_schema_section', __( 'Schema / Structured Data', 'stratawp-seo' ), [ $this, 'render_schema_section' ], 'stratawp-seo' );

        $this->add_field( 'schema_enabled', __( 'Enable Schema Markup', 'stratawp-seo' ), 'checkbox', 'swps_schema_section', [
            'label' => __( 'Output JSON-LD structured data on all posts and pages (auto-disabled when Yoast, RankMath, or AIOSEO is active)', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_article_type', __( 'Article Type', 'stratawp-seo' ), 'select', 'swps_schema_section', [
            'options' => [
                'Article'      => __( 'Article', 'stratawp-seo' ),
                'BlogPosting'  => __( 'BlogPosting', 'stratawp-seo' ),
                'NewsArticle'  => __( 'NewsArticle', 'stratawp-seo' ),
            ],
            'description' => __( 'Schema type for blog posts. Most sites should use Article.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_searchbox', __( 'Sitelinks Searchbox', 'stratawp-seo' ), 'checkbox', 'swps_schema_section', [
            'label' => __( 'Add SearchAction to enable Google sitelinks search box on your homepage', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_entity_type', __( 'Site Represents', 'stratawp-seo' ), 'select', 'swps_schema_section', [
            'options' => [
                'Organization' => __( 'Organization', 'stratawp-seo' ),
                'Person'       => __( 'Person', 'stratawp-seo' ),
            ],
            'description' => __( 'Does this website represent an organization or an individual?', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_name', __( 'Name', 'stratawp-seo' ), 'text', 'swps_schema_section', [
            'placeholder' => get_bloginfo( 'name' ),
            'description' => __( 'Organization or person name. Leave blank to use your site name.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_logo', __( 'Logo URL', 'stratawp-seo' ), 'text', 'swps_schema_section', [
            'placeholder' => 'https://example.com/logo.png',
            'description' => __( 'Full URL to your logo image (minimum 112x112px). Used for Organization schema and Article publisher.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'schema_social_profiles', __( 'Social Profiles', 'stratawp-seo' ), 'textarea', 'swps_schema_section', [
            'rows'        => 4,
            'placeholder' => "https://facebook.com/yourpage\nhttps://twitter.com/yourhandle\nhttps://linkedin.com/company/yourcompany",
            'description' => __( 'One social profile URL per line. Populates the sameAs property.', 'stratawp-seo' ),
        ] );

        // --- SEO Meta Section ---
        add_settings_section( 'swps_meta_section', __( 'SEO Meta', 'stratawp-seo' ), [ $this, 'render_meta_section' ], 'stratawp-seo' );

        $this->add_field( 'meta_editor_enabled', __( 'Enable Meta Editor', 'stratawp-seo' ), 'checkbox', 'swps_meta_section', [
            'label' => __( 'Show SEO meta fields (title, description, social, robots) on post and page editors', 'stratawp-seo' ),
        ] );

        $this->add_field( 'meta_editor_post_types', __( 'Post Types', 'stratawp-seo' ), 'text', 'swps_meta_section', [
            'placeholder' => 'post,page',
            'description' => __( 'Comma-separated post types to show the meta editor on. Default: post,page', 'stratawp-seo' ),
        ] );

        $this->add_field( 'meta_auto_generate', __( 'Auto-Generate Meta', 'stratawp-seo' ), 'checkbox', 'swps_meta_section', [
            'label' => __( 'Automatically generate meta title and description via AI when publishing a post without them', 'stratawp-seo' ),
        ] );

        // --- Analytics Section ---
        add_settings_section( 'swps_analytics_section', __( 'Analytics', 'stratawp-seo' ), [ $this, 'render_analytics_section' ], 'stratawp-seo' );

        $this->add_field( 'analytics_enabled', __( 'Enable On-Site Tracking', 'stratawp-seo' ), 'checkbox', 'swps_analytics_section', [
            'label' => __( 'Track page views, time on page, scroll depth, and bounce rate (cookie-free, GDPR-friendly)', 'stratawp-seo' ),
        ] );

        $this->add_field( 'analytics_retention', __( 'Data Retention', 'stratawp-seo' ), 'select', 'swps_analytics_section', [
            'options' => [
                '30'  => __( '30 days', 'stratawp-seo' ),
                '90'  => __( '90 days', 'stratawp-seo' ),
                '180' => __( '180 days', 'stratawp-seo' ),
                '365' => __( '365 days', 'stratawp-seo' ),
            ],
            'description' => __( 'How long to keep analytics data. Older data is automatically pruned.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'analytics_exclude_admins', __( 'Exclude Admins', 'stratawp-seo' ), 'checkbox', 'swps_analytics_section', [
            'label' => __( 'Don\'t track visits from logged-in administrators', 'stratawp-seo' ),
        ] );

        $this->add_field( 'gsc_client_id', __( 'Google OAuth Client ID', 'stratawp-seo' ), 'text', 'swps_analytics_section', [
            'placeholder' => 'xxxx.apps.googleusercontent.com',
            'description' => __( 'Create OAuth credentials in your <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>. Set the redirect URI to: <code>' . admin_url( 'admin.php?swps_gsc_callback=1' ) . '</code>', 'stratawp-seo' ),
        ] );

        $this->add_field( 'gsc_client_secret', __( 'Google OAuth Client Secret', 'stratawp-seo' ), 'password', 'swps_analytics_section', [
            'description' => __( 'Stored encrypted. After saving, connect via the Analytics page.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'keyword_tracking_frequency', __( 'Keyword Sync Frequency', 'stratawp-seo' ), 'select', 'swps_analytics_section', [
            'options' => [
                'daily'   => __( 'Daily', 'stratawp-seo' ),
                'weekly'  => __( 'Weekly', 'stratawp-seo' ),
                'monthly' => __( 'Monthly', 'stratawp-seo' ),
            ],
            'description' => __( 'How often to sync tracked keyword positions from Google Search Console.', 'stratawp-seo' ),
        ] );

        // --- Advanced Section (v2.0) ---
        add_settings_section( 'swps_advanced_section', __( 'Advanced Settings', 'stratawp-seo' ), [ $this, 'render_advanced_section' ], 'stratawp-seo' );

        $this->add_field( 'default_template', __( 'Default Content Template', 'stratawp-seo' ), 'select', 'swps_advanced_section', [
            'options'     => SWPS_Templates::get_options(),
            'description' => __( 'Default template for generated content. Can be overridden per generation.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'rate_limit', __( 'Rate Limit Cooldown', 'stratawp-seo' ), 'number', 'swps_advanced_section', [
            'min'         => 0,
            'max'         => 600,
            'description' => __( 'Seconds to wait between generations (prevents accidental double-clicks). 0 to disable.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'duplicate_check', __( 'Duplicate Detection', 'stratawp-seo' ), 'checkbox', 'swps_advanced_section', [
            'label' => __( 'Check for duplicate titles before creating posts (85% similarity threshold)', 'stratawp-seo' ),
        ] );

        $this->add_field( 'cost_tracking', __( 'Cost Tracking', 'stratawp-seo' ), 'checkbox', 'swps_advanced_section', [
            'label' => __( 'Track token usage and estimated costs per generation', 'stratawp-seo' ),
        ] );

        $this->add_field( 'min_content_score', __( 'Minimum Content Score', 'stratawp-seo' ), 'number', 'swps_advanced_section', [
            'min'         => 0,
            'max'         => 100,
            'description' => __( 'Posts scoring below this threshold are saved as drafts. Set to 0 to disable.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'jon_ai_endpoint', __( 'Remote Content Endpoint', 'stratawp-seo' ), 'text', 'swps_advanced_section', [
            'placeholder' => 'https://example.com/wp-json/jon-ai/v1/create-posts',
            'description' => __( 'Optional: Remote endpoint for duplicate checking against an external site.', 'stratawp-seo' ),
        ] );

        $this->add_field( 'jon_ai_secret', __( 'Remote Content Secret', 'stratawp-seo' ), 'password', 'swps_advanced_section', [
            'description' => __( 'Authentication secret for the remote content endpoint. Stored encrypted.', 'stratawp-seo' ),
        ] );

        // --- Head Cleanup Section ---
        add_settings_section( 'swps_cleanup_section', __( 'Head Cleanup', 'stratawp-seo' ), [ $this, 'render_cleanup_section' ], 'stratawp-seo' );

        $cleanup_fields = [
            'cleanup_generator' => __( 'Remove WP Generator Tag', 'stratawp-seo' ),
            'cleanup_rsd'       => __( 'Remove RSD/EditURI Link', 'stratawp-seo' ),
            'cleanup_wlw'       => __( 'Remove Windows Live Writer Link', 'stratawp-seo' ),
            'cleanup_shortlink' => __( 'Remove Shortlink', 'stratawp-seo' ),
            'cleanup_rest_api'  => __( 'Remove REST API Link', 'stratawp-seo' ),
            'cleanup_oembed'    => __( 'Remove oEmbed Discovery', 'stratawp-seo' ),
            'cleanup_emoji'     => __( 'Remove Emoji Scripts & Styles', 'stratawp-seo' ),
        ];

        foreach ( $cleanup_fields as $key => $label ) {
            $this->add_field( $key, $label, 'checkbox', 'swps_cleanup_section' );
        }

        // --- RSS Optimization Section ---
        add_settings_section( 'swps_rss_section', __( 'RSS Feed', 'stratawp-seo' ), [ $this, 'render_rss_section' ], 'stratawp-seo' );

        $this->add_field( 'rss_before', __( 'Content Before Post in RSS', 'stratawp-seo' ), 'textarea', 'swps_rss_section' );
        $this->add_field( 'rss_after', __( 'Content After Post in RSS', 'stratawp-seo' ), 'textarea', 'swps_rss_section' );

        // --- Sitemap Settings Section ---
        add_settings_section( 'swps_sitemap_section', __( 'Sitemaps', 'stratawp-seo' ), [ $this, 'render_sitemap_section' ], 'stratawp-seo' );

        $this->add_field( 'sitemap_exclude_images', __( 'Exclude Images from Sitemap', 'stratawp-seo' ), 'checkbox', 'swps_sitemap_section' );
        $this->add_field( 'sitemap_exclude_author', __( 'Exclude Author Sitemap', 'stratawp-seo' ), 'checkbox', 'swps_sitemap_section' );
        $this->add_field( 'auto_redirect_slug_change', __( 'Auto-redirect on slug change', 'stratawp-seo' ), 'checkbox', 'swps_sitemap_section' );

        // --- Search Appearance Settings (separate options group) ---
        register_setting( 'swps_search_appearance', 'swps_title_separator', [ 'sanitize_callback' => 'sanitize_text_field' ] );

        foreach ( get_post_types( [ 'public' => true ] ) as $pt ) {
            if ( 'attachment' === $pt ) continue;
            register_setting( 'swps_search_appearance', "swps_title_template_{$pt}", [ 'sanitize_callback' => 'sanitize_text_field' ] );
            register_setting( 'swps_search_appearance', "swps_desc_template_{$pt}", [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
            register_setting( 'swps_search_appearance', "swps_noindex_{$pt}", [ 'sanitize_callback' => 'absint' ] );
        }

        foreach ( get_taxonomies( [ 'public' => true ] ) as $tax ) {
            if ( 'post_format' === $tax ) continue;
            register_setting( 'swps_search_appearance', "swps_title_template_{$tax}", [ 'sanitize_callback' => 'sanitize_text_field' ] );
            register_setting( 'swps_search_appearance', "swps_noindex_{$tax}", [ 'sanitize_callback' => 'absint' ] );
        }

        register_setting( 'swps_search_appearance', 'swps_title_template_search', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'swps_search_appearance', 'swps_title_template_404', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'swps_search_appearance', 'swps_title_template_author', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'swps_search_appearance', 'swps_title_template_date', [ 'sanitize_callback' => 'sanitize_text_field' ] );

        // Breadcrumb settings.
        register_setting( 'swps_search_appearance', 'swps_breadcrumbs_enabled', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'swps_search_appearance', 'swps_breadcrumbs_separator', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'swps_search_appearance', 'swps_breadcrumbs_home_label', [ 'sanitize_callback' => 'sanitize_text_field' ] );

        // SEO Column settings.
        register_setting( 'swps_search_appearance', 'swps_seo_column_post_types', [
            'sanitize_callback' => function ( $value ) {
                return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : [ 'post', 'page' ];
            },
            'default' => [ 'post', 'page' ],
        ] );
        register_setting( 'swps_search_appearance', 'swps_seo_score_content_min', [
            'sanitize_callback' => 'absint',
            'default' => 300,
        ] );
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
    private function add_field( string $key, string $title, string $type, string $section, array $args = [] ): void {
        $option_name = "swps_{$key}";

        register_setting( 'stratawp-seo', $option_name, [
            'sanitize_callback' => $this->get_sanitize_callback( $type, $key ),
        ] );

        $field_args = array_merge( $args, [
            'key'  => $key,
            'type' => $type,
            'name' => $option_name,
        ] );

        $extra = [];
        if ( ! empty( $args['row_class'] ) ) {
            $extra['class'] = $args['row_class'];
        }

        add_settings_field(
            $option_name,
            $title,
            [ $this, 'render_field' ],
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
        $name  = $args['name'];
        $type  = $args['type'];
        $value = get_option( $name, '' );
        $desc  = $args['description'] ?? '';

        // Don't display encrypted values — show empty field with saved indicator.
        $has_encrypted_value = false;
        if ( in_array( $args['key'], [ 'jon_ai_secret', 'gsc_client_secret' ], true ) && ! empty( $value ) && SWPS_Encryption::is_encrypted( $value ) ) {
            $has_encrypted_value = true;
            $value = '';
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
                foreach ( ( $args['options'] ?? [] ) as $val => $label ) {
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

            case 'time':
                printf(
                    '<input type="time" name="%s" value="%s" />',
                    esc_attr( $name ),
                    esc_attr( $value ?: '09:00' )
                );
                break;

            case 'author_select':
                wp_dropdown_users( [
                    'name'     => $name,
                    'selected' => $value ?: get_current_user_id(),
                    'who'      => 'authors',
                ] );
                break;

            case 'category_select':
                wp_dropdown_categories( [
                    'name'             => $name,
                    'selected'         => $value,
                    'show_option_none' => __( '— AI Decides —', 'stratawp-seo' ),
                    'option_none_value' => 0,
                    'hide_empty'       => false,
                ] );
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

        if ( in_array( $action, [ 'new', 'edit' ], true ) ) {
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
