<?php
/**
 * Content Decay Watchdog — weekly GSC scan, per-post decay detection,
 * cause classification, cooldown meta, and summary email alert.
 *
 * Pure static helpers (assess / staleness_flags) are unit-tested with no
 * WordPress dependency. The cron handler and settings registration require WP.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decay detection, cause classification, and weekly scan cron.
 */
class SWPS_Decay_Watchdog {

	public const CRON_HOOK    = 'swps_decay_scan';
	public const META_DECAY   = '_swps_decay';
	public const META_FLAGGED = '_swps_decay_flagged';

	/** Cooldown window: skip re-flagging a post within this many seconds (28 days). */
	private const COOLDOWN = 2419200; // 28 * DAY_IN_SECONDS.

	/** Default click-decline threshold (fraction). */
	private const DEFAULT_THRESHOLD = 0.20;

	/** Default minimum prior-window impressions before a post is evaluated. */
	private const DEFAULT_MIN_IMPRESSIONS = 200;

	/** Recent window: last N days. */
	private const WINDOW_RECENT = 28;

	/** Prior window offset from "today − 1": prior starts here many days back (29–56d ago). */
	private const WINDOW_PRIOR_START = 56;

	/**
	 * GSC client.
	 *
	 * @var SWPS_Search_Console
	 */
	private SWPS_Search_Console $search_console;

	/**
	 * Metric history store.
	 *
	 * @var SWPS_Metric_History
	 */
	private SWPS_Metric_History $history;

	/**
	 * Wire cron action and register settings on admin_init.
	 *
	 * @param SWPS_Search_Console $search_console GSC client.
	 * @param SWPS_Metric_History $history        Metric history store.
	 */
	public function __construct(
		SWPS_Search_Console $search_console,
		SWPS_Metric_History $history
	) {
		$this->search_console = $search_console;
		$this->history        = $history;

		add_action( self::CRON_HOOK, array( $this, 'run_scan' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	// --- Pure static helpers (unit-tested, no WP required) ---

	/**
	 * Assess decay verdict for one post given two 28-day GSC windows.
	 *
	 * @param array $recent {clicks: int, impressions: int, position: float, ctr: float}.
	 * @param array $prior  Same shape as $recent.
	 * @param array $opts   {threshold: float (default 0.20), min_impressions: int (default 200)}.
	 * @return array{decayed: bool, click_change: float, reason: string}
	 *   reason is '' when not decayed; one of position_drop | demand_drop | ctr_drop | unknown when decayed.
	 */
	public static function assess( array $recent, array $prior, array $opts = array() ): array {
		$threshold       = isset( $opts['threshold'] ) ? (float) $opts['threshold'] : self::DEFAULT_THRESHOLD;
		$min_impressions = isset( $opts['min_impressions'] ) ? (int) $opts['min_impressions'] : self::DEFAULT_MIN_IMPRESSIONS;

		$not_decayed = array(
			'decayed'      => false,
			'click_change' => 0.0,
			'reason'       => '',
		);

		// Sample floor: skip posts without meaningful prior traffic data.
		if ( (int) ( $prior['impressions'] ?? 0 ) < $min_impressions ) {
			return $not_decayed;
		}

		// Divide-safety: can't measure decline when prior had no clicks.
		if ( (int) ( $prior['clicks'] ?? 0 ) === 0 ) {
			return $not_decayed;
		}

		$prior_clicks  = (float) $prior['clicks'];
		$recent_clicks = (float) ( $recent['clicks'] ?? 0 );

		$click_change = ( $prior_clicks - $recent_clicks ) / $prior_clicks;

		if ( $click_change < $threshold ) {
			return $not_decayed;
		}

		// Post is decayed — classify cause.
		$reason = self::classify_cause( $recent, $prior, $threshold );

		return array(
			'decayed'      => true,
			'click_change' => $click_change,
			'reason'       => $reason,
		);
	}

	/**
	 * Classify the likely cause of a confirmed decay event.
	 *
	 * Called only when clicks have already declined ≥ threshold.
	 *
	 * @param array $recent See assess().
	 * @param array $prior  See assess().
	 * @param float $threshold Configured threshold fraction.
	 * @return string One of position_drop | demand_drop | ctr_drop | unknown.
	 */
	private static function classify_cause( array $recent, array $prior, float $threshold ): string {
		$recent_pos = (float) ( $recent['position'] ?? 0.0 );
		$prior_pos  = (float) ( $prior['position'] ?? 0.0 );
		$pos_delta  = $recent_pos - $prior_pos; // positive = worsened (higher number = worse rank).

		// Position worsened by ≥ 3 spots → most likely cause.
		if ( $pos_delta >= 3.0 ) {
			return 'position_drop';
		}

		// Position is stable (< 3 spots worse); check impressions for demand drop.
		$prior_imp  = (float) ( $prior['impressions'] ?? 1.0 );
		$recent_imp = (float) ( $recent['impressions'] ?? 0.0 );

		if ( $prior_imp > 0.0 ) {
			$imp_decline = ( $prior_imp - $recent_imp ) / $prior_imp;
			if ( $imp_decline >= $threshold ) {
				return 'demand_drop';
			}
		}

		// Impressions stable; check CTR at stable position.
		$prior_ctr  = (float) ( $prior['ctr'] ?? 0.0 );
		$recent_ctr = (float) ( $recent['ctr'] ?? 0.0 );

		if ( $prior_ctr > 0.0 ) {
			$ctr_decline = ( $prior_ctr - $recent_ctr ) / $prior_ctr;
			if ( $ctr_decline >= $threshold ) {
				return 'ctr_drop';
			}
		}

		return 'unknown';
	}

	/**
	 * Return an array of staleness flags for a post.
	 *
	 * Recomputes the authority-freshness rule inline (modified <12mo OR
	 * published <24mo = fresh) to avoid coupling to SWPS_AEO_Authority_Scorer.
	 *
	 * @param int    $published Unix timestamp of post publish date.
	 * @param int    $modified  Unix timestamp of post last-modified date.
	 * @param string $html      Post HTML content.
	 * @return string[] Zero or more of: 'not_fresh', 'no_current_year'.
	 */
	public static function staleness_flags( int $published, int $modified, string $html ): array {
		$flags = array();

		$now       = time();
		$twelve_mo = 12 * 30 * DAY_IN_SECONDS;
		$twoyr_mo  = 24 * 30 * DAY_IN_SECONDS;

		// not_fresh: modified > 12 months ago AND published > 24 months ago.
		if ( ( $now - $modified ) > $twelve_mo && ( $now - $published ) > $twoyr_mo ) {
			$flags[] = 'not_fresh';
		}

		// no_current_year: content contains no mention of the current year.
		$current_year = (string) gmdate( 'Y' );
		if ( false === strpos( $html, $current_year ) ) {
			$flags[] = 'no_current_year';
		}

		return $flags;
	}

	// --- Cron handler ---

	/**
	 * Weekly scan: fetch GSC page metrics for two windows, assess decay,
	 * write post meta, prune cleared posts, and fire alert email if needed.
	 */
	public function run_scan(): void {
		if ( ! $this->search_console->is_connected() ) {
			return;
		}

		$threshold       = (float) get_option( 'swps_decay_threshold', self::DEFAULT_THRESHOLD );
		$min_impressions = (int) get_option( 'swps_decay_min_impressions', self::DEFAULT_MIN_IMPRESSIONS );
		$opts            = array(
			'threshold'       => $threshold,
			'min_impressions' => $min_impressions,
		);

		// Build date windows.
		// GSC has a ~2-day data delay, so anchor to 3 days ago for safety.
		$anchor       = strtotime( '-3 days' );
		$recent_end   = gmdate( 'Y-m-d', $anchor );
		$recent_start = gmdate( 'Y-m-d', $anchor - ( self::WINDOW_RECENT * DAY_IN_SECONDS ) + DAY_IN_SECONDS );

		$prior_end   = gmdate( 'Y-m-d', $anchor - self::WINDOW_RECENT * DAY_IN_SECONDS );
		$prior_start = gmdate( 'Y-m-d', $anchor - self::WINDOW_PRIOR_START * DAY_IN_SECONDS + DAY_IN_SECONDS );

		$recent_rows = $this->search_console->get_page_metrics( $recent_start, $recent_end );
		$prior_rows  = $this->search_console->get_page_metrics( $prior_start, $prior_end );

		if ( empty( $recent_rows ) ) {
			return;
		}

		$today   = gmdate( 'Y-m-d' );
		$now     = time();
		$flagged = array();

		// Build prior index keyed by URL.
		$prior_index = array();
		foreach ( $prior_rows as $url => $data ) {
			$prior_index[ $url ] = $data;
		}

		// Collect bulk records for metric history.
		$bulk = array();

		foreach ( $recent_rows as $url => $data ) {
			$post_id = url_to_postid( $url );
			if ( ! $post_id ) {
				continue;
			}

			// Snapshot metrics to history.
			$bulk[] = array( $post_id, 'gsc_clicks',      $today, (float) $data['clicks'] );
			$bulk[] = array( $post_id, 'gsc_impressions',  $today, (float) $data['impressions'] );
			$bulk[] = array( $post_id, 'gsc_position',    $today, (float) $data['position'] );

			$aeo_score = (float) get_post_meta( $post_id, '_swps_aeo_score', true );
			$bulk[]    = array( $post_id, 'aeo_score', $today, $aeo_score );

			// Skip posts without prior data.
			if ( ! isset( $prior_index[ $url ] ) ) {
				continue;
			}

			$prior  = $prior_index[ $url ];
			$result = self::assess( $data, $prior, $opts );

			if ( $result['decayed'] ) {
				// Apply cooldown: skip posts flagged within the last 28 days.
				$last_flagged = (int) get_post_meta( $post_id, self::META_FLAGGED, true );
				if ( $last_flagged && ( $now - $last_flagged ) < self::COOLDOWN ) {
					continue;
				}

				// Compute staleness.
				$post      = get_post( $post_id );
				$pub_ts    = $post ? (int) strtotime( $post->post_date_gmt ) : 0;
				$mod_ts    = $post ? (int) strtotime( $post->post_modified_gmt ) : 0;
				$content   = $post ? (string) $post->post_content : '';
				$staleness = self::staleness_flags( $pub_ts, $mod_ts, $content );

				$payload = array(
					'reason'       => $result['reason'],
					'click_change' => $result['click_change'],
					'recent'       => $data,
					'prior'        => $prior,
					'staleness'    => $staleness,
					'flagged_at'   => $now,
				);

				update_post_meta( $post_id, self::META_DECAY, $payload );
				update_post_meta( $post_id, self::META_FLAGGED, $now );

				$flagged[ $post_id ] = array_merge( $payload, array( 'url' => $url ) );

			} else {
				// Clear decay meta for posts that have recovered.
				delete_post_meta( $post_id, self::META_DECAY );
			}
		}

		// Bulk-record metrics.
		if ( ! empty( $bulk ) ) {
			$this->history->bulk_record( $bulk );
		}

		// Prune history rows older than 400 days.
		$this->history->prune( 400 );

		// Fire alert email when flagged post is in the site's top-10 by recent clicks.
		if ( ! empty( $flagged ) ) {
			$this->maybe_send_alert( $flagged, $recent_rows );
		}
	}

	/**
	 * Send a summary alert email when a flagged post appears in the top-10
	 * by recent-window clicks.
	 *
	 * @param array $flagged      Map of post_id → decay payload (with url).
	 * @param array $recent_rows  Full recent GSC page-metrics indexed by URL.
	 */
	private function maybe_send_alert( array $flagged, array $recent_rows ): void {
		if ( ! (bool) get_option( 'swps_decay_email', 1 ) ) {
			return;
		}

		// Sort all recent URLs by clicks descending to find top-10.
		uasort(
			$recent_rows,
			static function ( $a, $b ) {
				return (int) ( $b['clicks'] ?? 0 ) - (int) ( $a['clicks'] ?? 0 );
			}
		);

		$top10_urls = array_keys( array_slice( $recent_rows, 0, 10, true ) );

		$alert_posts = array();
		foreach ( $flagged as $post_id => $payload ) {
			if ( in_array( $payload['url'], $top10_urls, true ) ) {
				$alert_posts[ $post_id ] = $payload;
			}
		}

		if ( empty( $alert_posts ) ) {
			return;
		}

		// Determine recipients: fall back to digest recipients, then admin email.
		$recipients_raw = (string) get_option( 'swps_digest_recipients', '' );
		if ( $recipients_raw ) {
			$recipients = array_filter( array_map( 'trim', explode( "\n", $recipients_raw ) ) );
		} else {
			$recipients = array( get_option( 'admin_email' ) );
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Content decay alert — top pages losing traffic', 'stratawp-seo' ),
			get_bloginfo( 'name' )
		);

		$lines = array();
		foreach ( $alert_posts as $post_id => $payload ) {
			$title   = get_the_title( $post_id );
			$pct     = round( $payload['click_change'] * 100 );
			$reason  = $payload['reason'];
			$lines[] = sprintf( '- %s: -%d%% clicks (%s)', $title, $pct, $reason );
		}

		$body = sprintf(
			/* translators: 1: site name, 2: newline-separated list of posts */
			__( "Content decay detected on %1\$s.\n\nThe following top-traffic posts have lost significant clicks in the past 28 days:\n\n%2\$s\n\nLog in to StrataWP SEO to review and queue refresh proposals.", 'stratawp-seo' ),
			get_bloginfo( 'name' ),
			implode( "\n", $lines )
		);

		try {
			wp_mail( $recipients, $subject, $body );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Silent fail — email is advisory, not critical.
			unset( $e );
		}
	}

	// --- Cron schedule / unschedule ---

	/**
	 * Schedule the weekly scan cron event. Called from activation hook.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the weekly scan cron event. Called from deactivation hook.
	 */
	public static function unschedule_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// --- Settings ---

	/**
	 * Register the decay watchdog settings section and fields on the
	 * 'analytics' settings tab.
	 */
	public function register_settings(): void {
		add_settings_section(
			'swps_decay_section',
			__( 'Content Decay Watchdog', 'stratawp-seo' ),
			array( $this, 'render_section_intro' ),
			'stratawp-seo'
		);

		register_setting( 'stratawp-seo', 'swps_decay_threshold', array( 'sanitize_callback' => array( $this, 'sanitize_threshold' ) ) );
		register_setting( 'stratawp-seo', 'swps_decay_min_impressions', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'stratawp-seo', 'swps_decay_email', array( 'sanitize_callback' => 'absint' ) );

		add_settings_field(
			'swps_decay_threshold',
			__( 'Click decline threshold', 'stratawp-seo' ),
			array( $this, 'render_threshold_field' ),
			'stratawp-seo',
			'swps_decay_section',
			array( 'label_for' => 'swps_decay_threshold' )
		);

		add_settings_field(
			'swps_decay_min_impressions',
			__( 'Minimum impressions (prior window)', 'stratawp-seo' ),
			array( $this, 'render_min_impressions_field' ),
			'stratawp-seo',
			'swps_decay_section',
			array( 'label_for' => 'swps_decay_min_impressions' )
		);

		add_settings_field(
			'swps_decay_email',
			__( 'Decay alert email', 'stratawp-seo' ),
			array( $this, 'render_email_toggle_field' ),
			'stratawp-seo',
			'swps_decay_section',
			array( 'label_for' => 'swps_decay_email' )
		);
	}

	/**
	 * Sanitize threshold: must be a float in (0, 1].
	 *
	 * @param mixed $val Raw input.
	 * @return float Sanitized value, clamped to [0.01, 1.0].
	 */
	public function sanitize_threshold( mixed $val ): float {
		$f = (float) $val / 100.0; // Input is expected as a percentage (e.g. 20 → 0.20).
		return max( 0.01, min( 1.0, $f ) );
	}

	/** Section intro text. */
	public function render_section_intro(): void {
		echo '<p>' . esc_html__( 'Weekly Search Console comparison across rolling 28-day windows. Posts that lose clicks beyond the threshold are queued for refresh.', 'stratawp-seo' ) . '</p>';
	}

	/** Threshold field. */
	public function render_threshold_field(): void {
		$val = (float) get_option( 'swps_decay_threshold', self::DEFAULT_THRESHOLD );
		printf(
			'<input type="number" id="swps_decay_threshold" name="swps_decay_threshold" value="%s" min="1" max="100" step="1" class="small-text" /> %%',
			esc_attr( (string) round( $val * 100 ) )
		);
		echo ' <p class="description">' . esc_html__( 'Flag a post when its click count drops by at least this percentage (default 20%).', 'stratawp-seo' ) . '</p>';
	}

	/** Min impressions field. */
	public function render_min_impressions_field(): void {
		$val = (int) get_option( 'swps_decay_min_impressions', self::DEFAULT_MIN_IMPRESSIONS );
		printf(
			'<input type="number" id="swps_decay_min_impressions" name="swps_decay_min_impressions" value="%s" min="0" step="50" class="small-text" />',
			esc_attr( (string) $val )
		);
		echo ' <p class="description">' . esc_html__( 'Ignore posts with fewer than this many impressions in the prior 28 days (default 200).', 'stratawp-seo' ) . '</p>';
	}

	/** Email toggle field. */
	public function render_email_toggle_field(): void {
		$checked = (bool) get_option( 'swps_decay_email', 1 );
		printf(
			'<label><input type="checkbox" id="swps_decay_email" name="swps_decay_email" value="1" %s /> %s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Send a summary email when a top-10 post decays (uses digest recipients)', 'stratawp-seo' )
		);
	}
}
