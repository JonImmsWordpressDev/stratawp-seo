<?php
/**
 * Social image resolution for Open Graph / Twitter Cards.
 *
 * LinkedIn's crawler does not render WebP (or AVIF) og:images, so posts
 * whose featured image was uploaded as WebP shipped share cards with no
 * preview. This helper picks the best available rendition of an
 * attachment for social use — preferring JPEG/PNG/GIF — and, when only
 * WebP/AVIF renditions exist, converts one to a cached JPEG copy.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves attachment renditions to a social-safe image with dimensions.
 */
class SWPS_Social_Image {

	/**
	 * Width sweet spot for social cards (LinkedIn/Facebook recommend 1200px).
	 */
	private const TARGET_WIDTH = 1200;

	/**
	 * Meta key caching the converted-JPEG rendition on the attachment.
	 */
	private const CONVERTED_META_KEY = '_swps_social_jpeg';

	/**
	 * Whether a mime type renders in every major share crawler.
	 *
	 * WebP and AVIF are excluded: LinkedIn's scraper skips them, leaving
	 * the share card without an image.
	 *
	 * @param string $mime Mime type, e.g. "image/webp".
	 * @return bool
	 */
	public static function is_social_safe( string $mime ): bool {
		return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true );
	}

	/**
	 * Best-effort mime type from a file path or URL extension.
	 *
	 * @param string $path File path or URL.
	 * @return string Mime type, or '' when unrecognized.
	 */
	public static function mime_from_path( string $path ): string {
		$clean = strtolower( (string) preg_replace( '/[?#].*$/', '', $path ) );
		$ext   = pathinfo( $clean, PATHINFO_EXTENSION );

		$map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
		);

		return $map[ $ext ] ?? '';
	}

	/**
	 * Choose the best social rendition from a candidate list.
	 *
	 * Candidates are arrays with url, width, height, mime keys. Social-safe
	 * mimes always beat WebP/AVIF regardless of size; within the winning
	 * pool the width closest to the 1200px target wins, larger on ties.
	 *
	 * @param array<int, array{url: string, width: int, height: int, mime: string}> $candidates Renditions.
	 * @return array{url: string, width: int, height: int, mime: string}|null
	 */
	public static function choose_rendition( array $candidates ): ?array {
		$valid = array_values(
			array_filter(
				$candidates,
				static function ( $c ) {
					return is_array( $c ) && ! empty( $c['url'] ) && ! empty( $c['width'] );
				}
			)
		);

		if ( ! $valid ) {
			return null;
		}

		$safe = array_values(
			array_filter(
				$valid,
				static function ( $c ) {
					return self::is_social_safe( (string) $c['mime'] );
				}
			)
		);

		$pool = $safe ? $safe : $valid;

		usort(
			$pool,
			static function ( $a, $b ) {
				$dist_a = abs( (int) $a['width'] - self::TARGET_WIDTH );
				$dist_b = abs( (int) $b['width'] - self::TARGET_WIDTH );
				if ( $dist_a === $dist_b ) {
					return (int) $b['width'] <=> (int) $a['width'];
				}
				return $dist_a <=> $dist_b;
			}
		);

		return $pool[0];
	}

	/**
	 * Resolve an attachment to a social-safe image with dimensions.
	 *
	 * Picks the best rendition; when only WebP/AVIF exists, converts it to
	 * a JPEG copy cached beside the original (and in attachment meta) so
	 * the conversion runs once per attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{url: string, width: int, height: int, mime: string}|null
	 */
	public static function get( int $attachment_id ): ?array {
		$chosen = self::choose_rendition( self::build_candidates( $attachment_id ) );
		if ( null === $chosen ) {
			return null;
		}

		if ( self::is_social_safe( $chosen['mime'] ) ) {
			return $chosen;
		}

		return self::ensure_jpeg( $attachment_id, $chosen ) ?? $chosen;
	}

	/**
	 * Build rendition candidates (full size + sub-sizes) for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<int, array{url: string, width: int, height: int, mime: string}>
	 */
	private static function build_candidates( int $attachment_id ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$url  = wp_get_attachment_url( $attachment_id );

		if ( ! is_array( $meta ) || ! $url ) {
			return array();
		}

		$candidates = array(
			array(
				'url'    => $url,
				'width'  => (int) $meta['width'],
				'height' => (int) $meta['height'],
				'mime'   => self::mime_from_path( $url ),
			),
		);

		$base = trailingslashit( dirname( $url ) );
		foreach ( (array) $meta['sizes'] as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			$candidates[] = array(
				'url'    => $base . $size['file'],
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'mime'   => (string) ( $size['mime-type'] ?? self::mime_from_path( (string) $size['file'] ) ),
			);
		}

		return $candidates;
	}

	/**
	 * Return (creating once) a JPEG copy of a WebP/AVIF rendition.
	 *
	 * @param int                                                       $attachment_id Attachment post ID.
	 * @param array{url: string, width: int, height: int, mime: string} $rendition     Chosen unsafe rendition.
	 * @return array{url: string, width: int, height: int, mime: string}|null Null when conversion is unavailable.
	 */
	private static function ensure_jpeg( int $attachment_id, array $rendition ): ?array {
		$uploads = wp_get_upload_dir();

		// Cached conversion from a previous request.
		$cached = get_post_meta( $attachment_id, self::CONVERTED_META_KEY, true );
		if ( is_array( $cached ) && ! empty( $cached['file'] ) ) {
			$path = trailingslashit( $uploads['basedir'] ) . $cached['file'];
			if ( file_exists( $path ) ) {
				return array(
					'url'    => trailingslashit( $uploads['baseurl'] ) . $cached['file'],
					'width'  => (int) ( $cached['width'] ?? 0 ),
					'height' => (int) ( $cached['height'] ?? 0 ),
					'mime'   => 'image/jpeg',
				);
			}
			delete_post_meta( $attachment_id, self::CONVERTED_META_KEY );
		}

		// Map the rendition URL back to a local file path.
		$path = str_replace( trailingslashit( $uploads['baseurl'] ), trailingslashit( $uploads['basedir'] ), $rendition['url'] );
		if ( $path === $rendition['url'] || ! file_exists( $path ) ) {
			return null;
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return null;
		}

		$dest  = preg_replace( '/\.[a-z0-9]+$/i', '', $path ) . '-social.jpg';
		$saved = $editor->save( $dest, 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return null;
		}

		$relative = ltrim( str_replace( trailingslashit( $uploads['basedir'] ), '', $saved['path'] ), '/' );
		update_post_meta(
			$attachment_id,
			self::CONVERTED_META_KEY,
			array(
				'file'   => $relative,
				'width'  => (int) $saved['width'],
				'height' => (int) $saved['height'],
			)
		);

		return array(
			'url'    => trailingslashit( $uploads['baseurl'] ) . $relative,
			'width'  => (int) $saved['width'],
			'height' => (int) $saved['height'],
			'mime'   => 'image/jpeg',
		);
	}
}
