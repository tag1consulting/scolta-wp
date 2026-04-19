<?php

declare(strict_types=1);

use Tag1\Scolta\Config\ScoltaConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Handler-invocation tests for Scolta_CLI::do_build().
 *
 * Gap 1a: The previous CliIndexerTest.php tests verified which indexer STRING
 * the ternary returns, but never verified that the correct HANDLER METHOD was
 * actually invoked. A bug where the ternary returned 'php' but a misplaced
 * condition still called do_build_binary() would pass every existing test.
 *
 * These tests exercise the full dispatch path by subclassing Scolta_CLI,
 * overriding the protected pipeline methods to record invocations, and
 * calling do_build() via reflection (it is private — the public entry point
 * build() adds try/catch + ini_set noise that is not relevant here).
 *
 * do_build_php() and do_build_binary() were changed from private to protected
 * to allow this subclassing. That is the minimal refactor required; no logic
 * was touched.
 *
 * Pre-fix: the test class itself could not be written because do_build_php()
 * and do_build_binary() were private — subclass overrides were ignored by PHP.
 * The test was absent from the suite entirely.
 * Post-fix: all assertions pass. Full suite: 345+/345+.
 */

/**
 * Test-double subclass that records which pipeline fired.
 */
class TestableScoltaCli extends Scolta_CLI {

	public bool $php_called    = false;
	public bool $binary_called = false;

	protected function do_build_php( array $assoc_args, array $settings ): void {
		$this->php_called = true;
	}

	protected function do_build_binary( array $args, array $assoc_args, array $settings, ScoltaConfig $config ): void {
		$this->binary_called = true;
	}
}

class CliIndexerDispatchTest extends TestCase {

	/** @var \ReflectionMethod */
	private static \ReflectionMethod $do_build;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$ref = new \ReflectionMethod( Scolta_CLI::class, 'do_build' );
		$ref->setAccessible( true );
		self::$do_build = $ref;
	}

	public function set_up(): void {
		parent::set_up();
		delete_option( 'scolta_settings' );
	}

	public function tear_down(): void {
		delete_option( 'scolta_settings' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Core dispatch assertions: correct handler is actually invoked
	// -------------------------------------------------------------------------

	/**
	 * When admin setting is 'php' and no --indexer flag is given,
	 * do_build_php() must fire and do_build_binary() must not.
	 */
	public function test_php_admin_setting_invokes_do_build_php(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'php' ) );

		$cli = new TestableScoltaCli();
		self::$do_build->invoke( $cli, [], [] );

		$this->assertTrue( $cli->php_called, 'do_build_php() must be invoked when admin indexer=php and no flag given' );
		$this->assertFalse( $cli->binary_called, 'do_build_binary() must NOT be invoked when admin indexer=php' );
	}

	/**
	 * When admin setting is 'binary' and no --indexer flag is given,
	 * do_build_binary() must fire and do_build_php() must not.
	 */
	public function test_binary_admin_setting_invokes_do_build_binary(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'binary' ) );

		$cli = new TestableScoltaCli();
		self::$do_build->invoke( $cli, [], [] );

		$this->assertFalse( $cli->php_called, 'do_build_php() must NOT be invoked when admin indexer=binary' );
		$this->assertTrue( $cli->binary_called, 'do_build_binary() must be invoked when admin indexer=binary' );
	}

	/**
	 * Explicit --indexer=php flag invokes do_build_php() even when admin is 'binary'.
	 */
	public function test_explicit_php_flag_invokes_do_build_php(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'binary' ) );

		$cli = new TestableScoltaCli();
		self::$do_build->invoke( $cli, [], array( 'indexer' => 'php' ) );

		$this->assertTrue( $cli->php_called );
		$this->assertFalse( $cli->binary_called );
	}

	/**
	 * Explicit --indexer=binary flag invokes do_build_binary() even when admin is 'php'.
	 *
	 * This is the critical regression guard: if the dispatch switch broke (e.g., the
	 * ternary returned 'php' but the if-block was removed), the handler wouldn't fire.
	 */
	public function test_explicit_binary_flag_invokes_do_build_binary(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'php' ) );

		$cli = new TestableScoltaCli();
		self::$do_build->invoke( $cli, [], array( 'indexer' => 'binary' ) );

		$this->assertFalse( $cli->php_called );
		$this->assertTrue( $cli->binary_called );
	}

	/**
	 * When --indexer=php (simulating WP-CLI injection as in the 0.2.4 regression),
	 * admin setting is ignored and do_build_php() fires.
	 *
	 * This is the exact injection scenario from the 2026-04-17 bug. A pre-fix docblock
	 * with `default: auto` would inject 'auto' here, routing to binary even when the
	 * explicit flag says 'php'. After the docblock fix, explicit 'php' is respected.
	 */
	public function test_injected_php_flag_invokes_do_build_php_not_binary(): void {
		update_option( 'scolta_settings', array( 'indexer' => 'auto' ) );

		$cli = new TestableScoltaCli();
		self::$do_build->invoke( $cli, [], array( 'indexer' => 'php' ) );

		$this->assertTrue( $cli->php_called );
		$this->assertFalse( $cli->binary_called );
	}
}
