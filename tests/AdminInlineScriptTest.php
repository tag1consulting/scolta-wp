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
}
