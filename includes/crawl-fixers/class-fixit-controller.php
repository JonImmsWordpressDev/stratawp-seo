<?php
/**
 * AJAX controller for the Site Audit Fix-It engine.
 *
 * Thin nonce/capability ajax_* wrappers over testable do_*() methods
 * (SWPS_AEO_Optimizer convention). Draft generation is chunked with an
 * offset cursor — the codebase's standard batch idiom.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fix-It draft/apply/undo/dismiss endpoints.
 */
class SWPS_Fixit_Controller {

	public const NONCE_ACTION = 'swps_fixit';
	public const SWEEP_HOOK   = 'swps_fixit_sweep';

	/** Drafts per AJAX chunk (each is one AI call). */
	private const DRAFT_CHUNK = 5;

	/** Mechanical applies per AJAX chunk. */
	private const APPLY_CHUNK = 10;

	/** Drafts older than this are swept. */
	private const DRAFT_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Wire AJAX + sweep cron.
	 */
	public function __construct() {
		add_action( 'wp_ajax_swps_fixit_draft_chunk', array( $this, 'ajax_draft_chunk' ) );
		add_action( 'wp_ajax_swps_fixit_apply', array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_swps_fixit_undo', array( $this, 'ajax_undo' ) );
		add_action( 'wp_ajax_swps_fixit_dismiss', array( $this, 'ajax_dismiss' ) );
		add_action( self::SWEEP_HOOK, array( $this, 'sweep_drafts' ) );

		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::SWEEP_HOOK );
		}
	}

	// =========================================================================
	// PURE HELPERS (unit-tested)
	// =========================================================================

	/**
	 * Slice a row list for chunked processing.
	 *
	 * @param array $rows   All rows.
	 * @param int   $offset Start index.
	 * @param int   $limit  Chunk size.
	 * @return array {batch: array, remaining: int}
	 */
	public static function partition_rows( array $rows, int $offset, int $limit ): array {
		$batch     = array_slice( $rows, $offset, $limit );
		$remaining = max( 0, count( $rows ) - $offset - count( $batch ) );

		return array(
			'batch'     => $batch,
			'remaining' => $remaining,
		);
	}

	// =========================================================================
	// AJAX WRAPPERS
	// =========================================================================

	/** Draft the next chunk of AI proposals for one check group. */
	public function ajax_draft_chunk(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard()
		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard()
		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard()
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

		$this->respond( $this->do_draft_chunk( $run_id, $check_id, $offset ) );
	}

	/** Apply drafts / run mechanical fixes for accepted rows. */
	public function ajax_apply(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard()
		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard()
		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in guard(); elements intval-mapped below
		$issue_ids = isset( $_POST['issue_ids'] ) ? (array) wp_unslash( $_POST['issue_ids'] ) : array();
		$accepted  = array_map( 'intval', $issue_ids );

		$this->respond( $this->do_apply( $run_id, $check_id, $accepted ) );
	}

	/** Undo one applied fix. */
	public function ajax_undo(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard()
		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard()
		$issue_id = isset( $_POST['issue_id'] ) ? absint( wp_unslash( $_POST['issue_id'] ) ) : 0;

		$this->respond( $this->do_undo( $run_id, $issue_id ) );
	}

	/** Discard one stored draft. */
	public function ajax_dismiss(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard()
		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard()
		$issue_id = isset( $_POST['issue_id'] ) ? absint( wp_unslash( $_POST['issue_id'] ) ) : 0;

		$this->respond( $this->do_dismiss( $run_id, $issue_id ) );
	}

	/** Shared nonce + capability gate. */
	private function guard(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ), 403 );
		}
	}

	/**
	 * Send a do_*() result as JSON (error key → error response).
	 *
	 * @param array $result Result from a do_*() method.
	 */
	private function respond( array $result ): void {
		if ( isset( $result['error'] ) ) {
			$data = array( 'message' => (string) $result['error'] );
			foreach ( $result as $key => $value ) {
				if ( ! in_array( $key, array( 'error', 'http_status' ), true ) ) {
					$data[ $key ] = $value;
				}
			}
			wp_send_json_error( $data, (int) ( $result['http_status'] ?? 400 ) );
		}
		wp_send_json_success( $result );
	}

	// =========================================================================
	// DO METHODS
	// =========================================================================

	/**
	 * Draft the next DRAFT_CHUNK proposals for a check group.
	 *
	 * @param int    $run_id   Crawl run id.
	 * @param string $check_id Check id.
	 * @param int    $offset   Cursor into the group's fixable rows.
	 * @return array {drafted: array, remaining: int, errors: array} or {error, http_status}.
	 */
	public function do_draft_chunk( int $run_id, string $check_id, int $offset ): array {
		$fixer = SWPS_Crawl_Fixer_Registry::for_check( $check_id );
		if ( ! $fixer || 'draft' !== $fixer->kind() ) {
			return array(
				'error'       => __( 'This check has no draft fix.', 'stratawp-seo' ),
				'http_status' => 400,
			);
		}

		$limiter = new SWPS_Rate_Limiter();
		if ( 0 === $offset && ! $limiter->can_generate() ) {
			return array(
				'error'       => sprintf(
					/* translators: %d: seconds until the rate limit clears */
					__( 'Rate limited — try again in %d seconds.', 'stratawp-seo' ),
					$limiter->get_remaining_seconds()
				),
				'http_status' => 429,
				'retry_after' => $limiter->get_remaining_seconds(),
			);
		}

		$rows      = $this->fixable_rows( $run_id, $check_id, $fixer );
		$partition = self::partition_rows( $rows, $offset, self::DRAFT_CHUNK );

		$drafted = array();
		$errors  = array();
		foreach ( $partition['batch'] as $issue ) {
			$out = $fixer->draft( $issue );
			if ( is_wp_error( $out ) ) {
				$errors[] = array(
					'issue_id' => (int) $issue['id'],
					'url'      => (string) $issue['url'],
					'reason'   => $out->get_error_message(),
				);
				continue;
			}
			$drafted[] = array(
				'issue_id' => (int) $issue['id'],
				'url'      => (string) $issue['url'],
				'current'  => (string) $out['current'],
				'proposed' => (string) $out['proposed'],
			);
		}

		// Lock only once the whole drafting session has finished — a
		// per-chunk lock stranded chunk 2+ of any multi-chunk session with
		// a permanent 429 (the button had no path to recovery).
		if ( 0 === $partition['remaining'] ) {
			$limiter->lock();
		}

		return array(
			'drafted'   => $drafted,
			'errors'    => $errors,
			'remaining' => $partition['remaining'],
		);
	}

	/**
	 * Apply accepted issue rows for a group (drafts or mechanical), capped
	 * at APPLY_CHUNK per call; the JS loops on remaining ids.
	 *
	 * @param int    $run_id    Crawl run id.
	 * @param string $check_id  Check id.
	 * @param int[]  $issue_ids Accepted issue row ids.
	 * @return array {fixed, skipped, remaining_ids} or {error, http_status}.
	 */
	public function do_apply( int $run_id, string $check_id, array $issue_ids ): array {
		$fixer = SWPS_Crawl_Fixer_Registry::for_check( $check_id );
		if ( ! $fixer ) {
			return array(
				'error'       => __( 'This check has no fix.', 'stratawp-seo' ),
				'http_status' => 400,
			);
		}

		$by_id = array();
		foreach ( $this->fixable_rows( $run_id, $check_id, $fixer ) as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		$batch_ids = array_slice( $issue_ids, 0, self::APPLY_CHUNK );
		$fixed     = array();
		$skipped   = array();

		foreach ( $batch_ids as $issue_id ) {
			$issue = $by_id[ $issue_id ] ?? null;
			if ( ! $issue ) {
				$skipped[] = array(
					'issue_id' => $issue_id,
					'reason'   => __( 'Issue not found or not fixable.', 'stratawp-seo' ),
				);
				continue;
			}

			$out = $fixer->apply( $issue, array() );
			if ( is_wp_error( $out ) ) {
				$skipped[] = array(
					'issue_id' => $issue_id,
					'reason'   => $out->get_error_message(),
				);
				continue;
			}
			if ( ! empty( $out['changed'] ) ) {
				SWPS_Crawl_Issues::mark_fixed( $issue_id );
				$fixed[] = array(
					'issue_id' => $issue_id,
					'message'  => (string) $out['message'],
				);
			} else {
				$skipped[] = array(
					'issue_id' => $issue_id,
					'reason'   => (string) $out['message'],
				);
			}
		}

		return array(
			'fixed'         => $fixed,
			'skipped'       => $skipped,
			'remaining_ids' => array_values( array_slice( $issue_ids, self::APPLY_CHUNK ) ),
		);
	}

	/**
	 * Undo one fix.
	 *
	 * @param int $run_id   Crawl run id.
	 * @param int $issue_id Issue row id.
	 * @return array {undone: bool} or {error, http_status}.
	 */
	public function do_undo( int $run_id, int $issue_id ): array {
		$issue = $this->row_by_id( $run_id, $issue_id );
		if ( ! $issue ) {
			return array(
				'error'       => __( 'Issue not found.', 'stratawp-seo' ),
				'http_status' => 404,
			);
		}

		$fixer = SWPS_Crawl_Fixer_Registry::for_check( (string) $issue['type'] );
		if ( ! $fixer ) {
			return array(
				'error'       => __( 'This check has no fix.', 'stratawp-seo' ),
				'http_status' => 400,
			);
		}

		$undone = $fixer->undo( $issue );
		if ( $undone ) {
			SWPS_Crawl_Issues::unmark_fixed( $issue_id );
		}

		return array( 'undone' => $undone );
	}

	/**
	 * Discard the stored draft behind one issue row.
	 *
	 * @param int $run_id   Crawl run id.
	 * @param int $issue_id Issue row id.
	 * @return array {dismissed: bool} or {error, http_status}.
	 */
	public function do_dismiss( int $run_id, int $issue_id ): array {
		$issue = $this->row_by_id( $run_id, $issue_id );
		if ( ! $issue ) {
			return array(
				'error'       => __( 'Issue not found.', 'stratawp-seo' ),
				'http_status' => 404,
			);
		}

		$field = $this->field_for_check( (string) $issue['type'] );
		if ( null !== $field ) {
			SWPS_Fixit_Store::remove_draft( (string) $issue['object_type'], (int) $issue['object_id'], $field );
		}

		return array( 'dismissed' => true );
	}

	// =========================================================================
	// SCREEN SUPPORT
	// =========================================================================

	/**
	 * Stored drafts for a group's rows, keyed by issue id — used by the
	 * Site Audit screen to render the review table on page load.
	 *
	 * @param int    $run_id   Crawl run id.
	 * @param string $check_id Check id.
	 * @param array  $rows     The group's decoded rows.
	 * @return array issue_id => {current, proposed}
	 */
	public function drafts_for_group( int $run_id, string $check_id, array $rows ): array {
		$field = $this->field_for_check( $check_id );
		if ( null === $field ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$otype = (string) ( $row['object_type'] ?? '' );
			$oid   = (int) ( $row['object_id'] ?? 0 );
			if ( '' === $otype || 'none' === $otype || 0 === $oid ) {
				continue;
			}
			$drafts = SWPS_Fixit_Store::get_drafts( $otype, $oid );
			if ( isset( $drafts[ $field ] ) && $drafts[ $field ]['check_id'] === $check_id ) {
				$out[ (int) $row['id'] ] = array(
					'current'  => $drafts[ $field ]['current'],
					'proposed' => $drafts[ $field ]['proposed'],
				);
			}
		}

		return $out;
	}

	/**
	 * Weekly sweep: delete drafts older than DRAFT_TTL across posts and
	 * terms (direct meta query on the two meta tables).
	 */
	public function sweep_drafts(): void {
		global $wpdb;

		$cutoff = time() - self::DRAFT_TTL;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( array(
			array( $wpdb->postmeta, 'post_id', 'delete_post_meta' ),
			array( $wpdb->termmeta, 'term_id', 'delete_term_meta' ),
		) as list( $table, $id_col, $deleter ) ) {
			$ids = (array) $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT {$id_col} FROM {$table} WHERE meta_key = %s",
					SWPS_Fixit_Store::META_DRAFTS
				)
			);
			foreach ( $ids as $id ) {
				$otype  = 'delete_term_meta' === $deleter ? 'term' : 'post';
				$drafts = SWPS_Fixit_Store::get_drafts( $otype, (int) $id );
				$kept   = array_filter( $drafts, static fn( $d ) => $d['drafted_at'] >= $cutoff );
				if ( count( $kept ) === count( $drafts ) ) {
					continue;
				}
				call_user_func( $deleter, (int) $id, SWPS_Fixit_Store::META_DRAFTS );
				foreach ( $kept as $field => $draft ) {
					SWPS_Fixit_Store::put_draft( $otype, (int) $id, (string) $field, $draft );
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// =========================================================================
	// INTERNALS
	// =========================================================================

	/**
	 * A group's rows that the fixer says it can fix.
	 *
	 * @param int              $run_id   Crawl run id.
	 * @param string           $check_id Check id.
	 * @param SWPS_Crawl_Fixer $fixer    Fixer for the check.
	 * @return array
	 */
	private function fixable_rows( int $run_id, string $check_id, SWPS_Crawl_Fixer $fixer ): array {
		$groups = SWPS_Crawl_Issues::issues_for_run( $run_id );
		$rows   = (array) ( $groups[ $check_id ] ?? array() );

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $fixer ) {
					return empty( $row['detail']['fixed_at'] ) && $fixer->can_fix( (array) $row );
				}
			)
		);
	}

	/**
	 * One decoded row by issue id.
	 *
	 * @param int $run_id   Crawl run id.
	 * @param int $issue_id Issue row id.
	 * @return array|null
	 */
	private function row_by_id( int $run_id, int $issue_id ): ?array {
		foreach ( SWPS_Crawl_Issues::issues_for_run( $run_id ) as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $issue_id ) {
					return (array) $row;
				}
			}
		}
		return null;
	}

	/**
	 * Draft-store field for a draft-kind check id, null for mechanical.
	 *
	 * @param string $check_id Check id.
	 * @return string|null
	 */
	private function field_for_check( string $check_id ): ?string {
		$map = array(
			'missing_title'              => 'meta_title',
			'title_too_long'             => 'meta_title',
			'title_too_short'            => 'meta_title',
			'duplicate_title'            => 'meta_title',
			'missing_meta_description'   => 'meta_description',
			'desc_too_long'              => 'meta_description',
			'duplicate_meta_description' => 'meta_description',
		);
		return $map[ $check_id ] ?? null;
	}
}
