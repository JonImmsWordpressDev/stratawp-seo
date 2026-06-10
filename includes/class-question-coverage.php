<?php
/**
 * Question Coverage — helpers for the AEO question-coverage engine.
 *
 * Phase A (Phase B adds GSC mining): pure, WP-free formatting utility
 * consumed by SWPS_AEO_Optimizer::do_propose() to inject coverage gaps
 * as qa-insert instructions into the proposal prompt.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWPS_Question_Coverage
 *
 * Utility class for the AEO question-coverage engine.
 */
class SWPS_Question_Coverage {

	/**
	 * Format a compact prompt instruction from sub-query gap data.
	 *
	 * Missing questions are listed before partial questions. Answered
	 * questions are excluded entirely. Returns an empty string when there
	 * are no missing or partial queries (so callers can do a simple
	 * `if ( '' !== $instruction )` guard).
	 *
	 * @param array<int, array<string, mixed>> $sub_queries Sub-query list from coverage scorer.
	 * @param int                                          $cap         Maximum number of questions to include (default 3).
	 * @return string Prompt fragment, or '' when nothing to add.
	 */
	public static function format_gap_instruction( array $sub_queries, int $cap = 3 ): string {
		$missing = array();
		$partial = array();

		foreach ( $sub_queries as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$status = (string) ( $item['status'] ?? '' );
			$q      = trim( (string) ( $item['q'] ?? '' ) );
			if ( '' === $q ) {
				continue;
			}
			if ( 'missing' === $status ) {
				$missing[] = $q;
			} elseif ( 'partial' === $status ) {
				$partial[] = $q;
			}
			// 'answered' is skipped intentionally.
		}

		// Missing questions first, then partial.
		$ordered = array_merge( $missing, $partial );

		if ( empty( $ordered ) ) {
			return '';
		}

		$capped = array_slice( $ordered, 0, max( 1, $cap ) );

		$list = implode(
			'; ',
			array_map(
				static function ( string $q ): string {
					return '"' . $q . '"';
				},
				$capped
			)
		);

		return 'Also add qa inserts answering these searcher questions the post does not yet fully cover: ' . $list . '.';
	}
}
