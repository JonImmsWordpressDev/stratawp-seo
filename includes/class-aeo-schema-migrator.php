<?php
/**
 * AEO Schema Migrator — converts legacy QAPage JSON-LD to FAQPage.
 *
 * Background (issue #44): early AEO releases emitted QAPage structured data
 * for site-authored Q&A content. QAPage is Google's type for community
 * pages with user-submitted answers; using it for FAQ-style content trips
 * Search Console's QAPage validators. The generator now emits FAQPage, but
 * pages optimized before the fix have QAPage frozen into the
 * _swps_aeo_schema_json / _swps_aeo_schema_type post meta. This class
 * rewrites that stored meta in place so existing pages stop erroring.
 *
 * convert_schema() is pure (no WordPress) and unit-tested. migrate_all()
 * is the WP-dependent bulk runner invoked from the one-time upgrade routine
 * and the `wp swps migrate-qapage` CLI command.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts legacy QAPage AEO schema meta to FAQPage (issue #44).
 */
class SWPS_AEO_Schema_Migrator {

	private const META_SCHEMA_TYPE = '_swps_aeo_schema_type';
	private const META_SCHEMA_JSON = '_swps_aeo_schema_json';

	/**
	 * Convert a QAPage JSON-LD array to a valid FAQPage array.
	 *
	 * QAPage stores a single Question in mainEntity (Stack Overflow shape);
	 * FAQPage wants a list of Questions, each with name + acceptedAnswer.text.
	 * QAPage's suggestedAnswer (when there's no acceptedAnswer) becomes the
	 * FAQPage answer. Questions with no usable answer are dropped. QAPage-only
	 * fields (answerCount, upvoteCount, dateCreated, author) are stripped.
	 *
	 * @param array<string, mixed> $json Decoded QAPage JSON-LD.
	 * @return array<string, mixed>|null FAQPage array, or null if nothing convertible.
	 */
	public static function convert_schema( array $json ): ?array {
		$entity = $json['mainEntity'] ?? null;
		if ( null === $entity ) {
			return null;
		}

		// Normalize to a list of question candidates.
		$questions = isset( $entity['@type'] ) ? array( $entity ) : (array) $entity;

		$clean = array();
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$built = self::build_question( $q );
			if ( null !== $built ) {
				$clean[] = $built;
			}
		}

		if ( empty( $clean ) ) {
			return null;
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $clean,
		);
	}

	/**
	 * Build a clean FAQPage Question from a QAPage Question, or null if it
	 * has no usable name/answer.
	 *
	 * @param array<string, mixed> $q One QAPage Question node.
	 * @return array<string, mixed>|null
	 */
	private static function build_question( array $q ): ?array {
		$name = $q['name'] ?? ( $q['text'] ?? '' );
		if ( ! is_string( $name ) || '' === trim( $name ) ) {
			return null;
		}

		// Prefer acceptedAnswer; fall back to the first suggestedAnswer.
		$answer = $q['acceptedAnswer'] ?? null;
		if ( ! is_array( $answer ) || '' === trim( (string) ( $answer['text'] ?? '' ) ) ) {
			$suggested = $q['suggestedAnswer'] ?? null;
			if ( is_array( $suggested ) ) {
				// suggestedAnswer may be a single Answer or a list of them.
				$answer = isset( $suggested['text'] ) ? $suggested : ( $suggested[0] ?? null );
			}
		}

		$text = is_array( $answer ) ? (string) ( $answer['text'] ?? '' ) : '';
		if ( '' === trim( $text ) ) {
			return null;
		}

		return array(
			'@type'          => 'Question',
			'name'           => trim( $name ),
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => trim( $text ),
			),
		);
	}

	/**
	 * Bulk-convert every post still storing QAPage schema meta to FAQPage.
	 *
	 * Idempotent: only touches posts whose _swps_aeo_schema_type is 'qapage'.
	 * Posts whose stored JSON can't be converted have their AEO schema meta
	 * cleared (so they stop emitting invalid QAPage) and are reported as
	 * 'cleared' — the user can re-run AEO optimize to regenerate FAQPage.
	 *
	 * Also rewrites the swps_aeo_enabled_schema_types option so a saved
	 * 'qapage' selection becomes 'faqpage'.
	 *
	 * @return array{converted:int, cleared:int} Counts.
	 */
	public static function migrate_all(): array {
		self::migrate_enabled_types_option();

		$post_ids = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => self::META_SCHEMA_TYPE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => 'qapage',                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		);

		$converted = 0;
		$cleared   = 0;

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$raw     = (string) get_post_meta( $post_id, self::META_SCHEMA_JSON, true );
			$decoded = '' !== $raw ? json_decode( $raw, true ) : null;
			$faq     = is_array( $decoded ) ? self::convert_schema( $decoded ) : null;

			if ( null === $faq ) {
				// Nothing convertible — clear so the page stops emitting QAPage.
				delete_post_meta( $post_id, self::META_SCHEMA_TYPE );
				delete_post_meta( $post_id, self::META_SCHEMA_JSON );
				++$cleared;
				continue;
			}

			update_post_meta( $post_id, self::META_SCHEMA_TYPE, 'faqpage' );
			// wp_slash before update_post_meta: update_metadata() runs wp_unslash
			// internally, which would otherwise strip the \" escapes and corrupt
			// the stored JSON (same pattern as SWPS_AEO_Optimizer).
			update_post_meta(
				$post_id,
				self::META_SCHEMA_JSON,
				wp_slash( (string) wp_json_encode( $faq ) )
			);
			++$converted;
		}

		return array(
			'converted' => $converted,
			'cleared'   => $cleared,
		);
	}

	/**
	 * Replace 'qapage' with 'faqpage' in the enabled-schema-types option.
	 */
	private static function migrate_enabled_types_option(): void {
		$enabled = get_option( 'swps_aeo_enabled_schema_types', null );
		if ( ! is_array( $enabled ) || ! in_array( 'qapage', $enabled, true ) ) {
			return;
		}
		$enabled = array_values(
			array_unique(
				array_map(
					static fn( $t ) => 'qapage' === $t ? 'faqpage' : $t,
					$enabled
				)
			)
		);
		update_option( 'swps_aeo_enabled_schema_types', $enabled );
	}
}
