<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Verifies that all canonical version strings are identical.
 *
 * The version appears in four places: the plugin header comment in scolta.php,
 * the SCOLTA_VERSION constant, composer.json "version", and readme.txt
 * "Stable Tag". All four must match to prevent silent mismatches that ship
 * the wrong version to WordPress admin, Composer, or WordPress.org.
 */
class VersionConsistencyTest extends TestCase {

	/**
	 * Parse the Version header from scolta.php without loading the file.
	 */
	private static function read_plugin_header_version(): string {
		$plugin_file = dirname( __DIR__ ) . '/scolta.php';
		$contents    = file_get_contents( $plugin_file, false, null, 0, 2048 );
		if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $contents, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Parse "version" from composer.json.
	 */
	private static function read_composer_version(): string {
		$composer = json_decode(
			file_get_contents( dirname( __DIR__ ) . '/composer.json' ),
			true
		);
		return $composer['version'] ?? '';
	}

	/**
	 * Parse "Stable Tag" from readme.txt.
	 */
	private static function read_readme_stable_tag(): string {
		$readme = dirname( __DIR__ ) . '/readme.txt';
		$contents = file_get_contents( $readme, false, null, 0, 2048 );
		if ( preg_match( '/^Stable Tag:\s*(.+)$/mi', $contents, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	public function test_plugin_header_matches_constant(): void {
		$header = self::read_plugin_header_version();
		$this->assertSame(
			SCOLTA_VERSION,
			$header,
			'Plugin header Version: must match the SCOLTA_VERSION constant'
		);
	}

	public function test_composer_json_matches_constant(): void {
		$composer = self::read_composer_version();
		$this->assertSame(
			SCOLTA_VERSION,
			$composer,
			'composer.json "version" must match the SCOLTA_VERSION constant'
		);
	}

	public function test_readme_stable_tag_matches_constant(): void {
		$stable_tag = self::read_readme_stable_tag();
		$this->assertSame(
			SCOLTA_VERSION,
			$stable_tag,
			'readme.txt Stable Tag must match the SCOLTA_VERSION constant'
		);
	}

	public function test_version_is_non_empty(): void {
		$this->assertNotEmpty( SCOLTA_VERSION, 'SCOLTA_VERSION must not be empty' );
	}
}
