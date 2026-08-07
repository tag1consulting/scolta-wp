<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the facet-index loading admin field
 * (render_facet_mode_field() + its sanitizer).
 *
 * The setting decides whether the browser downloads the facet index at all,
 * which on a large site runs to a megabyte or more. Two properties matter and
 * are asserted separately: the control must round-trip a chosen mode, and it
 * must fail closed to 'eager' for anything else. Failing closed is the one that
 * needs guarding — 'disabled' suppresses the filter sidebar entirely, so a
 * sanitizer that let an arbitrary string through could take a site's facets away
 * on a malformed post with nothing in the UI to show for it.
 *
 * The admin class is only loaded when is_admin() returns true; load it
 * explicitly for testing.
 */
class AdminFacetModeFieldTest extends TestCase {

	public static function set_up_before_class(): void {
		if ( ! class_exists( 'Scolta_Admin' ) ) {
			require_once dirname( __DIR__ ) . '/admin/class-scolta-admin.php';
		}
	}

	protected function set_up(): void {
		$GLOBALS['wp_options'] = array();
	}

	private function renderFacetMode(): string {
		ob_start();
		Scolta_Admin::render_facet_mode_field();
		return (string) ob_get_clean();
	}

	public function test_facet_mode_renders_all_three_options(): void {
		$html = $this->renderFacetMode();
		$this->assertStringContainsString( 'name="scolta_settings[facet_mode]"', $html );
		$this->assertStringContainsString( 'value="eager"', $html );
		$this->assertStringContainsString( 'value="deferred"', $html );
		$this->assertStringContainsString( 'value="disabled"', $html );
	}

	public function test_facet_mode_defaults_to_eager(): void {
		$html = $this->renderFacetMode();
		$this->assertMatchesRegularExpression(
			'/<option value="eager"[^>]*selected/',
			$html
		);
	}

	public function test_facet_mode_renders_saved_value_selected(): void {
		update_option( 'scolta_settings', array( 'facet_mode' => 'deferred' ) );
		$html = $this->renderFacetMode();
		$this->assertMatchesRegularExpression(
			'/<option value="deferred"[^>]*selected/',
			$html
		);
	}

	public function test_facet_mode_renders_eager_selected_for_stored_garbage(): void {
		// A value written by an older or tampered install must not leave the
		// select with nothing chosen.
		update_option( 'scolta_settings', array( 'facet_mode' => 'defered' ) );
		$html = $this->renderFacetMode();
		$this->assertMatchesRegularExpression(
			'/<option value="eager"[^>]*selected/',
			$html
		);
	}

	public function test_sanitize_facet_mode_accepts_every_supported_mode(): void {
		foreach ( array( 'eager', 'deferred', 'disabled' ) as $mode ) {
			$clean = Scolta_Admin::sanitize_settings( array( 'facet_mode' => $mode ) );
			$this->assertSame( $mode, $clean['facet_mode'] );
		}
	}

	public function test_sanitize_facet_mode_falls_back_for_invalid(): void {
		$clean = Scolta_Admin::sanitize_settings( array( 'facet_mode' => 'bogus' ) );
		$this->assertSame( 'eager', $clean['facet_mode'] );
	}

	public function test_sanitize_facet_mode_defaults_when_missing(): void {
		$clean = Scolta_Admin::sanitize_settings( array() );
		$this->assertSame( 'eager', $clean['facet_mode'] );
	}
}
