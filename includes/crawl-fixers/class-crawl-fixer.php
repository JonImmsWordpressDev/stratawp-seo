<?php
/**
 * Base class for Site Audit Fix-It fixers.
 *
 * A fixer turns crawl issue rows of one or more check types into applied
 * fixes. 'draft' fixers generate an AI proposal the user reviews before
 * apply; 'mechanical' fixers apply directly. Both snapshot before writing
 * and support undo.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract every Fix-It fixer implements.
 */
abstract class SWPS_Crawl_Fixer {

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	abstract public function check_ids(): array;

	/**
	 * 'draft' (AI proposal, review-before-apply) or 'mechanical' (direct).
	 *
	 * @return string
	 */
	abstract public function kind(): string;

	/**
	 * Whether this specific issue row is fixable (has a live target, etc.).
	 *
	 * @param array $issue Decoded issues_for_run() row.
	 * @return bool
	 */
	public function can_fix( array $issue ): bool {
		$type = (string) ( $issue['object_type'] ?? '' );
		$id   = (int) ( $issue['object_id'] ?? 0 );
		return '' !== $type && 'none' !== $type && $id > 0;
	}

	/**
	 * Generate a proposal for a draft-kind fixer.
	 *
	 * @param array $issue Decoded issue row.
	 * @return array|WP_Error {field, current, proposed, usage}.
	 */
	public function draft( array $issue ): array|WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return new WP_Error( 'swps_fixit_no_draft', __( 'This fix does not use drafts.', 'stratawp-seo' ) );
	}

	/**
	 * Apply the fix. Snapshot first.
	 *
	 * @param array $issue    Decoded issue row.
	 * @param array $accepted Fixer-specific acceptance payload (draft kinds
	 *                        pass the reviewed draft; mechanical kinds pass
	 *                        an empty array).
	 * @return array|WP_Error {changed: bool, message: string}.
	 */
	abstract public function apply( array $issue, array $accepted ): array|WP_Error;

	/**
	 * Restore the pre-apply snapshot.
	 *
	 * @param array $issue Decoded issue row.
	 * @return bool
	 */
	abstract public function undo( array $issue ): bool;
}
