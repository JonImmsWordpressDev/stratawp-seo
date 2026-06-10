<?php
/**
 * Citation tracker admin — AJAX endpoints, settings section, and asset
 * enqueue for the AI Citations card on the Keywords page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX + settings surface for the AI citation tracker.
 */
class SWPS_Citation_Admin {

	/** Nonce action shared by all citation AJAX endpoints. */
	private const NONCE = 'swps_citations';

	/**
	 * Citation tracker core.
	 *
	 * @var SWPS_Citation_Tracker
	 */
	private SWPS_Citation_Tracker $tracker;

	/**
	 * Wire AJAX endpoints, settings, and assets.
	 *
	 * @param SWPS_Citation_Tracker $tracker Citation tracker instance.
	 */
	public function __construct( SWPS_Citation_Tracker $tracker ) {
		$this->tracker = $tracker;

		add_action( 'admin_init', array( $this, 'register_settings' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_swps_citations_state', array( $this, 'ajax_state' ) );
		add_action( 'wp_ajax_swps_citations_add_prompt', array( $this, 'ajax_add_prompt' ) );
		add_action( 'wp_ajax_swps_citations_remove_prompt', array( $this, 'ajax_remove_prompt' ) );
		add_action( 'wp_ajax_swps_citations_seed', array( $this, 'ajax_seed' ) );
		add_action( 'wp_ajax_swps_citations_run_one', array( $this, 'ajax_run_one' ) );
	}

	/**
	 * Verify nonce + capability on every endpoint.
	 */
	private function verify_request(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ), 403 );
		}
	}

	/**
	 * Enqueue the citations JS on the Keywords page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'swps-keywords' ) ) {
			return;
		}

		wp_enqueue_script(
			'swps-citations',
			SWPS_PLUGIN_URL . 'admin/js/citations.js',
			array( 'jquery' ),
			SWPS_VERSION,
			true
		);

		$engines = array();
		foreach ( SWPS_Provider_Factory::all_ai_providers() as $slug => $provider ) {
			$engines[ $slug ] = $provider->get_name();
		}

		wp_localize_script(
			'swps-citations',
			'swpsCitations',
			array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE ),
				'engines'         => $engines,
				'enabled_engines' => $this->tracker->prompts()->enabled_engines(),
			)
		);
	}

	// -------------------------------------------------------------------------
	// AJAX endpoints
	// -------------------------------------------------------------------------

	/**
	 * Full card state: prompt states, share of voice, cited-domains breakdown,
	 * and monthly cap usage.
	 */
	public function ajax_state(): void {
		$this->verify_request();

		wp_send_json_success(
			array(
				'prompt_states'  => $this->tracker->prompt_states(),
				'share_of_voice' => $this->tracker->share_of_voice_data( 30 ),
				'breakdown'      => $this->tracker->cited_domains_breakdown( 30, 10 ),
				'engines'        => $this->tracker->prompts()->enabled_engines(),
				'cap'            => array(
					'used'  => (int) get_option( SWPS_Citation_Tracker::counter_option(), 0 ),
					'limit' => (int) get_option( SWPS_Citation_Tracker::OPTION_CAP, 200 ),
				),
			)
		);
	}

	/**
	 * Add a tracked prompt.
	 */
	public function ajax_add_prompt(): void {
		$this->verify_request();

		$prompt  = sanitize_text_field( wp_unslash( $_POST['prompt'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_request().
		$post_id = absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_request().

		$result = $this->tracker->prompts()->add_prompt( $prompt, $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * Remove a tracked prompt.
	 */
	public function ajax_remove_prompt(): void {
		$this->verify_request();

		$prompt = sanitize_text_field( wp_unslash( $_POST['prompt'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_request().
		if ( '' === $prompt ) {
			wp_send_json_error( array( 'message' => __( 'Prompt is required.', 'stratawp-seo' ) ) );
		}

		$this->tracker->prompts()->remove_prompt( $prompt );
		wp_send_json_success();
	}

	/**
	 * Build seed candidates from tracked keywords + GSC question queries.
	 * Only callable via this explicit AJAX — never on page load (GSC API cost).
	 */
	public function ajax_seed(): void {
		$this->verify_request();

		wp_send_json_success( $this->tracker->prompts()->seed_candidates( 20 ) );
	}

	/**
	 * Run one chunked check (one prompt × engine per request). A cap-reached
	 * error returns code 'cap' so the JS can abort the run cleanly.
	 */
	public function ajax_run_one(): void {
		$this->verify_request();

		$prompt = sanitize_text_field( wp_unslash( $_POST['prompt'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_request().
		$engine = sanitize_key( $_POST['engine'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_request().

		if ( '' === $prompt || '' === $engine ) {
			wp_send_json_error( array( 'message' => __( 'Prompt and engine are required.', 'stratawp-seo' ) ) );
		}

		$result = $this->tracker->check_one( $prompt, $engine );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => 'swps_citation_cap_reached' === $result->get_error_code() ? 'cap' : $result->get_error_code(),
				)
			);
		}

		$this->tracker->detect_losses();
		wp_send_json_success( $result );
	}

	// -------------------------------------------------------------------------
	// Settings section (page 'stratawp-seo', AI Crawlers tab)
	// -------------------------------------------------------------------------

	/**
	 * Register the AI Citations settings section + fields.
	 */
	public function register_settings(): void {
		add_settings_section(
			'swps_citations_section',
			__( 'AI Citation Tracking', 'stratawp-seo' ),
			array( $this, 'render_section_description' ),
			'stratawp-seo'
		);

		register_setting( 'stratawp-seo', SWPS_Citation_Tracker::OPTION_FREQUENCY, array( 'sanitize_callback' => array( $this, 'sanitize_frequency' ) ) );
		register_setting( 'stratawp-seo', SWPS_Citation_Tracker::OPTION_CAP, array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'stratawp-seo', SWPS_Citation_Prompts::OPTION_ENGINES, array( 'sanitize_callback' => array( $this, 'sanitize_engines' ) ) );

		add_settings_field( SWPS_Citation_Tracker::OPTION_FREQUENCY, __( 'Check Frequency', 'stratawp-seo' ), array( $this, 'render_frequency_field' ), 'stratawp-seo', 'swps_citations_section' );
		add_settings_field( SWPS_Citation_Tracker::OPTION_CAP, __( 'Monthly Call Cap', 'stratawp-seo' ), array( $this, 'render_cap_field' ), 'stratawp-seo', 'swps_citations_section' );
		add_settings_field( SWPS_Citation_Prompts::OPTION_ENGINES, __( 'Engines', 'stratawp-seo' ), array( $this, 'render_engines_field' ), 'stratawp-seo', 'swps_citations_section' );
	}

	/**
	 * Section intro copy with the cost note.
	 */
	public function render_section_description(): void {
		echo '<p>' . esc_html__( 'Scheduled checks ask your AI providers (search-grounded) whether they cite your site for your tracked prompts. Checks use your API keys; some providers bill per search — estimates only.', 'stratawp-seo' ) . '</p>';
	}

	/**
	 * Keep frequency within the supported set.
	 *
	 * @param mixed $value Raw value.
	 * @return string 'daily' or 'weekly'.
	 */
	public function sanitize_frequency( $value ): string {
		return in_array( $value, array( 'daily', 'weekly' ), true ) ? (string) $value : 'weekly';
	}

	/**
	 * Keep only valid provider slugs in the engines selection.
	 *
	 * @param mixed $value Raw value.
	 * @return string[] Valid slugs.
	 */
	public function sanitize_engines( $value ): array {
		$valid = array_keys( SWPS_Provider_Factory::all_ai_providers() );
		$out   = array();
		foreach ( (array) $value as $slug ) {
			if ( in_array( $slug, $valid, true ) ) {
				$out[] = (string) $slug;
			}
		}
		return $out;
	}

	/**
	 * Render the frequency select.
	 */
	public function render_frequency_field(): void {
		$current = (string) get_option( SWPS_Citation_Tracker::OPTION_FREQUENCY, 'weekly' );
		echo '<select name="' . esc_attr( SWPS_Citation_Tracker::OPTION_FREQUENCY ) . '">';
		foreach ( array(
			'weekly' => __( 'Weekly', 'stratawp-seo' ),
			'daily'  => __( 'Daily', 'stratawp-seo' ),
		) as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Render the monthly cap number input.
	 */
	public function render_cap_field(): void {
		printf(
			'<input type="number" min="0" step="1" name="%s" value="%d" class="small-text" /> <p class="description">%s</p>',
			esc_attr( SWPS_Citation_Tracker::OPTION_CAP ),
			(int) get_option( SWPS_Citation_Tracker::OPTION_CAP, 200 ),
			esc_html__( 'Maximum search-grounded calls per calendar month across all engines.', 'stratawp-seo' )
		);
	}

	/**
	 * Render the engines multi-checkbox (providers without keys are disabled).
	 */
	public function render_engines_field(): void {
		$selected = get_option( SWPS_Citation_Prompts::OPTION_ENGINES, array( (string) get_option( 'swps_ai_provider', 'anthropic' ) ) );
		$selected = is_array( $selected ) ? $selected : array();

		foreach ( SWPS_Provider_Factory::all_ai_providers() as $slug => $provider ) {
			$has_key = '' !== $provider->get_api_key();
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s[]" value="%s" %s %s /> %s%s</label>',
				esc_attr( SWPS_Citation_Prompts::OPTION_ENGINES ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				disabled( ! $has_key, true, false ),
				esc_html( $provider->get_name() ),
				$has_key ? '' : ' <em>' . esc_html__( '(no API key saved)', 'stratawp-seo' ) . '</em>'
			);
		}
	}
}
