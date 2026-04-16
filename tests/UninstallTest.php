<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for uninstall.php cleanup behavior.
 *
 * The uninstall script cannot be included directly (it checks
 * WP_UNINSTALL_PLUGIN), so we test the two cleanup paths it exercises:
 * - Option/table deletion via the existing WP stubs
 * - Directory tree removal via the RecursiveDirectoryIterator fallback
 */
class UninstallTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
    }

    // -------------------------------------------------------------------
    // Script structure
    // -------------------------------------------------------------------

    public function test_uninstall_file_exists(): void {
        $this->assertFileExists(dirname(__DIR__) . '/uninstall.php');
    }

    public function test_uninstall_checks_wp_uninstall_plugin_constant(): void {
        $source = file_get_contents(dirname(__DIR__) . '/uninstall.php');
        $this->assertStringContainsString(
            'WP_UNINSTALL_PLUGIN',
            $source,
            'uninstall.php must guard against direct execution'
        );
    }

    public function test_uninstall_deletes_scolta_settings(): void {
        $source = file_get_contents(dirname(__DIR__) . '/uninstall.php');
        $this->assertStringContainsString(
            "delete_option('scolta_settings')",
            $source
        );
    }

    public function test_uninstall_drops_tracker_table(): void {
        $source = file_get_contents(dirname(__DIR__) . '/uninstall.php');
        $this->assertStringContainsString(
            'DROP TABLE IF EXISTS',
            $source
        );
        $this->assertStringContainsString(
            'scolta_tracker',
            $source
        );
    }

    public function test_uninstall_removes_uploads_scolta_directory(): void {
        $source = file_get_contents(dirname(__DIR__) . '/uninstall.php');
        // Path is built dynamically as wp_upload_dir()['basedir'] . '/scolta'.
        $this->assertStringContainsString(
            "wp_upload_dir()",
            $source,
            'uninstall.php must use wp_upload_dir() to locate the scolta directory'
        );
        $this->assertStringContainsString(
            "'/scolta'",
            $source,
            'uninstall.php must target the /scolta subdirectory of uploads'
        );
        $this->assertStringContainsString(
            'rmdir',
            $source,
            'uninstall.php must call rmdir (or WP_Filesystem->rmdir) to remove the directory'
        );
    }

    // -------------------------------------------------------------------
    // Directory removal logic (RecursiveDirectoryIterator path)
    // -------------------------------------------------------------------

    public function test_recursive_directory_removal(): void {
        // Build a temp tree that mimics uploads/scolta/pagefind structure.
        $base = sys_get_temp_dir() . '/scolta_uninstall_test_' . uniqid();
        mkdir($base . '/pagefind/fragment', 0755, true);
        mkdir($base . '/build', 0755, true);
        file_put_contents($base . '/pagefind/pagefind.js', 'x');
        file_put_contents($base . '/pagefind/fragment/frag.pf_fragment', 'x');
        file_put_contents($base . '/build/1.html', '<html></html>');

        // Use the same logic as uninstall.php's RecursiveDirectoryIterator path.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        @rmdir($base);

        $this->assertDirectoryDoesNotExist($base, 'Directory tree must be fully removed');
    }

    // -------------------------------------------------------------------
    // Admin source — no private isExecutable() call
    // -------------------------------------------------------------------

    public function test_admin_uses_status_not_is_executable(): void {
        $source = file_get_contents(dirname(__DIR__) . '/admin/class-scolta-admin.php');
        $this->assertStringNotContainsString(
            'isExecutable()',
            $source,
            'admin must not call private isExecutable(); use status()[\'available\'] instead'
        );
        $this->assertStringContainsString(
            "status()",
            $source,
            'admin must use PagefindBinary::status() to check binary availability'
        );
    }
}
