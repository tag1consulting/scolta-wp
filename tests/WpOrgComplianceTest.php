<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Repo-wide WordPress.org plugin-review compliance guards.
 *
 * The plugin-review team flags issue CLASSES across rounds ("we may not
 * share all cases of the same issue"), so these tests scan every shipped
 * first-party PHP file rather than only the file a reviewer happened to
 * cite. The per-file regression tests that carry the review citations
 * (e.g. CliValidationTest::test_cli_contains_no_ini_set_display_errors)
 * stay in place; these close the class.
 *
 * vendor/ is intentionally out of scope here — the shipped vendor tree is
 * swept by scripts/validate-dist.sh against the built zip, which is the
 * artifact reviewers actually receive.
 */
class WpOrgComplianceTest extends TestCase {

	/**
	 * ABSPATH uses that are legitimate in ANY shipped file.
	 *
	 * - defined( 'ABSPATH' ) — direct-access guards and existence checks.
	 * - require_once ABSPATH . 'wp-admin/...' — loading WordPress core
	 *   admin includes (upgrade.php for dbDelta, file.php for
	 *   WP_Filesystem) the way core documents it.
	 */
	private const ABSPATH_ALLOWED_GLOBAL = array(
		"/defined\\(\\s*'ABSPATH'\\s*\\)/",
		"/require_once\\s+ABSPATH\\s*\\.\\s*'wp-admin\\/[^']+'/",
	);

	/**
	 * Enumerated per-file ABSPATH allowances. Every entry must be a use of
	 * the SITE root (URL mapping, site-root tooling, legacy site-root
	 * cleanup) — never a way to locate plugin files. Patterns, not line
	 * numbers, so refactors that keep the construct don't churn this list.
	 */
	private const ABSPATH_ALLOWED_PER_FILE = array(
		'includes/class-scolta-shortcode.php' => array(
			// Site-root resolution for mapping index paths to URLs.
			'/realpath\\(\\s*ABSPATH\\s*\\)/',
			// Fallback when realpath() fails on the same resolution.
			'/\\$real_abspath\\s*:\\s*ABSPATH/',
		),
		'cli/class-scolta-cli.php'            => array(
			// Stale legacy-index warning for the pre-uploads default dir.
			"/rtrim\\(\\s*ABSPATH,\\s*'\\/'\\s*\\)\\s*\\.\\s*'\\/scolta-pagefind'/",
			// The warning's message text mentions ABSPATH as prose, inside
			// a string literal — not a use of the constant.
			'/Stale ABSPATH index/',
			// Site-root vendor/bin/wp lookup for the background resume.
			'/dirname\\(\\s*ABSPATH\\s*\\)/',
		),
		'scolta.php'                          => array(
			// Migration of the pre-uploads output_dir default.
			"/wp_normalize_path\\(\\s*ABSPATH\\s*\\.\\s*'scolta-pagefind'\\s*\\)/",
		),
	);

	/**
	 * Every shipped first-party PHP file: includes/, admin/, cli/, plus
	 * scolta.php and uninstall.php. tests/ is not shipped; vendor/ is
	 * covered by the dist-zip sweeps.
	 *
	 * @return array<string> Absolute paths, sorted.
	 */
	private function shipped_php_files(): array {
		$root  = dirname( __DIR__ );
		$files = array( $root . '/scolta.php', $root . '/uninstall.php' );

		foreach ( array( 'includes', 'admin', 'cli' ) as $dir ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && $file->getExtension() === 'php' ) {
					$files[] = $file->getPathname();
				}
			}
		}

		sort( $files );
		$this->assertNotEmpty( $files );
		return $files;
	}

	private function relative_path( string $absolute ): string {
		return ltrim( substr( $absolute, strlen( dirname( __DIR__ ) ) ), '/' );
	}

	private function is_comment_line( string $line ): bool {
		$trimmed = ltrim( $line );
		return str_starts_with( $trimmed, '//' )
			|| str_starts_with( $trimmed, '*' )
			|| str_starts_with( $trimmed, '/*' )
			|| str_starts_with( $trimmed, '#' );
	}

	// -------------------------------------------------------------------
	// Error-reporting configuration (WP.org review round 2: 18 instances
	// of ini_set('display_errors') in the CLI; the class is banned, not
	// the instances)
	// -------------------------------------------------------------------

	public function test_no_error_reporting_configuration_in_any_shipped_file(): void {
		$violations = array();

		foreach ( $this->shipped_php_files() as $file ) {
			$lines = explode( "\n", (string) file_get_contents( $file ) );
			foreach ( $lines as $number => $line ) {
				if ( preg_match( '/\bini_set\s*\(|\berror_reporting\s*\(|display_errors/', $line ) ) {
					$violations[] = $this->relative_path( $file ) . ':' . ( $number + 1 ) . '  ' . trim( $line );
				}
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"Shipped plugin code must not touch PHP error-reporting configuration.\n"
			. "ini_set(), error_reporting(), and display_errors are banned in every\n"
			. "shipped file (WP.org review round 2 flagged the CLI; this closes the class):\n"
			. implode( "\n", $violations )
		);
	}

	// -------------------------------------------------------------------
	// ABSPATH (WP.org review round 2: ABSPATH used to locate plugin files
	// in the REST health handler; plugin paths come from SCOLTA_PLUGIN_DIR)
	// -------------------------------------------------------------------

	public function test_abspath_is_never_used_to_locate_plugin_files(): void {
		$violations = array();

		foreach ( $this->shipped_php_files() as $file ) {
			$relative = $this->relative_path( $file );
			$patterns = self::ABSPATH_ALLOWED_GLOBAL;
			if ( isset( self::ABSPATH_ALLOWED_PER_FILE[ $relative ] ) ) {
				$patterns = array_merge( $patterns, self::ABSPATH_ALLOWED_PER_FILE[ $relative ] );
			}

			$lines = explode( "\n", (string) file_get_contents( $file ) );
			foreach ( $lines as $number => $line ) {
				if ( ! str_contains( $line, 'ABSPATH' ) || $this->is_comment_line( $line ) ) {
					continue;
				}
				// Strip every allowed construct, then fail if any ABSPATH
				// token survives — this catches a second, disallowed use
				// hiding on an otherwise-allowed line.
				$stripped = (string) preg_replace( $patterns, '', $line );
				if ( preg_match( '/\bABSPATH\b/', $stripped ) ) {
					$violations[] = $relative . ':' . ( $number + 1 ) . '  ' . trim( $line );
				}
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"ABSPATH must never locate plugin files — use SCOLTA_PLUGIN_DIR (WP.org review, round 2).\n"
			. "Allowed uses are defined( 'ABSPATH' ) guards, require_once of wp-admin core\n"
			. "includes, and the enumerated site-root patterns in this test. If a new use is\n"
			. "genuinely about the SITE root (never plugin files), add a pattern to\n"
			. "ABSPATH_ALLOWED_PER_FILE with a justification comment:\n"
			. implode( "\n", $violations )
		);
	}
}
