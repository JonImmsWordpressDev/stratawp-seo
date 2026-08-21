<?php
/**
 * Registry mapping crawl check ids to their Fix-It fixer.
 *
 * Mirrors SWPS_Crawl_Check_Registry: a hardcoded class list, instantiated
 * lazily, shared per request.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check id → fixer lookup.
 */
class SWPS_Crawl_Fixer_Registry {

	/** Fixer classes. Order is irrelevant; check_ids() declares coverage. */
	private const FIXERS = array(
		SWPS_Fixer_Meta_Title::class,
		SWPS_Fixer_Meta_Description::class,
		SWPS_Fixer_Image_Alt::class,
		SWPS_Fixer_Mixed_Content::class,
		SWPS_Fixer_Nofollow::class,
		SWPS_Fixer_Sitemap_Exclude::class,
	);

	/**
	 * Lazily-built check_id → instance map.
	 *
	 * @var array|null
	 */
	private static ?array $map = null;

	/**
	 * The fixer handling a check id, or null when the check has no fixer.
	 *
	 * @param string $check_id Check id.
	 * @return SWPS_Crawl_Fixer|null
	 */
	public static function for_check( string $check_id ): ?SWPS_Crawl_Fixer {
		$map = self::map();
		return $map[ $check_id ] ?? null;
	}

	/**
	 * Every fixable check id.
	 *
	 * @return string[]
	 */
	public static function fixable_ids(): array {
		return array_keys( self::map() );
	}

	/**
	 * 'draft' | 'mechanical' | null for a check id.
	 *
	 * @param string $check_id Check id.
	 * @return string|null
	 */
	public static function kind_of( string $check_id ): ?string {
		$fixer = self::for_check( $check_id );
		return $fixer ? $fixer->kind() : null;
	}

	/**
	 * Build (once) the check_id → fixer instance map.
	 *
	 * @return array
	 */
	private static function map(): array {
		if ( null !== self::$map ) {
			return self::$map;
		}

		self::$map = array();
		foreach ( self::FIXERS as $class ) {
			$fixer = new $class();
			foreach ( $fixer->check_ids() as $id ) {
				self::$map[ $id ] = $fixer;
			}
		}

		return self::$map;
	}
}
