<?php
/**
 * AEO Schema Generator — AI-driven dynamic JSON-LD for 5 schema types:
 * HowTo, Recipe, Product, Review, FAQPage.
 *
 * Q&A content uses FAQPage (a list of questions each with one authoritative
 * answer the site provides), NOT QAPage. QAPage is Google's type for pages
 * built around a single user-submitted question with community answers
 * (forum / Stack Overflow style); applying it to site-authored Q&A trips
 * Search Console's QAPage validators. See issue #44.
 *
 * Workflow:
 *   1. Caller picks a type (typically from SWPS_AEO_Markup_Scorer::infer_schema_type()).
 *   2. Generator builds a per-type prompt using the field manifest at
 *      includes/data/aeo-schema-fields.json (required + recommended fields).
 *   3. Provider returns the JSON-LD object.
 *   4. Generator validates: @context, @type, all required fields non-empty.
 *   5. On validation failure: returns ['json' => null, 'error' => '...'].
 *
 * Output filterable via swps_aeo_schema_json before validation.
 *
 * Provider is dependency-injected (any object with
 * chat_json(string, string, int): array|WP_Error) — tests use FakeAIProvider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Schema_Generator {

	private const SUPPORTED_TYPES = array( 'howto', 'recipe', 'product', 'review', 'faqpage' );

	/** @var object */
	private $provider;

	/** @var array<string, array<string, mixed>> */
	private array $fields;

	/**
	 * @param object $provider     Anything with chat_json(string, string, int): array|WP_Error.
	 * @param string $fields_path  Path to the schema-fields JSON manifest.
	 */
	public function __construct( $provider, string $fields_path ) {
		$this->provider = $provider;
		$raw            = is_readable( $fields_path ) ? (string) file_get_contents( $fields_path ) : '{}';
		$decoded        = json_decode( $raw, true );
		$this->fields   = is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Generate JSON-LD for the given type.
	 *
	 * @param string $type  One of: howto, recipe, product, review, faqpage.
	 * @param string $title Post title.
	 * @param string $html  Raw post HTML.
	 * @return array{json: ?array<string, mixed>, error: ?string}
	 */
	public function generate( string $type, string $title, string $html ): array {
		$type = strtolower( $type );
		if ( ! in_array( $type, self::SUPPORTED_TYPES, true ) ) {
			return array(
				'json'  => null,
				'error' => 'unsupported_type',
			);
		}
		if ( ! isset( $this->fields[ $type ] ) ) {
			return array(
				'json'  => null,
				'error' => 'missing_field_manifest',
			);
		}

		$spec          = $this->fields[ $type ];
		$required      = (array) ( $spec['required']    ?? array() );
		$recommended   = (array) ( $spec['recommended'] ?? array() );
		$expected_type = (string) ( $spec['type'] ?? '' );
		$shape_notes   = $this->format_shape_notes( $spec );

		$system = 'You generate schema.org JSON-LD from blog post content. ' .
			'Required fields MUST be populated by synthesizing from the post title and body ' .
			'(do not leave them empty). Recommended fields should only be included when ' .
			'derivable from the post. Do not invent facts not in the post.';

		$user = sprintf(
			"Type: %s\nTitle: %s\n\n" .
			"REQUIRED fields (must be populated, never empty): %s\n" .
			"RECOMMENDED fields (include when derivable, omit otherwise): %s\n" .
			"%s\n" .
			"Post HTML (truncated):\n%s\n\n" .
			'Return a single JSON object: {"@context": "https://schema.org", "@type": "%s", ...fields...}. ' .
			'For recommended fields you cannot derive, omit the key entirely (do NOT use null or empty arrays). ' .
			'For required fields, always populate — synthesize from the title and body if necessary.',
			$expected_type,
			$title,
			implode( ', ', $required ),
			empty( $recommended ) ? '(none)' : implode( ', ', $recommended ),
			'' === $shape_notes ? '' : "\nField shape guidance:\n" . $shape_notes,
			mb_substr( wp_strip_all_tags( $html ), 0, 8000 ),
			$expected_type
		);

		$response = $this->provider->chat_json( $system, $user, 2048 );

		if ( $response instanceof WP_Error ) {
			return array(
				'json'  => null,
				'error' => $response->get_error_message(),
			);
		}
		if ( ! is_array( $response ) ) {
			return array(
				'json'  => null,
				'error' => 'invalid_response',
			);
		}

		$json = $response;

		if ( function_exists( 'apply_filters' ) ) {
			$json = (array) apply_filters( 'swps_aeo_schema_json', $json, $type, null );
		}

		// FAQPage's mainEntity must be a list of Questions. The AI sometimes
		// returns a single Question object for a one-question post; wrap it so
		// the output matches Google's expected shape.
		if ( 'FAQPage' === $expected_type ) {
			$json = $this->normalize_faqpage( (array) $json );
		}

		$err = $this->validate( (array) $json, $expected_type, $required );
		if ( null !== $err ) {
			return array(
				'json'  => null,
				'error' => $err,
			);
		}

		return array(
			'json'  => (array) $json,
			'error' => null,
		);
	}

	/**
	 * Build human-readable shape guidance from the spec's *_shape entries.
	 *
	 * The schema-fields manifest defines nested-object shapes (e.g.
	 * FAQPage's main_entity_shape → Question, HowTo's step_shape →
	 * HowToStep). Without telling the AI these shapes, it tends to
	 * return empty arrays or null for the parent required field — which
	 * the validator rejects. This method turns each *_shape entry into
	 * a one-line prompt fragment the AI can use as a template.
	 *
	 * @param array<string, mixed> $spec One type's entry from the manifest.
	 */
	private function format_shape_notes( array $spec ): string {
		$lines = array();
		foreach ( $spec as $key => $value ) {
			if ( ! is_array( $value ) || ! str_ends_with( (string) $key, '_shape' ) ) {
				continue;
			}

			// Shape keys in the manifest are snake_case (e.g. main_entity_shape)
			// but schema.org field names are camelCase (e.g. mainEntity). Convert
			// here so the AI sees the actual schema field name. A shape entry can
			// override with an explicit "field" key when the parent field name
			// doesn't derive cleanly from the shape key (e.g. instruction_shape
			// describes the items inside the recipeInstructions array).
			$shape_key    = substr( (string) $key, 0, -strlen( '_shape' ) );
			$parent_field = isset( $value['field'] ) && is_string( $value['field'] )
				? $value['field']
				: $this->snake_to_camel( $shape_key );

			$type   = (string) ( $value['@type'] ?? '' );
			$fields = (array)  ( $value['fields'] ?? array() );
			if ( '' === $type || empty( $fields ) ) {
				continue;
			}
			$is_array = ! empty( $value['is_array'] );
			$lines[]  = $is_array
				? sprintf(
					'  - %s should be an ARRAY of %s objects, each with fields: %s',
					$parent_field,
					$type,
					implode( ', ', $fields )
				)
				: sprintf(
					'  - %s should be a %s object with fields: %s',
					$parent_field,
					$type,
					implode( ', ', $fields )
				);
		}
		return implode( "\n", $lines );
	}

	/** Convert "snake_case_name" → "snakeCaseName". */
	private function snake_to_camel( string $snake ): string {
		if ( false === strpos( $snake, '_' ) ) {
			return $snake;
		}
		return lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $snake ) ) ) );
	}

	/**
	 * Validate generated JSON-LD against schema-field manifest requirements.
	 *
	 * @param array<string, mixed> $json
	 * @param string               $expected_type
	 * @param string[]             $required
	 */
	private function validate( array $json, string $expected_type, array $required ): ?string {
		if ( ( $json['@context'] ?? '' ) !== 'https://schema.org' ) {
			return 'invalid @context';
		}
		if ( ( $json['@type'] ?? '' ) !== $expected_type ) {
			return 'invalid @type (expected ' . $expected_type . ')';
		}

		// FAQPage needs structural validation Google enforces but a flat
		// required-fields check can't express: every Question must carry a
		// non-empty name and an acceptedAnswer with non-empty text. Emitting
		// markup that misses these is what trips Search Console (issue #44).
		if ( 'FAQPage' === $expected_type ) {
			return $this->validate_faqpage( $json );
		}

		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $json ) ) {
				return "missing required field: {$field}";
			}
			$v = $json[ $field ];
			if ( null === $v || '' === $v || ( is_array( $v ) && empty( $v ) ) ) {
				return "empty required field: {$field}";
			}
		}
		return null;
	}

	/**
	 * Coerce a FAQPage's mainEntity into a list of Question objects.
	 *
	 * The AI may return mainEntity as a single Question object (one-question
	 * post). Google accepts a single item but its examples and validators
	 * favour an array; we always emit a list for consistency.
	 *
	 * @param array<string, mixed> $json
	 * @return array<string, mixed>
	 */
	private function normalize_faqpage( array $json ): array {
		$entity = $json['mainEntity'] ?? null;
		if ( is_array( $entity ) && isset( $entity['@type'] ) ) {
			$json['mainEntity'] = array( $entity );
		}
		return $json;
	}

	/**
	 * Validate a FAQPage's mainEntity question/answer structure.
	 *
	 * Requires a non-empty list of Question objects, each with a non-empty
	 * name and an acceptedAnswer carrying non-empty text — the minimum Google
	 * accepts for FAQPage.
	 *
	 * @param array<string, mixed> $json
	 */
	private function validate_faqpage( array $json ): ?string {
		$entities = $json['mainEntity'] ?? null;
		if ( ! is_array( $entities ) || empty( $entities ) ) {
			return 'empty required field: mainEntity';
		}

		foreach ( $entities as $q ) {
			if ( ! is_array( $q ) || ( $q['@type'] ?? '' ) !== 'Question' ) {
				return 'mainEntity must be a list of Question objects';
			}
			$name = $q['name'] ?? '';
			if ( ! is_string( $name ) || '' === trim( $name ) ) {
				return 'Question missing name';
			}
			$answer = $q['acceptedAnswer'] ?? null;
			$text   = is_array( $answer ) ? ( $answer['text'] ?? '' ) : '';
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				return 'Question missing acceptedAnswer text';
			}
		}
		return null;
	}
}
