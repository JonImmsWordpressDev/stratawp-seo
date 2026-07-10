<?php
/**
 * Admin shell renderer: top bar + sidebar wrapper for StrataWP admin pages.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Admin_Shell {

	/**
	 * Hook-suffix prefixes that mark a page as ours.
	 * Used to gate shell rendering and CSS/JS enqueue.
	 */
	private const HOOK_PREFIXES = array(
		'toplevel_page_stratawp-seo',
		'toplevel_page_swps-',
		'stratawp-seo_page_',
		'swps-seo_page_',
	);

	/**
	 * Page slugs that StrataWP owns. Used as a fallback when matching
	 * by hook-suffix isn't available (e.g., during enqueue scoping).
	 */
	private const OWN_SLUGS = array(
		'stratawp-seo',
		'swps-dashboard',
		'swps-generate',
		'swps-voice-profiles',
		'swps-seo-audit',
		'swps-search-appearance',
		'swps-redirects',
		'swps-debug',
		'swps-analytics',
		'swps-keywords',
		'swps-calendar',
		'swps-sitemaps',
		'swps-internal-links',
		'swps-schema',
		'swps-search-console',
		'swps-auto-optimize',
		'swps-competitors',
		'swps-local-seo',
		'swps-image-seo',
		'swps-crawl-files',
		'swps-backlinks',
	);

	private SWPS_User_Prefs $prefs;

	public function __construct( SWPS_User_Prefs $prefs ) {
		$this->prefs = $prefs;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'in_admin_header', array( $this, 'render_chrome' ), 1 );
		add_filter( 'admin_body_class', array( $this, 'add_body_class' ) );
		add_filter( 'language_attributes', array( $this, 'add_html_theme_attr' ) );
	}

	/**
	 * Add data-swps-theme to <html> via the language_attributes filter so
	 * CSS variables resolve correctly on first paint (no FOUC).
	 */
	public function add_html_theme_attr( string $output ): string {
		if ( ! is_admin() || ! self::is_own_page() ) {
			return $output;
		}
		return $output . ' data-swps-theme="' . esc_attr( $this->prefs->get_theme() ) . '"';
	}

	/**
	 * Is the current admin page one of ours?
	 */
	public static function is_own_page( string $hook = '' ): bool {
		if ( '' === $hook ) {
			$hook = $GLOBALS['hook_suffix'] ?? '';
		}
		if ( '' === $hook ) {
			return false;
		}
		foreach ( self::HOOK_PREFIXES as $prefix ) {
			if ( str_starts_with( $hook, $prefix ) ) {
				return true;
			}
		}
		// Fallback: check ?page= against known slugs (covers some renamed top-level scenarios).
		$page = $_GET['page'] ?? '';
		if ( is_string( $page ) && in_array( $page, self::OWN_SLUGS, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Add body class so the shell CSS can scope WP overrides.
	 */
	public function add_body_class( string $classes ): string {
		if ( self::is_own_page() ) {
			$classes .= ' swps-has-shell';
		}
		return $classes;
	}

	/**
	 * Enqueue tokens, shell, components CSS + the shell JS.
	 *
	 * The shell asset FILES are named admin-ui.(css|js), not shell.*:
	 * hosting WAFs commonly 403 any URL containing "shell" (webshell
	 * scanner rules), which blocked these two files and collapsed the
	 * whole admin layout on affected hosts (issue #88). Handles keep
	 * the swps-shell name — they never appear in URLs.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! self::is_own_page( $hook ) ) {
			return;
		}

		// v4.0.4: Poppins (heading) + Open Sans (body) — match jonimms.com.
		wp_enqueue_style(
			'swps-fonts',
			'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Open+Sans:wght@400;500;600&display=swap',
			array(),
			SWPS_VERSION
		);

		wp_enqueue_style(
			'swps-tokens',
			SWPS_PLUGIN_URL . 'admin/css/tokens.css',
			array( 'swps-fonts' ),
			SWPS_VERSION
		);

		wp_enqueue_style(
			'swps-shell',
			SWPS_PLUGIN_URL . 'admin/css/admin-ui.css',
			array( 'swps-tokens', 'dashicons' ),
			SWPS_VERSION
		);

		wp_enqueue_style(
			'swps-components',
			SWPS_PLUGIN_URL . 'admin/css/components.css',
			array( 'swps-tokens' ),
			SWPS_VERSION
		);

		wp_enqueue_style(
			'swps-templates',
			SWPS_PLUGIN_URL . 'admin/css/templates.css',
			array( 'swps-tokens', 'swps-components' ),
			SWPS_VERSION
		);

		wp_enqueue_script(
			'swps-shell',
			SWPS_PLUGIN_URL . 'admin/js/admin-ui.js',
			array(),
			SWPS_VERSION,
			true
		);

		wp_localize_script(
			'swps-shell',
			'swpsShell',
			array(
				'restUrl'   => esc_url_raw( rest_url() ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'theme'     => $this->prefs->get_theme(),
			)
		);
	}

	/**
	 * Render top bar inside #wpcontent.
	 *
	 * v4.0.4: dropped the in-shell nav entirely. WP's native admin
	 * sidebar + StrataWP submenu is the only navigation — no
	 * duplication, no horizontal scroll issues.
	 */
	public function render_chrome(): void {
		if ( ! self::is_own_page() ) {
			return;
		}
		$page = $_GET['page'] ?? 'stratawp-seo';
		$this->render_top_bar( $page );
	}

	/**
	 * Top bar: logo, breadcrumb, search, theme toggle, help.
	 */
	private function render_top_bar( string $current_page ): void {
		$crumb       = $this->breadcrumb_for( $current_page );
		$theme       = $this->prefs->get_theme();
		$is_dark     = ( self::THEME_DARK_NAME === $theme );
		$toggle_icon = $is_dark ? 'dashicons-sun' : 'dashicons-moon';
		$toggle_lbl  = $is_dark
			? __( 'Switch to light mode', 'stratawp-seo' )
			: __( 'Switch to dark mode', 'stratawp-seo' );
		// Visible label names the target mode (what a click switches to).
		$toggle_word = $is_dark ? __( 'Light', 'stratawp-seo' ) : __( 'Dark', 'stratawp-seo' );
		$dashboard   = admin_url( 'admin.php?page=stratawp-seo' );
		?>
		<div class="swps-shell-top">
			<a class="swps-shell-logo" href="<?php echo esc_url( $dashboard ); ?>">
				<span class="swps-shell-logo-mark">S</span>
				<span>StrataWP SEO</span>
			</a>
			<span class="swps-shell-breadcrumb"><?php echo wp_kses_post( $crumb ); ?></span>
			<div class="swps-shell-spacer"></div>
			<input
				type="search"
				class="swps-shell-search"
				placeholder="<?php esc_attr_e( 'Search settings, posts, keywords...', 'stratawp-seo' ); ?>"
				data-swps-search
				aria-label="<?php esc_attr_e( 'Search StrataWP SEO', 'stratawp-seo' ); ?>"
			/>
			<button
				type="button"
				class="swps-shell-iconbtn"
				data-swps-theme-toggle
				aria-label="<?php echo esc_attr( $toggle_lbl ); ?>"
				title="<?php echo esc_attr( $toggle_lbl ); ?>"
			>
				<span class="dashicons <?php echo esc_attr( $toggle_icon ); ?>"></span><span class="swps-shell-themebtn-label" data-swps-theme-label><?php echo esc_html( $toggle_word ); ?></span>
			</button>
			<a class="swps-shell-iconbtn"
				href="https://github.com/JonImmsWordpressDev/stratawp-seo"
				target="_blank"
				rel="noopener noreferrer"
				aria-label="<?php esc_attr_e( 'Help &amp; docs', 'stratawp-seo' ); ?>"
				title="<?php esc_attr_e( 'Help &amp; docs', 'stratawp-seo' ); ?>"
			>
				<span class="dashicons dashicons-editor-help"></span>
			</a>
		</div>
		<?php
	}

	/**
	 * Horizontal top nav with grouped pill items.
	 *
	 * v4.0.2: replaces the old left sidebar. Items are arranged inline
	 * with section dividers; nav scrolls horizontally if total width
	 * exceeds the viewport.
	 */
	private function render_top_nav( string $current_page ): void {
		$items = $this->get_nav_items();
		?>
		<nav class="swps-shell-nav" aria-label="<?php esc_attr_e( 'StrataWP SEO navigation', 'stratawp-seo' ); ?>">
			<?php
			foreach ( $items as $group_label => $group_items ) :
				$is_dashboard_group = ( '' === $group_label );
				?>
				<div class="swps-shell-nav-group">
					<?php if ( ! $is_dashboard_group ) : ?>
						<span class="swps-shell-nav-label"><?php echo esc_html( $group_label ); ?></span>
					<?php endif; ?>

					<?php
					foreach ( $group_items as $item ) :
						$url    = admin_url( 'admin.php?page=' . $item['slug'] );
						$active = ( $item['slug'] === $current_page ) ? ' is-active' : '';
						?>
						<a class="swps-shell-nav-item<?php echo esc_attr( $active ); ?>"
							href="<?php echo esc_url( $url ); ?>">
							<?php echo esc_html( $item['label'] ); ?>
							<?php if ( ! empty( $item['badge'] ) ) : ?>
								<span class="swps-shell-nav-item-badge<?php echo $item['badge']['type'] === 'new' ? ' is-new' : ''; ?>">
									<?php echo esc_html( $item['badge']['label'] ); ?>
								</span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Static nav items (groups → items). Filterable for extensions and
	 * later replacement by the Modules registry.
	 *
	 * @return array<string, array<int, array{slug:string,label:string,badge?:array{type:string,label:string}}>>
	 */
	private function get_nav_items(): array {
		$items = array(
			''                               => array(
				array(
					'slug'  => 'stratawp-seo',
					'label' => __( 'Dashboard', 'stratawp-seo' ),
				),
			),
			__( 'Content', 'stratawp-seo' )  => array(
				array(
					'slug'  => 'swps-generate',
					'label' => __( 'Generate', 'stratawp-seo' ),
				),
				array(
					'slug'  => 'swps-auto-optimize',
					'label' => __( 'Auto-Optimize', 'stratawp-seo' ),
					'badge' => array(
						'type'  => 'new',
						'label' => 'NEW',
					),
				),
				array(
					'slug'  => 'swps-calendar',
					'label' => __( 'Calendar', 'stratawp-seo' ),
				),
				array(
					'slug'  => 'swps-voice-profiles',
					'label' => __( 'Voice Profiles', 'stratawp-seo' ),
				),
			),
			__( 'SEO', 'stratawp-seo' )      => array(
				array(
					'slug'  => 'swps-seo-audit',
					'label' => __( 'Audit', 'stratawp-seo' ),
				),
				array(
					'slug'  => 'swps-search-appearance',
					'label' => __( 'Search Appearance', 'stratawp-seo' ),
				),
				array(
					'slug'  => 'swps-sitemaps',
					'label' => __( 'Sitemaps', 'stratawp-seo' ),
				),
				array(
					'slug'  => 'swps-redirects',
					'label' => __( 'Redirects', 'stratawp-seo' ),
				),
				array(
					'slug'  => 'swps-internal-links',
					'label' => __( 'Internal Links', 'stratawp-seo' ),
				),
				array(
					'slug'  => 'swps-local-seo',
					'label' => __( 'Local SEO', 'stratawp-seo' ),
					'badge' => array(
						'type'  => 'new',
						'label' => 'NEW',
					),
				),
				array(
					'slug'  => 'swps-image-seo',
					'label' => __( 'Image SEO', 'stratawp-seo' ),
					'badge' => array(
						'type'  => 'new',
						'label' => 'NEW',
					),
				),
				array(
					'slug'  => 'swps-crawl-files',
					'label' => __( 'Crawlers & Files', 'stratawp-seo' ),
					'badge' => array(
						'type'  => 'new',
						'label' => 'NEW',
					),
				),
			),
			__( 'Insights', 'stratawp-seo' ) => array(
				array(
					'slug'  => 'swps-analytics',
					'label' => __( 'Analytics', 'stratawp-seo' ),
				),
				array(
					'slug'  => 'swps-keywords',
					'label' => __( 'Keywords', 'stratawp-seo' ),
				),
				array(
					'slug'  => 'swps-competitors',
					'label' => __( 'Competitors', 'stratawp-seo' ),
					'badge' => array(
						'type'  => 'new',
						'label' => 'NEW',
					),
				),
				array(
					'slug'  => 'swps-backlinks',
					'label' => __( 'Backlinks', 'stratawp-seo' ),
					'badge' => array(
						'type'  => 'new',
						'label' => 'NEW',
					),
				),
			),
			__( 'System', 'stratawp-seo' )   => array(
				array(
					'slug'  => 'stratawp-seo',
					'label' => __( 'Settings', 'stratawp-seo' ),
				),
				array(
					'slug'  => 'swps-debug',
					'label' => __( 'Debug', 'stratawp-seo' ),
				),
			),
		);
		// The "Settings" entry under System and the "Dashboard" entry up top both
		// currently point to the same slug (stratawp-seo). Phase 2 splits them.

		/**
		 * Filter the admin shell sidebar items.
		 *
		 * @param array $items Group label => items array.
		 */
		return apply_filters( 'swps_admin_shell_nav', $items );
	}

	/**
	 * Build the breadcrumb trail HTML for a given slug.
	 */
	private function breadcrumb_for( string $page ): string {
		$items = $this->get_nav_items();
		foreach ( $items as $group_label => $group_items ) {
			foreach ( $group_items as $item ) {
				if ( $item['slug'] === $page ) {
					if ( '' === $group_label ) {
						return sprintf(
							'<span class="swps-shell-breadcrumb-sep">/</span><strong>%s</strong>',
							esc_html( $item['label'] )
						);
					}
					return sprintf(
						'<span class="swps-shell-breadcrumb-sep">/</span>%s<span class="swps-shell-breadcrumb-sep">/</span><strong>%s</strong>',
						esc_html( $group_label ),
						esc_html( $item['label'] )
					);
				}
			}
		}
		return sprintf(
			'<span class="swps-shell-breadcrumb-sep">/</span><strong>%s</strong>',
			esc_html__( 'Dashboard', 'stratawp-seo' )
		);
	}

	/* Constants for the toggle icon swap so we don't depend on the prefs class loading order. */
	private const THEME_DARK_NAME = 'dark';
}
