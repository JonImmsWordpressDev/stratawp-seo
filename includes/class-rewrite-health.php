<?php
/**
 * Rewrite rule health check.
 *
 * Loopback-requests the sitemap index, one post type sitemap and one
 * category archive. A 404 on any of them means the rewrite rules were
 * flushed while some rules were missing (a known failure mode of flushing
 * on Heartbeat or front-end requests). Reported in Site Health and the
 * Sitemap audit module, with a one-click "Rebuild permalinks" action.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrite rule probes, the Site Health test and the rebuild action.
 */
class SWPS_Rewrite_Health {

	const CACHE_KEY = 'swps_rewrite_health';
	const CACHE_TTL = 10 * MINUTE_IN_SECONDS;
	const ACTION    = 'swps_rebuild_permalinks';

	/**
	 * Register the Site Health test, the rebuild action and its notice.
	 */
	public function __construct() {
		add_filter( 'site_status_tests', array( $this, 'register_site_health_test' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_rebuild' ) );
		add_action( 'admin_notices', array( $this, 'rebuilt_notice' ) );
	}

	/**
	 * URLs to probe, keyed by a human label.
	 *
	 * @return array<string, string>
	 */
	public static function get_probe_urls(): array {
		$urls = array(
			__( 'Sitemap index', 'stratawp-seo' ) => SWPS_Sitemap_Manager::get_sitemap_url(),
		);

		foreach ( array( 'post', 'page' ) as $post_type ) {
			if ( ! get_option( "swps_sitemap_exclude_{$post_type}", 0 ) && ! get_option( "swps_noindex_{$post_type}", 0 ) ) {
				$urls[ __( 'Post type sitemap', 'stratawp-seo' ) ] = home_url( "/{$post_type}-sitemap.xml" );
				break;
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'number'     => 1,
			)
		);
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$link = get_term_link( $terms[0] );
			if ( ! is_wp_error( $link ) ) {
				$urls[ __( 'Category archive', 'stratawp-seo' ) ] = $link;
			}
		}

		return $urls;
	}

	/**
	 * Labels of the probes that returned 404.
	 *
	 * @param array<string, array{url: string, code: int|null}> $checked Probe results; code null when the request failed.
	 * @return string[]
	 */
	public static function find_broken( array $checked ): array {
		$broken = array();
		foreach ( $checked as $label => $result ) {
			if ( 404 === ( $result['code'] ?? null ) ) {
				$broken[] = (string) $label;
			}
		}
		return $broken;
	}

	/**
	 * Run the probes (cached for a few minutes).
	 *
	 * @param bool $use_cache Whether a recent result may be reused.
	 * @return array{checked: array<string, array{url: string, code: int|null}>, broken: string[]}
	 */
	public static function check( bool $use_cache = true ): array {
		if ( $use_cache ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['checked'], $cached['broken'] ) ) {
				return $cached;
			}
		}

		$checked = array();
		foreach ( self::get_probe_urls() as $label => $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 3,
					// Same as core's loopback checks: local certificates are often self-signed.
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);

			$checked[ $label ] = array(
				'url'  => (string) $url,
				'code' => is_wp_error( $response ) ? null : (int) wp_remote_retrieve_response_code( $response ),
			);
		}

		$result = array(
			'checked' => $checked,
			'broken'  => self::find_broken( $checked ),
		);
		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Nonced URL of the "Rebuild permalinks" action.
	 */
	public static function rebuild_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
	}

	/**
	 * Add the Site Health test.
	 *
	 * @param array $tests Site Health tests.
	 */
	public function register_site_health_test( $tests ) {
		$tests['direct']['swps_rewrite_rules'] = array(
			'label' => __( 'Rewrite rules', 'stratawp-seo' ),
			'test'  => array( $this, 'site_health_test' ),
		);
		return $tests;
	}

	/**
	 * Site Health result.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health_test(): array {
		$health = self::check( false );

		$result = array(
			'label'       => __( 'Rewrite rules are working', 'stratawp-seo' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'SEO', 'stratawp-seo' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'The sitemap index, a post type sitemap and a category archive all respond.', 'stratawp-seo' ) . '</p>',
			'actions'     => '',
			'test'        => 'swps_rewrite_rules',
		);

		$unchecked = array_keys( array_filter( $health['checked'], fn( $r ) => null === $r['code'] ) );

		if ( $health['broken'] ) {
			$items = '';
			foreach ( $health['broken'] as $label ) {
				$items .= '<li>' . esc_html( $label ) . ': <code>' . esc_html( $health['checked'][ $label ]['url'] ) . '</code></li>';
			}
			$result['label']          = __( 'Rewrite rules look broken', 'stratawp-seo' );
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['description']    = '<p>' . esc_html__( 'These URLs return 404. This usually means the rewrite rules were rebuilt while some rules were missing. Rebuilding the permalinks restores them.', 'stratawp-seo' ) . '</p><ul>' . $items . '</ul>';
			$result['actions']        = '<p><a class="button button-primary" href="' . esc_url( self::rebuild_url() ) . '">' . esc_html__( 'Rebuild permalinks', 'stratawp-seo' ) . '</a></p>';
		} elseif ( $unchecked ) {
			$result['label']       = __( 'Rewrite rules could not be checked', 'stratawp-seo' );
			$result['status']      = 'recommended';
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: comma-separated list of checks. */
					__( 'The site could not request these URLs from itself: %s. Loopback requests may be blocked.', 'stratawp-seo' ),
					implode( ', ', $unchecked )
				)
			) . '</p>';
		}

		return $result;
	}

	/**
	 * Handle the admin-post action: rebuild the rewrite rules.
	 */
	public function handle_rebuild(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		self::rebuild();

		$back = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'swps_permalinks_rebuilt', '1', $back ? $back : admin_url( 'site-health.php' ) ) );
		exit;
	}

	/**
	 * Rebuild the rewrite rules and forget the last health result. Call only
	 * from an explicit admin action.
	 */
	public static function rebuild(): void {
		flush_rewrite_rules( false );
		if ( defined( 'SWPS_VERSION' ) ) {
			update_option( 'swps_rewrite_version', SWPS_VERSION, false );
		}
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Notice after a rebuild.
	 */
	public function rebuilt_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( empty( $_GET['swps_permalinks_rebuilt'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Permalinks rebuilt.', 'stratawp-seo' ) . '</p></div>';
	}
}
