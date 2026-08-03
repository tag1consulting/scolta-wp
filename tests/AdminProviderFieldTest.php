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
     * Store Amazee credentials so the resolution reports an Amazee source.
     *
     * The source is a single 'amazee'. The resolver briefly split it into
     * 'amazee:auto' and 'amazee:operator', but nothing records which of the
     * two produced a stored token, and on WordPress only the auto case was
     * ever reachable (tag1consulting/scolta-php#273).
     */
    private function storeAmazeeCredentials(): void {
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store('amazee-token', 'https://api.amazee.example.com', 'us-east-1');
        $this->assertSame(
            'amazee',
            Scolta_Ai_Service::get_api_key_source(),
            'Precondition: Amazee credentials must make get_api_key_source() report an Amazee source'
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
        // The placeholder carries value="", so the pattern has to allow it —
        // "nothing selected" is a real state here, not a missing one.
        if (!preg_match('/<option value="([^"]*)"[^>]*\sselected="selected"/', $html, $m)) {
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
    // Empty state — the placeholder, never an inferred selection
    // -------------------------------------------------------------------

    public function test_empty_state_selects_the_placeholder_even_with_credentials_stored(): void {
        // Stored credentials used to preselect Amazee for a site that had never
        // chosen it. Inferring a selection from a side effect is what made the
        // form indistinguishable from one somebody had filled in: the connect
        // flow now writes ai_provider itself, so an unset value genuinely means
        // nobody has chosen.
        $this->storeAmazeeCredentials();
        update_option('scolta_settings', []);

        $this->assertSelectedOption('', $this->renderField());
    }

    public function test_empty_state_selects_the_placeholder_without_credentials(): void {
        // No default provider. An untouched install shows "- Select a provider -"
        // and AI stays off; it is not Anthropic.
        update_option('scolta_settings', []);

        $markup = $this->renderField();
        $this->assertSelectedOption('', $markup);
        $this->assertStringContainsString('- Select a provider -', $markup);
        $this->assertStringContainsString('AI features are off', $markup);
    }
}
