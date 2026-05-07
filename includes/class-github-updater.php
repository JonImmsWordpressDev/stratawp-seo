<?php
/**
 * GitHub Updater
 *
 * Hooks into the WordPress plugin update API so installs of StrataWP SEO
 * see "Update available" notices when a new release is published on GitHub,
 * and can one-click update via the standard Plugins screen.
 *
 * Source of truth: latest GitHub Release (tag `v{version}`) with an asset
 * named `stratawp-seo.zip` attached. The release.yml workflow produces this
 * automatically when stratawp-seo.php's Version header changes.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_GitHub_Updater {

	private const REPO            = 'JonImmsWordpressDev/stratawp-seo';
	private const ASSET_NAME      = 'stratawp-seo.zip';
	private const TRANSIENT_KEY   = 'swps_github_release_v1';
	private const TRANSIENT_TTL   = 12 * HOUR_IN_SECONDS;

	private string $plugin_file;
	private string $plugin_basename;
	private string $plugin_slug;
	private string $current_version;

	public function __construct( string $plugin_file, string $current_version ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		$this->current_version = $current_version;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'rename_source_dir' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache_after_update' ], 10, 2 );
	}

	/**
	 * Inject our plugin into the WP update transient if a newer release exists.
	 *
	 * @param mixed $transient Update_plugins transient (object or false).
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = [];
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
		$package_url    = $release['package_url'] ?? '';

		if ( '' === $remote_version || '' === $package_url ) {
			return $transient;
		}

		$item = (object) [
			'id'            => $this->plugin_basename,
			'slug'          => $this->plugin_slug,
			'plugin'        => $this->plugin_basename,
			'new_version'   => $remote_version,
			'url'           => 'https://github.com/' . self::REPO,
			'package'       => $package_url,
			'icons'         => [],
			'banners'       => [],
			'banners_rtl'   => [],
			'tested'        => '',
			'requires_php'  => '8.0',
			'compatibility' => new stdClass(),
		];

		if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = $item;
			unset( $transient->no_update[ $this->plugin_basename ] );
		} else {
			$transient->no_update[ $this->plugin_basename ] = $item;
			unset( $transient->response[ $this->plugin_basename ] );
		}

		return $transient;
	}

	/**
	 * Provide plugin metadata for the "View details" popup.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
		$body           = (string) ( $release['body'] ?? '' );
		$package_url    = (string) ( $release['package_url'] ?? '' );
		$published      = (string) ( $release['published_at'] ?? '' );

		$info               = new stdClass();
		$info->name         = 'StrataWP SEO';
		$info->slug         = $this->plugin_slug;
		$info->version      = $remote_version;
		$info->author       = '<a href="https://jonimms.com">Jon Imms</a>';
		$info->homepage     = 'https://github.com/' . self::REPO;
		$info->requires     = '6.0';
		$info->requires_php = '8.0';
		$info->download_link = $package_url;
		$info->last_updated = $published ? gmdate( 'Y-m-d H:i:s', strtotime( $published ) ) : '';
		$info->sections     = [
			'description' => 'AI-powered SEO content generator for WordPress. Updates are delivered directly from the official GitHub repository.',
			'changelog'   => '<pre style="white-space:pre-wrap;">' . esc_html( $body ) . '</pre>',
		];

		return $info;
	}

	/**
	 * Rename the unpacked source directory to match our plugin slug.
	 *
	 * GitHub release zips unpack into `stratawp-seo/` (matches slug) so this
	 * is usually a no-op, but covers cases where the asset name or branch zip
	 * unpacks to a different folder.
	 *
	 * @param string      $source        Path to extracted source.
	 * @param string      $remote_source Path to the remote source.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra args.
	 * @return string|WP_Error
	 */
	public function rename_source_dir( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->plugin_slug;
		if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Clear our cached release info after a plugin upgrade so the new
	 * version isn't reported as "update available" indefinitely.
	 */
	public function flush_cache_after_update( $upgrader, $hook_extra ): void {
		if (
			isset( $hook_extra['action'], $hook_extra['type'] )
			&& 'update' === $hook_extra['action']
			&& 'plugin' === $hook_extra['type']
		) {
			delete_transient( self::TRANSIENT_KEY );
		}
	}

	/**
	 * Fetch and cache the latest release from GitHub.
	 *
	 * @return array{tag_name:string,body:string,package_url:string,published_at:string}|null
	 */
	private function get_latest_release(): ?array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'StrataWP-SEO-Updater',
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache a short empty result so we don't hammer the API on transient failures.
			set_transient( self::TRANSIENT_KEY, [], 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			set_transient( self::TRANSIENT_KEY, [], 15 * MINUTE_IN_SECONDS );
			return null;
		}

		// Find the stratawp-seo.zip asset's browser_download_url.
		$package_url = '';
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET_NAME === $asset['name'] ) {
					$package_url = (string) $asset['browser_download_url'];
					break;
				}
			}
		}

		// Fallback to releases/latest/download URL (always resolves to the asset).
		if ( '' === $package_url ) {
			$package_url = 'https://github.com/' . self::REPO . '/releases/latest/download/' . self::ASSET_NAME;
		}

		$release = [
			'tag_name'     => (string) ( $data['tag_name'] ?? '' ),
			'body'         => (string) ( $data['body'] ?? '' ),
			'package_url'  => $package_url,
			'published_at' => (string) ( $data['published_at'] ?? '' ),
		];

		set_transient( self::TRANSIENT_KEY, $release, self::TRANSIENT_TTL );
		return $release;
	}
}
