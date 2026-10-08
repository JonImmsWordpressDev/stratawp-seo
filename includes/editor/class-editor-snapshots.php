<?php
/**
 * Proof snapshots: remember which fixes were applied to a post and what its
 * score and search numbers were when it was next saved while published.
 * Storage only; reporting is a separate feature.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Snapshots {

	public const META_PENDING   = '_swps_pending_fixes';
	public const META_SNAPSHOTS = '_swps_editor_snapshots';
	public const CAP            = 20;

	private SWPS_Search_Console $gsc;

	public function __construct( SWPS_Search_Console $gsc ) {
		$this->gsc = $gsc;
		add_action( 'init', array( $this, 'register' ), 30 );
	}

	// ---------------------------------------------------------------------
	// Pure helpers (unit tested without WordPress).
	// ---------------------------------------------------------------------

	/**
	 * @param mixed $existing Stored list.
	 * @return string[]
	 */
	public static function add_pending( $existing, string $check_id ): array {
		$list = array();
		if ( is_array( $existing ) ) {
			foreach ( $existing as $id ) {
				if ( is_string( $id ) && '' !== $id ) {
					$list[] = $id;
				}
			}
		}
		if ( ! in_array( $check_id, $list, true ) ) {
			$list[] = $check_id;
		}
		return array_slice( $list, -self::CAP );
	}

	/**
	 * @param string[]                                           $fixes Applied check ids.
	 * @param array{clicks:int,impressions:int,position:float}|null $gsc  Search Console numbers.
	 * @return array<string,mixed>
	 */
	public static function build( int $score, array $fixes, ?array $gsc, ?int $aeo, int $time ): array {
		return array(
			'time'  => $time,
			'score' => $score,
			'fixes' => array_values( array_unique( $fixes ) ),
			'aeo'   => $aeo,
			'gsc'   => $gsc,
		);
	}

	/**
	 * @param mixed                $existing Stored snapshots.
	 * @param array<string,mixed>  $snapshot New snapshot.
	 * @return array<int,array<string,mixed>>
	 */
	public static function append( $existing, array $snapshot, int $cap = self::CAP ): array {
		$list   = is_array( $existing ) ? array_values( $existing ) : array();
		$list[] = $snapshot;
		return array_slice( $list, -$cap );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows    Search Console page-query rows.
	 * @return array{clicks:int,impressions:int,position:float}|null
	 */
	public static function pick_keyword_row( array $rows, string $keyword ): ?array {
		$needle = mb_strtolower( trim( $keyword ) );
		foreach ( $rows as $row ) {
			if ( mb_strtolower( (string) ( $row['keys'][0] ?? '' ) ) === $needle && '' !== $needle ) {
				return array(
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				);
			}
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// WordPress wiring.
	// ---------------------------------------------------------------------

	public function register(): void {
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
			add_action( "rest_after_insert_{$type}", array( $this, 'maybe_snapshot' ), 20, 3 );
		}
	}

	/**
	 * @param WP_Post         $post     Saved post.
	 * @param WP_REST_Request $request  Request.
	 * @param bool            $creating Whether the post was just created.
	 */
	public function maybe_snapshot( $post, $request, $creating ): void {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		$pending = get_post_meta( $post->ID, self::META_PENDING, true );
		if ( ! is_array( $pending ) || ! $pending ) {
			return;
		}

		try {
			$input = SWPS_Editor_Input::for_post( $post );
			$score = SWPS_Editor_Check_Engine::score( SWPS_Editor_Check_Engine::run( $input ) );
			$focus = (string) $input['keywords'][0]['keyword'];

			$gsc = null;
			if ( '' !== $focus && $this->gsc->is_connected() ) {
				$url = get_permalink( $post );
				if ( $url ) {
					$gsc = self::pick_keyword_row( $this->gsc->get_page_queries( $url, 28 ), $focus );
				}
			}

			$aeo_total = get_post_meta( $post->ID, SWPS_AEO_Scorer::META_TOTAL, true );
			$snapshot  = self::build( $score['overall'], $pending, $gsc, '' === $aeo_total ? null : (int) $aeo_total, time() );

			update_post_meta(
				$post->ID,
				self::META_SNAPSHOTS,
				self::append( get_post_meta( $post->ID, self::META_SNAPSHOTS, true ), $snapshot )
			);
			delete_post_meta( $post->ID, self::META_PENDING );
		} catch ( \Throwable $e ) {
			// Proof is best effort; never break the writer's save. Pending fixes stay for the next save.
			do_action( 'swps_snapshot_failed', $post, $e );
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'StrataWP SEO: proof snapshot failed for post %d: %s', $post->ID, $e->getMessage() ) );
			}
			return;
		}
	}
}
