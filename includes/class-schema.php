<?php
/**
 * Schema / Structured Data output.
 *
 * Outputs a single JSON-LD @graph block on every frontend page when StrataWP
 * SEO's schema module is active (i.e. no Yoast / RankMath / AIOSEO active).
 * Graph composition:
 *   - Front page:  Organization, WebSite
 *   - All pages:   BreadcrumbList (when HTML breadcrumbs disabled)
 *   - Singular post: Article (→ author Person, publisher Organization)
 *   - Author archive: ProfilePage (→ Person mainEntity)
 *   - FAQ / Key Takeaways post meta: absorbed as graph nodes when this module
 *     is active; standalone fallback from stratawp-seo.php when deferred.
 *
 * Three defer policies (unchanged from pre-graph):
 *   1. Legacy schema set (Organisation/WebSite/Article/Breadcrumb):
 *      bail entirely when Yoast / RankMath / AIOSEO is active.
 *   2. AEO post schema (HowTo/Recipe/…): always registers; defers per-type via
 *      is_aeo_schema_deferred() unless swps_schema_override is on.
 *   3. FAQ / Takeaways: absorbed into graph when active; standalone fallback in
 *      stratawp-seo.php only fires when this module is deferred (duplicate-safe).
 *
 * INTENTIONAL DIVERGENCE — AEO stays outside the @graph:
 *   AEO types (HowTo/Recipe/Product/Review/FAQPage from _swps_aeo_schema_json)
 *   are emitted as a SEPARATE standalone ld+json block at wp_head priority 10,
 *   not merged into the @graph. Their defer logic is independent of the main
 *   module: the swps_schema_override option can re-enable AEO output even when
 *   the @graph is fully deferred to Yoast/RankMath/AIOSEO. Merging them would
 *   couple the two defer policies and break the override path. So a page with
 *   an AEO type assigned legitimately carries TWO ld+json blocks: one @graph
 *   (when active) + one AEO block.
 *
 * Legacy filter back-compat:
 *   swps_schema_article, swps_schema_breadcrumb, swps_schema_organization each
 *   receive a standalone-shaped copy of the node (with @context injected before
 *   the filter, stripped after). The returned data is normalised and merged into
 *   the graph. Third-party consumers may modify the node body as before; the
 *   one behavioral difference is that output now appears inside one @graph block.
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
 *   - maybe_render_aeo_schema at wp_head priority 10 (always, internal defer)
 *   - output_schema at wp_head priority 1 (only when no other SEO plugin active)
 */
class SWPS_Schema {

	/**
	 * Detects whether the main schema module (legacy set + graph) is deferred
	 * to another SEO plugin.
	 *
	 * @return bool True when Yoast / RankMath / AIOSEO is active.
	 */
	public static function is_main_schema_deferred(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' );
	}

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
		// deferral check inside the callback.
		add_action( 'wp_head', array( $this, 'maybe_render_aeo_schema' ), 10 );

		// Defer the legacy schema set (and @graph) to other SEO plugins.
		if ( self::is_main_schema_deferred() ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'output_schema' ), 1 );
	}

	// -------------------------------------------------------------------------
	// Main output
	// -------------------------------------------------------------------------

	/**
	 * Main wp_head callback — builds and emits a single @graph block.
	 *
	 * Organization is emitted on every page so Article/WebPage nodes can
	 * reference it by @id without creating orphan refs. WebSite is front-page
	 * only (it carries the SearchAction sitelinks box).
	 */
	public function output_schema(): void {
		$graph = new SWPS_Schema_Graph();

		// Organization always present — referenced by Article.publisher and WebPage.
		$this->add_organization_node( $graph );

		if ( is_front_page() ) {
			$this->add_website_node( $graph );
		}

		// LocalBusiness absorbed into the graph on the homepage when Local SEO is on.
		if ( is_front_page() || is_home() ) {
			$this->add_local_business_node( $graph );
		}

		// WebPage node on every non-front-page so Article.mainEntityOfPage resolves.
		if ( ! is_front_page() ) {
			$this->add_webpage_node( $graph );
		}

		if ( is_singular( 'post' ) ) {
			$this->add_article_node( $graph );
		}

		// JSON-LD breadcrumbs — Google's preferred breadcrumb format. Previously
		// gated on the HTML breadcrumbs toggle being OFF, which meant a default
		// install (toggle on, but theme never calling swps_breadcrumbs()) shipped
		// no BreadcrumbList markup at all. Now emitted by default; sites whose
		// theme renders the microdata trail can turn this off to avoid the
		// harmless duplicate.
		if ( ! is_front_page() && get_option( 'swps_breadcrumbs_jsonld', 1 ) ) {
			$this->add_breadcrumb_node( $graph );
		}

		if ( is_author() ) {
			$this->add_author_nodes( $graph );
		}

		// FAQ / Key Takeaways — absorb into graph when schema is active.
		// The standalone hooks in stratawp-seo.php are gated on is_main_schema_deferred()
		// so they fire ONLY under Yoast/RankMath/AIOSEO; this path handles the
		// active-schema case, preventing duplicate emission.
		if ( is_singular( 'post' ) ) {
			$this->add_faq_node( $graph );
			$this->add_takeaways_node( $graph );
		}

		$graph->output();
	}

	// -------------------------------------------------------------------------
	// AEO (unchanged from pre-graph)
	// -------------------------------------------------------------------------

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
		if ( self::is_main_schema_deferred() ) {
			return ! (bool) get_option( 'swps_schema_override', false );
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Graph node builders
	// -------------------------------------------------------------------------

	/**
	 * Add the Organization (or Person for personal-brand sites) node.
	 *
	 * Legacy filter: swps_schema_organization receives a standalone-shaped copy
	 * (with @context). The returned array is stripped of @context and merged.
	 *
	 * @param SWPS_Schema_Graph $graph Target graph.
	 */
	private function add_organization_node( SWPS_Schema_Graph $graph ): void {
		$entity_type = get_option( 'swps_schema_entity_type', 'Organization' );
		$name        = get_option( 'swps_schema_name', '' );

		if ( empty( $name ) ) {
			$name = get_bloginfo( 'name' );
		}

		$node = array(
			'@context' => 'https://schema.org',
			'@type'    => $entity_type,
			'@id'      => SWPS_Schema_Graph::org_id(),
			'name'     => $name,
			'url'      => home_url( '/' ),
		);

		if ( 'Organization' === $entity_type ) {
			$logo_url = get_option( 'swps_schema_logo', '' );
			if ( ! empty( $logo_url ) ) {
				$node['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo_url,
				);
			}
		}

		$social_raw = get_option( 'swps_schema_social_profiles', '' );
		if ( ! empty( $social_raw ) ) {
			$urls = array_values( array_filter( array_map( 'trim', explode( "\n", $social_raw ) ) ) );
			if ( ! empty( $urls ) ) {
				$node['sameAs'] = $urls;
			}
		}

		// Legacy filter shim — receives standalone-shaped copy with @context; see class-hooks.php.
		$node = SWPS_Schema_Graph::normalize_node(
			SWPS_Hooks::filter_schema_organization( $node ),
			SWPS_Schema_Graph::org_id()
		);

		$graph->add_node( $node );
	}

	/**
	 * Add a WebPage node for the current URL.
	 *
	 * Emitted on every non-front-page so Article.mainEntityOfPage resolves
	 * within the same @graph without creating orphan @id refs.
	 *
	 * @param SWPS_Schema_Graph $graph Target graph.
	 */
	private function add_webpage_node( SWPS_Schema_Graph $graph ): void {
		$url = (string) ( is_singular() ? get_permalink() : get_pagenum_link() );
		if ( '' === $url ) {
			return;
		}

		$page_title = wp_strip_all_tags( (string) wp_title( '|', false, 'right' ) );
		$node       = array(
			'@type' => 'WebPage',
			'@id'   => SWPS_Schema_Graph::webpage_id( $url ),
			'url'   => $url,
			'name'  => '' !== $page_title ? $page_title : get_bloginfo( 'name' ),
		);

		$graph->add_node( $node );
	}

	/**
	 * Add the WebSite node.
	 *
	 * @param SWPS_Schema_Graph $graph Target graph.
	 */
	private function add_website_node( SWPS_Schema_Graph $graph ): void {
		$name = get_option( 'swps_schema_name', '' );
		if ( empty( $name ) ) {
			$name = get_bloginfo( 'name' );
		}

		$node = array(
			'@type' => 'WebSite',
			'@id'   => SWPS_Schema_Graph::website_id(),
			'name'  => $name,
			'url'   => home_url( '/' ),
		);

		if ( get_option( 'swps_schema_searchbox', 1 ) ) {
			$node['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			);
		}

		$graph->add_node( $node );
	}

	/**
	 * Absorb the Local SEO LocalBusiness node into the graph (homepage only).
	 *
	 * The node carries a stable @id and a link to the site entity, so it is
	 * connected within the @graph rather than emitted as a disconnected block.
	 *
	 * @param SWPS_Schema_Graph $graph Target graph.
	 */
	private function add_local_business_node( SWPS_Schema_Graph $graph ): void {
		if ( ! class_exists( 'SWPS_Local_SEO' ) ) {
			return;
		}

		$opts = get_option( 'swps_local_seo', array() );
		if ( empty( $opts['enabled'] ) ) {
			return;
		}

		// Same "meaningful data" threshold as SWPS_Local_SEO::is_enabled() —
		// without it, enabled + name but no locality emitted a thin,
		// address-less LocalBusiness node.
		if ( '' === trim( (string) ( $opts['name'] ?? '' ) ) || '' === trim( (string) ( $opts['locality'] ?? '' ) ) ) {
			return;
		}

		/** This filter mirrors the legacy standalone emitter's context gate. */
		if ( ! (bool) apply_filters( 'swps_local_seo_emit', true ) ) {
			return;
		}

		$node = SWPS_Local_SEO::graph_node();
		if ( ! empty( $node ) ) {
			$graph->add_node(
				SWPS_Schema_Graph::normalize_node( $node, SWPS_Schema_Graph::local_business_id() )
			);
		}
	}

	/**
	 * Add the Article node (references author Person by @id).
	 *
	 * Legacy filter: swps_schema_article receives a standalone-shaped copy
	 * (with @context). The returned array is stripped of @context and merged.
	 *
	 * @param SWPS_Schema_Graph $graph Target graph.
	 */
	private function add_article_node( SWPS_Schema_Graph $graph ): void {
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$article_type = get_option( 'swps_schema_article_type', 'Article' );
		$author       = get_userdata( $post->post_author );
		$permalink    = (string) get_permalink( $post );
		$excerpt      = get_the_excerpt( $post );

		if ( empty( $excerpt ) ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 25, '...' );
		}

		$node = array(
			'@context'         => 'https://schema.org',
			'@type'            => $article_type,
			'@id'              => SWPS_Schema_Graph::article_id( $permalink ),
			'headline'         => get_the_title( $post ),
			'description'      => $excerpt,
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'mainEntityOfPage' => array( '@id' => SWPS_Schema_Graph::webpage_id( $permalink ) ),
			'wordCount'        => str_word_count( wp_strip_all_tags( $post->post_content ) ),
		);

		// Publisher — Google requires Organization type for Article.publisher.
		// On personal-brand sites (swps_schema_entity_type = Person) the site
		// entity node is Person-typed, so referencing it would emit an invalid
		// Person publisher. Publisher is a recommended (not required) Article
		// property, so we omit it entirely in that case.
		if ( 'Organization' === get_option( 'swps_schema_entity_type', 'Organization' ) ) {
			$node['publisher'] = array( '@id' => SWPS_Schema_Graph::org_id() );
		}

		// Author node: store inline for the filter shim (back-compat), then
		// replace with an @id reference after the filter runs. That way
		// swps_schema_article filter consumers can still read/modify author data.
		if ( $author ) {
			$node['author'] = $this->build_person_node( $author );
			// Organization-entity sites get a standalone author Person node. On
			// personal-brand (Person) sites the site entity (#person) IS the author,
			// so we skip the duplicate node and point author at the main entity after
			// the filter runs — keeping one coherent Person across the whole graph.
			if ( 'Person' !== get_option( 'swps_schema_entity_type', 'Organization' ) ) {
				$person_node = array_merge(
					array( '@id' => SWPS_Schema_Graph::person_id( $author->ID ) ),
					$node['author']
				);
				$graph->add_node( $person_node );
			}
		}

		$thumb_id = get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$img_data = wp_get_attachment_image_src( $thumb_id, 'full' );
			if ( $img_data ) {
				$node['image'] = array(
					'@type'  => 'ImageObject',
					'url'    => $img_data[0],
					'width'  => $img_data[1],
					'height' => $img_data[2],
				);
			}
		}

		/**
		 * Filter the Article schema before output (legacy shim).
		 *
		 * Receives a standalone-shaped copy with @context. The @context is
		 * stripped after the filter; output location changes from a separate
		 * <script> tag to a node inside the @graph envelope.
		 *
		 * @param array $node    Article schema array.
		 * @param int   $post_id Post ID.
		 */
		$node = SWPS_Schema_Graph::normalize_node(
			SWPS_Hooks::filter_schema_article( $node, $post->ID ),
			SWPS_Schema_Graph::article_id( $permalink )
		);

		// After filter: replace inline author object with an @id reference so the
		// graph stays interlinked. On personal-brand (Person) sites that @id is the
		// single #person entity; on Organization sites it's the standalone author node.
		if ( $author && isset( $node['author'] ) && is_array( $node['author'] ) ) {
			$node['author'] = array(
				'@id' => 'Person' === get_option( 'swps_schema_entity_type', 'Organization' )
					? SWPS_Schema_Graph::org_id()
					: SWPS_Schema_Graph::person_id( $author->ID ),
			);
		}

		$graph->add_node( $node );
	}

	/**
	 * Add the BreadcrumbList node.
	 *
	 * Legacy filter: swps_schema_breadcrumb receives a standalone-shaped copy.
	 *
	 * @param SWPS_Schema_Graph $graph Target graph.
	 */
	private function add_breadcrumb_node( SWPS_Schema_Graph $graph ): void {
		$items = array();
		$url   = (string) ( is_singular() ? get_permalink() : '' );

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

		$bc_id = SWPS_Schema_Graph::breadcrumb_id( '' !== $url ? $url : home_url( '/' ) );

		$node = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'@id'             => $bc_id,
			'itemListElement' => $list_items,
		);

		// Legacy filter shim — receives standalone-shaped copy with @context; see class-hooks.php.
		$node = SWPS_Schema_Graph::normalize_node(
			SWPS_Hooks::filter_schema_breadcrumb( $node ),
			$bc_id
		);

		$graph->add_node( $node );
	}

	/**
	 * Add the ProfilePage + Person nodes for author archives.
	 *
	 * @param SWPS_Schema_Graph $graph Target graph.
	 */
	private function add_author_nodes( SWPS_Schema_Graph $graph ): void {
		$author = get_queried_object();
		if ( ! ( $author instanceof WP_User ) ) {
			return;
		}

		$author_url = get_author_posts_url( $author->ID );
		$person_id  = SWPS_Schema_Graph::person_id( $author->ID );

		// Full Person node.
		$person = array_merge(
			array( '@id' => $person_id ),
			$this->build_person_node( $author )
		);
		$graph->add_node( $person );

		// ProfilePage referencing Person.
		$profile_node = array(
			'@type'      => 'ProfilePage',
			'@id'        => SWPS_Schema_Graph::profile_page_id( $author_url ),
			'name'       => sprintf(
				/* translators: %s: author display name */
				__( 'Author: %s', 'stratawp-seo' ),
				$author->display_name
			),
			'url'        => $author_url,
			'mainEntity' => array( '@id' => $person_id ),
		);
		$graph->add_node( $profile_node );
	}

	/**
	 * Add FAQ schema as a graph node (when post meta is present).
	 *
	 * This absorbs the FAQ/takeaways emission that previously came from
	 * standalone hooks in stratawp-seo.php. Those standalone hooks still fire
	 * ONLY when the main schema module is deferred (under Yoast/RankMath/AIOSEO)
	 * — see the gating condition added in stratawp-seo.php — so emission is
	 * always exactly once.
	 *
	 * @param SWPS_Schema_Graph $graph Target graph.
	 */
	private function add_faq_node( SWPS_Schema_Graph $graph ): void {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		// Re-use the normalisation logic from stratawp-seo.php. That method is
		// on the main plugin class which isn't available here, so we call
		// the FAQ schema helper directly via the plugin instance or duplicate
		// the minimal normalization inline.
		$schema = get_post_meta( $post_id, '_swps_faq_schema', true );
		$node   = SWPS_Schema_Graph::normalize_faq_schema( $schema );

		if ( empty( $node ) ) {
			return;
		}

		// Assign @id and strip standalone @context for graph inclusion.
		$permalink   = (string) get_permalink( $post_id );
		$node['@id'] = trailingslashit( $permalink ) . '#faqpage';
		unset( $node['@context'] );

		$graph->add_node( $node );
	}

	/**
	 * Add Key Takeaways ItemList node (when post meta is present).
	 *
	 * Same duplicate-prevention guarantee as add_faq_node().
	 *
	 * @param SWPS_Schema_Graph $graph Target graph.
	 */
	private function add_takeaways_node( SWPS_Schema_Graph $graph ): void {
		if ( ! get_option( 'swps_takeaways_schema', 1 ) ) {
			return;
		}

		$post_id   = get_the_ID();
		$takeaways = $post_id ? get_post_meta( $post_id, '_swps_key_takeaways', true ) : null;

		if ( empty( $takeaways ) || ! is_array( $takeaways ) ) {
			return;
		}

		$items = array();
		foreach ( $takeaways as $index => $takeaway ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $takeaway,
			);
		}

		$permalink = (string) get_permalink( $post_id );

		$node = array(
			'@type'           => 'ItemList',
			'@id'             => trailingslashit( $permalink ) . '#takeaways',
			'name'            => __( 'Key Takeaways', 'stratawp-seo' ),
			'numberOfItems'   => count( $takeaways ),
			'itemListElement' => $items,
		);

		// Legacy filter shim — same public filter (swps_takeaways_schema) the
		// standalone path applies; receives the graph-shaped node (with @id).
		$node = SWPS_Hooks::filter_takeaways_schema( $node, $takeaways );

		$graph->add_node( $node );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a Person schema node for an author, including E-E-A-T fields when set.
	 *
	 * Always includes @type, name, url. Adds sameAs/jobTitle/description only
	 * when the meta values are non-empty (no empty strings in JSON-LD).
	 *
	 * @param WP_User $author The author user object.
	 * @return array Person schema node (no @context, no @id).
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
	 * Get the primary category for a post.
	 *
	 * Uses Yoast primary category if set; falls back to first category.
	 *
	 * @param WP_Post $post Post object.
	 * @return WP_Term|null Category term or null.
	 */
	private function get_primary_category( WP_Post $post ): ?WP_Term {
		$primary_id = get_post_meta( $post->ID, '_yoast_wpseo_primary_category', true );

		if ( $primary_id ) {
			$term = get_term( (int) $primary_id, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		$categories = get_the_category( $post->ID );
		return ! empty( $categories ) ? $categories[0] : null;
	}

	/**
	 * Output a JSON-LD script tag from a schema array.
	 *
	 * Used by maybe_render_aeo_schema() for standalone AEO nodes that are
	 * intentionally emitted outside the @graph (AEO has its own defer logic).
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
}
