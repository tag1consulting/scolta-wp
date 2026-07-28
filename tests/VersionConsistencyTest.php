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
	 * Every workflow step that needs the version, and where it reads it from.
	 *
	 * @return array<string, string> workflow file => file contents
	 */
	private static function workflows(): array {
		$dir = dirname( __DIR__ ) . '/.github/workflows';
		$out = [];
		foreach ( [ 'ci.yml', 'release.yml' ] as $name ) {
			$out[ $name ] = file_get_contents( $dir . '/' . $name );
		}
		return $out;
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
	 * No workflow may go back to reading the version out of composer.json.
	 *
	 * Five steps used to hand-roll the same
	 * `php -r "echo json_decode(file_get_contents('composer.json'))->version;"`,
	 * which is what made the hardcoded key load-bearing in the first place.
	 * They now all read scripts/plugin-version.sh, so there is one place to
	 * change if the source ever moves — and a job that quietly reverted would
	 * fail at runtime with an empty version rather than being caught here.
	 */
	public function test_no_workflow_reads_the_version_from_composer_json(): void {
		foreach ( self::workflows() as $name => $yaml ) {
			$this->assertStringNotContainsString(
				"composer.json'))->version",
				$yaml,
				"{$name} must not read the version from composer.json; composer.json " .
				'declares none. Use scripts/plugin-version.sh.'
			);
		}
	}

	/**
	 * scripts/plugin-version.sh must read the plugin header, which is the
	 * source of the version. Not executed here: the shipped jobs run it, and
	 * `scripts/build-dist.sh "$VERSION"` names the archive from its output, so
	 * an empty or wrong result surfaces there. This pins what it reads.
	 */
	public function test_plugin_version_script_reads_the_plugin_header(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/scripts/plugin-version.sh' );

		// Executable lines only. The header comment explains why composer.json
		// is not the source, so asserting over the whole file would trip on
		// its own rationale.
		$code = implode( "\n", array_filter(
			explode( "\n", $script ),
			static fn ( string $line ): bool => ! preg_match( '/^\s*(#|$)/', $line )
		) );

		$this->assertStringContainsString( 'scolta.php', $code,
			'plugin-version.sh must read the plugin header in scolta.php' );
		$this->assertStringContainsString( 'Version:', $code,
			'plugin-version.sh must extract the Version: header' );
		$this->assertStringNotContainsString( 'composer.json', $code,
			'plugin-version.sh must not fall back to composer.json, which declares no version' );
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
