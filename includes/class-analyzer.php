<?php
/**
 * Site content analyzer.
 *
 * Reads existing WordPress content to provide context for AI generation.
 * Uses cache manager for performance.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Analyzer {

	private ?SWPS_Cache_Manager $cache;

	public function __construct( ?SWPS_Cache_Manager $cache = null ) {
		$this->cache = $cache;
	}

	/**
	 * Get a structured summary of the site's content for Claude.
	 *
	 * @param int $max_posts Maximum posts to include.
	 * @return array Site summary data.
	 */
	public function get_site_summary( int $max_posts = 50 ): array {
		$cache_key = 'site_summary_' . $max_posts;

		if ( $this->cache ) {
			$cached = $this->cache->get( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$summary = array(
			'site_info'     => $this->get_site_info(),
			'posts'         => $this->get_posts_summary( $max_posts ),
			'categories'    => $this->get_categories(),
			'tags'          => $this->get_popular_tags( 30 ),
			'content_stats' => $this->get_content_stats(),
		);

		if ( $this->cache ) {
			$this->cache->set( $cache_key, $summary );
		}

		return $summary;
	}

	/**
	 * Get basic site information.
	 */
	public function get_site_info(): array {
		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'niche'       => get_option( 'swps_site_niche', '' ),
			'site_desc'   => get_option( 'swps_site_description', '' ),
		);
	}

	/**
	 * Get a summary of published posts (title, URL, excerpt, categories).
	 *
	 * @param int $max Maximum posts to retrieve.
	 * @return array Post summaries.
	 */
	public function get_posts_summary( int $max = 50 ): array {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $max,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$summaries = array();

		foreach ( $posts as $post ) {
			$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
			$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

			$content    = wp_strip_all_tags( $post->post_content );
			$words      = explode( ' ', $content );
			$excerpt    = implode( ' ', array_slice( $words, 0, 200 ) );
			$word_count = count( $words );

			$headings = $this->extract_headings( $post->post_content );

			$summaries[] = array(
				'id'                 => $post->ID,
				'title'              => $post->post_title,
				'url'                => get_permalink( $post->ID ),
				'slug'               => $post->post_name,
				'date'               => $post->post_date,
				'excerpt'            => $excerpt,
				'word_count'         => $word_count,
				'categories'         => $categories,
				'tags'               => $tags,
				'headings'           => $headings,
				'has_featured_image' => has_post_thumbnail( $post->ID ),
			);
		}

		return $summaries;
	}

	/**
	 * Get all categories with post counts.
	 */
	public function get_categories(): array {
		$categories = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		return array_map(
			function ( $cat ) {
				return array(
					'id'    => $cat->term_id,
					'name'  => $cat->name,
					'slug'  => $cat->slug,
					'count' => $cat->count,
					'url'   => get_category_link( $cat->term_id ),
				);
			},
			$categories
		);
	}

	/**
	 * Get popular tags.
	 *
	 * @param int $limit Max tags to return.
	 */
	public function get_popular_tags( int $limit = 30 ): array {
		$tags = get_tags(
			array(
				'orderby' => 'count',
				'order'   => 'DESC',
				'number'  => $limit,
			)
		);

		if ( is_wp_error( $tags ) || empty( $tags ) ) {
			return array();
		}

		return array_map(
			function ( $tag ) {
				return array(
					'name'  => $tag->name,
					'slug'  => $tag->slug,
					'count' => $tag->count,
				);
			},
			$tags
		);
	}

	/**
	 * Get overall content statistics.
	 */
	public function get_content_stats(): array {
		$post_count = wp_count_posts( 'post' );
		$page_count = wp_count_posts( 'page' );

		return array(
			'published_posts'  => (int) $post_count->publish,
			'draft_posts'      => (int) $post_count->draft,
			'published_pages'  => (int) $page_count->publish,
			'total_categories' => wp_count_terms( 'category' ),
			'total_tags'       => wp_count_terms( 'post_tag' ),
		);
	}

	/**
	 * Extract headings from post HTML content.
	 *
	 * @param string $content The post content HTML.
	 * @return array Array of headings [{level, text}].
	 */
	public function extract_headings( string $content ): array {
		$headings = array();

		if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/si', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$headings[] = array(
					'level' => (int) $match[1],
					'text'  => wp_strip_all_tags( $match[2] ),
				);
			}
		}

		return $headings;
	}

	/**
	 * Build a condensed text representation of the site for Claude's context.
	 *
	 * @param int $max_posts Maximum posts to include in context.
	 * @return string Formatted site context string.
	 */
	public function build_context_for_prompt( int $max_posts = 20 ): string {
		$cache_key = 'context_prompt_' . $max_posts;

		if ( $this->cache ) {
			$cached = $this->cache->get( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$summary = $this->get_site_summary( $max_posts );
		$info    = $summary['site_info'];

		$context  = "=== SITE INFORMATION ===\n";
		$context .= "Name: {$info['name']}\n";
		$context .= "URL: {$info['url']}\n";
		$context .= "Niche: {$info['niche']}\n";
		$context .= "Description: {$info['site_desc']}\n\n";

		$context .= "=== EXISTING CONTENT ({$summary['content_stats']['published_posts']} published posts) ===\n";

		foreach ( $summary['posts'] as $post ) {
			$cats     = implode( ', ', $post['categories'] );
			$context .= "- \"{$post['title']}\" ({$post['url']}) [{$cats}] {$post['word_count']} words\n";
			if ( ! empty( $post['excerpt'] ) ) {
				$short_excerpt = mb_substr( $post['excerpt'], 0, 150 );
				$context      .= "  Summary: {$short_excerpt}...\n";
			}
		}

		$context .= "\n=== CATEGORIES ===\n";
		foreach ( $summary['categories'] as $cat ) {
			$context .= "- {$cat['name']} ({$cat['count']} posts, {$cat['url']})\n";
		}

		if ( ! empty( $summary['tags'] ) ) {
			$tag_names = array_column( $summary['tags'], 'name' );
			$context  .= "\n=== POPULAR TAGS ===\n";
			$context  .= implode( ', ', $tag_names ) . "\n";
		}

		if ( $this->cache ) {
			$this->cache->set( $cache_key, $context );
		}

		return $context;
	}

	/**
	 * Get existing post titles and URLs for internal linking context.
	 *
	 * @return array Simplified list of [{title, url}].
	 */
	public function get_linkable_posts(): array {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return array_map(
			function ( $post ) {
				return array(
					'title' => $post->post_title,
					'url'   => get_permalink( $post->ID ),
				);
			},
			$posts
		);
	}
}
