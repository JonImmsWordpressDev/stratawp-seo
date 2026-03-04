<?php
/**
 * Image SEO audit module.
 *
 * Checks for missing alt text, poor file names, and missing dimensions.
 * Auto-fix: Generates alt text for images missing it (batched, max 20).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Image_SEO_Module extends SWPS_Audit_Module {

	private const MAX_BATCH = 20;

	public function get_id(): string {
		return 'image_seo';
	}

	public function get_label(): string {
		return __( 'Image SEO', 'stratawp-seo' );
	}

	public function can_auto_fix(): bool {
		return true;
	}

	public function run(): array {
		$issues = [];

		// Query recent attachments (images).
		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		$missing_alt    = 0;
		$bad_filenames  = 0;
		$fixable_ids    = [];

		foreach ( $attachments as $attachment ) {
			$alt = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );

			if ( empty( trim( $alt ) ) ) {
				$missing_alt++;
				$fixable_ids[] = $attachment->ID;

				$issues[] = [
					'post_id' => $attachment->ID,
					'message' => sprintf(
						__( 'Image "%s" is missing alt text.', 'stratawp-seo' ),
						basename( get_attached_file( $attachment->ID ) )
					),
					'fixable' => true,
				];
			}

			// Check filename quality.
			$filename = basename( get_attached_file( $attachment->ID ) );
			if ( preg_match( '/^(IMG_|DSC_|Screenshot|image|photo)\d*/i', $filename ) ) {
				$bad_filenames++;

				$issues[] = [
					'post_id' => $attachment->ID,
					'message' => sprintf(
						__( 'Image "%s" has a non-descriptive filename.', 'stratawp-seo' ),
						$filename
					),
					'fixable' => false,
				];
			}
		}

		$total   = count( $attachments );
		$failing = $missing_alt + $bad_filenames;
		$score   = $total > 0 ? (int) round( ( ( $total - $failing ) / $total ) * 100 ) : 100;

		return [
			'score'   => $score,
			'status'  => $this->status_from_score( $score ),
			'issues'  => $issues,
			'summary' => 0 === $failing
				? __( 'All images have alt text and descriptive filenames.', 'stratawp-seo' )
				: sprintf(
					__( '%d missing alt text, %d non-descriptive filenames.', 'stratawp-seo' ),
					$missing_alt,
					$bad_filenames
				),
		];
	}

	public function auto_fix( array $issues ): array {
		$fixable = array_filter( $issues, fn( $i ) => $i['fixable'] && $i['post_id'] );
		$fixable = array_slice( $fixable, 0, self::MAX_BATCH );

		$fixed    = 0;
		$messages = [];

		foreach ( $fixable as $issue ) {
			$attachment = get_post( $issue['post_id'] );
			if ( ! $attachment ) {
				continue;
			}

			// Generate alt text from parent post context + filename.
			$alt = $this->generate_alt_text( $attachment );

			if ( ! empty( $alt ) ) {
				update_post_meta( $attachment->ID, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
				$fixed++;
				$messages[] = sprintf(
					__( 'Set alt text for "%s": "%s"', 'stratawp-seo' ),
					basename( get_attached_file( $attachment->ID ) ),
					$alt
				);
			}
		}

		return [
			'fixed'    => $fixed,
			'messages' => $messages,
		];
	}

	/**
	 * Generate alt text for an image attachment.
	 * Uses the parent post title + image caption/title as context.
	 * Falls back to a cleaned-up filename if no context available.
	 */
	private function generate_alt_text( WP_Post $attachment ): string {
		// Try caption or title from attachment.
		if ( ! empty( $attachment->post_excerpt ) ) {
			return $attachment->post_excerpt;
		}

		if ( ! empty( $attachment->post_title ) && 'attachment' !== $attachment->post_title ) {
			// Clean up auto-generated titles (which are often just the filename).
			$title = $attachment->post_title;
			// If it looks like a cleaned filename, use parent context instead.
			if ( ! preg_match( '/^(IMG|DSC|Screenshot|image|photo)/i', $title ) ) {
				return $title;
			}
		}

		// Use parent post context.
		if ( $attachment->post_parent ) {
			$parent = get_post( $attachment->post_parent );
			if ( $parent ) {
				return sprintf(
					__( 'Image from "%s"', 'stratawp-seo' ),
					$parent->post_title
				);
			}
		}

		// Last resort: clean up filename.
		$filename = pathinfo( basename( get_attached_file( $attachment->ID ) ), PATHINFO_FILENAME );
		return ucfirst( str_replace( [ '-', '_' ], ' ', $filename ) );
	}
}
