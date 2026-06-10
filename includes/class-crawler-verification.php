<?php
/**
 * SWPS_Crawler_Verification — CIDR/rDNS crawler verification, weekly IP-range
 * fetch, and hourly rDNS batch upgrade for search-bot hits.
 *
 * Architecture notes (plan §Dispatch 1):
 *  - Pure static helpers (ip_in_ranges, classify_hit, rdns_host_allowed, client_ip)
 *    are WordPress-free and fully unit-tested.
 *  - Weekly cron `swps_crawler_ranges_fetch` fetches CIDR lists from providers
 *    and stores them in option `swps_crawler_ip_ranges`.  Network calls ONLY here.
 *  - Hourly cron `swps_crawler_rdns` upgrades 'unverifiable' raw-hit rows via
 *    gethostbyaddr + forward re-resolve.  DNS calls ONLY here.
 *  - Capture path (shutdown hook in tracker) uses CACHED option — zero network.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CIDR/rDNS crawler-hit verification, weekly IP-range fetch, and hourly rDNS batch.
 */
class SWPS_Crawler_Verification {

	/**
	 * Option name storing the cached CIDR map (array keyed by bot_key).
	 */
	public const OPT_IP_RANGES = 'swps_crawler_ip_ranges';

	/**
	 * Option storing the filterable source URL map.
	 */
	public const OPT_IP_SOURCES = 'swps_crawler_ip_sources';

	/**
	 * Stale threshold in seconds — ranges used but staleness flagged above 14 days.
	 */
	public const STALE_THRESHOLD = 14 * DAY_IN_SECONDS;

	/**
	 * Cron hook — weekly IP-range fetch.
	 */
	public const CRON_RANGES = 'swps_crawler_ranges_fetch';

	/**
	 * Cron hook — hourly rDNS batch upgrade.
	 */
	public const CRON_RDNS = 'swps_crawler_rdns';

	/**
	 * Cron hook — daily reconciliation (disallowed-but-crawling + spoofed share).
	 */
	public const CRON_RECONCILE = 'swps_crawler_reconcile';

	/**
	 * Spoofed-share threshold: fire a finding when spoofed hits exceed this fraction.
	 */
	private const SPOOFED_THRESHOLD = 0.20;

	/**
	 * Transient key for the pending admin notice (expires after 2 days).
	 */
	private const TRANSIENT_NOTICE = 'swps_crawler_reconcile_notice';

	/**
	 * RDNS batch size per hourly run.
	 */
	private const RDNS_BATCH = 50;

	/**
	 * Raw hits table suffix (without wpdb prefix).
	 */
	private const RAW_TABLE = 'swps_bot_hits';


	/**
	 * Register cron action handlers.
	 */
	public function __construct() {
		add_action( self::CRON_RANGES, array( $this, 'fetch_ip_ranges' ) );
		add_action( self::CRON_RDNS, array( $this, 'run_rdns_batch' ) );
		add_action( self::CRON_RECONCILE, array( $this, 'run_reconciliation' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_reconcile_notice' ) );
	}

	/**
	 * Schedule all cron events. Called on plugin activation.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_RANGES ) ) {
			wp_schedule_event( time(), 'weekly', self::CRON_RANGES );
		}
		if ( ! wp_next_scheduled( self::CRON_RDNS ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_RDNS );
		}
		if ( ! wp_next_scheduled( self::CRON_RECONCILE ) ) {
			// Run daily at ~02:00 UTC (offset 2 hours from now, then daily).
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON_RECONCILE );
		}
	}

	/**
	 * Unschedule all cron events. Called on plugin deactivation.
	 */
	public static function unschedule_cron(): void {
		foreach ( array( self::CRON_RANGES, self::CRON_RDNS, self::CRON_RECONCILE ) as $hook ) {
			$ts = wp_next_scheduled( $hook );
			if ( $ts ) {
				wp_unschedule_event( $ts, $hook );
			}
		}
	}


	/**
	 * True when $ip falls in any CIDR (v4 + v6; invalid input → false).
	 *
	 * @param string   $ip    A single IPv4 or IPv6 address.
	 * @param string[] $cidrs List of CIDR strings, e.g. '66.249.64.0/19'.
	 */
	public static function ip_in_ranges( string $ip, array $cidrs ): bool {
		if ( '' === $ip || empty( $cidrs ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton emits warnings on bad input; we check return value.
		$ip_long = @inet_pton( $ip );
		if ( false === $ip_long ) {
			return false;
		}

		$is_v6 = ( strlen( $ip_long ) === 16 );

		foreach ( $cidrs as $cidr ) {
			if ( ! is_string( $cidr ) || '' === $cidr ) {
				continue;
			}

			$slash = strrpos( $cidr, '/' );
			if ( false === $slash ) {
				continue;
			}

			$range_ip = substr( $cidr, 0, $slash );
			$prefix   = (int) substr( $cidr, $slash + 1 );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same pattern as above.
			$range_bin = @inet_pton( $range_ip );
			if ( false === $range_bin ) {
				continue;
			}

			$range_v6 = ( strlen( $range_bin ) === 16 );
			if ( $is_v6 !== $range_v6 ) {
				// Mismatched address families — skip.
				continue;
			}

			$bits = $is_v6 ? 128 : 32;
			if ( $prefix < 0 || $prefix > $bits ) {
				continue;
			}

			// Build the mask as a raw binary string.
			$mask_bits = str_repeat( '1', $prefix ) . str_repeat( '0', $bits - $prefix );
			$mask_bin  = '';
			foreach ( str_split( $mask_bits, 8 ) as $byte ) {
				$mask_bin .= chr( (int) bindec( $byte ) );
			}

			if ( ( $ip_long & $mask_bin ) === ( $range_bin & $mask_bin ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify a hit verdict from a cached CIDR map.  Zero network I/O.
	 *
	 * @param string $bot_key Bot key, e.g. 'googlebot'.
	 * @param string $ip      Client IP (may be empty — unverifiable).
	 * @param array  $ranges  Map of bot_key → string[] CIDRs, as stored in OPT_IP_RANGES.
	 * @return 'verified'|'spoofed'|'unverifiable'
	 */
	public static function classify_hit( string $bot_key, string $ip, array $ranges ): string {
		if ( '' === $ip ) {
			return 'unverifiable';
		}

		if ( empty( $ranges[ $bot_key ] ) ) {
			// No published ranges for this provider → cannot verify.
			return 'unverifiable';
		}

		$provider_ranges = (array) $ranges[ $bot_key ];
		if ( empty( $provider_ranges ) ) {
			return 'unverifiable';
		}

		return self::ip_in_ranges( $ip, $provider_ranges ) ? 'verified' : 'spoofed';
	}

	/**
	 * Check that a reverse-DNS hostname ends with an approved suffix for the
	 * given bot key (anti-spoof: evilgooglebot.com must not pass).
	 *
	 * @param string $host    The PTR hostname returned by gethostbyaddr.
	 * @param string $bot_key Bot key, e.g. 'googlebot'.
	 */
	public static function rdns_host_allowed( string $host, string $bot_key ): bool {
		$suffixes = self::rdns_suffix_map();

		if ( ! isset( $suffixes[ $bot_key ] ) ) {
			return false;
		}

		$host = strtolower( trim( $host ) );
		foreach ( $suffixes[ $bot_key ] as $suffix ) {
			$suffix = strtolower( $suffix );
			// Suffix must be at the end of the host and must be preceded by a
			// dot or be the entire hostname, preventing evilgooglebot.com matches.
			if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the real client IP from $_SERVER, honoring the proxy mode setting.
	 * Spoof-resistant: headers are only honored when the mode is explicitly set.
	 *
	 * @param array  $server $_SERVER superglobal (or test fixture).
	 * @param string $mode   Proxy mode: 'none', 'cf', or 'xff'.
	 */
	public static function client_ip( array $server, string $mode ): string {
		$remote = isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';

		switch ( $mode ) {
			case 'cf':
				$cf = isset( $server['HTTP_CF_CONNECTING_IP'] )
					? trim( (string) $server['HTTP_CF_CONNECTING_IP'] )
					: '';
				return '' !== $cf ? $cf : $remote;

			case 'xff':
				$xff = isset( $server['HTTP_X_FORWARDED_FOR'] )
					? (string) $server['HTTP_X_FORWARDED_FOR']
					: '';
				if ( '' !== $xff ) {
					$parts = explode( ',', $xff );
					$first = trim( $parts[0] );
					if ( '' !== $first ) {
						return $first;
					}
				}
				return $remote;

			default:
				return $remote;
		}
	}

	/**
	 * Map of bot_key → allowed PTR hostname suffixes for rdns_host_allowed().
	 *
	 * @return array<string, string[]>
	 */
	private static function rdns_suffix_map(): array {
		$anth = array( 'anthropic.com' );
		$oai  = array( 'openai.com' );
		$pplx = array( 'perplexity.ai' );
		$goog = array( 'googlebot.com', 'google.com' );
		return array(
			'googlebot'       => $goog,
			'googlebot_image' => $goog,
			'bingbot'         => array( 'search.msn.com' ),
			'applebot'        => array( 'applebot.apple.com' ),
			'yandexbot'       => array( 'yandex.ru', 'yandex.net', 'yandex.com' ),
			'duckduckbot'     => array( 'duckduckgo.com' ),
			'claudebot'       => $anth,
			'claude_web'      => $anth,
			'anthropic_ai'    => $anth,
			'gptbot'          => $oai,
			'oai_searchbot'   => $oai,
			'chatgpt_user'    => $oai,
			'perplexitybot'   => $pplx,
			'perplexity_user' => $pplx,
		);
	}

	/**
	 * Return the default (filterable) map of bot_key → fetch URL(s).
	 * Multiple URLs per key are fetched and merged.
	 *
	 * @return array<string, string[]>
	 */
	public static function default_ip_sources(): array {
		// Applebot and Anthropic publish no CIDR list — those bots use the rDNS path only.
		$googlebot_url = 'https://developers.google.com/static/search/apis/ipranges/googlebot.json';
		$sources       = array(
			'googlebot'       => array( $googlebot_url ),
			'googlebot_image' => array( $googlebot_url ),
			'bingbot'         => array( 'https://www.bing.com/toolbox/bingbot.json' ),
			'gptbot'          => array( 'https://openai.com/gptbot.json' ),
			'oai_searchbot'   => array( 'https://openai.com/searchbot.json' ),
			'chatgpt_user'    => array( 'https://openai.com/chatgpt-user.json' ),
			'perplexitybot'   => array( 'https://www.perplexity.ai/perplexitybot.json' ),
		);

		/**
		 * Filter the IP-range source URL map.
		 * Keys are bot_key strings; values are arrays of fetch URLs.
		 *
		 * @param array<string, string[]> $sources Default source map.
		 */
		return (array) apply_filters( 'swps_crawler_ip_sources', $sources );
	}


	/**
	 * Fetch all provider CIDR lists and store in the cached option.
	 * On per-provider failure the previous ranges for that provider are kept.
	 */
	public function fetch_ip_ranges(): void {
		$sources  = self::default_ip_sources();
		$existing = (array) get_option( self::OPT_IP_RANGES, array() );
		$ranges   = isset( $existing['ranges'] ) ? (array) $existing['ranges'] : array();

		foreach ( $sources as $bot_key => $urls ) {
			$merged = array();
			$ok     = true;

			foreach ( (array) $urls as $url ) {
				$result = $this->fetch_single_source( $url );
				if ( null === $result ) {
					$ok = false;
					break;
				}
				$merged = array_merge( $merged, $result );
			}

			if ( $ok && ! empty( $merged ) ) {
				$ranges[ $bot_key ] = array_values( array_unique( $merged ) );
			}
			// On failure: keep existing value (or absent) — fail-open, no wipe.
		}

		update_option(
			self::OPT_IP_RANGES,
			array(
				'ranges'     => $ranges,
				'fetched_at' => time(),
			),
			false
		);
	}

	/**
	 * Fetch one JSON source URL and normalize to a flat CIDR list.
	 * Returns null on any failure (caller keeps previous data).
	 *
	 * JSON shapes handled:
	 *  - Google: { "prefixes": [ {"ipv4Prefix":"..."}, {"ipv6Prefix":"..."} ] }
	 *  - Bing:   { "prefixes": [ {"ipv4Prefix":"..."}, {"ipv6Prefix":"..."} ] }
	 *  - OpenAI: { "prefixes": [ "1.2.3.0/24", ... ] }
	 *  - Perplexity: { "prefixes": [ "1.2.3.0/24", ... ] }
	 *
	 * @param string $url The HTTPS endpoint.
	 * @return string[]|null Flat CIDR list, or null on failure.
	 */
	private function fetch_single_source( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'StrataWP-SEO/' . SWPS_VERSION . ' (crawler-ip-range-fetch)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['prefixes'] ) ) {
			return null;
		}

		$cidrs = array();
		foreach ( $data['prefixes'] as $entry ) {
			if ( is_string( $entry ) ) {
				// OpenAI / Perplexity flat-string format.
				$cidrs[] = $entry;
				continue;
			}
			if ( is_array( $entry ) ) {
				// Google / Bing object format.
				if ( ! empty( $entry['ipv4Prefix'] ) ) {
					$cidrs[] = (string) $entry['ipv4Prefix'];
				}
				if ( ! empty( $entry['ipv6Prefix'] ) ) {
					$cidrs[] = (string) $entry['ipv6Prefix'];
				}
			}
		}

		return array_filter( $cidrs );
	}


	/**
	 * Upgrade up to RDNS_BATCH 'unverifiable' raw rows via forward-confirmed rDNS.
	 * DNS I/O happens ONLY here — never at capture or enforcement time.
	 */
	public function run_rdns_batch(): void {
		global $wpdb;

		$table  = $wpdb->prefix . self::RAW_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, ip, bot_key FROM {$table}
				 WHERE verified = 'unverifiable' AND ip != '' AND created_at >= %s
				 ORDER BY created_at DESC
				 LIMIT %d",
				$cutoff,
				self::RDNS_BATCH
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$verdict = $this->rdns_verify( (string) $row['ip'], (string) $row['bot_key'] );
			if ( null !== $verdict ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array( 'verified' => $verdict ),
					array( 'id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}

	/**
	 * Forward-confirmed rDNS: PTR lookup → suffix check → A/AAAA re-resolve.
	 * Returns 'verified', 'spoofed', or null when DNS is inconclusive.
	 *
	 * @param string $ip      The IP stored in the raw row.
	 * @param string $bot_key The bot key for suffix matching.
	 * @return 'verified'|'spoofed'|null
	 */
	private function rdns_verify( string $ip, string $bot_key ): ?string {
		// Step 1: PTR lookup.
		$hostname = @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $hostname || $hostname === $ip ) {
			// No PTR record — inconclusive; leave as unverifiable.
			return null;
		}

		// Step 2: Suffix check.
		if ( ! self::rdns_host_allowed( $hostname, $bot_key ) ) {
			return 'spoofed';
		}

		// Step 3: Forward re-resolve — hostname must map back to the original IP.
		$resolved = @gethostbynamel( $hostname ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $resolved ) || ! in_array( $ip, $resolved, true ) ) {
			return 'spoofed';
		}

		return 'verified';
	}


	/**
	 * Load the cached ranges from the option.  Returns the ranges sub-array.
	 *
	 * @return array<string, string[]>
	 */
	public static function get_cached_ranges(): array {
		$stored = get_option( self::OPT_IP_RANGES, array() );
		if ( ! is_array( $stored ) || empty( $stored['ranges'] ) ) {
			return array();
		}
		return (array) $stored['ranges'];
	}

	/**
	 * True when the cached ranges are present but older than the stale threshold.
	 */
	public static function is_stale(): bool {
		$stored = get_option( self::OPT_IP_RANGES, array() );
		if ( ! is_array( $stored ) || empty( $stored['fetched_at'] ) ) {
			return false; // No data yet — not "stale", just absent.
		}
		return ( time() - (int) $stored['fetched_at'] ) > self::STALE_THRESHOLD;
	}


	// -------------------------------------------------------------------------
	// Pure enforcement decision
	// -------------------------------------------------------------------------

	/**
	 * Decide whether to issue a 403 for a request.
	 *
	 * Fail-open contract:
	 *  - 'unverifiable' → NEVER blocks (no ranges available / proxy misconfig).
	 *  - '' (empty)     → NEVER blocks (capture hasn't run yet).
	 *  - master toggle OFF → NEVER blocks.
	 *  - bot not in per-bot block list → NEVER blocks.
	 *  - 'verified' + user explicitly blocked bot → blocks.
	 *  - 'spoofed'  + master + bot enabled → blocks.
	 *
	 * @param string $verdict    'verified' | 'spoofed' | 'unverifiable' | ''.
	 * @param bool   $master     True when the master enforcement toggle is on.
	 * @param bool   $bot_enabled True when this specific bot is opted-in to blocking.
	 * @param bool   $user_blocked True when the user has disallowed this bot in robots.txt settings.
	 * @return bool True → issue 403; false → allow through (fail-open default).
	 */
	public static function should_block(
		string $verdict,
		bool $master,
		bool $bot_enabled,
		bool $user_blocked = false
	): bool {
		if ( ! $master ) {
			return false;
		}
		if ( '' === $verdict || 'unverifiable' === $verdict ) {
			return false; // Fail-open.
		}
		if ( ! $bot_enabled ) {
			return false;
		}
		if ( 'verified' === $verdict && $user_blocked ) {
			return true; // Verified disallowed bot — block.
		}
		if ( 'spoofed' === $verdict ) {
			return true; // Spoofed identity — block.
		}
		return false;
	}


	// -------------------------------------------------------------------------
	// Daily reconciliation
	// -------------------------------------------------------------------------

	/**
	 * Daily reconciliation cron handler.
	 *
	 * Compares yesterday's verified bot hits against the user's robots.txt intent
	 * (swps_ai_bots_allowed option) and checks for spoofed-share spikes.
	 *
	 * Two-consecutive-day smoothing: a finding must appear on two consecutive
	 * calendar dates before it triggers the notice/email.
	 */
	public function run_reconciliation(): void {
		$report    = new SWPS_Crawl_Budget_Report();
		$stats     = $report->get_yesterday_bot_stats();
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		if ( empty( $stats ) ) {
			return; // No data — nothing to reconcile.
		}

		// Determine which bots the user has explicitly blocked in robots.txt.
		$allowed_raw  = get_option( 'swps_ai_bots_allowed', null );
		$blocked_keys = array();
		if ( is_array( $allowed_raw ) ) {
			$all_known    = array_keys( SWPS_AI_Bots::KNOWN_BOTS );
			$allowed_keys = array_values( array_filter( (array) $allowed_raw, 'is_string' ) );
			$blocked_keys = array_values( array_diff( $all_known, $allowed_keys ) );
		}

		$today_findings = array(
			'disallowed_crawling' => array(),
			'spoofed_bots'        => array(),
		);

		foreach ( $stats as $bot_key => $data ) {
			$total   = (int) $data['total_hits'];
			$spoofed = (int) $data['spoofed_hits'];

			if ( $total <= 0 ) {
				continue;
			}

			// (a) Disallowed bot still crawling as VERIFIED.
			if ( in_array( $bot_key, $blocked_keys, true ) && (int) $data['verified_hits'] > 0 ) {
				$today_findings['disallowed_crawling'][] = $bot_key;
			}

			// (b) Spoofed share > 20%.
			if ( ( $spoofed / $total ) > self::SPOOFED_THRESHOLD ) {
				$today_findings['spoofed_bots'][] = $bot_key;
			}
		}

		// Two-consecutive-day smoothing.
		$prev = (array) get_option( SWPS_Crawl_Budget_Report::OPT_RECONCILE_PREV, array() );

		$smoothed = array(
			'disallowed_crawling' => array(),
			'spoofed_bots'        => array(),
		);

		if ( ! empty( $prev['date'] ) && ! empty( $prev['findings'] ) ) {
			$prev_date     = (string) $prev['date'];
			$prev_findings = (array) $prev['findings'];
			$expected_prev = gmdate( 'Y-m-d', strtotime( $yesterday . ' -1 day' ) );

			if ( $prev_date === $expected_prev ) {
				// Two consecutive days — intersect findings.
				foreach ( array( 'disallowed_crawling', 'spoofed_bots' ) as $key ) {
					$prev_set         = (array) ( $prev_findings[ $key ] ?? array() );
					$today_set        = $today_findings[ $key ];
					$smoothed[ $key ] = array_values( array_intersect( $prev_set, $today_set ) );
				}
			}
		}

		// Store today's raw findings for tomorrow's smoothing pass.
		update_option(
			SWPS_Crawl_Budget_Report::OPT_RECONCILE_PREV,
			array(
				'date'     => $yesterday,
				'findings' => $today_findings,
			),
			false
		);

		if ( empty( $smoothed['disallowed_crawling'] ) && empty( $smoothed['spoofed_bots'] ) ) {
			// No smoothed findings — clear any stale notice transient.
			delete_transient( self::TRANSIENT_NOTICE );
			update_option( SWPS_Crawl_Budget_Report::OPT_RECONCILE_FINDINGS, array(), false );
			return;
		}

		// Persist smoothed findings and set notice transient (2-day TTL).
		update_option( SWPS_Crawl_Budget_Report::OPT_RECONCILE_FINDINGS, $smoothed, false );
		set_transient( self::TRANSIENT_NOTICE, $smoothed, 2 * DAY_IN_SECONDS );

		// Send email if not suppressed.
		$this->maybe_send_reconcile_email( $smoothed );
	}

	/**
	 * Render an admin notice when the reconciliation transient is set.
	 * The notice is rendered once; admins can dismiss it.
	 */
	public function maybe_show_reconcile_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$findings = get_transient( self::TRANSIENT_NOTICE );
		if ( ! is_array( $findings ) || ( empty( $findings['disallowed_crawling'] ) && empty( $findings['spoofed_bots'] ) ) ) {
			return;
		}

		$lines = array();
		if ( ! empty( $findings['disallowed_crawling'] ) ) {
			$bots    = implode( ', ', array_map( 'esc_html', $findings['disallowed_crawling'] ) );
			$lines[] = sprintf(
				/* translators: %s: comma-separated bot keys */
				__( '<strong>Disallowed bots still crawling (verified):</strong> %s', 'stratawp-seo' ),
				$bots
			);
		}
		if ( ! empty( $findings['spoofed_bots'] ) ) {
			$bots    = implode( ', ', array_map( 'esc_html', $findings['spoofed_bots'] ) );
			$lines[] = sprintf(
				/* translators: %s: comma-separated bot keys */
				__( '<strong>High spoofed-traffic share (>20%%):</strong> %s', 'stratawp-seo' ),
				$bots
			);
		}

		echo '<div class="notice notice-warning is-dismissible">';
		echo '<p><strong>' . esc_html__( 'StrataWP SEO — Crawler Reconciliation', 'stratawp-seo' ) . '</strong></p>';
		echo '<ul>';
		foreach ( $lines as $line ) {
			echo '<li>' . wp_kses( $line, array( 'strong' => array() ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</ul>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=swps-analytics' ) ) . '">' . esc_html__( 'View Crawl Budget Report', 'stratawp-seo' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Send a reconciliation digest email if not suppressed.
	 *
	 * Uses swps_crawler_email option (default 1 = enabled).
	 * Recipients fall back to swps_digest_recipients, then admin_email.
	 *
	 * @param array{disallowed_crawling: string[], spoofed_bots: string[]} $findings Smoothed findings.
	 */
	private function maybe_send_reconcile_email( array $findings ): void {
		if ( ! (bool) get_option( 'swps_crawler_email', 1 ) ) {
			return;
		}

		$recipients_raw = (string) get_option( 'swps_digest_recipients', '' );
		if ( $recipients_raw ) {
			$recipients = array_filter( array_map( 'trim', explode( "\n", $recipients_raw ) ) );
		} else {
			$recipients = array( get_option( 'admin_email' ) );
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Crawler Reconciliation Alert', 'stratawp-seo' ),
			get_bloginfo( 'name' )
		);

		$lines = array();
		if ( ! empty( $findings['disallowed_crawling'] ) ) {
			$lines[] = '- Disallowed bots crawling (verified): ' . implode( ', ', $findings['disallowed_crawling'] );
		}
		if ( ! empty( $findings['spoofed_bots'] ) ) {
			$lines[] = '- Spoofed traffic spike (>20%): ' . implode( ', ', $findings['spoofed_bots'] );
		}

		$body = sprintf(
			/* translators: 1: site name, 2: findings list */
			__( "Crawler reconciliation findings for %1\$s (2-consecutive-day confirmed):\n\n%2\$s\n\nLog in to StrataWP SEO to review the Crawl Budget report.", 'stratawp-seo' ),
			get_bloginfo( 'name' ),
			implode( "\n", $lines )
		);

		try {
			wp_mail( $recipients, $subject, $body );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $e );
		}
	}
}
