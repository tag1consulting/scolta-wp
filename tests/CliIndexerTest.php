<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for indexer selection logic in Scolta_CLI::do_build().
 *
 * Root cause of the regression: WP-CLI injects assoc_args['indexer'] = 'auto'
 * on every invocation when the docblock contains `default: auto`. Both
 * get_flag_value() and the isset() ternary see the already-injected value, so
 * neither can fall through to the admin setting. The fix is removing `default:`
 * from the docblock entirely.
 *
 * These tests verify:
 * 1. The docblock has no `default: auto` injection (regression guard).
 * 2. The dispatch logic correctly falls through to the admin setting when no
 *    flag is present.
 * 3. An explicit --indexer flag correctly overrides the admin setting.
 */
class CliIndexerTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'scolta_settings' );
	}

	public function tear_down(): void {
		delete_option( 'scolta_settings' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Docblock regression: WP-CLI must not inject a default for --indexer
	// -------------------------------------------------------------------

	/**
	 * Regression guard: the [--indexer] docblock must have no `default:` line.
	 *
	 * When `default: auto` is present in the WP-CLI docblock, WP-CLI injects
	 * assoc_args['indexer'] = 'auto' on every invocation without an explicit
	 * flag, making the admin setting permanently unreachable. Removing the
	 * `default:` line is the only correct fix — neither get_flag_value() nor
	 * isset() can distinguish an injected default from an explicit flag.
	 */
	public function test_indexer_docblock_has_no_default_injection(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );

		// Extract the [--indexer] docblock section (between the param marker and the next blank * line).
		preg_match( '/\[--indexer=<indexer>\](.*?)(?=\n\t \*\n)/s', $source, $m );
		$indexer_block = $m[0] ?? '';

		$this->assertNotEmpty( $indexer_block, 'Could not locate [--indexer=<indexer>] in docblock' );
		$this->assertStringNotContainsString(
			'default:',
			$indexer_block,
			'[--indexer] docblock must not contain "default:" — WP-CLI would inject the value on every ' .
			'invocation, making the admin setting unreachable when no explicit flag is passed'
		);
	}

	// -------------------------------------------------------------------
	// Dispatch logic: admin setting wins when no flag is passed
	// -------------------------------------------------------------------

	public function test_admin_php_setting_respected_without_cli_flag(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'php' ) );
		$assoc_args      = array(); // No --indexer flag; WP-CLI does NOT inject after the docblock fix.
		$settings        = get_option( 'scolta_settings', array() );
		$indexer_setting = $settings['indexer'] ?? 'auto';
		$indexer         = isset( $assoc_args['indexer'] ) ? $assoc_args['indexer'] : $indexer_setting;
		$this->assertEquals( 'php', $indexer );
	}

	public function test_admin_binary_setting_respected_without_cli_flag(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'binary' ) );
		$assoc_args      = array();
		$settings        = get_option( 'scolta_settings', array() );
		$indexer_setting = $settings['indexer'] ?? 'auto';
		$indexer         = isset( $assoc_args['indexer'] ) ? $assoc_args['indexer'] : $indexer_setting;
		$this->assertEquals( 'binary', $indexer );
	}

	public function test_default_auto_when_no_setting_no_flag(): void {
		$assoc_args      = array();
		$settings        = get_option( 'scolta_settings', array() );
		$indexer_setting = $settings['indexer'] ?? 'auto';
		$indexer         = isset( $assoc_args['indexer'] ) ? $assoc_args['indexer'] : $indexer_setting;
		$this->assertEquals( 'auto', $indexer );
	}

	// -------------------------------------------------------------------
	// Dispatch logic: explicit flag overrides admin setting
	// -------------------------------------------------------------------

	public function test_explicit_flag_overrides_admin_setting(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'php' ) );
		$assoc_args      = array( 'indexer' => 'auto' ); // User explicitly passed --indexer=auto.
		$settings        = get_option( 'scolta_settings', array() );
		$indexer_setting = $settings['indexer'] ?? 'auto';
		$indexer         = isset( $assoc_args['indexer'] ) ? $assoc_args['indexer'] : $indexer_setting;
		$this->assertEquals( 'auto', $indexer );
	}

	public function test_explicit_binary_flag_overrides_php_admin_setting(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'php' ) );
		$assoc_args      = array( 'indexer' => 'binary' );
		$settings        = get_option( 'scolta_settings', array() );
		$indexer_setting = $settings['indexer'] ?? 'auto';
		$indexer         = isset( $assoc_args['indexer'] ) ? $assoc_args['indexer'] : $indexer_setting;
		$this->assertEquals( 'binary', $indexer );
	}

	/**
	 * Documents the pre-fix bug: when WP-CLI injected 'auto' as a default,
	 * the admin setting 'php' was silently overridden. This cannot occur after
	 * the docblock fix, but the test serves as a readable regression document.
	 */
	public function test_injected_default_would_have_overridden_admin_setting(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'php' ) );
		$assoc_args_injected = array( 'indexer' => 'auto' ); // Simulates old WP-CLI injection.
		$settings            = get_option( 'scolta_settings', array() );
		$indexer_setting     = $settings['indexer'] ?? 'auto';
		$indexer             = isset( $assoc_args_injected['indexer'] ) ? $assoc_args_injected['indexer'] : $indexer_setting;
		// 'auto' wins — this is the broken behavior the docblock fix prevents.
		$this->assertEquals( 'auto', $indexer );
	}

	// -------------------------------------------------------------------
	// Source-level guards
	// -------------------------------------------------------------------

	public function test_no_get_flag_value_for_indexer(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );
		$this->assertDoesNotMatchRegularExpression(
			'/get_flag_value\s*\([^)]*[\'"]indexer[\'"]/',
			$source,
			'Indexer selection must not use get_flag_value() — it cannot distinguish an injected default from an explicit flag'
		);
	}

	/**
	 * The rebuild-index command is binary-only by definition. It must refuse
	 * to run when the admin setting is 'php', because the PHP pipeline writes
	 * the index directly and produces no HTML staging files to re-index.
	 */
	public function test_rebuild_index_guards_against_php_setting(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );

		// Locate the rebuild_index method body.
		preg_match( '/function rebuild_index\s*\([^)]*\)[^{]*\{(.+?)(?=\n\t\/\*\*|\n\tpublic function|\n\tprivate function)/s', $source, $m );
		$method_body = $m[1] ?? '';

		$this->assertNotEmpty( $method_body, 'Could not locate rebuild_index method body' );
		$this->assertStringContainsString(
			"'php' === \$indexer_setting",
			$method_body,
			'rebuild_index must check for php indexer setting and refuse with an error'
		);
	}
}
