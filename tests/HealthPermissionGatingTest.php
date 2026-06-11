<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the /health endpoint's capability-based detail gating.
 *
 * Anonymous requesters get only the overall status (enough for uptime
 * monitoring); the full diagnostic payload requires manage_options.
 */
class HealthPermissionGatingTest extends TestCase {

	private string $tmp_dir = '';

	protected function set_up(): void {
		$GLOBALS['wp_options'] = [];

		$this->tmp_dir = sys_get_temp_dir() . '/scolta_health_perm_test_' . uniqid();
		mkdir( $this->tmp_dir, 0755, true );
	}

	protected function tear_down(): void {
		unset( $GLOBALS['scolta_test_user_can'] );

		if ( $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			$this->rmdir_recursive( $this->tmp_dir );
		}
	}

	// -------------------------------------------------------------------
	// Anonymous: status only
	// -------------------------------------------------------------------

	public function test_anonymous_response_contains_exactly_status(): void {
		$GLOBALS['scolta_test_user_can'] = false;

		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$data = $response->get_data();
		$this->assertSame(
			array( 'status' ),
			array_keys( $data ),
			'anonymous /health body must contain the status key and nothing else'
		);
	}

	public function test_anonymous_response_is_http_200(): void {
		$GLOBALS['scolta_test_user_can'] = false;

		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$this->assertSame( 200, $response->get_status(), 'gating must not change the HTTP status' );
	}

	public function test_anonymous_status_still_reflects_index_integrity(): void {
		// pagefind.js with no fragments → integrity failure → degraded.
		// The detail gate must sit AFTER the enrichment so monitors see the
		// real status even though they don't see the integrity breakdown.
		mkdir( $this->tmp_dir . '/pagefind', 0755, true );
		file_put_contents( $this->tmp_dir . '/pagefind/pagefind.js', 'pagefind-stub' );
		update_option( 'scolta_settings', array( 'output_dir' => $this->tmp_dir ) );

		$GLOBALS['scolta_test_user_can'] = false;

		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$this->assertSame( 'degraded', $response->get_data()['status'] );
	}

	// -------------------------------------------------------------------
	// manage_options: full detail
	// -------------------------------------------------------------------

	public function test_admin_response_keeps_full_detail(): void {
		// The bootstrap stub defaults current_user_can() to true.
		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'status', $data );
		$this->assertArrayHasKey( 'index_exists', $data );
		$this->assertArrayHasKey( 'index', $data );
		$this->assertGreaterThan(
			1,
			count( $data ),
			'manage_options requesters must keep the full diagnostic payload'
		);
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	private function rmdir_recursive( string $dir ): void {
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rmdir_recursive( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
