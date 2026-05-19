<?php
/**
 * Duplicate content detection.
 *
 * Two-phase checking:
 * 1. Local DB check using similar_text() at 85% threshold
 * 2. Remote check via jonimms-ai-writer /recent-titles endpoint
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Duplicate_Checker {

	private const SIMILARITY_THRESHOLD = 85;

	/**
	 * Check if a title is a duplicate.
	 *
	 * @param string $title The proposed title.
	 * @return string|false False if not a duplicate, or the matching title string.
	 */
	public function is_duplicate( string $title ): string|false {
		if ( ! get_option( 'swps_duplicate_check', false ) ) {
			return false;
		}

		// Phase 1: Local database check.
		$local_match = $this->check_local( $title );
		if ( false !== $local_match ) {
			return $local_match;
		}

		// Phase 2: Remote endpoint check.
		$remote_match = $this->check_remote( $title );
		if ( false !== $remote_match ) {
			return $remote_match;
		}

		return false;
	}

	/**
	 * Check against local WordPress posts.
	 *
	 * @param string $title Title to check.
	 * @return string|false Matching title or false.
	 */
	private function check_local( string $title ): string|false {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			$existing_title = get_the_title( $post_id );
			$similarity     = 0;

			similar_text(
				strtolower( $title ),
				strtolower( $existing_title ),
				$similarity
			);

			if ( $similarity >= self::SIMILARITY_THRESHOLD ) {
				return $existing_title;
			}
		}

		return false;
	}

	/**
	 * Check against remote jonimms-ai-writer endpoint.
	 *
	 * @param string $title Title to check.
	 * @return string|false Matching title or false.
	 */
	private function check_remote( string $title ): string|false {
		$endpoint = get_option( 'swps_jon_ai_endpoint', '' );
		$secret   = get_option( 'swps_jon_ai_secret', '' );

		if ( empty( $endpoint ) || empty( $secret ) ) {
			return false;
		}

		// Decrypt the secret if encrypted.
		if ( class_exists( 'SWPS_Encryption' ) ) {
			$secret = SWPS_Encryption::decrypt( $secret );
		}

		// Build the recent-titles URL from the base endpoint.
		$base_url   = dirname( $endpoint );
		$titles_url = trailingslashit( $base_url ) . 'recent-titles';

		$response = wp_remote_get(
			$titles_url,
			array(
				'headers' => array(
					'X-Jon-AI-Secret' => $secret,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return false;
		}

		// Check the titles array for similarity.
		$titles = $body['titles'] ?? $body;

		foreach ( $titles as $remote_title ) {
			if ( ! is_string( $remote_title ) ) {
				continue;
			}

			$similarity = 0;
			similar_text(
				strtolower( $title ),
				strtolower( $remote_title ),
				$similarity
			);

			if ( $similarity >= self::SIMILARITY_THRESHOLD ) {
				return $remote_title;
			}
		}

		return false;
	}
}
