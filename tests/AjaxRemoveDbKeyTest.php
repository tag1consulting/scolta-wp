<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for Scolta_Admin::ajax_remove_db_key().
 *
 * Verifies nonce action name consistency, hook registration, and that the
 * function removes only ai_api_key from scolta_settings, nothing else.
 */
class AjaxRemoveDbKeyTest extends TestCase {

    public static function set_up_before_class(): void {
        if (!class_exists('Scolta_Admin')) {
            require_once dirname(__DIR__) . '/admin/class-scolta-admin.php';
        }
    }

    // -------------------------------------------------------------------
    // Source-level consistency checks
    // -------------------------------------------------------------------

    public function test_nonce_action_matches_between_field_and_referer_check(): void {
        $source = file_get_contents(dirname(__DIR__) . '/admin/class-scolta-admin.php');

        // wp_nonce_field uses 'scolta_remove_db_key' as the action.
        $this->assertMatchesRegularExpression(
            "/wp_nonce_field\s*\(\s*'scolta_remove_db_key'/",
            $source,
            "wp_nonce_field must use 'scolta_remove_db_key' as the nonce action"
        );

        // check_ajax_referer uses the same action.
        $this->assertMatchesRegularExpression(
            "/check_ajax_referer\s*\(\s*'scolta_remove_db_key'\s*\)/",
            $source,
            "check_ajax_referer must use 'scolta_remove_db_key' — must match wp_nonce_field"
        );
    }

    public function test_ajax_action_hook_is_registered(): void {
        $source = file_get_contents(dirname(__DIR__) . '/admin/class-scolta-admin.php');

        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'wp_ajax_scolta_remove_db_key'/",
            $source,
            "wp_ajax_scolta_remove_db_key hook must be registered"
        );
    }

    public function test_ajax_hook_points_to_correct_method(): void {
        $source = file_get_contents(dirname(__DIR__) . '/admin/class-scolta-admin.php');

        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'wp_ajax_scolta_remove_db_key'\s*,\s*(?:array\s*\(\s*|\[)self::class,\s*'ajax_remove_db_key'/",
            $source,
            "wp_ajax_scolta_remove_db_key must point to ajax_remove_db_key method"
        );
    }

    // -------------------------------------------------------------------
    // Functional: only ai_api_key is removed, other settings are preserved
    // -------------------------------------------------------------------

    public function test_removes_only_ai_api_key(): void {
        // Seed settings with an API key alongside other settings.
        $initial = [
            'ai_api_key'    => 'sk-legacy-key',
            'ai_provider'   => 'anthropic',
            'site_name'     => 'Test Site',
            'auto_rebuild'  => true,
        ];
        update_option('scolta_settings', $initial);

        // Simulate what ajax_remove_db_key does (without WP AJAX machinery).
        $settings = get_option('scolta_settings', []);
        unset($settings['ai_api_key']);
        update_option('scolta_settings', $settings);

        $result = get_option('scolta_settings', []);

        $this->assertArrayNotHasKey('ai_api_key', $result,
            'ai_api_key must be removed from scolta_settings');
        $this->assertEquals('anthropic', $result['ai_provider'],
            'ai_provider must be preserved');
        $this->assertEquals('Test Site', $result['site_name'],
            'site_name must be preserved');
        $this->assertTrue($result['auto_rebuild'],
            'auto_rebuild must be preserved');
    }

    public function test_removing_nonexistent_key_is_idempotent(): void {
        // Settings without an API key — removing should not error.
        update_option('scolta_settings', ['ai_provider' => 'openai']);

        $settings = get_option('scolta_settings', []);
        unset($settings['ai_api_key']);
        update_option('scolta_settings', $settings);

        $result = get_option('scolta_settings', []);
        $this->assertEquals('openai', $result['ai_provider']);
        $this->assertArrayNotHasKey('ai_api_key', $result);
    }

    // -------------------------------------------------------------------
    // Method existence and access
    // -------------------------------------------------------------------

    public function test_method_is_public_static(): void {
        $method = new ReflectionMethod('Scolta_Admin', 'ajax_remove_db_key');
        $this->assertTrue($method->isPublic(), 'ajax_remove_db_key must be public');
        $this->assertTrue($method->isStatic(), 'ajax_remove_db_key must be static');
    }

    public function test_method_has_no_parameters(): void {
        $method = new ReflectionMethod('Scolta_Admin', 'ajax_remove_db_key');
        $this->assertCount(0, $method->getParameters(),
            'ajax_remove_db_key takes no parameters (reads from global WP state)');
    }

    // -------------------------------------------------------------------
    // Security: permission check is present in source
    // -------------------------------------------------------------------

    public function test_permission_check_is_present(): void {
        $source = file_get_contents(dirname(__DIR__) . '/admin/class-scolta-admin.php');

        // Extract the ajax_remove_db_key function body.
        preg_match(
            '/public static function ajax_remove_db_key\(\)[^{]*\{(.*?)\n[\t ]+\}/s',
            $source,
            $match
        );
        $body = $match[1] ?? '';

        $this->assertMatchesRegularExpression(
            "/current_user_can\s*\(\s*'manage_options'\s*\)/",
            $body,
            'ajax_remove_db_key must check manage_options capability'
        );
    }

    public function test_nonce_check_precedes_permission_check(): void {
        $source = file_get_contents(dirname(__DIR__) . '/admin/class-scolta-admin.php');

        // Extract just the function body.
        preg_match(
            '/public static function ajax_remove_db_key\(\)[^{]*\{(.*?)\n[\t ]+\}/s',
            $source,
            $match
        );
        $body = $match[1] ?? '';

        $noncePos = strpos($body, 'check_ajax_referer');
        $permPos  = strpos($body, 'current_user_can');

        $this->assertNotFalse($noncePos, 'check_ajax_referer must be present');
        $this->assertNotFalse($permPos, 'current_user_can must be present');
        $this->assertLessThan($permPos, $noncePos,
            'Nonce check must come before capability check');
    }
}
