<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the unified output_dir default and related bug fixes (SCOLTA-9).
 *
 * The root cause: the old default ended in /pagefind, which caused the PHP
 * indexer's atomicSwap() to write the index to /pagefind/pagefind/ instead of
 * /pagefind/. The shortcode's dual-layout check then found the stale double-nested
 * path first and served outdated content.
 */
class OutputDirTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options']            = [];
        $GLOBALS['scolta_doing_it_wrong'] = [];
        scolta_activate();
    }

    protected function tear_down(): void {
        unset( $GLOBALS['scolta_doing_it_wrong'] );
        // Remove any index files created under the uploads/scolta tree during tests.
        $scolta_dir = wp_upload_dir()['basedir'] . '/scolta';
        $this->rm_rf( $scolta_dir );
    }

    private function rm_rf( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( scandir( $dir ) ?: [] as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir( $path ) ? $this->rm_rf( $path ) : @unlink( $path );
        }
        @rmdir( $dir );
    }

    // -------------------------------------------------------------------
    // A1. Canonical default does NOT end in /pagefind
    // -------------------------------------------------------------------

    public function test_default_output_dir_does_not_end_with_pagefind(): void {
        $default = scolta_default_output_dir();
        $this->assertStringNotContainsString(
            '/pagefind',
            $default,
            'scolta_default_output_dir() must not contain /pagefind'
        );
    }

    public function test_default_output_dir_is_under_uploads(): void {
        $default  = scolta_default_output_dir();
        $uploads  = wp_upload_dir()['basedir'];
        $this->assertStringStartsWith( $uploads, $default );
    }

    public function test_activation_output_dir_does_not_end_with_pagefind(): void {
        $settings = get_option( 'scolta_settings' );
        $this->assertStringNotContainsString(
            '/pagefind',
            $settings['output_dir'] ?? '',
            'Activated output_dir must not end with /pagefind'
        );
    }

    // -------------------------------------------------------------------
    // A1. All sources resolve to the same default
    // -------------------------------------------------------------------

    public function test_shortcode_default_matches_canonical(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-shortcode.php' );
        $this->assertStringNotContainsString(
            "wp_upload_dir()['basedir'] . '/scolta/pagefind'",
            $source,
            'Shortcode must not have inline /scolta/pagefind fallback; use scolta_default_output_dir()'
        );
        $this->assertStringContainsString(
            'scolta_default_output_dir()',
            $source,
            'Shortcode must use scolta_default_output_dir() as fallback'
        );
    }

    public function test_rebuild_scheduler_default_matches_canonical(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-rebuild-scheduler.php' );
        $this->assertStringNotContainsString(
            "ABSPATH . 'scolta-pagefind'",
            $source,
            'Rebuild scheduler must not use old ABSPATH default'
        );
        $this->assertStringContainsString(
            'scolta_default_output_dir()',
            $source,
            'Rebuild scheduler must use scolta_default_output_dir() as fallback'
        );
    }

    public function test_cli_default_matches_canonical(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );
        $this->assertStringNotContainsString(
            "wp_upload_dir()['basedir'] . '/scolta/pagefind'",
            $source,
            'CLI must not have inline /scolta/pagefind fallback; use scolta_default_output_dir()'
        );
        $this->assertStringContainsString(
            'scolta_default_output_dir()',
            $source,
            'CLI must use scolta_default_output_dir() as fallback'
        );
    }

    public function test_rest_api_default_matches_canonical(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-rest-api.php' );
        $this->assertStringNotContainsString(
            "ABSPATH . 'scolta-pagefind'",
            $source,
            'REST API must not use old ABSPATH default'
        );
        $this->assertStringContainsString(
            'scolta_default_output_dir()',
            $source,
            'REST API must use scolta_default_output_dir() as fallback'
        );
    }

    // -------------------------------------------------------------------
    // A4. Shortcode emits _doing_it_wrong when output_dir ends in /pagefind
    // -------------------------------------------------------------------

    public function test_shortcode_warns_when_output_dir_ends_in_pagefind(): void {
        $GLOBALS['scolta_enqueued_scripts'] = [];
        $GLOBALS['scolta_enqueued_styles']  = [];
        $GLOBALS['scolta_localized_scripts'] = [];

        $bad_dir = wp_upload_dir()['basedir'] . '/scolta/pagefind';
        update_option( 'scolta_settings', [ 'output_dir' => $bad_dir ] );

        // Create the index at the correct sub-path so render() doesn't bail early.
        $index_path = $bad_dir . '/pagefind';
        if ( ! is_dir( $index_path ) ) {
            @mkdir( $index_path, 0755, true );
        }
        file_put_contents( $index_path . '/pagefind-entry.json', '{}' );

        Scolta_Shortcode::render();

        $warnings = $GLOBALS['scolta_doing_it_wrong'] ?? [];
        $this->assertNotEmpty( $warnings, '_doing_it_wrong() must fire when output_dir ends in /pagefind' );

        $functions = array_column( $warnings, 'function' );
        $this->assertContains(
            'Scolta_Shortcode::render',
            $functions,
            '_doing_it_wrong() must identify Scolta_Shortcode::render as the caller'
        );

        // Cleanup.
        unset(
            $GLOBALS['scolta_enqueued_scripts'],
            $GLOBALS['scolta_enqueued_styles'],
            $GLOBALS['scolta_localized_scripts']
        );
    }

    public function test_shortcode_no_warning_with_canonical_output_dir(): void {
        $GLOBALS['scolta_enqueued_scripts'] = [];
        $GLOBALS['scolta_enqueued_styles']  = [];
        $GLOBALS['scolta_localized_scripts'] = [];

        $good_dir  = scolta_default_output_dir();
        $index_dir = $good_dir . '/pagefind';
        if ( ! is_dir( $index_dir ) ) {
            @mkdir( $index_dir, 0755, true );
        }
        file_put_contents( $index_dir . '/pagefind-entry.json', '{}' );

        Scolta_Shortcode::render();

        $warnings = $GLOBALS['scolta_doing_it_wrong'] ?? [];
        $this->assertEmpty( $warnings, 'No _doing_it_wrong() should fire for the canonical default' );

        unset(
            $GLOBALS['scolta_enqueued_scripts'],
            $GLOBALS['scolta_enqueued_styles'],
            $GLOBALS['scolta_localized_scripts']
        );
    }

    // -------------------------------------------------------------------
    // A2. Dual-layout detection finds correct single-nested index
    // -------------------------------------------------------------------

    public function test_shortcode_prefers_php_layout_when_both_exist(): void {
        $GLOBALS['scolta_enqueued_scripts'] = [];
        $GLOBALS['scolta_enqueued_styles']  = [];
        $GLOBALS['scolta_localized_scripts'] = [];

        $output_dir = scolta_default_output_dir();
        // PHP-indexer layout: output_dir/pagefind/pagefind-entry.json
        $php_index = $output_dir . '/pagefind';
        @mkdir( $php_index, 0755, true );
        file_put_contents( $php_index . '/pagefind-entry.json', '{"version":"1.5.2"}' );
        file_put_contents( $php_index . '/pagefind.js', '' );
        file_put_contents( $php_index . '/pagefind-ui.css', '' );

        // Binary-indexer layout: output_dir/pagefind-entry.json (also present but should lose)
        file_put_contents( $output_dir . '/pagefind-entry.json', '{"version":"stale"}' );

        $html = Scolta_Shortcode::render();
        $this->assertStringContainsString( 'scolta-search', $html );

        // pagefindPath in config must point to the PHP-indexer (subdirectory) location.
        $config = $GLOBALS['scolta_localized_scripts']['scolta-search'] ?? [];
        $this->assertStringContainsString(
            '/pagefind/pagefind.js',
            $config['pagefindPath'] ?? '',
            'pagefindPath must reference the PHP-indexer layout (subdirectory /pagefind/)'
        );

        unset(
            $GLOBALS['scolta_enqueued_scripts'],
            $GLOBALS['scolta_enqueued_styles'],
            $GLOBALS['scolta_localized_scripts']
        );

        // Cleanup.
        @unlink( $output_dir . '/pagefind-entry.json' );
    }

    // -------------------------------------------------------------------
    // A2. scolta_cleanup_nested_indexes() removes double-nested directories
    // -------------------------------------------------------------------

    public function test_cleanup_removes_double_nested_directory(): void {
        $output_dir = sys_get_temp_dir() . '/scolta-test-cleanup-' . uniqid();
        @mkdir( $output_dir . '/pagefind/pagefind', 0755, true );
        file_put_contents( $output_dir . '/pagefind/pagefind/pagefind-entry.json', '{}' );
        file_put_contents( $output_dir . '/pagefind/pagefind/pagefind.js', '' );

        $this->assertDirectoryExists( $output_dir . '/pagefind/pagefind' );

        scolta_cleanup_nested_indexes( $output_dir );

        $this->assertDirectoryDoesNotExist( $output_dir . '/pagefind/pagefind' );

        // Cleanup temp dir.
        @rmdir( $output_dir . '/pagefind' );
        @rmdir( $output_dir );
    }

    public function test_cleanup_is_noop_when_no_nested_directory(): void {
        $output_dir = sys_get_temp_dir() . '/scolta-test-cleanup-noop-' . uniqid();
        @mkdir( $output_dir . '/pagefind', 0755, true );
        file_put_contents( $output_dir . '/pagefind/pagefind-entry.json', '{}' );

        // Should not throw.
        scolta_cleanup_nested_indexes( $output_dir );

        $this->assertFileExists( $output_dir . '/pagefind/pagefind-entry.json' );

        // Cleanup.
        @unlink( $output_dir . '/pagefind/pagefind-entry.json' );
        @rmdir( $output_dir . '/pagefind' );
        @rmdir( $output_dir );
    }

    // -------------------------------------------------------------------
    // A3. wp scolta cleanup command exists
    // -------------------------------------------------------------------

    public function test_cli_has_cleanup_subcommand(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );
        $this->assertStringContainsString(
            '@subcommand cleanup',
            $source,
            'CLI must define a cleanup subcommand'
        );
        $this->assertStringContainsString(
            'public function cleanup(',
            $source,
            'Scolta_CLI must have a public cleanup() method'
        );
    }
}
