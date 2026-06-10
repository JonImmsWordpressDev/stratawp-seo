<?php
/**
 * Topic Queue using custom post type.
 *
 * Manages the swps_topic CPT for scheduled content generation.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Topic_Queue {

	public const POST_TYPE = 'swps_topic';

	/**
	 * Register the custom post type.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Topics', 'stratawp-seo' ),
					'singular_name' => __( 'Topic', 'stratawp-seo' ),
				),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor' ),
				'capabilities' => array(
					'create_posts' => 'manage_options',
				),
			)
		);

		// Register custom statuses.
		register_post_status(
			'queued',
			array(
				'label'       => __( 'Queued', 'stratawp-seo' ),
				'public'      => false,
				'internal'    => true,
				'label_count' => _n_noop( 'Queued <span class="count">(%s)</span>', 'Queued <span class="count">(%s)</span>', 'stratawp-seo' ),
			)
		);

		register_post_status(
			'generating',
			array(
				'label'       => __( 'Generating', 'stratawp-seo' ),
				'public'      => false,
				'internal'    => true,
				'label_count' => _n_noop( 'Generating <span class="count">(%s)</span>', 'Generating <span class="count">(%s)</span>', 'stratawp-seo' ),
			)
		);

		register_post_status(
			'failed',
			array(
				'label'       => __( 'Failed', 'stratawp-seo' ),
				'public'      => false,
				'internal'    => true,
				'label_count' => _n_noop( 'Failed <span class="count">(%s)</span>', 'Failed <span class="count">(%s)</span>', 'stratawp-seo' ),
			)
		);

		register_post_status(
			'retrying',
			array(
				'label'       => __( 'Retrying', 'stratawp-seo' ),
				'public'      => false,
				'internal'    => true,
				'label_count' => _n_noop( 'Retrying <span class="count">(%s)</span>', 'Retrying <span class="count">(%s)</span>', 'stratawp-seo' ),
			)
		);
	}

	/**
	 * Create a new topic in the queue.
	 *
	 * @param string $title    Topic title/headline.
	 * @param string $date     Scheduled date (Y-m-d H:i:s).
	 * @param string $template Template slug.
	 * @param string $notes    Optional notes.
	 * @param int    $priority Priority (1=highest).
	 * @return int|WP_Error Post ID or error.
	 */
	public function create_topic( string $title, string $date = '', string $template = 'auto', string $notes = '', int $priority = 10 ): int|WP_Error {
		$post_data = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => sanitize_textarea_field( $notes ),
			'post_status'  => 'queued',
		);

		if ( ! empty( $date ) ) {
			$post_data['post_date']     = $date;
			$post_data['post_date_gmt'] = get_gmt_from_date( $date );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_swps_template', sanitize_text_field( $template ) );
		update_post_meta( $post_id, '_swps_priority', $priority );

		return $post_id;
	}

	/**
	 * Get all queued topics, ordered by date then priority.
	 *
	 * @param int $limit Max topics to return.
	 * @return array Array of topic post objects.
	 */
	public function get_queued_topics( int $limit = 50 ): array {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'queued',
				'posts_per_page' => $limit,
				'orderby'        => array( 'date' => 'ASC' ),
				'meta_key'       => '_swps_priority',
			)
		);
	}

	/**
	 * Get the next topic due for generation.
	 *
	 * @return WP_Post|null Next topic or null.
	 */
	public function get_next_topic(): ?WP_Post {
		$topics = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'queued',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'date_query'     => array(
					array(
						'before'    => 'now',
						'inclusive' => true,
					),
				),
			)
		);

		return $topics[0] ?? null;
	}

	/**
	 * Update a topic's status.
	 *
	 * @param int    $topic_id Topic post ID.
	 * @param string $status   New status (queued, generating, published, failed).
	 * @param string $error    Error message (for failed status).
	 * @param int    $post_id  Generated post ID (for published status).
	 */
	public function update_status( int $topic_id, string $status, string $error = '', int $post_id = 0 ): void {
		wp_update_post(
			array(
				'ID'          => $topic_id,
				'post_status' => $status,
			)
		);

		if ( ! empty( $error ) ) {
			update_post_meta( $topic_id, '_swps_error_message', $error );
		}

		if ( $post_id > 0 ) {
			update_post_meta( $topic_id, '_swps_generated_post_id', $post_id );
		}
	}

	/**
	 * Update a topic's scheduled date.
	 *
	 * @param int    $topic_id Topic post ID.
	 * @param string $date     New date (Y-m-d H:i:s).
	 */
	public function update_date( int $topic_id, string $date ): void {
		wp_update_post(
			array(
				'ID'            => $topic_id,
				'post_date'     => $date,
				'post_date_gmt' => get_gmt_from_date( $date ),
			)
		);
	}

	/**
	 * Delete a topic.
	 *
	 * @param int $topic_id Topic post ID.
	 * @return bool True on success.
	 */
	public function delete_topic( int $topic_id ): bool {
		$result = wp_delete_post( $topic_id, true );
		return false !== $result;
	}

	/**
	 * Get all topics for calendar display.
	 *
	 * @param string $start Start date (Y-m-d).
	 * @param string $end   End date (Y-m-d).
	 * @return array Array of topic data for FullCalendar.
	 */
	public function get_calendar_events( string $start, string $end ): array {
		$topics = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'queued', 'generating', 'published', 'failed', 'retrying' ),
				'posts_per_page' => 200,
				'date_query'     => array(
					array(
						'after'     => $start,
						'before'    => $end,
						'inclusive' => true,
					),
				),
			)
		);

		$events = array();
		foreach ( $topics as $topic ) {
			$template = get_post_meta( $topic->ID, '_swps_template', true ) ?: 'auto';
			$color    = match ( $topic->post_status ) {
				'queued'     => '#dba617',
				'generating' => '#2271b1',
				'published'  => '#00a32a',
				'failed'     => '#d63638',
				'retrying'   => '#f59e0b',
				default      => '#646970',
			};

			$events[] = array(
				'id'              => $topic->ID,
				'title'           => $topic->post_title,
				'start'           => $topic->post_date,
				'backgroundColor' => $color,
				'borderColor'     => $color,
				'extendedProps'   => array(
					'status'   => $topic->post_status,
					'template' => $template,
					'notes'    => $topic->post_content,
					'type'     => 'topic',
				),
			);
		}

		return $events;
	}

	/**
	 * Get published posts for calendar display.
	 *
	 * @param string $start Start date (Y-m-d).
	 * @param string $end   End date (Y-m-d).
	 * @return array Array of post data for FullCalendar.
	 */
	public function get_published_events( string $start, string $end ): array {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'date_query'     => array(
					array(
						'after'     => $start,
						'before'    => $end,
						'inclusive' => true,
					),
				),
			)
		);

		$events = array();
		foreach ( $posts as $post ) {
			$is_generated = get_post_meta( $post->ID, '_swps_generated', true );

			$events[] = array(
				'id'              => 'post_' . $post->ID,
				'title'           => $post->post_title,
				'start'           => $post->post_date,
				'backgroundColor' => $is_generated ? '#00a32a' : '#8c8f94',
				'borderColor'     => $is_generated ? '#00a32a' : '#8c8f94',
				'url'             => get_edit_post_link( $post->ID, 'raw' ),
				'extendedProps'   => array(
					'status' => 'published',
					'type'   => 'post',
				),
			);
		}

		return $events;
	}
}
