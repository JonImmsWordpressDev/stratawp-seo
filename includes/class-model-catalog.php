<?php
/**
 * Heuristic model catalog: derives provider, power rank, and default pricing
 * from a model ID, and computes dynamic superlative labels for a model list.
 *
 * Pure PHP — no WordPress calls — so it is unit-testable without a WP bootstrap.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Heuristic model-metadata catalog: provider, power rank, and default pricing.
 *
 * @package StrataWP_SEO
 */
class SWPS_Model_Catalog {

	/**
	 * Per-provider heuristic rules. Provider is detected by the `id` pattern;
	 * the family (power rank + default USD price per 1M input/output) is the
	 * first matching `match`, evaluated in order (most specific first, with a
	 * catch-all last so unknown sub-families still get a sane mid-tier default).
	 */
	private const RULES = array(
		'anthropic' => array(
			'id'       => '/^claude-/',
			'families' => array(
				array(
					'match' => '/opus/',
					'rank'  => 30,
					'in'    => 15.00,
					'out'   => 75.00,
				),
				array(
					'match' => '/sonnet/',
					'rank'  => 20,
					'in'    => 3.00,
					'out'   => 15.00,
				),
				array(
					'match' => '/haiku/',
					'rank'  => 10,
					'in'    => 0.80,
					'out'   => 4.00,
				),
				array(
					'match' => '/claude/',
					'rank'  => 20,
					'in'    => 3.00,
					'out'   => 15.00,
				),
			),
		),
		'openai'    => array(
			'id'       => '/^(gpt-|o\d|chatgpt-)/',
			'families' => array(
				// nano/mini are budget tiers — matched first regardless of base model.
				array(
					'match' => '/nano/',
					'rank'  => 10,
					'in'    => 0.10,
					'out'   => 0.40,
				),
				array(
					'match' => '/mini/',
					'rank'  => 15,
					'in'    => 0.40,
					'out'   => 1.60,
				),
				array(
					'match' => '/^o\d/',
					'rank'  => 30,
					'in'    => 1.10,
					'out'   => 4.40,
				),
				array(
					'match' => '/gpt-4\.1/',
					'rank'  => 26,
					'in'    => 2.00,
					'out'   => 8.00,
				),
				array(
					'match' => '/gpt-4o/',
					'rank'  => 24,
					'in'    => 2.50,
					'out'   => 10.00,
				),
				array(
					'match' => '/gpt-/',
					'rank'  => 22,
					'in'    => 2.00,
					'out'   => 8.00,
				),
			),
		),
		'google'    => array(
			'id'       => '/^(gemini|gemma)/',
			'families' => array(
				array(
					'match' => '/flash-lite/',
					'rank'  => 10,
					'in'    => 0.075,
					'out'   => 0.30,
				),
				array(
					'match' => '/flash/',
					'rank'  => 20,
					'in'    => 0.15,
					'out'   => 0.60,
				),
				array(
					'match' => '/pro/',
					'rank'  => 30,
					'in'    => 1.25,
					'out'   => 10.00,
				),
				array(
					'match' => '/gemma/',
					'rank'  => 5,
					'in'    => 0.00,
					'out'   => 0.00,
				),
				array(
					'match' => '/gemini/',
					'rank'  => 20,
					'in'    => 0.15,
					'out'   => 0.60,
				),
			),
		),
		'xai'       => array(
			'id'       => '/^grok/',
			'families' => array(
				array(
					'match' => '/mini/',
					'rank'  => 10,
					'in'    => 0.30,
					'out'   => 0.50,
				),
				array(
					'match' => '/fast/',
					'rank'  => 20,
					'in'    => 5.00,
					'out'   => 25.00,
				),
				array(
					'match' => '/grok/',
					'rank'  => 20,
					'in'    => 3.00,
					'out'   => 15.00,
				),
			),
		),
	);

	/**
	 * Default price (USD per 1M input/output) for models with no family match.
	 */
	private const DEFAULT_PRICE = array(
		'input'  => 3.00,
		'output' => 15.00,
	);

	/**
	 * Derive heuristic metadata for a model ID.
	 *
	 * @param string $id Model identifier.
	 * @return array{provider: string|null, power_score: int|null, price_in: float|null, price_out: float|null}
	 */
	public static function metadata( string $id ): array {
		$none = array(
			'provider'    => null,
			'power_score' => null,
			'price_in'    => null,
			'price_out'   => null,
		);

		foreach ( self::RULES as $provider => $rules ) {
			if ( ! preg_match( $rules['id'], $id ) ) {
				continue;
			}
			foreach ( $rules['families'] as $family ) {
				if ( preg_match( $family['match'], $id ) ) {
					$version = self::parse_version( $id );
					return array(
						'provider'    => $provider,
						'power_score' => (int) ( $family['rank'] * 1000 + (int) round( $version * 10 ) ),
						'price_in'    => (float) $family['in'],
						'price_out'   => (float) $family['out'],
					);
				}
			}
			return array_merge( $none, array( 'provider' => $provider ) );
		}

		return $none;
	}

	/**
	 * Resolve input/output price (USD per 1M tokens) for a model, falling back
	 * to a default for unknown models. Used by the cost tracker.
	 *
	 * @param string $id Model identifier.
	 * @return array{input: float, output: float}
	 */
	public static function price_for( string $id ): array {
		$m = self::metadata( $id );
		if ( null !== $m['price_in'] ) {
			return array(
				'input'  => $m['price_in'],
				'output' => $m['price_out'],
			);
		}
		return self::DEFAULT_PRICE;
	}

	/**
	 * Decorate a provider's `id => display_name` map with dynamic superlative
	 * tags computed across the set. Keys (model IDs) are never modified.
	 *
	 * @param array<string, string> $models id => display name.
	 * @return array<string, string> id => decorated label.
	 */
	public static function decorate_labels( array $models ): array {
		if ( count( $models ) < 2 ) {
			return $models;
		}

		$meta = array();
		foreach ( $models as $id => $label ) {
			$meta[ $id ] = self::metadata( (string) $id );
		}

		$tags = array();

		// Most powerful: highest power score.
		$top_power = null;
		$top_id    = null;
		foreach ( $meta as $id => $m ) {
			if ( null !== $m['power_score'] && ( null === $top_power || $m['power_score'] > $top_power ) ) {
				$top_power = $m['power_score'];
				$top_id    = $id;
			}
		}
		if ( null !== $top_id ) {
			$tags[ $top_id ][] = 'Most powerful';
		}

		// Price-based tags only when there is an actual spread.
		$priced = array();
		foreach ( $meta as $id => $m ) {
			if ( null !== $m['price_out'] ) {
				$priced[ $id ] = $m['price_out'];
			}
		}
		if ( count( $priced ) >= 2 ) {
			$max_out = max( $priced );
			$min_out = min( $priced );
			if ( $max_out > $min_out ) {
				// First key wins on a price tie — intentional, input order decides.
				$costliest = array_search( $max_out, $priced, true );
				$cheapest  = array_search( $min_out, $priced, true );
				if ( false !== $costliest ) {
					$tags[ $costliest ][] = 'Costs most';
				}
				if ( false !== $cheapest ) {
					$tags[ $cheapest ][] = 'Cheapest';
				}

				// Best value: highest power among models priced below the max.
				$bv_power = null;
				$bv_id    = null;
				foreach ( $priced as $id => $out ) {
					if ( $out >= $max_out ) {
						continue;
					}
					$p = $meta[ $id ]['power_score'];
					if ( null !== $p && ( null === $bv_power || $p > $bv_power ) ) {
						$bv_power = $p;
						$bv_id    = $id;
					}
				}
				if ( null !== $bv_id ) {
					$tags[ $bv_id ][] = 'Best value';
				}
			}
		}

		$out = array();
		foreach ( $models as $id => $label ) {
			$out[ $id ] = isset( $tags[ $id ] )
				? $label . ' — ' . implode( ' · ', $tags[ $id ] )
				: $label;
		}
		return $out;
	}

	/**
	 * Parse a numeric version from a model ID for ordering within a tier.
	 *
	 * Strips a trailing date suffix, then reads the first number, treating the
	 * first internal separator as a decimal point (e.g. `opus-4-8` => 4.8).
	 *
	 * @param string $id Model identifier.
	 * @return float
	 */
	private static function parse_version( string $id ): float {
		$id = preg_replace( '/-\d{8}$/', '', $id );
		$id = preg_replace( '/-\d{4}-\d{2}-\d{2}$/', '', $id );
		if ( preg_match( '/(\d+)(?:[.-](\d+))?/', $id, $m ) ) {
			return (float) ( $m[1] . ( isset( $m[2] ) ? '.' . $m[2] : '' ) );
		}
		return 0.0;
	}
}
