<?php
/**
 * WP-Cron scheduling for automated content generation.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Cron {

	private const HOOK = 'swps_generate_scheduled_post';

	private SWPS_Generator $generator;
	private ?SWPS_Topic_Queue $queue;

	public function __construct( SWPS_Generator $generator, ?SWPS_Topic_Queue $queue = null ) {
		$this->generator = $generator;
		$this->queue     = $queue;

		add_action( self::HOOK, array( $this, 'run_scheduled_generation' ) );
		add_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) );
	}

	/**
	 * Add custom cron schedules.
	 */
	public function add_custom_schedules( array $schedules ): array {
		$schedules['swps_twice_weekly'] = array(
			'interval' => 3.5 * DAY_IN_SECONDS,
			'display'  => __( 'Twice Weekly', 'stratawp-seo' ),
		);

		$schedules['swps_three_weekly'] = array(
			'interval' => 2.33 * DAY_IN_SECONDS,
			'display'  => __( 'Three Times Weekly', 'stratawp-seo' ),
		);

		$schedules['swps_biweekly'] = array(
			'interval' => 14 * DAY_IN_SECONDS,
			'display'  => __( 'Every Two Weeks', 'stratawp-seo' ),
		);

		$schedules['swps_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Monthly', 'stratawp-seo' ),
		);

		return $schedules;
	}

	/**
	 * The cron callback — generates posts from queue or randomly.
	 */
	public function run_scheduled_generation(): void {
		$enabled = get_option( 'swps_cron_enabled', false );

		if ( ! $enabled ) {
			return;
		}

		$posts_per_run = (int) get_option( 'swps_cron_posts_per_run', 1 );
		$posts_per_run = min( $posts_per_run, 5 );

		$succeeded = 0;
		$failed    = 0;

		for ( $i = 0; $i < $posts_per_run; $i++ ) {
			$topic    = '';
			$template = get_option( 'swps_default_template', 'auto' );
			$topic_id = 0;

			// Check queue for next topic.
			if ( $this->queue ) {
				$next_topic = $this->queue->get_next_topic();
				if ( $next_topic ) {
					$topic    = $next_topic->post_title;
					$template = get_post_meta( $next_topic->ID, '_swps_template', true ) ?: $template;
					$topic_id = $next_topic->ID;
					$this->queue->update_status( $topic_id, 'generating' );
				} elseif ( get_option( SWPS_Topic_Scout_Cron::OPT_AUTOPROMOTE, 0 ) ) {
					// Queue empty and auto-promote is on: promote the top proposal.
					$promoted = $this->maybe_autopromote_proposal();
					if ( $promoted ) {
						$topic    = $promoted->post_title;
						$template = get_post_meta( $promoted->ID, '_swps_template', true ) ?: $template;
						$topic_id = $promoted->ID;
						$this->queue->update_status( $topic_id, 'generating' );
					}
				}
			}

			$result = $this->generator->generate_post( $topic, $template );

			if ( is_wp_error( $result ) ) {
				++$failed;
				error_log( '[StrataWP SEO Cron] Generation failed: ' . $result->get_error_message() );

				// Budget exhausted: every remaining post would fail too. Put
				// the topic back in the queue untouched and stop the batch.
				if ( 'swps_budget_exceeded' === $result->get_error_code() ) {
					if ( $topic_id && $this->queue ) {
						$this->queue->update_status( $topic_id, 'queued' );
					}
					break;
				}

				if ( $topic_id && $this->queue ) {
					SWPS_Autopilot_Guardian::handle_topic_failure( $topic_id, $result, $this->queue );
				}

				continue;
			}

			++$succeeded;

			// Update topic status if from queue.
			if ( $topic_id && $this->queue ) {
				delete_post_meta( $topic_id, '_swps_attempt_count' );
				$this->queue->update_status( $topic_id, 'published', '', $result['post_id'] );
			}

			if ( $i < $posts_per_run - 1 ) {
				sleep( 5 );
			}
		}

		SWPS_Autopilot_Guardian::record_run( $succeeded, $failed );
		update_option( 'swps_cron_last_run', current_time( 'mysql' ) );
	}

	/**
	 * Schedule the cron event.
	 */
	public static function schedule(): void {
		self::unschedule();

		$frequency = get_option( 'swps_cron_frequency', 'weekly' );
		$day       = get_option( 'swps_cron_day', 'monday' );
		$time      = get_option( 'swps_cron_time', '09:00' );

		$recurrence_map = array(
			'daily'        => 'daily',
			'twice_weekly' => 'swps_twice_weekly',
			'three_weekly' => 'swps_three_weekly',
			'weekly'       => 'weekly',
			'biweekly'     => 'swps_biweekly',
			'monthly'      => 'swps_monthly',
		);

		$recurrence = $recurrence_map[ $frequency ] ?? 'weekly';
		$next_run   = self::calculate_next_run( $day, $time );

		wp_schedule_event( $next_run, $recurrence, self::HOOK );
	}

	/**
	 * Unschedule the cron event.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		wp_unschedule_hook( self::HOOK );
	}

	/**
	 * Calculate the next run timestamp based on desired day and time.
	 *
	 * For daily frequency, schedules the next occurrence of the time
	 * (today if it hasn't passed, otherwise tomorrow). For weekly+
	 * frequencies, schedules the next occurrence of the specified day.
	 */
	private static function calculate_next_run( string $day, string $time ): int {
		$timezone  = wp_timezone();
		$now       = new DateTime( 'now', $timezone );
		$frequency = get_option( 'swps_cron_frequency', 'weekly' );

		list( $hour, $minute ) = explode( ':', $time );

		if ( 'daily' === $frequency ) {
			// For daily: use today's date at the specified time, or tomorrow if past.
			$next = clone $now;
			$next->setTime( (int) $hour, (int) $minute, 0 );
			if ( $next <= $now ) {
				$next->modify( '+1 day' );
			}
			return $next->getTimestamp();
		}

		// For weekly+ frequencies: schedule on the specified day.
		$next = new DateTime( "next {$day}", $timezone );
		$next->setTime( (int) $hour, (int) $minute, 0 );

		// If "next $day" overshoots (e.g. today is that day but time hasn't passed).
		$today_at_time = clone $now;
		$today_at_time->setTime( (int) $hour, (int) $minute, 0 );
		if ( strtolower( $now->format( 'l' ) ) === strtolower( $day ) && $today_at_time > $now ) {
			return $today_at_time->getTimestamp();
		}

		return $next->getTimestamp();
	}

	/**
	 * Promote the top proposed topic to 'queued' status with a back-dated
	 * post_date so get_next_topic() picks it up immediately.
	 *
	 * Called only when the queue is empty and auto-promote is enabled.
	 *
	 * @return WP_Post|null The promoted topic, or null when no proposals exist.
	 */
	private function maybe_autopromote_proposal(): ?WP_Post {
		if ( ! $this->queue ) {
			return null;
		}

		$proposals = get_posts(
			array(
				'post_type'      => SWPS_Topic_Queue::POST_TYPE,
				'post_status'    => 'proposed',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		if ( empty( $proposals ) ) {
			return null;
		}

		$proposal = $proposals[0];

		// Invariant: SWPS_Topic_Queue::get_next_topic() filters on a date_query
		// of before 'now', which WP_Date_Query resolves in the SITE timezone and
		// compares against post_date (local time). post_date must therefore be a
		// LOCAL timestamp — a UTC value would sit in the local future on sites
		// west of UTC and the promoted topic would be skipped.
		$gmt   = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
		$local = get_date_from_gmt( $gmt );

		wp_update_post(
			array(
				'ID'            => $proposal->ID,
				'post_status'   => 'queued',
				'post_date'     => $local,
				'post_date_gmt' => $gmt,
			)
		);

		$rationale = (string) get_post_meta( $proposal->ID, SWPS_Topic_Scout_Cron::META_RATIONALE, true );
		SWPS_Generator::append_log(
			sprintf(
				'Auto-promoted proposal: %s%s',
				$proposal->post_title,
				$rationale ? ' — ' . $rationale : ''
			)
		);

		return get_post( $proposal->ID );
	}

	/**
	 * Get info about the current schedule for display.
	 */
	public static function get_schedule_info(): array {
		$next = wp_next_scheduled( self::HOOK );

		return array(
			'enabled'   => (bool) get_option( 'swps_cron_enabled', false ),
			'frequency' => get_option( 'swps_cron_frequency', 'weekly' ),
			'day'       => get_option( 'swps_cron_day', 'monday' ),
			'time'      => get_option( 'swps_cron_time', '09:00' ),
			'posts_per' => (int) get_option( 'swps_cron_posts_per_run', 1 ),
			'next_run'  => $next ? wp_date( 'F j, Y \a\t g:i A', $next ) : __( 'Not scheduled', 'stratawp-seo' ),
			'last_run'  => get_option( 'swps_cron_last_run', __( 'Never', 'stratawp-seo' ) ),
		);
	}
}
