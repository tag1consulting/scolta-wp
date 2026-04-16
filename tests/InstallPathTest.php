<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests the full install → configure → build → search path on WordPress.
 *
 * These structural and source-inspection tests verify that the install path
 * does NOT require FFI, Extism, or any native PHP extensions beyond standard
 * PHP — the core managed hosting compatibility requirement for 0.2.2.
 */
class InstallPathTest extends TestCase {

    private string $pluginDir;

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
        $this->pluginDir       = dirname( __DIR__ );
    }

    // -------------------------------------------------------------------
    // Activation defaults
    // -------------------------------------------------------------------

    public function test_activation_sets_defaults_without_ffi_checks(): void {
        scolta_activate();

        $settings = get_option( 'scolta_settings' );
        $this->assertIsArray( $settings );

        // Paths must use uploads, not WP_CONTENT_DIR or ABSPATH.
        $uploads = wp_upload_dir()['basedir'];
        $this->assertStringStartsWith( $uploads, $settings['build_dir'] );
        $this->assertStringStartsWith( $uploads, $settings['output_dir'] );
    }

    public function test_activation_does_not_reference_ffi_or_extism(): void {
        $source = file_get_contents( $this->pluginDir . '/scolta.php' );
        $this->assertStringNotContainsString( 'FFI', $source );
        $this->assertStringNotContainsString( 'Extism', $source );
        $this->assertStringNotContainsString( 'ext-ffi', $source );
    }

    public function test_default_paths_use_wp_upload_dir(): void {
        scolta_activate();

        $settings = get_option( 'scolta_settings' );
        $uploads  = wp_upload_dir()['basedir'];

        $this->assertStringStartsWith(
            $uploads,
            $settings['build_dir'] ?? '',
            'build_dir must default to uploads-based path'
        );
        $this->assertStringStartsWith(
            $uploads,
            $settings['output_dir'] ?? '',
            'output_dir must default to uploads-based path'
        );
    }

    // -------------------------------------------------------------------
    // CLI build path: proc_open, not exec
    // -------------------------------------------------------------------

    public function test_cli_build_uses_proc_open_not_shell_exec_for_pagefind(): void {
        $source = file_get_contents( $this->pluginDir . '/cli/class-scolta-cli.php' );

        // The Pagefind subprocess must use proc_open (non-blocking, timeout-safe).
        $this->assertStringContainsString(
            'proc_open',
            $source,
            'CLI must use proc_open for Pagefind subprocess (not shell_exec)'
        );
    }

    public function test_cli_source_has_no_ffi_references(): void {
        $source = file_get_contents( $this->pluginDir . '/cli/class-scolta-cli.php' );
        $this->assertStringNotContainsString( 'FFI', $source );
        $this->assertStringNotContainsString( 'Extism', $source );
        $this->assertStringNotContainsString( 'ext-ffi', $source );
    }

    // -------------------------------------------------------------------
    // Admin UI
    // -------------------------------------------------------------------

    public function test_admin_settings_page_has_auto_rebuild_delay_field(): void {
        $source = file_get_contents( $this->pluginDir . '/admin/class-scolta-admin.php' );
        $this->assertStringContainsString(
            'auto_rebuild_delay',
            $source,
            'Admin settings page must register auto_rebuild_delay field'
        );
    }

    public function test_admin_auto_rebuild_delay_has_min_max(): void {
        $source = file_get_contents( $this->pluginDir . '/admin/class-scolta-admin.php' );
        $this->assertStringContainsString( 'min="60"', $source );
        $this->assertStringContainsString( 'max="3600"', $source );
    }

    public function test_dashboard_widget_is_registered(): void {
        $source = file_get_contents( $this->pluginDir . '/admin/class-scolta-admin.php' );
        $this->assertStringContainsString(
            'wp_add_dashboard_widget',
            $source,
            'Dashboard widget must be registered via wp_add_dashboard_widget()'
        );
    }

    public function test_admin_source_has_no_ffi_references(): void {
        $source = file_get_contents( $this->pluginDir . '/admin/class-scolta-admin.php' );
        $this->assertStringNotContainsString( 'FFI', $source );
        $this->assertStringNotContainsString( 'Extism', $source );
        $this->assertStringNotContainsString( 'ext-ffi', $source );
    }

    // -------------------------------------------------------------------
    // Plugin file
    // -------------------------------------------------------------------

    public function test_plugin_file_does_not_check_ffi_or_extensions(): void {
        $source = file_get_contents( $this->pluginDir . '/scolta.php' );
        $this->assertStringNotContainsString( 'extension_loaded(\'ffi\')', $source );
        $this->assertStringNotContainsString( 'extension_loaded("ffi")', $source );
        $this->assertStringNotContainsString( 'ext-extism', $source );
    }
}
