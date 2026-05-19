<?php
/**
 * Background processing for content generation.
 *
 * Uses Action Scheduler if available, falls back to wp_schedule_single_event.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Background_Processor {

	private const HOOK = 'swps_process_topic';

	public function __construct() {
		add_action( self::HOOK, array( $this, 'process_topic' ) );
	}

	/**
	 * Schedule a topic for background generation.
	 *
	 * @param int $topic_id Topic post ID.
	 * @param int $delay    Delay in seconds from now.
	 */
	public function schedule_generation( int $topic_id, int $delay = 0 ): void {
		$timestamp = time() + $delay;

		if ( $this->has_action_scheduler() ) {
			as_schedule_single_action( $timestamp, self::HOOK, array( 'topic_id' => $topic_id ) );
		} else {
			wp_schedule_single_event( $timestamp, self::HOOK, array( $topic_id ) );
		}
	}

	/**
	 * Process a single topic (callback for scheduled action).
	 *
	 * @param int $topic_id Topic post ID.
	 */
	public function process_topic( int $topic_id ): void {
		$topic = get_post( $topic_id );

		if ( ! $topic || $topic->post_type !== SWPS_Topic_Queue::POST_TYPE ) {
			return;
		}

		$queue = new SWPS_Topic_Queue();
		$queue->update_status( $topic_id, 'generating' );

		// Get the plugin instance and generator.
		$plugin   = stratawp_seo();
		$template = get_post_meta( $topic_id, '_swps_template', true ) ?: 'auto';

		$result = $plugin->generator->generate_post( $topic->post_title, $template );

		if ( is_wp_error( $result ) ) {
			$queue->update_status( $topic_id, 'failed', $result->get_error_message() );
			return;
		}

		$queue->update_status( $topic_id, 'published', '', $result['post_id'] );
	}

	/**
	 * Schedule multiple topics with staggered delays.
	 *
	 * @param array $topic_ids Array of topic post IDs.
	 * @param int   $interval  Seconds between each generation.
	 */
	public function schedule_batch( array $topic_ids, int $interval = 5 ): void {
		foreach ( $topic_ids as $index => $topic_id ) {
			$this->schedule_generation( $topic_id, $index * $interval );
		}
	}

	/**
	 * Check if any topics are currently being processed.
	 *
	 * @return bool True if processing.
	 */
	public function is_processing(): bool {
		$generating = get_posts(
			array(
				'post_type'      => SWPS_Topic_Queue::POST_TYPE,
				'post_status'    => 'generating',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return ! empty( $generating );
	}

	/**
	 * Check if Action Scheduler is available.
	 *
	 * @return bool
	 */
	private function has_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' );
	}
}
