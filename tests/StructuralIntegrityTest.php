<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Structural integrity and rename validation tests.
 */
class StructuralIntegrityTest extends TestCase {

    private string $root;

    protected function set_up(): void {
        $this->root = dirname(__DIR__);
    }

    // -------------------------------------------------------------------
    // Required classes exist and are loadable
    // -------------------------------------------------------------------

    public function test_tracker_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_Tracker'));
    }

    public function test_content_source_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_Content_Source'));
    }

    public function test_ai_service_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_Ai_Service'));
    }

    public function test_rest_api_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_Rest_Api'));
    }

    public function test_shortcode_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_Shortcode'));
    }

    public function test_admin_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_Admin'));
    }

    // -------------------------------------------------------------------
    // Content source implements interface
    // -------------------------------------------------------------------

    public function test_content_source_implements_interface(): void {
        $ref = new \ReflectionClass('Scolta_Content_Source');
        $this->assertTrue(
            $ref->implementsInterface(\Tag1\Scolta\Content\ContentSourceInterface::class)
        );
    }

    // -------------------------------------------------------------------
    // Required files exist
    // -------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredFileProvider')]
    public function test_required_file_exists(string $relativePath): void {
        $this->assertFileExists($this->root . '/' . $relativePath);
    }

    public static function requiredFileProvider(): array {
        return [
            'main plugin file' => ['scolta.php'],
            'uninstall' => ['uninstall.php'],
            'composer.json' => ['composer.json'],
            'tracker' => ['includes/class-scolta-tracker.php'],
            'content source' => ['includes/class-scolta-content-source.php'],
            'amazee config storage' => ['includes/class-scolta-amazee-config-storage.php'],
            'amazee budget handler' => ['includes/class-scolta-amazee-budget-handler.php'],
            'ai service' => ['includes/class-scolta-ai-service.php'],
            'cache driver' => ['includes/class-scolta-cache-driver.php'],
            'rest api' => ['includes/class-scolta-rest-api.php'],
            'shortcode' => ['includes/class-scolta-shortcode.php'],
            'admin' => ['admin/class-scolta-admin.php'],
            'amazee admin page' => ['admin/class-scolta-amazee-admin-page.php'],
            'cli' => ['cli/class-scolta-cli.php'],
        ];
    }

    // -------------------------------------------------------------------
    // Rename integrity — no stale references
    // -------------------------------------------------------------------

    public function test_no_scolta_core_wasm_references(): void {
        $stale = $this->grepSourceFiles('/scolta[-_]core[-_]wasm/i');
        $this->assertEmpty($stale,
            "Files still reference scolta-core-wasm:\n" . implode("\n", $stale));
    }

    public function test_no_old_package_name(): void {
        $stale = $this->grepSourceFiles('/"tag1\/scolta"/');
        $this->assertEmpty($stale,
            "Files still reference old package name:\n" . implode("\n", $stale));
    }

    public function test_no_old_vendor_paths(): void {
        $stale = $this->grepSourceFiles('/vendor\/tag1\/scolta\//');
        $this->assertEmpty($stale,
            "Files still reference old vendor path:\n" . implode("\n", $stale));
    }

    public function test_composer_requires_scolta_php(): void {
        $composer = json_decode(file_get_contents($this->root . '/composer.json'), true);
        $this->assertArrayHasKey('tag1/scolta-php', $composer['require'] ?? []);
    }

    public function test_shortcode_uses_correct_vendor_path(): void {
        $content = file_get_contents($this->root . '/includes/class-scolta-shortcode.php');
        // Should reference tag1/scolta-php, not tag1/scolta.
        if (str_contains($content, 'vendor/tag1/')) {
            $this->assertStringContainsString('tag1/scolta-php', $content);
        } else {
            // No vendor/tag1/ references found — verify that explicitly.
            $this->assertStringNotContainsString('vendor/tag1/', $content,
                'Shortcode file should not contain any vendor/tag1/ references if the branch was taken');
        }
    }

    // -------------------------------------------------------------------
    // Plugin constants
    // -------------------------------------------------------------------

    public function test_plugin_constants_defined(): void {
        $this->assertTrue(defined('SCOLTA_VERSION'));
        $this->assertTrue(defined('SCOLTA_PLUGIN_DIR'));
        $this->assertTrue(defined('SCOLTA_PLUGIN_URL'));
        $this->assertTrue(defined('SCOLTA_PLUGIN_FILE'));
    }

    public function test_version_constant_format(): void {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', SCOLTA_VERSION);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function grepSourceFiles(string $pattern): array {
        $hits = [];
        $dirs = ['includes', 'admin', 'cli'];
        $files = [$this->root . '/scolta.php', $this->root . '/uninstall.php'];

        foreach ($dirs as $dir) {
            $path = $this->root . '/' . $dir;
            if (is_dir($path)) {
                foreach (glob($path . '/*.php') as $f) {
                    $files[] = $f;
                }
            }
        }

        $exclude = ['tests', 'vendor'];
        foreach ($files as $file) {
            foreach ($exclude as $dir) {
                if (str_contains($file, '/' . $dir . '/')) continue 2;
            }
            if (preg_match($pattern, file_get_contents($file))) {
                $hits[] = str_replace($this->root . '/', '', $file);
            }
        }
        return $hits;
    }

    // -------------------------------------------------------------------
    // JS/CSS assets bundled directly in plugin
    // -------------------------------------------------------------------

    public function test_scolta_js_bundled_in_assets(): void {
        $jsPath = $this->root . '/assets/js/scolta.js';
        $this->assertFileExists($jsPath,
            'scolta.js must be bundled at assets/js/scolta.js');
        $this->assertNotEmpty(file_get_contents($jsPath));
    }

    public function test_scolta_css_bundled_in_assets(): void {
        $cssPath = $this->root . '/assets/css/scolta.css';
        $this->assertFileExists($cssPath,
            'scolta.css must be bundled at assets/css/scolta.css');
        $this->assertNotEmpty(file_get_contents($cssPath));
    }

    public function test_shortcode_references_correct_js_path(): void {
        $content = file_get_contents($this->root . '/includes/class-scolta-shortcode.php');
        $this->assertStringContainsString(
            "'assets/js/scolta.js'",
            $content,
            'Shortcode must reference scolta.js from bundled assets/ directory'
        );
    }

    // -------------------------------------------------------------------
    // Distribution scripts exist and are executable
    // -------------------------------------------------------------------

    public function test_build_dist_script_exists(): void {
        $this->assertFileExists($this->root . '/scripts/build-dist.sh');
    }

    public function test_validate_dist_script_exists(): void {
        $this->assertFileExists($this->root . '/scripts/validate-dist.sh');
    }

    // -------------------------------------------------------------------
    // Build script produces correct ZIP folder structure
    // -------------------------------------------------------------------

    public function test_build_script_creates_correct_zip_folder(): void {
        $script = file_get_contents($this->root . '/scripts/build-dist.sh');
        $this->assertStringContainsString(
            'PKG="scolta"',
            $script,
            'Build script must set PKG to scolta for the zip folder name'
        );
    }

    public function test_build_script_prunes_vendor_test_dirs(): void {
        $script = file_get_contents($this->root . '/scripts/build-dist.sh');
        $this->assertStringContainsString(
            '-name tests -o -name test',
            $script,
            'Build script must prune vendor test/ and tests/ directories from the staged archive'
        );
    }

    public function test_build_script_removes_vendor_wasm(): void {
        $script = file_get_contents($this->root . '/scripts/build-dist.sh');
        $this->assertStringContainsString(
            'vendor/tag1/scolta-php/assets/wasm',
            $script,
            'Build script must remove duplicate WASM from vendor/tag1/scolta-php/assets/wasm/'
        );
    }

    public function test_build_script_excludes_disallowed_extensions(): void {
        $script = file_get_contents($this->root . '/scripts/build-dist.sh');
        $this->assertStringContainsString(
            '*.sha256',
            $script,
            'Build script must delete .sha256 files from vendor'
        );
        $this->assertStringContainsString(
            '*.toml',
            $script,
            'Build script must delete .toml files from vendor'
        );
    }

    // -------------------------------------------------------------------
    // Validate script checks archive integrity
    // -------------------------------------------------------------------

    public function test_validate_script_checks_test_singular(): void {
        $script = file_get_contents($this->root . '/scripts/validate-dist.sh');
        $this->assertStringContainsString(
            "scolta/vendor/.+/test/",
            $script,
            'Validate script must check for vendor test/ directories (singular)'
        );
    }

    public function test_validate_script_checks_no_vendor_wasm(): void {
        $script = file_get_contents($this->root . '/scripts/validate-dist.sh');
        $this->assertStringContainsString(
            'scolta/vendor/tag1/scolta-php/assets/wasm/',
            $script,
            'Validate script must check that vendor WASM is excluded'
        );
    }

    public function test_validate_script_checks_nested_vendor(): void {
        $script = file_get_contents($this->root . '/scripts/validate-dist.sh');
        $this->assertStringContainsString(
            "scolta/vendor/[^/]+/vendor/",
            $script,
            'Validate script must check for nested vendor/ directories'
        );
    }

    public function test_validate_script_checks_size(): void {
        $script = file_get_contents($this->root . '/scripts/validate-dist.sh');
        $this->assertStringContainsString(
            'ZIP_SIZE',
            $script,
            'Validate script must include a ZIP size check'
        );
    }

    public function test_validate_script_checks_disallowed_extensions(): void {
        $script = file_get_contents($this->root . '/scripts/validate-dist.sh');
        $this->assertStringContainsString(
            '.sha256',
            $script,
            'Validate script must check for disallowed .sha256 files'
        );
        $this->assertStringContainsString(
            '.toml',
            $script,
            'Validate script must check for disallowed .toml files'
        );
    }

    // -------------------------------------------------------------------
    // Release ZIP bloat prevention
    // -------------------------------------------------------------------

    public function test_composer_lock_not_gitignored(): void {
        $gitignore = file_get_contents($this->root . '/.gitignore');
        $this->assertStringNotContainsString(
            'composer.lock',
            $gitignore,
            'composer.lock must not be gitignored — CI release workflow requires it for partial updates'
        );
    }

    public function test_composer_lock_exists(): void {
        $this->assertFileExists(
            $this->root . '/composer.lock',
            'composer.lock must be committed — CI release workflow requires it for partial updates'
        );
    }

    public function test_release_workflow_has_lock_guard(): void {
        $workflow = file_get_contents($this->root . '/.github/workflows/release.yml');
        $this->assertStringContainsString(
            'LOCK GUARD FAILED',
            $workflow,
            'Release workflow must include the scolta-php lock-source guard'
        );
    }

    public function test_release_workflow_calls_build_script(): void {
        $workflow = file_get_contents($this->root . '/.github/workflows/release.yml');
        $this->assertStringContainsString(
            'scripts/build-dist.sh',
            $workflow,
            'Release workflow must call scripts/build-dist.sh'
        );
    }

    public function test_release_workflow_calls_validate_script(): void {
        $workflow = file_get_contents($this->root . '/.github/workflows/release.yml');
        $this->assertStringContainsString(
            'scripts/validate-dist.sh',
            $workflow,
            'Release workflow must call scripts/validate-dist.sh'
        );
    }

    public function test_ci_has_dist_build_job(): void {
        $ci = file_get_contents($this->root . '/.github/workflows/ci.yml');
        $this->assertStringContainsString(
            'dist-build',
            $ci,
            'CI workflow must include a dist-build job to catch build regressions on PRs'
        );
    }

    public function test_release_workflow_has_wp_version_check(): void {
        $workflow = file_get_contents($this->root . '/.github/workflows/release.yml');
        $this->assertStringContainsString(
            'check-wp-version',
            $workflow,
            'Release workflow must include a check-wp-version job to prevent stale Tested up to values'
        );
    }
}
