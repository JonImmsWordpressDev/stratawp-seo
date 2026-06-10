<?php
/**
 * Digest send pipeline — rendering glue and optional AI executive summary.
 *
 * Split out of SWPS_Digest as a trait to keep class-digest.php under the
 * project's 500-line file limit.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send pipeline for SWPS_Digest: send() plus the AI-summary helper.
 */
trait SWPS_Digest_Send {

	/**
	 * Build and send the digest email.
	 *
	 * @param bool        $test               True for a manual test send (never bails on empty data,
	 *                                        never updates last-sent).
	 * @param string|null $override_recipient Send to this address only (used by test sends).
	 * @return bool Whether wp_mail reported success.
	 */
	public function send( bool $test = false, ?string $override_recipient = null ): bool {
		$frequency = get_option( self::OPTION_FREQUENCY, 'weekly' );
		$span      = 'daily' === $frequency ? DAY_IN_SECONDS : ( 'monthly' === $frequency ? 30 * DAY_IN_SECONDS : WEEK_IN_SECONDS );
		$last_sent = (int) get_option( self::OPTION_LAST_SENT, 0 );

		// Double-fire guard: skip cron runs landing inside half the frequency window.
		if ( ! $test && $last_sent > ( time() - (int) ( $span / 2 ) ) ) {
			return false;
		}

		$since = ( $test || $last_sent <= 0 ) ? time() - ( $test ? WEEK_IN_SECONDS : $span ) : $last_sent;
		$data  = $this->collect_data( $since );

		// Bail when there is literally nothing to report — except on test sends.
		$sections = array_diff_key( $data, array( 'attention' => true ) );
		if ( ! $test && empty( $sections ) && empty( $data['attention'] ) ) {
			return false;
		}

		if ( get_option( self::OPTION_AI_SUMMARY ) ) {
			$summary = $this->get_ai_summary( $data );
			if ( null !== $summary ) {
				$data['ai_summary'] = $summary;
			}
		}

		$accent = get_option( self::OPTION_ACCENT, '' );
		$accent = $accent ? $accent : '#2271b1';
		$logo   = (string) get_option( self::OPTION_LOGO, '' );
		$footer = (string) get_option( self::OPTION_FOOTER, '' );

		ob_start();
		include SWPS_PLUGIN_DIR . 'templates/email/digest.php';
		$html = (string) ob_get_clean();

		if ( $test && $override_recipient ) {
			$recipients = array( $override_recipient );
		} else {
			$recipients = self::parse_recipients( (string) get_option( self::OPTION_RECIPIENTS, '' ) );
		}
		if ( empty( $recipients ) ) {
			$recipients = array( get_option( 'admin_email' ) );
		}

		$subject = sprintf( '[%s] SEO digest — %s', get_bloginfo( 'name' ), wp_date( get_option( 'date_format' ) ) );

		try {
			$sent = wp_mail( $recipients, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		} catch ( Throwable $e ) {
			$sent = false;
		}

		if ( $sent && ! $test ) {
			update_option( self::OPTION_LAST_SENT, time(), false );
		}

		return (bool) $sent;
	}

	/**
	 * Optional AI executive summary (2 sentences) from compact section stats.
	 *
	 * Fail-soft: any provider error returns null and the section is skipped.
	 * Note: SWPS_AI_Provider keeps last_usage protected with no public accessor,
	 * so per-send cost tracking is skipped here.
	 *
	 * @param array<string, mixed> $data Assembled digest data.
	 * @return string|null Plain-text summary or null on any failure.
	 */
	private function get_ai_summary( array $data ): ?string {
		try {
			$stats = array(
				'posts_generated'     => isset( $data['generated'] ) ? count( $data['generated'] ) : 0,
				'generation_failures' => isset( $data['failures'] ) ? count( $data['failures'] ) : 0,
				'keyword_winners'     => isset( $data['keywords']['winners'] ) ? count( $data['keywords']['winners'] ) : 0,
				'keyword_losers'      => isset( $data['keywords']['losers'] ) ? count( $data['keywords']['losers'] ) : 0,
				'backlinks'           => $data['backlinks'] ?? null,
				'monthly_spend'       => $data['spend']['total_cost'] ?? null,
				'attention_items'     => $data['attention'],
			);

			$response = SWPS_Provider_Factory::create_ai_provider()->chat(
				'You are an SEO consultant writing a friendly executive summary for a client email digest. Reply with exactly two short sentences of plain text, no markdown.',
				'Summarize this week for the client: ' . wp_json_encode( $stats ),
				200
			);

			if ( is_wp_error( $response ) || ! is_string( $response ) || '' === trim( $response ) ) {
				return null;
			}
			return trim( $response );
		} catch ( Throwable $e ) {
			return null;
		}
	}
}
