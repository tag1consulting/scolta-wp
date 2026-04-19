<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Verifies that the three canonical version strings are identical.
 *
 * Gap 1b: scolta.php header comment, SCOLTA_VERSION constant, and
 * composer.json "version" field were all 0.2.3 while work was already
 * targeting 0.2.4. A mismatch silently ships the wrong version string to
 * WP admin and to Composer.
 *
 * Pre-fix: header said 0.2.3, constant said 0.2.3, composer.json said 0.2.3
 * while the release target was 0.2.4-dev — test would fail if the strings
 * diverged again.
 * Post-fix: all three agree.
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

	public function test_version_is_non_empty(): void {
		$this->assertNotEmpty( SCOLTA_VERSION, 'SCOLTA_VERSION must not be empty' );
	}
}
