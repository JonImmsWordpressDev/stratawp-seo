<?php
/**
 * Full XML Sitemap Manager.
 *
 * Generates sitemap index with sub-sitemaps for post types, taxonomies,
 * and authors. Supports per-URL priority/changefreq and image entries.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Sitemap_Manager {

	private function get_urls_per_sitemap(): int {
		return max( 100, min( 50000, (int) get_option( 'swps_sitemap_urls_per_page', 1000 ) ) );
	}
	private static function is_post_type_hidden_from_sitemap( string $post_type ): bool {
		return 'attachment' === $post_type
			|| (bool) get_option( "swps_sitemap_exclude_{$post_type}", 0 )
			|| (bool) get_option( "swps_noindex_{$post_type}", 0 );
	}

	private static function is_taxonomy_hidden_from_sitemap( string $taxonomy ): bool {
		return 'post_format' === $taxonomy
			|| (bool) get_option( "swps_sitemap_exclude_{$taxonomy}", 0 )
			|| (bool) get_option( "swps_noindex_{$taxonomy}", 0 );
	}

	/**
	 * Canonical "is this URL part of the sitemap" predicate, shared with IndexNow.
	 * Mirrors the inline skip logic in render_post_type_sitemap().
	 *
	 * @param WP_Post|object $post Post object with ID, post_status, post_type.
	 */
	public static function is_post_indexable( $post ): bool {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return false;
		}
		if ( 'publish' !== ( $post->post_status ?? '' ) ) {
			return false;
		}
		if ( self::is_post_type_hidden_from_sitemap( (string) ( $post->post_type ?? '' ) ) ) {
			return false;
		}
		if ( get_post_meta( $post->ID, '_swps_sitemap_exclude', true ) ) {
			return false;
		}
		$robots = get_post_meta( $post->ID, '_swps_robots', true );
		if ( ! empty( $robots ) && str_contains( (string) $robots, 'noindex' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Term eligibility, mirroring render_taxonomy_sitemap()'s inline checks.
	 * Also rejects terms whose taxonomy is hidden from the sitemap, so this
	 * is a self-contained, taxonomy-aware public API (later tasks call it
	 * directly on term-change hooks).
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name the term belongs to.
	 */
	public static function is_term_indexable( int $term_id, string $taxonomy ): bool {
		if ( self::is_taxonomy_hidden_from_sitemap( $taxonomy ) ) {
			return false;
		}
		if ( get_term_meta( $term_id, '_swps_sitemap_exclude', true ) ) {
			return false;
		}
		$robots = get_term_meta( $term_id, '_swps_robots', true );
		if ( ! empty( $robots ) && str_contains( (string) $robots, 'noindex' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Full indexable URL set (posts + pages + CPTs + taxonomies + authors),
	 * applying the same eligibility rules as the sitemap. Used by "Resubmit all".
	 *
	 * @return string[] Absolute URLs, deduped.
	 */
	public static function get_indexable_urls(): array {
		$urls = array( home_url( '/' ) );

		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( self::is_post_type_hidden_from_sitemap( $post_type ) ) {
				continue;
			}
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $posts as $post_id ) {
				$post = get_post( $post_id );
				if ( $post && self::is_post_indexable( $post ) ) {
					$urls[] = get_permalink( $post );
				}
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			if ( self::is_taxonomy_hidden_from_sitemap( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( self::is_term_indexable( (int) $term->term_id, $taxonomy ) ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		if ( ! get_option( 'swps_sitemap_exclude_author', 0 ) ) {
			$authors = get_users( array( 'has_published_posts' => true, 'fields' => 'ID' ) );
			foreach ( $authors as $author_id ) {
				$urls[] = get_author_posts_url( (int) $author_id );
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * The canonical, provider-aware sitemap URL to advertise in robots.txt and
	 * llms.txt. Single source of truth so the advertised location can never
	 * drift from what is actually served (see issue #48).
	 *
	 * StrataWP SEO serves its own index at /sitemap_index.xml (see
	 * register_rewrite_rules() and serve_sitemap()). When a major third-party
	 * SEO plugin is active we defer to its well-known sitemap location instead.
	 */
	public static function get_sitemap_url(): string {
		// Yoast SEO and Rank Math both serve their index at /sitemap_index.xml.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			return home_url( '/sitemap_index.xml' );
		}
		// All in One SEO serves its index at /sitemap.xml.
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return home_url( '/sitemap.xml' );
		}
		// StrataWP SEO disables WP core sitemaps and serves its own index.
		return home_url( '/sitemap_index.xml' );
	}

	/**
	 * Whether a resolved sitemap "type" is the legacy /swps-sitemap.xml URL
	 * that should 301 to the canonical index.
	 *
	 * The dedicated rewrite maps `swps-sitemap.xml` to `legacy_redirect`, but
	 * the generic `{type}-sitemap(\d*).xml` rule matches the same URL and can
	 * win the ordering, yielding the raw prefix `swps` instead. Treating both
	 * as the legacy redirect makes /swps-sitemap.xml resolve regardless of
	 * which rewrite rule fires first (no public post type/taxonomy is named
	 * `swps`, so there is nothing legitimate to shadow).
	 *
	 * @param string $type The resolved swps_sitemap query var.
	 */
	public static function is_legacy_redirect_type( string $type ): bool {
		return 'legacy_redirect' === $type || 'swps' === $type;
	}

	public function __construct() {
		// Disable WP core sitemaps to prevent conflict detection from blocking us.
		add_filter( 'wp_sitemaps_enabled', '__return_false' );

		// Rewrite rules.
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );

		// Auto-flush after a plugin upgrade so cached rewrite_rules pick up
		// new sitemap URLs without the user manually visiting Settings →
		// Permalinks. Runs once per version change.
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrites' ) );

		// Serve sitemaps.
		add_action( 'template_redirect', array( $this, 'serve_sitemap' ), 1 );
	}

	/**
	 * Register rewrite rules for all sitemap URLs.
	 */
	public function register_rewrite_rules(): void {
		// Sitemap index.
		add_rewrite_rule( 'sitemap_index\.xml$', 'index.php?swps_sitemap=index', 'top' );

		// Post type sitemaps.
		add_rewrite_rule( '([a-z0-9_-]+)-sitemap(\d*)\.xml$', 'index.php?swps_sitemap=$matches[1]&swps_sitemap_page=$matches[2]', 'top' );

		// Author sitemap.
		add_rewrite_rule( 'author-sitemap\.xml$', 'index.php?swps_sitemap=author', 'top' );

		// Legacy URL redirect.
		add_rewrite_rule( 'swps-sitemap\.xml$', 'index.php?swps_sitemap=legacy_redirect', 'top' );

		add_filter(
			'query_vars',
			function ( array $vars ): array {
				$vars[] = 'swps_sitemap';
				$vars[] = 'swps_sitemap_page';
				return $vars;
			}
		);
	}

	/**
	 * Flush rewrite rules once per plugin version so users do not have to
	 * manually re-save permalinks after every upgrade.
	 */
	public function maybe_flush_rewrites(): void {
		if ( get_option( 'swps_rewrite_version' ) === SWPS_VERSION ) {
			return;
		}
		update_option( 'swps_rewrite_version', SWPS_VERSION, false );
		flush_rewrite_rules( false );
	}

	/**
	 * Serve the appropriate sitemap based on the query var.
	 */
	public function serve_sitemap(): void {
		$type = get_query_var( 'swps_sitemap', '' );
		if ( empty( $type ) ) {
			return;
		}

		// Skip if another SEO plugin is actively handling sitemaps.
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return;
		}

		// Legacy redirect.
		if ( self::is_legacy_redirect_type( $type ) ) {
			wp_redirect( home_url( '/sitemap_index.xml' ), 301 );
			exit;
		}

		$page = (int) get_query_var( 'swps_sitemap_page', 1 );
		if ( $page < 1 ) {
			$page = 1;
		}

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );

		if ( 'index' === $type ) {
			$this->render_sitemap_index();
		} elseif ( 'author' === $type ) {
			$this->render_author_sitemap();
		} else {
			// Check if it's a taxonomy.
			$taxonomies = get_taxonomies( array( 'public' => true ) );
			if ( in_array( $type, $taxonomies, true ) && ! self::is_taxonomy_hidden_from_sitemap( $type ) ) {
				$this->render_taxonomy_sitemap( $type );
			} else {
				// Post type sitemap.
				$post_types = get_post_types( array( 'public' => true ) );
				if ( in_array( $type, $post_types, true ) && ! self::is_post_type_hidden_from_sitemap( $type ) ) {
					$this->render_post_type_sitemap( $type, $page );
				} else {
					status_header( 404 );
					exit;
				}
			}
		}

		exit;
	}

	/**
	 * Render the sitemap index.
	 */
	private function render_sitemap_index(): void {
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		// Post type sitemaps.
		$post_types = get_post_types( array( 'public' => true ) );
		foreach ( $post_types as $pt ) {
			if ( self::is_post_type_hidden_from_sitemap( $pt ) ) {
				continue;
			}

			$count = $this->get_post_type_count( $pt );
			$pages = max( 1, (int) ceil( $count / $this->get_urls_per_sitemap() ) );

			for ( $i = 1; $i <= $pages; $i++ ) {
				$suffix = $i > 1 ? $i : '';
				printf(
					"<sitemap>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n</sitemap>\n",
					esc_url( home_url( "/{$pt}-sitemap{$suffix}.xml" ) ),
					$this->get_post_type_lastmod( $pt )
				);
			}
		}

		// Taxonomy sitemaps.
		$taxonomies = get_taxonomies( array( 'public' => true ) );
		foreach ( $taxonomies as $tax ) {
			if ( self::is_taxonomy_hidden_from_sitemap( $tax ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => true,
					'number'     => 1,
				)
			);
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			printf(
				"<sitemap>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n</sitemap>\n",
				esc_url( home_url( "/{$tax}-sitemap.xml" ) ),
				$this->get_site_lastmod()
			);
		}

		// Author sitemap.
		if ( ! get_option( 'swps_sitemap_exclude_author', 0 ) ) {
			printf(
				"<sitemap>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n</sitemap>\n",
				esc_url( home_url( '/author-sitemap.xml' ) ),
				$this->get_site_lastmod()
			);
		}

		echo '</sitemapindex>';
	}

	/**
	 * Render a post type sub-sitemap.
	 */
	private function render_post_type_sitemap( string $post_type, int $page ): void {
		if ( self::is_post_type_hidden_from_sitemap( $post_type ) ) {
			return;
		}

		$offset = ( $page - 1 ) * $this->get_urls_per_sitemap();

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $this->get_urls_per_sitemap(),
				'offset'         => $offset,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

		// Include homepage on first page of 'page' sitemap.
		$front_page_id = 0;
		if ( 'page' === $post_type && 1 === $page ) {
			printf(
				"<url>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n  <priority>1.0</priority>\n</url>\n",
				esc_url( home_url( '/' ) ),
				$this->get_site_lastmod()
			);

			// When a static front page is set, it is emitted above as the
			// homepage URL. Skip it in the loop below so it is not listed
			// twice (the static front page's permalink is home_url('/')).
			if ( 'page' === (string) get_option( 'show_on_front' ) ) {
				$front_page_id = (int) get_option( 'page_on_front' );
			}
		}

		foreach ( $posts as $post ) {
			// Skip the static front page; already emitted as the homepage URL.
			if ( $front_page_id && (int) $post->ID === $front_page_id ) {
				continue;
			}

			// Keep in sync with SWPS_Sitemap_Manager::is_post_indexable().
			// Respect exclusions.
			if ( get_post_meta( $post->ID, '_swps_sitemap_exclude', true ) ) {
				continue;
			}

			// Respect noindex.
			$robots = get_post_meta( $post->ID, '_swps_robots', true );
			if ( ! empty( $robots ) && str_contains( (string) $robots, 'noindex' ) ) {
				continue;
			}

			$modified = get_post_modified_time( 'U', true, $post );
			$priority = get_post_meta( $post->ID, '_swps_sitemap_priority', true );
			if ( empty( $priority ) ) {
				$priority = 'page' === $post_type ? '0.8' : '0.6';
			}
			$changefreq = get_post_meta( $post->ID, '_swps_sitemap_changefreq', true ) ?: 'weekly';

			echo "<url>\n";
			printf( "  <loc>%s</loc>\n", esc_url( get_permalink( $post ) ) );
			printf( "  <lastmod>%s</lastmod>\n", gmdate( 'Y-m-d\TH:i:s+00:00', $modified ) );
			printf( "  <changefreq>%s</changefreq>\n", esc_xml( $changefreq ) );
			printf( "  <priority>%s</priority>\n", esc_attr( $priority ) );

			// Image entries.
			if ( ! get_option( 'swps_sitemap_exclude_images', 0 ) ) {
				$this->render_image_entries( $post->ID );
			}

			echo "</url>\n";
		}

		echo '</urlset>';
	}

	/**
	 * Render a taxonomy sub-sitemap.
	 */
	private function render_taxonomy_sitemap( string $taxonomy ): void {
		if ( self::is_taxonomy_hidden_from_sitemap( $taxonomy ) ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => $this->get_urls_per_sitemap(),
			)
		);

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				// Keep in sync with SWPS_Sitemap_Manager::is_term_indexable().
				// Respect exclusions.
				if ( get_term_meta( $term->term_id, '_swps_sitemap_exclude', true ) ) {
					continue;
				}

				$robots = get_term_meta( $term->term_id, '_swps_robots', true );
				if ( ! empty( $robots ) && str_contains( (string) $robots, 'noindex' ) ) {
					continue;
				}

				echo "<url>\n";
				printf( "  <loc>%s</loc>\n", esc_url( get_term_link( $term ) ) );
				printf( "  <changefreq>weekly</changefreq>\n" );
				printf( "  <priority>0.4</priority>\n" );
				echo "</url>\n";
			}
		}

		echo '</urlset>';
	}

	/**
	 * Render author sub-sitemap.
	 */
	private function render_author_sitemap(): void {
		$authors = get_users(
			array(
				'has_published_posts' => true,
				'fields'              => array( 'ID', 'user_nicename' ),
			)
		);

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $authors as $author ) {
			echo "<url>\n";
			printf( "  <loc>%s</loc>\n", esc_url( get_author_posts_url( $author->ID ) ) );
			printf( "  <changefreq>weekly</changefreq>\n" );
			printf( "  <priority>0.3</priority>\n" );
			echo "</url>\n";
		}

		echo '</urlset>';
	}

	/**
	 * Render image entries for a post.
	 */
	private function render_image_entries( int $post_id ): void {
		// Featured image.
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			$img_url = wp_get_attachment_url( $thumb_id );
			if ( $img_url ) {
				echo "  <image:image>\n";
				printf( "    <image:loc>%s</image:loc>\n", esc_url( $img_url ) );
				$alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
				if ( $alt ) {
					printf( "    <image:title>%s</image:title>\n", esc_xml( $alt ) );
				}
				echo "  </image:image>\n";
			}
		}

		// In-content images.
		$post = get_post( $post_id );
		if ( $post && preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches ) ) {
			$seen = array( $thumb_id ? wp_get_attachment_url( $thumb_id ) : '' );
			foreach ( $matches[1] as $img_url ) {
				if ( in_array( $img_url, $seen, true ) ) {
					continue;
				}
				$seen[] = $img_url;
				echo "  <image:image>\n";
				printf( "    <image:loc>%s</image:loc>\n", esc_url( $img_url ) );
				echo "  </image:image>\n";
			}
		}
	}

	/**
	 * Get post count for a post type (excluding sitemap-excluded posts).
	 */
	private function get_post_type_count( string $post_type ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				$post_type
			)
		);
	}

	/**
	 * Get the most recent lastmod for a post type.
	 */
	private function get_post_type_lastmod( string $post_type ): string {
		global $wpdb;
		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				$post_type
			)
		);
		return $date ? gmdate( 'Y-m-d\TH:i:s+00:00', strtotime( $date ) ) : gmdate( 'Y-m-d\TH:i:s+00:00' );
	}

	/**
	 * Site-wide last modification timestamp for sitemap entries that
	 * aggregate many objects (taxonomy/author sub-sitemaps, homepage URL).
	 *
	 * Previously these were stamped gmdate(now), which changed on every
	 * request — a volatile lastmod is an unreliable crawl-scheduling signal.
	 */
	private function get_site_lastmod(): string {
		$modified = get_lastpostmodified( 'gmt' );
		return $modified
			? gmdate( 'Y-m-d\TH:i:s+00:00', strtotime( $modified ) )
			: gmdate( 'Y-m-d\TH:i:s+00:00' );
	}
}
