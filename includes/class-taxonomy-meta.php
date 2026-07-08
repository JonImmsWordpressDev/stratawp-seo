<?php
/**
 * Taxonomy Meta — SEO fields on term edit screens + archive meta output.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Taxonomy_Meta {

	private const META_FIELDS = array(
		'_swps_meta_title',
		'_swps_meta_description',
		'_swps_canonical_url',
		'_swps_robots',
		'_swps_og_title',
		'_swps_og_description',
		'_swps_og_image',
		'_swps_focus_keyword',
	);

	public function __construct() {
		// Register fields on all public taxonomy edit screens.
		$taxonomies = get_taxonomies( array( 'public' => true ) );
		foreach ( $taxonomies as $taxonomy ) {
			if ( 'post_format' === $taxonomy ) {
				continue;
			}
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_fields' ), 10, 2 );
			add_action( "edited_{$taxonomy}", array( $this, 'save_fields' ), 10, 2 );
		}

		// Frontend meta output for archive pages (only when no conflict).
		if ( ! is_admin() && ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'AIOSEO_VERSION' ) ) {
			add_action( 'wp_head', array( $this, 'output_archive_meta' ), 3 );
			add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
		}
	}

	/**
	 * Merge per-term robots settings into core's robots meta tag.
	 *
	 * Previously printed as a second <meta name="robots"> tag alongside
	 * WordPress core's default tag.
	 *
	 * @param array $robots Core robots directives.
	 * @return array
	 */
	public function filter_robots( array $robots ): array {
		if ( ! is_category() && ! is_tag() && ! is_tax() ) {
			return $robots;
		}

		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return $robots;
		}

		$setting = get_term_meta( $term->term_id, '_swps_robots', true );
		if ( empty( $setting ) ) {
			return $robots;
		}

		foreach ( array_map( 'trim', explode( ',', $setting ) ) as $directive ) {
			if ( '' !== $directive ) {
				$robots[ $directive ] = true;
			}
		}

		return $robots;
	}

	/**
	 * Render SEO fields on the term edit screen.
	 */
	public function render_fields( \WP_Term $term ): void {
		wp_nonce_field( 'swps_taxonomy_meta', 'swps_taxonomy_meta_nonce' );
		?>
		<tr class="form-field">
			<th colspan="2"><h2><?php esc_html_e( 'StrataWP SEO', 'stratawp-seo' ); ?></h2></th>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="swps-tax-meta-title"><?php esc_html_e( 'Meta Title', 'stratawp-seo' ); ?></label></th>
			<td>
				<input type="text" id="swps-tax-meta-title" name="_swps_meta_title" class="large-text"
					value="<?php echo esc_attr( get_term_meta( $term->term_id, '_swps_meta_title', true ) ); ?>">
				<p class="description"><?php esc_html_e( 'Leave blank to use the Search Appearance template.', 'stratawp-seo' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="swps-tax-meta-desc"><?php esc_html_e( 'Meta Description', 'stratawp-seo' ); ?></label></th>
			<td>
				<textarea id="swps-tax-meta-desc" name="_swps_meta_description" rows="3" class="large-text"
				><?php echo esc_textarea( get_term_meta( $term->term_id, '_swps_meta_description', true ) ); ?></textarea>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="swps-tax-canonical"><?php esc_html_e( 'Canonical URL', 'stratawp-seo' ); ?></label></th>
			<td>
				<input type="url" id="swps-tax-canonical" name="_swps_canonical_url" class="large-text"
					value="<?php echo esc_url( get_term_meta( $term->term_id, '_swps_canonical_url', true ) ); ?>">
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Robots', 'stratawp-seo' ); ?></th>
			<td>
				<?php $robots = get_term_meta( $term->term_id, '_swps_robots', true ); ?>
				<label>
					<input type="checkbox" name="_swps_robots_noindex" value="1"
						<?php checked( str_contains( (string) $robots, 'noindex' ) ); ?>>
					<?php esc_html_e( 'noindex', 'stratawp-seo' ); ?>
				</label>
				<label style="margin-left: 15px;">
					<input type="checkbox" name="_swps_robots_nofollow" value="1"
						<?php checked( str_contains( (string) $robots, 'nofollow' ) ); ?>>
					<?php esc_html_e( 'nofollow', 'stratawp-seo' ); ?>
				</label>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="swps-tax-og-title"><?php esc_html_e( 'OG Title', 'stratawp-seo' ); ?></label></th>
			<td>
				<input type="text" id="swps-tax-og-title" name="_swps_og_title" class="large-text"
					value="<?php echo esc_attr( get_term_meta( $term->term_id, '_swps_og_title', true ) ); ?>">
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="swps-tax-og-desc"><?php esc_html_e( 'OG Description', 'stratawp-seo' ); ?></label></th>
			<td>
				<textarea id="swps-tax-og-desc" name="_swps_og_description" rows="2" class="large-text"
				><?php echo esc_textarea( get_term_meta( $term->term_id, '_swps_og_description', true ) ); ?></textarea>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="swps-tax-focus-kw"><?php esc_html_e( 'Focus Keyword', 'stratawp-seo' ); ?></label></th>
			<td>
				<input type="text" id="swps-tax-focus-kw" name="_swps_focus_keyword" class="large-text"
					value="<?php echo esc_attr( get_term_meta( $term->term_id, '_swps_focus_keyword', true ) ); ?>">
			</td>
		</tr>
		<?php
	}

	/**
	 * Save taxonomy SEO fields.
	 */
	public function save_fields( int $term_id ): void {
		if ( ! isset( $_POST['swps_taxonomy_meta_nonce'] ) ||
			! wp_verify_nonce( $_POST['swps_taxonomy_meta_nonce'], 'swps_taxonomy_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// Text fields.
		$text_fields = array( '_swps_meta_title', '_swps_meta_description', '_swps_canonical_url', '_swps_og_title', '_swps_og_description', '_swps_focus_keyword' );
		foreach ( $text_fields as $field ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			if ( ! empty( $value ) ) {
				update_term_meta( $term_id, $field, $value );
			} else {
				delete_term_meta( $term_id, $field );
			}
		}

		// Robots directives.
		$robots = array();
		if ( ! empty( $_POST['_swps_robots_noindex'] ) ) {
			$robots[] = 'noindex';
		}
		if ( ! empty( $_POST['_swps_robots_nofollow'] ) ) {
			$robots[] = 'nofollow';
		}
		if ( ! empty( $robots ) ) {
			update_term_meta( $term_id, '_swps_robots', implode( ',', $robots ) );
		} else {
			delete_term_meta( $term_id, '_swps_robots' );
		}
	}

	/**
	 * Output meta tags on archive pages using term meta overrides.
	 */
	public function output_archive_meta(): void {
		if ( ! is_category() && ! is_tag() && ! is_tax() ) {
			return;
		}

		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		// Canonical URL.
		$canonical = get_term_meta( $term->term_id, '_swps_canonical_url', true );
		if ( ! empty( $canonical ) ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
		}

		// Robots are merged into core's tag via the wp_robots filter (see
		// filter_robots). Open Graph output moved to
		// SWPS_Search_Appearance::output_og_tags(), which emits the complete
		// tag set (type/url/site_name/image) with the term-level
		// _swps_og_title / _swps_og_description / _swps_og_image overrides —
		// the partial og:title/og:description pair emitted here produced a
		// malformed card on the platforms that read it.
	}
}
