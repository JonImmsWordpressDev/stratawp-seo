<?php
/**
 * Fix-It fixer: rewrite post titles and meta tags to fix title issues.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta title draft fixer.
 */
class SWPS_Fixer_Meta_Title extends SWPS_Crawl_Fixer {

	/** Draft field name in SWPS_Fixit_Store. */
	protected const FIELD = 'meta_title';

	/** Meta key written on apply. */
	protected const META_KEY = '_swps_meta_title';

	/** JSON key expected from the model. */
	protected const RESPONSE_KEY = 'title';

	/** Length window enforced in the prompt and on the response. */
	protected const MIN_LEN = 30;
	protected const MAX_LEN = 60;

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		return array(
			'missing_title',
			'title_too_long',
			'title_too_short',
			'duplicate_title',
		);
	}

	/**
	 * Fixer kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'draft';
	}

	/**
	 * Build the generation prompt. Pure; unit-tested.
	 *
	 * @param array $ctx {kind, page_title, excerpt, keyword, min, max, siblings}.
	 */
	public static function build_prompt( array $ctx ): string {
		$prompt = sprintf(
			"Write an SEO %s for this page.\nPage title: %s\nContent summary: %s\n",
			(string) $ctx['kind'],
			(string) $ctx['page_title'],
			(string) $ctx['excerpt']
		);
		if ( '' !== (string) $ctx['keyword'] ) {
			$prompt .= 'Focus keyword (must appear naturally): ' . (string) $ctx['keyword'] . "\n";
		}
		$prompt .= sprintf(
			"Length: between %d and %d characters.\n",
			(int) $ctx['min'],
			(int) $ctx['max']
		);
		if ( array() !== (array) $ctx['siblings'] ) {
			$prompt .= "It must clearly differ from these existing values on other pages:\n- "
				. implode( "\n- ", array_map( 'strval', (array) $ctx['siblings'] ) ) . "\n";
		}
		$prompt .= sprintf( 'Return only JSON: {"%s": "..."}', static::RESPONSE_KEY );

		return $prompt;
	}

	/**
	 * Extract, trim, and length-cap the model response. Pure; unit-tested.
	 *
	 * @param array  $decoded chat_json() result.
	 * @param string $key     Expected JSON key.
	 * @param int    $max_len Hard cap (word-boundary truncation).
	 */
	public static function normalize_response( array $decoded, string $key, int $max_len ): ?string {
		$value = trim( (string) ( $decoded[ $key ] ?? '' ) );
		if ( '' === $value ) {
			return null;
		}
		if ( mb_strlen( $value ) > $max_len ) {
			$value = mb_substr( $value, 0, $max_len );
			$cut   = mb_strrpos( $value, ' ' );
			if ( false !== $cut && $cut > (int) ( $max_len / 2 ) ) {
				$value = mb_substr( $value, 0, $cut );
			}
			$value = rtrim( $value, " \t.,;:-" );
		}
		return $value;
	}

	/**
	 * Generate a draft for the issue's target object and store it.
	 *
	 * @param array $issue Decoded issue row.
	 * @return array|WP_Error {field, current, proposed, usage}
	 */
	public function draft( array $issue ): array|WP_Error {
		$otype = (string) $issue['object_type'];
		$oid   = (int) $issue['object_id'];

		$ctx = $this->context_for( $otype, $oid, $issue );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$api     = SWPS_Provider_Factory::create_ai_provider();
		$decoded = $api->chat_json(
			'You are an SEO copywriting expert. Return only valid JSON.',
			static::build_prompt( $ctx ),
			512
		);
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$proposed = static::normalize_response( $decoded, static::RESPONSE_KEY, static::MAX_LEN );
		if ( null === $proposed ) {
			return new WP_Error( 'swps_fixit_bad_response', __( 'The AI response was empty or malformed.', 'stratawp-seo' ) );
		}

		$draft = array(
			'check_id'   => (string) $issue['type'],
			'run_id'     => (int) ( $issue['run_id'] ?? 0 ),
			'current'    => $ctx['current'],
			'proposed'   => $proposed,
			'drafted_at' => time(),
			'usage'      => is_array( $decoded['_usage'] ?? null ) ? $decoded['_usage'] : array(),
		);
		SWPS_Fixit_Store::put_draft( $otype, $oid, static::FIELD, $draft );

		return array(
			'field'    => static::FIELD,
			'current'  => $ctx['current'],
			'proposed' => $proposed,
			'usage'    => $draft['usage'],
		);
	}

	/**
	 * Resolve duplicate URLs to their live text values for the AI prompt.
	 *
	 * @param array $urls Sibling page URLs (from issue['detail']['duplicate_of']).
	 * @return array Unique non-empty text values (meta key content or title/name fallback).
	 */
	protected function sibling_values( array $urls ): array {
		$values = array();
		$count  = 0;

		foreach ( $urls as $url ) {
			if ( $count >= 5 ) {
				break; // Cap at 5 siblings.
			}

			$target = SWPS_Crawl_Target::resolve( (string) $url );
			$otype  = (string) $target['object_type'];
			$oid    = (int) $target['object_id'];

			// Skip unresolved URLs.
			if ( 'none' === $otype || 'user' === $otype || $oid <= 0 ) {
				continue;
			}

			$value = '';
			if ( 'term' === $otype ) {
				$value = (string) get_term_meta( $oid, static::META_KEY, true );
				// Fallback to term name only for titles, not descriptions.
				if ( '' === $value && 'title' === static::RESPONSE_KEY ) {
					$term  = get_term( $oid );
					$value = $term && ! is_wp_error( $term ) ? (string) $term->name : '';
				}
			} else {
				$value = (string) get_post_meta( $oid, static::META_KEY, true );
				// Fallback to post title only for titles, not descriptions.
				if ( '' === $value && 'title' === static::RESPONSE_KEY ) {
					$value = (string) get_the_title( $oid );
				}
			}

			if ( '' !== $value ) {
				$values[ $value ] = true; // Track uniqueness.
				++$count;
			}
		}

		return array_keys( $values );
	}

	/**
	 * Gather generation context from the target post or term.
	 *
	 * @param string $otype 'post' | 'term'.
	 * @param int    $oid   Object id.
	 * @param array  $issue Decoded issue row (for duplicate siblings).
	 * @return array|WP_Error {kind, page_title, excerpt, keyword, min, max, siblings, current}
	 */
	protected function context_for( string $otype, int $oid, array $issue ): array|WP_Error {
		$siblings = $this->sibling_values( array_map( 'strval', (array) ( $issue['detail']['duplicate_of'] ?? array() ) ) );

		if ( 'term' === $otype ) {
			$term = get_term( $oid );
			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error( 'swps_fixit_gone', __( 'The term no longer exists.', 'stratawp-seo' ) );
			}
			return array(
				'kind'       => 'title' === static::RESPONSE_KEY ? 'meta title' : 'meta description',
				'page_title' => $term->name,
				'excerpt'    => mb_substr( wp_strip_all_tags( (string) $term->description ), 0, 500 ),
				'keyword'    => (string) get_term_meta( $oid, '_swps_focus_keyword', true ),
				'min'        => static::MIN_LEN,
				'max'        => static::MAX_LEN,
				'siblings'   => $siblings,
				'current'    => (string) get_term_meta( $oid, static::META_KEY, true ),
			);
		}

		$post = get_post( $oid );
		if ( ! $post ) {
			return new WP_Error( 'swps_fixit_gone', __( 'The post no longer exists.', 'stratawp-seo' ) );
		}
		return array(
			'kind'       => 'title' === static::RESPONSE_KEY ? 'meta title' : 'meta description',
			'page_title' => (string) $post->post_title,
			'excerpt'    => mb_substr( wp_strip_all_tags( (string) $post->post_content ), 0, 2000 ),
			'keyword'    => (string) get_post_meta( $oid, '_swps_focus_keyword', true ),
			'min'        => static::MIN_LEN,
			'max'        => static::MAX_LEN,
			'siblings'   => $siblings,
			'current'    => (string) get_post_meta( $oid, static::META_KEY, true ),
		);
	}

	/**
	 * Apply the reviewed draft: snapshot, write the meta key, drop the draft.
	 *
	 * @param array $issue    Decoded issue row.
	 * @param array $accepted Unused (the stored draft is the source of truth).
	 * @return array|WP_Error {changed: bool, message: string}
	 */
	public function apply( array $issue, array $accepted ): array|WP_Error {
		$otype = (string) $issue['object_type'];
		$oid   = (int) $issue['object_id'];

		$drafts = SWPS_Fixit_Store::get_drafts( $otype, $oid );
		if ( ! isset( $drafts[ static::FIELD ] ) ) {
			return new WP_Error( 'swps_fixit_no_draft', __( 'No draft cached — generate drafts again (they may have been swept).', 'stratawp-seo' ) );
		}

		$current = 'term' === $otype
			? (string) get_term_meta( $oid, static::META_KEY, true )
			: (string) get_post_meta( $oid, static::META_KEY, true );

		SWPS_Fixit_Store::snapshot_fields( $otype, $oid, array( static::FIELD => $current ) );

		$value = $this->sanitize( $drafts[ static::FIELD ]['proposed'] );
		if ( 'term' === $otype ) {
			update_term_meta( $oid, static::META_KEY, $value );
		} else {
			update_post_meta( $oid, static::META_KEY, $value );
		}

		SWPS_Fixit_Store::remove_draft( $otype, $oid, static::FIELD );

		return array(
			'changed' => true,
			'message' => __( 'Applied.', 'stratawp-seo' ),
		);
	}

	/**
	 * Restore the snapshotted value for this field.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function undo( array $issue ): bool {
		$otype = (string) $issue['object_type'];
		$oid   = (int) $issue['object_id'];

		$snap = SWPS_Fixit_Store::get_snapshot( $otype, $oid );
		if ( ! array_key_exists( static::FIELD, $snap['fields'] ?? array() ) ) {
			return false;
		}

		$original = (string) $snap['fields'][ static::FIELD ];
		if ( 'term' === $otype ) {
			if ( '' === $original ) {
				delete_term_meta( $oid, static::META_KEY );
			} else {
				update_term_meta( $oid, static::META_KEY, $this->sanitize( $original ) );
			}
		} elseif ( '' === $original ) {
			delete_post_meta( $oid, static::META_KEY );
		} else {
			update_post_meta( $oid, static::META_KEY, $this->sanitize( $original ) );
		}

		SWPS_Fixit_Store::remove_snapshot_field( $otype, $oid, static::FIELD );

		return true;
	}

	/**
	 * Field-appropriate sanitizer (titles are single-line).
	 *
	 * @param string $value Raw value.
	 */
	protected function sanitize( string $value ): string {
		return sanitize_text_field( $value );
	}
}
