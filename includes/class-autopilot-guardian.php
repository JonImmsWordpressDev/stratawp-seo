<?php
/**
 * Autopilot safety layer: budget caps, transient-error retry, topic requeue,
 * and run-summary recording for unattended generation.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Autopilot_Guardian {

	public const OPTION_BUDGET   = 'swps_monthly_budget';
	public const OPTION_LAST_RUN = 'swps_autopilot_last_run';

	public const MAX_ATTEMPTS = 3;

	/**
	 * Fraction of budget at which the warning notice fires.
	 */
	private const WARNING_RATIO = 0.8;

	/**
	 * Error codes that retrying cannot fix. Everything else is treated as
	 * transient — the attempt cap bounds the damage of a misclassification.
	 */
	private const PERMANENT_CODES = array(
		'swps_no_api_key',
		'swps_invalid_key',
		'swps_duplicate',
		'swps_budget_exceeded',
	);

	/**
	 * Classify spend against the budget.
	 *
	 * @param float $spent  USD spent this month.
	 * @param float $budget Monthly budget in USD; 0 disables budgeting.
	 * @return string 'ok' | 'warning' | 'exceeded'.
	 */
	public static function budget_state( float $spent, float $budget ): string {
		if ( $budget <= 0 ) {
			return 'ok';
		}
		if ( $spent >= $budget ) {
			return 'exceeded';
		}
		if ( $spent >= $budget * self::WARNING_RATIO ) {
			return 'warning';
		}
		return 'ok';
	}

	/**
	 * Whether a generation error is worth retrying.
	 *
	 * Providers attach the HTTP status as error data ('status'); a 4xx other
	 * than 429 means the request itself is bad, so retrying is pointless.
	 */
	public static function is_transient_error( WP_Error $error ): bool {
		$code = $error->get_error_code();

		if ( in_array( $code, self::PERMANENT_CODES, true ) ) {
			return false;
		}

		if ( 'swps_api_error' === $code ) {
			$data   = $error->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
			if ( $status >= 400 && $status < 500 && 429 !== $status ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Exponential backoff: 15 min, 30 min, 60 min…
	 *
	 * @param int $attempt 1-based attempt number about to be scheduled.
	 * @return int Delay in seconds before the next attempt.
	 */
	public static function retry_delay( int $attempt ): int {
		$attempt = max( 1, $attempt );
		return 900 * ( 2 ** ( $attempt - 1 ) );
	}

	/**
	 * Gate a generation on the monthly budget.
	 *
	 * @return true|WP_Error True to proceed; WP_Error 'swps_budget_exceeded' to block.
	 */
	public static function check_budget(): bool|WP_Error {
		$budget = (float) get_option( self::OPTION_BUDGET, 0 );
		if ( $budget <= 0 ) {
			return true;
		}

		$tracker = new SWPS_Cost_Tracker();
		$spent   = (float) ( $tracker->get_monthly_stats()['total_cost'] ?? 0 );

		if ( 'exceeded' === self::budget_state( $spent, $budget ) ) {
			return new WP_Error(
				'swps_budget_exceeded',
				sprintf(
					/* translators: 1: amount spent, 2: budget */
					__( 'Monthly AI budget reached ($%1$s of $%2$s). Generation is paused until next month or until you raise the budget in Settings.', 'stratawp-seo' ),
					number_format_i18n( $spent, 2 ),
					number_format_i18n( $budget, 2 )
				)
			);
		}

		return true;
	}
}
