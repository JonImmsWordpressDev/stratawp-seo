<?php
/**
 * AI Crawler support — robots.txt allowlist + dynamic llms.txt.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AI_Bots {

	/**
	 * Known AI crawler user agents. The key is what gets stored in the option;
	 * the value is the User-agent token written to robots.txt.
	 */
	public const KNOWN_BOTS = array(
		'gptbot'             => 'GPTBot',
		'oai_searchbot'      => 'OAI-SearchBot',
		'chatgpt_user'       => 'ChatGPT-User',
		'claudebot'          => 'ClaudeBot',
		'claude_web'         => 'Claude-Web',
		'anthropic_ai'       => 'anthropic-ai',
		'perplexitybot'      => 'PerplexityBot',
		'perplexity_user'    => 'Perplexity-User',
		'google_extended'    => 'Google-Extended',
		'applebot_extended'  => 'Applebot-Extended',
		'ccbot'              => 'CCBot',
		'meta_externalagent' => 'Meta-ExternalAgent',
		'bytespider'         => 'Bytespider',
		'amazonbot'          => 'Amazonbot',
		'duckassistbot'      => 'DuckAssistBot',
	);

	public function __construct() {
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 100, 2 );
		add_action( 'init', array( $this, 'maybe_serve_llms_txt' ), 1 );
	}

	/**
	 * Return the canonical bot list. Filter `swps_ai_bots_known` lets third
	 * parties add custom AI crawlers (custom keys → UA tokens).
	 *
	 * @return array<string, string> Map of bot key → User-agent token.
	 */
	public static function get_bots(): array {
		return (array) apply_filters( 'swps_ai_bots_known', self::KNOWN_BOTS );
	}

	/**
	 * Append AI bot allow/disallow rules to robots.txt.
	 */
	public function filter_robots_txt( string $output, $public ): string {
		if ( ! $public ) {
			return $output;
		}

		$allowed = $this->get_allowed_bots();
		$blocked = $this->get_blocked_bots();

		if ( empty( $allowed ) && empty( $blocked ) ) {
			return $output;
		}

		$lines = array( '', '# AI crawlers — managed by StrataWP SEO' );

		foreach ( $allowed as $token ) {
			$lines[] = '';
			$lines[] = 'User-agent: ' . $token;
			$lines[] = 'Allow: /';
		}

		foreach ( $blocked as $token ) {
			$lines[] = '';
			$lines[] = 'User-agent: ' . $token;
			$lines[] = 'Disallow: /';
		}

		return $output . implode( "\n", $lines ) . "\n";
	}

	/**
	 * Get the list of bot user-agent tokens the user has allowed.
	 */
	private function get_allowed_bots(): array {
		$stored = get_option( 'swps_ai_bots_allowed', null );

		// Default: allow every known bot if the option has never been saved.
		if ( null === $stored ) {
			return array_values( self::KNOWN_BOTS );
		}

		$stored = is_array( $stored ) ? $stored : array();
		$tokens = array();
		foreach ( $stored as $key ) {
			if ( isset( self::KNOWN_BOTS[ $key ] ) ) {
				$tokens[] = self::KNOWN_BOTS[ $key ];
			}
		}
		return $tokens;
	}

	/**
	 * Get the list of bot tokens explicitly blocked (known bots not in the allow list).
	 * Only emits a Disallow when the user has saved the option (otherwise default = allow all).
	 */
	private function get_blocked_bots(): array {
		$stored = get_option( 'swps_ai_bots_allowed', null );
		if ( null === $stored ) {
			return array();
		}
		$stored  = is_array( $stored ) ? $stored : array();
		$blocked = array();
		foreach ( self::KNOWN_BOTS as $key => $token ) {
			if ( ! in_array( $key, $stored, true ) ) {
				$blocked[] = $token;
			}
		}
		return $blocked;
	}

	/**
	 * Intercept requests for /llms.txt and serve the generated file.
	 */
	public function maybe_serve_llms_txt(): void {
		if ( ! get_option( 'swps_llms_txt_enabled', 1 ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		if ( '/llms.txt' !== $uri ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $this->generate_llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markdown body
		exit;
	}

	/**
	 * Build the llms.txt content from site options + recent published posts.
	 */
	public function generate_llms_txt(): string {
		$site_name = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$home      = home_url( '/' );
		$tagline   = wp_strip_all_tags( get_bloginfo( 'description' ) );

		// Prefer the plugin's Site Description over the WP tagline if set.
		$description = (string) get_option( 'swps_site_description', '' );
		$description = trim( wp_strip_all_tags( $description ) );
		if ( '' === $description ) {
			$description = $tagline;
		}
		$description = $this->collapse_whitespace( $description );

		$out  = "# {$site_name}\n\n";
		$out .= '> ' . $description . "\n\n";
		$out .= "Site: {$home}\n";

		$sitemap_url = $this->detect_sitemap_url();
		if ( $sitemap_url ) {
			$out .= "Sitemap: {$sitemap_url}\n";
		}
		$out .= "\n";

		// Posts.
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => (int) apply_filters( 'swps_llms_txt_post_limit', 100 ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! empty( $posts ) ) {
			$out .= "## Posts\n";
			foreach ( $posts as $post ) {
				$out .= $this->format_entry( $post );
			}
			$out .= "\n";
		}

		// Pages (top-level only, exclude home).
		$pages    = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 25,
				'post_parent'    => 0,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		$front_id = (int) get_option( 'page_on_front' );
		$pages    = array_filter(
			$pages,
			static fn( $p ) => (int) $p->ID !== $front_id
		);

		if ( ! empty( $pages ) ) {
			$out .= "## Pages\n";
			foreach ( $pages as $page ) {
				$out .= $this->format_entry( $page );
			}
			$out .= "\n";
		}

		// Categories.
		$cats = get_categories(
			array(
				'hide_empty' => true,
				'number'     => 20,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( ! empty( $cats ) ) {
			$out .= "## Categories\n";
			foreach ( $cats as $cat ) {
				$name = $this->collapse_whitespace( wp_strip_all_tags( $cat->name ) );
				$url  = get_category_link( $cat->term_id );
				$out .= "- [{$name}]({$url})\n";
			}
			$out .= "\n";
		}

		return apply_filters( 'swps_llms_txt_content', $out );
	}

	/**
	 * Format one post/page entry as a markdown bullet with a one-line summary.
	 */
	private function format_entry( WP_Post $post ): string {
		$title   = $this->collapse_whitespace( wp_strip_all_tags( get_the_title( $post ) ) );
		$url     = get_permalink( $post );
		$summary = $this->build_summary( $post );

		if ( '' !== $summary ) {
			return "- [{$title}]({$url}): {$summary}\n";
		}
		return "- [{$title}]({$url})\n";
	}

	/**
	 * Build a one-line summary from (in order): plugin meta description, post excerpt,
	 * trimmed first paragraph of the content.
	 */
	private function build_summary( WP_Post $post ): string {
		$candidates = array(
			(string) get_post_meta( $post->ID, '_swps_meta_description', true ),
			(string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true ),
			(string) get_post_meta( $post->ID, 'rank_math_description', true ),
			(string) $post->post_excerpt,
			wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ),
		);

		foreach ( $candidates as $candidate ) {
			$candidate = $this->collapse_whitespace( wp_strip_all_tags( $candidate ) );
			if ( '' !== $candidate ) {
				return $this->trim_to_length( $candidate, 200 );
			}
		}

		return '';
	}

	private function collapse_whitespace( string $s ): string {
		$s = preg_replace( '/\s+/', ' ', $s );
		return trim( $s );
	}

	private function trim_to_length( string $s, int $max ): string {
		if ( mb_strlen( $s ) <= $max ) {
			return $s;
		}
		$cut = mb_substr( $s, 0, $max );
		// Trim back to the last sentence/word boundary.
		$last_period = strrpos( $cut, '. ' );
		if ( $last_period !== false && $last_period > $max * 0.6 ) {
			return rtrim( mb_substr( $cut, 0, $last_period + 1 ) );
		}
		$last_space = strrpos( $cut, ' ' );
		if ( $last_space !== false ) {
			$cut = mb_substr( $cut, 0, $last_space );
		}
		return rtrim( $cut, ' ,;:' ) . '…';
	}

	/**
	 * Detect the canonical sitemap URL from common providers.
	 */
	private function detect_sitemap_url(): ?string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return home_url( '/sitemap_index.xml' );
		}
		if ( class_exists( 'RankMath' ) ) {
			return home_url( '/sitemap_index.xml' );
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return home_url( '/sitemap.xml' );
		}

		// StrataWP SEO disables the WP core sitemap and serves its own index.
		return home_url( '/sitemap_index.xml' );
	}
}
