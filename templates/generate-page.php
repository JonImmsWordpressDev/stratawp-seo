<?php
$has_api_key = ! empty( SWPS_Provider_Factory::create_ai_provider()->get_api_key() );
$has_niche   = ! empty( get_option( 'swps_site_niche' ) );
$schedule    = SWPS_Cron::get_schedule_info();
?>
<div class="wrap swps-wrap">
    <h1>
        <span class="dashicons dashicons-superhero-alt" style="font-size: 28px; margin-right: 8px;"></span>
        <?php esc_html_e( 'Generate SEO Content', 'stratawp-seo' ); ?>
    </h1>

    <?php if ( ! $has_api_key ) : ?>
        <div class="notice notice-error">
            <p><?php printf(
                __( 'You need to <a href="%s">add your AI provider API key</a> before generating content.', 'stratawp-seo' ),
                esc_url( admin_url( 'admin.php?page=stratawp-seo' ) )
            ); ?></p>
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

            <div class="swps-generate-actions">
                <button
                    type="button"
                    id="swps-generate-btn"
                    class="button button-primary button-hero"
                    <?php echo ! $has_api_key ? 'disabled' : ''; ?>
                >
                    <span class="dashicons dashicons-edit-large" style="margin-top: 4px;"></span>
                    <?php esc_html_e( 'Generate Post', 'stratawp-seo' ); ?>
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
                    <p><?php printf(
                        __( 'Enable automated posting in <a href="%s">Settings</a>.', 'stratawp-seo' ),
                        esc_url( admin_url( 'admin.php?page=stratawp-seo' ) )
                    ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Site Stats Card -->
        <div class="swps-card swps-card-stats">
            <h2><?php esc_html_e( 'Site Overview', 'stratawp-seo' ); ?></h2>
            <?php
            $post_count = wp_count_posts( 'post' );
            $generated  = new WP_Query( [
                'post_type'      => 'post',
                'post_status'    => 'any',
                'meta_key'       => '_swps_generated',
                'meta_value'     => '1',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ] );
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

</div>
