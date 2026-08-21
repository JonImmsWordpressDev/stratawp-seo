<?php
/**
 * Fix-It fixer: rewrite http:// asset URLs to https:// in post content.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mixed-content mechanical fixer.
 */
class SWPS_Fixer_Mixed_Content extends SWPS_Crawl_Fixer {

	/**
	 * Attributes that load subresources (= can cause mixed content).
	 * href is deliberately excluded: anchor links are not mixed content.
	 */
	private const ASSET_ATTRS = array( 'src', 'srcset', 'poster', 'data-src' );

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		return array( 'mixed_content' );
	}

	/**
	 * Fixer kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'mechanical';
	}

	/**
	 * Rewrite http:// URLs to https:// inside asset-loading attributes.
	 *
	 * Pure; unit-tested. Counts every rewritten URL (srcset can hold
	 * several per attribute).
	 *
	 * @param string $html Post content HTML.
	 * @return array {content: string, changed: int}
	 */
	public static function rewrite( string $html ): array {
		$changed = 0;
		$attrs   = implode( '|', self::ASSET_ATTRS );

		$content = (string) preg_replace_callback(
			'#(?<![\w-])(' . $attrs . ')\s*=\s*("|\')(.*?)\2#is',
			static function ( array $m ) use ( &$changed ): string {
				$value    = preg_replace( '#\bhttp://#i', 'https://', $m[3], -1, $count );
				$changed += (int) $count;
				return $m[1] . '=' . $m[2] . $value . $m[2];
			},
			$html
		);

		return array(
			'content' => $content,
			'changed' => $changed,
		);
	}

	/**
	 * Snapshot the post content, rewrite, save.
	 *
	 * @param array $issue    Decoded issue row (object_type 'post' expected).
	 * @param array $accepted Unused for mechanical fixers.
	 * @return array|WP_Error {changed: bool, message: string}
	 */
	public function apply( array $issue, array $accepted ): array|WP_Error {
		if ( 'post' !== ( $issue['object_type'] ?? '' ) ) {
			return new WP_Error( 'swps_fixit_bad_target', __( 'Mixed-content fixes apply to posts only.', 'stratawp-seo' ) );
		}

		$post = get_post( (int) $issue['object_id'] );
		if ( ! $post ) {
			return new WP_Error( 'swps_fixit_gone', __( 'The post no longer exists.', 'stratawp-seo' ) );
		}

		$result = self::rewrite( $post->post_content );
		if ( 0 === $result['changed'] ) {
			return array(
				'changed' => false,
				'message' => __( 'No http:// asset URLs found in the content (the issue may come from the theme or a widget).', 'stratawp-seo' ),
			);
		}

		SWPS_Fixit_Store::snapshot_fields( 'post', (int) $post->ID, array( 'post_content' => $post->post_content ) );

		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $result['content'] ),
			)
		);

		return array(
			'changed' => true,
			/* translators: %d: number of rewritten URLs */
			'message' => sprintf( __( 'Rewrote %d URL(s) to https.', 'stratawp-seo' ), $result['changed'] ),
		);
	}

	/**
	 * Restore the snapshotted post content.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function undo( array $issue ): bool {
		$oid  = (int) ( $issue['object_id'] ?? 0 );
		$snap = SWPS_Fixit_Store::get_snapshot( 'post', $oid );
		if ( ! isset( $snap['fields']['post_content'] ) ) {
			return false;
		}

		wp_update_post(
			array(
				'ID'           => $oid,
				'post_content' => wp_slash( (string) $snap['fields']['post_content'] ),
			)
		);
		SWPS_Fixit_Store::clear_snapshot( 'post', $oid );

		return true;
	}
}
