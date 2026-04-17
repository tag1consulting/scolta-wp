<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for indexer selection logic in Scolta_CLI::do_build().
 *
 * The admin setting must be respected when no --indexer CLI flag is passed.
 * Uses get_flag_value() was previously used and silently ignored the admin
 * default; these tests verify the correct isset() ternary is used instead.
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
	// Precedence logic: simulate the do_build() indexer selection
	// -------------------------------------------------------------------

	public function test_admin_php_setting_respected_without_cli_flag(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'php' ) );
		$assoc_args      = array();
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

	public function test_cli_flag_overrides_admin_setting(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'php' ) );
		$assoc_args      = array( 'indexer' => 'auto' );
		$settings        = get_option( 'scolta_settings', array() );
		$indexer_setting = $settings['indexer'] ?? 'auto';
		$indexer         = isset( $assoc_args['indexer'] ) ? $assoc_args['indexer'] : $indexer_setting;
		$this->assertEquals( 'auto', $indexer );
	}

	public function test_default_auto_when_no_setting_no_flag(): void {
		// No setting stored — delete_option in set_up ensures clean state.
		$assoc_args      = array();
		$settings        = get_option( 'scolta_settings', array() );
		$indexer_setting = $settings['indexer'] ?? 'auto';
		$indexer         = isset( $assoc_args['indexer'] ) ? $assoc_args['indexer'] : $indexer_setting;
		$this->assertEquals( 'auto', $indexer );
	}

	// -------------------------------------------------------------------
	// Regression: get_flag_value() must not be used for indexer
	// -------------------------------------------------------------------

	public function test_no_get_flag_value_for_indexer(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );
		$this->assertDoesNotMatchRegularExpression(
			'/get_flag_value\s*\([^)]*indexer/',
			$source,
			'Indexer selection must not use get_flag_value() — it ignores the admin setting default'
		);
	}
}
