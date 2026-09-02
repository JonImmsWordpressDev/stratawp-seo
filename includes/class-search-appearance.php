<?php
/**
 * Search Appearance — title/description templates and frontend output.
 *
 * Hooks document_title_parts at priority 10 (before Meta Editor at 20)
 * so per-post overrides always win. Handles all non-singular pages
 * (archives, taxonomies, search, 404) and provides template fallbacks
 * for singular pages without per-post meta.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Search_Appearance {

	/**
	 * Supported template variables.
	 */
	public const VARIABLES = array(
		'%%title%%',
		'%%sitename%%',
		'%%sep%%',
		'%%excerpt%%',
		'%%category%%',
		'%%tag%%',
		'%%author%%',
		'%%date%%',
		'%%page%%',
		'%%searchphrase%%',
		'%%pt_single%%',
		'%%pt_plural%%',
	);

	/**
	 * Yoast-style variable names mapped to their StrataWP equivalents.
	 *
	 * Templates imported from Yoast (and older migrations that stored Yoast
	 * names verbatim) use these; without aliasing they hit the unknown-variable
	 * strip in resolve_variables() and vanish from rendered titles — every tag
	 * archive ends up titled just "Archives – Site".
	 */
	private const LEGACY_ALIASES = array(
		'%%term_title%%'       => '%%title%%',
		'%%archive_title%%'    => '%%title%%',
		'%%name%%'             => '%%author%%',
		'%%primary_category%%' => '%%category%%',
		'%%pagenumber%%'       => '%%page%%',
	);

	/**
	 * Rewrite legacy (Yoast-style) variable names to supported ones.
	 */
	public static function normalize_legacy_vars( string $template ): string {
		return str_replace(
			array_keys( self::LEGACY_ALIASES ),
			array_values( self::LEGACY_ALIASES ),
			$template
		);
	}

	public function __construct() {
		if ( is_admin() ) {
			return;
		}

		// Conflict detection — defer when competing plugins active.
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return;
		}

		// Title template — priority 10 (Meta Editor overrides at 20).
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 10 );
		add_filter( 'document_title_separator', array( $this, 'filter_separator' ), 10 );

		// Meta description for non-singular pages — priority 2 (same as Meta Editor).
		add_action( 'wp_head', array( $this, 'output_meta_description' ), 2 );

		// Self-referencing canonicals for non-singular views — neither core
		// nor the Canonical module covers the posts page or archives.
		add_action( 'wp_head', array( $this, 'output_canonical' ), 1 );

		// Open Graph / Twitter for non-singular pages — priority 5 (Meta Editor
		// covers singular views at priority 2; the audit OG module covers
		// singular views at priority 5 when the Meta Editor is disabled).
		add_action( 'wp_head', array( $this, 'output_og_tags' ), 5 );

		// Robots directives from Search Appearance noindex controls — merged
		// into WordPress core's single robots meta tag instead of printing a
		// second <meta name="robots"> alongside core's.
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
	}

	/**
	 * Apply a stored posts-page meta title to core's title parts.
	 *
	 * Split out as a pure static so the precedence is unit-testable without
	 * loading WordPress. An empty or whitespace-only stored title leaves the
	 * parts untouched so the template path can take over.
	 *
	 * @param array  $title_parts Core's title parts.
	 * @param string $meta_title  Stored _swps_meta_title for the posts page.
	 * @return array
	 */
	public static function apply_posts_page_title( array $title_parts, string $meta_title ): array {
		$meta_title = trim( $meta_title );

		if ( '' === $meta_title ) {
			return $title_parts;
		}

		$title_parts['title'] = $meta_title;

		// The stored title is the complete tag; core would otherwise append
		// the site name a second time.
		unset( $title_parts['site'], $title_parts['tagline'] );

		return $title_parts;
	}

	/**
	 * Choose the social image for a non-singular view.
	 *
	 * Pure so the precedence rule is unit-testable without WordPress: an
	 * explicit per-object override wins, otherwise the site-level fallback
	 * (schema logo or site icon) stands.
	 *
	 * @param string $fallback Site-level image URL.
	 * @param string $override Per-object image URL, may be empty.
	 * @return string
	 */
	public static function resolve_social_image( string $fallback, string $override ): string {
		$override = trim( $override );

		return '' !== $override ? $override : $fallback;
	}

	/**
	 * Filter the document title parts array.
	 */
	public function filter_title_parts( array $title_parts ): array {
		// Posts page: an explicit per-page meta title wins, mirroring how
		// resolve_description() sources the posts-page description. The Meta
		// Editor cannot cover this -- its title filters gate on is_singular(),
		// which the posts page never satisfies, so a title set on the page
		// assigned to "Posts page" was stored and shown in the editor but
		// never rendered.
		if ( is_home() && ! is_front_page() ) {
			$posts_page = (int) get_option( 'page_for_posts' );

			if ( $posts_page ) {
				$meta_title = (string) get_post_meta( $posts_page, '_swps_meta_title', true );
				$meta_title = (string) SWPS_Hooks::filter_meta_title( $meta_title, $posts_page );
				$applied    = self::apply_posts_page_title( $title_parts, $meta_title );

				if ( $applied !== $title_parts ) {
					return $applied;
				}
			}
		}

		$template = $this->get_title_template();

		if ( empty( $template ) ) {
			return $title_parts;
		}

		$resolved             = $this->resolve_variables( $template );
		$title_parts['title'] = $resolved;

		// Remove site name from parts since template includes it.
		unset( $title_parts['site'], $title_parts['tagline'] );

		return $title_parts;
	}

	/**
	 * Filter the title separator.
	 */
	public function filter_separator( string $sep ): string {
		$custom = get_option( 'swps_title_separator', '-' );
		return ! empty( $custom ) ? $custom : $sep;
	}

	/**
	 * Output meta description for non-singular pages.
	 * Singular pages are handled by SWPS_Meta_Editor.
	 */
	public function output_meta_description(): void {
		// Paginated archive views (page 2+) would repeat page 1's description
		// verbatim — a duplicate-description flag in every crawler. Each page
		// already carries a self-referencing canonical, so omit it instead.
		if ( ! is_singular() && (int) get_query_var( 'paged' ) > 1 ) {
			return;
		}

		// Singular pages: only output if Meta Editor hasn't set one.
		if ( is_singular() ) {
			$post_desc = get_post_meta( get_the_ID(), '_swps_meta_description', true );
			if ( ! empty( $post_desc ) ) {
				return; // Meta Editor handles it.
			}
		}

		$description = $this->get_description();

		if ( ! empty( $description ) ) {
			printf(
				'<meta name="description" content="%s" />' . "\n",
				esc_attr( wp_strip_all_tags( $description ) )
			);
		}
	}

	/**
	 * Merge Search Appearance visibility settings into core's robots meta tag.
	 *
	 * Using the wp_robots filter keeps the page on a single
	 * <meta name="robots"> tag (core's), so directives like
	 * max-image-preview:large are preserved alongside noindex.
	 *
	 * @param array $robots Core robots directives.
	 * @return array
	 */
	public function filter_robots( array $robots ): array {
		foreach ( $this->get_robots_directives() as $directive ) {
			$robots[ $directive ] = true;
		}

		return $robots;
	}

	/**
	 * Output Open Graph and Twitter Card tags for non-singular pages.
	 *
	 * Singular views are handled by SWPS_Meta_Editor (or the audit OG module
	 * when the Meta Editor is disabled). Without this, the blog index,
	 * category/tag/taxonomy archives, author archives, and post type archives
	 * shipped no social preview card at all.
	 */
	public function output_og_tags(): void {
		if ( is_singular() || is_search() || is_404() || is_admin() || is_feed() ) {
			return;
		}

		$title = wp_get_document_title();
		$desc  = $this->get_description();
		$url   = $this->get_context_url();
		$image = (string) get_option( 'swps_schema_logo', '' )
				?: (string) get_site_icon_url( 512 );

		// Term-level social overrides from Taxonomy Meta.
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$og_title = get_term_meta( $term->term_id, '_swps_og_title', true );
				$og_desc  = get_term_meta( $term->term_id, '_swps_og_description', true );
				$og_image = get_term_meta( $term->term_id, '_swps_og_image', true );
				$title    = ! empty( $og_title ) ? $og_title : $title;
				$desc     = ! empty( $og_desc ) ? $og_desc : $desc;
				$image    = ! empty( $og_image ) ? $og_image : $image;
			}
		}

		// Posts-page social override. is_home() is never is_singular(), so Meta
		// Editor's per-post path never runs here and the Social Image stored on
		// page_for_posts was ignored -- every share of the blog index fell back
		// to the site logo. Mirrors the term-override branch above, and the
		// is_home() branches resolve_description() and get_title_template()
		// already have.
		if ( is_home() && ! is_front_page() ) {
			$posts_page_id = (int) get_option( 'page_for_posts' );
			$image         = self::resolve_social_image(
				$image,
				$posts_page_id ? (string) get_post_meta( $posts_page_id, '_swps_social_image', true ) : ''
			);
		}

		printf( '<meta property="og:type" content="website" />' . "\n" );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		if ( ! empty( $desc ) ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( wp_strip_all_tags( $desc ) ) );
		}
		if ( ! empty( $url ) ) {
			printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		}
		if ( ! empty( $image ) ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
		}
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );

		printf( '<meta name="twitter:card" content="%s" />' . "\n", $image ? 'summary_large_image' : 'summary' );
		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );
		if ( ! empty( $desc ) ) {
			printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( wp_strip_all_tags( $desc ) ) );
		}
		if ( ! empty( $image ) ) {
			printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $image ) );
		}
	}

	/**
	 * Output a self-referencing canonical on non-singular views.
	 *
	 * WordPress core's rel_canonical and the Canonical audit module are both
	 * singular-only, so the posts page, term archives, author archives, and
	 * post type archives shipped with no canonical at all. Paginated archives
	 * canonicalize to their own page URL.
	 */
	public function output_canonical(): void {
		if ( is_singular() || is_404() || is_search() ) {
			return;
		}

		// A manual term-level canonical override is emitted by Taxonomy Meta.
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term && get_term_meta( $term->term_id, '_swps_canonical_url', true ) ) {
				return;
			}
		}

		$url = $this->get_context_url();
		if ( '' === $url ) {
			return;
		}

		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			$url = trailingslashit( $url ) . user_trailingslashit( 'page/' . $paged, 'paged' );
		}

		/**
		 * Filters the archive canonical URL before output.
		 *
		 * Themes that paginate archives with a custom query arg instead of
		 * core's paged query var can append it here so paginated views keep
		 * a self-referencing canonical. Return an empty string to suppress
		 * the tag entirely.
		 *
		 * @param string $url Canonical URL for the current archive view.
		 */
		$url = (string) apply_filters( 'swps_canonical_url', $url );
		if ( '' === $url ) {
			return;
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
	}

	/**
	 * Canonical URL for the current non-singular context.
	 */
	private function get_context_url(): string {
		if ( is_front_page() ) {
			return home_url( '/' );
		}

		if ( is_home() ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			return $posts_page ? (string) get_permalink( $posts_page ) : home_url( '/' );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$link = get_term_link( $term );
				return is_wp_error( $link ) ? '' : $link;
			}
		}

		if ( is_author() ) {
			$author = get_queried_object();
			return $author ? get_author_posts_url( $author->ID ) : '';
		}

		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
			return is_string( $post_type ) ? (string) get_post_type_archive_link( $post_type ) : '';
		}

		return '';
	}

	/**
	 * Resolve robots directives for the current request.
	 *
	 * Per-post and per-term explicit robots settings win; this method only fills
	 * in the global noindex controls from Search Appearance to avoid duplicate
	 * meta tags from the Meta Editor or Taxonomy Meta modules.
	 */
	private function get_robots_directives(): array {
		if ( is_singular() ) {
			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return array();
			}

			$explicit = get_post_meta( $post_id, '_swps_robots', true );
			if ( ! empty( $explicit ) ) {
				return array();
			}

			$post_type = get_post_type( $post_id );
			if ( $post_type && get_option( "swps_noindex_{$post_type}", 0 ) ) {
				return array( 'noindex', 'follow' );
			}

			return array();
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return array();
			}

			$explicit = get_term_meta( $term->term_id, '_swps_robots', true );
			if ( ! empty( $explicit ) ) {
				return array();
			}

			if ( get_option( "swps_noindex_{$term->taxonomy}", 0 ) ) {
				return array( 'noindex', 'follow' );
			}

			return array();
		}

		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}

			if ( is_string( $post_type ) && get_option( "swps_noindex_{$post_type}", 0 ) ) {
				return array( 'noindex', 'follow' );
			}
		}

		if ( is_author() && get_option( 'swps_noindex_author', 0 ) ) {
			return array( 'noindex', 'follow' );
		}

		if ( is_date() && get_option( 'swps_noindex_date', 0 ) ) {
			return array( 'noindex', 'follow' );
		}

		return array();
	}

	/**
	 * Get the title template for the current page context.
	 */
	private function get_title_template(): string {
		// Both homepage setups — "your latest posts" AND a static front page —
		// use the homepage template. The old is_front_page() && is_home() check
		// silently ignored the configured template on static front pages.
		if ( is_front_page() ) {
			return get_option( 'swps_title_template_homepage', '%%sitename%%' );
		}

		// Posts page on a site with a static front page. Without this branch
		// the posts page matched nothing and fell through to the empty return,
		// leaving WordPress's untemplated default.
		if ( is_home() ) {
			return get_option( 'swps_title_template_home', '%%title%% %%sep%% %%sitename%%' );
		}

		if ( is_singular() ) {
			$post_type = get_post_type();
			return get_option( "swps_title_template_{$post_type}", '%%title%% %%sep%% %%sitename%%' );
		}

		if ( is_category() ) {
			return get_option( 'swps_title_template_category', '%%title%% Archives %%sep%% %%sitename%%' );
		}

		if ( is_tag() ) {
			return get_option( 'swps_title_template_post_tag', '%%title%% %%sep%% %%sitename%%' );
		}

		if ( is_tax() ) {
			$taxonomy = get_queried_object()->taxonomy ?? '';
			return get_option( "swps_title_template_{$taxonomy}", '%%title%% %%sep%% %%sitename%%' );
		}

		if ( is_author() ) {
			return get_option( 'swps_title_template_author', '%%author%% %%sep%% %%sitename%%' );
		}

		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
			return get_option( "swps_title_template_{$post_type}_archive", '%%pt_plural%% %%sep%% %%sitename%%' );
		}

		if ( is_date() ) {
			return get_option( 'swps_title_template_date', '%%title%% %%sep%% %%sitename%%' );
		}

		if ( is_search() ) {
			return get_option( 'swps_title_template_search', 'Search: %%searchphrase%% %%sep%% %%sitename%%' );
		}

		if ( is_404() ) {
			return get_option( 'swps_title_template_404', 'Page Not Found %%sep%% %%sitename%%' );
		}

		return '';
	}

	/**
	 * Get the meta description for the current page context.
	 */
	private function get_description(): string {
		/**
		 * Filters the resolved meta description before output.
		 *
		 * Applies everywhere the description is consumed (meta description,
		 * Open Graph, Twitter Card), letting themes correct or override the
		 * stored value for any view.
		 *
		 * @param string $description Resolved description for the current view.
		 */
		return (string) apply_filters( 'swps_meta_description', $this->resolve_description() );
	}

	/**
	 * Resolve the raw description from post meta, templates, and options.
	 */
	private function resolve_description(): string {
		// Blog-index homepage / posts page: previously returned nothing, so the
		// most-shared URL on a blog-style site had no meta description at all.
		if ( is_home() || ( is_front_page() && ! is_singular() ) ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			if ( $posts_page ) {
				$meta_desc = get_post_meta( $posts_page, '_swps_meta_description', true );
				if ( ! empty( $meta_desc ) ) {
					return $meta_desc;
				}
			}

			$template = get_option( 'swps_desc_template_homepage', '' );
			if ( ! empty( $template ) ) {
				return $this->resolve_variables( $template );
			}

			return (string) get_bloginfo( 'description' );
		}

		if ( is_singular() ) {
			$post_type = get_post_type();
			$template  = get_option( "swps_desc_template_{$post_type}", '%%excerpt%%' );
			return $this->resolve_variables( $template );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && ! empty( $term->description ) ) {
				return wp_trim_words( $term->description, 30 );
			}
			// Check for per-term meta description (from Taxonomy Meta).
			if ( $term ) {
				$meta_desc = get_term_meta( $term->term_id, '_swps_meta_description', true );
				if ( ! empty( $meta_desc ) ) {
					return $meta_desc;
				}
			}
		}

		if ( is_author() ) {
			$author = get_queried_object();
			if ( $author ) {
				return wp_trim_words( get_the_author_meta( 'description', $author->ID ), 30 );
			}
		}

		return '';
	}

	/**
	 * Resolve template variables to their current values.
	 */
	public function resolve_variables( string $template ): string {
		$template = self::normalize_legacy_vars( $template );

		$sep      = get_option( 'swps_title_separator', '-' );
		$sitename = get_bloginfo( 'name' );

		$replacements = array(
			'%%sitename%%'     => $sitename,
			'%%sep%%'          => $sep,
			'%%searchphrase%%' => get_search_query(),
		);

		// Context-specific replacements.
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post ) {
				$replacements['%%title%%']   = $post->post_title;
				$replacements['%%excerpt%%'] = wp_trim_words( $post->post_excerpt ?: wp_strip_all_tags( $post->post_content ), 30 );
				$replacements['%%author%%']  = get_the_author_meta( 'display_name', $post->post_author );
				$replacements['%%date%%']    = get_the_date( '', $post );

				$categories                   = get_the_category( $post->ID );
				$replacements['%%category%%'] = ! empty( $categories ) ? $categories[0]->name : '';

				$tags                    = get_the_tags( $post->ID );
				$replacements['%%tag%%'] = ! empty( $tags ) ? $tags[0]->name : '';
			}
		} elseif ( is_home() && ! is_front_page() ) {
			// %%title%% on the posts page is the title of the page assigned to
			// it; without this the variable resolved to nothing and was then
			// stripped, collapsing the template to just the site name.
			$posts_page = (int) get_option( 'page_for_posts' );
			if ( $posts_page ) {
				$replacements['%%title%%'] = get_the_title( $posts_page );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term ) {
				$replacements['%%title%%'] = $term->name;
			}
		} elseif ( is_author() ) {
			$author = get_queried_object();
			if ( $author ) {
				$replacements['%%title%%']  = $author->display_name;
				$replacements['%%author%%'] = $author->display_name;
			}
		} elseif ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
			$pto = get_post_type_object( $post_type );
			if ( $pto ) {
				$replacements['%%title%%']     = $pto->labels->name;
				$replacements['%%pt_single%%'] = $pto->labels->singular_name;
				$replacements['%%pt_plural%%'] = $pto->labels->name;
			}
		} elseif ( is_date() ) {
			if ( is_year() ) {
				$replacements['%%title%%'] = get_the_date( 'Y' );
			} elseif ( is_month() ) {
				$replacements['%%title%%'] = get_the_date( 'F Y' );
			} else {
				$replacements['%%title%%'] = get_the_date();
			}
		}

		// Pagination.
		$paged                    = get_query_var( 'paged', 0 );
		$replacements['%%page%%'] = $paged > 1 ? sprintf( __( 'Page %d', 'stratawp-seo' ), $paged ) : '';

		$result = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );

		// Clean up empty variables and double separators.
		$result = preg_replace( '/%%\w+%%/', '', $result );
		$result = preg_replace( '/\s+/', ' ', trim( $result ) );

		return $result;
	}
}
