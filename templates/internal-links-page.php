<?php
/**
 * Internal Links overview admin page template.
 *
 * @var array $stats Stats from SWPS_Internal_Links_Admin::get_stats().
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Internal Links', 'stratawp-seo' ); ?></h1>

    <div class="swps-link-actions-bar" style="margin: 15px 0;">
        <button type="button" class="button button-primary" id="swps-rebuild-index">
            <?php esc_html_e( 'Rebuild Index', 'stratawp-seo' ); ?>
        </button>
        <span id="swps-rebuild-status"></span>
        <div id="swps-rebuild-progress" style="display:none; margin-top:8px;">
            <progress id="swps-rebuild-bar" value="0" max="100" style="width:300px;"></progress>
            <span id="swps-rebuild-text"></span>
        </div>
    </div>

    <!-- Health Summary -->
    <div class="swps-link-health" style="display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap;">
        <div class="card" style="padding:15px; min-width:150px;">
            <h3 style="margin:0 0 5px;"><?php echo esc_html( $stats['total_links'] ); ?></h3>
            <p style="margin:0; color:#666;"><?php esc_html_e( 'Total Internal Links', 'stratawp-seo' ); ?></p>
        </div>
        <div class="card" style="padding:15px; min-width:150px;">
            <h3 style="margin:0 0 5px;"><?php echo esc_html( $stats['avg_links'] ); ?></h3>
            <p style="margin:0; color:#666;"><?php esc_html_e( 'Avg Links/Post', 'stratawp-seo' ); ?></p>
        </div>
        <div class="card" style="padding:15px; min-width:150px;">
            <h3 style="margin:0 0 5px; color:<?php echo $stats['orphan_count'] > 0 ? '#d63638' : '#00a32a'; ?>;">
                <?php echo esc_html( $stats['orphan_count'] ); ?>
            </h3>
            <p style="margin:0; color:#666;"><?php esc_html_e( 'Orphan Pages', 'stratawp-seo' ); ?></p>
        </div>
        <div class="card" style="padding:15px; min-width:150px;">
            <h3 style="margin:0 0 5px;"><?php echo esc_html( $stats['pending_suggestions'] ); ?></h3>
            <p style="margin:0; color:#666;"><?php esc_html_e( 'Pending Suggestions', 'stratawp-seo' ); ?></p>
        </div>
    </div>

    <?php if ( ! empty( $stats['most_linked'] ) ) : ?>
    <h2><?php esc_html_e( 'Most Linked Posts', 'stratawp-seo' ); ?></h2>
    <table class="widefat striped" style="max-width:600px; margin-bottom:20px;">
        <thead><tr><th><?php esc_html_e( 'Post', 'stratawp-seo' ); ?></th><th><?php esc_html_e( 'Inbound Links', 'stratawp-seo' ); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $stats['most_linked'] as $row ) :
            $post = get_post( (int) $row['target_post_id'] );
            if ( ! $post ) continue;
        ?>
            <tr>
                <td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a></td>
                <td><?php echo esc_html( $row['link_count'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Opportunities Table -->
    <h2><?php esc_html_e( 'Link Opportunities', 'stratawp-seo' ); ?></h2>
    <?php if ( ! empty( $stats['opportunities'] ) ) : ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><input type="checkbox" id="swps-check-all" /></th>
                <th><?php esc_html_e( 'Source Post', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Target Post', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Relevance', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Type', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Anchor Text', 'stratawp-seo' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $stats['opportunities'] as $opp ) :
            $source = get_post( (int) $opp['source_post_id'] );
            $target = get_post( (int) $opp['target_post_id'] );
            if ( ! $source || ! $target ) continue;
            $score = (float) $opp['relevance_score'];
            $color = $score >= 0.7 ? '#00a32a' : ( $score >= 0.4 ? '#dba617' : '#d63638' );
        ?>
            <tr>
                <td><input type="checkbox" class="swps-opp-check" value="<?php echo esc_attr( $opp['id'] ); ?>" /></td>
                <td><a href="<?php echo esc_url( get_edit_post_link( $source->ID ) ); ?>"><?php echo esc_html( $source->post_title ); ?></a></td>
                <td><a href="<?php echo esc_url( get_edit_post_link( $target->ID ) ); ?>"><?php echo esc_html( $target->post_title ); ?></a></td>
                <td><span style="color:<?php echo esc_attr( $color ); ?>;">&#9679;</span> <?php echo esc_html( round( $score * 100 ) ); ?>%</td>
                <td><?php echo esc_html( $opp['match_type'] ); ?></td>
                <td><?php echo esc_html( $opp['anchor_text'] ?: '—' ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin:10px 0;">
        <button type="button" class="button" id="swps-bulk-dismiss"><?php esc_html_e( 'Dismiss Selected', 'stratawp-seo' ); ?></button>
    </div>

    <?php if ( $stats['total_pages'] > 1 ) : ?>
    <div class="tablenav">
        <div class="tablenav-pages">
            <?php for ( $i = 1; $i <= $stats['total_pages']; $i++ ) : ?>
                <?php if ( $i === $stats['current_page'] ) : ?>
                    <span class="tablenav-pages-navspan button disabled"><?php echo esc_html( $i ); ?></span>
                <?php else : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( 'link_page', $i ) ); ?>"><?php echo esc_html( $i ); ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else : ?>
        <p><?php esc_html_e( 'No link opportunities found. Try rebuilding the index.', 'stratawp-seo' ); ?></p>
    <?php endif; ?>

    <!-- Orphan Pages -->
    <h2><?php esc_html_e( 'Orphan Pages', 'stratawp-seo' ); ?></h2>
    <?php if ( ! empty( $stats['orphan_posts'] ) ) : ?>
    <p><?php esc_html_e( 'These posts have no inbound internal links — consider linking to them from related content.', 'stratawp-seo' ); ?></p>
    <table class="widefat striped" style="max-width:600px;">
        <thead><tr><th><?php esc_html_e( 'Post', 'stratawp-seo' ); ?></th><th><?php esc_html_e( 'Published', 'stratawp-seo' ); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $stats['orphan_posts'] as $orphan ) : ?>
            <tr>
                <td><a href="<?php echo esc_url( get_edit_post_link( (int) $orphan['ID'] ) ); ?>"><?php echo esc_html( $orphan['post_title'] ); ?></a></td>
                <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $orphan['post_date'] ) ) ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p style="color:#00a32a;"><?php esc_html_e( 'No orphan pages found. All posts have at least one inbound link.', 'stratawp-seo' ); ?></p>
    <?php endif; ?>
</div>
