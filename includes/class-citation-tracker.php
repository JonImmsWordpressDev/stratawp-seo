<?php
/**
 * Citation tracker — storage, cron runner, and pure static helpers.
 *
 * This file ships the pure static helpers only (Task 1). Table creation,
 * cron runner, admin CRUD, and AEO hook are wired in Task 2 once the first
 * call site exists (no require_once in stratawp-seo.php yet).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages AI citation tracking: storage, cron runner, and pure static helpers.
 *
 * @package StrataWP_SEO
 */
class SWPS_Citation_Tracker {

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
}
