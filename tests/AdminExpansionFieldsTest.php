<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the expansion combine-mode admin field
 * (render_expansion_combine_mode_field() + its sanitizer).
 *
 * Release-gate coverage: the combine-mode field must render its saved value and
 * sanitize input so a site can configure the scolta-php round-robin AI-summary
 * candidate selection across query-expansion sub-queries (scolta-php#170). The
 * per-sub-query top-K is locked at 3 inside scolta-php and is no longer a
 * configurable WordPress setting (scolta-php#180), so its admin field and
 * sanitizer key are gone.
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

	/**
	 * The per-term top-K knob is locked at 3 inside scolta-php and is no longer a
	 * WordPress setting: there is no render method and the sanitizer must not emit
	 * the key.
	 */
	public function test_per_term_top_k_field_is_removed(): void {
		$this->assertFalse(
			method_exists( 'Scolta_Admin', 'render_expansion_per_term_top_k_field' ),
			'render_expansion_per_term_top_k_field() should be removed — K is locked at 3 in scolta-php.'
		);
	}

	public function test_sanitize_does_not_emit_per_term_top_k(): void {
		$clean = Scolta_Admin::sanitize_settings( array( 'expansion_per_term_top_k' => '7' ) );
		$this->assertArrayNotHasKey( 'expansion_per_term_top_k', $clean );
	}
}
