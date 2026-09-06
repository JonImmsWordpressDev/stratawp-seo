<?php
/**
 * Per-run image plan for AI generation.
 *
 * Whether to attach a featured image and how many in-content images to
 * insert for ONE generation, chosen on the Generate Content form. Defaults
 * come from Settings; the plan is stored as post meta so the background
 * image jobs can honour it. Posts without a plan (cron, bulk, topic queue)
 * keep reading the global options.
 *
 * Pure PHP apart from defaults_from_settings() so it can be unit tested
 * without a WordPress bootstrap.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes and stores the per-run image choice.
 */
class SWPS_Image_Plan {

	/** Post meta key holding the plan array. */
	public const META_KEY = '_swps_image_plan';

	/** Request key for the featured-image checkbox. */
	public const KEY_FEATURED = 'featured_image';

	/** Request key for the in-content image count. */
	public const KEY_CONTENT = 'content_images';

	/** Upper bound for in-content images (matches the Settings field). */
	public const MAX_CONTENT_IMAGES = 4;

	/**
	 * Whether the request carries either image key at all.
	 *
	 * @param array<string, mixed> $raw Raw request data.
	 * @return bool
	 */
	public static function has_request_keys( array $raw ): bool {
		return array_key_exists( self::KEY_FEATURED, $raw ) || array_key_exists( self::KEY_CONTENT, $raw );
	}

	/**
	 * Build a normalized plan from raw request input.
	 *
	 * @param array<string, mixed>                      $raw      Raw request data.
	 * @param array{featured: bool, content_count: int} $defaults Values used for missing or invalid keys.
	 * @return array{featured: bool, content_count: int}
	 */
	public static function from_request( array $raw, array $defaults ): array {
		$featured = (bool) ( $defaults['featured'] ?? false );
		$count    = (int) ( $defaults['content_count'] ?? 0 );

		if ( array_key_exists( self::KEY_FEATURED, $raw ) && is_scalar( $raw[ self::KEY_FEATURED ] ) ) {
			$featured = self::to_bool( $raw[ self::KEY_FEATURED ] );
		}

		if ( array_key_exists( self::KEY_CONTENT, $raw ) && is_scalar( $raw[ self::KEY_CONTENT ] ) ) {
			$count = (int) $raw[ self::KEY_CONTENT ];
		}

		return array(
			'featured'      => $featured,
			'content_count' => max( 0, min( self::MAX_CONTENT_IMAGES, $count ) ),
		);
	}

	/**
	 * Defaults derived from the plugin settings.
	 *
	 * Uses the same defaults as the scheduler in stratawp-seo.php so the form
	 * shows what a settings-driven run would actually do.
	 *
	 * @return array{featured: bool, content_count: int}
	 */
	public static function defaults_from_settings(): array {
		$insert = (bool) get_option( 'swps_insert_content_images', 0 );
		$count  = (int) get_option( 'swps_content_images_count', 2 );

		return array(
			'featured'      => (bool) get_option( 'swps_featured_images', 1 ),
			'content_count' => $insert ? max( 0, min( self::MAX_CONTENT_IMAGES, $count ) ) : 0,
		);
	}

	/**
	 * Read a stored plan for a post, or null when none was saved.
	 *
	 * @param int $post_id Post ID.
	 * @return array{featured: bool, content_count: int}|null
	 */
	public static function for_post( int $post_id ): ?array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $stored ) || ! array_key_exists( 'featured', $stored ) ) {
			return null;
		}

		return array(
			'featured'      => (bool) $stored['featured'],
			'content_count' => max( 0, min( self::MAX_CONTENT_IMAGES, (int) ( $stored['content_count'] ?? 0 ) ) ),
		);
	}

	/**
	 * Loose boolean parsing for checkbox values arriving as strings.
	 *
	 * @param mixed $value Scalar value.
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$text = strtolower( trim( (string) $value ) );
		return in_array( $text, array( '1', 'true', 'on', 'yes' ), true );
	}
}
