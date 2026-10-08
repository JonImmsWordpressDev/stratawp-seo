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

	/**
	 * @return array<string,mixed>
	 */
	private function script_data(): array {
		return array(
			'enabled'   => true,
			'toggleUrl' => $this->toggle_url( false ),
		);
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
