<?php
/**
 * HTML digest email template — 600px inline-CSS table layout.
 *
 * Expects local vars from SWPS_Digest::send(): $data (array of sections),
 * $accent (hex color), $logo (URL or ''), $footer (string or '').
 * Sections render only when their key is present and non-empty.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$swps_site = get_bloginfo( 'name' );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="UTF-8">
<meta name="color-scheme" content="light dark">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#f0f0f1;font-family:Helvetica,Arial,sans-serif;color:#1d2327;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f0f1;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">

	<?php /* Header bar with optional logo. */ ?>
	<tr><td style="background-color:<?php echo esc_attr( $accent ); ?>;padding:20px 32px;">
		<?php if ( ! empty( $logo ) ) : ?>
			<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $swps_site ); ?>" height="40" style="display:block;max-height:40px;width:auto;margin-bottom:8px;">
		<?php endif; ?>
		<h1 style="margin:0;font-size:20px;line-height:1.3;color:#ffffff;"><?php echo esc_html( $swps_site ); ?> — <?php esc_html_e( 'SEO Digest', 'stratawp-seo' ); ?></h1>
		<p style="margin:4px 0 0;font-size:13px;color:#ffffff;opacity:0.85;"><?php echo esc_html( wp_date( get_option( 'date_format' ) ) ); ?></p>
	</td></tr>

	<?php /* Attention section — always first. */ ?>
	<tr><td style="padding:24px 32px 8px;">
		<?php if ( ! empty( $data['attention'] ) ) : ?>
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-left:4px solid #d63638;background-color:#fcf0f1;">
			<tr><td style="padding:14px 18px;">
				<h2 style="margin:0 0 8px;font-size:15px;color:#d63638;"><?php esc_html_e( 'Needs your attention', 'stratawp-seo' ); ?></h2>
				<?php foreach ( $data['attention'] as $swps_item ) : ?>
					<p style="margin:0 0 4px;font-size:14px;line-height:1.5;">&bull; <?php echo esc_html( $swps_item ); ?></p>
				<?php endforeach; ?>
			</td></tr>
			</table>
		<?php else : ?>
			<p style="margin:0;font-size:14px;line-height:1.6;"><?php esc_html_e( 'Nothing needs your attention this week. 🎉', 'stratawp-seo' ); ?></p>
		<?php endif; ?>
	</td></tr>

	<?php /* AI executive summary. */ ?>
	<?php if ( ! empty( $data['ai_summary'] ) ) : ?>
	<tr><td style="padding:16px 32px 0;">
		<p style="margin:0;font-size:14px;line-height:1.6;font-style:italic;color:#50575e;"><?php echo esc_html( $data['ai_summary'] ); ?></p>
	</td></tr>
	<?php endif; ?>

	<?php /* Generated posts. */ ?>
	<?php if ( ! empty( $data['generated'] ) ) : ?>
	<tr><td style="padding:24px 32px 0;">
		<h2 style="margin:0 0 10px;font-size:16px;color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Posts generated', 'stratawp-seo' ); ?></h2>
		<?php foreach ( $data['generated'] as $swps_post ) : ?>
			<p style="margin:0 0 6px;font-size:14px;line-height:1.5;">
				<a href="<?php echo esc_url( (string) $swps_post['edit_link'] ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;text-decoration:none;"><?php echo esc_html( $swps_post['title'] ); ?></a>
				<?php if ( null !== $swps_post['seo_score'] ) : ?>
					<span style="color:#50575e;font-size:12px;"> · SEO <?php echo esc_html( (string) $swps_post['seo_score'] ); ?></span>
				<?php endif; ?>
				<?php if ( null !== $swps_post['aeo_score'] ) : ?>
					<span style="color:#50575e;font-size:12px;"> · AEO <?php echo esc_html( (string) $swps_post['aeo_score'] ); ?></span>
				<?php endif; ?>
				<?php if ( null !== $swps_post['cost'] ) : ?>
					<span style="color:#50575e;font-size:12px;"> · $<?php echo esc_html( number_format( (float) $swps_post['cost'], 4 ) ); ?></span>
				<?php endif; ?>
			</p>
		<?php endforeach; ?>
	</td></tr>
	<?php endif; ?>

	<?php /* Generation failures. */ ?>
	<?php if ( ! empty( $data['failures'] ) ) : ?>
	<tr><td style="padding:24px 32px 0;">
		<h2 style="margin:0 0 10px;font-size:16px;color:#d63638;"><?php esc_html_e( 'Generation failures', 'stratawp-seo' ); ?></h2>
		<?php foreach ( $data['failures'] as $swps_failure ) : ?>
			<p style="margin:0 0 6px;font-size:13px;line-height:1.5;color:#50575e;">
				<strong style="color:#1d2327;"><?php echo esc_html( $swps_failure['topic'] ?? '' ); ?></strong>
				— <?php echo esc_html( $swps_failure['message'] ?? '' ); ?>
				<span style="font-size:12px;">(<?php echo esc_html( wp_date( get_option( 'date_format' ), (int) ( $swps_failure['time'] ?? 0 ) ) ); ?>)</span>
			</p>
		<?php endforeach; ?>
		<p style="margin:6px 0 0;font-size:13px;"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=swps_topic' ) ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Review the topic queue', 'stratawp-seo' ); ?></a></p>
	</td></tr>
	<?php endif; ?>

	<?php /* Keyword winners / losers. */ ?>
	<?php if ( ! empty( $data['keywords'] ) ) : ?>
	<tr><td style="padding:24px 32px 0;">
		<h2 style="margin:0 0 10px;font-size:16px;color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Keyword movers', 'stratawp-seo' ); ?></h2>
		<?php if ( ! empty( $data['keywords']['winners'] ) ) : ?>
			<?php foreach ( $data['keywords']['winners'] as $swps_kw => $swps_delta ) : ?>
				<p style="margin:0 0 4px;font-size:14px;">▲ <?php echo esc_html( $swps_kw ); ?> <span style="color:#00a32a;font-size:13px;">(<?php echo esc_html( number_format( abs( $swps_delta ), 1 ) ); ?> <?php esc_html_e( 'positions up', 'stratawp-seo' ); ?>)</span></p>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php if ( ! empty( $data['keywords']['losers'] ) ) : ?>
			<?php foreach ( $data['keywords']['losers'] as $swps_kw => $swps_delta ) : ?>
				<p style="margin:0 0 4px;font-size:14px;">▼ <?php echo esc_html( $swps_kw ); ?> <span style="color:#d63638;font-size:13px;">(<?php echo esc_html( number_format( abs( $swps_delta ), 1 ) ); ?> <?php esc_html_e( 'positions down', 'stratawp-seo' ); ?>)</span></p>
			<?php endforeach; ?>
		<?php endif; ?>
		<p style="margin:6px 0 0;font-size:13px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-keywords' ) ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'View keyword tracking', 'stratawp-seo' ); ?></a></p>
	</td></tr>
	<?php endif; ?>

	<?php /* Backlinks. */ ?>
	<?php if ( ! empty( $data['backlinks'] ) ) : ?>
	<tr><td style="padding:24px 32px 0;">
		<h2 style="margin:0 0 10px;font-size:16px;color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Backlinks', 'stratawp-seo' ); ?></h2>
		<p style="margin:0;font-size:14px;line-height:1.6;">
			<?php
			printf(
				/* translators: 1: total, 2: live, 3: new, 4: lost, 5: broken */
				esc_html__( '%1$d total (%2$d live) · %3$d new and %4$d lost in the last 30 days · %5$d broken', 'stratawp-seo' ),
				(int) ( $data['backlinks']['total'] ?? 0 ),
				(int) ( $data['backlinks']['live'] ?? 0 ),
				(int) ( $data['backlinks']['new_30d'] ?? 0 ),
				(int) ( $data['backlinks']['lost_30d'] ?? 0 ),
				(int) ( $data['backlinks']['broken'] ?? 0 )
			);
			?>
		</p>
		<p style="margin:6px 0 0;font-size:13px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-backlinks' ) ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'View backlinks', 'stratawp-seo' ); ?></a></p>
	</td></tr>
	<?php endif; ?>

	<?php /* Competitors. */ ?>
	<?php if ( ! empty( $data['competitors'] ) ) : ?>
	<tr><td style="padding:24px 32px 0;">
		<h2 style="margin:0 0 10px;font-size:16px;color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Competitor activity', 'stratawp-seo' ); ?></h2>
		<?php foreach ( $data['competitors'] as $swps_comp ) : ?>
			<p style="margin:0 0 6px;font-size:14px;line-height:1.5;">
				<strong><?php echo esc_html( $swps_comp['label'] ?? '' ); ?></strong>
				<?php if ( ! empty( $swps_comp['summary'] ) ) : ?>
					— <?php echo esc_html( $swps_comp['summary'] ); ?>
				<?php endif; ?>
			</p>
		<?php endforeach; ?>
		<p style="margin:6px 0 0;font-size:13px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-competitors' ) ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'View competitors', 'stratawp-seo' ); ?></a></p>
	</td></tr>
	<?php endif; ?>

	<?php /* AI-bot crawl trends. */ ?>
	<?php if ( ! empty( $data['bots'] ) ) : ?>
	<tr><td style="padding:24px 32px 0;">
		<h2 style="margin:0 0 10px;font-size:16px;color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'AI bot crawls', 'stratawp-seo' ); ?></h2>
		<?php foreach ( array_slice( $data['bots'], 0, 5 ) as $swps_bot ) : ?>
			<p style="margin:0 0 4px;font-size:14px;">
				<?php echo esc_html( $swps_bot['label'] ?? ( $swps_bot['bot_key'] ?? '' ) ); ?>:
				<strong><?php echo esc_html( number_format( (int) ( $swps_bot['hits'] ?? 0 ) ) ); ?></strong> <?php esc_html_e( 'hits', 'stratawp-seo' ); ?>
			</p>
		<?php endforeach; ?>
		<p style="margin:6px 0 0;font-size:13px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-analytics' ) ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'View bot analytics', 'stratawp-seo' ); ?></a></p>
	</td></tr>
	<?php endif; ?>

	<?php /* AI spend. */ ?>
	<?php if ( ! empty( $data['spend'] ) ) : ?>
	<tr><td style="padding:24px 32px 0;">
		<h2 style="margin:0 0 10px;font-size:16px;color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'AI spend this month', 'stratawp-seo' ); ?></h2>
		<p style="margin:0;font-size:14px;line-height:1.6;">
			<strong>$<?php echo esc_html( number_format( (float) ( $data['spend']['total_cost'] ?? 0 ), 2 ) ); ?></strong>
			<?php if ( isset( $data['spend']['generation_count'] ) ) : ?>
				· <?php echo esc_html( number_format( (int) $data['spend']['generation_count'] ) ); ?> <?php esc_html_e( 'generations', 'stratawp-seo' ); ?>
			<?php endif; ?>
			<?php if ( ! empty( $data['spend']['guardian']['budget'] ) ) : ?>
				· <?php esc_html_e( 'budget', 'stratawp-seo' ); ?> $<?php echo esc_html( number_format( (float) $data['spend']['guardian']['budget'], 2 ) ); ?>
			<?php endif; ?>
		</p>
	</td></tr>
	<?php endif; ?>

	<?php /* AEO movers. */ ?>
	<?php if ( ! empty( $data['aeo_movers'] ) ) : ?>
	<tr><td style="padding:24px 32px 0;">
		<h2 style="margin:0 0 10px;font-size:16px;color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'AEO score movers', 'stratawp-seo' ); ?></h2>
		<?php foreach ( $data['aeo_movers']['risers'] as $swps_pid => $swps_delta ) : ?>
			<p style="margin:0 0 4px;font-size:14px;">▲ <a href="<?php echo esc_url( (string) get_edit_post_link( $swps_pid, 'raw' ) ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;text-decoration:none;"><?php echo esc_html( get_the_title( $swps_pid ) ); ?></a> <span style="color:#00a32a;font-size:13px;">(+<?php echo esc_html( (string) $swps_delta ); ?>)</span></p>
		<?php endforeach; ?>
		<?php foreach ( $data['aeo_movers']['fallers'] as $swps_pid => $swps_delta ) : ?>
			<p style="margin:0 0 4px;font-size:14px;">▼ <a href="<?php echo esc_url( (string) get_edit_post_link( $swps_pid, 'raw' ) ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;text-decoration:none;"><?php echo esc_html( get_the_title( $swps_pid ) ); ?></a> <span style="color:#d63638;font-size:13px;">(<?php echo esc_html( (string) $swps_delta ); ?>)</span></p>
		<?php endforeach; ?>
	</td></tr>
	<?php endif; ?>

	<?php /* Footer. */ ?>
	<tr><td style="padding:28px 32px 24px;">
		<hr style="border:none;border-top:1px solid #dcdcde;margin:0 0 16px;">
		<?php if ( ! empty( $footer ) ) : ?>
			<p style="margin:0 0 6px;font-size:13px;color:#50575e;"><?php echo esc_html( $footer ); ?></p>
		<?php endif; ?>
		<p style="margin:0;font-size:12px;color:#787c82;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( 'Prepared by StrataWP SEO for %s.', 'stratawp-seo' ),
				esc_html( $swps_site )
			);
			?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-settings' ) ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'Digest settings', 'stratawp-seo' ); ?></a>
		</p>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
