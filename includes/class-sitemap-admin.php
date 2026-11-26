<?php
/**
 * Sitemap Admin page and AJAX handlers.
 *
 * Provides the dashboard for managing XML sitemaps with exclusion toggles.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Sitemap_Admin {

	/**
	 * Rows per page in the sitemap preview table.
	 */
	const PREVIEW_PER_PAGE = 100;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_swps_get_sitemaps', array( $this, 'ajax_get_sitemaps' ) );
		add_action( 'wp_ajax_swps_toggle_sitemap', array( $this, 'ajax_toggle_sitemap' ) );
	}

	/**
	 * Register the Sitemaps submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Sitemaps', 'stratawp-seo' ),
			__( 'Sitemaps', 'stratawp-seo' ),
			'manage_options',
			'swps-sitemaps',
			array( $this, 'render' )
		);
	}

	/**
	 * Register sitemap settings.
	 */
	public function register_settings(): void {
		register_setting(
			'swps_sitemap_settings',
			'swps_sitemap_exclude_images',
			array(
				'sanitize_callback' => function ( $value ) {
					return $value ? 1 : 0;
				},
			)
		);

		register_setting(
			'swps_sitemap_settings',
			'swps_sitemap_exclude_author',
			array(
				'sanitize_callback' => function ( $value ) {
					return $value ? 1 : 0;
				},
			)
		);

		register_setting(
			'swps_sitemap_settings',
			'swps_sitemap_news_enabled',
			array(
				'sanitize_callback' => function ( $value ) {
					return $value ? 1 : 0;
				},
			)
		);

		register_setting(
			'swps_sitemap_settings',
			'swps_sitemap_news_name',
			array(
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'swps_sitemap_settings',
			'swps_auto_redirect_slug_change',
			array(
				'sanitize_callback' => function ( $value ) {
					return $value ? 1 : 0;
				},
			)
		);

		register_setting(
			'swps_sitemap_settings',
			'swps_sitemap_urls_per_page',
			array(
				'sanitize_callback' => function ( $value ) {
					$value = absint( $value );
					if ( $value < 100 ) {
						$value = 100;
					}
					if ( $value > 50000 ) {
						$value = 50000;
					}
					return $value;
				},
			)
		);
	}

	/**
	 * Render the Sitemaps admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
		}

		include SWPS_PLUGIN_DIR . 'templates/sitemaps-page.php';
	}

	/**
	 * Map a sub-sitemap file name to the type and page the manager renders.
	 *
	 * @param string $file File name, e.g. post-sitemap2.xml or author-sitemap.xml.
	 * @return array{type: string, page: int}|null Null for anything else.
	 */
	public static function sitemap_file_request( string $file ): ?array {
		if ( ! preg_match( '/^([a-z0-9_-]+)-sitemap(\d*)\.xml$/', $file, $m ) ) {
			return null;
		}
		return array(
			'type' => $m[1],
			'page' => max( 1, (int) $m[2] ),
		);
	}

	/**
	 * Sub-sitemaps listed in a sitemap index.
	 *
	 * @param string $xml Sitemap index XML.
	 * @return array<int, array{file: string, loc: string, lastmod: string}>
	 */
	public static function parse_index_entries( string $xml ): array {
		$entries = array();
		if ( ! preg_match_all( '#<sitemap>(.*?)</sitemap>#s', $xml, $blocks ) ) {
			return $entries;
		}
		foreach ( $blocks[1] as $block ) {
			$loc = self::xml_tag_value( $block, 'loc' );
			if ( '' === $loc ) {
				continue;
			}
			$path      = (string) wp_parse_url( $loc, PHP_URL_PATH );
			$entries[] = array(
				'file'    => basename( $path ),
				'loc'     => $loc,
				'lastmod' => self::xml_tag_value( $block, 'lastmod' ),
			);
		}
		return $entries;
	}

	/**
	 * One page of the <url> entries in a sitemap.
	 *
	 * @param string $xml    Sitemap XML.
	 * @param int    $offset Entries to skip.
	 * @param int    $limit  Entries to return.
	 * @return array{total: int, rows: array<int, array{loc: string, lastmod: string, images: int}>}
	 */
	public static function parse_url_entries( string $xml, int $offset, int $limit ): array {
		$rows = array();
		if ( ! preg_match_all( '#<url>(.*?)</url>#s', $xml, $blocks ) ) {
			return array(
				'total' => 0,
				'rows'  => $rows,
			);
		}
		foreach ( array_slice( $blocks[1], max( 0, $offset ), max( 0, $limit ) ) as $block ) {
			$rows[] = array(
				'loc'     => self::xml_tag_value( $block, 'loc' ),
				'lastmod' => self::xml_tag_value( $block, 'lastmod' ),
				'images'  => substr_count( $block, '<image:image>' ),
			);
		}
		return array(
			'total' => count( $blocks[1] ),
			'rows'  => $rows,
		);
	}

	/**
	 * Decoded text of the first <$tag> in an XML fragment, or ''.
	 *
	 * @param string $xml XML fragment.
	 * @param string $tag Tag name.
	 */
	private static function xml_tag_value( string $xml, string $tag ): string {
		if ( ! preg_match( '#<' . preg_quote( $tag, '#' ) . '>(.*?)</' . preg_quote( $tag, '#' ) . '>#s', $xml, $m ) ) {
			return '';
		}
		return trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
	}

	/**
	 * Data for the Preview tab: the sub-sitemaps in the index and, when one
	 * is selected, a page of its entries. Reads the same XML crawlers get.
	 *
	 * @param string $file  Selected sub-sitemap file name, or ''.
	 * @param int    $paged Table page.
	 * @return array<string, mixed>
	 */
	public function get_preview( string $file, int $paged ): array {
		$manager  = StrataWP_SEO::instance()->sitemap_manager;
		$sitemaps = self::parse_index_entries( (string) $manager->get_sitemap_xml( 'index' ) );
		$preview  = array(
			'sitemaps' => $sitemaps,
			'selected' => null,
			'total'    => 0,
			'rows'     => array(),
			'paged'    => 1,
			'pages'    => 1,
		);

		// Only files the index lists can be previewed.
		$selected = null;
		foreach ( $sitemaps as $entry ) {
			if ( $entry['file'] === $file ) {
				$selected = $entry;
				break;
			}
		}
		$request = $selected ? self::sitemap_file_request( $selected['file'] ) : null;
		if ( ! $request ) {
			return $preview;
		}

		$xml = $manager->get_sitemap_xml( $request['type'], $request['page'] );
		if ( null === $xml ) {
			return $preview;
		}

		$total = (int) substr_count( $xml, '<url>' );
		$pages = max( 1, (int) ceil( $total / self::PREVIEW_PER_PAGE ) );
		$paged = min( max( 1, $paged ), $pages );
		$page  = self::parse_url_entries( $xml, ( $paged - 1 ) * self::PREVIEW_PER_PAGE, self::PREVIEW_PER_PAGE );

		$preview['selected'] = $selected;
		$preview['total']    = $page['total'];
		$preview['rows']     = $page['rows'];
		$preview['paged']    = $paged;
		$preview['pages']    = $pages;
		return $preview;
	}

	/**
	 * AJAX: Get all sitemaps with metadata.
	 */
	public function ajax_get_sitemaps(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$sitemaps = array();

		// Post type sitemaps.
		$post_types = get_post_types( array( 'public' => true ) );
		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt ) {
				continue;
			}

			$count         = $this->get_post_type_count( $pt );
			$lastmod       = $this->get_post_type_lastmod( $pt );
			$excluded      = (bool) get_option( "swps_sitemap_exclude_{$pt}", 0 );
			$post_type_obj = get_post_type_object( $pt );
			$label         = $post_type_obj ? $post_type_obj->label : $pt;

			$sitemaps[] = array(
				'key'      => $pt,
				'name'     => $label,
				'type'     => 'post_type',
				'url'      => home_url( "/{$pt}-sitemap.xml" ),
				'count'    => $count,
				'lastmod'  => $lastmod,
				'excluded' => $excluded,
			);
		}

		// Taxonomy sitemaps.
		$taxonomies = get_taxonomies( array( 'public' => true ) );
		foreach ( $taxonomies as $tax ) {
			if ( 'post_format' === $tax ) {
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

			$excluded   = (bool) get_option( "swps_sitemap_exclude_{$tax}", 0 );
			$tax_obj    = get_taxonomy( $tax );
			$label      = $tax_obj ? $tax_obj->label : $tax;
			$term_count = wp_count_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => true,
				)
			);

			$sitemaps[] = array(
				'key'      => $tax,
				'name'     => $label,
				'type'     => 'taxonomy',
				'url'      => home_url( "/{$tax}-sitemap.xml" ),
				'count'    => is_wp_error( $term_count ) ? 0 : (int) $term_count,
				'lastmod'  => gmdate( 'Y-m-d\TH:i:s+00:00' ),
				'excluded' => $excluded,
			);
		}

		// Author sitemap.
		$author_excluded = (bool) get_option( 'swps_sitemap_exclude_author', 0 );
		$author_count    = count(
			get_users(
				array(
					'has_published_posts' => true,
					'fields'              => 'ID',
				)
			)
		);

		$sitemaps[] = array(
			'key'      => 'author',
			'name'     => __( 'Authors', 'stratawp-seo' ),
			'type'     => 'author',
			'url'      => home_url( '/author-sitemap.xml' ),
			'count'    => $author_count,
			'lastmod'  => gmdate( 'Y-m-d\TH:i:s+00:00' ),
			'excluded' => $author_excluded,
		);

		$index_url = home_url( '/sitemap_index.xml' );

		wp_send_json_success(
			array(
				'sitemaps'  => $sitemaps,
				'index_url' => $index_url,
			)
		);
	}

	/**
	 * AJAX: Toggle sitemap exclusion status.
	 */
	public function ajax_toggle_sitemap(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$key = sanitize_text_field( $_POST['key'] ?? '' );
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => 'Missing sitemap key.' ) );
		}

		$option_name = "swps_sitemap_exclude_{$key}";
		$current     = (bool) get_option( $option_name, 0 );
		$new_value   = $current ? 0 : 1;

		update_option( $option_name, $new_value );

		wp_send_json_success(
			array(
				'key'      => $key,
				'excluded' => (bool) $new_value,
			)
		);
	}

	/**
	 * Get post count for a post type.
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
}
