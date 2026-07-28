<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the ten search-as-you-type admin fields and their sanitizer.
 *
 * Three things have to hold for a SAYT setting to work on a WordPress site, and
 * each has its own failure mode:
 *
 * 1. It is registered on the settings screen, or a site owner cannot reach it.
 *    A renderer that nothing calls add_settings_field() for is invisible, and
 *    renderer-only coverage passes right through that.
 * 2. sanitize_settings() writes it. That function is a strict
 *    rebuild-from-scratch allowlist registered as the option's
 *    sanitize_callback, so a key it does not write is dropped on every save —
 *    the setting is not merely unvalidated, it is unsaveable.
 * 3. It survives a reload, which is the round trip of 2 back through the
 *    renderer: sanitize, store, render, and see the stored value.
 *
 * Reaching the browser is the fourth link and lives in ShortcodeTest and
 * BrowserConfigParityTest, because that is a property of the localized config
 * rather than of the admin screen.
 *
 * The admin class is only loaded when is_admin() returns true; load it
 * explicitly for testing.
 */
class AdminSaytFieldsTest extends TestCase {

	public static function set_up_before_class(): void {
		if ( ! class_exists( 'Scolta_Admin' ) ) {
			require_once dirname( __DIR__ ) . '/admin/class-scolta-admin.php';
		}
	}

	protected function set_up(): void {
		$GLOBALS['wp_options']              = array();
		$GLOBALS['scolta_settings_sections'] = array();
		$GLOBALS['scolta_settings_fields']   = array();
	}

	protected function tear_down(): void {
		unset( $GLOBALS['scolta_settings_sections'], $GLOBALS['scolta_settings_fields'] );
	}

	/**
	 * The three checkbox settings: key => [render method, default].
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	private function boolKnobs(): array {
		return array(
			'sayt_enabled'         => array( 'render_sayt_enabled_field', true ),
			'sayt_recent_searches' => array( 'render_sayt_recent_searches_field', true ),
			'sayt_expand'          => array( 'render_sayt_expand_field', true ),
		);
	}

	/**
	 * The six integer settings: key => [render method, default, clamp min, clamp max].
	 *
	 * @return array<string, array{0: string, 1: int, 2: int, 3: int}>
	 */
	private function intKnobs(): array {
		return array(
			'sayt_min_chars'          => array( 'render_sayt_min_chars_field', 2, 1, 10 ),
			'sayt_debounce_ms'        => array( 'render_sayt_debounce_field', 150, 0, 2000 ),
			'sayt_max_suggestions'    => array( 'render_sayt_max_suggestions_field', 6, 1, 20 ),
			'sayt_max_recent'         => array( 'render_sayt_max_recent_field', 3, 0, 20 ),
			'sayt_expand_per_minute'  => array( 'render_sayt_expand_per_minute_field', 6, 0, 60 ),
			'sayt_expansion_delay_ms' => array( 'render_sayt_expansion_delay_field', 500, 0, 5000 ),
		);
	}

	/**
	 * All ten keys, in the order the contract documents them.
	 *
	 * @return array<int, string>
	 */
	private function allKeys(): array {
		return array(
			'sayt_enabled',
			'sayt_min_chars',
			'sayt_debounce_ms',
			'sayt_max_suggestions',
			'sayt_recent_searches',
			'sayt_max_recent',
			'sayt_expand',
			'sayt_expand_per_minute',
			'sayt_expansion_delay_ms',
			'sayt_suggestion_action',
		);
	}

	// ------------------------------------------------------------------
	// The settings screen offers the section
	// ------------------------------------------------------------------

	public function test_the_settings_screen_registers_a_search_as_you_type_section(): void {
		Scolta_Admin::register_settings();

		$this->assertArrayHasKey(
			'scolta_sayt_section',
			$GLOBALS['scolta_settings_sections']['scolta'] ?? array(),
			'register_settings() must add a search-as-you-type section to the scolta settings page.'
		);
	}

	public function test_every_sayt_setting_is_registered_as_a_field_in_that_section(): void {
		Scolta_Admin::register_settings();

		$fields = $GLOBALS['scolta_settings_fields']['scolta']['scolta_sayt_section'] ?? array();

		foreach ( $this->allKeys() as $key ) {
			$this->assertArrayHasKey(
				$key,
				$fields,
				"$key must be registered with add_settings_field() or a site owner cannot reach it."
			);
			$this->assertIsCallable(
				$fields[ $key ]['callback'],
				"$key is registered with a callback that cannot be called."
			);
		}

		$this->assertCount(
			10,
			$fields,
			'The section must carry exactly the ten documented settings.'
		);
	}

	public function test_the_section_renders_a_description(): void {
		ob_start();
		Scolta_Admin::render_sayt_section();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="description"', $html );
		$this->assertNotSame( '', trim( wp_strip_all_tags( $html ) ), 'The section description must not be empty.' );
	}

	/**
	 * Rendering the section means calling every registered field callback, which
	 * is what do_settings_sections() does on the real screen.
	 */
	public function test_rendering_the_section_emits_an_input_for_every_setting(): void {
		Scolta_Admin::register_settings();

		$fields = $GLOBALS['scolta_settings_fields']['scolta']['scolta_sayt_section'] ?? array();

		foreach ( $fields as $key => $field ) {
			ob_start();
			call_user_func( $field['callback'] );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString(
				'name="scolta_settings[' . $key . ']"',
				$html,
				"$key must render a control bound to its settings key."
			);
			$this->assertStringContainsString(
				'class="description"',
				$html,
				"$key must explain itself to whoever is deciding what to set it to."
			);
		}
	}

	// ------------------------------------------------------------------
	// Rendering: defaults, saved values
	// ------------------------------------------------------------------

	public function test_int_fields_render_their_documented_default(): void {
		foreach ( $this->intKnobs() as $key => $spec ) {
			list( $method, $default, $min, $max ) = $spec;

			ob_start();
			Scolta_Admin::$method();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'type="number"', $html, "$key must be a number input." );
			$this->assertStringContainsString(
				'value="' . $default . '"',
				$html,
				"$key must render its documented default of $default."
			);
			$this->assertStringContainsString( 'min="' . $min . '"', $html, "$key must bound its input at $min." );
			$this->assertStringContainsString( 'max="' . $max . '"', $html, "$key must bound its input at $max." );
		}
	}

	public function test_bool_fields_render_as_checked_checkboxes_by_default(): void {
		foreach ( $this->boolKnobs() as $key => $spec ) {
			list( $method ) = $spec;

			ob_start();
			Scolta_Admin::$method();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'type="checkbox"', $html, "$key must be a checkbox." );
			$this->assertStringContainsString(
				'checked="checked"',
				$html,
				"$key defaults to on, so an unsaved install must render it checked."
			);
		}
	}

	public function test_the_suggestion_action_renders_a_select_with_both_documented_values(): void {
		ob_start();
		Scolta_Admin::render_sayt_suggestion_action_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<select name="scolta_settings[sayt_suggestion_action]"', $html );
		$this->assertStringContainsString( 'value="navigate"', $html );
		$this->assertStringContainsString( 'value="search"', $html );
		$this->assertMatchesRegularExpression(
			'/value="navigate"\s+selected="selected"/',
			$html,
			'navigate is the default and must be preselected on an unsaved install.'
		);
	}

	/**
	 * An unrecognized stored value must not reach the screen as itself, or a
	 * select with no matching option silently shows the first entry while the
	 * option still holds the junk.
	 */
	public function test_the_suggestion_action_falls_back_to_navigate_on_an_unknown_stored_value(): void {
		update_option( 'scolta_settings', array( 'sayt_suggestion_action' => 'teleport' ) );

		ob_start();
		Scolta_Admin::render_sayt_suggestion_action_field();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'teleport', $html );
		$this->assertMatchesRegularExpression( '/value="navigate"\s+selected="selected"/', $html );
	}

	// ------------------------------------------------------------------
	// Sanitizer: accepts, clamps, defaults
	// ------------------------------------------------------------------

	public function test_sanitize_accepts_valid_values_for_every_key(): void {
		$clean = Scolta_Admin::sanitize_settings(
			array(
				'sayt_enabled'            => '1',
				'sayt_min_chars'          => 3,
				'sayt_debounce_ms'        => 220,
				'sayt_max_suggestions'    => 8,
				'sayt_recent_searches'    => '1',
				'sayt_max_recent'         => 5,
				'sayt_expand'             => '1',
				'sayt_expand_per_minute'  => 12,
				'sayt_expansion_delay_ms' => 750,
				'sayt_suggestion_action'  => 'search',
			)
		);

		$this->assertTrue( $clean['sayt_enabled'] );
		$this->assertSame( 3, $clean['sayt_min_chars'] );
		$this->assertSame( 220, $clean['sayt_debounce_ms'] );
		$this->assertSame( 8, $clean['sayt_max_suggestions'] );
		$this->assertTrue( $clean['sayt_recent_searches'] );
		$this->assertSame( 5, $clean['sayt_max_recent'] );
		$this->assertTrue( $clean['sayt_expand'] );
		$this->assertSame( 12, $clean['sayt_expand_per_minute'] );
		$this->assertSame( 750, $clean['sayt_expansion_delay_ms'] );
		$this->assertSame( 'search', $clean['sayt_suggestion_action'] );
	}

	public function test_sanitize_clamps_every_integer_to_its_bounds(): void {
		$high = array();
		$low  = array();
		foreach ( array_keys( $this->intKnobs() ) as $key ) {
			$high[ $key ] = 999999;
			$low[ $key ]  = -999999;
		}

		$clean_high = Scolta_Admin::sanitize_settings( $high );
		$clean_low  = Scolta_Admin::sanitize_settings( $low );

		foreach ( $this->intKnobs() as $key => $spec ) {
			list( , , $min, $max ) = $spec;
			$this->assertSame( $max, $clean_high[ $key ], "$key must clamp to its maximum of $max." );
			$this->assertSame( $min, $clean_low[ $key ], "$key must clamp to its minimum of $min." );
		}
	}

	public function test_sanitize_falls_back_to_documented_defaults_when_integer_keys_are_missing(): void {
		$clean = Scolta_Admin::sanitize_settings( array() );

		foreach ( $this->intKnobs() as $key => $spec ) {
			$this->assertSame(
				$spec[1],
				$clean[ $key ],
				"$key must fall back to its documented default of {$spec[1]}."
			);
		}
	}

	/**
	 * Zero must survive on the two knobs where it is meaningful: no recent
	 * searches shown, and no AI spent on typing. A truthiness guard eats both.
	 */
	public function test_sanitize_preserves_a_zero_for_the_knobs_where_zero_means_something(): void {
		$clean = Scolta_Admin::sanitize_settings(
			array(
				'sayt_max_recent'        => 0,
				'sayt_expand_per_minute' => 0,
			)
		);

		$this->assertSame( 0, $clean['sayt_max_recent'] );
		$this->assertSame( 0, $clean['sayt_expand_per_minute'] );
	}

	public function test_sanitize_handles_the_checkboxes(): void {
		// An unchecked checkbox posts nothing at all.
		$clean = Scolta_Admin::sanitize_settings( array() );
		foreach ( array_keys( $this->boolKnobs() ) as $key ) {
			$this->assertFalse( $clean[ $key ], "An unchecked $key must save as false." );
		}

		$checked = array();
		foreach ( array_keys( $this->boolKnobs() ) as $key ) {
			$checked[ $key ] = '1';
		}
		$clean = Scolta_Admin::sanitize_settings( $checked );
		foreach ( array_keys( $this->boolKnobs() ) as $key ) {
			$this->assertTrue( $clean[ $key ], "A checked $key must save as true." );
		}
	}

	public function test_sanitize_rejects_an_unknown_suggestion_action(): void {
		$clean = Scolta_Admin::sanitize_settings( array( 'sayt_suggestion_action' => 'teleport' ) );
		$this->assertSame( 'navigate', $clean['sayt_suggestion_action'] );

		$clean = Scolta_Admin::sanitize_settings( array() );
		$this->assertSame( 'navigate', $clean['sayt_suggestion_action'] );
	}

	public function test_sanitize_emits_every_sayt_key(): void {
		$clean = Scolta_Admin::sanitize_settings( array() );

		foreach ( $this->allKeys() as $key ) {
			$this->assertArrayHasKey(
				$key,
				$clean,
				"sanitize_settings() must write $key, or every save silently drops it."
			);
		}
	}

	// ------------------------------------------------------------------
	// Round trip: saved state is what the screen shows on reload
	// ------------------------------------------------------------------

	public function test_saved_settings_are_reflected_when_the_screen_reloads(): void {
		$submitted = array(
			'sayt_enabled'            => '',
			'sayt_min_chars'          => 4,
			'sayt_debounce_ms'        => 300,
			'sayt_max_suggestions'    => 9,
			'sayt_recent_searches'    => '',
			'sayt_max_recent'         => 7,
			'sayt_expand'             => '1',
			'sayt_expand_per_minute'  => 2,
			'sayt_expansion_delay_ms' => 900,
			'sayt_suggestion_action'  => 'search',
		);

		// Save the way WordPress does: through the registered sanitize_callback.
		update_option( 'scolta_settings', Scolta_Admin::sanitize_settings( $submitted ) );

		foreach ( $this->intKnobs() as $key => $spec ) {
			list( $method ) = $spec;
			ob_start();
			Scolta_Admin::$method();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString(
				'value="' . $submitted[ $key ] . '"',
				$html,
				"$key must render the saved value after a reload, not its default."
			);
		}

		// The two cleared checkboxes come back unchecked; the one that was left
		// on comes back checked.
		ob_start();
		Scolta_Admin::render_sayt_enabled_field();
		$enabled_html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'checked="checked"', $enabled_html );

		ob_start();
		Scolta_Admin::render_sayt_recent_searches_field();
		$recent_html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'checked="checked"', $recent_html );

		ob_start();
		Scolta_Admin::render_sayt_expand_field();
		$expand_html = (string) ob_get_clean();
		$this->assertStringContainsString( 'checked="checked"', $expand_html );

		ob_start();
		Scolta_Admin::render_sayt_suggestion_action_field();
		$action_html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/value="search"\s+selected="selected"/', $action_html );
	}
}
