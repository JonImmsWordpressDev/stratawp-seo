<?php
/**
 * Cost tracking for AI generation.
 *
 * Tracks token usage and calculates costs by model with monthly aggregates.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Cost_Tracker {

	/**
	 * Pricing per 1M tokens [model => [input, output]].
	 * Prices in USD.
	 */
	private const PRICING = array(
		// Anthropic
		'claude-opus-4-7'            => array(
			'input'  => 15.00,
			'output' => 75.00,
		),
		'claude-opus-4-6'            => array(
			'input'  => 15.00,
			'output' => 75.00,
		),
		'claude-sonnet-4-6'          => array(
			'input'  => 3.00,
			'output' => 15.00,
		),
		'claude-sonnet-4-5-20250929' => array(
			'input'  => 3.00,
			'output' => 15.00,
		),
		'claude-haiku-4-5-20251001'  => array(
			'input'  => 0.80,
			'output' => 4.00,
		),
		// OpenAI
		'gpt-4.1'                    => array(
			'input'  => 2.00,
			'output' => 8.00,
		),
		'gpt-4.1-mini'               => array(
			'input'  => 0.40,
			'output' => 1.60,
		),
		'gpt-4.1-nano'               => array(
			'input'  => 0.10,
			'output' => 0.40,
		),
		'gpt-4o'                     => array(
			'input'  => 2.50,
			'output' => 10.00,
		),
		'gpt-4o-mini'                => array(
			'input'  => 0.15,
			'output' => 0.60,
		),
		'o3-mini'                    => array(
			'input'  => 1.10,
			'output' => 4.40,
		),
		// Google
		'gemini-2.5-pro'             => array(
			'input'  => 1.25,
			'output' => 10.00,
		),
		'gemini-2.5-flash'           => array(
			'input'  => 0.15,
			'output' => 0.60,
		),
		'gemini-2.0-flash'           => array(
			'input'  => 0.10,
			'output' => 0.40,
		),
		'gemini-2.0-flash-lite'      => array(
			'input'  => 0.075,
			'output' => 0.30,
		),
		// xAI
		'grok-3'                     => array(
			'input'  => 3.00,
			'output' => 15.00,
		),
		'grok-3-mini'                => array(
			'input'  => 0.30,
			'output' => 0.50,
		),
		'grok-3-fast'                => array(
			'input'  => 5.00,
			'output' => 25.00,
		),
	);

	/**
	 * Track token usage for a generation.
	 *
	 * @param string $model         Model identifier.
	 * @param int    $input_tokens  Input token count.
	 * @param int    $output_tokens Output token count.
	 * @param int    $post_id       Optional post ID to store per-post data.
	 */
	public function track( string $model, int $input_tokens, int $output_tokens, int $post_id = 0 ): void {
		if ( ! get_option( 'swps_cost_tracking', false ) ) {
			return;
		}

		$cost = $this->calculate_cost( $model, $input_tokens, $output_tokens );

		// Update monthly aggregate.
		$month_key = 'swps_cost_' . gmdate( 'Y_m' );
		$monthly   = get_option(
			$month_key,
			array(
				'total_cost'          => 0,
				'total_input_tokens'  => 0,
				'total_output_tokens' => 0,
				'generation_count'    => 0,
			)
		);

		$monthly['total_cost']          += $cost;
		$monthly['total_input_tokens']  += $input_tokens;
		$monthly['total_output_tokens'] += $output_tokens;
		$monthly['generation_count']    += 1;

		update_option( $month_key, $monthly );

		// Store per-post if applicable.
		if ( $post_id > 0 ) {
			update_post_meta( $post_id, '_swps_cost', round( $cost, 6 ) );
			update_post_meta(
				$post_id,
				'_swps_tokens',
				array(
					'input'  => $input_tokens,
					'output' => $output_tokens,
					'model'  => $model,
				)
			);
		}
	}

	/**
	 * Calculate cost for given token usage.
	 *
	 * @param string $model         Model identifier.
	 * @param int    $input_tokens  Input token count.
	 * @param int    $output_tokens Output token count.
	 * @return float Cost in USD.
	 */
	public function calculate_cost( string $model, int $input_tokens, int $output_tokens ): float {
		$pricing = self::PRICING[ $model ] ?? array(
			'input'  => 3.00,
			'output' => 15.00,
		);

		$input_cost  = ( $input_tokens / 1_000_000 ) * $pricing['input'];
		$output_cost = ( $output_tokens / 1_000_000 ) * $pricing['output'];

		return $input_cost + $output_cost;
	}

	/**
	 * Get monthly stats.
	 *
	 * @param string $year_month Format: Y_m (e.g., 2026_02). Defaults to current month.
	 * @return array Monthly stats.
	 */
	public function get_monthly_stats( string $year_month = '' ): array {
		if ( empty( $year_month ) ) {
			$year_month = gmdate( 'Y_m' );
		}

		return get_option(
			'swps_cost_' . $year_month,
			array(
				'total_cost'          => 0,
				'total_input_tokens'  => 0,
				'total_output_tokens' => 0,
				'generation_count'    => 0,
			)
		);
	}

	/**
	 * Get total cost across all months.
	 *
	 * @return float Total cost in USD.
	 */
	public function get_total_cost(): float {
		global $wpdb;

		$results = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'swps_cost_20%'"
		);

		$total = 0;
		foreach ( $results as $option_name ) {
			$data   = get_option( $option_name, array() );
			$total += $data['total_cost'] ?? 0;
		}

		return $total;
	}
}
