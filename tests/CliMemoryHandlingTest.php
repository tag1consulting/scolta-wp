<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for memory_abort and index_only_complete handling in do_build_php().
 *
 * Behavioral testing of spawn methods is impractical without a real WP-CLI
 * environment and OS process control. These structural tests read the source
 * file directly to verify that each branch exists, is guarded correctly, and
 * routes to the right helper. They guard against accidental regression of the
 * conditions that make auto-resume work after PR #107 (scolta-php).
 */
class CliMemoryHandlingTest extends TestCase {

	private string $source;

	public function set_up(): void {
		parent::set_up();
		$this->source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );
	}

	// -------------------------------------------------------------------
	// memory_abort branch
	// -------------------------------------------------------------------

	public function test_do_build_php_handles_memory_abort(): void {
		$this->assertStringContainsString(
			"=== 'memory_abort'",
			$this->source,
			'do_build_php() must have a memory_abort branch to avoid fatal exit on memory pressure'
		);
	}

	public function test_memory_abort_with_chunks_spawns_resume(): void {
		$this->assertStringContainsString(
			'spawn_resume_background',
			$this->source,
			'do_build_php() must call spawn_resume_background() after memory_abort with chunks > 0'
		);
	}

	public function test_memory_abort_checks_chunks_written(): void {
		$this->assertStringContainsString(
			'chunksWritten > 0',
			$this->source,
			'memory_abort handler must check chunksWritten > 0 before spawning resume'
		);
	}

	public function test_memory_abort_with_no_chunks_emits_error(): void {
		$this->assertStringContainsString(
			'Memory limit hit before any chunks were committed',
			$this->source,
			'When memory_abort occurs with 0 chunks committed, a helpful error must be emitted'
		);
	}

	// -------------------------------------------------------------------
	// index_only_complete branch
	// -------------------------------------------------------------------

	public function test_do_build_php_handles_index_only_complete(): void {
		$this->assertStringContainsString(
			"=== 'index_only_complete'",
			$this->source,
			'do_build_php() must have an index_only_complete branch to auto-finalize after heap fragmentation'
		);
	}

	public function test_index_only_complete_logs_page_count(): void {
		$this->assertStringContainsString(
			'pagesProcessed',
			$this->source,
			'index_only_complete handler must log pagesProcessed'
		);
	}

	public function test_index_only_complete_logs_chunk_count(): void {
		$this->assertStringContainsString(
			'chunksWritten',
			$this->source,
			'index_only_complete handler must log chunksWritten'
		);
	}

	// -------------------------------------------------------------------
	// spawn_resume_background()
	// -------------------------------------------------------------------

	public function test_spawn_resume_background_exists(): void {
		$this->assertStringContainsString(
			'function spawn_resume_background',
			$this->source,
			'spawn_resume_background() method must exist'
		);
	}

	public function test_spawn_resume_background_runs_in_background(): void {
		// The & at the end of the shell command detaches the child process so
		// the parent can exit first, releasing its fragmented heap.
		$this->assertStringContainsString(
			'2>&1 &',
			$this->source,
			'spawn_resume_background() must append 2>&1 & to run the child in the background'
		);
	}

	public function test_spawn_resume_background_passes_resume_flag(): void {
		$this->assertStringContainsString(
			'--resume',
			$this->source,
			'spawn_resume_background() must pass --resume so the child continues from the last chunk'
		);
	}

	public function test_spawn_resume_background_logs_resume_log_path(): void {
		$this->assertStringContainsString(
			'scolta-resume.log',
			$this->source,
			'spawn_resume_background() must log the path to the resume log file'
		);
	}

	// -------------------------------------------------------------------
	// find_wp_cli_bin()
	// -------------------------------------------------------------------

	public function test_find_wp_cli_bin_exists(): void {
		$this->assertStringContainsString(
			'function find_wp_cli_bin',
			$this->source,
			'find_wp_cli_bin() method must exist'
		);
	}

	public function test_find_wp_cli_bin_checks_argv0_first(): void {
		// argv[0] is the most reliable path to the running WP-CLI binary.
		$this->assertStringContainsString(
			"argv'][0]",
			$this->source,
			"find_wp_cli_bin() must check \$_SERVER['argv'][0] as the primary source"
		);
	}

	// -------------------------------------------------------------------
	// Non-regression: unknown errors still call WP_CLI::error()
	// -------------------------------------------------------------------

	public function test_unknown_errors_still_call_wp_cli_error(): void {
		$this->assertStringContainsString(
			'Unknown indexer error',
			$this->source,
			'do_build_php() must have a fallback that calls WP_CLI::error() for unrecognised errors'
		);
	}

	// -------------------------------------------------------------------
	// Non-regression: success path still increments generation counter
	// -------------------------------------------------------------------

	public function test_success_path_increments_generation(): void {
		$this->assertStringContainsString(
			"update_option( 'scolta_generation'",
			$this->source,
			'Success path must still increment the generation counter'
		);
	}
}
