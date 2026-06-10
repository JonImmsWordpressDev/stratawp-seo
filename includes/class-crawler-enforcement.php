<?php
/**
 * SWPS_Crawler_Enforcement — opt-in 403 gate for spoofed/disallowed crawlers.
 *
 * Hooks template_redirect at priority 0 (before SWPS_Redirect_Manager at 1).
 * Reads ONLY the cached CIDR option — zero network I/O at request time.
 *
 * Fail-open contract (mirrored from SWPS_Crawler_Verification::should_block):
 *  - Master toggle off → pass through.
 *  - Bot not in per-bot opt-in list → pass through.
 *  - verdict = '' | 'unverifiable' → pass through.
 *  - verdict = 'verified' AND bot is disallowed → 403.
 *  - verdict = 'spoofed' AND bot is in opt-in list → 403.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Early template_redirect enforcement handler for verified-spoofed/disallowed bots.
 */
class SWPS_Crawler_Enforcement {

	/**
	 * Wire the enforcement hook.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_block' ), 0 );
	}

	/**
	 * Evaluate the current request and issue a 403 when warranted.
	 *
	 * This method is called at template_redirect priority 0 — before any
	 * redirect logic runs. It returns immediately (fail-open) on any
	 * condition that cannot be verified (no UA, no IP, unverifiable verdict,
	 * or master toggle off).
	 */
	public function maybe_block(): void {
		// Master toggle.
		if ( ! (bool) get_option( 'swps_crawler_block_enabled', 0 ) ) {
			return;
		}

		// Never block a logged-in admin testing with a bot-like user agent.
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- UA is used only for substring matching (stripos), never output.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $ua ) {
			return;
		}

		// Identify bot from UA (search bots first, then AI bots — mirrors tracker logic).
		$bot_key = $this->match_bot_key( $ua );
		if ( null === $bot_key ) {
			return;
		}

		// Is this bot in the per-bot opt-in block list?
		$block_bots  = (array) get_option( 'swps_crawler_block_bots', array() );
		$bot_enabled = in_array( $bot_key, $block_bots, true );
		if ( ! $bot_enabled ) {
			return; // Bot not opted-in — fail-open.
		}

		// Resolve client IP from cached proxy setting (zero network).
		$proxy_mode = (string) get_option( 'swps_trusted_proxy_header', 'none' );
		$client_ip  = SWPS_Crawler_Verification::client_ip( $_SERVER, $proxy_mode ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Classify from cached CIDR option only — zero network.
		$ranges  = SWPS_Crawler_Verification::get_cached_ranges();
		$verdict = SWPS_Crawler_Verification::classify_hit( $bot_key, $client_ip, $ranges );

		// Is this bot disallowed in the user's robots.txt settings?
		$user_blocked = $this->is_user_blocked( $bot_key );

		if ( SWPS_Crawler_Verification::should_block( $verdict, true, $bot_enabled, $user_blocked ) ) {
			status_header( 403 );
			nocache_headers();
			exit;
		}
	}

	/**
	 * Match a UA string against both search-bot and AI-bot lists.
	 * Returns the bot_key string, or null when unmatched.
	 *
	 * @param string $ua User-agent string.
	 * @return string|null
	 */
	private function match_bot_key( string $ua ): ?string {
		foreach ( SWPS_AI_Bots::get_search_bots() as $key => $token ) {
			if ( stripos( $ua, $token ) !== false ) {
				return $key;
			}
		}
		foreach ( SWPS_AI_Bots::get_bots() as $key => $token ) {
			if ( stripos( $ua, $token ) !== false ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * True when the user has explicitly disallowed this bot in the robots.txt settings.
	 *
	 * @param string $bot_key Bot key.
	 */
	private function is_user_blocked( string $bot_key ): bool {
		$stored = get_option( 'swps_ai_bots_allowed', null );
		if ( null === $stored ) {
			// Default: all bots allowed — not blocked.
			return false;
		}
		// Bot is blocked when its key is in KNOWN_BOTS but NOT in the allowed set.
		if ( ! isset( SWPS_AI_Bots::KNOWN_BOTS[ $bot_key ] ) ) {
			return false; // Search bots are never in the robots.txt allowlist.
		}
		$allowed = array_keys( array_filter( (array) $stored ) );
		return ! in_array( $bot_key, $allowed, true );
	}
}
