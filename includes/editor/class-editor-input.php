<?php
/**
 * Builds engine input from WordPress state and holds the small pure helpers
 * around keywords and presets.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Input {

	public const META_RELATED = '_swps_related_keywords';
	public const MAX_RELATED  = 4;

	/**
	 * @param mixed $value Raw meta value.
	 * @return string[]
	 */
	public static function sanitize_related( $value ): array {
		$out = array();
		foreach ( (array) $value as $kw ) {
			$kw = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $kw ) ) );
			if ( '' === $kw ) {
				continue;
			}
			$key = mb_strtolower( $kw );
			if ( isset( $out[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = mb_substr( $kw, 0, 80 );
			if ( count( $out ) >= self::MAX_RELATED ) {
				break;
			}
		}
		return array_values( $out );
	}

	/**
	 * The older _swps_secondary_keywords meta is a comma separated string used
	 * by the AI generation path. Related keywords are mirrored into it so that
	 * path keeps working.
	 *
	 * @param string[] $related Related keywords.
	 */
	public static function secondary_string( array $related ): string {
		return implode( ', ', $related );
	}

	/**
	 * Reads the older comma separated _swps_secondary_keywords string as a
	 * related keywords list.
	 *
	 * @return string[]
	 */
	public static function parse_legacy_secondary( string $secondary ): array {
		return self::sanitize_related( explode( ',', $secondary ) );
	}

	/**
	 * True when a related keywords list should overwrite the legacy string.
	 * An unchanged list is left alone so a legacy string holding more keywords
	 * than the sidebar shows survives until the writer edits the list.
	 *
	 * @param mixed[] $new_related       Related keywords being saved.
	 * @param string  $current_secondary Current legacy secondary string.
	 */
	public static function should_mirror( array $new_related, string $current_secondary ): bool {
		return self::sanitize_related( $new_related ) !== self::parse_legacy_secondary( $current_secondary );
	}

	/**
	 * Thresholds for a post type. Posts keep honouring the existing
	 * swps_seo_score_content_min option.
	 *
	 * @return array<string,int>
	 */
	public static function preset( string $post_type ): array {
		$base = array(
			'min_words' => 300,
			'title_min' => 30,
			'title_max' => 60,
			'desc_min'  => 70,
			'desc_max'  => 160,
		);
		$per  = array(
			'post'    => array( 'min_words' => (int) get_option( 'swps_seo_score_content_min', 300 ) ),
			'page'    => array( 'min_words' => 150 ),
			'product' => array( 'min_words' => 100 ),
		);

		$preset = array_merge( $base, $per[ $post_type ] ?? array() );

		/**
		 * Filters the editor check thresholds for a post type.
		 *
		 * @param array<string,int> $preset    Thresholds.
		 * @param string            $post_type Post type slug.
		 */
		return array_map( 'intval', (array) apply_filters( 'swps_editor_preset', $preset, $post_type ) );
	}

	/**
	 * True when another post already uses this as its focus keyword.
	 */
	public static function keyword_used_elsewhere( string $keyword, int $post_id ): bool {
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return false;
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'post__not_in'   => array( $post_id ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_swps_focus_keyword',
						'value' => $keyword,
					),
				),
			)
		);
		return ! empty( $query->posts );
	}

	/**
	 * Engine input for a saved post (used for server-side scoring).
	 *
	 * @return array<string,mixed>
	 */
	public static function for_post( WP_Post $post ): array {
		$focus    = (string) get_post_meta( $post->ID, '_swps_focus_keyword', true );
		$related  = self::sanitize_related( get_post_meta( $post->ID, self::META_RELATED, true ) );
		$keywords = array(
			array(
				'keyword'        => $focus,
				'used_elsewhere' => '' === $focus ? null : self::keyword_used_elsewhere( $focus, $post->ID ),
			),
		);
		foreach ( $related as $kw ) {
			$keywords[] = array(
				'keyword'        => $kw,
				'used_elsewhere' => self::keyword_used_elsewhere( $kw, $post->ID ),
			);
		}

		return array(
			'title'            => $post->post_title,
			'slug'             => $post->post_name,
			'content_html'     => $post->post_content,
			'meta_title'       => (string) get_post_meta( $post->ID, '_swps_meta_title', true ),
			'meta_description' => (string) get_post_meta( $post->ID, '_swps_meta_description', true ),
			'lang'             => get_locale(),
			'host'             => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'preset'           => self::preset( $post->post_type ),
			'keywords'         => $keywords,
		);
	}
}
