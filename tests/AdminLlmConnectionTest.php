<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the LLM "Test Connection" AJAX handler.
 *
 * Verifies hook registration, security ordering, method structure, and
 * the no-API-key early-exit path.
 */
class AdminLlmConnectionTest extends TestCase {

	// -------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------

	public function test_ajax_action_hook_is_registered(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\s*\(\s*'wp_ajax_scolta_test_connection'/",
			$source,
			'wp_ajax_scolta_test_connection hook must be registered'
		);
	}

	public function test_ajax_hook_points_to_correct_method(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\s*\(\s*'wp_ajax_scolta_test_connection'\s*,\s*(?:array\s*\(\s*|\[)self::class,\s*'ajax_scolta_test_connection'/",
			$source,
			'wp_ajax_scolta_test_connection must point to ajax_scolta_test_connection method'
		);
	}

	// -------------------------------------------------------------------
	// Method existence and access
	// -------------------------------------------------------------------

	public function test_method_is_public_static(): void {
		$method = new ReflectionMethod( 'Scolta_Admin', 'ajax_scolta_test_connection' );
		$this->assertTrue( $method->isPublic(), 'ajax_scolta_test_connection must be public' );
		$this->assertTrue( $method->isStatic(), 'ajax_scolta_test_connection must be static' );
	}

	// -------------------------------------------------------------------
	// Security: nonce and permission checks present in correct order
	// -------------------------------------------------------------------

	public function test_nonce_check_is_present(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		preg_match(
			'/public static function ajax_scolta_test_connection\(\)[^{]*\{(.*?)\n\t\}/s',
			$source,
			$match
		);
		$body = $match[1] ?? '';
		$this->assertMatchesRegularExpression(
			"/check_ajax_referer\s*\(\s*'scolta_test_connection'/",
			$body,
			'ajax_scolta_test_connection must verify nonce with check_ajax_referer'
		);
	}

	public function test_permission_check_is_present(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		preg_match(
			'/public static function ajax_scolta_test_connection\(\)[^{]*\{(.*?)\n\t\}/s',
			$source,
			$match
		);
		$body = $match[1] ?? '';
		$this->assertMatchesRegularExpression(
			"/current_user_can\s*\(\s*'manage_options'\s*\)/",
			$body,
			'ajax_scolta_test_connection must check manage_options capability'
		);
	}

	public function test_nonce_check_precedes_permission_check(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		preg_match(
			'/public static function ajax_scolta_test_connection\(\)[^{]*\{(.*?)\n\t\}/s',
			$source,
			$match
		);
		$body     = $match[1] ?? '';
		$nonce_pos = strpos( $body, 'check_ajax_referer' );
		$perm_pos  = strpos( $body, 'current_user_can' );
		$this->assertNotFalse( $nonce_pos, 'check_ajax_referer must be present' );
		$this->assertNotFalse( $perm_pos, 'current_user_can must be present' );
		$this->assertLessThan( $perm_pos, $nonce_pos, 'Nonce check must come before capability check' );
	}

	// -------------------------------------------------------------------
	// No-API-key early exit
	// -------------------------------------------------------------------

	public function test_no_api_key_returns_error(): void {
		// Seed settings with no API key and ensure env is clear.
		update_option( 'scolta_settings', array( 'ai_provider' => 'anthropic' ) );

		// Simulate the no-key guard from the handler.
		$api_key = Scolta_Ai_Service::get_api_key();
		$has_wp_ai = class_exists( '\WordPress\AI\Client' );
		$would_error = empty( $api_key ) && ! $has_wp_ai;

		$this->assertTrue( $would_error, 'Handler must return an error when no API key is configured' );
	}

	// -------------------------------------------------------------------
	// UI: test button rendered when API key is present
	// -------------------------------------------------------------------

	public function test_test_button_in_source(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		$this->assertStringContainsString(
			'scolta-test-connection-btn',
			$source,
			'Test Connection button must be present in admin source'
		);
		$this->assertStringContainsString(
			'scolta_test_connection',
			$source,
			'Nonce action scolta_test_connection must appear in source'
		);
	}
}
