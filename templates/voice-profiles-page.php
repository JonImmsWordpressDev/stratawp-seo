<div class="wrap swps-wrap">
    <div class="swps-page-header">
        <span class="swps-page-header-orb"></span>
        <span class="swps-page-header-orb"></span>
        <h1>
            <span class="dashicons dashicons-format-status"></span>
            <?php esc_html_e( 'Voice Profiles', 'stratawp-seo' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-voice-profiles&action=new' ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Add New', 'stratawp-seo' ); ?>
            </a>
        </h1>
        <p><?php esc_html_e( 'Define consistent brand voices for your AI content', 'stratawp-seo' ); ?></p>
    </div>

    <?php
    $active_id = (int) get_option( 'swps_voice_profile', 0 );
    $profiles  = stratawp_seo()->voice_profile->get_all();
    ?>

    <?php if ( empty( $profiles ) ) : ?>
        <div class="swps-card">
            <p><?php esc_html_e( 'No voice profiles yet. Create one to define a consistent brand voice for your AI-generated content.', 'stratawp-seo' ); ?></p>
        </div>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Tone', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Formality', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Person', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'stratawp-seo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $profiles as $profile ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $profile['name'] ); ?></strong></td>
                    <td><?php echo esc_html( ucfirst( $profile['tone'] ) ); ?></td>
                    <td><?php echo esc_html( $profile['formality'] . '/10' ); ?></td>
                    <td><?php echo esc_html( ucfirst( $profile['person'] ) ); ?></td>
                    <td>
                        <?php if ( $profile['id'] === $active_id ) : ?>
                            <span class="swps-score-badge swps-score-badge--excellent"><?php esc_html_e( 'Active', 'stratawp-seo' ); ?></span>
                        <?php else : ?>
                            <span style="color: #646970;"><?php esc_html_e( 'Inactive', 'stratawp-seo' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-voice-profiles&action=edit&profile_id=' . $profile['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'stratawp-seo' ); ?></a>
                        |
                        <a href="#" class="swps-delete-profile" data-id="<?php echo esc_attr( $profile['id'] ); ?>" style="color: #d63638;"><?php esc_html_e( 'Delete', 'stratawp-seo' ); ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
