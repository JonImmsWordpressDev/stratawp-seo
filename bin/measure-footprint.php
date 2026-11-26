<?php
/**
 * Measures what StrataWP SEO adds to a WordPress site.
 *
 * Every sample is a fresh PHP process that boots the site and renders one page,
 * once with the plugin loaded and once with it filtered out of active_plugins
 * (nothing is deactivated or written). The two runs are interleaved and the
 * report shows the median of each.
 *
 * Usage:
 *   php bin/measure-footprint.php --path=/path/to/wordpress [--runs=15] [--uri=/ --uri=/hello-world/] [--json]
 *
 *   --path   WordPress root (the folder with wp-load.php). Required.
 *   --runs   Samples per page and plugin state. Default 15.
 *   --uri    Front-end path to measure, repeatable. Default: home page and the latest post.
 *   --json   Print the raw results as JSON instead of the table.
 *
 * Admin pages are rendered as the first administrator, with a stubbed auth
 * cookie check in the measuring process only. The site must have an
 * administrator and at least one published post.
 *
 * Runs PHP with OPcache's file cache when the extension is available, so
 * timings are closer to a production server than a cold CLI compile.
 *
 * @package StrataWP_SEO
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$swps_fp_opts = swps_fp_parse_args( array_slice( $argv, 1 ) );

if ( isset( $swps_fp_opts['child'] ) ) {
	// WordPress must be loaded in global scope, so the child setup only
	// returns the file to require.
	require swps_fp_child( $swps_fp_opts );
	return;
}

exit( swps_fp_main( $swps_fp_opts ) );

/**
 * @param string[] $args CLI args.
 * @return array<string, mixed>
 */
function swps_fp_parse_args( array $args ): array {
	$opts = array( 'uri' => array() );
	foreach ( $args as $arg ) {
		if ( ! preg_match( '/^--([a-z-]+)(?:=(.*))?$/', $arg, $m ) ) {
			continue;
		}
		$value = $m[2] ?? true;
		if ( 'uri' === $m[1] ) {
			$opts['uri'][] = (string) $value;
		} else {
			$opts[ $m[1] ] = $value;
		}
	}
	return $opts;
}

/**
 * @param float[] $values Samples.
 */
function swps_fp_median( array $values ): float {
	if ( ! $values ) {
		return 0.0;
	}
	sort( $values );
	$n   = count( $values );
	$mid = intdiv( $n, 2 );
	return $n % 2 ? (float) $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
}

/**
 * @param array<string, mixed> $opts Parsed options.
 */
function swps_fp_main( array $opts ): int {
	$path = isset( $opts['path'] ) ? rtrim( (string) $opts['path'], '/' ) : '';
	if ( '' === $path || ! is_file( $path . '/wp-load.php' ) ) {
		fwrite( STDERR, "Usage: php bin/measure-footprint.php --path=/path/to/wordpress [--runs=15] [--uri=/path/] [--json]\n" );
		return 2;
	}
	$runs = max( 1, (int) ( $opts['runs'] ?? 15 ) );

	$info = swps_fp_spawn( $path, array( 'context' => 'info', 'plugin' => 'on' ) );
	if ( ! $info || empty( $info['plugin_active'] ) ) {
		fwrite( STDERR, "StrataWP SEO is not active on this site (or the site did not boot).\n" );
		return 1;
	}

	$uris = $opts['uri'] ? $opts['uri'] : array_values( array_filter( array( '/', $info['latest_post_uri'] ) ) );

	$targets = array();
	foreach ( $uris as $uri ) {
		$targets[] = array( 'label' => 'front ' . $uri, 'context' => 'front', 'uri' => $uri );
	}
	$targets[] = array( 'label' => 'admin Posts list', 'context' => 'admin', 'uri' => '/wp-admin/edit.php' );
	if ( $info['latest_post_id'] ) {
		$targets[] = array(
			'label'   => 'admin post editor',
			'context' => 'admin',
			'uri'     => '/wp-admin/post.php?post=' . (int) $info['latest_post_id'] . '&action=edit',
		);
	}

	$results = array();
	foreach ( $targets as $target ) {
		$samples = array( 'on' => array(), 'off' => array() );
		// One discarded warm-up per state fills the OPcache file cache.
		for ( $i = -1; $i < $runs; $i++ ) {
			foreach ( array( 'on', 'off' ) as $state ) {
				$sample = swps_fp_spawn( $path, array( 'context' => $target['context'], 'uri' => $target['uri'], 'plugin' => $state ) );
				if ( ! $sample ) {
					fwrite( STDERR, "Sample failed for {$target['label']} ({$state}).\n" );
					return 1;
				}
				if ( $i >= 0 ) {
					$samples[ $state ][] = $sample;
				}
			}
		}
		$row = array( 'label' => $target['label'] );
		foreach ( array( 'on', 'off' ) as $state ) {
			$row[ $state ] = array(
				'ms'         => round( swps_fp_median( array_column( $samples[ $state ], 'ms' ) ), 2 ),
				'queries'    => swps_fp_median( array_column( $samples[ $state ], 'queries' ) ),
				'head_bytes' => swps_fp_median( array_column( $samples[ $state ], 'head_bytes' ) ),
				'status'     => $samples[ $state ][0]['status'],
			);
		}
		$row['plugin_assets'] = $samples['on'][0]['plugin_assets'];
		$results[]            = $row;
	}

	$report = array(
		'measured_at' => gmdate( 'c' ),
		'php'         => PHP_VERSION,
		'opcache'     => swps_fp_opcache_args() ? 'file cache' : 'off',
		'runs'        => $runs,
		'wp_version'  => $info['wp_version'],
		'version'     => $info['plugin_version'],
		'tables'      => $info['tables'],
		'autoload'    => $info['autoload'],
		'pages'       => $results,
	);

	if ( isset( $opts['json'] ) ) {
		echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
		return 0;
	}

	swps_fp_print( $report );
	return 0;
}

/**
 * @param array<string, mixed> $report Results.
 */
function swps_fp_print( array $report ): void {
	printf(
		"StrataWP SEO %s footprint on WordPress %s, PHP %s, OPcache %s, median of %d runs\n\n",
		$report['version'],
		$report['wp_version'],
		$report['php'],
		$report['opcache'],
		$report['runs']
	);
	printf( "%-34s %10s %10s %9s %8s %8s %7s %9s %9s %7s\n", 'Page', 'ms off', 'ms on', '+ms', 'q off', 'q on', '+q', 'head off', 'head on', '+head' );
	foreach ( $report['pages'] as $row ) {
		printf(
			"%-34s %10.2f %10.2f %+9.2f %8s %8s %+7s %9s %9s %+7s\n",
			$row['label'],
			$row['off']['ms'],
			$row['on']['ms'],
			$row['on']['ms'] - $row['off']['ms'],
			$row['off']['queries'],
			$row['on']['queries'],
			$row['on']['queries'] - $row['off']['queries'],
			$row['off']['head_bytes'],
			$row['on']['head_bytes'],
			$row['on']['head_bytes'] - $row['off']['head_bytes']
		);
		if ( $row['on']['status'] !== $row['off']['status'] ) {
			printf( "  note: HTTP status %s with the plugin, %s without\n", $row['on']['status'], $row['off']['status'] );
		}
	}

	echo "\nPlugin JS/CSS enqueued (with the plugin on):\n";
	foreach ( $report['pages'] as $row ) {
		$assets = $row['plugin_assets'];
		printf( "  %-32s %s\n", $row['label'], $assets ? implode( ', ', $assets ) : 'none' );
	}

	printf( "\nCustom tables: %d\n", count( $report['tables'] ) );
	foreach ( $report['tables'] as $table ) {
		printf( "  %s\n", $table );
	}
	printf(
		"\nAutoloaded options from the plugin: %d options, %s bytes (all autoloaded options on the site: %s bytes)\n",
		$report['autoload']['count'],
		number_format( $report['autoload']['bytes'] ),
		number_format( $report['autoload']['site_bytes'] )
	);
	echo "\nms is wall time inside PHP from boot to the end of the response, not network time.\n";
}

/**
 * @return string[] Extra php -d flags.
 */
function swps_fp_opcache_args(): array {
	static $args = null;
	if ( null !== $args ) {
		return $args;
	}
	$args = array();
	if ( extension_loaded( 'Zend OPcache' ) ) {
		$dir = sys_get_temp_dir() . '/swps-footprint-opcache';
		if ( is_dir( $dir ) || @mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$args = array( '-d', 'opcache.enable_cli=1', '-d', 'opcache.file_cache=' . $dir, '-d', 'opcache.file_cache_only=1', '-d', 'opcache.validate_timestamps=0' );
		}
	}
	return $args;
}

/**
 * Runs one measurement in a fresh PHP process.
 *
 * @param string               $path WordPress root.
 * @param array<string,string> $args Child options.
 * @return array<string, mixed>|null
 */
function swps_fp_spawn( string $path, array $args ): ?array {
	$cmd = array_merge( array( PHP_BINARY ), swps_fp_opcache_args(), array( __FILE__, '--child', '--path=' . $path ) );
	foreach ( $args as $key => $value ) {
		$cmd[] = '--' . $key . '=' . $value;
	}
	$cmd = implode( ' ', array_map( 'escapeshellarg', $cmd ) );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- fixed local command, measurement tool.
	exec( $cmd . ' 2>/dev/null', $out, $code );
	foreach ( array_reverse( $out ) as $line ) {
		if ( 0 === strpos( $line, 'SWPS_FOOTPRINT ' ) ) {
			$data = json_decode( substr( $line, 15 ), true );
			return is_array( $data ) ? $data : null;
		}
	}
	return null;
}

/**
 * Child process: boot WordPress, render one page, print one result line.
 *
 * @param array<string, mixed> $opts Parsed options.
 * @return string File to require in global scope.
 */
function swps_fp_child( array $opts ): string {
	$start   = hrtime( true );
	$path    = rtrim( (string) $opts['path'], '/' );
	$context = (string) ( $opts['context'] ?? 'front' );
	$uri     = (string) ( $opts['uri'][0] ?? '/' );
	$off     = 'off' === ( $opts['plugin'] ?? 'on' );

	define( 'SAVEQUERIES', false );
	if ( ! defined( 'DISABLE_WP_CRON' ) ) {
		define( 'DISABLE_WP_CRON', true );
	}

	$strip = static function ( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}
		foreach ( $plugins as $key => $value ) {
			$file = is_string( $value ) ? $value : (string) $key;
			if ( '/stratawp-seo.php' === substr( '/' . $file, -17 ) ) {
				unset( $plugins[ $key ] );
			}
		}
		return $plugins;
	};
	if ( $off ) {
		// Pre-initialized hooks: WordPress turns these into WP_Hook objects on load.
		$GLOBALS['wp_filter'] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			'option_active_plugins'                => array( 10 => array( array( 'function' => $strip, 'accepted_args' => 1 ) ) ),
			'site_option_active_sitewide_plugins' => array( 10 => array( array( 'function' => $strip, 'accepted_args' => 1 ) ) ),
		);
	}

	$parts                     = parse_url( $uri );
	$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$_SERVER['SERVER_NAME']    = $_SERVER['HTTP_HOST'];
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI']    = $uri;
	$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
	$_SERVER['HTTP_USER_AGENT'] = 'StrataWP SEO footprint measurement';
	$_GET                      = array();
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $_GET );
	}
	$_REQUEST = $_GET;

	$GLOBALS['swps_fp'] = array(
		'start'   => $start,
		'context' => $context,
	);

	register_shutdown_function( 'swps_fp_child_report' );
	ob_start();

	if ( 'info' === $context ) {
		return $path . '/wp-load.php';
	}

	if ( 'admin' === $context ) {
		$script                  = $path . $parts['path'];
		$_SERVER['PHP_SELF']     = $parts['path'];
		$_SERVER['SCRIPT_NAME']  = $parts['path'];
		chdir( dirname( $script ) );
		// The measuring process is "logged in" as the first administrator.
		// wp_validate_auth_cookie() is pluggable, so this definition wins.
		function wp_validate_auth_cookie( $cookie = '', $scheme = '' ) {
			static $id = null;
			if ( null === $id ) {
				$ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) );
				$id  = $ids ? (int) $ids[0] : 0;
			}
			return $id ? $id : false;
		}
		return $script;
	}

	define( 'WP_USE_THEMES', true );
	return $path . '/wp-blog-header.php';
}

/**
 * Shutdown handler for the child: collects the numbers and prints them.
 */
function swps_fp_child_report(): void {
	$ms   = ( hrtime( true ) - $GLOBALS['swps_fp']['start'] ) / 1e6;
	$html = '';
	while ( ob_get_level() > 0 ) {
		$html = (string) ob_get_clean() . $html;
	}

	global $wpdb;
	$result = array(
		'ms'         => round( $ms, 3 ),
		'queries'    => isset( $wpdb ) ? (int) $wpdb->num_queries : 0,
		'head_bytes' => 0,
		'status'     => function_exists( 'http_response_code' ) ? (int) http_response_code() : 0,
	);

	if ( preg_match( '#<head[^>]*>(.*?)</head>#is', $html, $m ) ) {
		$result['head_bytes'] = strlen( $m[1] );
	}

	$plugin_url = defined( 'SWPS_PLUGIN_URL' ) ? SWPS_PLUGIN_URL : '';
	$assets     = array();
	if ( $plugin_url && function_exists( 'wp_scripts' ) ) {
		foreach ( array( 'js' => wp_scripts(), 'css' => wp_styles() ) as $kind => $deps ) {
			foreach ( $deps->done as $handle ) {
				$src = $deps->registered[ $handle ]->src ?? '';
				if ( is_string( $src ) && 0 === strpos( $src, $plugin_url ) ) {
					$assets[] = $handle . ' (' . $kind . ')';
				}
			}
		}
	}
	$result['plugin_assets'] = $assets;

	if ( 'info' === $GLOBALS['swps_fp']['context'] && function_exists( 'is_plugin_active' ) === false && defined( 'ABSPATH' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( 'info' === $GLOBALS['swps_fp']['context'] && isset( $wpdb ) ) {
		$latest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'post_type' => 'post' ) );
		$like   = $wpdb->esc_like( $wpdb->prefix . 'swps_' ) . '%';
		$auto   = "autoload IN ('yes','on','auto-on','auto')";
		$result += array(
			'plugin_active'   => defined( 'SWPS_VERSION' ),
			'plugin_version'  => defined( 'SWPS_VERSION' ) ? SWPS_VERSION : null,
			'wp_version'      => get_bloginfo( 'version' ),
			'latest_post_id'  => $latest ? (int) $latest[0]->ID : 0,
			'latest_post_uri' => $latest ? (string) wp_parse_url( get_permalink( $latest[0] ), PHP_URL_PATH ) : '',
			'tables'          => $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ),
			'autoload'        => array(
				'count'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$auto} AND option_name LIKE %s", 'swps\_%' ) ),
				'bytes'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE {$auto} AND option_name LIKE %s", 'swps\_%' ) ),
				'site_bytes' => (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE {$auto}" ),
			),
		);
	}

	echo "\nSWPS_FOOTPRINT ", json_encode( $result, JSON_UNESCAPED_SLASHES ), "\n";
}
