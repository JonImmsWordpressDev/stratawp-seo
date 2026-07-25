<?php
/**
 * Cross-site link candidates from owned/partner domains.
 *
 * Maintains the swps_owned_domains setting, fetches each owned domain's
 * public post inventory over the WP REST API (cached daily), scores that
 * inventory against a post's indexed terms, and tracks per-post cross-site
 * link state (existing / dismissed / inserted / AI enrichment) in post meta.
 *
 * Cross-site targets have no local post ID, so they deliberately never
 * enter the swps_link_graph table.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owned/partner-domain link candidates for the internal-link engines.
 */
class SWPS_Cross_Site_Links {

	private const INVENTORY_TRANSIENT_PREFIX = 'swps_cross_site_inv_';
	private const FETCH_TIMEOUT              = 10;

	// Mirrors SWPS_Link_Keyword_Engine::WEIGHTS for title/body.
	private const TITLE_WEIGHT   = 1.0;
	private const EXCERPT_WEIGHT = 0.3;

	private const META_EXISTING  = '_swps_cross_site_existing';
	private const META_DISMISSED = '_swps_cross_site_dismissed';
	private const META_INSERTED  = '_swps_cross_site_inserted';
	private const META_AI        = '_swps_cross_site_ai';

	// -------------------------------------------------------------------------
	// Pure helpers (unit tested without WordPress)
	// -------------------------------------------------------------------------

	/**
	 * Normalize a user-entered domain to an origin (scheme://host[:port]).
	 *
	 * Bare hosts get https:// prepended; paths, queries, and fragments are
	 * stripped; only http/https survive.
	 *
	 * @param string $input Raw entry, e.g. "jonimms.com" or "https://x.com/p/".
	 * @return string|null Normalized origin, or null when invalid.
	 */
	public static function normalize_origin( string $input ): ?string {
		$input = trim( $input );
		if ( '' === $input ) {
			return null;
		}

		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $input ) ) {
			$input = 'https://' . $input;
		}

		$parts = wp_parse_url( $input );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		$host = strtolower( $parts['host'] );
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) ) {
			return null;
		}

		$origin = $scheme . '://' . $host;
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		return $origin;
	}

	/**
	 * Sanitize the swps_owned_domains option value.
	 *
	 * Accepts the settings textarea (one domain per line) or an array of
	 * entries; returns a deduped list of normalized origins.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<int, string> Normalized origins.
	 */
	public static function sanitize_owned_domains( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$origins = array();
		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$origin = self::normalize_origin( $entry );
			if ( null !== $origin && ! in_array( $origin, $origins, true ) ) {
				$origins[] = $origin;
			}
		}

		return $origins;
	}

	/**
	 * Whether a URL points at one of the given origins.
	 *
	 * Host comparison is scheme-insensitive and ignores a leading "www.",
	 * but requires an exact host (no subdomain match) and a matching port.
	 *
	 * @param string $url     URL to test (absolute or protocol-relative).
	 * @param array  $origins Normalized origins.
	 * @return bool True when the URL's host belongs to an origin.
	 */
	public static function url_matches_domains( string $url, array $origins ): bool {
		if ( empty( $origins ) ) {
			return false;
		}

		$needle = self::host_key( $url );
		if ( null === $needle ) {
			return false;
		}

		foreach ( $origins as $origin ) {
			if ( is_string( $origin ) && self::host_key( $origin ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse a WP REST /wp/v2/posts response body into inventory items.
	 *
	 * @param string $body   Raw JSON response body.
	 * @param string $origin Origin the response came from.
	 * @return array<int, array{url: string, title: string, excerpt: string, domain: string}>
	 */
	public static function parse_inventory_response( string $body, string $origin ): array {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$items = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) || empty( $row['link'] ) || ! is_string( $row['link'] ) ) {
				continue;
			}
			$title   = isset( $row['title']['rendered'] ) ? (string) $row['title']['rendered'] : '';
			$excerpt = isset( $row['excerpt']['rendered'] ) ? (string) $row['excerpt']['rendered'] : '';

			$items[] = array(
				'url'     => $row['link'],
				'title'   => html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'excerpt' => html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'domain'  => $origin,
			);
		}

		return $items;
	}

	/**
	 * Score inventory items against a source post's weighted terms.
	 *
	 * Mirrors SWPS_Link_Keyword_Engine::find_related(): source term weight
	 * times target weight (title 1.0, excerpt 0.3), normalized against the
	 * best raw score, thresholded, ordered by score descending.
	 *
	 * @param array    $term_weights Source terms mapped to weights.
	 * @param array    $inventory    Items from parse_inventory_response().
	 * @param callable $tokenize     Tokenizer (SWPS_Link_Keyword_Engine::tokenize_public).
	 * @param float    $threshold    Minimum normalized score.
	 * @param int      $limit        Maximum results.
	 * @param array    $exclude_urls URLs to skip (already linked / dismissed).
	 * @return array<int, array> Scored candidates, each with cross_site => true.
	 */
	public static function score_candidates( array $term_weights, array $inventory, callable $tokenize, float $threshold = 0.3, int $limit = 10, array $exclude_urls = array() ): array {
		if ( empty( $term_weights ) || empty( $inventory ) ) {
			return array();
		}

		$scored = array();
		foreach ( $inventory as $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) || in_array( $item['url'], $exclude_urls, true ) ) {
				continue;
			}

			$title_terms   = (array) call_user_func( $tokenize, (string) ( $item['title'] ?? '' ) );
			$excerpt_terms = (array) call_user_func( $tokenize, (string) ( $item['excerpt'] ?? '' ) );

			$score = 0.0;
			foreach ( $term_weights as $term => $weight ) {
				if ( in_array( (string) $term, $title_terms, true ) ) {
					$score += (float) $weight * self::TITLE_WEIGHT;
				} elseif ( in_array( (string) $term, $excerpt_terms, true ) ) {
					$score += (float) $weight * self::EXCERPT_WEIGHT;
				}
			}

			if ( $score <= 0 ) {
				continue;
			}

			$scored[] = array(
				'url'        => (string) $item['url'],
				'title'      => (string) ( $item['title'] ?? '' ),
				'excerpt'    => (string) ( $item['excerpt'] ?? '' ),
				'domain'     => (string) ( $item['domain'] ?? '' ),
				'score'      => $score,
				'cross_site' => true,
			);
		}

		if ( empty( $scored ) ) {
			return array();
		}

		$max     = max( array_column( $scored, 'score' ) );
		$results = array();
		foreach ( $scored as $item ) {
			$item['score'] = $max > 0 ? round( $item['score'] / $max, 3 ) : 0.0;
			if ( $item['score'] >= $threshold ) {
				$results[] = $item;
			}
		}

		usort( $results, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Comparable host key for a URL: lowercased host without a leading
	 * "www.", plus the explicit port when present.
	 *
	 * @param string $url URL or origin.
	 * @return string|null Host key, or null when the URL has no host.
	 */
	private static function host_key( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) || ! is_string( $host ) ) {
			return null;
		}

		$host = strtolower( $host );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		$port = wp_parse_url( $url, PHP_URL_PORT );

		return $host . ( $port ? ':' . (int) $port : '' );
	}

	// -------------------------------------------------------------------------
	// WordPress-backed API
	// -------------------------------------------------------------------------

	/**
	 * Register option-change hooks (inventory cache flush).
	 */
	public function register_hooks(): void {
		add_action( 'update_option_swps_owned_domains', array( $this, 'on_domains_updated' ), 10, 2 );
	}

	/**
	 * Configured owned domains, excluding this site's own host.
	 *
	 * @return array<int, string> Normalized origins.
	 */
	public function get_owned_domains(): array {
		$origins = self::sanitize_owned_domains( get_option( 'swps_owned_domains', array() ) );

		return array_values(
			array_filter(
				$origins,
				static function ( string $origin ): bool {
					return ! self::url_matches_domains( $origin, array( home_url() ) );
				}
			)
		);
	}

	/**
	 * Whether cross-site linking is active (at least one owned domain).
	 */
	public function is_active(): bool {
		return array() !== $this->get_owned_domains();
	}

	/**
	 * Whether a URL belongs to a configured owned domain.
	 *
	 * @param string $url URL to test.
	 */
	public function is_owned_url( string $url ): bool {
		return self::url_matches_domains( $url, $this->get_owned_domains() );
	}

	/**
	 * Merged post inventory across all owned domains.
	 *
	 * @return array<int, array> Inventory items.
	 */
	public function get_inventory(): array {
		$inventory = array();
		foreach ( $this->get_owned_domains() as $origin ) {
			$inventory = array_merge( $inventory, $this->get_domain_inventory( $origin ) );
		}

		return $inventory;
	}

	/**
	 * Post inventory for one owned domain, cached in a transient.
	 *
	 * Successful fetches cache for a day; failures cache an empty list for
	 * an hour so an unreachable site isn't hammered on every editor load.
	 *
	 * @param string $origin Normalized origin.
	 * @return array<int, array> Inventory items.
	 */
	public function get_domain_inventory( string $origin ): array {
		$key    = self::INVENTORY_TRANSIENT_PREFIX . md5( $origin );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			$origin . '/wp-json/wp/v2/posts?per_page=100&_fields=title,link,excerpt',
			array( 'timeout' => self::FETCH_TIMEOUT )
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$items = self::parse_inventory_response( (string) wp_remote_retrieve_body( $response ), $origin );
		set_transient( $key, $items, DAY_IN_SECONDS );

		return $items;
	}

	/**
	 * Cross-site link suggestions for a post.
	 *
	 * Scores the cached inventory against the post's indexed terms and
	 * overlays any stored AI enrichment. URLs already linked, dismissed,
	 * or inserted are excluded.
	 *
	 * @param int   $post_id   Source post ID.
	 * @param float $threshold Minimum normalized score.
	 * @param int   $limit     Maximum suggestions.
	 * @return array<int, array> Suggestions.
	 */
	public function find_candidates( int $post_id, float $threshold = 0.3, int $limit = 5 ): array {
		$inventory = $this->get_inventory();
		if ( empty( $inventory ) ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'swps_link_index';

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT term, weight FROM {$table} WHERE post_id = %d", $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return array();
		}

		$term_weights = array();
		foreach ( $rows as $row ) {
			$term_weights[ $row['term'] ] = (float) $row['weight'];
		}

		$exclude = array_merge(
			array_column( $this->get_existing_links( $post_id ), 'url' ),
			$this->get_url_list( $post_id, self::META_DISMISSED ),
			$this->get_url_list( $post_id, self::META_INSERTED )
		);

		$engine     = new SWPS_Link_Keyword_Engine();
		$candidates = self::score_candidates(
			$term_weights,
			$inventory,
			array( $engine, 'tokenize_public' ),
			$threshold,
			$limit,
			$exclude
		);

		$enrichment = $this->get_ai_enrichment( $post_id );
		foreach ( $candidates as &$candidate ) {
			if ( isset( $enrichment[ $candidate['url'] ] ) ) {
				$candidate['score']       = (float) $enrichment[ $candidate['url'] ]['relevance_score'];
				$candidate['anchor_text'] = (string) $enrichment[ $candidate['url'] ]['anchor_text'];
				$candidate['rationale']   = (string) $enrichment[ $candidate['url'] ]['rationale'];
				$candidate['ai_enriched'] = true;
			}
		}
		unset( $candidate );

		return $candidates;
	}

	// -------------------------------------------------------------------------
	// Per-post cross-site state (post meta)
	// -------------------------------------------------------------------------

	/**
	 * Existing cross-site links detected in the post content.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array{url: string, anchor: string}>
	 */
	public function get_existing_links( int $post_id ): array {
		$value = get_post_meta( $post_id, self::META_EXISTING, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Replace the recorded existing cross-site links for a post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $links   Array of ['url' => ..., 'anchor' => ...].
	 */
	public function set_existing_links( int $post_id, array $links ): void {
		if ( empty( $links ) ) {
			delete_post_meta( $post_id, self::META_EXISTING );
			return;
		}
		update_post_meta( $post_id, self::META_EXISTING, array_values( $links ) );
	}

	/**
	 * Mark a cross-site suggestion as dismissed for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Suggestion URL.
	 */
	public function dismiss_url( int $post_id, string $url ): void {
		$this->add_url_to_list( $post_id, self::META_DISMISSED, $url );
	}

	/**
	 * Mark a cross-site suggestion as inserted for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Suggestion URL.
	 */
	public function mark_inserted( int $post_id, string $url ): void {
		$this->add_url_to_list( $post_id, self::META_INSERTED, $url );
	}

	/**
	 * Stored AI enrichment for a post's cross-site suggestions.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array> Map of URL => enrichment.
	 */
	public function get_ai_enrichment( int $post_id ): array {
		$value = get_post_meta( $post_id, self::META_AI, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Merge AI enrichment results (keyed by URL) into the stored map.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $items   Enriched cross-site items from the AI engine.
	 */
	public function store_ai_enrichment( int $post_id, array $items ): void {
		$map = $this->get_ai_enrichment( $post_id );
		foreach ( $items as $item ) {
			if ( empty( $item['url'] ) ) {
				continue;
			}
			$map[ (string) $item['url'] ] = array(
				'relevance_score' => (float) ( $item['relevance_score'] ?? 0 ),
				'anchor_text'     => (string) ( $item['anchor_text'] ?? '' ),
				'rationale'       => (string) ( $item['rationale'] ?? '' ),
			);
		}
		update_post_meta( $post_id, self::META_AI, $map );
	}

	/**
	 * Flush cached inventories when the owned-domains option changes.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $value     New option value.
	 */
	public function on_domains_updated( $old_value, $value ): void {
		$origins = array_unique(
			array_merge(
				self::sanitize_owned_domains( $old_value ),
				self::sanitize_owned_domains( $value )
			)
		);
		foreach ( $origins as $origin ) {
			delete_transient( self::INVENTORY_TRANSIENT_PREFIX . md5( $origin ) );
		}
	}

	/**
	 * Read a URL-list post meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @return array<int, string>
	 */
	private function get_url_list( int $post_id, string $meta_key ): array {
		$value = get_post_meta( $post_id, $meta_key, true );
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Append a URL to a URL-list post meta value (deduped).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param string $url     URL to add.
	 */
	private function add_url_to_list( int $post_id, string $meta_key, string $url ): void {
		$list = $this->get_url_list( $post_id, $meta_key );
		if ( ! in_array( $url, $list, true ) ) {
			$list[] = $url;
			update_post_meta( $post_id, $meta_key, $list );
		}
	}
}
