<?php
/**
 * Crawlers & Files — edit /llms.txt and /robots.txt directly inside the plugin.
 *
 * Two files, two modes per file:
 *   llms.txt:    auto (default) | custom (user-written body served verbatim)
 *   robots.txt:  auto (default) | append (auto + user lines) | replace (user only)
 *
 * Hooks into the existing pipeline:
 *   - llms.txt → filter `swps_llms_txt_content` (added by SWPS_AI_Bots).
 *   - robots.txt → filter `robots_txt` at priority 200 (after AI-bots writes its
 *     allow/disallow block at priority 100).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Crawl_Files {

	public const OPT_LLMS_MODE     = 'swps_llms_txt_mode';
	public const OPT_LLMS_CUSTOM   = 'swps_llms_txt_custom';
	public const OPT_ROBOTS_MODE   = 'swps_robots_txt_mode';
	public const OPT_ROBOTS_CUSTOM = 'swps_robots_txt_custom';

	public const MODE_AUTO    = 'auto';
	public const MODE_CUSTOM  = 'custom';
	public const MODE_APPEND  = 'append';
	public const MODE_REPLACE = 'replace';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 21 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Edit-on-frontend hooks.
		add_filter( 'swps_llms_txt_content', array( $this, 'filter_llms_content' ), 100 );
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 200, 2 );
	}

	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Crawlers & Files', 'stratawp-seo' ),
			__( 'Crawlers & Files', 'stratawp-seo' ),
			'manage_options',
			'swps-crawl-files',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
		}
		require SWPS_PLUGIN_DIR . 'templates/crawl-files-page.php';
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'stratawp-seo_page_swps-crawl-files' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'swps-page-crawl-files',
			SWPS_PLUGIN_URL . 'admin/css/pages/crawl-files.css',
			array( 'swps-tokens', 'swps-components', 'swps-templates' ),
			SWPS_VERSION
		);
	}

	public function register_settings(): void {
		register_setting(
			'swps_crawl_files_group',
			self::OPT_LLMS_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_llms_mode' ),
				'default'           => self::MODE_AUTO,
			)
		);
		register_setting(
			'swps_crawl_files_group',
			self::OPT_LLMS_CUSTOM,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_textfile' ),
				'default'           => '',
			)
		);
		register_setting(
			'swps_crawl_files_group',
			self::OPT_ROBOTS_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_robots_mode' ),
				'default'           => self::MODE_AUTO,
			)
		);
		register_setting(
			'swps_crawl_files_group',
			self::OPT_ROBOTS_CUSTOM,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_textfile' ),
				'default'           => '',
			)
		);
	}

	public function sanitize_llms_mode( $value ): string {
		return self::MODE_CUSTOM === $value ? self::MODE_CUSTOM : self::MODE_AUTO;
	}

	public function sanitize_robots_mode( $value ): string {
		$value = is_string( $value ) ? $value : '';
		if ( in_array( $value, array( self::MODE_APPEND, self::MODE_REPLACE ), true ) ) {
			return $value;
		}
		return self::MODE_AUTO;
	}

	/**
	 * Strip control chars except tab/newline/CR. Capped at 64KB to keep rows sane.
	 */
	public function sanitize_textfile( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( $value );
		$value = preg_replace( "/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u", '', $value ) ?? '';
		// Normalize line endings.
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		if ( strlen( $value ) > 65536 ) {
			$value = substr( $value, 0, 65536 );
		}
		return $value;
	}

	// ---------- Public mode/value helpers (used by the template) ----------

	public function get_llms_mode(): string {
		return self::MODE_CUSTOM === get_option( self::OPT_LLMS_MODE, self::MODE_AUTO ) ? self::MODE_CUSTOM : self::MODE_AUTO;
	}

	public function get_robots_mode(): string {
		$mode = (string) get_option( self::OPT_ROBOTS_MODE, self::MODE_AUTO );
		return in_array( $mode, array( self::MODE_AUTO, self::MODE_APPEND, self::MODE_REPLACE ), true ) ? $mode : self::MODE_AUTO;
	}

	public function get_llms_custom(): string {
		return (string) get_option( self::OPT_LLMS_CUSTOM, '' );
	}

	public function get_robots_custom(): string {
		return (string) get_option( self::OPT_ROBOTS_CUSTOM, '' );
	}

	/**
	 * Render the auto llms.txt the way the public endpoint would.
	 */
	public function render_auto_llms( SWPS_AI_Bots $bots ): string {
		// Run the bots class generator, but bypass our own custom-mode filter.
		remove_filter( 'swps_llms_txt_content', array( $this, 'filter_llms_content' ), 100 );
		$out = $bots->generate_llms_txt();
		add_filter( 'swps_llms_txt_content', array( $this, 'filter_llms_content' ), 100 );
		return $out;
	}

	/**
	 * Render the auto robots.txt by re-running the chain WordPress would,
	 * but skipping our own filter so users see the raw "auto" baseline.
	 */
	public function render_auto_robots(): string {
		remove_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 200 );
		$public = (int) get_option( 'blog_public' );
		// Mimic WP core default body, then run filters as core would.
		if ( 0 === $public ) {
			$output = "User-agent: *\nDisallow: /\n";
		} else {
			$site_url = wp_parse_url( site_url() );
			$path     = ( ! empty( $site_url['path'] ) ) ? $site_url['path'] : '';
			$output   = "User-agent: *\nDisallow: {$path}/wp-admin/\nAllow: {$path}/wp-admin/admin-ajax.php\n";
		}
		$output = (string) apply_filters( 'robots_txt', $output, $public );
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 200, 2 );
		return $output;
	}

	// ---------- Filters ----------

	public function filter_llms_content( string $content ): string {
		if ( self::MODE_CUSTOM !== $this->get_llms_mode() ) {
			return $content;
		}
		$custom = trim( $this->get_llms_custom() );
		if ( '' === $custom ) {
			return $content;
		}
		return rtrim( $custom ) . "\n";
	}

	public function filter_robots_txt( string $output, $public ): string {
		if ( ! $public ) {
			return $output;
		}
		$mode   = $this->get_robots_mode();
		$custom = trim( $this->get_robots_custom() );
		if ( '' === $custom || self::MODE_AUTO === $mode ) {
			return $output;
		}

		if ( self::MODE_REPLACE === $mode ) {
			return rtrim( $custom ) . "\n";
		}
		// append
		return rtrim( $output ) . "\n\n# Custom — managed by StrataWP SEO\n" . rtrim( $custom ) . "\n";
	}
}
