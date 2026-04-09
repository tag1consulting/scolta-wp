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
            'ai service' => ['includes/class-scolta-ai-service.php'],
            'cache driver' => ['includes/class-scolta-cache-driver.php'],
            'rest api' => ['includes/class-scolta-rest-api.php'],
            'shortcode' => ['includes/class-scolta-shortcode.php'],
            'admin' => ['admin/class-scolta-admin.php'],
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
    // JS/CSS assets accessible via vendor path
    // -------------------------------------------------------------------

    public function test_scolta_js_accessible_via_vendor(): void {
        $jsPath = $this->root . '/vendor/tag1/scolta-php/assets/js/scolta.js';
        $this->assertFileExists($jsPath,
            'scolta.js must be accessible at vendor/tag1/scolta-php/assets/js/scolta.js');
        $this->assertNotEmpty(file_get_contents($jsPath));
    }

    public function test_scolta_css_accessible_via_vendor(): void {
        $cssPath = $this->root . '/vendor/tag1/scolta-php/assets/css/scolta.css';
        $this->assertFileExists($cssPath,
            'scolta.css must be accessible at vendor/tag1/scolta-php/assets/css/scolta.css');
        $this->assertNotEmpty(file_get_contents($cssPath));
    }

    public function test_shortcode_references_correct_js_path(): void {
        $content = file_get_contents($this->root . '/includes/class-scolta-shortcode.php');
        $this->assertStringContainsString(
            'vendor/tag1/scolta-php/assets/js/scolta.js',
            $content,
            'Shortcode must reference scolta.js from vendor/tag1/scolta-php/assets/'
        );
    }
}
