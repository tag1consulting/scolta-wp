<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the six specificity / co-occurrence admin fields and their sanitizer.
 *
 * scolta.js reads all six of these scoring keys and scolta-php carries them as
 * typed properties, but WordPress exposed none of them, so every site was pinned
 * to the hardcoded JS fallbacks.
 *
 * The sanitizer coverage is the load-bearing part. sanitize_settings() is a
 * strict rebuild-from-scratch allowlist: it starts from an empty array and
 * returns only what it writes, and it is registered as the option's
 * sanitize_callback, so any key it does not write is dropped on every save,
 * including saves triggered programmatically. A missing sanitizer line would
 * therefore make a setting silently unsaveable rather than merely unvalidated.
 *
 * The admin class is only loaded when is_admin() returns true; load it
 * explicitly for testing.
 */
class AdminSpecificityFieldsTest extends TestCase {

	public static function set_up_before_class(): void {
		if ( ! class_exists( 'Scolta_Admin' ) ) {
			require_once dirname( __DIR__ ) . '/admin/class-scolta-admin.php';
		}
	}

	protected function set_up(): void {
		$GLOBALS['wp_options'] = array();
	}

	/**
	 * The float knobs, their defaults, and their clamp ranges.
	 *
	 * @return array<string, array{0: string, 1: float, 2: float}>
	 */
	private function floatKnobs(): array {
		return array(
			// key => [render method, default, clamp max]
			'specificity_floor'           => array( 'render_specificity_floor_field', 0.15, 1.0 ),
			'specificity_strong_match'    => array( 'render_specificity_strong_match_field', 0.55, 1.0 ),
			'specificity_cooccurrence'    => array( 'render_specificity_cooccurrence_field', 0.9, 5.0 ),
			'specificity_agreement_gate'  => array( 'render_specificity_agreement_gate_field', 0.45, 1.0 ),
			'specificity_agreement_decay' => array( 'render_specificity_agreement_decay_field', 1.0, 5.0 ),
		);
	}

	// ------------------------------------------------------------------
	// Rendering
	// ------------------------------------------------------------------

	public function test_all_float_fields_render_their_input_and_default(): void {
		foreach ( $this->floatKnobs() as $key => $spec ) {
			list( $method, $default ) = $spec;

			ob_start();
			Scolta_Admin::$method();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString(
				'name="scolta_settings[' . $key . ']"',
				$html,
				"$key must render an input bound to its settings key."
			);
			$this->assertStringContainsString(
				'value="' . $default . '"',
				$html,
				"$key must render its documented default of $default."
			);
		}
	}

	public function test_all_float_fields_render_their_saved_value(): void {
		foreach ( $this->floatKnobs() as $key => $spec ) {
			list( $method ) = $spec;

			update_option( 'scolta_settings', array( $key => 0.32 ) );
			ob_start();
			Scolta_Admin::$method();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString(
				'value="0.32"',
				$html,
				"$key must render the saved value, not the default."
			);
		}
	}

	public function test_weighting_field_renders_as_a_checked_checkbox_by_default(): void {
		ob_start();
		Scolta_Admin::render_specificity_weighting_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="scolta_settings[specificity_weighting]"', $html );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'checked="checked"', $html );
	}

	// ------------------------------------------------------------------
	// Sanitizer: accepts, clamps, defaults
	// ------------------------------------------------------------------

	public function test_sanitize_accepts_valid_values(): void {
		$clean = Scolta_Admin::sanitize_settings(
			array(
				'specificity_floor'           => 0.2,
				'specificity_strong_match'    => 0.7,
				'specificity_cooccurrence'    => 1.4,
				'specificity_agreement_gate'  => 0.3,
				'specificity_agreement_decay' => 0.65,
			)
		);

		$this->assertSame( 0.2, $clean['specificity_floor'] );
		$this->assertSame( 0.7, $clean['specificity_strong_match'] );
		$this->assertSame( 1.4, $clean['specificity_cooccurrence'] );
		$this->assertSame( 0.3, $clean['specificity_agreement_gate'] );
		$this->assertSame( 0.65, $clean['specificity_agreement_decay'] );
	}

	public function test_sanitize_clamps_out_of_range_values_to_the_upper_bound(): void {
		$clean = Scolta_Admin::sanitize_settings(
			array(
				'specificity_floor'           => 9.0,
				'specificity_strong_match'    => 9.0,
				'specificity_cooccurrence'    => 99.0,
				'specificity_agreement_gate'  => 9.0,
				'specificity_agreement_decay' => 99.0,
			)
		);

		foreach ( $this->floatKnobs() as $key => $spec ) {
			$this->assertSame(
				$spec[2],
				$clean[ $key ],
				"$key must clamp to its documented maximum of {$spec[2]}."
			);
		}
	}

	public function test_sanitize_clamps_negative_values_to_zero(): void {
		$clean = Scolta_Admin::sanitize_settings(
			array(
				'specificity_floor'           => -1.0,
				'specificity_strong_match'    => -1.0,
				'specificity_cooccurrence'    => -1.0,
				'specificity_agreement_gate'  => -1.0,
				'specificity_agreement_decay' => -1.0,
			)
		);

		foreach ( array_keys( $this->floatKnobs() ) as $key ) {
			$this->assertSame( 0.0, $clean[ $key ], "$key must clamp a negative input to 0." );
		}
	}

	public function test_sanitize_falls_back_to_defaults_when_keys_are_missing(): void {
		$clean = Scolta_Admin::sanitize_settings( array() );

		foreach ( $this->floatKnobs() as $key => $spec ) {
			$this->assertSame(
				$spec[1],
				$clean[ $key ],
				"$key must fall back to its documented default of {$spec[1]}."
			);
		}
	}

	/**
	 * Zero must survive the sanitizer, since it is the documented way to restore
	 * the prior maximum-only merge. A truthiness-based guard would swallow it.
	 */
	public function test_sanitize_preserves_a_zero_cooccurrence_bonus(): void {
		$clean = Scolta_Admin::sanitize_settings( array( 'specificity_cooccurrence' => 0 ) );
		$this->assertSame( 0.0, $clean['specificity_cooccurrence'] );
	}

	public function test_sanitize_handles_the_weighting_checkbox(): void {
		// An unchecked checkbox posts nothing at all.
		$clean = Scolta_Admin::sanitize_settings( array() );
		$this->assertFalse( $clean['specificity_weighting'] );

		$clean = Scolta_Admin::sanitize_settings( array( 'specificity_weighting' => '1' ) );
		$this->assertTrue( $clean['specificity_weighting'] );
	}

	/**
	 * All six keys must be written by the sanitizer, or they are dropped on save.
	 */
	public function test_sanitize_emits_every_specificity_key(): void {
		$clean = Scolta_Admin::sanitize_settings( array() );

		$expected = array_merge(
			array( 'specificity_weighting' ),
			array_keys( $this->floatKnobs() )
		);
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey(
				$key,
				$clean,
				"sanitize_settings() must write $key, or every save silently drops it."
			);
		}
	}
}
