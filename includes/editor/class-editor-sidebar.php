<?php
/**
 * Block editor sidebar: rollout setting, assets and classic metabox fallback.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Sidebar {

	public const OPTION_ENABLED   = 'swps_editor_sidebar';
	public const OPTION_INSTALLED = 'swps_installed_version';
	public const TOGGLE_ACTION    = 'swps_editor_sidebar_toggle';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'seed_rollout_default' ), 1 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'added_post_meta', array( $this, 'mirror_related' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'mirror_related' ), 10, 4 );
		add_filter( 'default_post_metadata', array( __CLASS__, 'default_related' ), 10, 4 );
	}

	/**
	 * Fresh installs get the sidebar on; sites that already ran an earlier
	 * version get it off until they opt in. A fresh install runs activation
	 * first, which writes OPTION_INSTALLED, so seed_rollout_default() is then
	 * a no-op. An upgraded site never ran activation, so it lands there first.
	 */
	public static function on_activate(): void {
		if ( false === get_option( self::OPTION_INSTALLED ) ) {
			add_option( self::OPTION_INSTALLED, SWPS_VERSION );
			add_option( self::OPTION_ENABLED, '1' );
		}
	}

	public static function seed_rollout_default(): void {
		if ( false === get_option( self::OPTION_INSTALLED ) ) {
			add_option( self::OPTION_INSTALLED, SWPS_VERSION );
			add_option( self::OPTION_ENABLED, '0' );
		}
	}

	public static function is_enabled(): bool {
		$enabled = '1' === (string) get_option( self::OPTION_ENABLED, '0' );
		return (bool) apply_filters( 'swps_editor_sidebar_enabled', $enabled );
	}

	/**
	 * True when the classic SEO and AEO metaboxes should not register because
	 * the sidebar replaces them on this screen.
	 */
	public static function hide_classic_metaboxes(): bool {
		if ( ! self::is_enabled() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen instanceof WP_Screen && $screen->is_block_editor();
	}

	public function enqueue(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		// Post editor only. The Site Editor and widgets editor also fire this
		// hook, but they have no post to analyse.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! $screen->is_block_editor() ) {
			return;
		}
		if ( ! in_array( $screen->post_type, SWPS_Meta_Editor::get_enabled_post_types(), true ) ) {
			return;
		}
		$asset_file = SWPS_PLUGIN_DIR . 'admin/editor/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;

		wp_enqueue_script(
			'swps-editor',
			SWPS_PLUGIN_URL . 'admin/editor/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( 'swps-editor', 'stratawp-seo' );
		// wp-scripts emits the imported style.scss as style-index.css, plus a
		// style-index-rtl.css that core swaps in on RTL sites.
		if ( file_exists( SWPS_PLUGIN_DIR . 'admin/editor/style-index.css' ) ) {
			wp_enqueue_style( 'swps-editor', SWPS_PLUGIN_URL . 'admin/editor/style-index.css', array(), $asset['version'] );
			wp_style_add_data( 'swps-editor', 'rtl', 'replace' );
		}

		wp_add_inline_script(
			'swps-editor',
			'window.swpsEditor = ' . wp_json_encode( $this->script_data() ) . ';',
			'before'
		);
	}

	public static function sanitize_robots( $value ): string {
		$allowed = array( '', 'noindex, follow', 'index, nofollow', 'noindex, nofollow' );
		$value   = (string) $value;
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Expose the SEO fields to the block editor through REST. Only registered
	 * when the sidebar is on, so nothing changes for sites that have not
	 * opted in.
	 */
	public function register_meta(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$auth = static function ( $allowed, $meta_key, $post_id ): bool {
			return current_user_can( 'edit_post', (int) $post_id );
		};

		$strings = array(
			'_swps_meta_title'         => 'sanitize_text_field',
			'_swps_meta_description'   => 'sanitize_textarea_field',
			'_swps_focus_keyword'      => 'sanitize_text_field',
			'_swps_secondary_keywords' => 'sanitize_text_field',
			'_swps_canonical_url'      => 'esc_url_raw',
			'_swps_robots'             => array( __CLASS__, 'sanitize_robots' ),
			'_swps_breadcrumb_title'   => 'sanitize_text_field',
			'_swps_social_title'       => 'sanitize_text_field',
			'_swps_social_description' => 'sanitize_textarea_field',
			'_swps_social_image'       => 'esc_url_raw',
			'_swps_sitemap_priority'   => 'sanitize_text_field',
			'_swps_sitemap_changefreq' => 'sanitize_text_field',
		);

		foreach ( SWPS_Meta_Editor::get_enabled_post_types() as $type ) {
			foreach ( $strings as $key => $sanitize ) {
				register_post_meta(
					$type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => $sanitize,
						'auth_callback'     => $auth,
					)
				);
			}

			register_post_meta(
				$type,
				'_swps_sitemap_exclude',
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => $auth,
				)
			);

			register_post_meta(
				$type,
				SWPS_Editor_Input::META_RELATED,
				array(
					'type'              => 'array',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'maxItems' => SWPS_Editor_Input::MAX_RELATED,
						),
					),
					'sanitize_callback' => array( 'SWPS_Editor_Input', 'sanitize_related' ),
					'auth_callback'     => $auth,
				)
			);

			// Readable in the editor (for the old versus new score note), never writable over REST.
			register_post_meta(
				$type,
				'_swps_seo_score_value',
				array(
					'type'          => 'integer',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => '__return_false',
				)
			);
		}
	}

	/**
	 * Keep the legacy comma separated secondary keywords in step with the new
	 * related keywords list so the AI generation path sees the same values.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public function mirror_related( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( SWPS_Editor_Input::META_RELATED !== $meta_key ) {
			return;
		}
		$related   = SWPS_Editor_Input::sanitize_related( $meta_value );
		$secondary = (string) get_post_meta( (int) $post_id, '_swps_secondary_keywords', true );
		if ( ! SWPS_Editor_Input::should_mirror( $related, $secondary ) ) {
			return;
		}
		update_post_meta( (int) $post_id, '_swps_secondary_keywords', SWPS_Editor_Input::secondary_string( $related ) );
	}

	/**
	 * Posts that predate the related keywords list read it from the legacy
	 * secondary keywords string, so the sidebar shows those values and does
	 * not send back an empty list that would wipe them.
	 *
	 * @param mixed  $value     Default value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed
	 */
	public static function default_related( $value, $object_id, $meta_key, $single ) {
		static $running = false;
		if ( SWPS_Editor_Input::META_RELATED !== $meta_key || $running ) {
			return $value;
		}
		$running = true;
		$legacy  = SWPS_Editor_Input::parse_legacy_secondary( (string) get_post_meta( (int) $object_id, '_swps_secondary_keywords', true ) );
		$running = false;
		if ( array() === $legacy ) {
			return $value;
		}
		// REST reads with $single false and takes the first row as the value.
		return $single ? $legacy : array( $legacy );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function script_data(): array {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = $screen && $screen->post_type ? $screen->post_type : 'post';

		return array(
			'enabled'    => true,
			'toggleUrl'  => $this->toggle_url( false ),
			'registry'   => SWPS_Editor_Check_Registry::for_js(),
			'preset'     => SWPS_Editor_Input::preset( $post_type ),
			'host'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'lang'       => get_locale(),
			'siteTitle'  => get_bloginfo( 'name' ),
			'aeoEnabled' => (bool) get_option( SWPS_AEO_Scorer::OPTION_COVERAGE_ENABLED ),
			'canManage'  => current_user_can( 'manage_options' ),
			'fixCost'    => $this->estimate_fix_cost(),
		);
	}

	/**
	 * Rough price of one AI fix (about 1,500 tokens in, 300 out), or null when
	 * the model price is unknown.
	 */
	private function estimate_fix_cost(): ?float {
		$model = (string) get_option( 'swps_model', '' );
		if ( '' === $model ) {
			return null;
		}
		$cost = ( new SWPS_Cost_Tracker() )->calculate_cost( $model, 1500, 300 );
		return $cost > 0 ? round( $cost, 4 ) : null;
	}

	private function toggle_url( bool $enable ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::TOGGLE_ACTION,
					'enable' => $enable ? '1' : '0',
				),
				admin_url( 'admin-post.php' )
			),
			self::TOGGLE_ACTION
		);
	}

	public function handle_toggle(): void {
		check_admin_referer( self::TOGGLE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change this setting.', 'stratawp-seo' ), 403 );
		}
		$enable = isset( $_GET['enable'] ) && '1' === $_GET['enable'];
		update_option( self::OPTION_ENABLED, $enable ? '1' : '0' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public function maybe_show_notice(): void {
		if ( self::is_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! $screen->is_block_editor() ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'StrataWP SEO has a new editor sidebar with live analysis, multiple keywords and AI fixes.', 'stratawp-seo' ),
			esc_url( $this->toggle_url( true ) ),
			esc_html__( 'Turn it on', 'stratawp-seo' )
		);
	}
}
