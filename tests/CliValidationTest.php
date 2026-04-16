<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for Scolta_CLI command structure.
 *
 * The CLI class is only loaded when WP_CLI is defined. We load it
 * manually with WP_CLI stubs to verify class structure.
 */
class CliValidationTest extends TestCase {

    private static bool $cliLoaded = false;

    public static function set_up_before_class(): void {
        // Ensure WP_CLI stubs exist so we can load the CLI class.
        if (!class_exists('WP_CLI')) {
            require_once __DIR__ . '/stubs/wp-cli-stubs.php';
            require_once __DIR__ . '/stubs/wp-cli-utils-stubs.php';
        }

        if (!self::$cliLoaded && !class_exists('Scolta_CLI')) {
            require_once dirname(__DIR__) . '/cli/class-scolta-cli.php';
            self::$cliLoaded = true;
        }
    }

    // -------------------------------------------------------------------
    // Class existence
    // -------------------------------------------------------------------

    public function test_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_CLI'));
    }

    // -------------------------------------------------------------------
    // Public command methods
    // -------------------------------------------------------------------

    public function test_has_build_method(): void {
        $ref = new ReflectionClass('Scolta_CLI');
        $this->assertTrue($ref->hasMethod('build'));
        $this->assertTrue($ref->getMethod('build')->isPublic());
    }

    public function test_has_export_method(): void {
        $ref = new ReflectionClass('Scolta_CLI');
        $this->assertTrue($ref->hasMethod('export'));
        $this->assertTrue($ref->getMethod('export')->isPublic());
    }

    public function test_has_rebuild_index_method(): void {
        $ref = new ReflectionClass('Scolta_CLI');
        $this->assertTrue($ref->hasMethod('rebuild_index'));
        $this->assertTrue($ref->getMethod('rebuild_index')->isPublic());
    }

    public function test_has_status_method(): void {
        $ref = new ReflectionClass('Scolta_CLI');
        $this->assertTrue($ref->hasMethod('status'));
        $this->assertTrue($ref->getMethod('status')->isPublic());
    }

    public function test_has_clear_cache_method(): void {
        $ref = new ReflectionClass('Scolta_CLI');
        $this->assertTrue($ref->hasMethod('clear_cache'));
        $this->assertTrue($ref->getMethod('clear_cache')->isPublic());
    }

    public function test_has_check_setup_method(): void {
        $ref = new ReflectionClass('Scolta_CLI');
        $this->assertTrue($ref->hasMethod('check_setup'));
        $this->assertTrue($ref->getMethod('check_setup')->isPublic());
    }

    public function test_has_download_pagefind_method(): void {
        $ref = new ReflectionClass('Scolta_CLI');
        $this->assertTrue($ref->hasMethod('download_pagefind'));
        $this->assertTrue($ref->getMethod('download_pagefind')->isPublic());
    }

    // -------------------------------------------------------------------
    // Private helper methods
    // -------------------------------------------------------------------

    public function test_has_run_pagefind_private_method(): void {
        $ref = new ReflectionClass('Scolta_CLI');
        $this->assertTrue($ref->hasMethod('run_pagefind'));
        $this->assertTrue($ref->getMethod('run_pagefind')->isPrivate());
    }

    public function test_has_join_types_private_method(): void {
        $ref = new ReflectionClass('Scolta_CLI');
        $this->assertTrue($ref->hasMethod('join_types'));
        $this->assertTrue($ref->getMethod('join_types')->isPrivate());
    }

    // -------------------------------------------------------------------
    // Method signatures
    // -------------------------------------------------------------------

    public function test_build_accepts_args_and_assoc_args(): void {
        $ref = new ReflectionMethod('Scolta_CLI', 'build');
        $params = $ref->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('args', $params[0]->getName());
        $this->assertEquals('array', $params[0]->getType()->getName());
        $this->assertEquals('assoc_args', $params[1]->getName());
        $this->assertEquals('array', $params[1]->getType()->getName());
    }

    public function test_export_accepts_args_and_assoc_args(): void {
        $ref = new ReflectionMethod('Scolta_CLI', 'export');
        $params = $ref->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('args', $params[0]->getName());
        $this->assertEquals('assoc_args', $params[1]->getName());
    }

    public function test_status_accepts_args_and_assoc_args(): void {
        $ref = new ReflectionMethod('Scolta_CLI', 'status');
        $params = $ref->getParameters();

        $this->assertCount(2, $params);
    }

    // -------------------------------------------------------------------
    // Subcommand annotations
    // -------------------------------------------------------------------

    public function test_build_has_subcommand_annotation(): void {
        $ref = new ReflectionMethod('Scolta_CLI', 'build');
        $docComment = $ref->getDocComment();
        $this->assertStringContainsString('@subcommand build', $docComment);
    }

    public function test_export_has_subcommand_annotation(): void {
        $ref = new ReflectionMethod('Scolta_CLI', 'export');
        $docComment = $ref->getDocComment();
        $this->assertStringContainsString('@subcommand export', $docComment);
    }

    public function test_rebuild_index_has_subcommand_annotation(): void {
        $ref = new ReflectionMethod('Scolta_CLI', 'rebuild_index');
        $docComment = $ref->getDocComment();
        $this->assertStringContainsString('@subcommand rebuild-index', $docComment);
    }

    public function test_status_has_subcommand_annotation(): void {
        $ref = new ReflectionMethod('Scolta_CLI', 'status');
        $docComment = $ref->getDocComment();
        $this->assertStringContainsString('@subcommand status', $docComment);
    }

    public function test_clear_cache_has_subcommand_annotation(): void {
        $ref = new ReflectionMethod('Scolta_CLI', 'clear_cache');
        $docComment = $ref->getDocComment();
        $this->assertStringContainsString('@subcommand clear-cache', $docComment);
    }

    public function test_check_setup_has_subcommand_annotation(): void {
        $ref = new ReflectionMethod('Scolta_CLI', 'check_setup');
        $docComment = $ref->getDocComment();
        $this->assertStringContainsString('@subcommand check-setup', $docComment);
    }

    public function test_download_pagefind_has_subcommand_annotation(): void {
        $ref = new ReflectionMethod('Scolta_CLI', 'download_pagefind');
        $docComment = $ref->getDocComment();
        $this->assertStringContainsString('@subcommand download-pagefind', $docComment);
    }

    // -------------------------------------------------------------------
    // WP-CLI registration
    // -------------------------------------------------------------------

    public function test_cli_file_registers_command(): void {
        $content = file_get_contents(dirname(__DIR__) . '/cli/class-scolta-cli.php');
        $this->assertMatchesRegularExpression(
            "/\\\\?WP_CLI::add_command\s*\(\s*'scolta'/",
            $content,
            'CLI file should register the scolta command via WP_CLI::add_command'
        );
    }

    public function test_command_registered_with_class_name(): void {
        $content = file_get_contents(dirname(__DIR__) . '/cli/class-scolta-cli.php');
        $this->assertStringContainsString(
            "'Scolta_CLI'",
            $content,
            'CLI command should be registered with Scolta_CLI class'
        );
    }

    // -------------------------------------------------------------------
    // display_errors suppression (managed hosting fix)
    // -------------------------------------------------------------------

    public function test_public_commands_suppress_display_errors(): void {
        // Every public command method must open with ini_set('display_errors','0')
        // and restore it in a finally block.
        $source = file_get_contents(dirname(__DIR__) . '/cli/class-scolta-cli.php');
        $this->assertMatchesRegularExpression(
            "/ini_set\s*\(\s*'display_errors'\s*,\s*'0'\s*\)/",
            $source,
            'CLI handlers must suppress display_errors'
        );
        $this->assertStringContainsString(
            'finally',
            $source,
            'CLI handlers must use finally to restore display_errors'
        );
    }

    public function test_display_errors_is_restored_after_clear_cache(): void {
        // clear_cache completes successfully with WP stubs, so we can
        // verify that ini_set('display_errors','0') is in effect during the
        // call and restored afterward via the finally block.
        $prev = ini_get('display_errors');
        ini_set('display_errors', '1');

        $cli = new \Scolta_CLI();
        $cli->clear_cache([], []);

        $this->assertEquals('1', ini_get('display_errors'),
            'display_errors must be restored to its prior value after command returns');

        ini_set('display_errors', $prev);
    }

    // -------------------------------------------------------------------
    // download-pagefind uses downloadTargetDir() (Fix 3)
    // -------------------------------------------------------------------

    public function test_download_pagefind_uses_download_target_dir(): void {
        $source = file_get_contents(dirname(__DIR__) . '/cli/class-scolta-cli.php');
        $this->assertStringContainsString(
            'downloadTargetDir()',
            $source,
            'do_download_pagefind() must call downloadTargetDir() to obtain install path'
        );
        $this->assertStringNotContainsString(
            "SCOLTA_PLUGIN_DIR . '/bin'",
            $source,
            'Hardcoded /bin path must not remain — downloadTargetDir() owns the path'
        );
    }

    public function test_all_resolvers_use_plugin_dir(): void {
        $source = file_get_contents(dirname(__DIR__) . '/cli/class-scolta-cli.php');
        $this->assertStringNotContainsString(
            'projectDir: ABSPATH',
            $source,
            'All PagefindBinary constructions must use SCOLTA_PLUGIN_DIR, not ABSPATH'
        );
    }

    // -------------------------------------------------------------------
    // Method count
    // -------------------------------------------------------------------

    // -------------------------------------------------------------------
    // isExecutable() guard
    // -------------------------------------------------------------------

    public function test_cli_uses_status_not_is_executable(): void {
        $source = file_get_contents(dirname(__DIR__) . '/cli/class-scolta-cli.php');
        $this->assertStringNotContainsString(
            'isExecutable()',
            $source,
            'CLI must not call private isExecutable(); use resolve() + status() instead'
        );
    }

    public function test_public_command_method_count(): void {
        $ref = new ReflectionClass('Scolta_CLI');
        $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        // Filter out inherited methods (e.g., from PHPUnit or base class).
        $ownMethods = array_filter($publicMethods, function ($m) {
            return $m->getDeclaringClass()->getName() === 'Scolta_CLI';
        });

        // 7 public command methods: build, export, rebuild_index, status,
        // clear_cache, check_setup, download_pagefind
        $this->assertCount(7, $ownMethods);
    }
}
