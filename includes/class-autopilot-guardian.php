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

/**
 * Safety layer for unattended (autopilot) content generation.
 */
class SWPS_Autopilot_Guardian {

	public const OPTION_BUDGET   = 'swps_monthly_budget';
	public const OPTION_LAST_RUN = 'swps_autopilot_last_run';

	public const MAX_ATTEMPTS = 3;

	/**
	 * Hook the settings field and admin notices.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_dismiss_budget_notice' ), 5 );
		// Priority 20: SWPS_Settings registers the schedule section at default 10.
		add_action( 'admin_init', array( $this, 'register_settings' ), 20 );
		add_action( 'admin_notices', array( $this, 'maybe_warn_budget' ) );
	}

	/**
	 * Handle the budget-notice dismiss link before output, then strip the
	 * query args so the nonce doesn't linger in the URL.
	 */
	public function maybe_dismiss_budget_notice(): void {
		if ( ! isset( $_GET['swps_dismiss_budget_notice'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'swps_dismiss_budget_notice' );

		update_option( 'swps_budget_notice_dismissed_' . gmdate( 'Y_m' ), 1, false );

		wp_safe_redirect( remove_query_arg( array( 'swps_dismiss_budget_notice', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Register the budget field inside the existing Auto-Publishing Schedule section.
	 */
	public function register_settings(): void {
		register_setting(
			'stratawp-seo',
			self::OPTION_BUDGET,
			array(
				'type'              => 'number',
				'sanitize_callback' => array( $this, 'sanitize_budget' ),
				'default'           => 0,
			)
		);

		add_settings_field(
			self::OPTION_BUDGET,
			__( 'Monthly AI budget (USD)', 'stratawp-seo' ),
			array( $this, 'render_budget_field' ),
			'stratawp-seo',
			'swps_schedule_section'
		);
	}

	/**
	 * Sanitize the submitted budget to a non-negative two-decimal float.
	 *
	 * @param mixed $value Raw submitted value.
	 */
	public function sanitize_budget( $value ): float {
		return max( 0, round( (float) $value, 2 ) );
	}

	/**
	 * Render the budget input on the settings page.
	 */
	public function render_budget_field(): void {
		$budget = (float) get_option( self::OPTION_BUDGET, 0 );
		printf(
			'<input type="number" name="%1$s" value="%2$s" min="0" step="0.01" class="small-text" /> ' .
			'<p class="description">%3$s</p>',
			esc_attr( self::OPTION_BUDGET ),
			esc_attr( $budget > 0 ? (string) $budget : '' ),
			esc_html__( 'Hard stop for AI generation this calendar month. 0 or empty disables the cap. Setting a budget automatically enables cost tracking. Costs are estimates from the model price catalog — the provider bill may differ slightly.', 'stratawp-seo' )
		);
	}

	/**
	 * One dismissible warning per month once spend crosses 80% of budget.
	 */
	public function maybe_warn_budget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$budget = (float) get_option( self::OPTION_BUDGET, 0 );
		if ( $budget <= 0 ) {
			return;
		}

		$tracker = new SWPS_Cost_Tracker();
		$spent   = (float) ( $tracker->get_monthly_stats()['total_cost'] ?? 0 );
		$state   = self::budget_state( $spent, $budget );

		if ( 'ok' === $state ) {
			return;
		}

		$dismiss_key = 'swps_budget_notice_dismissed_' . gmdate( 'Y_m' );

		if ( 'warning' === $state && get_option( $dismiss_key ) ) {
			return;
		}

		if ( 'exceeded' === $state ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'StrataWP SEO: monthly AI budget reached.', 'stratawp-seo' ),
				esc_html(
					sprintf(
						/* translators: 1: spent, 2: budget */
						__( '$%1$s of $%2$s spent — AI generation is paused until next month or a higher budget.', 'stratawp-seo' ),
						number_format_i18n( $spent, 2 ),
						number_format_i18n( $budget, 2 )
					)
				)
			);
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'swps_dismiss_budget_notice', 1 ),
			'swps_dismiss_budget_notice'
		);
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'StrataWP SEO: approaching monthly AI budget.', 'stratawp-seo' ),
			esc_html(
				sprintf(
					/* translators: 1: spent, 2: budget */
					__( '$%1$s of $%2$s spent this month.', 'stratawp-seo' ),
					number_format_i18n( $spent, 2 ),
					number_format_i18n( $budget, 2 )
				)
			),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss for this month', 'stratawp-seo' )
		);
	}

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
	 *
	 * @param WP_Error $error The generation error to classify.
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
	 * Handle a failed topic: requeue transient failures with backoff (capped
	 * at MAX_ATTEMPTS total attempts), mark permanent ones failed.
	 *
	 * @param int              $topic_id Topic post ID.
	 * @param WP_Error         $error    The generation error.
	 * @param SWPS_Topic_Queue $queue    Queue for status updates.
	 * @return string 'retrying' or 'failed'.
	 */
	public static function handle_topic_failure( int $topic_id, WP_Error $error, SWPS_Topic_Queue $queue ): string {
		$attempts = (int) get_post_meta( $topic_id, '_swps_attempt_count', true ) + 1;
		update_post_meta( $topic_id, '_swps_attempt_count', $attempts );

		if ( self::is_transient_error( $error ) && $attempts < self::MAX_ATTEMPTS ) {
			$queue->update_status(
				$topic_id,
				'retrying',
				sprintf(
					/* translators: 1: attempt, 2: max attempts, 3: error message */
					__( 'Attempt %1$d of %2$d failed: %3$s — retry scheduled.', 'stratawp-seo' ),
					$attempts,
					self::MAX_ATTEMPTS,
					$error->get_error_message()
				)
			);
			stratawp_seo()->background_processor->schedule_generation( $topic_id, self::retry_delay( $attempts ) );
			return 'retrying';
		}

		$queue->update_status( $topic_id, 'failed', $error->get_error_message() );
		return 'failed';
	}

	/**
	 * Record a per-run summary for the dashboard status strip.
	 *
	 * @param int $succeeded Posts generated successfully this run.
	 * @param int $failed    Generation failures this run.
	 */
	public static function record_run( int $succeeded, int $failed ): void {
		update_option(
			self::OPTION_LAST_RUN,
			array(
				'timestamp' => time(),
				'succeeded' => $succeeded,
				'failed'    => $failed,
			),
			false
		);
	}

	/**
	 * Status payload for the dashboard tile.
	 */
	public static function get_status(): array {
		$tracker = new SWPS_Cost_Tracker();
		$spent   = (float) ( $tracker->get_monthly_stats()['total_cost'] ?? 0 );
		$budget  = (float) get_option( self::OPTION_BUDGET, 0 );

		return array(
			'last_run'     => get_option( self::OPTION_LAST_RUN, array() ),
			'spent'        => $spent,
			'budget'       => $budget,
			'budget_state' => self::budget_state( $spent, $budget ),
		);
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
