<?php
/**
 * AEO Schema Generator — AI-driven dynamic JSON-LD for 5 schema types:
 * HowTo, Recipe, Product, Review, QAPage.
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
 * chat_json(array, int): array) — tests use FakeAIProvider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Schema_Generator {

	private const SUPPORTED_TYPES = array( 'howto', 'recipe', 'product', 'review', 'qapage' );

	/** @var object */
	private $provider;

	/** @var array<string, array<string, mixed>> */
	private array $fields;

	/**
	 * @param object $provider     Anything with chat_json(array, int): array.
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
	 * @param string $type  One of: howto, recipe, product, review, qapage.
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

		$system = 'You generate schema.org JSON-LD from blog post content. ' .
			'Only return fields you can derive from the post — do NOT invent.';
		$user   = sprintf(
			"Type: %s\nTitle: %s\n\nRequired fields: %s\nRecommended fields: %s\n\n" .
			"Post HTML (truncated):\n%s\n\n" .
			'Return a single JSON object: {"@context": "https://schema.org", "@type": "%s", ...fields...}. ' .
			'Use empty arrays / null for fields you cannot derive.',
			$expected_type,
			$title,
			implode( ', ', $required ),
			implode( ', ', $recommended ),
			mb_substr( wp_strip_all_tags( $html ), 0, 8000 ),
			$expected_type
		);

		try {
			$json = $this->provider->chat_json(
				array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
				2048
			);
		} catch ( \Throwable $e ) {
			return array(
				'json'  => null,
				'error' => $e->getMessage(),
			);
		}

		if ( function_exists( 'apply_filters' ) ) {
			$json = (array) apply_filters( 'swps_aeo_schema_json', $json, $type, null );
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
}
