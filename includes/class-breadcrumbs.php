<?php
/**
 * Frontend Breadcrumbs — HTML output with inline schema markup.
 *
 * Provides: swps_breadcrumbs() template function, [swps_breadcrumbs] shortcode.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Breadcrumbs {

	public function __construct() {
		add_shortcode( 'swps_breadcrumbs', array( $this, 'shortcode' ) );
	}

	/**
	 * Shortcode handler.
	 */
	public function shortcode(): string {
		ob_start();
		$this->render();
		return ob_get_clean();
	}

	/**
	 * Render breadcrumbs HTML with schema markup.
	 */
	public function render(): void {
		if ( ! get_option( 'swps_breadcrumbs_enabled', 1 ) ) {
			return;
		}

		// Skip on front page.
		if ( is_front_page() ) {
			return;
		}

		$crumbs    = $this->build_crumbs();
		$separator = get_option( 'swps_breadcrumbs_separator', '&raquo;' );
		$class     = get_option( 'swps_breadcrumbs_class', 'swps-breadcrumbs' );

		if ( empty( $crumbs ) ) {
			return;
		}

		echo '<nav aria-label="Breadcrumb" class="' . esc_attr( $class ) . '">';
		echo '<ol itemscope itemtype="https://schema.org/BreadcrumbList">';

		$position = 0;
		$last     = count( $crumbs ) - 1;

		foreach ( $crumbs as $i => $crumb ) {
			++$position;
			echo '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';

			if ( $i < $last && ! empty( $crumb['url'] ) ) {
				printf(
					'<a itemprop="item" href="%s"><span itemprop="name">%s</span></a>',
					esc_url( $crumb['url'] ),
					esc_html( $crumb['label'] )
				);
			} else {
				printf( '<span itemprop="name">%s</span>', esc_html( $crumb['label'] ) );
			}

			printf( '<meta itemprop="position" content="%d">', $position );
			echo '</li>';

			if ( $i < $last ) {
				printf( ' <span class="swps-breadcrumb-sep">%s</span> ', $separator );
			}
		}

		echo '</ol>';
		echo '</nav>';
	}

	/**
	 * Build the breadcrumb trail.
	 *
	 * @return array<array{label: string, url: string}>
	 */
	private function build_crumbs(): array {
		$home_label = get_option( 'swps_breadcrumbs_home_label', __( 'Home', 'stratawp-seo' ) );
		$crumbs     = array(
			array(
				'label' => $home_label,
				'url'   => home_url( '/' ),
			),
		);

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post ) {
				return $crumbs;
			}

			// Hierarchical pages: add parent chain.
			if ( is_page() && $post->post_parent ) {
				$ancestors = array_reverse( get_post_ancestors( $post ) );
				foreach ( $ancestors as $ancestor_id ) {
					$crumbs[] = array(
						'label' => get_the_title( $ancestor_id ),
						'url'   => get_permalink( $ancestor_id ),
					);
				}
			}

			// Posts: add primary category.
			if ( 'post' === $post->post_type ) {
				$categories = get_the_category( $post->ID );
				if ( ! empty( $categories ) ) {
					// Use Yoast primary category if set, otherwise first.
					$primary  = $categories[0];
					$crumbs[] = array(
						'label' => $primary->name,
						'url'   => get_category_link( $primary->term_id ),
					);
				}
			}

			// CPTs: add post type archive.
			$post_type = get_post_type_object( $post->post_type );
			if ( $post_type && $post_type->has_archive && 'post' !== $post->post_type ) {
				$crumbs[] = array(
					'label' => $post_type->labels->name,
					'url'   => get_post_type_archive_link( $post->post_type ),
				);
			}

			// Current post — use breadcrumb title override if set.
			$breadcrumb_title = get_post_meta( $post->ID, '_swps_breadcrumb_title', true );
			$crumbs[]         = array(
				'label' => ! empty( $breadcrumb_title ) ? $breadcrumb_title : $post->post_title,
				'url'   => '',
			);

		} elseif ( is_category() ) {
			$term = get_queried_object();
			// Parent categories.
			if ( $term->parent ) {
				$ancestors = array_reverse( get_ancestors( $term->term_id, 'category' ) );
				foreach ( $ancestors as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, 'category' );
					if ( $ancestor ) {
						$crumbs[] = array(
							'label' => $ancestor->name,
							'url'   => get_category_link( $ancestor_id ),
						);
					}
				}
			}
			$crumbs[] = array(
				'label' => $term->name,
				'url'   => '',
			);

		} elseif ( is_tag() ) {
			$crumbs[] = array(
				'label' => single_tag_title( '', false ),
				'url'   => '',
			);

		} elseif ( is_tax() ) {
			$term     = get_queried_object();
			$crumbs[] = array(
				'label' => $term->name,
				'url'   => '',
			);

		} elseif ( is_author() ) {
			$crumbs[] = array(
				'label' => get_the_author(),
				'url'   => '',
			);

		} elseif ( is_date() ) {
			if ( is_year() ) {
				$crumbs[] = array(
					'label' => get_the_date( 'Y' ),
					'url'   => '',
				);
			} elseif ( is_month() ) {
				$crumbs[] = array(
					'label' => get_the_date( 'Y' ),
					'url'   => get_year_link( get_the_date( 'Y' ) ),
				);
				$crumbs[] = array(
					'label' => get_the_date( 'F' ),
					'url'   => '',
				);
			} elseif ( is_day() ) {
				$crumbs[] = array(
					'label' => get_the_date( 'Y' ),
					'url'   => get_year_link( get_the_date( 'Y' ) ),
				);
				$crumbs[] = array(
					'label' => get_the_date( 'F' ),
					'url'   => get_month_link( get_the_date( 'Y' ), get_the_date( 'm' ) ),
				);
				$crumbs[] = array(
					'label' => get_the_date( 'j' ),
					'url'   => '',
				);
			}
		} elseif ( is_search() ) {
			$crumbs[] = array(
				'label' => sprintf( __( 'Search: %s', 'stratawp-seo' ), get_search_query() ),
				'url'   => '',
			);

		} elseif ( is_404() ) {
			$crumbs[] = array(
				'label' => __( 'Page Not Found', 'stratawp-seo' ),
				'url'   => '',
			);

		} elseif ( is_post_type_archive() ) {
			$crumbs[] = array(
				'label' => post_type_archive_title( '', false ),
				'url'   => '',
			);
		}

		return $crumbs;
	}
}

/**
 * Template function — call in theme templates.
 */
function swps_breadcrumbs(): void {
	$plugin = stratawp_seo();
	if ( isset( $plugin->breadcrumbs ) ) {
		$plugin->breadcrumbs->render();
	}
}
