<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Verifies that all canonical version strings are identical.
 *
 * The version appears in three places: the plugin header comment in
 * scolta.php, the SCOLTA_VERSION constant, and readme.txt "Stable Tag". All
 * three must match to prevent silent mismatches that ship the wrong version
 * to the WordPress admin or to WordPress.org.
 *
 * There used to be a fourth, a "version" key in composer.json. It is gone,
 * and testComposerJsonDeclaresNoVersion below keeps it gone: declaring a
 * version in a package published from version control overrides the version
 * Composer derives from the branch or tag, which is what the
 * extra.branch-alias beside it exists to describe. Packagist ignores the
 * declared string, but the drupal.org Composer facade honours it, which is
 * how the sibling Drupal adapter came to break `composer install` on every
 * site tracking a dev branch. Nothing here needs it declared: WordPress reads
 * the plugin header, WordPress.org reads the Stable Tag, and everything in CI
 * that needs the version reads it through scripts/plugin-version.sh.
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
	 * Read the version the way CI does, through scripts/plugin-version.sh.
	 */
	private static function read_plugin_version_script(): string {
		$script = dirname( __DIR__ ) . '/scripts/plugin-version.sh';
		return trim( (string) shell_exec( 'bash ' . escapeshellarg( $script ) . ' 2>/dev/null' ) );
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

	/**
	 * composer.json must not declare a "version".
	 *
	 * A declared version overrides the one Composer derives from the branch or
	 * tag. Packagist ignores it, but the drupal.org Composer facade does not,
	 * and the sibling Drupal adapter broke a client build on 2026-07-27 for
	 * exactly this reason: the package announced a fixed version string
	 * regardless of branch, so a consuming site could `composer update` but
	 * never `composer install` from the resulting lock.
	 */
	public function test_composer_json_declares_no_version(): void {
		$composer = json_decode(
			file_get_contents( dirname( __DIR__ ) . '/composer.json' ),
			true
		);
		$this->assertArrayNotHasKey(
			'version',
			$composer,
			'composer.json must not declare a version. The plugin header in scolta.php ' .
			'is the source of the plugin version; extra.branch-alias describes the ' .
			'dev-main mapping.'
		);
	}

	/**
	 * Everything in CI that needs the version reads it through this script, so
	 * it must agree with the constant. A silent disagreement would name the
	 * plugin zip after a version the plugin does not report.
	 */
	public function test_plugin_version_script_matches_constant(): void {
		$this->assertSame(
			SCOLTA_VERSION,
			self::read_plugin_version_script(),
			'scripts/plugin-version.sh must report the SCOLTA_VERSION constant'
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
