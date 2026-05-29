<?php
/**
 * Discovers available models from provider APIs and merges them with the
 * curated lists. Refreshed daily; never auto-switches the selected model.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merges curated and API-discovered models, refreshes the discovered cache,
 * and tracks newly-seen model IDs for a dismissible admin notice.
 */
class SWPS_Model_Discovery {

	/**
	 * Option: per-slug discovered models. Shape: [ slug => [ id => label ] ].
	 */
	private const OPTION_DISCOVERED = 'swps_discovered_models';

	/**
	 * Option: flat list of model IDs we've already seen. Shape: [ id, ... ].
	 */
	private const OPTION_KNOWN = 'swps_known_model_ids';

	/**
	 * Option: newly-seen models awaiting an admin notice. Shape: [ id => label ].
	 */
	private const OPTION_NEW = 'swps_new_models_available';

	/**
	 * Get the curated ∪ discovered text models for a provider.
	 *
	 * @param string $provider_slug AI provider slug.
	 * @return array<string, string> model ID => display name.
	 */
	public function get_text_models( string $provider_slug ): array {
		$curated    = SWPS_Provider_Factory::curated_models_for_provider( $provider_slug );
		$discovered = ( get_option( self::OPTION_DISCOVERED, array() )[ $provider_slug ] ?? array() );
		return self::merge_models( $curated, (array) $discovered );
	}

	/**
	 * Get the curated ∪ discovered Gemini image models.
	 *
	 * @return array<string, string> model ID => display name.
	 */
	public function get_image_models(): array {
		$curated    = SWPS_Gemini_Image_Provider::curated_models();
		$discovered = ( get_option( self::OPTION_DISCOVERED, array() )['gemini-image'] ?? array() );
		return self::merge_models( $curated, (array) $discovered );
	}

	/**
	 * Daily cron: refresh discovered models for every configured provider.
	 *
	 * Keeps the last-good list when a provider returns nothing, seeds the
	 * known-IDs list silently on first run, and flags genuinely new IDs for
	 * a dismissible admin notice. Never changes the selected model.
	 *
	 * @return void
	 */
	public function refresh(): void {
		$store = (array) get_option( self::OPTION_DISCOVERED, array() );
		$seen  = array();

		foreach ( SWPS_Provider_Factory::all_ai_providers() as $slug => $provider ) {
			$found = $provider->fetch_remote_models();
			if ( ! empty( $found ) ) {
				$store[ $slug ] = $found; // Keep last-good if empty.
			}
			$seen = array_merge( $seen, array_keys( $store[ $slug ] ?? array() ) );
		}

		$image_provider = new SWPS_Gemini_Image_Provider();
		$found_image    = $image_provider->fetch_remote_models();
		if ( ! empty( $found_image ) ) {
			$store['gemini-image'] = $found_image;
		}
		$seen = array_merge( $seen, array_keys( $store['gemini-image'] ?? array() ) );

		update_option( self::OPTION_DISCOVERED, $store );

		$known = (array) get_option( self::OPTION_KNOWN, array() );
		if ( empty( $known ) ) {
			update_option( self::OPTION_KNOWN, array_values( array_unique( $seen ) ) ); // Seed silently.
			return;
		}

		$new = self::diff_new_ids( $known, array_values( array_unique( $seen ) ) );
		if ( ! empty( $new ) ) {
			$labels = self::labels_for( $store, $new );
			update_option( self::OPTION_NEW, array_replace( (array) get_option( self::OPTION_NEW, array() ), $labels ) );
			update_option( self::OPTION_KNOWN, array_values( array_unique( array_merge( $known, $new ) ) ) );
		}
	}

	/**
	 * Clear the pending new-model alert.
	 *
	 * @return void
	 */
	public function dismiss_alert(): void {
		delete_option( self::OPTION_NEW );
	}

	/**
	 * Get the pending new-model alert payload.
	 *
	 * @return array<string, string> model ID => display name.
	 */
	public function new_models(): array {
		return (array) get_option( self::OPTION_NEW, array() );
	}

	/**
	 * Merge curated ∪ discovered models; curated label + order wins, new
	 * discovered entries are appended.
	 *
	 * @param array<string, string> $curated    Curated model ID => label.
	 * @param array<string, string> $discovered Discovered model ID => label.
	 * @return array<string, string> Merged model ID => label.
	 */
	public static function merge_models( array $curated, array $discovered ): array {
		$merged = $curated;
		foreach ( $discovered as $id => $label ) {
			if ( ! array_key_exists( $id, $merged ) ) {
				$merged[ $id ] = $label;
			}
		}
		return $merged;
	}

	/**
	 * Compute the IDs in $current that are not present in $known.
	 *
	 * @param array<int, string> $known   Known model IDs.
	 * @param array<int, string> $current Currently-discovered model IDs.
	 * @return array<int, string> IDs in $current absent from $known.
	 */
	public static function diff_new_ids( array $known, array $current ): array {
		return array_values( array_diff( $current, $known ) );
	}

	/**
	 * Look up labels for IDs across the per-slug discovered store.
	 *
	 * @param array<string, array<string, string>> $store Per-slug discovered models.
	 * @param array<int, string>                   $ids   Model IDs to resolve.
	 * @return array<string, string> model ID => label (falls back to the ID).
	 */
	private static function labels_for( array $store, array $ids ): array {
		$flat = array();
		foreach ( $store as $models ) {
			$flat += (array) $models;
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = $flat[ $id ] ?? $id;
		}
		return $out;
	}
}
