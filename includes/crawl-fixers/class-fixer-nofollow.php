<?php
/**
 * Fix-It fixer: remove nofollow from internal links.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nofollow removal mechanical fixer.
 */
class SWPS_Fixer_Nofollow extends SWPS_Crawl_Fixer {

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		return array( 'nofollow_internal_link' );
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
	 * Remove the nofollow rel token from internal <a> tags.
	 *
	 * Internal = relative href, or absolute href whose host equals the home
	 * host (www-insensitive). Other rel tokens survive; an emptied rel
	 * attribute is removed entirely. Pure; unit-tested.
	 *
	 * @param string $html      Post content HTML.
	 * @param string $home_host Site host, e.g. "example.com".
	 * @return array {content: string, changed: int}
	 */
	public static function strip( string $html, string $home_host ): array {
		$changed   = 0;
		$home_host = strtolower( preg_replace( '/^www\./', '', $home_host ) );

		$content = (string) preg_replace_callback(
			'#<a\b[^>]*>#is',
			static function ( array $m ) use ( &$changed, $home_host ): string {
				$tag = $m[0];

				if ( ! preg_match( '#\brel\s*=\s*("|\')(.*?)\1#is', $tag, $rel ) ) {
					return $tag;
				}
				if ( ! preg_match( '#\bnofollow\b#i', $rel[2] ) ) {
					return $tag;
				}

				// Internal-only: relative href, or matching host.
				if ( preg_match( '#\bhref\s*=\s*("|\')(.*?)\1#is', $tag, $href ) ) {
					$url  = trim( $href[2] );
					$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
					$host = preg_replace( '/^www\./', '', $host );
					if ( '' !== $host && $host !== $home_host ) {
						return $tag;
					}
				}

				$rest = trim( (string) preg_replace( '#\bnofollow\b#i', '', $rel[2] ) );
				$rest = (string) preg_replace( '/\s{2,}/', ' ', $rest );
				++$changed;

				if ( '' === $rest ) {
					// Drop the whole rel attribute.
					return (string) preg_replace( '#\s*\brel\s*=\s*("|\').*?\1#is', '', $tag );
				}

				return str_replace( $rel[0], 'rel=' . $rel[1] . $rest . $rel[1], $tag );
			},
			$html
		);

		return array(
			'content' => $content,
			'changed' => $changed,
		);
	}

	/**
	 * Snapshot the post content, strip nofollow, save.
	 *
	 * @param array $issue    Decoded issue row (object_type 'post' expected).
	 * @param array $accepted Unused for mechanical fixers.
	 * @return array|WP_Error {changed: bool, message: string}
	 */
	public function apply( array $issue, array $accepted ): array|WP_Error {
		if ( 'post' !== ( $issue['object_type'] ?? '' ) ) {
			return new WP_Error( 'swps_fixit_bad_target', __( 'Nofollow fixes apply to posts only.', 'stratawp-seo' ) );
		}

		$post = get_post( (int) $issue['object_id'] );
		if ( ! $post ) {
			return new WP_Error( 'swps_fixit_gone', __( 'The post no longer exists.', 'stratawp-seo' ) );
		}

		$result = self::strip( $post->post_content, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( 0 === $result['changed'] ) {
			return array(
				'changed' => false,
				'message' => __( 'No internal nofollow links found in the content.', 'stratawp-seo' ),
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
			/* translators: %d: number of rewritten links */
			'message' => sprintf( __( 'Rewrote %d link(s) to remove nofollow.', 'stratawp-seo' ), $result['changed'] ),
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
