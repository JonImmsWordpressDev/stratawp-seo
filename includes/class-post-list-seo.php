<?php
/**
 * SEO score column for the WordPress posts list table.
 *
 * Adds a colored circle with hover tooltip showing a 14-item
 * SEO checklist. Scores are cached as post meta and refreshed via AJAX.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Post_List_SEO {

	private SWPS_Content_Scorer $scorer;

	public function __construct( SWPS_Content_Scorer $scorer ) {
		$this->scorer = $scorer;

		$enabled_types = (array) get_option( 'swps_seo_column_post_types', array( 'post', 'page' ) );

		foreach ( $enabled_types as $post_type ) {
			if ( 'post' === $post_type ) {
				add_filter( 'manage_posts_columns', array( $this, 'add_column' ) );
				add_action( 'manage_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
			} elseif ( 'page' === $post_type ) {
				add_filter( 'manage_pages_columns', array( $this, 'add_column' ) );
				add_action( 'manage_pages_custom_column', array( $this, 'render_column' ), 10, 2 );
			} else {
				add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
				add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			}

			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'sortable_column' ) );
		}

		add_action( 'pre_get_posts', array( $this, 'handle_orderby' ) );
		add_action( 'save_post', array( $this, 'invalidate_score' ) );
		add_action( 'wp_ajax_swps_refresh_seo_score', array( $this, 'ajax_refresh' ) );
		add_action( 'wp_ajax_swps_bulk_refresh_seo_scores', array( $this, 'ajax_bulk_refresh' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the SEO column header.
	 */
	public function add_column( array $columns ): array {
		$columns['swps_seo'] = __( 'SEO', 'stratawp-seo' );
		return $columns;
	}

	/**
	 * Render the SEO score circle and tooltip for a post.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'swps_seo' !== $column ) {
			return;
		}

		$cached = get_post_meta( $post_id, '_swps_seo_score', true );

		if ( empty( $cached ) || ! is_array( $cached ) ) {
			echo '<div class="swps-seo-indicator" data-post-id="' . esc_attr( $post_id ) . '">';
			echo '<span class="swps-seo-circle swps-seo--no_keyword" title="' . esc_attr__( 'Not scored', 'stratawp-seo' ) . '">&mdash;</span>';
			echo '<div class="swps-seo-tooltip">';
			echo '<div class="swps-seo-tooltip__header">' . esc_html__( 'SEO Score', 'stratawp-seo' ) . '</div>';
			echo '<p>' . esc_html__( 'Score not calculated yet. Click Refresh to score this post.', 'stratawp-seo' ) . '</p>';
			echo '<div class="swps-seo-tooltip__actions">';
			echo '<a href="#" class="swps-seo-refresh" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( '🔄 Refresh', 'stratawp-seo' ) . '</a>';
			echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '#swps-meta-editor">' . esc_html__( '✏️ Edit SEO', 'stratawp-seo' ) . '</a>';
			echo '</div></div></div>';
			return;
		}

		$score  = (int) ( $cached['score'] ?? 0 );
		$status = $cached['status'] ?? 'poor';
		$checks = $cached['checks'] ?? array();

		if ( 'no_keyword' === $status ) {
			echo '<div class="swps-seo-indicator" data-post-id="' . esc_attr( $post_id ) . '">';
			echo '<span class="swps-seo-circle swps-seo--no_keyword" title="' . esc_attr__( 'No keyword', 'stratawp-seo' ) . '">&mdash;</span>';
			echo '<div class="swps-seo-tooltip">';
			echo '<div class="swps-seo-tooltip__header">' . esc_html__( 'SEO Score', 'stratawp-seo' ) . '</div>';
			echo '<p>' . esc_html__( 'Set a focus keyword to get your SEO score.', 'stratawp-seo' ) . '</p>';
			echo '<div class="swps-seo-tooltip__actions">';
			echo '<a href="#" class="swps-seo-refresh" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( '🔄 Refresh', 'stratawp-seo' ) . '</a>';
			echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '#swps-meta-editor">' . esc_html__( '✏️ Edit SEO', 'stratawp-seo' ) . '</a>';
			echo '</div></div></div>';
			return;
		}

		echo '<div class="swps-seo-indicator" data-post-id="' . esc_attr( $post_id ) . '">';
		echo '<span class="swps-seo-circle swps-seo--' . esc_attr( $status ) . '" title="' . esc_attr( sprintf( __( 'SEO: %d/100', 'stratawp-seo' ), $score ) ) . '">';
		echo esc_html( $score );
		echo '</span>';

		echo '<div class="swps-seo-tooltip">';
		echo '<div class="swps-seo-tooltip__header">';
		echo '<span class="swps-seo-circle swps-seo--' . esc_attr( $status ) . '">' . esc_html( $score ) . '</span> ';
		echo esc_html__( 'SEO Score', 'stratawp-seo' );
		echo '</div>';

		if ( ! empty( $checks ) ) {
			echo '<ul class="swps-seo-tooltip__checks">';
			foreach ( $checks as $check ) {
				$icon  = $check['pass'] ? '✅' : '❌';
				$class = $check['pass'] ? 'swps-check--pass' : 'swps-check--fail';
				$label = esc_html( $check['label'] );
				if ( ! empty( $check['value'] ) ) {
					$label .= ' <span class="swps-check-value">(' . esc_html( $check['value'] ) . ')</span>';
				}
				echo '<li class="' . esc_attr( $class ) . '">' . $icon . ' ' . $label . '</li>';
			}
			echo '</ul>';
		}

		echo '<div class="swps-seo-tooltip__actions">';
		echo '<a href="#" class="swps-seo-refresh" data-post-id="' . esc_attr( $post_id ) . '">' . esc_html__( '🔄 Refresh', 'stratawp-seo' ) . '</a>';
		echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '#swps-meta-editor">' . esc_html__( '✏️ Edit SEO', 'stratawp-seo' ) . '</a>';
		echo '</div>';

		echo '</div></div>';
	}

	/**
	 * Make the SEO column sortable.
	 */
	public function sortable_column( array $columns ): array {
		$columns['swps_seo'] = 'swps_seo_score';
		return $columns;
	}

	/**
	 * Handle orderby for SEO score column.
	 */
	public function handle_orderby( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'swps_seo_score' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_swps_seo_score_value' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Invalidate cached score on post save.
	 */
	public function invalidate_score( int $post_id ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		delete_post_meta( $post_id, '_swps_seo_score' );
		delete_post_meta( $post_id, '_swps_seo_score_value' );
	}

	/**
	 * AJAX: Refresh a single post's SEO score.
	 */
	public function ajax_refresh(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
		}

		$result = $this->scorer->score_post( $post_id );

		ob_start();
		$this->render_column( 'swps_seo', $post_id );
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'  => $html,
				'score' => $result['score'],
			)
		);
	}

	/**
	 * AJAX: Bulk refresh all post SEO scores in batches.
	 */
	public function ajax_bulk_refresh(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$offset = absint( $_POST['offset'] ?? 0 );
		$batch  = 20;

		$enabled_types = (array) get_option( 'swps_seo_column_post_types', array( 'post', 'page' ) );

		$posts = get_posts(
			array(
				'post_type'      => $enabled_types,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => $batch,
				'offset'         => $offset,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $pid ) {
			$this->scorer->score_post( $pid );
		}

		$total = 0;
		foreach ( $enabled_types as $pt ) {
			$counts = wp_count_posts( $pt );
			$total += ( $counts->publish ?? 0 ) + ( $counts->draft ?? 0 );
		}

		wp_send_json_success(
			array(
				'processed' => $offset + count( $posts ),
				'total'     => $total,
				'done'      => count( $posts ) < $batch,
			)
		);
	}

	/**
	 * Enqueue CSS/JS on edit.php screens only.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$enabled_types = (array) get_option( 'swps_seo_column_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $screen->post_type, $enabled_types, true ) ) {
			return;
		}

		wp_enqueue_style(
			'swps-admin',
			SWPS_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			SWPS_VERSION
		);

		wp_enqueue_script(
			'swps-post-list-seo',
			SWPS_PLUGIN_URL . 'admin/js/post-list-seo.js',
			array( 'jquery' ),
			SWPS_VERSION,
			true
		);

		wp_localize_script(
			'swps-post-list-seo',
			'swpsPostListSeo',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'swps_nonce' ),
			)
		);
	}
}
