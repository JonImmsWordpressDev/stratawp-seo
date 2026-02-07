<div class="wrap swps-wrap">
    <h1>
        <span class="dashicons dashicons-superhero-alt" style="font-size: 28px; margin-right: 8px;"></span>
        <?php esc_html_e( 'StrataWP SEO — Settings', 'stratawp-seo' ); ?>
    </h1>

    <div class="swps-header-bar">
        <p><?php esc_html_e( 'Configure your AI-powered SEO content generator. Fill in your site details and preferences, then head to', 'stratawp-seo' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-generate' ) ); ?>"><?php esc_html_e( 'Generate Content', 'stratawp-seo' ); ?></a>
        <?php esc_html_e( 'to create your first post.', 'stratawp-seo' ); ?></p>
    </div>

    <form method="post" action="options.php">
        <?php
        settings_fields( 'stratawp-seo' );
        do_settings_sections( 'stratawp-seo' );
        submit_button( __( 'Save Settings', 'stratawp-seo' ) );
        ?>
    </form>

    <?php
    // Show generation log.
    $log = get_option( 'swps_generation_log', [] );
    if ( ! empty( $log ) ) :
    ?>
    <div class="swps-log-section">
        <h2><?php esc_html_e( 'Recent Activity', 'stratawp-seo' ); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Time', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Message', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( array_reverse( $log ) as $entry ) : ?>
                <tr>
                    <td><?php echo esc_html( $entry['time'] ); ?></td>
                    <td><?php echo esc_html( $entry['message'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
