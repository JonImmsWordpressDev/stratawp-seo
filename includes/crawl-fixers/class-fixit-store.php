<?php
/**
 * Draft + undo-snapshot storage for the Fix-It engine.
 *
 * Drafts: `_swps_fixit_drafts` — a JSON map keyed by field
 * ('meta_title', 'meta_description'), so one object can hold a title AND
 * a description draft without clobbering. Snapshots: `_swps_fixit_snapshot`
 * — a JSON map of pre-change values, merged (earliest wins) across
 * sequential applies so undo always restores the true original.
 *
 * JSON is wp_slash()-wrapped before meta writes; update_metadata()
 * wp_unslash()es internally and eats JSON escapes otherwise (the 4.6.6 /
 * 4.22.1 AEO bug).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta-backed draft and snapshot store (posts and terms).
 */
class SWPS_Fixit_Store {

	public const META_DRAFTS   = '_swps_fixit_drafts';
	public const META_SNAPSHOT = '_swps_fixit_snapshot';

	// =========================================================================
	// PURE HELPERS (unit-tested)
	// =========================================================================

	/**
	 * Merge new pre-change field values into an existing snapshot. The
	 * earliest captured value wins per field, so sequential applies to the
	 * same object never lose the true original.
	 *
	 * @param array $existing   Decoded snapshot ({fields, taken_at}) or empty.
	 * @param array $new_fields field => pre-change value.
	 * @return array {fields: array, taken_at: int}
	 */
	public static function merge_snapshot( array $existing, array $new_fields ): array {
		$fields = is_array( $existing['fields'] ?? null ) ? $existing['fields'] : array();

		foreach ( $new_fields as $field => $value ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				$fields[ $field ] = $value;
			}
		}

		return array(
			'fields'   => $fields,
			'taken_at' => (int) ( $existing['taken_at'] ?? time() ),
		);
	}

	/**
	 * Validate and normalize a draft array. Null for garbage.
	 *
	 * @param mixed $draft Candidate draft.
	 * @return array|null {check_id, run_id, current, proposed, drafted_at, usage}
	 */
	public static function normalize_draft( $draft ): ?array {
		if ( ! is_array( $draft ) ) {
			return null;
		}
		if ( '' === (string) ( $draft['check_id'] ?? '' ) || '' === (string) ( $draft['proposed'] ?? '' ) ) {
			return null;
		}

		return array(
			'check_id'   => (string) $draft['check_id'],
			'run_id'     => (int) ( $draft['run_id'] ?? 0 ),
			'current'    => (string) ( $draft['current'] ?? '' ),
			'proposed'   => (string) $draft['proposed'],
			'drafted_at' => (int) ( $draft['drafted_at'] ?? time() ),
			'usage'      => is_array( $draft['usage'] ?? null ) ? $draft['usage'] : array(),
		);
	}

	// =========================================================================
	// WP-COUPLED STORAGE
	// =========================================================================

	/**
	 * All drafts on an object, keyed by field. Invalid entries dropped.
	 *
	 * @param string $otype Object type ('post' or 'term').
	 * @param int    $oid   Object ID.
	 * @return array
	 */
	public static function get_drafts( string $otype, int $oid ): array {
		$decoded = json_decode( (string) self::meta_get( $otype, $oid, self::META_DRAFTS ), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$drafts = array();
		foreach ( $decoded as $field => $draft ) {
			$normalized = self::normalize_draft( $draft );
			if ( null !== $normalized ) {
				$drafts[ (string) $field ] = $normalized;
			}
		}

		return $drafts;
	}

	/**
	 * Store one field's draft, preserving the object's other drafts.
	 *
	 * @param string $otype Object type ('post' or 'term').
	 * @param int    $oid   Object ID.
	 * @param string $field Field name.
	 * @param array  $draft Draft data.
	 * @return void
	 */
	public static function put_draft( string $otype, int $oid, string $field, array $draft ): void {
		$drafts           = self::get_drafts( $otype, $oid );
		$drafts[ $field ] = $draft;
		self::meta_set( $otype, $oid, self::META_DRAFTS, wp_slash( (string) wp_json_encode( $drafts ) ) );
	}

	/**
	 * Remove one field's draft; deletes the meta when the map empties.
	 *
	 * @param string $otype Object type ('post' or 'term').
	 * @param int    $oid   Object ID.
	 * @param string $field Field name.
	 * @return void
	 */
	public static function remove_draft( string $otype, int $oid, string $field ): void {
		$drafts = self::get_drafts( $otype, $oid );
		unset( $drafts[ $field ] );
		if ( array() === $drafts ) {
			self::meta_delete( $otype, $oid, self::META_DRAFTS );
			return;
		}
		self::meta_set( $otype, $oid, self::META_DRAFTS, wp_slash( (string) wp_json_encode( $drafts ) ) );
	}

	/**
	 * Merge pre-change values into the object's snapshot (earliest wins).
	 *
	 * @param string $otype  Object type ('post' or 'term').
	 * @param int    $oid    Object ID.
	 * @param array  $fields field => pre-change value.
	 * @return void
	 */
	public static function snapshot_fields( string $otype, int $oid, array $fields ): void {
		$existing = json_decode( (string) self::meta_get( $otype, $oid, self::META_SNAPSHOT ), true );
		$merged   = self::merge_snapshot( is_array( $existing ) ? $existing : array(), $fields );
		self::meta_set( $otype, $oid, self::META_SNAPSHOT, wp_slash( (string) wp_json_encode( $merged ) ) );
	}

	/**
	 * Decoded snapshot ({fields, taken_at}) or empty array.
	 *
	 * @param string $otype Object type ('post' or 'term').
	 * @param int    $oid   Object ID.
	 * @return array
	 */
	public static function get_snapshot( string $otype, int $oid ): array {
		$decoded = json_decode( (string) self::meta_get( $otype, $oid, self::META_SNAPSHOT ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Delete the snapshot after a successful undo.
	 *
	 * @param string $otype Object type ('post' or 'term').
	 * @param int    $oid   Object ID.
	 * @return void
	 */
	public static function clear_snapshot( string $otype, int $oid ): void {
		self::meta_delete( $otype, $oid, self::META_SNAPSHOT );
	}

	// =========================================================================
	// META DISPATCH
	// =========================================================================

	/**
	 * Read meta for a post or term target.
	 *
	 * @param string $otype Object type ('post' or 'term').
	 * @param int    $oid   Object ID.
	 * @param string $key   Meta key.
	 * @return string
	 */
	private static function meta_get( string $otype, int $oid, string $key ): string {
		if ( 'term' === $otype ) {
			return (string) get_term_meta( $oid, $key, true );
		}
		return (string) get_post_meta( $oid, $key, true );
	}

	/**
	 * Write meta for a post or term target.
	 *
	 * @param string $otype Object type ('post' or 'term').
	 * @param int    $oid   Object ID.
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 * @return void
	 */
	private static function meta_set( string $otype, int $oid, string $key, string $value ): void {
		if ( 'term' === $otype ) {
			update_term_meta( $oid, $key, $value );
			return;
		}
		update_post_meta( $oid, $key, $value );
	}

	/**
	 * Delete meta for a post or term target.
	 *
	 * @param string $otype Object type ('post' or 'term').
	 * @param int    $oid   Object ID.
	 * @param string $key   Meta key.
	 * @return void
	 */
	private static function meta_delete( string $otype, int $oid, string $key ): void {
		if ( 'term' === $otype ) {
			delete_term_meta( $oid, $key );
			return;
		}
		delete_post_meta( $oid, $key );
	}
}
