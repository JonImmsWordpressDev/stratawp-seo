<?php
/**
 * AEO Scorer orchestrator — combines 4 sub-scorers (Extractability,
 * Markup, Authority, Coverage) into a single weighted score, applies
 * the swps_aeo_subscores + swps_aeo_score filters, and persists results
 * to post meta when scoring a WP post.
 *
 * Default weights (sum to 1.0):
 *   - extractability : 0.30
 *   - markup         : 0.30
 *   - authority      : 0.20
 *   - coverage       : 0.20
 *
 * Weights re-normalize at runtime if Coverage is null (lazy or disabled).
 * Coverage is cached as post meta with content-hash invalidation.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Scorer {

	public const META_TOTAL           = '_swps_aeo_score';
	public const META_SUBSCORE_PREFIX = '_swps_aeo_subscore_';
	public const META_LAST_SCAN       = '_swps_aeo_last_scan';
	public const META_CONTENT_HASH    = '_swps_aeo_content_hash';

	public const OPTION_THRESHOLD        = 'swps_aeo_threshold';
	public const OPTION_WEIGHTS          = 'swps_aeo_weights';
	public const OPTION_COVERAGE_ENABLED = 'swps_aeo_coverage_enabled';

	public const DEFAULT_THRESHOLD = 70;
	public const DEFAULT_WEIGHTS   = array(
		'extractability' => 0.30,
		'markup'         => 0.30,
		'authority'      => 0.20,
		'coverage'       => 0.20,
	);

	private SWPS_AEO_Extractability_Scorer $extractability;
	private SWPS_AEO_Markup_Scorer         $markup;
	private SWPS_AEO_Authority_Scorer      $authority;
	private SWPS_AEO_Coverage_Scorer       $coverage;

	public function __construct(
		SWPS_AEO_Extractability_Scorer $extractability,
		SWPS_AEO_Markup_Scorer $markup,
		SWPS_AEO_Authority_Scorer $authority,
		SWPS_AEO_Coverage_Scorer $coverage
	) {
		$this->extractability = $extractability;
		$this->markup         = $markup;
		$this->authority      = $authority;
		$this->coverage       = $coverage;
	}

	/**
	 * Score raw HTML against the 4 sub-scorers and return a weighted total.
	 *
	 * @param string                    $html    Raw post HTML.
	 * @param array<string, mixed>      $ctx     Context: title, author, published_unix, modified_unix, word_count, existing_schema, coverage_cached (?int).
	 * @param array<string, float>|null $weights Optional per-dimension weights override (else loads from option).
	 * @return array{total:int, subscores: array{extractability:int, markup:int, authority:int, coverage:?int}}
	 */
	public function score_html( string $html, array $ctx, ?array $weights = null ): array {
		$subscores = array(
			'extractability' => $this->extractability->score( $html ),
			'markup'         => $this->markup->score( $html, array(
				'existing_schema' => $ctx['existing_schema'] ?? null,
				'title'           => $ctx['title']           ?? '',
			) ),
			'authority'      => $this->authority->score( $html, array(
				'author'         => $ctx['author']         ?? '',
				'published_unix' => $ctx['published_unix'] ?? 0,
				'modified_unix'  => $ctx['modified_unix']  ?? 0,
				'word_count'     => $ctx['word_count']     ?? 0,
			) ),
			'coverage'       => array_key_exists( 'coverage_cached', $ctx ) && null !== $ctx['coverage_cached']
								? (int) $ctx['coverage_cached']
								: null,
		);

		if ( function_exists( 'apply_filters' ) ) {
			$subscores = (array) apply_filters( 'swps_aeo_subscores', $subscores, $ctx );
		}

		$weights = $weights ?? $this->load_weights();
		$total   = $this->compute_total( $subscores, $weights );

		if ( function_exists( 'apply_filters' ) ) {
			$total = (int) apply_filters( 'swps_aeo_score', $total, $ctx, $subscores );
		}

		return array(
			'total'     => $total,
			'subscores' => $subscores,
		);
	}

	/**
	 * Score a WP post by ID. Persists results to post meta.
	 *
	 * @param int                       $post_id WP post ID.
	 * @param array<string, float>|null $weights Optional weight override.
	 * @return array{total:int, subscores: array{extractability:int, markup:int, authority:int, coverage:?int}}
	 */
	public function score_post( int $post_id, ?array $weights = null ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array(
				'total'     => 0,
				'subscores' => array(
					'extractability' => 0,
					'markup'         => 0,
					'authority'      => 0,
					'coverage'       => null,
				),
			);
		}

		$ctx = array(
			'title'           => $post->post_title,
			'author'          => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'published_unix'  => (int) strtotime( $post->post_date_gmt ),
			'modified_unix'   => (int) strtotime( $post->post_modified_gmt ),
			'word_count'      => str_word_count( wp_strip_all_tags( $post->post_content ) ),
			'existing_schema' => $this->detect_existing_schema( $post ),
			'coverage_cached' => $this->load_cached_coverage( $post_id, $post->post_content ),
		);

		$result = $this->score_html( $post->post_content, $ctx, $weights );

		update_post_meta( $post_id, self::META_TOTAL, $result['total'] );
		foreach ( $result['subscores'] as $k => $v ) {
			if ( null === $v ) {
				delete_post_meta( $post_id, self::META_SUBSCORE_PREFIX . $k );
			} else {
				update_post_meta( $post_id, self::META_SUBSCORE_PREFIX . $k, $v );
			}
		}
		update_post_meta( $post_id, self::META_LAST_SCAN, time() );

		return $result;
	}

	/**
	 * Compute weighted total. Skips null sub-scores and re-normalizes their weight away.
	 *
	 * @param array<string, int|null> $subscores
	 * @param array<string, float>    $weights
	 */
	private function compute_total( array $subscores, array $weights ): int {
		$w_sum    = 0.0;
		$weighted = 0.0;
		foreach ( $subscores as $dim => $val ) {
			if ( null === $val ) {
				continue;
			}
			$w        = (float) ( $weights[ $dim ] ?? 0 );
			$weighted += $val * $w;
			$w_sum    += $w;
		}
		if ( $w_sum <= 0 ) {
			return 0;
		}
		return (int) round( $weighted / $w_sum );
	}

	/** @return array<string, float> */
	private function load_weights(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return self::DEFAULT_WEIGHTS;
		}
		return array_merge( self::DEFAULT_WEIGHTS, (array) get_option( self::OPTION_WEIGHTS, self::DEFAULT_WEIGHTS ) );
	}

	/** Best-effort existing-schema detection from post meta or content scan. */
	private function detect_existing_schema( WP_Post $post ): ?string {
		$aeo_type = get_post_meta( $post->ID, '_swps_aeo_schema_type', true );
		if ( ! empty( $aeo_type ) ) {
			return ucfirst( (string) $aeo_type );
		}
		if ( false !== stripos( $post->post_content, 'FAQPage' ) ) {
			return 'FAQPage';
		}
		return null;
	}

	private function load_cached_coverage( int $post_id, string $content ): ?int {
		$cached = get_post_meta( $post_id, '_swps_aeo_subscore_coverage', true );
		if ( '' === $cached ) {
			return null;
		}
		$hash = get_post_meta( $post_id, self::META_CONTENT_HASH, true );
		if ( $hash !== md5( $content ) ) {
			return null;
		}
		return (int) $cached;
	}
}
