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
    }

    /**
     * Register all settings.
     */
    public function register_settings(): void {
        // --- API Section ---
        add_settings_section( 'swps_api_section', __( 'API Configuration', 'stratawp-seo' ), [ $this, 'render_api_section' ], 'stratawp-seo' );

        $this->add_field( 'api_key', __( 'Anthropic API Key', 'stratawp-seo' ), 'password', 'swps_api_section', [
            'description' => __( 'Get your API key from <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>', 'stratawp-seo' ),
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
            'min' => 300,
            'max' => 5000,
            'step' => 100,
        ] );

        $this->add_field( 'word_count_max', __( 'Maximum Word Count', 'stratawp-seo' ), 'number', 'swps_content_section', [
            'min' => 500,
            'max' => 8000,
            'step' => 100,
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
    }

    /**
     * Helper to register a setting field.
     */
    private function add_field( string $key, string $title, string $type, string $section, array $args = [] ): void {
        $option_name = "swps_{$key}";

        register_setting( 'stratawp-seo', $option_name, [
            'sanitize_callback' => $this->get_sanitize_callback( $type ),
        ] );

        add_settings_field(
            $option_name,
            $title,
            [ $this, 'render_field' ],
            'stratawp-seo',
            $section,
            array_merge( $args, [
                'key'  => $key,
                'type' => $type,
                'name' => $option_name,
            ] )
        );
    }

    /**
     * Get the appropriate sanitize callback for a field type.
     */
    private function get_sanitize_callback( string $type ): callable {
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
                printf( '<select name="%s">', esc_attr( $name ) );
                foreach ( $args['options'] as $val => $label ) {
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

        if ( $desc ) {
            printf( '<p class="description">%s</p>', wp_kses_post( $desc ) );
        }
    }

    // --- Section descriptions ---

    public function render_api_section(): void {
        echo '<p>' . esc_html__( 'Connect to the Anthropic Claude API to power AI content generation.', 'stratawp-seo' ) . '</p>';
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

    /**
     * Render the main settings page.
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Handle schedule update on save.
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

        // Check for API key.
        if ( empty( get_option( 'swps_api_key' ) ) ) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
                esc_html__( 'StrataWP SEO:', 'stratawp-seo' ),
                esc_html__( 'Please enter your Anthropic API key to get started.', 'stratawp-seo' )
            );
        }

        // Check for site niche.
        if ( empty( get_option( 'swps_site_niche' ) ) ) {
            printf(
                '<div class="notice notice-info"><p><strong>%s</strong> %s</p></div>',
                esc_html__( 'StrataWP SEO:', 'stratawp-seo' ),
                esc_html__( 'Enter your site niche and description for better content generation.', 'stratawp-seo' )
            );
        }
    }
}
