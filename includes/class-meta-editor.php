<?php
/**
 * SEO Meta Editor — per-post meta fields and frontend output.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Meta_Editor {

	private bool $conflict = false;

	public function __construct() {
		// Detect conflicting SEO plugins.
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			$this->conflict = true;
		}

		if ( ! get_option( 'swps_meta_editor_enabled', 1 ) ) {
			return;
		}

		// Admin: register metabox and save hook.
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'save_post', array( $this, 'save_meta' ), 10, 2 );

		// AJAX: AI-generate meta.
		add_action( 'wp_ajax_swps_generate_meta', array( $this, 'ajax_generate_meta' ) );
		add_action( 'wp_ajax_swps_bulk_generate_meta', array( $this, 'ajax_bulk_generate_meta' ) );

		// Frontend output — only when no conflict.
		if ( ! $this->conflict && ! is_admin() ) {
			add_action( 'wp_head', array( $this, 'output_meta_tags' ), 2 );
			add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );
			add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 20 );
			add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
		}

		// Auto-generate meta on publish if setting is enabled.
		if ( get_option( 'swps_meta_auto_generate', 0 ) ) {
			add_action( 'transition_post_status', array( $this, 'maybe_auto_generate' ), 10, 3 );
		}

		// Admin notice if conflict detected.
		if ( $this->conflict ) {
			add_action( 'admin_notices', array( $this, 'conflict_notice' ) );
		}
	}

	/**
	 * Check if there's an SEO plugin conflict.
	 */
	public function has_conflict(): bool {
		return $this->conflict;
	}

	/**
	 * Register the SEO metabox on configured post types.
	 */
	public function register_metabox(): void {
		$post_types = $this->get_enabled_post_types();

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'swps_meta_editor',
				__( 'StrataWP SEO', 'stratawp-seo' ),
				array( $this, 'render_metabox' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render the SEO metabox.
	 */
	public function render_metabox( WP_Post $post ): void {
		wp_nonce_field( 'swps_meta_editor', 'swps_meta_editor_nonce' );

		$meta_title       = get_post_meta( $post->ID, '_swps_meta_title', true );
		$meta_description = get_post_meta( $post->ID, '_swps_meta_description', true );
		$focus_keyword    = get_post_meta( $post->ID, '_swps_focus_keyword', true );
		$secondary_kws    = get_post_meta( $post->ID, '_swps_secondary_keywords', true );
		$canonical_url    = get_post_meta( $post->ID, '_swps_canonical_url', true );
		$robots           = get_post_meta( $post->ID, '_swps_robots', true );
		$breadcrumb_title = get_post_meta( $post->ID, '_swps_breadcrumb_title', true );
		$social_title     = get_post_meta( $post->ID, '_swps_social_title', true );
		$social_desc      = get_post_meta( $post->ID, '_swps_social_description', true );
		$social_image     = get_post_meta( $post->ID, '_swps_social_image', true );

		// Sitemap controls.
		$sitemap_exclude    = get_post_meta( $post->ID, '_swps_sitemap_exclude', true );
		$sitemap_priority   = get_post_meta( $post->ID, '_swps_sitemap_priority', true );
		$sitemap_changefreq = get_post_meta( $post->ID, '_swps_sitemap_changefreq', true );

		if ( $this->conflict ) {
			echo '<div class="notice notice-warning inline"><p>';
			esc_html_e( 'Meta output is disabled because another SEO plugin is active. Fields are saved but not output on the frontend.', 'stratawp-seo' );
			echo '</p></div>';
		}

		include SWPS_PLUGIN_DIR . 'templates/meta-editor-metabox.php';
	}

	/**
	 * Save meta fields on post save.
	 */
	public function save_meta( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['swps_meta_editor_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['swps_meta_editor_nonce'], 'swps_meta_editor' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'_swps_meta_title'         => 'sanitize_text_field',
			'_swps_meta_description'   => 'sanitize_textarea_field',
			'_swps_focus_keyword'      => 'sanitize_text_field',
			'_swps_secondary_keywords' => 'sanitize_text_field',
			'_swps_canonical_url'      => 'esc_url_raw',
			'_swps_robots'             => array( $this, 'sanitize_robots' ),
			'_swps_breadcrumb_title'   => 'sanitize_text_field',
			'_swps_social_title'       => 'sanitize_text_field',
			'_swps_social_description' => 'sanitize_textarea_field',
			'_swps_social_image'       => 'esc_url_raw',
		);

		foreach ( $fields as $key => $sanitizer ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = call_user_func( $sanitizer, $_POST[ $key ] );
				if ( ! empty( $value ) ) {
					update_post_meta( $post_id, $key, $value );
				} else {
					delete_post_meta( $post_id, $key );
				}
			}
		}

		// Sitemap meta.
		update_post_meta( $post_id, '_swps_sitemap_exclude', ! empty( $_POST['swps_sitemap_exclude'] ) ? 1 : 0 );

		$priority = isset( $_POST['swps_sitemap_priority'] ) ? sanitize_text_field( $_POST['swps_sitemap_priority'] ) : '';
		update_post_meta( $post_id, '_swps_sitemap_priority', $priority );

		$changefreq = isset( $_POST['swps_sitemap_changefreq'] ) ? sanitize_text_field( $_POST['swps_sitemap_changefreq'] ) : '';
		update_post_meta( $post_id, '_swps_sitemap_changefreq', $changefreq );
	}

	/**
	 * Output meta tags in wp_head.
	 */
	public function output_meta_tags(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$meta_desc = get_post_meta( $post_id, '_swps_meta_description', true );
		$canonical = get_post_meta( $post_id, '_swps_canonical_url', true );

		// Meta description.
		$meta_desc = SWPS_Hooks::filter_meta_description( $meta_desc, $post_id );
		if ( ! empty( $meta_desc ) ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $meta_desc ) );
		}

		// Canonical.
		if ( ! empty( $canonical ) ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
			// Remove WP default canonical.
			remove_action( 'wp_head', 'rel_canonical' );
		}

		// Robots are merged into core's single tag via the wp_robots filter
		// (see filter_robots) so the page never carries two robots meta tags.

		// Open Graph.
		$this->output_og_tags( $post_id );

		// Twitter Card.
		$this->output_twitter_tags( $post_id );
	}

	/**
	 * Merge the per-post robots setting into core's robots meta tag.
	 *
	 * Previously this printed its own <meta name="robots"> next to core's
	 * default (max-image-preview:large), leaving two robots tags on
	 * noindexed pages. Directives are parsed from the stored
	 * comma-separated string (e.g. "noindex, follow").
	 *
	 * @param array $robots Core robots directives.
	 * @return array
	 */
	public function filter_robots( array $robots ): array {
		if ( ! is_singular() ) {
			return $robots;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $robots;
		}

		$setting = get_post_meta( $post_id, '_swps_robots', true );
		$setting = SWPS_Hooks::filter_meta_robots( $setting, $post_id );

		if ( empty( $setting ) || 'index, follow' === $setting ) {
			return $robots;
		}

		foreach ( array_map( 'trim', explode( ',', $setting ) ) as $directive ) {
			if ( '' !== $directive && 'index' !== $directive ) {
				$robots[ $directive ] = true;
			}
		}

		return $robots;
	}

	/**
	 * Filter the document title for our custom meta title.
	 */
	public function filter_document_title( string $title ): string {
		if ( ! is_singular() ) {
			return $title;
		}

		$custom = get_post_meta( get_the_ID(), '_swps_meta_title', true );
		$custom = SWPS_Hooks::filter_meta_title( $custom, get_the_ID() );

		return ! empty( $custom ) ? $custom : $title;
	}

	/**
	 * Filter document title parts.
	 */
	public function filter_title_parts( array $parts ): array {
		if ( ! is_singular() ) {
			return $parts;
		}

		$custom = get_post_meta( get_the_ID(), '_swps_meta_title', true );
		$custom = SWPS_Hooks::filter_meta_title( $custom, get_the_ID() );

		if ( ! empty( $custom ) ) {
			$parts['title'] = $custom;
			// Remove site name suffix if custom title is set.
			unset( $parts['site'] );
		}

		return $parts;
	}

	/**
	 * AJAX: Generate meta title and description using AI.
	 */
	public function ajax_generate_meta(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$post_id       = absint( $_POST['post_id'] ?? 0 );
		$focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post not found.' ) );
		}

		$content = wp_strip_all_tags( $post->post_content );
		$content = mb_substr( $content, 0, 2000 ); // Limit to keep prompt short.

		$kw_instruction = ! empty( $focus_keyword )
			? "Focus keyword (provided by user): {$focus_keyword}"
			: 'Focus keyword: (none specified — you MUST suggest one based on the content)';

		$prompt = sprintf(
			"Generate an SEO-optimized meta title (50-60 chars), meta description (140-160 chars), and focus keyword for this blog post.\n\n"
			. "Post title: %s\n"
			. "%s\n"
			. "Content excerpt: %s\n\n"
			. "Requirements:\n"
			. "- If no focus keyword was provided, analyze the content and suggest the single best 2-4 word keyword phrase (e.g. 'WordPress SEO', 'Claude Skills' — NEVER a long sentence)\n"
			. "- If a focus keyword was provided, use it as-is\n"
			. "- Include the focus keyword naturally in both the meta title and meta description\n"
			. "- Meta title: compelling, 50-60 characters, includes the focus keyword\n"
			. "- Meta description: actionable, includes a call to read, 147-160 characters, includes the focus keyword\n\n"
			. 'Return JSON only: {"meta_title":"...","meta_description":"...","focus_keyword":"..."}',
			$post->post_title,
			$kw_instruction,
			$content
		);

		$api      = SWPS_Provider_Factory::create_ai_provider();
		$response = $api->chat( 'You are an SEO copywriting expert. Return only valid JSON.', $prompt, 1024 );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$json_match = array();
		if ( preg_match( '/\{.*\}/s', $response, $json_match ) ) {
			$result = json_decode( $json_match[0], true );
			if ( is_array( $result ) ) {
				// Save focus keyword to post meta immediately.
				if ( ! empty( $result['focus_keyword'] ) ) {
					$kw = sanitize_text_field( $result['focus_keyword'] );
					update_post_meta( $post_id, '_swps_focus_keyword', $kw );
					update_post_meta( $post_id, '_yoast_wpseo_focuskw', $kw );
					update_post_meta( $post_id, 'rank_math_focus_keyword', $kw );
				}
				wp_send_json_success( $result );
			}
		}

		wp_send_json_error( array( 'message' => 'Failed to parse AI response.' ) );
	}

	/**
	 * AJAX: Bulk generate meta for all posts missing SEO meta.
	 *
	 * Processes one post per request to avoid AI timeout. JS loops until done.
	 */
	public function ajax_bulk_generate_meta(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$offset = absint( $_POST['offset'] ?? 0 );

		$enabled_types = (array) get_option( 'swps_seo_column_post_types', array( 'post', 'page' ) );

		// Count total posts.
		$total = 0;
		foreach ( $enabled_types as $pt ) {
			$counts = wp_count_posts( $pt );
			$total += ( $counts->publish ?? 0 ) + ( $counts->draft ?? 0 );
		}

		// Get one post at the current offset (AI calls are slow, so one at a time).
		$posts = get_posts(
			array(
				'post_type'      => $enabled_types,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		if ( empty( $posts ) ) {
			wp_send_json_success(
				array(
					'processed'  => $offset,
					'total'      => $total,
					'done'       => true,
					'post_title' => '',
				)
			);
		}

		$post = $posts[0];

		// Skip posts that already have meta title AND focus keyword.
		$existing_title = get_post_meta( $post->ID, '_swps_meta_title', true );
		$existing_kw    = get_post_meta( $post->ID, '_swps_focus_keyword', true );

		if ( ! empty( $existing_title ) && ! empty( $existing_kw ) ) {
			wp_send_json_success(
				array(
					'processed'  => $offset + 1,
					'total'      => $total,
					'done'       => ( $offset + 1 ) >= $total,
					'post_title' => $post->post_title,
					'skipped'    => true,
				)
			);
		}

		// Build the AI prompt (same logic as ajax_generate_meta).
		$content = wp_strip_all_tags( $post->post_content );
		$content = mb_substr( $content, 0, 2000 );

		$prompt = sprintf(
			"Generate an SEO-optimized meta title (50-60 chars), meta description (140-160 chars), and focus keyword for this blog post.\n\n"
			. "Post title: %s\n"
			. "Focus keyword: (none specified — you MUST suggest one based on the content)\n"
			. "Content excerpt: %s\n\n"
			. "Requirements:\n"
			. "- Analyze the content and suggest the single best 2-4 word keyword phrase (e.g. 'WordPress SEO', 'Claude Skills' — NEVER a long sentence)\n"
			. "- Include the focus keyword naturally in both the meta title and meta description\n"
			. "- Meta title: compelling, 50-60 characters, includes the focus keyword\n"
			. "- Meta description: actionable, includes a call to read, 147-160 characters, includes the focus keyword\n\n"
			. 'Return JSON only: {"meta_title":"...","meta_description":"...","focus_keyword":"..."}',
			$post->post_title,
			$content
		);

		$api      = SWPS_Provider_Factory::create_ai_provider();
		$response = $api->chat( 'You are an SEO copywriting expert. Return only valid JSON.', $prompt, 1024 );

		if ( is_wp_error( $response ) ) {
			wp_send_json_success(
				array(
					'processed'  => $offset + 1,
					'total'      => $total,
					'done'       => ( $offset + 1 ) >= $total,
					'post_title' => $post->post_title,
					'error'      => $response->get_error_message(),
				)
			);
		}

		$json_match = array();
		if ( preg_match( '/\{.*\}/s', $response, $json_match ) ) {
			$result = json_decode( $json_match[0], true );
			if ( is_array( $result ) ) {
				// Save meta title.
				if ( ! empty( $result['meta_title'] ) ) {
					$title = sanitize_text_field( $result['meta_title'] );
					update_post_meta( $post->ID, '_swps_meta_title', $title );
				}

				// Save meta description.
				if ( ! empty( $result['meta_description'] ) ) {
					$desc = sanitize_text_field( $result['meta_description'] );
					update_post_meta( $post->ID, '_swps_meta_description', $desc );
					update_post_meta( $post->ID, '_yoast_wpseo_metadesc', $desc );
					update_post_meta( $post->ID, 'rank_math_description', $desc );
				}

				// Save focus keyword.
				if ( ! empty( $result['focus_keyword'] ) ) {
					$kw = sanitize_text_field( $result['focus_keyword'] );
					update_post_meta( $post->ID, '_swps_focus_keyword', $kw );
					update_post_meta( $post->ID, '_yoast_wpseo_focuskw', $kw );
					update_post_meta( $post->ID, 'rank_math_focus_keyword', $kw );
				}

				// Invalidate cached SEO score so it recalculates.
				delete_post_meta( $post->ID, '_swps_seo_score' );
				delete_post_meta( $post->ID, '_swps_seo_score_value' );

				wp_send_json_success(
					array(
						'processed'  => $offset + 1,
						'total'      => $total,
						'done'       => ( $offset + 1 ) >= $total,
						'post_title' => $post->post_title,
						'generated'  => $result,
					)
				);
			}
		}

		wp_send_json_success(
			array(
				'processed'  => $offset + 1,
				'total'      => $total,
				'done'       => ( $offset + 1 ) >= $total,
				'post_title' => $post->post_title,
				'error'      => 'Failed to parse AI response.',
			)
		);
	}

	/**
	 * Show admin notice when SEO plugin conflict detected.
	 */
	public function conflict_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'stratawp-seo' ) === false ) {
			return;
		}

		echo '<div class="notice notice-info"><p>';
		esc_html_e( 'StrataWP SEO: Meta tag output is disabled because another SEO plugin (Yoast, RankMath, or AIOSEO) is active. The meta editor fields are still saved for reference.', 'stratawp-seo' );
		echo '</p></div>';
	}

	/**
	 * Sanitize robots meta value against allowed options.
	 */
	public function sanitize_robots( string $value ): string {
		$allowed = array( '', 'noindex, follow', 'index, nofollow', 'noindex, nofollow' );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Auto-generate meta title/description when a post transitions to publish.
	 */
	public function maybe_auto_generate( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		// Skip posts created by the StrataWP generator — it handles meta itself.
		if ( defined( 'SWPS_GENERATING' ) && SWPS_GENERATING ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->get_enabled_post_types(), true ) ) {
			return;
		}

		// Only generate if both fields are empty.
		$existing_title = get_post_meta( $post->ID, '_swps_meta_title', true );
		$existing_desc  = get_post_meta( $post->ID, '_swps_meta_description', true );
		if ( ! empty( $existing_title ) || ! empty( $existing_desc ) ) {
			return;
		}

		$content = wp_strip_all_tags( $post->post_content );
		$content = mb_substr( $content, 0, 2000 );

		$focus_keyword = get_post_meta( $post->ID, '_swps_focus_keyword', true );

		$kw_instruction = ! empty( $focus_keyword )
			? "Focus keyword (provided by user): {$focus_keyword}"
			: 'Focus keyword: (none specified — you MUST suggest one based on the content)';

		$prompt = sprintf(
			"Generate an SEO-optimized meta title (50-60 chars), meta description (140-160 chars), and focus keyword for this blog post.\n\n"
			. "Post title: %s\n"
			. "%s\n"
			. "Content excerpt: %s\n\n"
			. "Requirements:\n"
			. "- If no focus keyword was provided, analyze the content and suggest the single best 2-4 word keyword phrase (e.g. 'WordPress SEO', 'Claude Skills' — NEVER a long sentence)\n"
			. "- If a focus keyword was provided, use it as-is\n"
			. "- Include the focus keyword naturally in both the meta title and meta description\n"
			. "- Meta title: compelling, 50-60 characters, includes the focus keyword\n"
			. "- Meta description: actionable, includes a call to read, 147-160 characters, includes the focus keyword\n\n"
			. 'Return JSON only: {"meta_title":"...","meta_description":"...","focus_keyword":"..."}',
			$post->post_title,
			$kw_instruction,
			$content
		);

		$api      = SWPS_Provider_Factory::create_ai_provider();
		$response = $api->chat( 'You are an SEO copywriting expert. Return only valid JSON.', $prompt, 1024 );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$json_match = array();
		if ( preg_match( '/\{.*\}/s', $response, $json_match ) ) {
			$result = json_decode( $json_match[0], true );
			if ( is_array( $result ) ) {
				if ( ! empty( $result['meta_title'] ) ) {
					update_post_meta( $post->ID, '_swps_meta_title', sanitize_text_field( $result['meta_title'] ) );
				}
				if ( ! empty( $result['meta_description'] ) ) {
					update_post_meta( $post->ID, '_swps_meta_description', sanitize_textarea_field( $result['meta_description'] ) );
				}
				if ( ! empty( $result['focus_keyword'] ) ) {
					update_post_meta( $post->ID, '_swps_focus_keyword', sanitize_text_field( $result['focus_keyword'] ) );
				}
			}
		}
	}

	/**
	 * Get post types where the meta editor is enabled.
	 */
	private function get_enabled_post_types(): array {
		$saved = get_option( 'swps_meta_editor_post_types', '' );

		if ( is_array( $saved ) && ! empty( $saved ) ) {
			// New storage format (settings UI): array of post-type slugs.
			$types = array_map( 'sanitize_text_field', $saved );
		} elseif ( is_string( $saved ) && '' !== $saved ) {
			// Legacy storage format: comma-separated string.
			$types = array_map( 'sanitize_text_field', explode( ',', $saved ) );
		} else {
			$types = array( 'post', 'page' );
		}

		/**
		 * Filters the post types that get the StrataWP SEO meta box
		 * (Meta Title, Description, Focus Words, social, sitemap controls).
		 *
		 * Useful for enabling the editor on custom post types — e.g.
		 * WooCommerce `product` — without touching the settings UI.
		 *
		 * @param string[] $types Post-type slugs.
		 */
		return (array) apply_filters( 'swps_meta_editor_post_types', $types );
	}

	/**
	 * Output Open Graph tags.
	 */
	private function output_og_tags( int $post_id ): void {
		$title = get_post_meta( $post_id, '_swps_social_title', true )
				?: get_post_meta( $post_id, '_swps_meta_title', true )
				?: get_the_title( $post_id );

		$desc = get_post_meta( $post_id, '_swps_social_description', true )
			?: get_post_meta( $post_id, '_swps_meta_description', true )
			?: get_the_excerpt( $post_id );

		$image = get_post_meta( $post_id, '_swps_social_image', true )
				?: get_the_post_thumbnail_url( $post_id, 'large' )
				?: (string) get_option( 'swps_schema_logo', '' )
				?: (string) get_site_icon_url( 512 );

		// Posts are articles; the front page and other pages are websites.
		printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( is_singular( 'post' ) ? 'article' : 'website' ) );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $desc ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( get_permalink( $post_id ) ) );
		if ( $image ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
		}
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
	}

	/**
	 * Output Twitter Card tags.
	 */
	private function output_twitter_tags( int $post_id ): void {
		$title = get_post_meta( $post_id, '_swps_social_title', true )
				?: get_post_meta( $post_id, '_swps_meta_title', true )
				?: get_the_title( $post_id );

		$desc = get_post_meta( $post_id, '_swps_social_description', true )
			?: get_post_meta( $post_id, '_swps_meta_description', true )
			?: get_the_excerpt( $post_id );

		$image = get_post_meta( $post_id, '_swps_social_image', true )
				?: get_the_post_thumbnail_url( $post_id, 'large' )
				?: (string) get_option( 'swps_schema_logo', '' )
				?: (string) get_site_icon_url( 512 );

		printf( '<meta name="twitter:card" content="%s" />' . "\n", $image ? 'summary_large_image' : 'summary' );
		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $desc ) );
		if ( $image ) {
			printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $image ) );
		}
	}
}
