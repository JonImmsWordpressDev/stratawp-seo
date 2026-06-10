<?php
/**
 * Weekly digest email — data assembly, cron scheduling, failure listener.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the weekly digest email: failure tracking, data assembly, send pipeline.
 */
class SWPS_Digest {

	// ---- Option / hook name constants ----

	public const OPTION_ENABLED    = 'swps_digest_enabled';
	public const OPTION_FREQUENCY  = 'swps_digest_frequency'; // Values: daily, weekly, monthly.
	public const OPTION_RECIPIENTS = 'swps_digest_recipients';
	public const OPTION_LOGO       = 'swps_digest_logo';
	public const OPTION_ACCENT     = 'swps_digest_accent';
	public const OPTION_FOOTER     = 'swps_digest_footer';
	public const OPTION_AI_SUMMARY = 'swps_digest_ai_summary';
	public const OPTION_FAILURES   = 'swps_generation_failures';
	public const OPTION_AEO_SNAP   = 'swps_digest_aeo_snapshot';
	public const OPTION_LAST_SENT  = 'swps_digest_last_sent';
	public const CRON_HOOK         = 'swps_send_digest';
	private const MAX_FAILURES     = 20;

	// ---- Constructor ----

	/**
	 * Register action hooks: generation-failure listener and cron send.
	 * Settings and test-send hooks are added in Task 3.
	 */
	public function __construct() {
		add_action( 'swps_generation_failed', array( $this, 'record_failure' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $this, 'send' ) );
	}

	// ---- Failure listener ----

	/**
	 * Persist a generation failure to the rolling option store.
	 *
	 * Fires on `swps_generation_failed` with args (WP_Error $error, string $topic, string $template).
	 *
	 * @param WP_Error $error    The error object from the failed generation.
	 * @param string   $topic    The topic/title being generated.
	 * @param string   $template The template slug used.
	 * @return void
	 */
	public function record_failure( WP_Error $error, string $topic, string $template ): void {
		$entry = array(
			'time'     => time(),
			'code'     => $error->get_error_code(),
			'message'  => $error->get_error_message(),
			'topic'    => $topic,
			'template' => $template,
		);

		$failures = get_option( self::OPTION_FAILURES, array() );
		if ( ! is_array( $failures ) ) {
			$failures = array();
		}
		array_unshift( $failures, $entry );
		$failures = array_slice( $failures, 0, self::MAX_FAILURES );
		update_option( self::OPTION_FAILURES, $failures, false );
	}

	// ---- Data assembly ----

	/**
	 * Assemble all digest sections for the given time window.
	 *
	 * Each section uses a fail-soft private getter; null sections are omitted from
	 * the returned array. Callers should treat a missing key as "no data".
	 *
	 * @param int $since Unix timestamp — only include data generated after this time.
	 * @return array<string, mixed> Keyed sections with non-null data.
	 */
	public function collect_data( int $since ): array {
		$frequency = get_option( self::OPTION_FREQUENCY, 'weekly' );
		$days      = 'daily' === $frequency ? 1 : ( 'monthly' === $frequency ? 30 : 7 );

		$sections = array(
			'generated'   => $this->get_generated( $since ),
			'failures'    => $this->get_failures( $since ),
			'keywords'    => $this->get_keywords( $since ),
			'backlinks'   => $this->get_backlinks(),
			'competitors' => $this->get_competitors(),
			'bots'        => $this->get_bots( $days ),
			'spend'       => $this->get_spend(),
			'aeo_movers'  => $this->get_aeo_movers(),
		);

		// Drop null sections; build attention last from what survived.
		$data              = array_filter( $sections, static fn( $v ) => null !== $v );
		$data['attention'] = $this->build_attention( $data );

		return $data;
	}

	// ---- Private fail-soft getters ----

	/**
	 * Posts generated since $since with _swps_tokens meta.
	 *
	 * @param int $since Unix timestamp.
	 * @return array<int, array<string, mixed>>|null
	 */
	private function get_generated( int $since ): ?array {
		try {
			global $wpdb;
			$since_date = gmdate( 'Y-m-d H:i:s', $since );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_swps_tokens'
					WHERE p.post_status IN ('publish','draft')
					AND p.post_modified_gmt >= %s
					ORDER BY p.post_modified_gmt DESC
					LIMIT 50",
					$since_date
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				return null;
			}
			$generated = array();
			foreach ( $rows as $row ) {
				$post_id     = (int) $row['ID'];
				$seo_score   = get_post_meta( $post_id, '_swps_seo_score', true );
				$aeo_score   = get_post_meta( $post_id, '_swps_aeo_score', true );
				$cost        = get_post_meta( $post_id, '_swps_cost', true );
				$generated[] = array(
					'post_id'   => $post_id,
					'title'     => $row['post_title'],
					'edit_link' => get_edit_post_link( $post_id, 'raw' ),
					'seo_score' => $seo_score ? $seo_score : null,
					'aeo_score' => $aeo_score ? $aeo_score : null,
					'cost'      => $cost ? $cost : null,
				);
			}
			return $generated;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Generation failures newer than $since (also prunes old ones).
	 *
	 * @param int $since Unix timestamp.
	 * @return array<int, array<string, mixed>>|null
	 */
	private function get_failures( int $since ): ?array {
		try {
			$failures = get_option( self::OPTION_FAILURES, array() );
			if ( ! is_array( $failures ) ) {
				return null;
			}
			$recent = array_values(
				array_filter(
					$failures,
					static fn( $f ) => isset( $f['time'] ) && (int) $f['time'] >= $since
				)
			);
			$pruned = array_values(
				array_filter(
					$failures,
					static fn( $f ) => isset( $f['time'] ) && (int) $f['time'] >= ( $since - WEEK_IN_SECONDS * 4 )
				)
			);
			if ( count( $pruned ) < count( $failures ) ) {
				update_option( self::OPTION_FAILURES, $pruned, false );
			}
			return empty( $recent ) ? null : $recent;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Keyword winners and losers for the period.
	 *
	 * Queries wp_swps_keyword_tracking directly: for each keyword, compares the
	 * latest position to the position closest to $since (7 days prior for weekly).
	 *
	 * @param int $since Unix timestamp.
	 * @return array{winners: array<string, float>, losers: array<string, float>}|null
	 */
	private function get_keywords( int $since ): ?array {
		try {
			global $wpdb;
			$table      = $wpdb->prefix . 'swps_keyword_tracking';
			$since_date = gmdate( 'Y-m-d', $since );
			// Table name is built from $wpdb->prefix + a fixed literal — safe to interpolate.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT keyword,
					MAX(CASE WHEN date > %s THEN position END) AS new_pos,
					MAX(CASE WHEN date <= %s THEN position END) AS old_pos
					FROM {$table}
					GROUP BY keyword
					HAVING new_pos IS NOT NULL OR old_pos IS NOT NULL",
					$since_date,
					$since_date
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $rows ) ) {
				return null;
			}
			$pairs = array();
			foreach ( $rows as $row ) {
				$pairs[ $row['keyword'] ] = array(
					null !== $row['old_pos'] ? (float) $row['old_pos'] : null,
					null !== $row['new_pos'] ? (float) $row['new_pos'] : null,
				);
			}
			$result = self::keyword_deltas( $pairs );
			if ( empty( $result['winners'] ) && empty( $result['losers'] ) ) {
				return null;
			}
			return $result;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Backlink stats from the singleton.
	 *
	 * Keys: total, live, lost, broken, pending, new_30d, lost_30d, unique_domains.
	 *
	 * @return array<string, int>|null
	 */
	private function get_backlinks(): ?array {
		try {
			$stats = stratawp_seo()->backlinks->get_stats();
			return ! empty( $stats ) ? $stats : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Competitor dashboard summary from the singleton.
	 *
	 * @return array<int, mixed>|null
	 */
	private function get_competitors(): ?array {
		try {
			$summary = stratawp_seo()->competitors->get_dashboard_summary( 3 );
			return ! empty( $summary ) ? $summary : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * AI-bot summary from the singleton.
	 *
	 * @param int $days Number of days to include.
	 * @return array<string, mixed>|null
	 */
	private function get_bots( int $days ): ?array {
		try {
			$summary = stratawp_seo()->bot_analytics_tracker->get_bot_summary( $days );
			return ! empty( $summary ) ? $summary : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Cost tracker stats + guardian budget status.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_spend(): ?array {
		try {
			$monthly  = stratawp_seo()->cost_tracker->get_monthly_stats();
			$guardian = SWPS_Autopilot_Guardian::get_status();
			return array_merge( $monthly, array( 'guardian' => $guardian ) );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * AEO score movers since last snapshot.
	 *
	 * Reads current _swps_aeo_score postmeta, diffs against OPTION_AEO_SNAP,
	 * then writes the new snapshot for next time.
	 *
	 * @return array{risers: array<int, int>, fallers: array<int, int>}|null
	 */
	private function get_aeo_movers(): ?array {
		try {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows    = $wpdb->get_results(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				WHERE meta_key = '_swps_aeo_score'
				LIMIT 500",
				ARRAY_A
			);
			$current = array();
			foreach ( $rows as $row ) {
				$current[ (int) $row['post_id'] ] = (int) $row['meta_value'];
			}
			$previous = get_option( self::OPTION_AEO_SNAP, array() );
			if ( ! is_array( $previous ) ) {
				$previous = array();
			}
			update_option( self::OPTION_AEO_SNAP, $current, false );
			if ( empty( $previous ) ) {
				return null;
			}
			$movers = self::compute_movers( $previous, $current );
			if ( empty( $movers['risers'] ) && empty( $movers['fallers'] ) ) {
				return null;
			}
			return $movers;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Build the attention/triage section from already-assembled data.
	 *
	 * @param array<string, mixed> $data Assembled sections.
	 * @return array<int, string> Items needing attention (empty = all good).
	 */
	private function build_attention( array $data ): array {
		$items = array();

		// Recent generation failures.
		if ( ! empty( $data['failures'] ) ) {
			$count   = count( $data['failures'] );
			$items[] = sprintf( '%d generation failure%s since last digest', $count, 1 === $count ? '' : 's' );
		}

		// Broken backlinks.
		if ( isset( $data['backlinks']['broken'] ) && $data['backlinks']['broken'] > 0 ) {
			$items[] = sprintf( '%d broken backlink%s', $data['backlinks']['broken'], 1 === $data['backlinks']['broken'] ? '' : 's' );
		}

		// Autopilot last-run failures.
		if ( isset( $data['spend']['guardian']['last_run']['failed'] ) && (int) $data['spend']['guardian']['last_run']['failed'] > 0 ) {
			$count = (int) $data['spend']['guardian']['last_run']['failed'];
			/* translators: %d: failed count */
			$items[] = sprintf( _n( 'Autopilot: %d post failed to generate in the last run', 'Autopilot: %d posts failed to generate in the last run', $count, 'stratawp-seo' ), $count );
		}

		// Budget warnings from autopilot guardian.
		if ( isset( $data['spend']['guardian']['budget_state'] ) ) {
			$state = $data['spend']['guardian']['budget_state'];
			if ( 'warning' === $state ) {
				$items[] = 'AI spend approaching budget limit';
			} elseif ( 'exceeded' === $state ) {
				$items[] = 'AI spend has exceeded the configured budget';
			}
		}

		return $items;
	}

	// ---- Static pure helpers ----

	/**
	 * Split a comma/space-separated recipients string into valid emails.
	 *
	 * @param string $raw Raw setting value.
	 * @return string[] Deduplicated valid emails (may be empty).
	 */
	public static function parse_recipients( string $raw ): array {
		$parts  = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$emails = array();
		foreach ( $parts as $part ) {
			$email = filter_var( $part, FILTER_VALIDATE_EMAIL );
			if ( false !== $email ) {
				$emails[ strtolower( $email ) ] = $email;
			}
		}
		return array_values( $emails );
	}

	/**
	 * Diff two post_id => score maps into top movers.
	 *
	 * @param array $previous post_id => int score at last send.
	 * @param array $current  post_id => int score now.
	 * @param int   $limit    Max movers per direction.
	 * @return array{risers: array<int, int>, fallers: array<int, int>} post_id => delta.
	 */
	public static function compute_movers( array $previous, array $current, int $limit = 5 ): array {
		$deltas = array();
		foreach ( $current as $post_id => $score ) {
			if ( isset( $previous[ $post_id ] ) && (int) $previous[ $post_id ] !== (int) $score ) {
				$deltas[ $post_id ] = (int) $score - (int) $previous[ $post_id ];
			}
		}
		arsort( $deltas );
		$risers = array_slice( array_filter( $deltas, static fn( $d ) => $d > 0 ), 0, $limit, true );
		asort( $deltas );
		$fallers = array_slice( array_filter( $deltas, static fn( $d ) => $d < 0 ), 0, $limit, true );
		return array(
			'risers'  => $risers,
			'fallers' => $fallers,
		);
	}

	/**
	 * Compute keyword winners/losers from (keyword => [old_position, new_position]) pairs.
	 * Lower position = better. Null positions are skipped.
	 *
	 * @param array $pairs keyword => array{0: float|null, 1: float|null}.
	 * @param int   $limit Max per direction.
	 * @return array{winners: array<string, float>, losers: array<string, float>} keyword => signed delta (negative = improved).
	 */
	public static function keyword_deltas( array $pairs, int $limit = 5 ): array {
		$deltas = array();
		foreach ( $pairs as $keyword => $pair ) {
			if ( ! isset( $pair[0], $pair[1] ) ) {
				continue;
			}
			$delta = (float) $pair[1] - (float) $pair[0];
			if ( 0.0 !== $delta ) {
				$deltas[ $keyword ] = $delta;
			}
		}
		asort( $deltas ); // Most negative (biggest improvement) first.
		$winners = array_slice( array_filter( $deltas, static fn( $d ) => $d < 0 ), 0, $limit, true );
		arsort( $deltas );
		$losers = array_slice( array_filter( $deltas, static fn( $d ) => $d > 0 ), 0, $limit, true );
		return array(
			'winners' => $winners,
			'losers'  => $losers,
		);
	}
}
