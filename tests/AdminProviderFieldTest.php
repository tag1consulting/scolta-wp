<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the AI Provider field renderer (render_ai_provider_field()).
 *
 * Regression coverage for #123: when Amazee credentials are present, the
 * <select> must still reflect the explicitly-saved ai_provider, not be forced
 * to "amazee" by API-key source auto-detection. Auto-detection is only a
 * fallback for the empty-state (no provider ever saved).
 *
 * The admin class is only loaded when is_admin() returns true; load it
 * explicitly for testing.
 */
class AdminProviderFieldTest extends TestCase {

    public static function set_up_before_class(): void {
        if (!class_exists('Scolta_Admin')) {
            require_once dirname(__DIR__) . '/admin/class-scolta-admin.php';
        }
    }

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
    }

    /**
     * Store Amazee credentials so get_api_key_source() returns 'amazee'.
     */
    private function storeAmazeeCredentials(): void {
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store('amazee-token', 'https://api.amazee.example.com', 'us-east-1');
        $this->assertSame(
            'amazee',
            Scolta_Ai_Service::get_api_key_source(),
            'Precondition: Amazee credentials must make get_api_key_source() return amazee'
        );
    }

    private function renderField(): string {
        ob_start();
        Scolta_Admin::render_ai_provider_field();
        return (string) ob_get_clean();
    }

    /**
     * Assert which <option> carries the selected attribute.
     */
    private function assertSelectedOption(string $expected, string $html): void {
        if (!preg_match('/<option value="([^"]+)"[^>]*\sselected="selected"/', $html, $m)) {
            $this->fail("No <option> was marked selected. HTML:\n{$html}");
        }
        $this->assertSame(
            $expected,
            $m[1],
            "Expected the '{$expected}' option to be selected, got '{$m[1]}'"
        );
    }

    // -------------------------------------------------------------------
    // #123 — saved provider wins over Amazee auto-detection
    // -------------------------------------------------------------------

    public function test_saved_anthropic_wins_over_amazee_credentials(): void {
        $this->storeAmazeeCredentials();
        update_option('scolta_settings', ['ai_provider' => 'anthropic']);

        $html = $this->renderField();

        $this->assertSelectedOption('anthropic', $html);
        $this->assertStringNotContainsString(
            'value="amazee" selected="selected"',
            $html,
            'The amazee option must NOT be selected when anthropic was saved'
        );
    }

    public function test_saved_openai_wins_over_amazee_credentials(): void {
        $this->storeAmazeeCredentials();
        update_option('scolta_settings', ['ai_provider' => 'openai']);

        $this->assertSelectedOption('openai', $this->renderField());
    }

    public function test_saved_amazee_is_respected(): void {
        $this->storeAmazeeCredentials();
        update_option('scolta_settings', ['ai_provider' => 'amazee']);

        $this->assertSelectedOption('amazee', $this->renderField());
    }

    // -------------------------------------------------------------------
    // Empty state — fall back to auto-detection
    // -------------------------------------------------------------------

    public function test_empty_state_falls_back_to_amazee_when_credentials_present(): void {
        // No ai_provider ever saved, but an Amazee trial was auto-provisioned.
        $this->storeAmazeeCredentials();
        update_option('scolta_settings', []);

        $this->assertSelectedOption('amazee', $this->renderField());
    }

    public function test_empty_state_defaults_to_anthropic_without_credentials(): void {
        // No saved provider and no Amazee credentials.
        update_option('scolta_settings', []);

        $this->assertSelectedOption('anthropic', $this->renderField());
    }
}
