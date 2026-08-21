<?php
/**
 * Open Graph audit module.
 *
 * Checks for OG title, description, and image on public posts.
 * Auto-fix: Outputs OG meta tags via wp_head.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_OpenGraph_Module extends SWPS_Audit_Module {

	public function get_id(): string {
		return 'opengraph';
	}

	public function get_label(): string {
		return __( 'Open Graph', 'stratawp-seo' );
	}

	public function can_auto_fix(): bool {
		return true;
	}

	public function run(): array {
		$posts  = $this->get_public_posts();
		$issues = array();

		// If another SEO plugin handles OG or we handle it, skip post-level checks.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			return array(
				'score'   => 100,
				'status'  => 'pass',
				'issues'  => array(),
				'summary' => __( 'Open Graph tags managed by SEO plugin.', 'stratawp-seo' ),
			);
		}

		$our_og = (bool) get_option( 'swps_audit_auto_og', 1 );

		if ( ! $our_og ) {
			// Check posts for missing OG signals (no featured image = no OG image).
			foreach ( $posts as $post ) {
				if ( ! has_post_thumbnail( $post->ID ) ) {
					$issues[] = array(
						'post_id' => $post->ID,
						'message' => sprintf(
							__( '"%s" has no featured image for og:image.', 'stratawp-seo' ),
							$post->post_title
						),
						'fixable' => false,
					);
				}
			}
		} else {
			// OG enabled — just check for missing images.
			foreach ( $posts as $post ) {
				if ( ! has_post_thumbnail( $post->ID ) ) {
					$issues[] = array(
						'post_id' => $post->ID,
						'message' => sprintf(
							__( '"%s" will use fallback og:image (no featured image).', 'stratawp-seo' ),
							$post->post_title
						),
						'fixable' => false,
					);
				}
			}
		}

		$total   = count( $posts );
		$failing = count( $issues );
		$score   = $total > 0 ? (int) round( ( ( $total - $failing ) / $total ) * 100 ) : 100;
		// Minimum 50 if our OG output is enabled (tags exist, just image issues).
		if ( $our_og && $score < 50 ) {
			$score = 50;
		}

		return array(
			'score'   => $score,
			'status'  => $this->status_from_score( $score ),
			'issues'  => $issues,
			'summary' => 0 === $failing
				? __( 'All posts have Open Graph tags.', 'stratawp-seo' )
				: sprintf( __( '%d posts with OG image issues.', 'stratawp-seo' ), $failing ),
		);
	}

	public function auto_fix( array $issues ): array {
		update_option( 'swps_audit_auto_og', 1 );

		return array(
			'fixed'    => 1,
			'messages' => array( __( 'Auto Open Graph tag output enabled.', 'stratawp-seo' ) ),
		);
	}

	/**
	 * Output OG and Twitter meta tags on singular pages.
	 * Hooked to wp_head at priority 5.
	 */
	public static function output_meta_tags(): void {
		if ( ! is_singular() ) {
			return;
		}

		// Skip if Yoast or RankMath handles OG.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			return;
		}

		if ( ! get_option( 'swps_audit_auto_og', 1 ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post || ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$title       = get_the_title( $post );
		$description = self::get_description( $post );
		$url         = get_permalink( $post );
		$site_name   = get_bloginfo( 'name' );
		$image       = self::get_og_image( $post );
		$dimensions  = self::get_og_image_dimensions( $post, $image );

		// OG tags. Posts are articles; pages and other singulars are websites.
		printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( is_singular( 'post' ) ? 'article' : 'website' ) );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( $site_name ) );

		if ( $image ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
			if ( $dimensions ) {
				printf( '<meta property="og:image:width" content="%d" />' . "\n", $dimensions['width'] );
				printf( '<meta property="og:image:height" content="%d" />' . "\n", $dimensions['height'] );
			}
		}

		// Twitter Card tags.
		printf( '<meta name="twitter:card" content="summary_large_image" />' . "\n" );
		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $description ) );

		if ( $image ) {
			printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $image ) );
		}
	}

	/**
	 * Get OG description from post.
	 * Priority: SWPS social desc → SWPS meta desc → Yoast → RankMath →
	 * excerpt → first 160 chars.
	 */
	private static function get_description( WP_Post $post ): string {
		// The plugin's own per-post fields win — previously only rival
		// plugins' keys were read, so an SWPS description was silently
		// ignored in this fallback path.
		$desc = get_post_meta( $post->ID, '_swps_social_description', true );
		if ( ! empty( $desc ) ) {
			return $desc;
		}

		$desc = get_post_meta( $post->ID, '_swps_meta_description', true );
		if ( ! empty( $desc ) ) {
			return $desc;
		}

		$desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( ! empty( $desc ) ) {
			return $desc;
		}

		$desc = get_post_meta( $post->ID, 'rank_math_description', true );
		if ( ! empty( $desc ) ) {
			return $desc;
		}

		if ( ! empty( $post->post_excerpt ) ) {
			return wp_trim_words( $post->post_excerpt, 30, '...' );
		}

		return wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' );
	}

	/**
	 * Get OG image URL.
	 * Priority: featured image → first content image → site icon.
	 */
	private static function get_og_image( WP_Post $post ): string {
		// Featured image — resolved via SWPS_Social_Image so WebP/AVIF
		// uploads fall back to a JPEG copy LinkedIn's crawler can render.
		$thumb_id = get_post_thumbnail_id( $post->ID );
		if ( $thumb_id ) {
			$resolved = SWPS_Social_Image::get( (int) $thumb_id );
			if ( null !== $resolved ) {
				return $resolved['url'];
			}

			$img = wp_get_attachment_image_src( $thumb_id, 'large' );
			if ( $img ) {
				return $img[0];
			}
		}

		// First content image.
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)/i', $post->post_content, $matches ) ) {
			return $matches[1];
		}

		return self::get_site_icon_fallback();
	}

	/**
	 * Dimensions for the emitted og:image, when they are knowable.
	 *
	 * Only the featured-image path (resolved through SWPS_Social_Image)
	 * carries dimensions; content-scraped images and icons return null.
	 *
	 * @param WP_Post $post  Queried post.
	 * @param string  $image The URL get_og_image() returned.
	 * @return array{width: int, height: int}|null
	 */
	private static function get_og_image_dimensions( WP_Post $post, string $image ): ?array {
		if ( '' === $image ) {
			return null;
		}

		$thumb_id = get_post_thumbnail_id( $post->ID );
		if ( ! $thumb_id ) {
			return null;
		}

		$resolved = SWPS_Social_Image::get( (int) $thumb_id );
		if ( null !== $resolved && $resolved['url'] === $image && $resolved['width'] > 0 && $resolved['height'] > 0 ) {
			return array(
				'width'  => $resolved['width'],
				'height' => $resolved['height'],
			);
		}

		return null;
	}

	/**
	 * Site icon fallback for get_og_image().
	 */
	private static function get_site_icon_fallback(): string {

		// Site icon.
		$icon_id = get_option( 'site_icon' );
		if ( $icon_id ) {
			$icon_url = wp_get_attachment_url( $icon_id );
			if ( $icon_url ) {
				return $icon_url;
			}
		}

		return '';
	}
}
