<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the health endpoint's index detail enrichment.
 *
 * Covers the fragment count, last_build timestamp, and integrity fields
 * added by handle_health() after calling HealthChecker::check().
 */
class HealthIndexDetailTest extends TestCase {

	private string $tmp_dir = '';

	protected function set_up(): void {
		$GLOBALS['wp_options'] = [];

		// Each test gets its own temp directory for index fixtures.
		$this->tmp_dir = sys_get_temp_dir() . '/scolta_health_test_' . uniqid();
		mkdir( $this->tmp_dir, 0755, true );
	}

	protected function tear_down(): void {
		// Clean up temp directory tree after each test.
		if ( $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			$this->rmdir_recursive( $this->tmp_dir );
		}
	}

	// -------------------------------------------------------------------
	// index key is always present
	// -------------------------------------------------------------------

	public function test_response_always_has_index_key(): void {
		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'index', $data, 'health response must always include an index key' );
	}

	// -------------------------------------------------------------------
	// No index on disk — built = false
	// -------------------------------------------------------------------

	public function test_index_built_false_when_no_index(): void {
		// Point output_dir at an empty temp directory — no pagefind.js present.
		update_option( 'scolta_settings', array( 'output_dir' => $this->tmp_dir ) );

		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$index = $response->get_data()['index'];
		$this->assertFalse( $index['built'], 'index.built must be false when no pagefind index exists' );
	}

	public function test_no_extra_index_fields_when_not_built(): void {
		update_option( 'scolta_settings', array( 'output_dir' => $this->tmp_dir ) );

		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$index = $response->get_data()['index'];
		$this->assertArrayNotHasKey( 'fragments', $index );
		$this->assertArrayNotHasKey( 'last_build', $index );
		$this->assertArrayNotHasKey( 'integrity', $index );
	}

	// -------------------------------------------------------------------
	// Index exists — detail fields are present and correct
	// -------------------------------------------------------------------

	public function test_index_built_true_when_index_exists(): void {
		$this->create_valid_index();
		update_option( 'scolta_settings', array( 'output_dir' => $this->tmp_dir ) );

		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$index = $response->get_data()['index'];
		$this->assertTrue( $index['built'], 'index.built must be true when pagefind.js exists' );
	}

	public function test_fragment_count_matches_files(): void {
		$this->create_valid_index( fragment_count: 5 );
		update_option( 'scolta_settings', array( 'output_dir' => $this->tmp_dir ) );

		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$this->assertSame( 5, $response->get_data()['index']['fragments'] );
	}

	public function test_last_build_is_iso8601_string(): void {
		$this->create_valid_index();
		update_option( 'scolta_settings', array( 'output_dir' => $this->tmp_dir ) );

		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$last_build = $response->get_data()['index']['last_build'];
		$this->assertNotNull( $last_build, 'last_build must not be null when pagefind.js exists' );
		// ISO 8601 format produced by date('c') — contains 'T' separator.
		$this->assertStringContainsString( 'T', $last_build, 'last_build must be an ISO 8601 datetime string' );
	}

	public function test_integrity_valid_when_index_is_healthy(): void {
		$this->create_valid_index();
		update_option( 'scolta_settings', array( 'output_dir' => $this->tmp_dir ) );

		$request   = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response  = Scolta_Rest_Api::handle_health( $request );
		$integrity = $response->get_data()['index']['integrity'];

		$this->assertTrue( $integrity['valid'], 'integrity.valid must be true for a healthy index' );
		$this->assertEmpty( $integrity['issues'], 'integrity.issues must be empty for a healthy index' );
	}

	// -------------------------------------------------------------------
	// Integrity failures
	// -------------------------------------------------------------------

	public function test_integrity_invalid_when_no_fragments(): void {
		// Create pagefind.js but no fragment directory.
		mkdir( $this->tmp_dir . '/pagefind', 0755, true );
		file_put_contents( $this->tmp_dir . '/pagefind/pagefind.js', 'pagefind-stub' );

		update_option( 'scolta_settings', array( 'output_dir' => $this->tmp_dir ) );

		$request   = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response  = Scolta_Rest_Api::handle_health( $request );
		$integrity = $response->get_data()['index']['integrity'];

		$this->assertFalse( $integrity['valid'], 'integrity must be invalid when no fragments exist' );
		$this->assertContains( 'No fragment files found', $integrity['issues'] );
	}

	public function test_integrity_invalid_when_pagefind_js_empty(): void {
		mkdir( $this->tmp_dir . '/pagefind/fragment', 0755, true );
		file_put_contents( $this->tmp_dir . '/pagefind/pagefind.js', '' );  // empty JS
		file_put_contents( $this->tmp_dir . '/pagefind/fragment/en_1.pf_fragment', 'data' );

		update_option( 'scolta_settings', array( 'output_dir' => $this->tmp_dir ) );

		$request   = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response  = Scolta_Rest_Api::handle_health( $request );
		$integrity = $response->get_data()['index']['integrity'];

		$this->assertFalse( $integrity['valid'], 'integrity must be invalid when pagefind.js is empty' );
		$this->assertContains( 'pagefind.js is empty or unreadable', $integrity['issues'] );
	}

	public function test_status_degraded_when_integrity_invalid(): void {
		// pagefind.js exists but no fragments — triggers integrity failure.
		mkdir( $this->tmp_dir . '/pagefind', 0755, true );
		file_put_contents( $this->tmp_dir . '/pagefind/pagefind.js', 'pagefind-stub' );

		update_option( 'scolta_settings', array( 'output_dir' => $this->tmp_dir ) );

		$request  = new WP_REST_Request( 'GET', '/wp-json/scolta/v1/health' );
		$response = Scolta_Rest_Api::handle_health( $request );

		$this->assertSame( 'degraded', $response->get_data()['status'],
			'overall status must be degraded when index integrity fails' );
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/**
	 * Create a minimal valid Pagefind index in $this->tmp_dir.
	 *
	 * @param int $fragment_count Number of fragment files to create.
	 */
	private function create_valid_index( int $fragment_count = 3 ): void {
		mkdir( $this->tmp_dir . '/pagefind/fragment', 0755, true );
		file_put_contents( $this->tmp_dir . '/pagefind/pagefind.js', 'pagefind-stub-content' );

		for ( $i = 0; $i < $fragment_count; $i++ ) {
			file_put_contents(
				$this->tmp_dir . '/pagefind/fragment/en_' . $i . '.pf_fragment',
				'fragment-data-' . $i
			);
		}
	}

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
