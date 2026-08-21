<?php
/**
 * Fix-It fixer: add alt text to images missing it.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image alt text mechanical fixer.
 */
class SWPS_Fixer_Image_Alt extends SWPS_Crawl_Fixer {

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		return array( 'image_missing_alt' );
	}

	/**
	 * Fixer kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'mechanical';
	}

	/**
	 * Strip a WordPress -{W}x{H} rendition suffix so the URL matches the
	 * original attachment file for attachment_url_to_postid(). Pure.
	 *
	 * @param string $url Image URL as rendered on the page.
	 * @return string Original-file URL.
	 */
	public static function strip_size_suffix( string $url ): string {
		return (string) preg_replace( '#-\d+x\d+(\.[a-z]{3,4})$#i', '$1', $url );
	}

	/**
	 * Generate + store alt text for each library image on the page that
	 * lacks one. detail['images'] holds rendered absolute URLs; non-library
	 * images are skipped and reported.
	 *
	 * @param array $issue    Decoded issue row.
	 * @param array $accepted Unused.
	 * @return array|WP_Error {changed: bool, message: string}
	 */
	public function apply( array $issue, array $accepted ): array|WP_Error {
		$urls = array_map( 'strval', (array) ( $issue['detail']['images'] ?? array() ) );
		if ( array() === $urls ) {
			return new WP_Error( 'swps_fixit_no_images', __( 'The issue row lists no image URLs.', 'stratawp-seo' ) );
		}

		$fixed   = 0;
		$skipped = 0;

		foreach ( $urls as $url ) {
			$attachment_id = attachment_url_to_postid( self::strip_size_suffix( $url ) );
			if ( 0 === $attachment_id ) {
				++$skipped;
				continue;
			}
			if ( '' !== (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
				continue; // Already fixed via another page's issue row.
			}

			$alt = stratawp_seo()->image_seo->generate_alt_for( $attachment_id );
			if ( '' === $alt ) {
				++$skipped;
				continue;
			}

			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			++$fixed;
		}

		return array(
			'changed' => $fixed > 0,
			'message' => sprintf(
				/* translators: 1: images fixed, 2: images skipped */
				__( 'Alt text added to %1$d image(s); %2$d skipped (not in the media library or generation failed).', 'stratawp-seo' ),
				$fixed,
				$skipped
			),
		);
	}

	/**
	 * Alt-text writes are additive to previously-empty fields; undo clears
	 * the generated alt on this page's library images.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function undo( array $issue ): bool {
		$done = false;
		foreach ( array_map( 'strval', (array) ( $issue['detail']['images'] ?? array() ) ) as $url ) {
			$attachment_id = attachment_url_to_postid( self::strip_size_suffix( $url ) );
			if ( $attachment_id > 0 ) {
				delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
				$done = true;
			}
		}
		return $done;
	}

	/**
	 * Fixable whenever the issue lists image URLs — the write target is the
	 * attachment, not the crawled page.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function can_fix( array $issue ): bool {
		return array() !== (array) ( $issue['detail']['images'] ?? array() );
	}
}
