<?php
/**
 * Google Search Console integration.
 *
 * OAuth 2.0 flow, token management, and Search Analytics API client.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Search_Console {

	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	private const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const API_BASE       = 'https://www.googleapis.com/webmasters/v3';
	private const SCOPE          = 'https://www.googleapis.com/auth/webmasters.readonly';
	private const CACHE_PREFIX   = 'swps_gsc_';
	private const CACHE_TTL      = 43200; // 12 hours.
	private const CRON_HOOK      = 'swps_gsc_refresh_data';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( self::CRON_HOOK, array( $this, 'refresh_cached_data' ) );
	}

	/**
	 * Check if GSC is connected.
	 */
	public function is_connected(): bool {
		return (bool) get_option( 'swps_gsc_connected', false );
	}

	/**
	 * Get the OAuth authorization URL.
	 */
	public function get_auth_url(): string {
		$client_id = get_option( 'swps_gsc_client_id', '' );

		if ( empty( $client_id ) ) {
			return '';
		}

		$state = wp_create_nonce( 'swps_gsc_oauth' );
		update_option( 'swps_gsc_oauth_state', $state );

		$params = array(
			'client_id'     => $client_id,
			'redirect_uri'  => $this->get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		);

		return self::AUTH_ENDPOINT . '?' . http_build_query( $params );
	}

	/**
	 * Handle the OAuth callback from Google.
	 */
	public function handle_oauth_callback(): void {
		if ( ! isset( $_GET['swps_gsc_callback'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = sanitize_text_field( $_GET['state'] ?? '' );
		$saved = get_option( 'swps_gsc_oauth_state', '' );

		if ( empty( $state ) || $state !== $saved ) {
			add_settings_error( 'stratawp-seo', 'gsc_oauth', __( 'Invalid OAuth state. Please try again.', 'stratawp-seo' ) );
			return;
		}

		delete_option( 'swps_gsc_oauth_state' );

		if ( isset( $_GET['error'] ) ) {
			add_settings_error( 'stratawp-seo', 'gsc_oauth', __( 'Google authorization denied.', 'stratawp-seo' ) );
			return;
		}

		$code = sanitize_text_field( $_GET['code'] ?? '' );

		if ( empty( $code ) ) {
			add_settings_error( 'stratawp-seo', 'gsc_oauth', __( 'No authorization code received.', 'stratawp-seo' ) );
			return;
		}

		$tokens = $this->exchange_code( $code );

		if ( is_wp_error( $tokens ) ) {
			add_settings_error( 'stratawp-seo', 'gsc_oauth', $tokens->get_error_message() );
			return;
		}

		update_option( 'swps_gsc_access_token', SWPS_Encryption::encrypt( $tokens['access_token'] ) );
		update_option( 'swps_gsc_refresh_token', SWPS_Encryption::encrypt( $tokens['refresh_token'] ) );
		update_option( 'swps_gsc_token_expiry', time() + (int) $tokens['expires_in'] );
		update_option( 'swps_gsc_connected', 1 );

		// Redirect to settings page.
		wp_safe_redirect( admin_url( 'admin.php?page=swps-settings&gsc_connected=1' ) );
		exit;
	}

	/**
	 * Disconnect from GSC — clear all tokens and cached data.
	 */
	public function disconnect(): void {
		delete_option( 'swps_gsc_access_token' );
		delete_option( 'swps_gsc_refresh_token' );
		delete_option( 'swps_gsc_token_expiry' );
		delete_option( 'swps_gsc_connected' );
		delete_option( 'swps_gsc_property' );
		delete_option( 'swps_gsc_oauth_state' );

		$this->clear_cache();
	}

	/**
	 * Get the list of verified GSC properties.
	 *
	 * @return array Site URLs.
	 */
	public function get_properties(): array {
		$response = $this->api_request( '/sites' );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$sites = array();

		foreach ( $response['siteEntry'] ?? array() as $site ) {
			$sites[] = $site['siteUrl'];
		}

		return $sites;
	}

	/**
	 * Fetch search analytics data (queries + pages).
	 *
	 * @param int $days Number of days to fetch.
	 * @return array Search data with 'queries', 'pages', 'daily' keys.
	 */
	public function get_search_data( int $days = 90 ): array {
		$cache_key = self::CACHE_PREFIX . 'search_data_' . $days;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return SWPS_Hooks::filter_gsc_data( $cached, $this->get_property() );
		}

		$property = $this->get_property();
		$start    = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end      = gmdate( 'Y-m-d', strtotime( '-2 days' ) ); // GSC data has 2-day delay.

		$data = array(
			'queries' => $this->fetch_analytics( $property, $start, $end, array( 'query' ) ),
			'pages'   => $this->fetch_analytics( $property, $start, $end, array( 'page' ) ),
			'daily'   => $this->fetch_analytics( $property, $start, $end, array( 'date' ) ),
		);

		set_transient( $cache_key, $data, self::CACHE_TTL );

		return SWPS_Hooks::filter_gsc_data( $data, $property );
	}

	/**
	 * Get search data for a specific page URL.
	 *
	 * @param string $url  Page URL.
	 * @param int    $days Days to look back.
	 * @return array Query data for this page.
	 */
	public function get_page_queries( string $url, int $days = 90 ): array {
		$property = $this->get_property();
		$start    = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end      = gmdate( 'Y-m-d', strtotime( '-2 days' ) );

		$body = array(
			'startDate'             => $start,
			'endDate'               => $end,
			'dimensions'            => array( 'query' ),
			'dimensionFilterGroups' => array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'page',
							'expression' => $url,
						),
					),
				),
			),
			'rowLimit'              => 10,
		);

		$encoded_property = rawurlencode( $property );
		$response         = $this->api_request(
			"/sites/{$encoded_property}/searchAnalytics/query",
			'POST',
			$body
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		return $response['rows'] ?? array();
	}

	/**
	 * Clear all GSC cached data.
	 */
	public function clear_cache(): void {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%'
			)
		);

		// Also delete timeout transients.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%'
			)
		);
	}

	/**
	 * Cron: Refresh cached data in background.
	 */
	public function refresh_cached_data(): void {
		if ( ! $this->is_connected() ) {
			return;
		}

		$this->clear_cache();
		$this->get_search_data( 90 );
	}

	/**
	 * Schedule the data refresh cron.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the data refresh cron.
	 */
	public static function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Get the selected GSC property.
	 */
	private function get_property(): string {
		return get_option( 'swps_gsc_property', '' );
	}

	/**
	 * Get the OAuth redirect URI.
	 */
	private function get_redirect_uri(): string {
		return admin_url( 'admin.php?swps_gsc_callback=1' );
	}

	/**
	 * Exchange authorization code for tokens.
	 *
	 * @param string $code Authorization code.
	 * @return array|WP_Error Token data or error.
	 */
	private function exchange_code( string $code ): array|WP_Error {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'body' => array(
					'code'          => $code,
					'client_id'     => get_option( 'swps_gsc_client_id', '' ),
					'client_secret' => SWPS_Encryption::decrypt( get_option( 'swps_gsc_client_secret', '' ) ),
					'redirect_uri'  => $this->get_redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new WP_Error( 'gsc_token_error', $body['error_description'] ?? $body['error'] );
		}

		if ( empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
			return new WP_Error( 'gsc_token_error', __( 'Invalid token response from Google.', 'stratawp-seo' ) );
		}

		return $body;
	}

	/**
	 * Refresh the access token using the refresh token.
	 *
	 * @return bool True if refreshed successfully.
	 */
	private function refresh_token(): bool {
		$refresh_token = SWPS_Encryption::decrypt( get_option( 'swps_gsc_refresh_token', '' ) );

		if ( empty( $refresh_token ) ) {
			$this->disconnect();
			return false;
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'body' => array(
					'refresh_token' => $refresh_token,
					'client_id'     => get_option( 'swps_gsc_client_id', '' ),
					'client_secret' => SWPS_Encryption::decrypt( get_option( 'swps_gsc_client_secret', '' ) ),
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) || empty( $body['access_token'] ) ) {
			$this->disconnect();
			return false;
		}

		update_option( 'swps_gsc_access_token', SWPS_Encryption::encrypt( $body['access_token'] ) );
		update_option( 'swps_gsc_token_expiry', time() + (int) $body['expires_in'] );

		return true;
	}

	/**
	 * Get a valid access token, refreshing if needed.
	 *
	 * @return string|null Access token or null.
	 */
	private function get_access_token(): ?string {
		$expiry = (int) get_option( 'swps_gsc_token_expiry', 0 );

		if ( $expiry < time() + 60 ) {
			if ( ! $this->refresh_token() ) {
				return null;
			}
		}

		return SWPS_Encryption::decrypt( get_option( 'swps_gsc_access_token', '' ) );
	}

	/**
	 * Make an authenticated API request to GSC.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param string $method   HTTP method.
	 * @param array  $body     Request body for POST.
	 * @return array|WP_Error Response data or error.
	 */
	private function api_request( string $endpoint, string $method = 'GET', array $body = array() ): array|WP_Error {
		$token = $this->get_access_token();

		if ( ! $token ) {
			return new WP_Error( 'gsc_no_token', __( 'Not connected to Google Search Console.', 'stratawp-seo' ) );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = $decoded['error']['message'] ?? "HTTP {$code}";
			return new WP_Error( 'gsc_api_error', $message );
		}

		return json_decode( wp_remote_retrieve_body( $response ), true ) ?: array();
	}

	/**
	 * Fetch search analytics from GSC API.
	 *
	 * @param string $property   Site URL.
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @param array  $dimensions Dimensions to group by.
	 * @return array Rows of analytics data.
	 */
	private function fetch_analytics( string $property, string $start_date, string $end_date, array $dimensions ): array {
		$encoded_property = rawurlencode( $property );

		$response = $this->api_request(
			"/sites/{$encoded_property}/searchAnalytics/query",
			'POST',
			array(
				'startDate'  => $start_date,
				'endDate'    => $end_date,
				'dimensions' => $dimensions,
				'rowLimit'   => 100,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		return $response['rows'] ?? array();
	}
}
