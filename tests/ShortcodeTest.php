<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for Scolta_Shortcode — search UI embedding.
 */
class ShortcodeTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['scolta_enqueued_scripts'] = [];
        $GLOBALS['scolta_enqueued_styles'] = [];
        $GLOBALS['scolta_localized_scripts'] = [];

        // Set up default settings so render() can create a ScoltaConfig.
        scolta_activate();
    }

    protected function tear_down(): void {
        unset(
            $GLOBALS['scolta_enqueued_scripts'],
            $GLOBALS['scolta_enqueued_styles'],
            $GLOBALS['scolta_localized_scripts']
        );
    }

    // -------------------------------------------------------------------
    // Class structure
    // -------------------------------------------------------------------

    public function test_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_Shortcode'));
    }

    public function test_register_method_exists(): void {
        $ref = new ReflectionClass('Scolta_Shortcode');
        $this->assertTrue($ref->hasMethod('register'));
    }

    public function test_register_is_static(): void {
        $ref = new ReflectionMethod('Scolta_Shortcode', 'register');
        $this->assertTrue($ref->isStatic());
    }

    public function test_render_method_exists(): void {
        $ref = new ReflectionClass('Scolta_Shortcode');
        $this->assertTrue($ref->hasMethod('render'));
    }

    public function test_render_returns_string(): void {
        $ref = new ReflectionMethod('Scolta_Shortcode', 'render');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('string', $returnType->getName());
    }

    // -------------------------------------------------------------------
    // Render output
    // -------------------------------------------------------------------

    public function test_render_contains_search_container(): void {
        $output = Scolta_Shortcode::render();
        $this->assertStringContainsString('id="scolta-search"', $output);
    }

    public function test_render_returns_div_element(): void {
        $output = Scolta_Shortcode::render();
        $this->assertStringStartsWith('<div', $output);
        $this->assertStringEndsWith('</div>', $output);
    }

    // -------------------------------------------------------------------
    // Asset enqueuing
    // -------------------------------------------------------------------

    public function test_render_enqueues_scolta_js(): void {
        Scolta_Shortcode::render();

        $this->assertContains(
            'scolta-search',
            $GLOBALS['scolta_enqueued_scripts'],
            'wp_enqueue_script should be called with handle "scolta-search"'
        );
    }

    public function test_render_enqueues_scolta_css(): void {
        Scolta_Shortcode::render();

        $this->assertContains(
            'scolta-search',
            $GLOBALS['scolta_enqueued_styles'],
            'wp_enqueue_style should be called with handle "scolta-search"'
        );
    }

    public function test_render_calls_wp_localize_script(): void {
        Scolta_Shortcode::render();

        $this->assertNotEmpty(
            $GLOBALS['scolta_localized_scripts'],
            'wp_localize_script should be called'
        );
    }

    // -------------------------------------------------------------------
    // Localized config structure
    // -------------------------------------------------------------------

    public function test_localized_config_has_expected_keys(): void {
        Scolta_Shortcode::render();

        $localized = $GLOBALS['scolta_localized_scripts'];
        $this->assertArrayHasKey('scolta-search', $localized);

        $config = $localized['scolta-search'];
        $this->assertArrayHasKey('scoring', $config);
        $this->assertArrayHasKey('endpoints', $config);
        $this->assertArrayHasKey('pagefindPath', $config);
        $this->assertArrayHasKey('siteName', $config);
        $this->assertArrayHasKey('container', $config);
        $this->assertArrayHasKey('nonce', $config);
    }

    public function test_localized_config_container_matches_output(): void {
        Scolta_Shortcode::render();

        $config = $GLOBALS['scolta_localized_scripts']['scolta-search'];
        $this->assertEquals('#scolta-search', $config['container']);
    }

    public function test_localized_config_endpoints_exist(): void {
        Scolta_Shortcode::render();

        $config = $GLOBALS['scolta_localized_scripts']['scolta-search'];
        $endpoints = $config['endpoints'];
        $this->assertArrayHasKey('expand', $endpoints);
        $this->assertArrayHasKey('summarize', $endpoints);
        $this->assertArrayHasKey('followup', $endpoints);
    }

    public function test_localized_config_nonce_is_string(): void {
        Scolta_Shortcode::render();

        $config = $GLOBALS['scolta_localized_scripts']['scolta-search'];
        $this->assertIsString($config['nonce']);
        $this->assertNotEmpty($config['nonce']);
    }

    // -------------------------------------------------------------------
    // Register behavior
    // -------------------------------------------------------------------

    public function test_register_runs_without_error(): void {
        Scolta_Shortcode::register();
        $this->assertTrue(true);
    }
}
