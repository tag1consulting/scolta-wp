<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Smoke-tests all six Scolta REST API handlers by invoking them directly.
 *
 * WP's stub environment already provides WP_REST_Request and WP_REST_Response,
 * so these tests call every handler method with realistic (minimal) parameters
 * and assert:
 *   1. The return value is a WP_REST_Response — no PHP fatal or uncaught exception.
 *   2. The HTTP status is a valid code (200–599).
 *
 * With no AI key configured, AI handlers return service-unavailable errors via
 * a proper WP_REST_Response — not by crashing. Admin-only endpoints
 * (build-progress, rebuild-now) are tested against the stub scheduler and must
 * also return clean responses.
 *
 * This is the WP equivalent of Drupal's RouteSmokeFunctionalTest: it catches
 * the class of error where a handler exists and is registered but throws or
 * PHP-fatals when actually invoked.
 */
class RestApiSmokeTest extends TestCase {

	protected function set_up(): void {
		$GLOBALS['wp_options']            = [];
		$GLOBALS['scolta_registered_routes'] = [];
	}

	// -----------------------------------------------------------------------
	// All six routes must be registered (guard against undocumented additions)
	// -----------------------------------------------------------------------

	public function test_all_six_routes_are_registered(): void {
		$GLOBALS['scolta_registered_routes'] = [];
		Scolta_Rest_Api::register_routes();

		$routes   = array_map( fn( $r ) => $r['route'], $GLOBALS['scolta_registered_routes'] );
		$expected = [ '/expand-query', '/summarize', '/followup', '/health', '/build-progress', '/rebuild-now' ];

		foreach ( $expected as $route ) {
			$this->assertContains( $route, $routes, "Route {$route} is not registered." );
		}

		$this->assertCount(
			count( $expected ),
			$routes,
			'Unexpected number of registered routes — a route was added or removed without updating this test.'
		);
	}

	// -----------------------------------------------------------------------
	// Handler smoke tests — each must return WP_REST_Response, never PHP-fatal
	// -----------------------------------------------------------------------

	/**
	 * POST /wp-json/scolta/v1/expand-query
	 * Without a valid AI key, the handler must return an error response (not crash).
	 */
	public function test_handle_expand_returns_rest_response(): void {
		$request = new WP_REST_Request( 'POST', '/wp-json/scolta/v1/expand-query' );
		$request->set_param( 'query', 'test search query' );

		$response = Scolta_Rest_Api::handle_expand( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assert_valid_http_status( $response->get_status(), 'handle_expand' );
	}

	/**
	 * POST /wp-json/scolta/v1/summarize
	 */
	public function test_handle_summarize_returns_rest_response(): void {
		$request = new WP_REST_Request( 'POST', '/wp-json/scolta/v1/summarize' );
		$request->set_param( 'query', 'test query' );
		$request->set_param( 'context', 'test search results context for summarization' );

		$response = Scolta_Rest_Api::handle_summarize( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assert_valid_http_status( $response->get_status(), 'handle_summarize' );
	}

	/**
	 * POST /wp-json/scolta/v1/followup
	 */
	public function test_handle_followup_returns_rest_response(): void {
		$request = new WP_REST_Request( 'POST', '/wp-json/scolta/v1/followup' );
		$request->set_param(
			'messages',
			[
				[ 'role' => 'user', 'content' => 'What is scolta?' ],
				[ 'role' => 'assistant', 'content' => 'Scolta is an AI search tool.' ],
				[ 'role' => 'user', 'content' => 'Tell me more.' ],
			]
		);

		$response = Scolta_Rest_Api::handle_followup( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assert_valid_http_status( $response->get_status(), 'handle_followup' );
	}

	/**
	 * GET /wp-json/scolta/v1/health
	 * Health must return 200 with an array body — no config or filesystem crash.
	 */
	public function test_handle_health_returns_200_with_array_body(): void {
		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status(), 'Health endpoint must always return 200.' );
		$this->assertIsArray( $response->get_data(), 'Health endpoint must return an array body.' );
	}

	/**
	 * GET /wp-json/scolta/v1/build-progress
	 * Returns current build status — must return 200 with array when no build is active.
	 */
	public function test_handle_build_progress_returns_200_with_array_body(): void {
		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/build-progress' );
		$response = Scolta_Rest_Api::handle_build_progress( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status(), 'build-progress must return 200 when idle.' );
		$this->assertIsArray( $response->get_data() );
		$this->assertArrayHasKey( 'status', $response->get_data(), 'build-progress body must contain a status key.' );
	}

	/**
	 * POST /wp-json/scolta/v1/rebuild-now
	 * With no active lock, must schedule a rebuild and return 200 — not crash.
	 */
	public function test_handle_rebuild_now_returns_rest_response(): void {
		// Ensure no stale build lock.
		delete_transient( Scolta_Rebuild_Scheduler::LOCK_KEY );

		$request = new WP_REST_Request( 'POST', '/wp-json/scolta/v1/rebuild-now' );
		$request->set_param( 'force', false );

		$response = Scolta_Rest_Api::handle_rebuild_now( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assert_valid_http_status( $response->get_status(), 'handle_rebuild_now' );
	}

	/**
	 * POST /wp-json/scolta/v1/rebuild-now when a build is already in progress.
	 * Must return 409 Conflict — not crash or silently ignore the lock.
	 */
	public function test_handle_rebuild_now_returns_409_when_locked(): void {
		set_transient( Scolta_Rebuild_Scheduler::LOCK_KEY, time(), 90 );

		$request = new WP_REST_Request( 'POST', '/wp-json/scolta/v1/rebuild-now' );
		$request->set_param( 'force', false );

		$response = Scolta_Rest_Api::handle_rebuild_now( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 409, $response->get_status(), 'rebuild-now must return 409 when a build lock is held.' );

		// Cleanup.
		delete_transient( Scolta_Rebuild_Scheduler::LOCK_KEY );
	}

	// -----------------------------------------------------------------------
	// Helper
	// -----------------------------------------------------------------------

	private function assert_valid_http_status( int $status, string $handler ): void {
		$this->assertGreaterThanOrEqual( 200, $status, "{$handler} returned status below 200." );
		$this->assertLessThan( 600, $status, "{$handler} returned status >= 600 — invalid HTTP status." );
		$this->assertNotEquals( 500, $status, "{$handler} returned 500 — handler crashed." );
	}
}
