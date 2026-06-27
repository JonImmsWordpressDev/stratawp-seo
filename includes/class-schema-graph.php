<?php
/**
 * Schema @graph builder — accumulates nodes and emits a single interlinked
 * JSON-LD @graph block per page request.
 *
 * This class handles the graph assembly and output mechanics. SWPS_Schema is
 * responsible for building nodes and passing them to this builder.
 *
 * Node @id conventions used by this plugin:
 *   Organization  — {home_url}#organization
 *   WebSite       — {home_url}#website
 *   WebPage       — {permalink}#webpage
 *   Article       — {permalink}#article
 *   BreadcrumbList— {permalink}#breadcrumb
 *   Person        — {home_url}#/schema/person/{user_id}
 *   ProfilePage   — {author_url}#profilepage
 *
 * @graph replaces the previous model where each type was its own ld+json block.
 * Third-party code that filtered standalone docs via swps_schema_article /
 * swps_schema_breadcrumb / swps_schema_organization still works: SWPS_Schema
 * applies those filters to a standalone-shaped copy (with @context), then
 * uses the returned data as the node body before adding it to the graph.
 * The only behavioral difference is that the schema now appears inside one
 * @graph block rather than as separate <script> tags. This is documented
 * in the filter hook descriptions in class-hooks.php.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accumulates schema nodes and emits them as a single JSON-LD @graph block.
 */
class SWPS_Schema_Graph {

	/**
	 * Accumulated graph nodes.
	 *
	 * @var array[]
	 */
	private array $nodes = array();

	// -------------------------------------------------------------------------
	// Node accumulation
	// -------------------------------------------------------------------------

	/**
	 * Add a node to the graph.
	 *
	 * If a node with the same @id already exists it is replaced (last-write wins,
	 * so caller order determines precedence).
	 *
	 * Nodes without an '@type' are silently dropped — a legacy filter returning
	 * an empty array or junk must not inject an invalid node into the graph.
	 *
	 * @param array $node Schema node array. Must contain '@type'.
	 */
	public function add_node( array $node ): void {
		if ( empty( $node ) || empty( $node['@type'] ) ) {
			return;
		}

		if ( ! empty( $node['@id'] ) ) {
			// Replace any existing node with the same @id.
			foreach ( $this->nodes as $i => $existing ) {
				if ( isset( $existing['@id'] ) && $existing['@id'] === $node['@id'] ) {
					$this->nodes[ $i ] = $node;
					return;
				}
			}
		}

		$this->nodes[] = $node;
	}

	/**
	 * Return the accumulated nodes (primarily for unit testing).
	 *
	 * @return array[]
	 */
	public function get_nodes(): array {
		return array_values( $this->nodes );
	}

	// -------------------------------------------------------------------------
	// Node normalization helpers
	// -------------------------------------------------------------------------

	/**
	 * Strip @context from a node array, add an @id if the node lacks one.
	 *
	 * Used when absorbing legacy-filtered standalone nodes back into the graph:
	 * the filter may have received a node with @context, and we must strip it
	 * before including it in the @graph envelope.
	 *
	 * @param array  $node        Possibly @context-bearing node.
	 * @param string $fallback_id @id to assign when the node has none.
	 * @return array Normalised node without @context, with @id set.
	 */
	public static function normalize_node( array $node, string $fallback_id ): array {
		unset( $node['@context'] );

		if ( empty( $node['@id'] ) ) {
			$node = array_merge( array( '@id' => $fallback_id ), $node );
		}

		return $node;
	}

	/**
	 * Build the main-entity @id for this site.
	 *
	 * On personal-brand sites (entity type Person) the fragment is #person so the
	 * node's @id and @type stay aligned; otherwise #organization. All publisher /
	 * author references resolve through this method, so they remain consistent.
	 *
	 * @return string Fragment identifier.
	 */
	public static function org_id(): string {
		$type = get_option( 'swps_schema_entity_type', 'Organization' );
		return home_url( 'Person' === $type ? '/#person' : '/#organization' );
	}

	/**
	 * Build the WebSite @id for this site.
	 *
	 * @return string Fragment identifier.
	 */
	public static function website_id(): string {
		return home_url( '/#website' );
	}

	/**
	 * Build the WebPage @id for a URL.
	 *
	 * @param string $url Canonical URL.
	 * @return string Fragment identifier.
	 */
	public static function webpage_id( string $url ): string {
		return trailingslashit( $url ) . '#webpage';
	}

	/**
	 * Build the Article @id for a URL.
	 *
	 * @param string $url Canonical URL.
	 * @return string Fragment identifier.
	 */
	public static function article_id( string $url ): string {
		return trailingslashit( $url ) . '#article';
	}

	/**
	 * Build the BreadcrumbList @id for a URL.
	 *
	 * @param string $url Canonical URL.
	 * @return string Fragment identifier.
	 */
	public static function breadcrumb_id( string $url ): string {
		return trailingslashit( $url ) . '#breadcrumb';
	}

	/**
	 * Build the Person @id for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Fragment identifier.
	 */
	public static function person_id( int $user_id ): string {
		return home_url( '/#/schema/person/' . $user_id );
	}

	/**
	 * Build the ProfilePage @id for an author URL.
	 *
	 * @param string $author_url Author archive URL.
	 * @return string Fragment identifier.
	 */
	public static function profile_page_id( string $author_url ): string {
		return trailingslashit( $author_url ) . '#profilepage';
	}

	// -------------------------------------------------------------------------
	// FAQ / Takeaways normalization helpers (used by SWPS_Schema)
	// -------------------------------------------------------------------------

	/**
	 * Normalize a raw FAQ schema meta value into a safe array.
	 *
	 * Handles both legacy string (possibly a <script> tag) and array formats.
	 * Returns an empty array when the value is unusable.
	 *
	 * @param mixed $schema Raw value from get_post_meta.
	 * @return array Normalised FAQPage array with @context, or empty array.
	 */
	public static function normalize_faq_schema( mixed $schema ): array {
		if ( empty( $schema ) ) {
			return array();
		}

		if ( is_string( $schema ) ) {
			$json = trim( $schema );
			if ( false !== stripos( $json, '<script' ) ) {
				if ( ! preg_match( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $json, $m ) ) {
					return array();
				}
				$json = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
			}
			$schema = json_decode( $json, true );
		}

		if ( ! is_array( $schema ) || 'FAQPage' !== ( $schema['@type'] ?? '' ) ) {
			return array();
		}

		$entities = $schema['mainEntity'] ?? array();
		if ( ! is_array( $entities ) ) {
			return array();
		}

		$normalized = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'name'       => sanitize_text_field( wp_strip_all_tags( (string) ( $schema['name'] ?? get_the_title() ) ) ),
			'mainEntity' => array(),
		);

		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$answer   = $entity['acceptedAnswer'] ?? array();
			$text     = is_array( $answer ) ? ( $answer['text'] ?? '' ) : '';
			$question = sanitize_text_field( wp_strip_all_tags( (string) ( $entity['name'] ?? '' ) ) );
			$text     = sanitize_textarea_field( wp_strip_all_tags( (string) $text ) );

			if ( '' === $question || '' === $text ) {
				continue;
			}

			$normalized['mainEntity'][] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $text,
				),
			);
		}

		return empty( $normalized['mainEntity'] ) ? array() : $normalized;
	}

	// -------------------------------------------------------------------------
	// Output
	// -------------------------------------------------------------------------

	/**
	 * Emit the @graph as a single JSON-LD <script> tag.
	 *
	 * Does nothing if there are no nodes.
	 */
	public function output(): void {
		if ( empty( $this->nodes ) ) {
			return;
		}

		$doc = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $this->nodes ),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD script tag.
		echo '<script type="application/ld+json">'
			. wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
	}
}
