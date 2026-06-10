<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Verify that the Scolta admin class uses wp_add_inline_script() instead of
 * echoing raw <script> tags, as required by WordPress.org plugin review.
 */
class AdminInlineScriptTest extends TestCase {

	// -------------------------------------------------------------------
	// No raw <script> tags in field renderer output
	// -------------------------------------------------------------------

	public function test_no_inline_script_tags_in_preset_field(): void {
		ob_start();
		Scolta_Admin::render_preset_field();
		$output = ob_get_clean();
		$this->assertStringNotContainsString( '<script', $output, 'render_preset_field must not echo inline <script> tags' );
	}

	public function test_no_inline_script_tags_in_api_key_status_field_database(): void {
		update_option( 'scolta_settings', array( 'ai_provider' => 'anthropic', 'ai_api_key' => 'sk-test-db-key' ) );
		ob_start();
		Scolta_Admin::render_api_key_status_field();
		$output = ob_get_clean();
		update_option( 'scolta_settings', array() );
		$this->assertStringNotContainsString( '<script', $output, 'render_api_key_status_field (database source) must not echo inline <script> tags' );
	}

	// -------------------------------------------------------------------
	// admin_enqueue_scripts hook is registered
	// -------------------------------------------------------------------

	public function test_admin_enqueue_scripts_hook_registered(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\s*\(\s*'admin_enqueue_scripts'/",
			$source,
			'admin_enqueue_scripts action must be registered in init()'
		);
	}

	public function test_admin_enqueue_scripts_points_to_correct_method(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\s*\(\s*'admin_enqueue_scripts'\s*,\s*(?:array\s*\(\s*|\[)self::class,\s*'enqueue_admin_scripts'/",
			$source,
			'admin_enqueue_scripts must point to enqueue_admin_scripts method'
		);
	}

	// -------------------------------------------------------------------
	// enqueue_admin_scripts() only fires on the Scolta settings page
	// -------------------------------------------------------------------

	public function test_enqueue_skipped_on_other_pages(): void {
		$GLOBALS['scolta_enqueued_scripts'] = array();
		Scolta_Admin::enqueue_admin_scripts( 'some-other-page' );
		$this->assertNotContains( 'scolta-admin', $GLOBALS['scolta_enqueued_scripts'] );
		unset( $GLOBALS['scolta_enqueued_scripts'] );
	}

	public function test_enqueue_fires_on_settings_page(): void {
		$GLOBALS['scolta_enqueued_scripts'] = array();
		Scolta_Admin::enqueue_admin_scripts( 'settings_page_scolta' );
		$this->assertContains( 'scolta-admin', $GLOBALS['scolta_enqueued_scripts'] );
		unset( $GLOBALS['scolta_enqueued_scripts'] );
	}

	// -------------------------------------------------------------------
	// enqueue_admin_scripts() localizes the required strings
	// -------------------------------------------------------------------

	public function test_localize_script_provides_required_keys(): void {
		$GLOBALS['scolta_localized_scripts'] = array();
		Scolta_Admin::enqueue_admin_scripts( 'settings_page_scolta' );
		$this->assertArrayHasKey( 'scolta-admin', $GLOBALS['scolta_localized_scripts'] );
		$l10n = $GLOBALS['scolta_localized_scripts']['scolta-admin'];
		foreach ( array( 'confirmRemoveDbKey', 'testing', 'connected', 'failed', 'networkError' ) as $key ) {
			$this->assertArrayHasKey( $key, $l10n, "scoltaAdminL10n must include key: $key" );
			$this->assertNotEmpty( $l10n[ $key ], "scoltaAdminL10n.$key must not be empty" );
		}
		unset( $GLOBALS['scolta_localized_scripts'] );
	}

	// -------------------------------------------------------------------
	// enqueue_admin_scripts() attaches inline scripts via wp_add_inline_script
	// -------------------------------------------------------------------

	public function test_inline_scripts_registered_for_preset_toggle(): void {
		$GLOBALS['scolta_inline_scripts'] = array();
		Scolta_Admin::enqueue_admin_scripts( 'settings_page_scolta' );
		$scripts = implode( "\n", $GLOBALS['scolta_inline_scripts']['scolta-admin'] ?? array() );
		$this->assertStringContainsString( 'scolta_preset', $scripts, 'Preset toggle script must be registered via wp_add_inline_script' );
		unset( $GLOBALS['scolta_inline_scripts'] );
	}

	public function test_inline_scripts_registered_for_remove_db_key(): void {
		$GLOBALS['scolta_inline_scripts'] = array();
		Scolta_Admin::enqueue_admin_scripts( 'settings_page_scolta' );
		$scripts = implode( "\n", $GLOBALS['scolta_inline_scripts']['scolta-admin'] ?? array() );
		$this->assertStringContainsString( 'scolta-remove-db-key', $scripts, 'Remove DB key script must be registered via wp_add_inline_script' );
		unset( $GLOBALS['scolta_inline_scripts'] );
	}

	public function test_inline_scripts_registered_for_test_connection(): void {
		$GLOBALS['scolta_inline_scripts'] = array();
		Scolta_Admin::enqueue_admin_scripts( 'settings_page_scolta' );
		$scripts = implode( "\n", $GLOBALS['scolta_inline_scripts']['scolta-admin'] ?? array() );
		$this->assertStringContainsString( 'scolta-test-connection-btn', $scripts, 'Test connection script must be registered via wp_add_inline_script' );
		unset( $GLOBALS['scolta_inline_scripts'] );
	}

	public function test_inline_scripts_registered_for_prompt_reset(): void {
		$GLOBALS['scolta_inline_scripts'] = array();
		Scolta_Admin::enqueue_admin_scripts( 'settings_page_scolta' );
		$scripts = implode( "\n", $GLOBALS['scolta_inline_scripts']['scolta-admin'] ?? array() );
		$this->assertStringContainsString( 'scolta-prompt-reset', $scripts, 'Prompt reset handler must be registered via wp_add_inline_script' );
		unset( $GLOBALS['scolta_inline_scripts'] );
	}

	// -------------------------------------------------------------------
	// No inline event handlers anywhere in admin source — onclick=""
	// survived the rc4 inline-script cleanup because only <script> tags
	// were asserted, and shipped two null derefs in the reset button.
	// -------------------------------------------------------------------

	public function test_no_onclick_attributes_in_admin_source(): void {
		foreach ( glob( dirname( __DIR__ ) . '/admin/*.php' ) as $file ) {
			$this->assertStringNotContainsString(
				'onclick=',
				file_get_contents( $file ),
				basename( $file ) . ' must not use inline onclick handlers — attach listeners via wp_add_inline_script()'
			);
		}
	}

	public function test_no_innerhtml_assignment_in_admin_source(): void {
		foreach ( glob( dirname( __DIR__ ) . '/admin/*.php' ) as $file ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\.innerHTML\s*=/',
				file_get_contents( $file ),
				basename( $file ) . ' must not assign .innerHTML — variable parts go through textContent/createTextNode'
			);
		}
	}

	// -------------------------------------------------------------------
	// Reset-prompt button wiring: badge class, textarea id, data target
	// -------------------------------------------------------------------

	public function test_customized_prompt_field_has_working_reset_wiring(): void {
		update_option( 'scolta_settings', array( 'prompt_expand_query' => 'My custom prompt text.' ) );

		ob_start();
		Scolta_Admin::render_prompt_expand_field();
		$output = ob_get_clean();
		update_option( 'scolta_settings', array() );

		$this->assertStringContainsString( 'class="scolta-badge"', $output, 'badge span must carry the scolta-badge class the JS targets' );
		$this->assertStringContainsString( 'scolta-prompt-reset', $output, 'reset button must carry the scolta-prompt-reset class' );
		$this->assertStringContainsString( 'data-textarea-id="scolta-prompt-prompt_expand_query"', $output, 'reset button must reference its textarea by id' );
		$this->assertStringContainsString( 'id="scolta-prompt-prompt_expand_query"', $output, 'textarea must have the id the reset button references' );
		$this->assertStringNotContainsString( 'onclick', $output );
	}

	public function test_default_prompt_field_has_badge_but_no_reset_button(): void {
		update_option( 'scolta_settings', array() );

		ob_start();
		Scolta_Admin::render_prompt_expand_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="scolta-badge"', $output );
		$this->assertStringNotContainsString( 'scolta-prompt-reset', $output, 'no reset button when the default prompt is active' );
	}
}
