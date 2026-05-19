<?php
/**
 * Internal Links overview admin page (v4.0).
 *
 * @var array $stats Stats from SWPS_Internal_Links_Admin::get_stats().
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap swps-internal-links-wrap">

	<?php
	$title    = __( 'Internal Links', 'stratawp-seo' );
	$subtitle = __( 'AI + keyword-based internal linking. Rebuild the index, then accept link suggestions per post.', 'stratawp-seo' );
	$actions  = array(
		array(
			'label' => __( 'Rebuild Index', 'stratawp-seo' ),
			'class' => 'swps-btn-grad',
			'attrs' => 'id="swps-rebuild-index"',
		),
	);
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<div id="swps-rebuild-progress" style="display:none; margin-bottom:16px;">
		<progress id="swps-rebuild-bar" value="0" max="100" style="width:300px;"></progress>
		<span id="swps-rebuild-text" style="margin-left:10px;color:var(--swps-text-muted);font-size:12px"></span>
	</div>
	<span id="swps-rebuild-status"></span>

	<div class="swps-summary-tiles">
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Total Internal Links', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-grad"><?php echo esc_html( number_format( (int) $stats['total_links'] ) ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Avg Links/Post', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-grad"><?php echo esc_html( $stats['avg_links'] ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Orphan Pages', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num <?php echo $stats['orphan_count'] > 0 ? 'is-crit' : 'is-ok'; ?>">
				<?php echo (int) $stats['orphan_count']; ?>
			</div>
			<div class="swps-summary-tile-foot"><?php esc_html_e( 'no inbound links', 'stratawp-seo' ); ?></div>
		</div>
		<div class="swps-summary-tile">
			<div class="swps-summary-tile-h"><?php esc_html_e( 'Pending Suggestions', 'stratawp-seo' ); ?></div>
			<div class="swps-summary-tile-num is-grad"><?php echo (int) $stats['pending_suggestions']; ?></div>
		</div>
	</div>

	<?php if ( ! empty( $stats['most_linked'] ) ) : ?>
	<div class="swps-section-h">
		<h3><?php esc_html_e( 'Most Linked Posts', 'stratawp-seo' ); ?></h3>
	</div>
	<table class="widefat striped" style="max-width:600px; margin-bottom:24px;">
		<thead><tr><th><?php esc_html_e( 'Post', 'stratawp-seo' ); ?></th><th><?php esc_html_e( 'Inbound Links', 'stratawp-seo' ); ?></th></tr></thead>
		<tbody>
		<?php
		foreach ( $stats['most_linked'] as $row ) :
			$post = get_post( (int) $row['target_post_id'] );
			if ( ! $post ) {
				continue; }
			?>
			<tr>
				<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a></td>
				<td><?php echo esc_html( $row['link_count'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<div class="swps-section-h">
		<h3><?php esc_html_e( 'Link Opportunities', 'stratawp-seo' ); ?></h3>
	</div>
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
		<?php
		foreach ( $stats['opportunities'] as $opp ) :
			$source = get_post( (int) $opp['source_post_id'] );
			$target = get_post( (int) $opp['target_post_id'] );
			if ( ! $source || ! $target ) {
				continue; }
			$score = (float) $opp['relevance_score'];
			$color = $score >= 0.7 ? 'var(--swps-success)' : ( $score >= 0.4 ? 'var(--swps-warn)' : 'var(--swps-crit)' );
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

	<div style="margin:16px 0;">
		<button type="button" class="swps-btn swps-btn-secondary" id="swps-bulk-dismiss"><?php esc_html_e( 'Dismiss Selected', 'stratawp-seo' ); ?></button>
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
		<p style="color:var(--swps-text-muted)"><?php esc_html_e( 'No link opportunities found. Try rebuilding the index.', 'stratawp-seo' ); ?></p>
	<?php endif; ?>

	<div class="swps-section-h" style="margin-top:32px">
		<h3><?php esc_html_e( 'Orphan Pages', 'stratawp-seo' ); ?></h3>
	</div>
	<?php if ( ! empty( $stats['orphan_posts'] ) ) : ?>
	<p style="color:var(--swps-text-muted)"><?php esc_html_e( 'These posts have no inbound internal links — consider linking to them from related content.', 'stratawp-seo' ); ?></p>
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
		<p style="color:var(--swps-success)"><?php esc_html_e( 'No orphan pages found. All posts have at least one inbound link.', 'stratawp-seo' ); ?></p>
	<?php endif; ?>
</div>
