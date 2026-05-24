<?php
/**
 * Authority Scorer — rates content authority signals: byline, freshness,
 * authoritative outbound links, current-year mentions, and "updated" notices.
 *
 * Scoring components (sum to 100):
 *   - Byline present       : 25 pts
 *   - Fresh (1y mod / 2y pub): 25 pts
 *   - Authoritative outbound links per 1k words (cap 1.5): 30 pts
 *   - Current-year mention : 10 pts
 *   - "Updated/reviewed" notice : 10 pts
 *
 * Allowlist sourced from includes/data/authoritative-domains.json.
 * Filterable via the swps_authoritative_domains filter (no-op outside WP).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Authority_Scorer {

	/** @var array{tlds: string[], domains: string[]}|null */
	private ?array $domain_data = null;

	/**
	 * Score the post HTML for Authority signals.
	 *
	 * @param string               $html    Raw post HTML.
	 * @param array<string, mixed> $context [
	 *     'author'         => string,
	 *     'published_unix' => int,
	 *     'modified_unix'  => int,
	 *     'word_count'     => int,
	 * ]
	 * @return int 0-100.
	 */
	public function score( string $html, array $context ): int {
		$byline_present     = ! empty( $context['author'] );
		$fresh              = $this->is_fresh(
			(int) ( $context['published_unix'] ?? 0 ),
			(int) ( $context['modified_unix']  ?? 0 )
		);
		$auth_links         = $this->count_authoritative_links( $html );
		$word_count         = max( 1, (int) ( $context['word_count'] ?? 0 ) );
		$links_per_1k_words = ( $auth_links / $word_count ) * 1000;
		$current_year       = $this->has_current_year_mention( $html );
		$updated_notice     = $this->has_updated_notice( $html );

		$score  = 0;
		$score += $byline_present ? 25 : 0;
		$score += $fresh ? 25 : 0;
		$score += (int) round( min( 1.0, $links_per_1k_words / 1.5 ) * 30 );
		$score += $current_year ? 10 : 0;
		$score += $updated_notice ? 10 : 0;

		return (int) round( max( 0, min( 100, $score ) ) );
	}

	/**
	 * Count outbound links whose host matches the authoritative allowlist (TLD or domain).
	 *
	 * @param string $html Raw post HTML.
	 * @return int
	 */
	public function count_authoritative_links( string $html ): int {
		if ( ! preg_match_all( '#<a[^>]+href=["\']([^"\']+)["\']#i', $html, $matches ) ) {
			return 0;
		}
		$data  = $this->load_domains();
		$count = 0;
		foreach ( $matches[1] as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host ) {
				continue;
			}
			$matched = false;
			foreach ( $data['tlds'] as $tld ) {
				if ( str_ends_with( $host, $tld ) ) {
					++$count;
					$matched = true;
					break;
				}
			}
			if ( $matched ) {
				continue;
			}
			foreach ( $data['domains'] as $domain ) {
				if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
					++$count;
					break;
				}
			}
		}
		return $count;
	}

	/**
	 * Is the post fresh? Modified in last 12 months OR published in last 24 months.
	 *
	 * @param int $published UNIX timestamp.
	 * @param int $modified  UNIX timestamp.
	 * @return bool
	 */
	private function is_fresh( int $published, int $modified ): bool {
		$now = time();
		if ( $modified > 0 && ( $now - $modified ) < ( 365 * 86400 ) ) {
			return true;
		}
		if ( $published > 0 && ( $now - $published ) < ( 730 * 86400 ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Does the text mention the current year?
	 *
	 * @param string $text Plain text or HTML.
	 * @return bool
	 */
	public function has_current_year_mention( string $text ): bool {
		$year = (int) gmdate( 'Y' );
		return (bool) preg_match( '/\b' . $year . '\b/', $text );
	}

	/**
	 * Does the post have an "updated:" / "last reviewed:" notice?
	 *
	 * @param string $html Raw post HTML.
	 * @return bool
	 */
	public function has_updated_notice( string $html ): bool {
		return (bool) preg_match( '/\b(updated|last reviewed|last updated|revised)\s*[:\-]/i', wp_strip_all_tags( $html ) );
	}

	/**
	 * Load the authoritative domain + TLD allowlist (lazy, cached, filterable).
	 *
	 * @return array{tlds: string[], domains: string[]}
	 */
	private function load_domains(): array {
		if ( null !== $this->domain_data ) {
			return $this->domain_data;
		}
		$path = defined( 'SWPS_PLUGIN_DIR' )
			? SWPS_PLUGIN_DIR . 'includes/data/authoritative-domains.json'
			: __DIR__ . '/../data/authoritative-domains.json';

		$raw     = is_readable( $path ) ? (string) file_get_contents( $path ) : '{}';
		$decoded = json_decode( $raw, true );
		$tlds    = ( is_array( $decoded ) && isset( $decoded['tlds'] )    && is_array( $decoded['tlds'] ) )    ? $decoded['tlds']    : array();
		$domains = ( is_array( $decoded ) && isset( $decoded['domains'] ) && is_array( $decoded['domains'] ) ) ? $decoded['domains'] : array();

		if ( function_exists( 'apply_filters' ) ) {
			$domains = (array) apply_filters( 'swps_authoritative_domains', $domains );
		}

		$this->domain_data = array(
			'tlds'    => array_map( 'strtolower', $tlds ),
			'domains' => array_map( 'strtolower', $domains ),
		);
		return $this->domain_data;
	}
}
