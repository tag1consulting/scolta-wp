<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the `wp scolta diagnose` command (Scolta_CLI::diagnose).
 *
 * The command depends on live WordPress functions and cannot be exercised
 * end-to-end in the stub environment, so these tests cover the pure-PHP
 * helpers and verify structural contracts via reflection.
 */
class DiagnoseCommandTest extends TestCase {

	private Scolta_CLI $cli;

	protected function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'Scolta_CLI' ) ) {
			require_once dirname( __DIR__ ) . '/cli/class-scolta-cli.php';
		}
		$this->cli = new Scolta_CLI();
	}

	// -------------------------------------------------------------------------
	// Structural: subcommand and helper exist
	// -------------------------------------------------------------------------

	public function test_diagnose_method_exists(): void {
		$this->assertTrue(
			method_exists( 'Scolta_CLI', 'diagnose' ),
			'Scolta_CLI must have a diagnose() method'
		);
	}

	public function test_format_duration_method_exists(): void {
		$this->assertTrue(
			method_exists( 'Scolta_CLI', 'format_duration' ),
			'Scolta_CLI must have a format_duration() helper'
		);
	}

	public function test_diagnose_docblock_declares_subcommand(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );
		$this->assertStringContainsString(
			'@subcommand diagnose',
			$source,
			'diagnose() must declare @subcommand diagnose'
		);
	}

	// -------------------------------------------------------------------------
	// format_duration — pure PHP, fully testable
	// -------------------------------------------------------------------------

	public function test_format_duration_seconds_only(): void {
		$this->assertSame( '45s', $this->cli->format_duration( 45.0 ) );
	}

	public function test_format_duration_rounds_to_nearest_second(): void {
		$this->assertSame( '46s', $this->cli->format_duration( 45.6 ) );
	}

	public function test_format_duration_zero(): void {
		$this->assertSame( '0s', $this->cli->format_duration( 0.0 ) );
	}

	public function test_format_duration_exactly_one_minute(): void {
		$this->assertSame( '1m 0s', $this->cli->format_duration( 60.0 ) );
	}

	public function test_format_duration_minutes_and_seconds(): void {
		$this->assertSame( '2m 15s', $this->cli->format_duration( 135.0 ) );
	}

	public function test_format_duration_long_build(): void {
		// 52.5 minutes — Hank's original report.
		$this->assertSame( '52m 30s', $this->cli->format_duration( 3150.0 ) );
	}

	// -------------------------------------------------------------------------
	// Structural: diagnose accesses gather_count and uses all three phases
	// -------------------------------------------------------------------------

	public function test_diagnose_uses_gather_count(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );
		$this->assertStringContainsString(
			'gather_count()',
			$source,
			'do_diagnose() must use gather_count() for total corpus size'
		);
	}

	public function test_diagnose_uses_filter_items(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );
		$this->assertStringContainsString(
			'filterItems(',
			$source,
			'do_diagnose() must call filterItems() to measure HtmlCleaner phase'
		);
	}

	public function test_diagnose_uses_orchestrator_build(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );
		$this->assertStringContainsString(
			'->build(',
			$source,
			'do_diagnose() must call orchestrator->build() to measure indexer phase'
		);
	}

	public function test_diagnose_accepts_count_flag(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );
		$this->assertStringContainsString(
			"'count'",
			$source,
			'diagnose() must accept a --count flag'
		);
	}
}
