<?php
/**
 * Schema validation helpers — pure static, no WordPress dependency.
 *
 * Provides utilities for extracting JSON-LD from HTML, validating schema nodes
 * against rule manifests, finding orphaned @id references, and checking
 * dateModified consistency against a post's modified timestamp.
 *
 * Used by SWPS_Schema_Audit_Module and available to tests without loading WP.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure-static schema extraction and validation helpers.
 */
class SWPS_Schema_Validator {

	/**
	 * Maximum drift (in seconds) between a node's dateModified and the post's
	 * post_modified_gmt before a consistency warning is raised.
	 */
	public const DATE_DRIFT_THRESHOLD = DAY_IN_SECONDS;

	// -------------------------------------------------------------------------
	// Extraction
	// -------------------------------------------------------------------------

	/**
	 * Extract all JSON-LD schema blocks from an HTML string.
	 *
	 * Handles:
	 * - Individual top-level schema objects  {"@type": "Article", ...}
	 * - @graph containers                    {"@context": "...", "@graph": [...]}
	 * - Malformed JSON (returns partial results from valid blocks, skips bad ones)
	 *
	 * @param string $html Raw HTML page source.
	 * @return array[] Flat array of decoded schema arrays (one per valid ld+json block).
	 */
	public static function extract_jsonld( string $html ): array {
		$results = array();

		// Grab every <script type="application/ld+json"> block.
		$pattern = '/<script[^>]+type\s*=\s*["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is';
		if ( ! preg_match_all( $pattern, $html, $matches ) ) {
			return $results;
		}

		foreach ( $matches[1] as $raw ) {
			$raw = trim( $raw );
			if ( '' === $raw ) {
				continue;
			}

			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				// Skip unparseable blocks rather than fataling.
				continue;
			}

			$results[] = $decoded;
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Node validation
	// -------------------------------------------------------------------------

	/**
	 * Validate a single schema node against a required-fields rule set.
	 *
	 * Returns an array of missing property names (empty = node passes).
	 * Ignores properties whose value is an empty string or null — those count as
	 * absent even if the key exists, because Google rejects empty values.
	 *
	 * @param array    $node  Decoded schema node.
	 * @param string[] $rules Flat array of required property names.
	 * @return string[] Names of required properties that are missing or empty.
	 */
	public static function validate_node( array $node, array $rules ): array {
		$missing = array();

		foreach ( $rules as $prop ) {
			if ( ! array_key_exists( $prop, $node ) ) {
				$missing[] = $prop;
				continue;
			}
			$value = $node[ $prop ];
			// Treat empty string, null, or empty array as absent.
			if ( null === $value || '' === $value || array() === $value ) {
				$missing[] = $prop;
			}
		}

		return $missing;
	}

	// -------------------------------------------------------------------------
	// @id orphan detection
	// -------------------------------------------------------------------------

	/**
	 * Find @id values that are referenced but never defined in the node set.
	 *
	 * Accepts either:
	 * - A flat array of nodes (e.g. extracted from a standalone block)
	 * - An array with a '@graph' key containing the node list
	 *
	 * Collects every @id string defined at the top level of any node, then
	 * searches recursively for any string value matching "#/" that does not
	 * appear in the defined-ids set.
	 *
	 * @param array $graph_or_nodes Decoded schema array or @graph wrapper.
	 * @return string[] Orphaned @id reference strings.
	 */
	public static function find_orphan_refs( array $graph_or_nodes ): array {
		// Normalise to a flat node list.
		if ( isset( $graph_or_nodes['@graph'] ) && is_array( $graph_or_nodes['@graph'] ) ) {
			$nodes = $graph_or_nodes['@graph'];
		} elseif ( isset( $graph_or_nodes['@type'] ) ) {
			// Single node passed directly.
			$nodes = array( $graph_or_nodes );
		} else {
			$nodes = array_values( $graph_or_nodes );
		}

		// Collect all defined @id values.
		$defined = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && isset( $node['@id'] ) && is_string( $node['@id'] ) ) {
				$defined[ $node['@id'] ] = true;
			}
		}

		// Collect all referenced @id strings (values at any depth that look like
		// fragment identifiers — start with # or are a full @id reference).
		$referenced = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) ) {
				self::collect_id_refs( $node, $referenced );
			}
		}

		// References that don't exist in the defined set.
		$orphans = array();
		foreach ( array_keys( $referenced ) as $ref ) {
			if ( ! isset( $defined[ $ref ] ) ) {
				$orphans[] = $ref;
			}
		}

		return array_values( array_unique( $orphans ) );
	}

	/**
	 * Recursively collect all @id reference strings from nested arrays.
	 *
	 * Looks for arrays that contain only '@id' (reference-style nodes), e.g.
	 * {"@id": "#/schema/organization"}, and collects the @id value.
	 *
	 * @param array $node        Node to traverse.
	 * @param array &$referenced Accumulator map of ref => true.
	 */
	private static function collect_id_refs( array $node, array &$referenced ): void {
		foreach ( $node as $key => $value ) {
			if ( '@id' === $key ) {
				// Skip — this is the node's own identity, not a reference.
				continue;
			}
			if ( is_array( $value ) ) {
				// A reference-shape sub-object: {"@id": "..."}.
				if ( array_keys( $value ) === array( '@id' ) && is_string( $value['@id'] ) ) {
					$referenced[ $value['@id'] ] = true;
				} else {
					self::collect_id_refs( $value, $referenced );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Date consistency
	// -------------------------------------------------------------------------

	/**
	 * Check whether a schema node's dateModified is consistent with the post's
	 * actual post_modified_gmt timestamp.
	 *
	 * Returns a human-readable warning string when the drift exceeds
	 * DATE_DRIFT_THRESHOLD, or null when all is well (or when the node has no
	 * dateModified property).
	 *
	 * @param array $node           Decoded schema node.
	 * @param int   $post_modified  Unix timestamp from post_modified_gmt.
	 * @return string|null Warning message or null.
	 */
	public static function check_date_consistency( array $node, int $post_modified ): ?string {
		if ( empty( $node['dateModified'] ) ) {
			return null;
		}

		$node_ts = strtotime( (string) $node['dateModified'] );
		if ( false === $node_ts ) {
			return sprintf(
				'dateModified value "%s" could not be parsed as a date.',
				htmlspecialchars( (string) $node['dateModified'], ENT_QUOTES, 'UTF-8' )
			);
		}

		$drift = abs( $node_ts - $post_modified );
		if ( $drift > self::DATE_DRIFT_THRESHOLD ) {
			return sprintf(
				'dateModified "%s" differs from post_modified_gmt by %d day(s).',
				htmlspecialchars( (string) $node['dateModified'], ENT_QUOTES, 'UTF-8' ),
				(int) floor( $drift / DAY_IN_SECONDS )
			);
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Manifest loader
	// -------------------------------------------------------------------------

	/**
	 * Load and return the schema-rules manifest.
	 *
	 * Returns a map of @type => { required: [], recommended: [] }.
	 *
	 * @return array Manifest array, or empty array on failure.
	 */
	public static function load_rules_manifest(): array {
		$path = dirname( __DIR__ ) . '/includes/data/schema-rules.json';

		if ( ! is_readable( $path ) ) {
			return array();
		}

		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $json ) {
			return array();
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}
}
