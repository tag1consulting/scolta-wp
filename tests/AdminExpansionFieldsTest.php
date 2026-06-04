<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the expansion round-robin admin fields
 * (render_expansion_combine_mode_field() / render_expansion_per_term_top_k_field()
 * + their sanitizers).
 *
 * Release-gate coverage: the admin fields must render their saved values and
 * sanitize input so a site can configure the scolta-php round-robin AI-summary
 * candidate selection across query-expansion sub-queries (scolta-php#170).
 *
 * The admin class is only loaded when is_admin() returns true; load it
 * explicitly for testing.
 */
class AdminExpansionFieldsTest extends TestCase {

	public static function set_up_before_class(): void {
		if ( ! class_exists( 'Scolta_Admin' ) ) {
			require_once dirname( __DIR__ ) . '/admin/class-scolta-admin.php';
		}
	}

	protected function set_up(): void {
		$GLOBALS['wp_options'] = array();
	}

	private function renderCombineMode(): string {
		ob_start();
		Scolta_Admin::render_expansion_combine_mode_field();
		return (string) ob_get_clean();
	}

	private function renderPerTermTopK(): string {
		ob_start();
		Scolta_Admin::render_expansion_per_term_top_k_field();
		return (string) ob_get_clean();
	}

	public function test_combine_mode_renders_both_options(): void {
		$html = $this->renderCombineMode();
		$this->assertStringContainsString( 'name="scolta_settings[expansion_combine_mode]"', $html );
		$this->assertStringContainsString( 'value="relevance_union"', $html );
		$this->assertStringContainsString( 'value="round_robin"', $html );
	}

	public function test_combine_mode_defaults_to_relevance_union(): void {
		$html = $this->renderCombineMode();
		// The default option must be the selected one.
		$this->assertMatchesRegularExpression(
			'/<option value="relevance_union"[^>]*selected/',
			$html
		);
	}

	public function test_combine_mode_renders_saved_value_selected(): void {
		update_option( 'scolta_settings', array( 'expansion_combine_mode' => 'round_robin' ) );
		$html = $this->renderCombineMode();
		$this->assertMatchesRegularExpression(
			'/<option value="round_robin"[^>]*selected/',
			$html
		);
	}

	public function test_per_term_top_k_renders_default(): void {
		$html = $this->renderPerTermTopK();
		$this->assertStringContainsString( 'name="scolta_settings[expansion_per_term_top_k]"', $html );
		$this->assertStringContainsString( 'value="3"', $html );
		$this->assertStringContainsString( 'min="1"', $html );
	}

	public function test_per_term_top_k_renders_saved_value(): void {
		update_option( 'scolta_settings', array( 'expansion_per_term_top_k' => 5 ) );
		$html = $this->renderPerTermTopK();
		$this->assertStringContainsString( 'value="5"', $html );
	}

	public function test_sanitize_combine_mode_accepts_round_robin(): void {
		$clean = Scolta_Admin::sanitize_settings( array( 'expansion_combine_mode' => 'round_robin' ) );
		$this->assertSame( 'round_robin', $clean['expansion_combine_mode'] );
	}

	public function test_sanitize_combine_mode_falls_back_for_invalid(): void {
		$clean = Scolta_Admin::sanitize_settings( array( 'expansion_combine_mode' => 'bogus' ) );
		$this->assertSame( 'relevance_union', $clean['expansion_combine_mode'] );
	}

	public function test_sanitize_combine_mode_defaults_when_missing(): void {
		$clean = Scolta_Admin::sanitize_settings( array() );
		$this->assertSame( 'relevance_union', $clean['expansion_combine_mode'] );
	}

	public function test_sanitize_per_term_top_k_casts_to_int(): void {
		$clean = Scolta_Admin::sanitize_settings( array( 'expansion_per_term_top_k' => '7' ) );
		$this->assertSame( 7, $clean['expansion_per_term_top_k'] );
	}

	public function test_sanitize_per_term_top_k_clamps_zero_to_one(): void {
		$clean = Scolta_Admin::sanitize_settings( array( 'expansion_per_term_top_k' => 0 ) );
		$this->assertSame( 1, $clean['expansion_per_term_top_k'] );
	}

	public function test_sanitize_per_term_top_k_clamps_negative_to_one(): void {
		$clean = Scolta_Admin::sanitize_settings( array( 'expansion_per_term_top_k' => -4 ) );
		$this->assertSame( 1, $clean['expansion_per_term_top_k'] );
	}

	public function test_sanitize_per_term_top_k_defaults_when_missing(): void {
		$clean = Scolta_Admin::sanitize_settings( array() );
		$this->assertSame( 3, $clean['expansion_per_term_top_k'] );
	}
}
