<?php
/**
 * Resolves a crawled URL to the WordPress object that renders it.
 *
 * The crawler stamps every issue row with {object_type, object_id} so the
 * Fix-It engine knows what to write to. normalize() and match() are pure
 * (unit-tested); resolve() is the WP-coupled runtime entry point with a
 * per-request cache of the term/author URL maps.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL → WP object resolution for crawl issues.
 */
class SWPS_Crawl_Target {

	/** No-object result shared by every miss path. */
	private const NONE = array(
		'object_type' => 'none',
		'object_id'   => 0,
	);

	/**
	 * Per-request cache: normalized URL → resolved target.
	 *
	 * @var array
	 */
	private static array $cache = array();

	/**
	 * Per-request lazy-built term/user URL maps (null until first use).
	 *
	 * @var array|null
	 */
	private static ?array $maps = null;

	/**
	 * Canonicalize a URL for map lookups: drop /page/N/ and ?paged=N
	 * pagination, fragments, and add a trailing slash to bare paths.
	 *
	 * @param string $url Absolute URL.
	 * @return string Normalized URL.
	 */
	public static function normalize( string $url ): string {
		$url = preg_replace( '#/page/\d+/?($|\?)#', '/$1', $url );
		$url = preg_replace( '#([?&])paged?=\d+&?#', '$1', (string) $url );
		$url = rtrim( (string) $url, '?&' );

		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return $url;
		}

		$path = $parts['path'] ?? '/';
		if ( '' === $path ) {
			$path = '/';
		}
		if ( ! str_ends_with( $path, '/' ) && '' === pathinfo( $path, PATHINFO_EXTENSION ) ) {
			$path .= '/';
		}

		$rebuilt = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . $path;
		if ( ! empty( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}

		return $rebuilt;
	}

	/**
	 * Pure lookup of a normalized URL against pre-built object maps.
	 *
	 * @param string $normalized normalize()d URL.
	 * @param array  $maps       {posts: [url => id], terms: [url => id], users: [url => id]}.
	 * @return array{object_type: string, object_id: int}
	 */
	public static function match( string $normalized, array $maps ): array {
		foreach ( array(
			'post' => 'posts',
			'term' => 'terms',
			'user' => 'users',
		) as $type => $key ) {
			$id = $maps[ $key ][ $normalized ] ?? 0;
			if ( $id > 0 ) {
				return array(
					'object_type' => $type,
					'object_id'   => (int) $id,
				);
			}
		}

		return self::NONE;
	}

	/**
	 * Resolve a crawled URL to its WP object. Cached per request.
	 *
	 * Posts resolve via url_to_postid() (cheap, core-cached); term and
	 * author archives via lazily-built URL maps since core has no reverse
	 * lookup for them.
	 *
	 * @param string $url Crawled URL.
	 * @return array{object_type: string, object_id: int}
	 */
	public static function resolve( string $url ): array {
		$normalized = self::normalize( $url );

		if ( isset( self::$cache[ $normalized ] ) ) {
			return self::$cache[ $normalized ];
		}

		$post_id = url_to_postid( $normalized );
		if ( $post_id > 0 ) {
			self::$cache[ $normalized ] = array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			);
			return self::$cache[ $normalized ];
		}

		$result                     = self::match( $normalized, self::maps() );
		self::$cache[ $normalized ] = $result;

		return $result;
	}

	/**
	 * Build the term/user URL maps once per request.
	 *
	 * @return array{posts: array, terms: array, users: array}
	 */
	private static function maps(): array {
		if ( null !== self::$maps ) {
			return self::$maps;
		}

		$terms = array();
		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$term_objects = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 1000,
				)
			);
			if ( is_wp_error( $term_objects ) ) {
				continue;
			}
			foreach ( $term_objects as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$terms[ self::normalize( (string) $link ) ] = (int) $term->term_id;
				}
			}
		}

		$users = array();
		foreach ( get_users(
			array(
				'has_published_posts' => true,
				'number'              => 500,
			)
		) as $user ) {
			$users[ self::normalize( get_author_posts_url( (int) $user->ID ) ) ] = (int) $user->ID;
		}

		self::$maps = array(
			'posts' => array(),
			'terms' => $terms,
			'users' => $users,
		);

		return self::$maps;
	}

	/**
	 * Drop the per-request caches (used between crawl chunks in long-running
	 * CLI contexts and by tests).
	 */
	public static function flush_cache(): void {
		self::$cache = array();
		self::$maps  = null;
	}
}
