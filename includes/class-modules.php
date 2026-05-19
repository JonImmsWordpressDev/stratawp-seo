<?php
/**
 * Modules registry — single source of truth for which features are
 * enabled, what shows in the dashboard grid, and what appears in the
 * sidebar.
 *
 * v4.0 scope: registry + persisted on/off toggle + REST. Existing
 * always-on classes (Schema, Audit, etc.) IGNORE the toggle — disabling
 * them is a v4.1 concern (and gets per-class init guards then). The
 * toggle DOES gate visibility on the new modules whose features ship
 * later (Auto-Optimize, Competitors, Local SEO, WooCommerce SEO,
 * Image SEO).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Modules {

	public const OPTION_KEY = 'swps_modules_enabled';

	/** @var array<string, array> */
	private array $registry = array();

	public function __construct() {
		add_action( 'init', array( $this, 'register_defaults' ), 1 );
	}

	/**
	 * Register the default v4.0 module set.
	 *
	 * Called on `init` priority 1 so other plugins can hook
	 * `swps_modules_register` to add their own.
	 */
	public function register_defaults(): void {
		// Existing always-on features (toggle visual only in v4.0).
		$this->register(
			'ai-generation',
			array(
				'name'         => __( 'AI Content Generation', 'stratawp-seo' ),
				'desc'         => __( 'Site-aware AI posts with internal links, FAQs, schema.', 'stratawp-seo' ),
				'icon'         => '✦',
				'group'        => 'content',
				'default_on'   => true,
				'badge'        => '',
				'settings_url' => admin_url( 'admin.php?page=swps-settings#ai-content' ),
			)
		);
		$this->register(
			'seo-audit',
			array(
				'name'         => __( 'SEO Audit', 'stratawp-seo' ),
				'desc'         => __( '8-module health check with one-click auto-fix.', 'stratawp-seo' ),
				'icon'         => '⚡',
				'group'        => 'seo',
				'default_on'   => true,
				'badge'        => '',
				'settings_url' => admin_url( 'admin.php?page=swps-seo-audit' ),
			)
		);
		$this->register(
			'schema',
			array(
				'name'         => __( 'Schema', 'stratawp-seo' ),
				'desc'         => __( 'Article, FAQ, BreadcrumbList, Organization JSON-LD.', 'stratawp-seo' ),
				'icon'         => '{}',
				'group'        => 'seo',
				'default_on'   => true,
				'badge'        => '',
				'settings_url' => admin_url( 'admin.php?page=swps-settings#seo' ),
			)
		);
		$this->register(
			'redirects',
			array(
				'name'         => __( 'Redirects', 'stratawp-seo' ),
				'desc'         => __( '301/302/307/410, regex, 404 monitor with one-click fix.', 'stratawp-seo' ),
				'icon'         => '⤳',
				'group'        => 'seo',
				'default_on'   => true,
				'badge'        => '',
				'settings_url' => admin_url( 'admin.php?page=swps-redirects' ),
			)
		);
		$this->register(
			'sitemaps',
			array(
				'name'         => __( 'Sitemaps', 'stratawp-seo' ),
				'desc'         => __( 'XML index, image sitemap, IndexNow batch submit.', 'stratawp-seo' ),
				'icon'         => '⌘',
				'group'        => 'seo',
				'default_on'   => true,
				'badge'        => '',
				'settings_url' => admin_url( 'admin.php?page=swps-sitemaps' ),
			)
		);
		$this->register(
			'internal-links',
			array(
				'name'         => __( 'Internal Links', 'stratawp-seo' ),
				'desc'         => __( 'Keyword + AI-suggested internal linking.', 'stratawp-seo' ),
				'icon'         => '⟿',
				'group'        => 'seo',
				'default_on'   => true,
				'badge'        => '',
				'settings_url' => admin_url( 'admin.php?page=swps-internal-links' ),
			)
		);
		$this->register(
			'meta-editor',
			array(
				'name'         => __( 'Meta Editor', 'stratawp-seo' ),
				'desc'         => __( 'Per-post title, description, OG, Twitter, robots, canonical.', 'stratawp-seo' ),
				'icon'         => '◆',
				'group'        => 'seo',
				'default_on'   => true,
				'badge'        => '',
				'settings_url' => admin_url( 'admin.php?page=swps-settings#seo' ),
			)
		);
		$this->register(
			'analytics',
			array(
				'name'         => __( 'Analytics', 'stratawp-seo' ),
				'desc'         => __( 'Cookie-free pageviews, time on page, scroll depth.', 'stratawp-seo' ),
				'icon'         => '◔',
				'group'        => 'insights',
				'default_on'   => true,
				'badge'        => '',
				'settings_url' => admin_url( 'admin.php?page=swps-analytics' ),
			)
		);
		$this->register(
			'ai-bots',
			array(
				'name'         => __( 'AI Crawlers + llms.txt', 'stratawp-seo' ),
				'desc'         => __( 'GPTBot, ClaudeBot, PerplexityBot allowlist + dynamic /llms.txt.', 'stratawp-seo' ),
				'icon'         => '⌨',
				'group'        => 'seo',
				'default_on'   => true,
				'badge'        => '',
				'settings_url' => admin_url( 'admin.php?page=swps-settings#discoverability' ),
			)
		);

		// New modules (v4.0 scaffold pages, full features in v4.1).
		$this->register(
			'auto-optimize',
			array(
				'name'         => __( 'AI Auto-Optimize', 'stratawp-seo' ),
				'desc'         => __( 'Auto-refresh old posts: kw in H2, first paragraph, schema, alt text.', 'stratawp-seo' ),
				'icon'         => '↻',
				'group'        => 'content',
				'default_on'   => false,
				'badge'        => 'ai',
				'settings_url' => admin_url( 'admin.php?page=swps-auto-optimize' ),
			)
		);
		$this->register(
			'competitors',
			array(
				'name'         => __( 'Competitors', 'stratawp-seo' ),
				'desc'         => __( 'Track sites, schema, keyword gaps, content velocity.', 'stratawp-seo' ),
				'icon'         => '◎',
				'group'        => 'insights',
				'default_on'   => false,
				'badge'        => 'new',
				'settings_url' => admin_url( 'admin.php?page=swps-competitors' ),
			)
		);

		// v4.2 — newly shipped modules.
		$this->register(
			'local-seo',
			array(
				'name'         => __( 'Local SEO', 'stratawp-seo' ),
				'desc'         => __( 'NAP, opening hours, LocalBusiness JSON-LD for local + service-area sites.', 'stratawp-seo' ),
				'icon'         => '◉',
				'group'        => 'seo',
				'default_on'   => false,
				'badge'        => 'new',
				'settings_url' => admin_url( 'admin.php?page=swps-local-seo' ),
			)
		);
		$this->register(
			'image-seo',
			array(
				'name'         => __( 'Image SEO', 'stratawp-seo' ),
				'desc'         => __( 'AI auto-alt, filename sanitization, lazy-loading, image sitemap.', 'stratawp-seo' ),
				'icon'         => '◈',
				'group'        => 'seo',
				'default_on'   => false,
				'badge'        => 'new',
				'settings_url' => admin_url( 'admin.php?page=swps-image-seo' ),
			)
		);

		$this->register(
			'backlinks',
			array(
				'name'         => __( 'Backlinks', 'stratawp-seo' ),
				'desc'         => __( 'Manual + CSV-import backlink tracker with daily health monitoring.', 'stratawp-seo' ),
				'icon'         => '⇄',
				'group'        => 'insights',
				'default_on'   => false,
				'badge'        => 'new',
				'settings_url' => admin_url( 'admin.php?page=swps-backlinks' ),
			)
		);

		// Coming soon — disabled, not user-toggleable.
		$this->register(
			'woocommerce',
			array(
				'name'         => __( 'WooCommerce SEO', 'stratawp-seo' ),
				'desc'         => __( 'Product schema, category SEO, OG for variations.', 'stratawp-seo' ),
				'icon'         => '⌥',
				'group'        => 'seo',
				'default_on'   => false,
				'badge'        => 'soon',
				'settings_url' => '',
				'locked'       => true,
			)
		);

		/**
		 * Fires after default modules are registered. Use this to add custom modules.
		 *
		 * @param SWPS_Modules $modules This registry instance.
		 */
		do_action( 'swps_modules_register', $this );
	}

	/**
	 * Register a module.
	 *
	 * @param string $slug Unique slug (kebab-case).
	 * @param array  $args  Module metadata.
	 */
	public function register( string $slug, array $args ): void {
		$this->registry[ $slug ] = wp_parse_args(
			$args,
			array(
				'name'         => '',
				'desc'         => '',
				'icon'         => '•',
				'group'        => 'system',
				'default_on'   => true,
				'badge'        => '',
				'settings_url' => '',
				'locked'       => false,
			)
		);
	}

	/**
	 * Is this module enabled?
	 */
	public function is_enabled( string $slug ): bool {
		if ( ! isset( $this->registry[ $slug ] ) ) {
			return false;
		}
		$stored = get_option( self::OPTION_KEY, array() );
		if ( isset( $stored[ $slug ] ) ) {
			return (bool) $stored[ $slug ];
		}
		return (bool) $this->registry[ $slug ]['default_on'];
	}

	/**
	 * Toggle a module's enabled state.
	 */
	public function set_enabled( string $slug, bool $enabled ): bool {
		if ( ! isset( $this->registry[ $slug ] ) ) {
			return false;
		}
		if ( ! empty( $this->registry[ $slug ]['locked'] ) ) {
			return false;
		}
		$stored          = get_option( self::OPTION_KEY, array() );
		$stored[ $slug ] = (bool) $enabled;
		update_option( self::OPTION_KEY, $stored );
		return true;
	}

	/**
	 * Get every registered module with its current state.
	 *
	 * @return array<string, array>
	 */
	public function get_all(): array {
		$out = array();
		foreach ( $this->registry as $slug => $meta ) {
			$out[ $slug ] = $meta + array(
				'slug'    => $slug,
				'enabled' => $this->is_enabled( $slug ),
			);
		}
		return $out;
	}

	/**
	 * Get one module's metadata + state, or null if unknown.
	 */
	public function get( string $slug ): ?array {
		if ( ! isset( $this->registry[ $slug ] ) ) {
			return null;
		}
		return $this->registry[ $slug ] + array(
			'slug'    => $slug,
			'enabled' => $this->is_enabled( $slug ),
		);
	}
}
