<?php
/**
 * Meta editor metabox template.
 *
 * Variables are set by SWPS_Meta_Editor::render_metabox().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="swps-meta-editor" data-post-id="<?php echo esc_attr( $post->ID ); ?>">

	<!-- Google Preview -->
	<div class="swps-serp-preview">
		<h4><?php esc_html_e( 'Google Preview', 'stratawp-seo' ); ?></h4>
		<div class="swps-serp-mock">
			<div class="swps-serp-title" id="swps-serp-title"><?php echo esc_html( $meta_title ?: $post->post_title ); ?></div>
			<div class="swps-serp-url"><?php echo esc_url( get_permalink( $post->ID ) ); ?></div>
			<div class="swps-serp-desc" id="swps-serp-desc"><?php echo esc_html( $meta_description ?: wp_trim_words( $post->post_content, 25, '...' ) ); ?></div>
		</div>
	</div>

	<!-- Focus Keyword -->
	<div class="swps-meta-row">
		<label for="_swps_focus_keyword"><strong><?php esc_html_e( 'Focus Keyword', 'stratawp-seo' ); ?></strong></label>
		<input type="text" name="_swps_focus_keyword" id="_swps_focus_keyword"
				value="<?php echo esc_attr( $focus_keyword ); ?>" class="widefat"
				placeholder="<?php esc_attr_e( 'e.g., best running shoes', 'stratawp-seo' ); ?>" />
	</div>

	<div class="swps-meta-row">
		<label for="_swps_secondary_keywords"><?php esc_html_e( 'Secondary Keywords', 'stratawp-seo' ); ?></label>
		<input type="text" name="_swps_secondary_keywords" id="_swps_secondary_keywords"
				value="<?php echo esc_attr( $secondary_kws ); ?>" class="widefat"
				placeholder="<?php esc_attr_e( 'Comma-separated: running gear, marathon training', 'stratawp-seo' ); ?>" />
	</div>

	<!-- Meta Title -->
	<div class="swps-meta-row">
		<label for="_swps_meta_title">
			<?php esc_html_e( 'Meta Title', 'stratawp-seo' ); ?>
			<span class="swps-char-count" id="swps-title-count">0</span>
		</label>
		<input type="text" name="_swps_meta_title" id="_swps_meta_title"
				value="<?php echo esc_attr( $meta_title ); ?>" class="widefat"
				placeholder="<?php echo esc_attr( $post->post_title ); ?>" />
		<button type="button" class="button button-small" id="swps-ai-generate-meta">
			<?php esc_html_e( 'AI Generate', 'stratawp-seo' ); ?>
		</button>
	</div>

	<!-- Meta Description -->
	<div class="swps-meta-row">
		<label for="_swps_meta_description">
			<?php esc_html_e( 'Meta Description', 'stratawp-seo' ); ?>
			<span class="swps-char-count" id="swps-desc-count">0</span>
		</label>
		<textarea name="_swps_meta_description" id="_swps_meta_description" class="widefat" rows="3"
					placeholder="<?php esc_attr_e( 'Compelling description for search results...', 'stratawp-seo' ); ?>"><?php echo esc_textarea( $meta_description ); ?></textarea>
	</div>

	<!-- SEO Checklist -->
	<div class="swps-seo-checklist" id="swps-seo-checklist">
		<h4><?php esc_html_e( 'SEO Checklist', 'stratawp-seo' ); ?></h4>
		<ul>
			<li data-check="keyword-in-title"><span class="swps-check-icon"></span> <?php esc_html_e( 'Focus keyword in meta title', 'stratawp-seo' ); ?></li>
			<li data-check="keyword-in-desc"><span class="swps-check-icon"></span> <?php esc_html_e( 'Focus keyword in meta description', 'stratawp-seo' ); ?></li>
			<li data-check="title-length"><span class="swps-check-icon"></span> <?php esc_html_e( 'Meta title length (50-60 chars)', 'stratawp-seo' ); ?></li>
			<li data-check="desc-length"><span class="swps-check-icon"></span> <?php esc_html_e( 'Meta description length (140-160 chars)', 'stratawp-seo' ); ?></li>
			<li data-check="keyword-in-content"><span class="swps-check-icon"></span> <?php esc_html_e( 'Focus keyword in first paragraph', 'stratawp-seo' ); ?></li>
			<li data-check="keyword-in-h2"><span class="swps-check-icon"></span> <?php esc_html_e( 'Focus keyword in at least one H2', 'stratawp-seo' ); ?></li>
		</ul>
	</div>

	<!-- Advanced: Canonical, Robots, Breadcrumb -->
	<details class="swps-meta-advanced">
		<summary><?php esc_html_e( 'Advanced', 'stratawp-seo' ); ?></summary>

		<div class="swps-meta-row">
			<label for="_swps_canonical_url"><?php esc_html_e( 'Canonical URL', 'stratawp-seo' ); ?></label>
			<input type="url" name="_swps_canonical_url" id="_swps_canonical_url"
					value="<?php echo esc_url( $canonical_url ); ?>" class="widefat"
					placeholder="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" />
		</div>

		<div class="swps-meta-row">
			<label for="_swps_robots"><?php esc_html_e( 'Robots Meta', 'stratawp-seo' ); ?></label>
			<select name="_swps_robots" id="_swps_robots">
				<option value="" <?php selected( $robots, '' ); ?>><?php esc_html_e( 'Default (index, follow)', 'stratawp-seo' ); ?></option>
				<option value="noindex, follow" <?php selected( $robots, 'noindex, follow' ); ?>><?php esc_html_e( 'noindex, follow', 'stratawp-seo' ); ?></option>
				<option value="index, nofollow" <?php selected( $robots, 'index, nofollow' ); ?>><?php esc_html_e( 'index, nofollow', 'stratawp-seo' ); ?></option>
				<option value="noindex, nofollow" <?php selected( $robots, 'noindex, nofollow' ); ?>><?php esc_html_e( 'noindex, nofollow', 'stratawp-seo' ); ?></option>
			</select>
		</div>

		<div class="swps-meta-row">
			<label for="_swps_breadcrumb_title"><?php esc_html_e( 'Breadcrumb Title', 'stratawp-seo' ); ?></label>
			<input type="text" name="_swps_breadcrumb_title" id="_swps_breadcrumb_title"
					value="<?php echo esc_attr( $breadcrumb_title ); ?>" class="widefat"
					placeholder="<?php echo esc_attr( $post->post_title ); ?>" />
		</div>
	</details>

	<!-- Social Preview -->
	<details class="swps-meta-social">
		<summary><?php esc_html_e( 'Social Previews', 'stratawp-seo' ); ?></summary>

		<div class="swps-meta-row">
			<label for="_swps_social_title"><?php esc_html_e( 'Social Title', 'stratawp-seo' ); ?></label>
			<input type="text" name="_swps_social_title" id="_swps_social_title"
					value="<?php echo esc_attr( $social_title ); ?>" class="widefat"
					placeholder="<?php esc_attr_e( 'Falls back to meta title', 'stratawp-seo' ); ?>" />
		</div>

		<div class="swps-meta-row">
			<label for="_swps_social_description"><?php esc_html_e( 'Social Description', 'stratawp-seo' ); ?></label>
			<textarea name="_swps_social_description" id="_swps_social_description" class="widefat" rows="2"
						placeholder="<?php esc_attr_e( 'Falls back to meta description', 'stratawp-seo' ); ?>"><?php echo esc_textarea( $social_desc ); ?></textarea>
		</div>

		<div class="swps-meta-row">
			<label for="_swps_social_image"><?php esc_html_e( 'Social Image URL', 'stratawp-seo' ); ?></label>
			<input type="url" name="_swps_social_image" id="_swps_social_image"
					value="<?php echo esc_url( $social_image ); ?>" class="widefat"
					placeholder="<?php esc_attr_e( 'Falls back to featured image', 'stratawp-seo' ); ?>" />
		</div>

		<!-- Facebook Preview Mock -->
		<div class="swps-social-preview swps-fb-preview">
			<h5><?php esc_html_e( 'Facebook Preview', 'stratawp-seo' ); ?></h5>
			<div class="swps-social-card">
				<div class="swps-social-image" id="swps-fb-image"></div>
				<div class="swps-social-text">
					<div class="swps-social-domain"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
					<div class="swps-social-title" id="swps-fb-title"></div>
					<div class="swps-social-desc" id="swps-fb-desc"></div>
				</div>
			</div>
		</div>
	</details>

	<!-- Sitemap -->
	<div class="swps-field-group">
		<h4><?php esc_html_e( 'Sitemap', 'stratawp-seo' ); ?></h4>
		<label>
			<input type="checkbox" name="swps_sitemap_exclude" value="1" <?php checked( $sitemap_exclude ); ?>>
			<?php esc_html_e( 'Exclude from sitemap', 'stratawp-seo' ); ?>
		</label>
		<p>
			<label><?php esc_html_e( 'Priority:', 'stratawp-seo' ); ?>
				<select name="swps_sitemap_priority">
					<option value=""><?php esc_html_e( 'Auto', 'stratawp-seo' ); ?></option>
					<?php foreach ( array( '1.0', '0.9', '0.8', '0.7', '0.6', '0.5', '0.4', '0.3', '0.2', '0.1' ) as $p ) : ?>
						<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $sitemap_priority, $p ); ?>><?php echo esc_html( $p ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>
		<p>
			<label><?php esc_html_e( 'Change Frequency:', 'stratawp-seo' ); ?>
				<select name="swps_sitemap_changefreq">
					<?php
					foreach ( array(
						''        => 'Auto',
						'always'  => 'Always',
						'hourly'  => 'Hourly',
						'daily'   => 'Daily',
						'weekly'  => 'Weekly',
						'monthly' => 'Monthly',
						'yearly'  => 'Yearly',
						'never'   => 'Never',
					) as $val => $label ) :
						?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $sitemap_changefreq, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>
	</div>
</div>
