<?php
/**
 * Optional removal of the taxonomy base from term URLs.
 *
 * With the setting on, /category/news/ becomes /news/ (and /news/child/,
 * /news/page/2/, /news/feed/). No rewrite rules are added, so nothing has to
 * be flushed when terms are created, renamed or deleted: base-less URLs are
 * resolved in the `request` filter, only when WordPress could not match them
 * to a page or post. Pages and posts always win on a slug collision.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strips the base from term links and resolves base-less term URLs.
 */
class SWPS_Term_Base {

	/**
	 * Option holding the taxonomy names whose base is removed.
	 */
	public const OPTION = 'swps_strip_term_base';

	/**
	 * Feed types core routes, as in WP_Rewrite::$feeds.
	 */
	private const FEEDS = 'feed|rdf|rss|rss2|atom';

	/**
	 * Query vars WordPress sets when it tries a base-less term URL as a
	 * page, post or attachment. Requests carrying any other var are left alone.
	 */
	private const TAKEOVER_VARS = array( 'pagename', 'name', 'page', 'paged', 'feed', 'attachment', 'error' );

	/**
	 * Hook link generation, request resolution and the old-URL redirect.
	 */
	public function __construct() {
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 10, 3 );
		add_filter( 'request', array( $this, 'resolve_request' ) );
		add_action( 'template_redirect', array( $this, 'redirect_based_url' ), 2 );
	}

	/**
	 * Public hierarchical taxonomies with pretty term URLs.
	 *
	 * @return string[]
	 */
	public static function eligible_taxonomies(): array {
		$out  = array();
		$args = array(
			'public'       => true,
			'hierarchical' => true,
		);
		foreach ( get_taxonomies( $args, 'objects' ) as $tax ) {
			if ( empty( $tax->rewrite ) || empty( $tax->query_var ) ) {
				continue;
			}
			$out[] = $tax->name;
		}
		return $out;
	}

	/**
	 * Taxonomies with the base removed. Empty on plain permalinks.
	 *
	 * @return string[]
	 */
	public static function enabled_taxonomies(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) || array() === $saved || '' === (string) get_option( 'permalink_structure' ) ) {
			return array();
		}
		return array_values( array_intersect( self::eligible_taxonomies(), $saved ) );
	}

	/**
	 * Sanitize callback for the setting.
	 *
	 * @param mixed $value Submitted value.
	 * @return string[]
	 */
	public static function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$value = array_map( 'sanitize_key', array_map( 'strval', $value ) );
		return array_values( array_intersect( self::eligible_taxonomies(), $value ) );
	}

	/**
	 * The taxonomy's URL base without slashes, e.g. "category" or "blog/category".
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function base( string $taxonomy ): string {
		global $wp_rewrite;
		$struct = $wp_rewrite instanceof WP_Rewrite ? (string) $wp_rewrite->get_extra_permastruct( $taxonomy ) : '';
		$pos    = strpos( $struct, '%' );
		if ( false === $pos ) {
			return '';
		}
		return trim( substr( $struct, 0, $pos ), '/' );
	}

	/**
	 * Split a request path into the term path and its page or feed suffix.
	 *
	 * @param string $path Request path relative to home, e.g. "news/child/page/2".
	 * @return array{path: string, paged: int, feed: string}|null Null when there is no term path.
	 */
	public static function parse_path( string $path ): ?array {
		$path  = trim( $path, '/' );
		$paged = 0;
		$feed  = '';

		if ( preg_match( '#^(.+?)/page/([0-9]+)$#', $path, $m ) ) {
			$path  = $m[1];
			$paged = (int) $m[2];
		} elseif ( preg_match( '#^(.+?)/(?:feed/)?(' . self::FEEDS . ')$#', $path, $m ) ) {
			$path = $m[1];
			$feed = $m[2];
		}

		if ( ! preg_match( '#^[^/]+(?:/[^/]+)*$#', $path ) ) {
			return null;
		}

		return array(
			'path'  => $path,
			'paged' => $paged,
			'feed'  => $feed,
		);
	}

	/**
	 * Drop the base from links of enabled taxonomies.
	 *
	 * @param string         $link     Term link.
	 * @param WP_Term|object $term     Term.
	 * @param string         $taxonomy Taxonomy name.
	 */
	public function filter_term_link( $link, $term, $taxonomy ): string {
		$link = (string) $link;
		if ( ! in_array( $taxonomy, self::enabled_taxonomies(), true ) ) {
			return $link;
		}
		$base = self::base( (string) $taxonomy );
		if ( '' === $base ) {
			return $link;
		}
		$prefix = home_url( '/' . $base . '/' );
		if ( 0 !== strpos( $link, $prefix ) ) {
			return $link;
		}
		return home_url( '/' . substr( $link, strlen( $prefix ) ) );
	}

	/**
	 * Route a base-less term URL to its term archive when no page or post owns it.
	 *
	 * @param array $query_vars Parsed query vars.
	 * @return array
	 */
	public function resolve_request( $query_vars ) {
		if ( ! is_array( $query_vars ) || is_admin() ) {
			return $query_vars;
		}
		$taxonomies = self::enabled_taxonomies();
		if ( array() === $taxonomies || array() !== array_diff( array_keys( $query_vars ), self::TAKEOVER_VARS ) ) {
			return $query_vars;
		}

		global $wp;
		$parsed = self::parse_path( isset( $wp->request ) ? (string) $wp->request : '' );
		if ( null === $parsed || self::content_exists( $parsed['path'] ) ) {
			return $query_vars;
		}

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! self::term_from_path( $parsed['path'], $taxonomy ) ) {
				continue;
			}
			$tax_obj = get_taxonomy( $taxonomy );
			$vars    = array( (string) $tax_obj->query_var => $parsed['path'] );
			if ( $parsed['paged'] > 0 ) {
				$vars['paged'] = (string) $parsed['paged'];
			}
			if ( '' !== $parsed['feed'] ) {
				$vars['feed'] = $parsed['feed'];
			}
			return $vars;
		}

		return $query_vars;
	}

	/**
	 * 301 an old /category/... URL to its base-less form.
	 */
	public function redirect_based_url(): void {
		$target = $this->based_url_target();
		if ( null !== $target && wp_safe_redirect( $target, 301, 'StrataWP SEO' ) ) {
			exit;
		}
	}

	/**
	 * Where the current request should be redirected, or null to serve it.
	 */
	public function based_url_target(): ?string {
		if ( ! is_category() && ! is_tax() ) {
			return null;
		}
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, self::enabled_taxonomies(), true ) ) {
			return null;
		}

		global $wp;
		$request = trim( isset( $wp->request ) ? (string) $wp->request : '', '/' );
		$base    = self::base( $term->taxonomy );
		if ( '' === $base || 0 !== strpos( $request, $base . '/' ) ) {
			return null;
		}
		$rest = substr( $request, strlen( $base ) + 1 );
		if ( '' === $rest ) {
			return null;
		}

		$target = home_url( '/' . user_trailingslashit( $rest ) );
		$query  = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through to a same-site URL only.
		if ( '' !== $query ) {
			$target .= '?' . $query;
		}
		return $target;
	}

	/**
	 * Terms of enabled taxonomies whose base-less URL is owned by a page or post.
	 *
	 * @return array<int, array{taxonomy: string, name: string, path: string}>
	 */
	public static function collisions(): array {
		$out = array();
		foreach ( self::enabled_taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 2000,
				)
			);
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$path = self::term_path( $term );
				if ( self::content_exists( $path ) ) {
					$out[] = array(
						'taxonomy' => $taxonomy,
						'name'     => $term->name,
						'path'     => $path,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Whether a page, or a post on a /%postname%/ structure, lives at this path.
	 *
	 * @param string $path Path without slashes.
	 */
	private static function content_exists( string $path ): bool {
		if ( get_page_by_path( $path ) ) {
			return true;
		}
		$structure = ltrim( (string) get_option( 'permalink_structure' ), '/' );
		if ( false !== strpos( $path, '/' ) || 0 !== strpos( $structure, '%postname%' ) ) {
			return false;
		}
		$ids = get_posts(
			array(
				'name'             => $path,
				'post_type'        => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'page', 'attachment' ) ) ),
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		return array() !== $ids;
	}

	/**
	 * The term whose base-less path is exactly $path, if any.
	 *
	 * @param string $path     Path without slashes.
	 * @param string $taxonomy Taxonomy name.
	 */
	private static function term_from_path( string $path, string $taxonomy ): ?WP_Term {
		$slugs = explode( '/', $path );
		$term  = get_term_by( 'slug', (string) end( $slugs ), $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		return self::term_path( $term ) === $path ? $term : null;
	}

	/**
	 * A term's path below the base: "parent/child" for hierarchical rewrites.
	 *
	 * @param WP_Term $term Term.
	 */
	private static function term_path( WP_Term $term ): string {
		$tax = get_taxonomy( $term->taxonomy );
		if ( ! $tax || empty( $tax->rewrite['hierarchical'] ) ) {
			return $term->slug;
		}
		$slugs = array();
		foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof WP_Term ) {
				$slugs[] = $ancestor->slug;
			}
		}
		$slugs[] = $term->slug;
		return implode( '/', $slugs );
	}
}
