<?php
/**
 * Provider factory — creates the active AI and image provider instances.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Provider_Factory {

	/**
	 * AI provider slug => class name.
	 */
	private const AI_PROVIDERS = array(
		'anthropic' => 'SWPS_Anthropic_Provider',
		'openai'    => 'SWPS_OpenAI_Provider',
		'google'    => 'SWPS_Google_Provider',
		'xai'       => 'SWPS_XAI_Provider',
	);

	/**
	 * Image provider slug => class name.
	 */
	private const IMAGE_PROVIDERS = array(
		'unsplash' => 'SWPS_Unsplash_Provider',
		'pexels'   => 'SWPS_Pexels_Provider',
		'pixabay'  => 'SWPS_Pixabay_Provider',
		'gemini'   => 'SWPS_Gemini_Image_Provider',
	);

	/**
	 * Create the active AI provider based on the stored option.
	 */
	public static function create_ai_provider(): SWPS_AI_Provider {
		$slug  = get_option( 'swps_ai_provider', 'anthropic' );
		$class = self::AI_PROVIDERS[ $slug ] ?? self::AI_PROVIDERS['anthropic'];

		return new $class();
	}

	/**
	 * Create the active image provider based on the stored option.
	 */
	public static function create_image_provider(): SWPS_Image_Provider {
		$slug  = get_option( 'swps_image_provider', 'unsplash' );
		$class = self::IMAGE_PROVIDERS[ $slug ] ?? self::IMAGE_PROVIDERS['unsplash'];

		return new $class();
	}

	/**
	 * Get AI provider options for a settings dropdown.
	 *
	 * @return array<string, string> slug => display name.
	 */
	public static function get_ai_provider_options(): array {
		$options = array();
		foreach ( self::AI_PROVIDERS as $slug => $class ) {
			$instance         = new $class();
			$options[ $slug ] = $instance->get_name();
		}
		return $options;
	}

	/**
	 * Get image provider options for a settings dropdown.
	 *
	 * @return array<string, string> slug => display name.
	 */
	public static function get_image_provider_options(): array {
		$options = array();
		foreach ( self::IMAGE_PROVIDERS as $slug => $class ) {
			$instance         = new $class();
			$options[ $slug ] = $instance->get_name();
		}
		return $options;
	}

	/**
	 * Get the curated (hardcoded) models for a provider — the canonical labels/order.
	 *
	 * @param string $slug AI provider slug.
	 * @return array<string, string> model ID => display name.
	 */
	public static function curated_models_for_provider( string $slug ): array {
		$class = self::AI_PROVIDERS[ $slug ] ?? null;

		if ( ! $class ) {
			return array();
		}

		$instance = new $class();
		return $instance->get_available_models();
	}

	/**
	 * Instantiate every AI provider, keyed by slug.
	 *
	 * @return array<string, SWPS_AI_Provider> slug => provider instance.
	 */
	public static function all_ai_providers(): array {
		$out = array();
		foreach ( self::AI_PROVIDERS as $slug => $class ) {
			$out[ $slug ] = new $class();
		}
		return $out;
	}

	/**
	 * Get available models for a specific AI provider slug (curated ∪ discovered).
	 *
	 * @param string $slug AI provider slug.
	 * @return array<string, string> model ID => display name.
	 */
	public static function get_models_for_provider( string $slug ): array {
		return ( new SWPS_Model_Discovery() )->get_text_models( $slug );
	}
}
