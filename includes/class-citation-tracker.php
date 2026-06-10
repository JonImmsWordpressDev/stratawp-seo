<?php
/**
 * Citation tracker — prompts, capped search-grounded checks, cron, AEO loss
 * hook, and pure static helpers. Storage lives in SWPS_Citation_Store.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages AI citation tracking: prompts, capped checks, cron, AEO loss hook,
 * and pure static helpers.
 *
 * @package StrataWP_SEO
 */
class SWPS_Citation_Tracker {

	public const CRON_HOOK        = 'swps_citation_check';
	public const OPTION_FREQUENCY = 'swps_citation_frequency';
	public const OPTION_CAP       = 'swps_citation_monthly_cap';
	public const META_LOSS        = '_swps_citation_loss';

	/** Default monthly search-call cap. */
	private const DEFAULT_CAP = 200;

	/**
	 * Prompt list, engines, and domain configuration.
	 *
	 * @var SWPS_Citation_Prompts
	 */
	private SWPS_Citation_Prompts $prompts;

	/**
	 * Wire cron, reschedule-on-change, and the AEO proposal filter.
	 *
	 * @param SWPS_Citation_Prompts $prompts Prompt/engine/domain configuration.
	 */
	public function __construct( SWPS_Citation_Prompts $prompts ) {
		$this->prompts = $prompts;

		add_action( self::CRON_HOOK, array( $this, 'run_checks' ) );

		// Reschedule when the frequency changes (both add_ and update_ in case
		// activation defaults were never seeded) — digest precedent.
		add_action( 'update_option_' . self::OPTION_FREQUENCY, array( __CLASS__, 'reschedule_cron' ), 10, 0 );
		add_action( 'add_option_' . self::OPTION_FREQUENCY, array( __CLASS__, 'reschedule_cron' ), 10, 0 );

		add_filter( 'swps_aeo_proposal', array( $this, 'filter_aeo_proposal' ), 10, 2 );
	}

	/**
	 * Prompt/engine/domain configuration (for admin UI access).
	 */
	public function prompts(): SWPS_Citation_Prompts {
		return $this->prompts;
	}

	// -------------------------------------------------------------------------
	// Pure static helpers (WP-free; fully unit-tested)
	// -------------------------------------------------------------------------

	/**
	 * Extract registrable hosts from an array of citation URLs.
	 *
	 * Rules:
	 * - Parse each entry with wp_parse_url(); skip anything without a valid host.
	 * - Lowercase and strip a leading "www." prefix.
	 * - Deduplicate; return an indexed (0-based) array.
	 *
	 * Note: entries that are already bare domain strings (no scheme) are treated
	 * as-is after lowercasing and www-stripping — they are not parsed as URLs.
	 * The Google provider stores vertexaisearch titles (bare domains) directly,
	 * so this function intentionally does NOT skip non-URL strings.
	 *
	 * @param string[] $urls Raw citation entries (URLs or bare domain strings).
	 * @return string[]      Lowercased, www-stripped, deduplicated hosts.
	 */
	public static function extract_domains( array $urls ): array {
		$seen  = array();
		$hosts = array();

		foreach ( $urls as $entry ) {
			if ( ! is_string( $entry ) || '' === trim( $entry ) ) {
				continue;
			}

			$parsed = wp_parse_url( $entry );

			if ( ! empty( $parsed['host'] ) ) {
				// Full URL with a recognisable host component.
				$host = strtolower( $parsed['host'] );
			} elseif ( empty( $parsed['scheme'] ) && empty( $parsed['path'] ) === false ) {
				// No scheme → parse_url put everything in 'path'; treat it as the
				// raw domain string (Google vertexaisearch title fall-through).
				$raw  = trim( $parsed['path'] );
				$host = strtolower( $raw );
				// Reject strings that contain slashes — they are paths, not domains.
				if ( false !== strpos( $host, '/' ) ) {
					continue;
				}
				// Must contain at least one dot to look like a domain.
				if ( false === strpos( $host, '.' ) ) {
					continue;
				}
			} else {
				continue;
			}

			// Strip leading "www.".
			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}

			if ( '' === $host || isset( $seen[ $host ] ) ) {
				continue;
			}

			$seen[ $host ] = true;
			$hosts[]       = $host;
		}

		return $hosts;
	}

	/**
	 * Return true when $domain (or any subdomain of it) appears in $hosts.
	 *
	 * Examples (domain = "example.com"):
	 * - "example.com"     → true  (exact match)
	 * - "sub.example.com" → true  (subdomain match)
	 * - "notexample.com"  → false (different registrable domain)
	 *
	 * @param string   $domain The registrable domain to look for (e.g. "example.com").
	 * @param string[] $hosts  List of lowercased, www-stripped host strings.
	 * @return bool
	 */
	public static function domain_cited( string $domain, array $hosts ): bool {
		$domain = strtolower( $domain );
		$suffix = '.' . $domain;

		foreach ( $hosts as $host ) {
			$host = strtolower( (string) $host );
			if ( $host === $domain || str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compute a smoothed citation state from a newest-first history of booleans.
	 *
	 * States:
	 * - 'cited'  — latest two checks both true  (or history has exactly one entry and it is true).
	 * - 'lost'   — at least one true anywhere in history AND the latest two checks are both false.
	 * - 'never'  — no true value anywhere in history (including empty history).
	 * - 'mixed'  — none of the above (e.g. one true, one false in the last two; or only one false).
	 *
	 * @param bool[] $history Newest-first array of cited booleans.
	 * @return string 'cited' | 'lost' | 'never' | 'mixed'
	 */
	public static function citation_state( array $history ): string {
		if ( empty( $history ) ) {
			return 'never';
		}

		$has_any_true = in_array( true, $history, true );

		if ( ! $has_any_true ) {
			return 'never';
		}

		$count  = count( $history );
		$latest = $history[0];

		// Single-entry history.
		if ( 1 === $count ) {
			return $latest ? 'cited' : 'lost';
		}

		$second_latest = $history[1];

		// Latest two both true → cited.
		if ( $latest && $second_latest ) {
			return 'cited';
		}

		// Latest two both false AND there is at least one true → lost.
		if ( ! $latest && ! $second_latest ) {
			return 'lost';
		}

		// One true and one false in the latest two — ambiguous.
		return 'mixed';
	}

	/**
	 * Compute share-of-voice percentages from per-domain cited counts.
	 *
	 * Returns an associative array of the same keys as $per_domain_counts, each
	 * value being the percentage (0–100, rounded to one decimal) of total prompts
	 * where that domain was cited. Division-safe: returns 0.0 for all when
	 * $total_prompts is zero.
	 *
	 * @param array<string, int> $per_domain_counts Domain => number of prompts cited.
	 * @param int                $total_prompts     Total prompts checked.
	 * @return array<string, float>                 Domain => percentage (0–100).
	 */
	public static function share_of_voice( array $per_domain_counts, int $total_prompts ): array {
		$result = array();

		foreach ( $per_domain_counts as $domain => $count ) {
			if ( $total_prompts <= 0 ) {
				$result[ $domain ] = 0.0;
			} else {
				$result[ $domain ] = round( ( (int) $count / $total_prompts ) * 100, 1 );
			}
		}

		return $result;
	}

	/**
	 * Classify a GSC query string as a question-form query.
	 *
	 * A query qualifies when it:
	 * (a) begins with a question word at a word boundary (who|what|how|why|when|where|which|can|does|do|is|are|should), OR
	 * (b) contains 5 or more whitespace-delimited words (long-tail).
	 *
	 * @param string $query Raw GSC query string.
	 * @return bool True when the query looks like a question or long-tail phrase.
	 */
	public static function is_question_query( string $query ): bool {
		$query = trim( $query );

		if ( '' === $query ) {
			return false;
		}

		// Rule (a): starts with a question word (case-insensitive, word boundary).
		if ( (bool) preg_match( '/^(who|what|how|why|when|where|which|can|does|do|is|are|should)\b/i', $query ) ) {
			return true;
		}

		// Rule (b): 5 or more words.
		$words = preg_split( '/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY );
		return count( $words ) >= 5;
	}

	// -------------------------------------------------------------------------
	// Cron scheduling (keyword-tracker precedent)
	// -------------------------------------------------------------------------

	/**
	 * Schedule the citation check cron at the configured frequency.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$frequency = get_option( self::OPTION_FREQUENCY, 'weekly' );
			$frequency = in_array( $frequency, array( 'daily', 'weekly' ), true ) ? $frequency : 'weekly';
			wp_schedule_event( time(), $frequency, self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the citation check cron.
	 */
	public static function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Reschedule with the new frequency (hooked to option changes).
	 */
	public static function reschedule_cron(): void {
		self::unschedule_cron();
		self::schedule_cron();
	}

	// -------------------------------------------------------------------------
	// Capped check runner
	// -------------------------------------------------------------------------

	/**
	 * Current month's call-counter option name (swps_citation_calls_YYYY_MM).
	 */
	public static function counter_option(): string {
		return 'swps_citation_calls_' . gmdate( 'Y_m' );
	}

	/**
	 * Whether the monthly call cap has been reached.
	 */
	public function cap_reached(): bool {
		$cap = (int) get_option( self::OPTION_CAP, self::DEFAULT_CAP );
		return (int) get_option( self::counter_option(), 0 ) >= $cap;
	}

	/**
	 * Run one search-grounded citation check for a prompt × engine pair and
	 * persist today's row. The cap is checked FIRST and the counter increments
	 * BEFORE the provider call (failed calls may still bill).
	 *
	 * @param string $prompt The prompt to check.
	 * @param string $engine Provider slug (anthropic|openai|google|xai).
	 * @return array|WP_Error The stored row data on success.
	 */
	public function check_one( string $prompt, string $engine ): array|WP_Error {
		$prompt = mb_substr( sanitize_text_field( trim( $prompt ) ), 0, 500 );
		if ( '' === $prompt ) {
			return new WP_Error( 'swps_citation_empty_prompt', __( 'Prompt cannot be empty.', 'stratawp-seo' ) );
		}

		// Cap guard FIRST.
		if ( $this->cap_reached() ) {
			return new WP_Error(
				'swps_citation_cap_reached',
				__( 'Monthly AI search call cap reached. Raise the cap in settings or wait for next month.', 'stratawp-seo' )
			);
		}

		$providers = SWPS_Provider_Factory::all_ai_providers();
		if ( ! isset( $providers[ $engine ] ) ) {
			return new WP_Error( 'swps_citation_invalid_engine', __( 'Unknown AI engine.', 'stratawp-seo' ) );
		}

		if ( ! in_array( $engine, $this->prompts->enabled_engines(), true ) ) {
			return new WP_Error( 'swps_citation_engine_disabled', __( 'This engine is not enabled for citation checks.', 'stratawp-seo' ) );
		}

		// Increment BEFORE the call — failed calls may still bill.
		$counter = self::counter_option();
		update_option( $counter, (int) get_option( $counter, 0 ) + 1, false );

		$result = $providers[ $engine ]->search_grounded( $prompt, 1024 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$hosts   = self::extract_domains( $result['citations'] );
		$matched = array();
		foreach ( $this->prompts->our_domains() as $domain ) {
			if ( self::domain_cited( $domain, $hosts ) ) {
				$matched[] = $domain;
			}
		}

		$row = array(
			'prompt_hash'   => md5( $prompt ),
			'prompt'        => $prompt,
			'engine'        => $engine,
			'check_date'    => gmdate( 'Y-m-d' ),
			'cited'         => empty( $matched ) ? 0 : 1,
			'our_domains'   => (string) wp_json_encode( $matched ),
			'cited_domains' => (string) wp_json_encode( $hosts ),
		);

		SWPS_Citation_Store::upsert_check( $row );

		return $row;
	}

	/**
	 * Cron callback: loop prompts × enabled engines with the cap guard, prune
	 * old rows, then run the loss detector.
	 */
	public function run_checks(): void {
		$engines = $this->prompts->enabled_engines();

		foreach ( $this->prompts->get_prompts() as $entry ) {
			foreach ( $engines as $engine ) {
				$result = $this->check_one( $entry['prompt'], $engine );
				if ( is_wp_error( $result ) && 'swps_citation_cap_reached' === $result->get_error_code() ) {
					break 2; // Cap hit — stop the whole run.
				}
			}
		}

		SWPS_Citation_Store::prune( 180 );
		$this->detect_losses();
	}

	// -------------------------------------------------------------------------
	// State queries (implementations live in SWPS_Citation_Store)
	// -------------------------------------------------------------------------

	/**
	 * Per-prompt per-engine citation states (smoothed over last 3 checks).
	 *
	 * @return array[] Entries: { prompt, post_id, states: engine => { state, last_date } }.
	 */
	public function prompt_states(): array {
		return SWPS_Citation_Store::prompt_states( $this->prompts->get_prompts(), $this->prompts->enabled_engines() );
	}

	/**
	 * Share-of-voice data over the window for us + competitors.
	 *
	 * @param int $days Window in days.
	 * @return array { total_prompts: int, counts: domain => int, pct: domain => float, us: string }.
	 */
	public function share_of_voice_data( int $days = 30 ): array {
		return SWPS_Citation_Store::share_of_voice_data( $this->prompts->our_domains(), $this->prompts->competitor_domains(), $days );
	}

	/**
	 * Top domains cited by AI engines for our prompts over the window.
	 *
	 * @param int $days  Window in days.
	 * @param int $limit Max domains returned.
	 * @return array<string, int> domain => citation count, descending.
	 */
	public function cited_domains_breakdown( int $days = 30, int $limit = 10 ): array {
		return SWPS_Citation_Store::cited_domains_breakdown( $days, $limit );
	}

	// -------------------------------------------------------------------------
	// Lost-citation → AEO context
	// -------------------------------------------------------------------------

	/**
	 * Detect prompts (with post_id > 0) in the 'lost' state and store loss
	 * context as post meta; clear the meta when the state returns to 'cited'.
	 */
	public function detect_losses(): void {
		foreach ( $this->prompt_states() as $row ) {
			$post_id = (int) $row['post_id'];
			if ( $post_id <= 0 ) {
				continue;
			}

			$lost_engines = array();
			$cited_any    = false;
			foreach ( $row['states'] as $engine => $info ) {
				if ( 'lost' === $info['state'] ) {
					$lost_engines[] = $engine;
				} elseif ( 'cited' === $info['state'] ) {
					$cited_any = true;
				}
			}

			if ( ! empty( $lost_engines ) ) {
				update_post_meta(
					$post_id,
					self::META_LOSS,
					array(
						'prompt'            => $row['prompt'],
						'engines'           => $lost_engines,
						'top_cited_domains' => array_keys( array_slice( SWPS_Citation_Store::cited_domains_breakdown( 30, 5 ), 0, 5, true ) ),
						'time'              => time(),
					)
				);
			} elseif ( $cited_any ) {
				delete_post_meta( $post_id, self::META_LOSS );
			}
		}
	}

	/**
	 * Append lost-citation context to an AEO proposal payload.
	 *
	 * The swps_aeo_proposal filter fires AFTER the AI call, so this cannot
	 * influence the generation prompt; instead it conservatively attaches a
	 * citation_loss key (raw meta) and a human-readable citation_loss_note to
	 * the proposal payload for the editor UI to surface alongside the edits.
	 *
	 * @param array $proposal The AI proposal payload.
	 * @param int   $post_id  The post being optimized.
	 * @return array The (possibly annotated) proposal.
	 */
	public function filter_aeo_proposal( $proposal, $post_id ): array {
		$proposal = (array) $proposal;
		$loss     = get_post_meta( (int) $post_id, self::META_LOSS, true );

		if ( ! is_array( $loss ) || empty( $loss['prompt'] ) ) {
			return $proposal;
		}

		$proposal['citation_loss']      = $loss;
		$proposal['citation_loss_note'] = sprintf(
			/* translators: 1: prompt text, 2: engine list, 3: domain list */
			__( 'AI engines (%2$s) recently stopped citing this site for the prompt "%1$s". Competing domains being cited: %3$s.', 'stratawp-seo' ),
			(string) $loss['prompt'],
			implode( ', ', (array) ( $loss['engines'] ?? array() ) ),
			implode( ', ', (array) ( $loss['top_cited_domains'] ?? array() ) )
		);

		return $proposal;
	}
}
