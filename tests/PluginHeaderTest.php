<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the scolta.php plugin header.
 *
 * WordPress reads plugin metadata from the header comment. Multi-line
 * description values are silently truncated — only the first line is parsed.
 * These tests prevent that regression from recurring.
 */
class PluginHeaderTest extends TestCase {

	private string $plugin_file;
	private string $plugin_source;

	public function set_up(): void {
		parent::set_up();
		$this->plugin_file   = dirname( __DIR__ ) . '/scolta.php';
		$this->plugin_source = file_get_contents( $this->plugin_file );
	}

	// -------------------------------------------------------------------------
	// Description header format
	// -------------------------------------------------------------------------

	/**
	 * The Description: header must contain a complete sentence (ends with period or similar).
	 *
	 * Pre-fix: description ends with "Uses Pagefind for" — incomplete phrase — FAIL.
	 * Post-fix: single-line, ends with a period — PASS.
	 */
	public function test_description_is_single_line(): void {
		preg_match( '/\* Description:\s+(.+)$/m', $this->plugin_source, $matches );
		$this->assertNotEmpty( $matches, 'scolta.php must contain a Description: header line' );

		$description = trim( $matches[1] );

		// Must end with sentence-terminal punctuation, not a preposition or fragment.
		$this->assertMatchesRegularExpression(
			'/[.!?]$/',
			$description,
			"Description must end with a sentence-terminal character (period, !, ?). Got: '{$description}'"
		);
	}

	/**
	 * The Description value must be short enough to display on the Plugins page.
	 *
	 * WordPress displays up to ~150 characters cleanly in the plugin list.
	 * Longer descriptions may be truncated with "..." in the narrow column view.
	 *
	 * Pre-fix: N/A (description was cut at "for" — 0 chars past the limit).
	 * Post-fix: single-line description must fit within 150 characters.
	 */
	public function test_description_length_under_limit(): void {
		preg_match( '/\* Description:\s+(.+)$/m', $this->plugin_source, $matches );
		$this->assertNotEmpty( $matches, 'scolta.php must contain a Description: header line' );

		$description = trim( $matches[1] );
		$length      = strlen( $description );

		$this->assertLessThanOrEqual(
			150,
			$length,
			"Description is {$length} chars, exceeds 150-char safe limit: '{$description}'"
		);
	}

	/**
	 * Source-parse: the line following Description: must not be a continuation line.
	 *
	 * A continuation line is one that starts with " * " followed by a lowercase letter
	 * (or indented text), indicating multi-line header format that WordPress ignores.
	 *
	 * Pre-fix: next line is " *                   client-side full-text..." — continuation — FAIL.
	 * Post-fix: Description is on a single line, no continuation — PASS.
	 */
	public function test_description_does_not_span_multiple_lines(): void {
		// Split source into lines and find the Description: line index.
		$lines       = explode( "\n", $this->plugin_source );
		$desc_line   = -1;
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/\* Description:\s+\S/', $line ) ) {
				$desc_line = $i;
				break;
			}
		}

		$this->assertGreaterThan( -1, $desc_line, 'Must find Description: header in scolta.php' );

		// Check the NEXT non-empty line isn't a continuation.
		$next_line = $lines[ $desc_line + 1 ] ?? '';

		$this->assertDoesNotMatchRegularExpression(
			'/^\s+\*\s+[a-z]/',
			$next_line,
			'Line after Description: must not be a continuation (multi-line descriptions are truncated by WordPress). ' .
			"Next line: '{$next_line}'"
		);
	}
}
