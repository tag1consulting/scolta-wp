<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the dashboard widget AI status indicator.
 *
 * The widget must detect API keys from all configured sources
 * (env var, wp-config constant, database, WP AI SDK) — not just the database.
 */
class AdminDashboardWidgetTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['wp_options'] = array();
		putenv( 'SCOLTA_API_KEY' );
		unset( $_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY'] );
	}

	public function tear_down(): void {
		putenv( 'SCOLTA_API_KEY' );
		unset( $_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY'] );
		$GLOBALS['wp_options'] = array();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Rendering: AI status line
	// -------------------------------------------------------------------------

	/**
	 * When SCOLTA_API_KEY env var is set, dashboard must show "Configured".
	 *
	 * Pre-fix: widget only checks $settings['ai_api_key'] and the WP AI Client SDK class.
	 * An env-var-configured key is invisible to the check → "Not configured" → FAIL.
	 * Post-fix: uses Scolta_Ai_Service::get_api_key_source() which checks the env var → PASS.
	 */
	public function test_dashboard_shows_configured_when_env_var_set(): void {
		putenv( 'SCOLTA_API_KEY=test-env-key' );

		ob_start();
		Scolta_Admin::render_dashboard_widget();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'Configured',
			$output,
			'Dashboard must show AI: Configured when SCOLTA_API_KEY env var is set'
		);
		$this->assertStringNotContainsString(
			'Not configured',
			$output,
			'Dashboard must not show "Not configured" when env var is set'
		);
	}

	/**
	 * Source-parse: the dashboard rendering code must use get_api_key_source()
	 * rather than checking $settings['ai_api_key'] directly.
	 *
	 * Pre-fix: contains $settings['ai_api_key'] inline check → FAIL.
	 * Post-fix: uses Scolta_Ai_Service::get_api_key_source() → PASS.
	 */
	public function test_dashboard_uses_get_api_key_source_not_inline_settings(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );

		// Isolate the render_dashboard_widget() method body.
		preg_match( '/function render_dashboard_widget\(\)(.*?)^\t}/ms', $source, $matches );
		$fn_body = $matches[1] ?? $source;

		$this->assertStringContainsString(
			'get_api_key_source',
			$fn_body,
			'render_dashboard_widget() must delegate AI detection to Scolta_Ai_Service::get_api_key_source()'
		);

		$this->assertDoesNotMatchRegularExpression(
			'/\$settings\s*\[\s*[\'"]ai_api_key[\'"]\s*\]/',
			$fn_body,
			'render_dashboard_widget() must not check $settings[\'ai_api_key\'] directly — use get_api_key_source()'
		);
	}

	/**
	 * When no API key is configured (no env, no constant, no DB, no SDK),
	 * dashboard must show "Not configured".
	 */
	public function test_dashboard_shows_not_configured_when_no_key(): void {
		// No env var (cleared in set_up), no constant, no DB key, no WP AI Client.
		$this->assertFalse(
			defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '',
			'SCOLTA_API_KEY constant must not be set for this test to be valid'
		);

		update_option( 'scolta_settings', array() );

		ob_start();
		Scolta_Admin::render_dashboard_widget();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'Not configured',
			$output,
			'Dashboard must show "Not configured" when no API key is available from any source'
		);
	}

	/**
	 * Source-parse: the AI detection context in render_dashboard_widget()
	 * must not contain a direct $settings['ai_api_key'] check.
	 *
	 * Prevents reintroduction of the inline DB-only check that missed env vars and constants.
	 */
	public function test_dashboard_does_not_check_settings_ai_api_key_directly(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );

		preg_match( '/function render_dashboard_widget\(\)(.*?)^\t}/ms', $source, $matches );
		$fn_body = $matches[1] ?? $source;

		$this->assertDoesNotMatchRegularExpression(
			'/empty\s*\(\s*\$settings\s*\[\s*[\'"]ai_api_key[\'"]\s*\]\s*\)/',
			$fn_body,
			'render_dashboard_widget() must not use empty($settings[\'ai_api_key\']) for AI detection'
		);
	}
}
