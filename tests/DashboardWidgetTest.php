<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Scolta_Admin dashboard widget methods.
 *
 * Verifies that the dashboard widget integration is wired up, that
 * render_dashboard_widget() produces expected HTML, and that
 * get_health_status() returns the correct structure.
 */
class DashboardWidgetTest extends TestCase {

    protected function setUp(): void {
        // Clear options before each test.
        $GLOBALS['wp_options'] = [];
    }

    // -------------------------------------------------------------------
    // Method existence
    // -------------------------------------------------------------------

    public function test_add_dashboard_widget_method_exists(): void {
        $this->assertTrue(
            method_exists('Scolta_Admin', 'add_dashboard_widget'),
            'Scolta_Admin must have add_dashboard_widget() method'
        );
    }

    public function test_render_dashboard_widget_method_exists(): void {
        $this->assertTrue(
            method_exists('Scolta_Admin', 'render_dashboard_widget'),
            'Scolta_Admin must have render_dashboard_widget() method'
        );
    }

    public function test_get_health_status_method_exists(): void {
        $this->assertTrue(
            method_exists('Scolta_Admin', 'get_health_status'),
            'Scolta_Admin must have get_health_status() method'
        );
    }

    // -------------------------------------------------------------------
    // wp_dashboard_setup hook registration
    // -------------------------------------------------------------------

    public function test_init_registers_wp_dashboard_setup_hook(): void {
        $source = file_get_contents(dirname(__DIR__) . '/admin/class-scolta-admin.php');

        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'wp_dashboard_setup'/",
            $source,
            'Scolta_Admin::init() must register wp_dashboard_setup action'
        );
        $this->assertStringContainsString(
            "'add_dashboard_widget'",
            $source,
            'wp_dashboard_setup must point to add_dashboard_widget'
        );
    }

    // -------------------------------------------------------------------
    // render_dashboard_widget() output
    // -------------------------------------------------------------------

    public function test_widget_renders_not_built_when_no_index(): void {
        // No settings — output_dir won't exist so index_exists = false.
        update_option('scolta_settings', []);

        ob_start();
        Scolta_Admin::render_dashboard_widget();
        $output = ob_get_clean();

        $this->assertStringContainsString('Not built yet', $output);
    }

    public function test_widget_renders_not_configured_when_no_api_key(): void {
        if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
            $this->markTestSkipped(
                'SCOLTA_API_KEY constant defined in a prior test (constants cannot be undefined in PHP); ' .
                'see AdminDashboardWidgetTest::test_dashboard_shows_not_configured_when_no_key() for an isolated version'
            );
        }

        putenv( 'SCOLTA_API_KEY' );
        unset( $_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY'] );
        update_option( 'scolta_settings', array( 'ai_provider' => 'anthropic' ) );

        ob_start();
        Scolta_Admin::render_dashboard_widget();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Not configured', $output );
    }

    public function test_widget_renders_configured_when_api_key_present(): void {
        update_option('scolta_settings', ['ai_api_key' => 'sk-test-key']);

        ob_start();
        Scolta_Admin::render_dashboard_widget();
        $output = ob_get_clean();

        $this->assertStringContainsString('Configured', $output);
    }

    public function test_widget_contains_rebuild_form(): void {
        update_option('scolta_settings', []);

        ob_start();
        Scolta_Admin::render_dashboard_widget();
        $output = ob_get_clean();

        $this->assertStringContainsString('scolta_rebuild_now', $output);
        $this->assertStringContainsString('<form', $output);
    }

    public function test_widget_contains_settings_link(): void {
        update_option('scolta_settings', []);

        ob_start();
        Scolta_Admin::render_dashboard_widget();
        $output = ob_get_clean();

        $this->assertStringContainsString('options-general.php?page=scolta', $output);
    }

    // -------------------------------------------------------------------
    // get_health_status() structure
    // -------------------------------------------------------------------

    public function test_health_status_returns_array_with_required_keys(): void {
        update_option('scolta_settings', ['output_dir' => '/tmp/nonexistent-' . uniqid()]);

        $health = Scolta_Admin::get_health_status();

        $this->assertIsArray($health);
        $this->assertArrayHasKey('index_exists', $health);
        $this->assertArrayHasKey('index', $health);
        $this->assertArrayHasKey('fragment_count', $health['index']);
        $this->assertArrayHasKey('last_modified', $health['index']);
    }

    public function test_health_status_reports_no_index_when_dir_missing(): void {
        update_option('scolta_settings', ['output_dir' => '/tmp/nonexistent-' . uniqid()]);

        $health = Scolta_Admin::get_health_status();

        $this->assertFalse($health['index_exists']);
        $this->assertSame(0, $health['index']['fragment_count']);
        $this->assertNull($health['index']['last_modified']);
    }
}
