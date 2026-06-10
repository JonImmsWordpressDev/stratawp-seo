<?php
/**
 * Digest settings — section/fields registration, cron scheduling, test-send.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Email Digest settings section, handles (re)scheduling the
 * digest cron when options change, and processes the test-send action.
 */
class SWPS_Digest_Settings {

	/**
	 * The digest instance used for test sends.
	 *
	 * @var SWPS_Digest
	 */
	private SWPS_Digest $digest;

	/**
	 * Wire up settings, scheduling, and test-send hooks.
	 *
	 * @param SWPS_Digest $digest Digest instance (DI for test sends).
	 */
	public function __construct( SWPS_Digest $digest ) {
		$this->digest = $digest;

		add_action( 'admin_init', array( $this, 'register_settings' ), 20 );

		// Reschedule when enable/frequency change (both add_ and update_ in case
		// activation defaults were never seeded).
		foreach ( array( SWPS_Digest::OPTION_ENABLED, SWPS_Digest::OPTION_FREQUENCY ) as $option ) {
			add_action( "update_option_{$option}", array( $this, 'reschedule' ), 10, 0 );
			add_action( "add_option_{$option}", array( $this, 'reschedule' ), 10, 0 );
		}

		add_action( 'admin_post_swps_digest_test', array( $this, 'handle_test_send' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_test_notice' ) );
	}

	/**
	 * Register the Email Digest section and its fields on the main settings page.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		add_settings_section(
			'swps_digest_section',
			__( 'Email Digest', 'stratawp-seo' ),
			array( $this, 'render_section_description' ),
			'stratawp-seo'
		);

		$fields = array(
			SWPS_Digest::OPTION_ENABLED    => array(
				'title'    => __( 'Enable Digest', 'stratawp-seo' ),
				'render'   => 'render_enabled_field',
				'sanitize' => 'absint',
			),
			SWPS_Digest::OPTION_FREQUENCY  => array(
				'title'    => __( 'Frequency', 'stratawp-seo' ),
				'render'   => 'render_frequency_field',
				'sanitize' => array( $this, 'sanitize_frequency' ),
			),
			SWPS_Digest::OPTION_RECIPIENTS => array(
				'title'    => __( 'Recipients', 'stratawp-seo' ),
				'render'   => 'render_recipients_field',
				'sanitize' => 'sanitize_textarea_field',
			),
			SWPS_Digest::OPTION_LOGO       => array(
				'title'    => __( 'Logo URL', 'stratawp-seo' ),
				'render'   => 'render_logo_field',
				'sanitize' => 'esc_url_raw',
			),
			SWPS_Digest::OPTION_ACCENT     => array(
				'title'    => __( 'Accent Color', 'stratawp-seo' ),
				'render'   => 'render_accent_field',
				'sanitize' => 'sanitize_hex_color',
			),
			SWPS_Digest::OPTION_FOOTER     => array(
				'title'    => __( 'Footer Text', 'stratawp-seo' ),
				'render'   => 'render_footer_field',
				'sanitize' => 'sanitize_text_field',
			),
			SWPS_Digest::OPTION_AI_SUMMARY => array(
				'title'    => __( 'AI Executive Summary', 'stratawp-seo' ),
				'render'   => 'render_ai_summary_field',
				'sanitize' => 'absint',
			),
		);

		foreach ( $fields as $option => $field ) {
			register_setting( 'stratawp-seo', $option, array( 'sanitize_callback' => $field['sanitize'] ) );
			add_settings_field(
				$option,
				$field['title'],
				array( $this, $field['render'] ),
				'stratawp-seo',
				'swps_digest_section'
			);
		}
	}

	/**
	 * Section description with the test-send button (nonce-protected GET link;
	 * a nested form inside the settings form would be invalid HTML).
	 *
	 * @return void
	 */
	public function render_section_description(): void {
		echo '<p>' . esc_html__( 'Scheduled HTML email summarizing generations, failures, keyword movers, backlinks, competitors, AI-bot trends, and AI spend. Delivery depends on your site\'s mailer — use the test send to verify.', 'stratawp-seo' ) . '</p>';
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=swps_digest_test' ), 'swps_digest_test' );
		echo '<p><a href="' . esc_url( $url ) . '" class="button button-secondary">' . esc_html__( 'Send test digest to my email', 'stratawp-seo' ) . '</a></p>';
	}

	/**
	 * Render the enabled checkbox.
	 *
	 * @return void
	 */
	public function render_enabled_field(): void {
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( SWPS_Digest::OPTION_ENABLED ),
			checked( 1, (int) get_option( SWPS_Digest::OPTION_ENABLED, 0 ), false ),
			esc_html__( 'Send the digest email on a schedule', 'stratawp-seo' )
		);
	}

	/**
	 * Render the frequency select.
	 *
	 * @return void
	 */
	public function render_frequency_field(): void {
		$current = get_option( SWPS_Digest::OPTION_FREQUENCY, 'weekly' );
		$options = array(
			'daily'   => __( 'Daily', 'stratawp-seo' ),
			'weekly'  => __( 'Weekly', 'stratawp-seo' ),
			'monthly' => __( 'Monthly', 'stratawp-seo' ),
		);
		echo '<select name="' . esc_attr( SWPS_Digest::OPTION_FREQUENCY ) . '">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Render the recipients textarea.
	 *
	 * @return void
	 */
	public function render_recipients_field(): void {
		printf(
			'<textarea name="%s" rows="3" class="large-text" placeholder="client@example.com, manager@example.com">%s</textarea><p class="description">%s</p>',
			esc_attr( SWPS_Digest::OPTION_RECIPIENTS ),
			esc_textarea( get_option( SWPS_Digest::OPTION_RECIPIENTS, '' ) ),
			esc_html__( 'Separate addresses with commas, spaces, or new lines. Invalid addresses are ignored. Falls back to the site admin email when empty.', 'stratawp-seo' )
		);
	}

	/**
	 * Render the logo URL field.
	 *
	 * @return void
	 */
	public function render_logo_field(): void {
		printf(
			'<input type="url" name="%s" value="%s" class="regular-text" placeholder="https://" /><p class="description">%s</p>',
			esc_attr( SWPS_Digest::OPTION_LOGO ),
			esc_attr( get_option( SWPS_Digest::OPTION_LOGO, '' ) ),
			esc_html__( 'Shown at the top of the email. Leave empty for no logo.', 'stratawp-seo' )
		);
	}

	/**
	 * Render the accent color field.
	 *
	 * @return void
	 */
	public function render_accent_field(): void {
		printf(
			'<input type="text" name="%s" value="%s" class="small-text" placeholder="#2271b1" /><p class="description">%s</p>',
			esc_attr( SWPS_Digest::OPTION_ACCENT ),
			esc_attr( get_option( SWPS_Digest::OPTION_ACCENT, '' ) ),
			esc_html__( 'Hex color for the header bar and links.', 'stratawp-seo' )
		);
	}

	/**
	 * Render the footer text field.
	 *
	 * @return void
	 */
	public function render_footer_field(): void {
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text" /><p class="description">%s</p>',
			esc_attr( SWPS_Digest::OPTION_FOOTER ),
			esc_attr( get_option( SWPS_Digest::OPTION_FOOTER, '' ) ),
			esc_html__( 'Custom footer line, e.g. your agency name and contact.', 'stratawp-seo' )
		);
	}

	/**
	 * Render the AI summary checkbox.
	 *
	 * @return void
	 */
	public function render_ai_summary_field(): void {
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( SWPS_Digest::OPTION_AI_SUMMARY ),
			checked( 1, (int) get_option( SWPS_Digest::OPTION_AI_SUMMARY, 0 ), false ),
			esc_html__( 'Open each digest with a short AI-written executive summary (uses your configured provider; small per-send cost)', 'stratawp-seo' )
		);
	}

	/**
	 * Whitelist sanitizer for the frequency option.
	 *
	 * @param mixed $value Submitted value.
	 * @return string One of daily|weekly|monthly.
	 */
	public function sanitize_frequency( $value ): string {
		return in_array( $value, array( 'daily', 'weekly', 'monthly' ), true ) ? $value : 'weekly';
	}

	/**
	 * Clear and (when enabled) re-create the digest cron event.
	 *
	 * First run lands one hour after saving, then recurs on the chosen schedule.
	 *
	 * @return void
	 */
	public function reschedule(): void {
		wp_unschedule_hook( SWPS_Digest::CRON_HOOK );

		if ( ! get_option( SWPS_Digest::OPTION_ENABLED ) ) {
			return;
		}

		$map      = array(
			'daily'   => 'daily',
			'weekly'  => 'weekly',
			'monthly' => 'swps_monthly',
		);
		$freq     = get_option( SWPS_Digest::OPTION_FREQUENCY, 'weekly' );
		$schedule = $map[ $freq ] ?? 'weekly';

		wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, SWPS_Digest::CRON_HOOK );
	}

	/**
	 * Handle the test-send GET action: send a 7-day digest to the current user only.
	 *
	 * @return void
	 */
	public function handle_test_send(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'stratawp-seo' ) );
		}
		check_admin_referer( 'swps_digest_test' );

		$sent = $this->digest->send( true, wp_get_current_user()->user_email );

		$redirect = add_query_arg(
			'swps_digest_test',
			$sent ? 'sent' : 'failed',
			admin_url( 'admin.php?page=swps-settings' )
		) . '#analytics';
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Show an admin notice after a test send.
	 *
	 * @return void
	 */
	public function maybe_show_test_notice(): void {
		if ( ! isset( $_GET['swps_digest_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$result = sanitize_key( wp_unslash( $_GET['swps_digest_test'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'sent' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test digest sent — check your inbox (and spam folder).', 'stratawp-seo' ) . '</p></div>';
		} elseif ( 'failed' === $result ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Test digest could not be sent. Check your site\'s mail configuration.', 'stratawp-seo' ) . '</p></div>';
		}
	}
}
