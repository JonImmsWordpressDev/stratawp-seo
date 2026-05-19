<?php
/**
 * Per-user preferences storage (theme, UI state).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_User_Prefs {

	public const META_THEME = '_swps_theme';

	public const THEME_DARK  = 'dark';
	public const THEME_LIGHT = 'light';

	/**
	 * Get the active theme for a user. Defaults to dark.
	 */
	public function get_theme( int $user_id = 0 ): string {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( 0 === $user_id ) {
			return self::THEME_DARK;
		}
		$stored = get_user_meta( $user_id, self::META_THEME, true );
		if ( in_array( $stored, array( self::THEME_DARK, self::THEME_LIGHT ), true ) ) {
			return $stored;
		}
		return self::THEME_DARK;
	}

	/**
	 * Set the theme for a user.
	 */
	public function set_theme( string $theme, int $user_id = 0 ): bool {
		if ( ! in_array( $theme, array( self::THEME_DARK, self::THEME_LIGHT ), true ) ) {
			return false;
		}
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( 0 === $user_id ) {
			return false;
		}
		update_user_meta( $user_id, self::META_THEME, $theme );
		return true;
	}
}
