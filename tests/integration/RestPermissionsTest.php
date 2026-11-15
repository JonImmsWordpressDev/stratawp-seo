<?php
/**
 * Every swps/v1 REST route refuses anonymous visitors and subscribers.
 *
 * Calls each route's permission_callback directly rather than dispatching,
 * because WordPress validates required args before permissions and would
 * answer 400 for most routes without ever reaching the check under test.
 *
 * @package StrataWP_SEO
 */

class RestPermissionsTest extends WP_UnitTestCase {

	/**
	 * Routes intended for any logged-in user (their own admin preferences).
	 */
	private const SUBSCRIBER_ALLOWED = array( '/swps/v1/user-prefs' );

	public function test_every_route_has_a_real_permission_check(): void {
		$routes = $this->swps_routes();
		$this->assertNotEmpty( $routes, 'expected swps/v1 routes to be registered' );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$checked    = 0;

		foreach ( $routes as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				$callback = $handler['permission_callback'] ?? null;
				$label    = $route . ' [' . implode( ',', array_keys( $handler['methods'] ) ) . ']';

				$this->assertNotNull( $callback, "{$label} has no permission_callback" );
				$this->assertNotSame( '__return_true', $callback, "{$label} is public" );

				$request = new WP_REST_Request( (string) array_key_first( $handler['methods'] ), $route );
				$request->set_param( 'post_id', self::factory()->post->create() );

				wp_set_current_user( 0 );
				$this->assertNotTrue( call_user_func( $callback, $request ), "{$label} allows anonymous visitors" );

				wp_set_current_user( $subscriber );
				$allowed = $this->starts_with_any( $route, self::SUBSCRIBER_ALLOWED );
				if ( ! $allowed ) {
					$this->assertNotTrue( call_user_func( $callback, $request ), "{$label} allows subscribers" );
				}
				++$checked;
			}
		}

		$this->assertGreaterThanOrEqual( 20, $checked );
	}

	/**
	 * @return array<string, array<int, array>>
	 */
	private function swps_routes(): array {
		$out = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			// '/swps/v1' itself is WordPress core's namespace index, not ours.
			if ( str_starts_with( $route, '/swps/' ) && ! preg_match( '#^/swps/v\d+$#', $route ) ) {
				$out[ $route ] = $handlers;
			}
		}
		return $out;
	}

	private function starts_with_any( string $route, array $prefixes ): bool {
		foreach ( $prefixes as $p ) {
			if ( str_starts_with( $route, $p ) ) {
				return true;
			}
		}
		return false;
	}
}
