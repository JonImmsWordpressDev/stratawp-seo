<?php
/**
 * Schema / Structured Data output.
 *
 * Outputs JSON-LD on the frontend:
 *   - Legacy set (v1-v4): Article, Breadcrumb, WebSite, Organization/Person.
 *     Hooked at wp_head priority 1. Bails entirely if Yoast / RankMath /
 *     AIOSEO is active.
 *   - AEO (v4.6+): dynamic per-post HowTo / Recipe / Product / Review /
 *     FAQPage from _swps_aeo_schema_json post meta. Hooked at wp_head
 *     priority 10. Defers to other SEO plugins by default; respects the
 *     swps_schema_override option for per-type re-enable.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SWPS_Schema
 *
 * Two hook registrations on construction (see __construct):
 *   - maybe_render_aeo_schema at priority 10 (always, with internal defer)
 *   - output_schema at priority 1 (only when no other SEO plugin is active)
 */
class SWPS_Schema {

	/**
	 * Constructor — bail if schema is disabled or another SEO plugin handles it.
	 */
	public function __construct() {
		if ( is_admin() ) {
			return;
		}

		if ( ! get_option( 'swps_schema_enabled', 1 ) ) {
			return;
		}

		// AEO schema (v4.6) always registers — it does its own per-type
		// deferral check inside the callback so a future override toggle
		// (swps_schema_override) can re-enable per-type emission when
		// another SEO plugin is active.
		add_action( 'wp_head', array( $this, 'maybe_render_aeo_schema' ), 10 );

		// Defer the legacy (v1-v4) schema set entirely to other SEO plugins
		// when they're active. This is the pre-existing behavior; AEO is
		// handled above with its own deferral.
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'output_schema' ), 1 );
	}

	/**
	 * Main wp_head callback — dispatches to individual schema methods.
	 */
	public function output_schema(): void {
		if ( is_front_page() ) {
			$this->website_schema();
			$this->organization_schema();
		}

		if ( is_singular( 'post' ) ) {
			$this->article_schema();
		}

		// Output JSON-LD breadcrumbs only when the HTML breadcrumbs feature is disabled.
		// When enabled, SWPS_Breadcrumbs outputs inline microdata schema instead.
		if ( ! is_front_page() && ! get_option( 'swps_breadcrumbs_enabled', 1 ) ) {
			$this->breadcrumb_schema();
		}

		// Author archive — emit ProfilePage + Person entity.
		if ( is_author() ) {
			$this->author_schema();
		}
	}

	/**
	 * Output a JSON-LD script tag from a schema array.
	 *
	 * @param array $schema Schema data array.
	 */
	private function output_jsonld( array $schema ): void {
		if ( empty( $schema ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD script tag.
		echo '<script type="application/ld+json">'
			. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
	}

	/**
	 * Render dynamic AEO JSON-LD on singulars if the post has a schema type assigned.
	 *
	 * Reads _swps_aeo_schema_type + _swps_aeo_schema_json post meta. Respects
	 * the swps_aeo_enabled_schema_types option. Defers when another SEO plugin
	 * is active unless the swps_schema_override toggle is on.
	 *
	 * Filterable via swps_schema_{type} (e.g. swps_schema_recipe).
	 */
	public function maybe_render_aeo_schema(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$type = (string) get_post_meta( $post_id, '_swps_aeo_schema_type', true );
		if ( '' === $type ) {
			return;
		}

		$enabled = (array) get_option(
			'swps_aeo_enabled_schema_types',
			array( 'howto', 'recipe', 'product', 'review', 'faqpage' )
		);
		if ( ! in_array( $type, $enabled, true ) ) {
			return;
		}

		if ( $this->is_aeo_schema_deferred() ) {
			return;
		}

		$raw = (string) get_post_meta( $post_id, '_swps_aeo_schema_json', true );
		if ( '' === $raw ) {
			return;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return;
		}

		/**
		 * Filter the AEO-generated schema array before output.
		 *
		 * @param array $decoded Schema array.
		 * @param int   $post_id Post ID being rendered.
		 */
		$decoded = (array) apply_filters( "swps_schema_{$type}", $decoded, $post_id );

		$this->output_jsonld( $decoded );
	}

	/**
	 * Should AEO schema defer to another SEO plugin?
	 *
	 * True when Yoast / RankMath / AIOSEO is active AND the user has NOT
	 * enabled the swps_schema_override toggle.
	 */
	private function is_aeo_schema_deferred(): bool {
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return ! (bool) get_option( 'swps_schema_override', false );
		}
		return false;
	}

	/**
	 * Output Article schema on single posts.
	 */
	private function article_schema(): void {
		$post = get_post();

		if ( ! $post ) {
			return;
		}

		$article_type = get_option( 'swps_schema_article_type', 'Article' );
		$author       = get_userdata( $post->post_author );
		$excerpt      = get_the_excerpt( $post );

		if ( empty( $excerpt ) ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 25, '...' );
		}

		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => $article_type,
			'headline'         => get_the_title( $post ),
			'description'      => $excerpt,
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post ),
			),
			'wordCount'        => str_word_count( wp_strip_all_tags( $post->post_content ) ),
		);

		// Author — inline Person node with E-E-A-T fields (commit c).
		if ( $author ) {
			$schema['author'] = $this->build_person_node( $author );
		}

		// Featured image.
		$thumb_id = get_post_thumbnail_id( $post );

		if ( $thumb_id ) {
			$img_data = wp_get_attachment_image_src( $thumb_id, 'full' );

			if ( $img_data ) {
				$schema['image'] = array(
					'@type'  => 'ImageObject',
					'url'    => $img_data[0],
					'width'  => $img_data[1],
					'height' => $img_data[2],
				);
			}
		}

		// Publisher — references Organization settings.
		$org_name = get_option( 'swps_schema_name', '' );

		if ( empty( $org_name ) ) {
			$org_name = get_bloginfo( 'name' );
		}

		$publisher = array(
			'@type' => 'Organization',
			'name'  => $org_name,
		);

		$logo_url = get_option( 'swps_schema_logo', '' );

		if ( ! empty( $logo_url ) ) {
			$publisher['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo_url,
			);
		}

		$schema['publisher'] = $publisher;

		/**
		 * Filter the Article schema before output.
		 *
		 * @param array $schema  Article schema array.
		 * @param int   $post_id Post ID.
		 */
		$schema = SWPS_Hooks::filter_schema_article( $schema, $post->ID );

		$this->output_jsonld( $schema );
	}

	/**
	 * Output BreadcrumbList schema on all pages except homepage.
	 */
	private function breadcrumb_schema(): void {
		$items = array();

		// Home is always first.
		$items[] = array(
			'name' => get_bloginfo( 'name' ),
			'url'  => home_url( '/' ),
		);

		if ( is_singular( 'post' ) ) {
			$post     = get_post();
			$category = $this->get_primary_category( $post );

			if ( $category ) {
				$items[] = array(
					'name' => $category->name,
					'url'  => get_category_link( $category->term_id ),
				);
			}

			$items[] = array(
				'name' => get_the_title( $post ),
				'url'  => get_permalink( $post ),
			);
		} elseif ( is_page() ) {
			$post      = get_post();
			$ancestors = array_reverse( get_post_ancestors( $post ) );

			foreach ( $ancestors as $ancestor_id ) {
				$items[] = array(
					'name' => get_the_title( $ancestor_id ),
					'url'  => get_permalink( $ancestor_id ),
				);
			}

			$items[] = array(
				'name' => get_the_title( $post ),
				'url'  => get_permalink( $post ),
			);
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( $term ) {
				$items[] = array(
					'name' => $term->name,
					'url'  => get_term_link( $term ),
				);
			}
		} elseif ( is_author() ) {
			$author = get_queried_object();

			if ( $author ) {
				$items[] = array(
					'name' => $author->display_name,
					'url'  => get_author_posts_url( $author->ID ),
				);
			}
		}

		// Need at least 2 items (Home + something) for a valid breadcrumb.
		if ( count( $items ) < 2 ) {
			return;
		}

		$list_items = array();

		foreach ( $items as $position => $item ) {
			$list_items[] = array(
				'@type'    => 'ListItem',
				'position' => $position + 1,
				'name'     => $item['name'],
				'item'     => $item['url'],
			);
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list_items,
		);

		/** Filter the Breadcrumb schema before output. */
		$schema = SWPS_Hooks::filter_schema_breadcrumb( $schema );

		$this->output_jsonld( $schema );
	}

	/**
	 * Get the primary category for a post.
	 *
	 * Uses Yoast primary category if set, otherwise falls back to first category.
	 *
	 * @param WP_Post $post Post object.
	 * @return WP_Term|null Category term or null.
	 */
	private function get_primary_category( WP_Post $post ): ?WP_Term {
		// Check Yoast primary category first.
		$primary_id = get_post_meta( $post->ID, '_yoast_wpseo_primary_category', true );

		if ( $primary_id ) {
			$term = get_term( (int) $primary_id, 'category' );

			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		// Fall back to first assigned category.
		$categories = get_the_category( $post->ID );

		if ( ! empty( $categories ) ) {
			return $categories[0];
		}

		return null;
	}

	/**
	 * Output WebSite schema on the homepage.
	 */
	private function website_schema(): void {
		$name = get_option( 'swps_schema_name', '' );

		if ( empty( $name ) ) {
			$name = get_bloginfo( 'name' );
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebSite',
			'name'     => $name,
			'url'      => home_url( '/' ),
		);

		// Optional sitelinks searchbox.
		if ( get_option( 'swps_schema_searchbox', 1 ) ) {
			$schema['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			);
		}

		$this->output_jsonld( $schema );
	}

	/**
	 * Build a Person schema node for an author, including E-E-A-T fields when set.
	 *
	 * Always includes @type, name, and url. Adds sameAs, jobTitle, and
	 * description (credentials) only when the meta values are non-empty, so no
	 * empty strings appear in the emitted JSON-LD.
	 *
	 * @param WP_User $author The author user object.
	 * @return array Person schema node.
	 */
	private function build_person_node( WP_User $author ): array {
		$node = array(
			'@type' => 'Person',
			'name'  => $author->display_name,
			'url'   => get_author_posts_url( $author->ID ),
		);

		$sameas      = SWPS_Author_Profile::get_sameas( $author->ID );
		$job_title   = SWPS_Author_Profile::get_job_title( $author->ID );
		$credentials = SWPS_Author_Profile::get_credentials( $author->ID );

		if ( ! empty( $sameas ) ) {
			$node['sameAs'] = $sameas;
		}

		if ( '' !== $job_title ) {
			$node['jobTitle'] = $job_title;
		}

		if ( '' !== $credentials ) {
			$node['description'] = $credentials;
		}

		return $node;
	}

	/**
	 * Output ProfilePage + Person schema on author archive pages.
	 *
	 * Emits a ProfilePage whose mainEntity is the full Person node, including
	 * any E-E-A-T fields stored against the author's user profile.
	 */
	private function author_schema(): void {
		$author = get_queried_object();

		if ( ! ( $author instanceof WP_User ) ) {
			return;
		}

		$person_node = $this->build_person_node( $author );

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'ProfilePage',
			'name'       => sprintf(
				/* translators: %s: author display name */
				__( 'Author: %s', 'stratawp-seo' ),
				$author->display_name
			),
			'url'        => get_author_posts_url( $author->ID ),
			'mainEntity' => $person_node,
		);

		$this->output_jsonld( $schema );
	}

	/**
	 * Output Organization or Person schema on the homepage.
	 */
	private function organization_schema(): void {
		$entity_type = get_option( 'swps_schema_entity_type', 'Organization' );
		$name        = get_option( 'swps_schema_name', '' );

		if ( empty( $name ) ) {
			$name = get_bloginfo( 'name' );
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $entity_type,
			'name'     => $name,
			'url'      => home_url( '/' ),
		);

		// Logo (Organization only, per Google spec).
		if ( 'Organization' === $entity_type ) {
			$logo_url = get_option( 'swps_schema_logo', '' );

			if ( ! empty( $logo_url ) ) {
				$schema['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo_url,
				);
			}
		}

		// Social profiles.
		$social_raw = get_option( 'swps_schema_social_profiles', '' );

		if ( ! empty( $social_raw ) ) {
			$urls = array_filter( array_map( 'trim', explode( "\n", $social_raw ) ) );

			if ( ! empty( $urls ) ) {
				$schema['sameAs'] = array_values( $urls );
			}
		}

		/** Filter the Organization/Person schema before output. */
		$schema = SWPS_Hooks::filter_schema_organization( $schema );

		$this->output_jsonld( $schema );
	}
}
