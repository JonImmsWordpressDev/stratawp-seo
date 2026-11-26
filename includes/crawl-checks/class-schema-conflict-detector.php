<?php
/**
 * Finds conflicts between the JSON-LD blocks on one page.
 *
 * Pure static, no WordPress dependency. Sites often run more than one plugin
 * or theme that emits JSON-LD, so this looks at every block on the page,
 * whoever emitted it, and reports where the blocks disagree.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cross-block JSON-LD conflict detection for the schema_conflict crawl check.
 */
class SWPS_Schema_Conflict_Detector {

	/** Blocks read per page; anything past this is ignored. */
	public const MAX_BLOCKS = 50;

	/** Characters of a block kept as its snippet. */
	public const SNIPPET_LENGTH = 120;

	/**
	 * Schema.org Organization subtypes (and common LocalBusiness subtypes)
	 * treated as "an Organization node". Types ending in Organization, Business,
	 * Store or Service are matched by suffix as well.
	 */
	private const ORG_TYPES = array(
		'Organization',
		'Corporation',
		'LocalBusiness',
		'NGO',
		'Airline',
		'Consortium',
		'Cooperative',
		'FundingScheme',
		'LibrarySystem',
		'OnlineBusiness',
		'OnlineStore',
		'PerformingGroup',
		'Project',
		'WorkersUnion',
		'Restaurant',
		'CafeOrCoffeeShop',
		'Bakery',
		'Dentist',
		'MedicalClinic',
		'Physician',
		'BeautySalon',
		'HairSalon',
		'DaySpa',
		'AutoRepair',
		'Plumber',
		'Electrician',
		'RoofingContractor',
		'GeneralContractor',
		'RealEstateAgent',
		'Attorney',
		'InsuranceAgency',
		'TravelAgency',
		'Hotel',
		'GymLocation',
		'SportsActivityLocation',
		'ChildCare',
	);

	/**
	 * Properties that link a branch to its parent organization. Organization
	 * nodes joined this way are a hierarchy, not duplicates.
	 */
	private const ORG_LINK_PROPS = array( 'parentOrganization', 'subOrganization', 'branchOf', 'department', 'memberOf', 'member' );

	/**
	 * Analyze the raw JSON-LD blocks of one page.
	 *
	 * @param string[] $blocks   Raw text of each application/ld+json script, in page order.
	 * @param string   $page_url URL of the page, used to tell same-site @id references from external IRIs.
	 * @return array{conflicts:array[],invalid_blocks:array[]} Conflicts found and blocks that failed to parse.
	 */
	public static function analyze( array $blocks, string $page_url = '' ): array {
		$invalid  = array();
		$top      = array();
		$defs     = array();
		$refs     = array();
		$pubs     = array();
		$page_url = strtolower( $page_url );

		foreach ( array_slice( array_values( $blocks ), 0, self::MAX_BLOCKS ) as $i => $raw ) {
			$raw   = trim( (string) $raw );
			$index = $i + 1;
			if ( '' === $raw ) {
				continue;
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				$invalid[] = self::origin( $index, $raw );
				continue;
			}
			$origin = self::origin( $index, $raw );

			foreach ( self::top_level_nodes( $decoded ) as $node ) {
				$top[] = array(
					'node'   => $node,
					'origin' => $origin,
				);
			}
			self::walk( $decoded, $origin, $defs, $refs, $pubs );
		}

		$conflicts = array_merge(
			self::duplicate_entities( $top, 'organization' ),
			self::duplicate_entities( $top, 'website' ),
			self::conflicting_types( $defs ),
			self::dangling_refs( $refs, $defs, $page_url ),
			self::mixed_publishers( $pubs, $defs )
		);

		return array(
			'conflicts'      => $conflicts,
			'invalid_blocks' => $invalid,
		);
	}

	/**
	 * One-line, human-readable summary of an analyze() result.
	 *
	 * @param array $result Result of analyze().
	 * @return string Summary, empty when there is nothing to report.
	 */
	public static function summarize( array $result ): string {
		$parts = array();
		foreach ( $result['conflicts'] as $conflict ) {
			$blocks = array_unique( array_column( $conflict['blocks'], 'block' ) );
			sort( $blocks );
			$parts[] = $conflict['message'] . ' (block ' . implode( ', ', $blocks ) . ')';
		}
		$bad = count( $result['invalid_blocks'] );
		if ( $bad > 0 ) {
			$parts[] = sprintf(
				'%d JSON-LD block(s) failed to parse (block %s)',
				$bad,
				implode( ', ', array_column( $result['invalid_blocks'], 'block' ) )
			);
		}
		return implode( '; ', $parts );
	}

	/**
	 * Block number => snippet for every block named in an issue detail, so
	 * the user can find which plugin emitted it.
	 *
	 * @param array $detail Issue detail with conflicts and invalid_blocks.
	 * @return array<int,string> Sorted by block number.
	 */
	public static function block_snippets( array $detail ): array {
		$origins = (array) ( $detail['invalid_blocks'] ?? array() );
		foreach ( (array) ( $detail['conflicts'] ?? array() ) as $conflict ) {
			$origins = array_merge( $origins, (array) ( $conflict['blocks'] ?? array() ) );
		}
		$out = array();
		foreach ( $origins as $origin ) {
			if ( is_array( $origin ) && isset( $origin['block'] ) ) {
				$out[ (int) $origin['block'] ] = (string) ( $origin['snippet'] ?? '' );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Whether a node's @type marks it as an Organization (or subtype).
	 *
	 * @param array $node Decoded node.
	 */
	public static function is_org( array $node ): bool {
		foreach ( self::types( $node ) as $type ) {
			if ( in_array( $type, self::ORG_TYPES, true ) || (bool) preg_match( '/(Organization|Business|Store|Service)$/', $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The @type of a node as a list of strings.
	 *
	 * @param array $node Decoded node.
	 * @return string[]
	 */
	private static function types( array $node ): array {
		$type = $node['@type'] ?? array();
		$list = is_array( $type ) ? $type : array( $type );
		return array_values( array_filter( array_map( 'strval', array_filter( $list, 'is_scalar' ) ), static fn( string $t ): bool => '' !== $t ) );
	}

	/**
	 * Where a block sits on the page: its 1-based index and a short snippet.
	 *
	 * @param int    $index Block index (1-based).
	 * @param string $raw   Raw block text.
	 */
	private static function origin( int $index, string $raw ): array {
		$flat = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
		if ( mb_strlen( $flat, 'UTF-8' ) > self::SNIPPET_LENGTH ) {
			$flat = mb_substr( $flat, 0, self::SNIPPET_LENGTH, 'UTF-8' ) . '...';
		}
		return array(
			'block'   => $index,
			'snippet' => $flat,
		);
	}

	/**
	 * Top-level nodes of a decoded block: the members of @graph, the items of
	 * a top-level list, or the block itself.
	 *
	 * @param array $decoded Decoded block.
	 * @return array[]
	 */
	private static function top_level_nodes( array $decoded ): array {
		if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
			$items = $decoded['@graph'];
		} elseif ( self::is_list( $decoded ) ) {
			$items = $decoded;
		} else {
			$items = array( $decoded );
		}
		return array_values( array_filter( $items, static fn( $n ): bool => is_array( $n ) && isset( $n['@type'] ) ) );
	}

	/**
	 * Collect @id definitions, @id references and publisher values at any depth.
	 *
	 * @param array $value  Current value.
	 * @param array $origin Block origin.
	 * @param array $defs   Accumulator: @id => list of {types, origin}.
	 * @param array $refs   Accumulator: list of {id, origin}.
	 * @param array $pubs   Accumulator: list of {value, origin}.
	 */
	private static function walk( array $value, array $origin, array &$defs, array &$refs, array &$pubs ): void {
		if ( isset( $value['@id'] ) && is_string( $value['@id'] ) && '' !== $value['@id'] ) {
			if ( array( '@id' ) === array_keys( $value ) ) {
				$refs[] = array(
					'id'     => $value['@id'],
					'origin' => $origin,
				);
				return;
			}
			$defs[ $value['@id'] ][] = array(
				'types'  => self::types( $value ),
				'origin' => $origin,
			);
		}

		foreach ( $value as $key => $child ) {
			if ( 'publisher' === $key && is_array( $child ) ) {
				foreach ( self::is_list( $child ) ? $child : array( $child ) as $pub ) {
					if ( is_array( $pub ) ) {
						$pubs[] = array(
							'value'  => $pub,
							'origin' => $origin,
						);
					}
				}
			}
			if ( is_array( $child ) ) {
				self::walk( $child, $origin, $defs, $refs, $pubs );
			}
		}
	}

	/**
	 * More than one top-level Organization or WebSite entity, each with its
	 * own @id (a node without an @id counts as its own entity).
	 *
	 * @param array[] $top  Top-level nodes with origins.
	 * @param string  $kind 'organization' or 'website'.
	 * @return array[] Zero or one conflict.
	 */
	private static function duplicate_entities( array $top, string $kind ): array {
		$matches = array();
		foreach ( $top as $item ) {
			$node = $item['node'];
			$hit  = 'website' === $kind ? in_array( 'WebSite', self::types( $node ), true ) : self::is_org( $node );
			if ( $hit ) {
				$matches[] = $item;
			}
		}

		if ( 'organization' === $kind ) {
			$matches = self::drop_linked_orgs( $matches );
		}

		$entities = array();
		foreach ( $matches as $n => $item ) {
			$id                 = $item['node']['@id'] ?? null;
			$key                = is_string( $id ) && '' !== $id ? $id : '#no-id-' . $n;
			$entities[ $key ][] = $item['origin'];
		}
		if ( count( $entities ) < 2 ) {
			return array();
		}

		$ids = array_map(
			static fn( string $k ): string => str_starts_with( $k, '#no-id-' ) ? '(no @id)' : $k,
			array_keys( $entities )
		);
		return array(
			array(
				'kind'    => 'duplicate_' . $kind,
				'message' => sprintf(
					'website' === $kind ? '%d different WebSite nodes: %s' : '%d different Organization nodes: %s',
					count( $entities ),
					implode( ', ', $ids )
				),
				'ids'     => $ids,
				'blocks'  => array_merge( ...array_values( $entities ) ),
			),
		);
	}

	/**
	 * Drop Organization nodes that are linked to another Organization node on
	 * the page (a LocalBusiness branch with parentOrganization, a department),
	 * since those describe one organization's structure, not a duplicate.
	 *
	 * @param array[] $orgs Top-level Organization nodes with origins.
	 * @return array[]
	 */
	private static function drop_linked_orgs( array $orgs ): array {
		$ids = array();
		foreach ( $orgs as $item ) {
			if ( isset( $item['node']['@id'] ) && is_string( $item['node']['@id'] ) ) {
				$ids[ $item['node']['@id'] ] = true;
			}
		}

		$linked = array();
		foreach ( $orgs as $n => $item ) {
			foreach ( self::ORG_LINK_PROPS as $prop ) {
				$targets = $item['node'][ $prop ] ?? array();
				if ( ! is_array( $targets ) ) {
					continue;
				}
				foreach ( self::is_list( $targets ) ? $targets : array( $targets ) as $target ) {
					$target_id = is_array( $target ) ? ( $target['@id'] ?? null ) : null;
					$self_id   = $item['node']['@id'] ?? null;
					if ( is_string( $target_id ) && isset( $ids[ $target_id ] ) && $self_id !== $target_id ) {
						$linked[ $n ] = true;
					}
				}
			}
		}

		// Keep one node from a fully linked set so a real duplicate elsewhere still counts.
		if ( count( $orgs ) > 0 && count( $orgs ) === count( $linked ) ) {
			unset( $linked[ array_key_first( $orgs ) ] );
		}
		return array_values( array_diff_key( $orgs, $linked ) );
	}

	/**
	 * The same @id declared with @types that share nothing.
	 *
	 * @param array $defs @id => list of {types, origin}.
	 * @return array[]
	 */
	private static function conflicting_types( array $defs ): array {
		$out = array();
		foreach ( $defs as $id => $list ) {
			$typed = array_values( array_filter( $list, static fn( array $d ): bool => array() !== $d['types'] ) );
			if ( count( $typed ) < 2 ) {
				continue;
			}
			$conflict = false;
			$count    = count( $typed );
			for ( $a = 0; $a < $count && ! $conflict; $a++ ) {
				for ( $b = $a + 1; $b < $count; $b++ ) {
					if ( array() === array_intersect( $typed[ $a ]['types'], $typed[ $b ]['types'] ) ) {
						$conflict = true;
						break;
					}
				}
			}
			if ( ! $conflict ) {
				continue;
			}
			$types = array();
			foreach ( $typed as $d ) {
				$types[] = implode( '+', $d['types'] );
			}
			$types = array_values( array_unique( $types ) );
			$out[] = array(
				'kind'    => 'conflicting_type',
				'message' => sprintf( '@id %s is declared as %s', $id, implode( ' and ', $types ) ),
				'id'      => (string) $id,
				'types'   => $types,
				'blocks'  => array_column( $typed, 'origin' ),
			);
		}
		return $out;
	}

	/**
	 * Same-site @id references that point at nothing defined on the page.
	 * References to other sites (Wikidata and similar) are left alone: JSON-LD
	 * allows pointing at an external IRI.
	 *
	 * @param array  $refs     List of {id, origin}.
	 * @param array  $defs     @id => definitions.
	 * @param string $page_url Lower-case page URL.
	 * @return array[]
	 */
	private static function dangling_refs( array $refs, array $defs, string $page_url ): array {
		$page_host = self::host( $page_url );
		$dangling  = array();
		foreach ( $refs as $ref ) {
			$id = $ref['id'];
			if ( isset( $defs[ $id ] ) ) {
				continue;
			}
			$host = self::host( $id );
			if ( '' !== $host && $host !== $page_host ) {
				continue;
			}
			$dangling[ $id ][] = $ref['origin'];
		}

		$out = array();
		foreach ( $dangling as $id => $origins ) {
			$out[] = array(
				'kind'    => 'dangling_id',
				'message' => sprintf( '@id reference %s points to no node on the page', $id ),
				'id'      => (string) $id,
				'blocks'  => $origins,
			);
		}
		return $out;
	}

	/**
	 * Publisher set to a Person on one node and an Organization on another.
	 *
	 * @param array $pubs List of {value, origin}.
	 * @param array $defs @id => definitions.
	 * @return array[]
	 */
	private static function mixed_publishers( array $pubs, array $defs ): array {
		$seen = array(
			'person'       => array(),
			'organization' => array(),
		);
		foreach ( $pubs as $pub ) {
			$types = self::types( $pub['value'] );
			$id    = $pub['value']['@id'] ?? null;
			if ( array() === $types && is_string( $id ) && isset( $defs[ $id ] ) ) {
				foreach ( $defs[ $id ] as $def ) {
					$types = array_merge( $types, $def['types'] );
				}
			}
			if ( in_array( 'Person', $types, true ) ) {
				$seen['person'][] = $pub['origin'];
			} elseif ( self::is_org( array( '@type' => $types ) ) ) {
				$seen['organization'][] = $pub['origin'];
			}
		}
		if ( array() === $seen['person'] || array() === $seen['organization'] ) {
			return array();
		}
		return array(
			array(
				'kind'    => 'mixed_publisher',
				'message' => 'publisher is a Person on one node and an Organization on another',
				'blocks'  => array_merge( $seen['person'], $seen['organization'] ),
			),
		);
	}

	/**
	 * Whether an array is a list (keys 0..n-1), for PHP 8.0 without array_is_list().
	 *
	 * @param array $value Array to test.
	 */
	private static function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 ) || array() === $value;
	}

	/**
	 * Lower-case host of a URL without a leading www., or '' for a relative IRI.
	 *
	 * @param string $url URL or IRI.
	 */
	private static function host( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
