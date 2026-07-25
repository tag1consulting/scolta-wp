<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Stay-in-sync guard between what the browser reads and what this plugin emits.
 *
 * assets/js/scolta.js is a byte-identical copy of the canonical bundle in
 * scolta-php. Every config value it consumes is read off the instance config
 * object that Scolta_Shortcode::render() passes to wp_localize_script(), so the
 * two are a contract: a key the bundle reads but no config layer emits is a
 * feature that is dead on arrival, and a key this plugin emits but the bundle
 * never reads is dead weight in every page payload.
 *
 * filterFieldDescriptions was exactly that dead-on-arrival case. WordPress had a
 * full admin surface for it (field registration, renderer, sanitizer) and the
 * REST layer already passed it to the AI expansion prompt, but the localize array
 * omitted it, so the browser feature never worked. Nothing caught it because
 * nothing asserted that the emitted config covered what the browser reads.
 *
 * This test parses the committed bundle for the keys it reads and diffs them
 * against the localized array, in both directions, recursing one level into the
 * `scoring` and `endpoints` sub-arrays. Asserting only the top level is not
 * enough: those two are arrays, so a top-level presence check passes while a
 * scoring sub-key is missing, which is how three scoring keys hid in scolta-php.
 *
 * There is no tests/js directory, no package.json and no Jest rig in this repo,
 * and no CI job that would run one, so this guard is PHP by necessity.
 *
 * Two deliberate design choices, shared with the other four implementations:
 *
 * - Comments are NOT stripped before matching. Naively cutting `//` to end of
 *   line would corrupt every line containing a URL such as `https://` and could
 *   silently drop a real key. Today exactly one comment names a config key
 *   (`instanceConfig.currentLanguage`) and that key is real, so comment noise
 *   produces zero phantoms. If a future comment does introduce a phantom, this
 *   test fails loudly and the maintainer either emits the key or adds it to an
 *   allowlist with a written justification. Loud and occasionally wrong beats
 *   silent and blind.
 * - The reverse assertion uses strict set membership against the extracted key
 *   set, not a substring search of the bundle. A substring search over 3,300
 *   lines matches almost any plausible camelCase name and would make the
 *   assertion worthless.
 *
 * The parse is deliberately strict: the tripwire assertions run BEFORE any diff,
 * so a reformat of scolta.js that stops the extraction matching fails loudly
 * instead of passing while asserting nothing.
 */
class BrowserConfigParityTest extends TestCase {

	/**
	 * Keys scolta.js reads that the localize array deliberately does not emit.
	 *
	 * Subtracts from the extracted set, so it may only ever contain keys the
	 * bundle actually reads.
	 */
	private const FORWARD_ALLOWLIST = array(
		// Emitted by no adapter at all; supplied only by a direct caller through
		// the scolta-php createInstance() public API. Note the snake_case name,
		// unlike every other top-level key.
		'priority_pages',
	);

	/**
	 * Keys the plugin emits that scolta.js does not read off the instance config.
	 *
	 * Subtracts from the emitted set, so it may only ever contain keys this
	 * plugin actually emits.
	 */
	private const REVERSE_ALLOWLIST = array(
		// Read only by autoInit() off the global window.scolta, never off the
		// instance config, so it is correctly absent from the extracted set and
		// belongs in no forward allowlist.
		'container',
		// Emitted for the WordPress REST API (wp_create_nonce). Appears nowhere in
		// scolta.js, which reads it off the global when posting to the endpoints.
		'nonce',
	);

	protected function set_up(): void {
		$GLOBALS['wp_options']                = array();
		$GLOBALS['scolta_enqueued_scripts']   = array();
		$GLOBALS['scolta_enqueued_styles']    = array();
		$GLOBALS['scolta_localized_scripts']  = array();

		scolta_activate();

		// render() bails out early when the index is missing, so seed a fake one
		// the same way ShortcodeTest::set_up() does.
		$settings   = get_option( 'scolta_settings', array() );
		$output_dir = ( $settings['output_dir'] ?? scolta_default_output_dir() );
		$index_dir  = $output_dir . '/pagefind';
		if ( ! is_dir( $index_dir ) ) {
			@mkdir( $index_dir, 0755, true );
		}
		if ( ! file_exists( $index_dir . '/pagefind-entry.json' ) ) {
			file_put_contents( $index_dir . '/pagefind-entry.json', '{}' );
		}
	}

	protected function tear_down(): void {
		unset(
			$GLOBALS['scolta_enqueued_scripts'],
			$GLOBALS['scolta_enqueued_styles'],
			$GLOBALS['scolta_localized_scripts']
		);
	}

	/**
	 * The localized browser config, as wp_localize_script() received it.
	 */
	private function emittedConfig(): array {
		Scolta_Shortcode::render();

		$this->assertArrayHasKey(
			'scolta-search',
			$GLOBALS['scolta_localized_scripts'],
			'Scolta_Shortcode::render() localized nothing for the scolta-search handle. '
			. 'It bails out early when the Pagefind index is missing — check the fixture in set_up().'
		);

		return $GLOBALS['scolta_localized_scripts']['scolta-search'];
	}

	/**
	 * The committed browser bundle as text.
	 */
	private function bundleSource(): string {
		$source = file_get_contents( dirname( __DIR__ ) . '/assets/js/scolta.js' );
		$this->assertNotFalse( $source, 'Unable to read assets/js/scolta.js' );

		return $source;
	}

	// ------------------------------------------------------------------
	// Forward: everything the browser reads must be emitted
	// ------------------------------------------------------------------

	public function test_browser_read_top_level_keys_are_emitted(): void {
		$emitted = $this->emittedConfig();
		$read    = $this->extractTopLevelKeys( $this->bundleSource() );

		foreach ( array_diff( $read, self::FORWARD_ALLOWLIST ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$emitted,
				sprintf(
					'scolta.js reads instanceConfig.%s but the wp_localize_script() array does not '
					. 'emit it, so the feature behind it is unreachable. Either emit the key or add '
					. 'it to %s::FORWARD_ALLOWLIST with a written justification.',
					$key,
					__CLASS__
				)
			);
		}
	}

	public function test_browser_read_scoring_keys_are_emitted(): void {
		$emitted = $this->emittedConfig();
		$this->assertArrayHasKey( 'scoring', $emitted, 'No scoring array was emitted at all.' );

		foreach ( $this->extractScoringKeys( $this->bundleSource() ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$emitted['scoring'],
				sprintf(
					'scolta.js reads scoring key %s but it is absent from the emitted scoring '
					. 'array, so it can only ever take its hardcoded JS fallback. Add an admin '
					. 'field, a renderer and a sanitizer line for it.',
					$key
				)
			);
		}
	}

	public function test_browser_read_endpoint_keys_are_emitted(): void {
		$emitted = $this->emittedConfig();
		$this->assertArrayHasKey( 'endpoints', $emitted, 'No endpoints array was emitted at all.' );

		foreach ( $this->extractEndpointKeys( $this->bundleSource() ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$emitted['endpoints'],
				sprintf( 'scolta.js reads endpoint %s but it is absent from the emitted endpoints array.', $key )
			);
		}
	}

	// ------------------------------------------------------------------
	// Reverse: nothing emitted should be dead
	// ------------------------------------------------------------------

	/**
	 * Separate from the forward assertions so it can be allowlisted independently.
	 */
	public function test_emitted_top_level_keys_are_read_by_the_browser(): void {
		$emitted = $this->emittedConfig();
		$read    = $this->extractTopLevelKeys( $this->bundleSource() );

		foreach ( array_diff( array_keys( $emitted ), self::REVERSE_ALLOWLIST ) as $key ) {
			$this->assertContains(
				$key,
				$read,
				sprintf(
					'The wp_localize_script() array emits %s but scolta.js never reads it off the '
					. 'instance config, so it is dead weight in every page payload. Either drop it '
					. 'or add it to %s::REVERSE_ALLOWLIST with a written justification.',
					$key,
					__CLASS__
				)
			);
		}
	}

	// ------------------------------------------------------------------
	// Extraction (tripwired)
	// ------------------------------------------------------------------

	/**
	 * Distinct top-level keys read as `instanceConfig.<key>`.
	 */
	private function extractTopLevelKeys( string $source ): array {
		preg_match_all( '/instanceConfig\.([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches );
		$keys = array_values( array_unique( $matches[1] ) );

		$this->assertGreaterThanOrEqual(
			11,
			count( $keys ),
			'Parsed too few top-level config reads from assets/js/scolta.js — the bundle may have '
			. 'been reformatted so `instanceConfig.<key>` no longer matches. Update the parser in '
			. __CLASS__ . ' so the guard keeps working.'
		);

		return $keys;
	}

	/**
	 * Distinct scoring keys read as `KEY: s.KEY ??` in the config return literals.
	 *
	 * The regex matches two return literals, the module-level getConfig() block
	 * and the getInstanceConfig() block, and their union is the full set only
	 * because the former's keys are a strict subset of the latter's. That holds
	 * today; if it ever stops holding, the tripwire count below moves and whoever
	 * hits it reads this note.
	 *
	 * Parsing the literals rather than grepping consumption sites is deliberate:
	 * several keys are forwarded to WASM wholesale and never named at a use site,
	 * so a consumption-site grep would silently miss them.
	 */
	private function extractScoringKeys( string $source ): array {
		preg_match_all( '/^\s*([A-Z][A-Z0-9_]*):\s*s\.\1\s*\?\?/m', $source, $matches );
		$keys = array_values( array_unique( $matches[1] ) );

		$this->assertGreaterThanOrEqual(
			40,
			count( $keys ),
			'Parsed too few scoring keys from assets/js/scolta.js — the getInstanceConfig() return '
			. 'literal may have been reformatted so `KEY: s.KEY ??` no longer matches. Update the '
			. 'parser in ' . __CLASS__ . ' so the guard keeps working.'
		);

		return $keys;
	}

	/**
	 * Distinct endpoint keys read as `key: e.key ||`.
	 */
	private function extractEndpointKeys( string $source ): array {
		preg_match_all( '/^\s*([a-z]+):\s*e\.\1\s*\|\|/m', $source, $matches );
		$keys = array_values( array_unique( $matches[1] ) );

		$this->assertCount(
			3,
			$keys,
			'Expected exactly 3 endpoint keys in assets/js/scolta.js (expand, summarize, followup) '
			. 'but parsed ' . count( $keys ) . '. Either an endpoint was added or the bundle was '
			. 'reformatted so `key: e.key ||` no longer matches. Update the parser in '
			. __CLASS__ . ' so the guard keeps working.'
		);

		return $keys;
	}
}
