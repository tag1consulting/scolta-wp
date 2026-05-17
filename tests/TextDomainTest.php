<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Verifies the plugin text domain matches the plugin slug 'scolta-ai-search'.
 *
 * WordPress.org requires the text domain in all gettext/i18n calls to match
 * the plugin slug so community translations work via translate.wordpress.org.
 */
class TextDomainTest extends TestCase {

	private string $root;

	protected function set_up(): void {
		$this->root = dirname( __DIR__ );
	}

	// -------------------------------------------------------------------------
	// Plugin header
	// -------------------------------------------------------------------------

	/**
	 * The Text Domain: header in scolta.php must be 'scolta-ai-search'.
	 */
	public function test_plugin_header_text_domain_matches_slug(): void {
		$source = file_get_contents( $this->root . '/scolta.php' );
		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*Text Domain:\s*scolta-ai-search\s*$/m',
			$source,
			"scolta.php plugin header must contain: Text Domain: scolta-ai-search"
		);
	}

	/**
	 * The old text domain 'scolta' must not appear in the Text Domain: header.
	 */
	public function test_plugin_header_does_not_use_old_text_domain(): void {
		$source = file_get_contents( $this->root . '/scolta.php' );
		$this->assertDoesNotMatchRegularExpression(
			'/^\s*\*\s*Text Domain:\s*scolta\b(?!-)/m',
			$source,
			"scolta.php plugin header must not use the old text domain 'scolta' without '-ai-search'"
		);
	}

	// -------------------------------------------------------------------------
	// i18n function calls across all PHP files
	// -------------------------------------------------------------------------

	/**
	 * Every i18n function call must use 'scolta-ai-search' as the text domain,
	 * and no call may use the old 'scolta' domain.
	 *
	 * This is a source-parse regression test: it would have caught the original
	 * bug (230+ calls using the wrong domain) and will catch any future regression.
	 *
	 * Checks line-by-line to avoid regex crossing function boundaries. A line
	 * that contains an i18n function call is classified as:
	 *   - correct: line also contains 'scolta-ai-search' (the expected domain)
	 *   - wrong: line does NOT contain 'scolta-ai-search' but does contain
	 *            'scolta' followed by optional whitespace and ')' — the old domain pattern
	 *
	 * The elseif prevents false positives on lines like:
	 *   add_settings_section( 'id', __( 'title', 'scolta-ai-search' ), cb, 'scolta' );
	 * where 'scolta' is a page slug on the same line as a correct i18n call.
	 */
	public function test_all_i18n_calls_use_correct_text_domain(): void {
		$correct_domain_count = 0;
		$wrong_domain_hits    = [];

		$i18n_pattern       = "/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_n|_x|_ex|_nx)\s*\(/";
		$correct_domain     = "/'scolta-ai-search'/";
		$old_domain_pattern = "/'scolta'\s*\)/";

		foreach ( $this->get_source_files() as $file ) {
			$relative = str_replace( $this->root . '/', '', $file );
			$lines    = explode( "\n", file_get_contents( $file ) );

			foreach ( $lines as $line_no => $line ) {
				if ( ! preg_match( $i18n_pattern, $line ) ) {
					continue;
				}

				if ( preg_match( $correct_domain, $line ) ) {
					++$correct_domain_count;
				} elseif ( preg_match( $old_domain_pattern, $line ) ) {
					$wrong_domain_hits[] = $relative . ':' . ( $line_no + 1 );
				}
			}
		}

		$this->assertGreaterThan(
			0,
			$correct_domain_count,
			"Expected to find i18n calls with 'scolta-ai-search' text domain, but found none — test may be misconfigured"
		);

		$this->assertEmpty(
			$wrong_domain_hits,
			"The following lines use the old 'scolta' text domain in i18n function calls:\n" .
			implode( "\n", $wrong_domain_hits )
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns PHP source files outside vendor/ and tests/.
	 *
	 * @return string[]
	 */
	private function get_source_files(): array {
		$files = [ $this->root . '/scolta.php', $this->root . '/uninstall.php' ];
		foreach ( [ 'admin', 'cli', 'includes' ] as $dir ) {
			$path = $this->root . '/' . $dir;
			if ( is_dir( $path ) ) {
				foreach ( glob( $path . '/*.php' ) as $f ) {
					$files[] = $f;
				}
			}
		}
		return $files;
	}
}
