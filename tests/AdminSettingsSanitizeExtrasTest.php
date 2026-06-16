<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Regression tests for sanitize_settings() hardening: ai_base_url URL
 * validation and recency_curve shape validation.
 *
 * (Prompt-field sanitization is covered separately in AdminSanitizeTest.)
 */
class AdminSettingsSanitizeExtrasTest extends TestCase {

	protected function set_up(): void {
		$GLOBALS['wp_options'] = [];
	}

	private function sanitize( array $input ): array {
		return Scolta_Admin::sanitize_settings( $input );
	}

	// -------------------------------------------------------------------
	// ai_base_url — must be an http(s) URL
	// -------------------------------------------------------------------

	public function test_base_url_https_is_kept(): void {
		$clean = $this->sanitize( array( 'ai_base_url' => 'https://api.example.com/v1' ) );
		$this->assertSame( 'https://api.example.com/v1', $clean['ai_base_url'] );
	}

	public function test_base_url_http_is_kept(): void {
		$clean = $this->sanitize( array( 'ai_base_url' => 'http://localhost:11434/v1' ) );
		$this->assertSame( 'http://localhost:11434/v1', $clean['ai_base_url'] );
	}

	public function test_base_url_javascript_scheme_is_dropped(): void {
		$clean = $this->sanitize( array( 'ai_base_url' => 'javascript:alert(1)' ) );
		$this->assertSame( '', $clean['ai_base_url'], 'non-http(s) schemes must not survive sanitization' );
	}

	public function test_base_url_ftp_scheme_is_dropped(): void {
		$clean = $this->sanitize( array( 'ai_base_url' => 'ftp://example.com/v1' ) );
		$this->assertSame( '', $clean['ai_base_url'], 'ftp is not a valid AI endpoint scheme' );
	}

	public function test_base_url_empty_stays_empty(): void {
		$clean = $this->sanitize( array( 'ai_base_url' => '' ) );
		$this->assertSame( '', $clean['ai_base_url'] );
	}

	// -------------------------------------------------------------------
	// recency_curve — must decode to [[days, boost], ...]
	// -------------------------------------------------------------------

	public function test_recency_curve_valid_pairs_are_kept_and_typed(): void {
		$clean = $this->sanitize( array( 'recency_curve' => '[[30, 1.5], [365, 0.5]]' ) );
		$this->assertSame( array( array( 30, 1.5 ), array( 365, 0.5 ) ), $clean['recency_curve'] );
	}

	public function test_recency_curve_numeric_strings_are_cast(): void {
		$clean = $this->sanitize( array( 'recency_curve' => '[["30", "1.5"]]' ) );
		$this->assertSame( array( array( 30, 1.5 ) ), $clean['recency_curve'] );
	}

	public function test_recency_curve_object_shape_is_rejected(): void {
		$clean = $this->sanitize( array( 'recency_curve' => '{"days": 30, "boost": 1.5}' ) );
		$this->assertSame( array(), $clean['recency_curve'] );
	}

	public function test_recency_curve_ragged_row_is_rejected(): void {
		$clean = $this->sanitize( array( 'recency_curve' => '[[30, 1.5], [365]]' ) );
		$this->assertSame( array(), $clean['recency_curve'], 'one malformed row must reject the whole curve' );
	}

	public function test_recency_curve_non_numeric_entry_is_rejected(): void {
		$clean = $this->sanitize( array( 'recency_curve' => '[[30, "<script>"]]' ) );
		$this->assertSame( array(), $clean['recency_curve'] );
	}

	public function test_recency_curve_invalid_json_is_rejected(): void {
		$clean = $this->sanitize( array( 'recency_curve' => 'not json' ) );
		$this->assertSame( array(), $clean['recency_curve'] );
	}

	public function test_recency_curve_array_input_is_rejected(): void {
		// A crafted form post can submit recency_curve as an array.
		$clean = $this->sanitize( array( 'recency_curve' => array( 'x' ) ) );
		$this->assertSame( array(), $clean['recency_curve'] );
	}
}
