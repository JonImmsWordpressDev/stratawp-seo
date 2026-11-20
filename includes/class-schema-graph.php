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
	 * Build the LocalBusiness @id for this site.
	 *
	 * @return string Fragment identifier.
	 */
	public static function local_business_id(): string {
		return home_url( '/#localbusiness' );
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

	/**
	 * Build the descriptive properties for a personal-brand Person entity.
	 *
	 * Pure helper: takes the raw option values and the Local SEO settings array
	 * and returns only the properties that have a usable value, so the caller
	 * can array_merge() the result straight into the #person node. Nothing here
	 * touches WordPress, which keeps it unit-testable.
	 *
	 * @param array  $opts        {
	 *     Person option values (all optional, all strings).
	 *
	 *     @type string $job_title   Role or title, e.g. "Senior WordPress Developer".
	 *     @type string $description One- or two-sentence bio. Whitespace is collapsed.
	 *     @type string $image       Absolute URL of a headshot.
	 *     @type string $knows_about Topics, one per line or comma-separated.
	 *     @type string $email       Contact email; falls back to the Local SEO email.
	 * }
	 * @param array  $local       The swps_local_seo settings array (enabled, email,
	 *                            locality, region, country). Only locality, region
	 *                            and country are used for the address — never the
	 *                            street or postal code, which would publish a home
	 *                            address for a person.
	 * @param string $business_id @id of the LocalBusiness node to reference via
	 *                            worksFor, or '' when no such node is emitted.
	 * @return array<string,mixed> Schema.org Person properties, possibly empty.
	 */
	public static function person_details( array $opts, array $local = array(), string $business_id = '' ): array {
		$out = array();

		$job_title = trim( (string) ( $opts['job_title'] ?? '' ) );
		if ( '' !== $job_title ) {
			$out['jobTitle'] = $job_title;
		}

		$description = trim( (string) preg_replace( '/\s+/', ' ', (string) ( $opts['description'] ?? '' ) ) );
		if ( '' !== $description ) {
			$out['description'] = $description;
		}

		$image = trim( (string) ( $opts['image'] ?? '' ) );
		if ( '' !== $image && false !== filter_var( $image, FILTER_VALIDATE_URL ) ) {
			$out['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $image,
			);
		}

		$topics = preg_split( '/[\r\n,]+/', (string) ( $opts['knows_about'] ?? '' ) );
		if ( ! is_array( $topics ) ) {
			$topics = array();
		}
		$topics = array_values(
			array_unique(
				array_filter(
					array_map( 'trim', $topics ),
					static fn( string $topic ): bool => '' !== $topic
				)
			)
		);
		if ( ! empty( $topics ) ) {
			$out['knowsAbout'] = $topics;
		}

		$local_enabled = ! empty( $local['enabled'] );

		$email = trim( (string) ( $opts['email'] ?? '' ) );
		if ( '' === $email && $local_enabled ) {
			$email = trim( (string) ( $local['email'] ?? '' ) );
		}
		if ( '' !== $email && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$out['email'] = $email;
		}

		if ( $local_enabled ) {
			$address     = array();
			$address_map = array(
				'locality' => 'addressLocality',
				'region'   => 'addressRegion',
				'country'  => 'addressCountry',
			);
			foreach ( $address_map as $key => $prop ) {
				$value = trim( (string) ( $local[ $key ] ?? '' ) );
				if ( '' !== $value ) {
					$address[ $prop ] = $value;
				}
			}
			if ( ! empty( $address ) ) {
				$out['address'] = array_merge( array( '@type' => 'PostalAddress' ), $address );
			}

			if ( '' !== $business_id ) {
				$out['worksFor'] = array( '@id' => $business_id );
			}
		}

		return $out;
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

	/**
	 * Keep only the FAQPage questions whose text appears on the page.
	 *
	 * Google requires FAQ structured data to mirror content the visitor can
	 * see. Older AEO runs stored single-question FAQPage blobs (a summary
	 * question that is not on the page), which validators flag as markup
	 * that does not match visible content. Matching is case-, punctuation-
	 * and whitespace-insensitive. Non-FAQPage schema is returned unchanged.
	 *
	 * @param array  $schema       Decoded schema array.
	 * @param string $visible_text Page text (HTML allowed; tags are stripped).
	 * @return array Schema with unmatched questions removed, or an empty
	 *               array when no question is visible.
	 */
	public static function filter_faq_to_visible( array $schema, string $visible_text ): array {
		if ( 'FAQPage' !== ( $schema['@type'] ?? '' ) ) {
			return $schema;
		}

		$haystack = self::match_text( $visible_text );
		$entities = $schema['mainEntity'] ?? array();
		if ( isset( $entities['@type'] ) ) {
			$entities = array( $entities );
		}

		$kept = array();
		foreach ( (array) $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$needle = self::match_text( (string) ( $entity['name'] ?? '' ) );
			if ( '' !== $needle && false !== strpos( $haystack, $needle ) ) {
				$kept[] = $entity;
			}
		}

		if ( empty( $kept ) ) {
			return array();
		}

		$schema['mainEntity'] = $kept;
		return $schema;
	}

	/**
	 * Lower-case, tag-free, punctuation-free text with single spaces, for
	 * loose "does this phrase appear" comparisons.
	 *
	 * @param string $text Raw text or HTML.
	 * @return string
	 */
	private static function match_text( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strtolower( $text );
		$text = (string) preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Format a stored latitude or longitude for JSON-LD.
	 *
	 * Casting to float lets hosts with serialize_precision = 17 print values
	 * like 41.256500000000002614. Returning a short decimal string avoids that
	 * (schema.org accepts Number or Text). Values are rounded to 6 places,
	 * about 0.1 m, which is more precision than any business address needs.
	 *
	 * @param mixed $value Stored coordinate.
	 * @return string Formatted coordinate, or '' when not a valid number.
	 */
	public static function format_coordinate( mixed $value ): string {
		if ( ! is_numeric( $value ) ) {
			return '';
		}
		$formatted = rtrim( rtrim( sprintf( '%.6F', round( (float) $value, 6 ) ), '0' ), '.' );
		return '-0' === $formatted ? '0' : $formatted;
	}

	/**
	 * Format a phone number for schema.org telephone.
	 *
	 * Google recommends including the country code. Numbers already starting
	 * with "+" are kept as entered. A 10-digit number (or 11 digits starting
	 * with 1) in a NANP country (US, CA) becomes +1-XXX-XXX-XXXX. Anything
	 * else is returned trimmed, unchanged, because guessing another country's
	 * format would be worse than leaving it alone.
	 *
	 * @param string $phone   Stored phone number.
	 * @param string $country Business country (ISO code or name).
	 * @return string
	 */
	public static function format_telephone( string $phone, string $country = '' ): string {
		$phone = trim( $phone );
		if ( '' === $phone || str_starts_with( $phone, '+' ) ) {
			return $phone;
		}

		$nanp = in_array( strtoupper( trim( $country ) ), array( '', 'US', 'USA', 'UNITED STATES', 'CA', 'CAN', 'CANADA' ), true );
		if ( ! $nanp ) {
			return $phone;
		}

		$digits = (string) preg_replace( '/\D+/', '', $phone );
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		if ( 10 !== strlen( $digits ) ) {
			return $phone;
		}

		return sprintf( '+1-%s-%s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6 ) );
	}

	/**
	 * Turn the site description setting into a schema.org description.
	 *
	 * Plain text with collapsed whitespace. When longer than $max characters it
	 * is cut at the last full sentence that fits (or at a word if no sentence
	 * fits), so the business node never carries a sentence fragment.
	 *
	 * @param string $text Raw site description (may contain HTML).
	 * @param int    $max  Maximum length in characters.
	 * @return string Description, or '' when empty.
	 */
	public static function business_description( string $text, int $max = 300 ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text || mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max );
		if ( preg_match( '/^(.*[.!?])\s/us', $cut . ' ', $m ) && mb_strlen( $m[1] ) >= 40 ) {
			return $m[1];
		}
		$space = mb_strrpos( $cut, ' ' );
		return rtrim( false === $space ? $cut : mb_substr( $cut, 0, $space ), ' ,;:' ) . '…';
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
