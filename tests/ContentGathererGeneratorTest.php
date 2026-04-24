<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for Scolta_Content_Gatherer generator conversion (0.3.2).
 *
 * Root cause: gather() was returning a fully-materialized ContentItem[]
 * array built from WP_Query( 'posts_per_page' => -1 ), loading all post
 * content into RAM before a single chunk was written. On a 44k-page corpus
 * this caused ~2.87 GB peak RSS. The fix converts gather() to a \Generator
 * that paginates in batches of 100, keeping peak RSS bounded.
 *
 * These tests use source inspection (file_get_contents + reflection) since
 * WP_Query cannot return real posts in the stub environment.
 */
class ContentGathererGeneratorTest extends TestCase {

	private string $gatherer_file;
	private string $gatherer_source;

	protected function set_up(): void {
		parent::set_up();
		$this->gatherer_file   = dirname( __DIR__ ) . '/includes/class-scolta-content-gatherer.php';
		$this->gatherer_source = file_get_contents( $this->gatherer_file );

		// Load the gatherer class if not already loaded.
		if ( ! class_exists( 'Scolta_Content_Gatherer' ) ) {
			require_once $this->gatherer_file;
		}
	}

	// -------------------------------------------------------------------
	// gather() returns \Generator, not array
	// -------------------------------------------------------------------

	public function test_gather_return_type_is_generator(): void {
		$ref    = new ReflectionMethod( 'Scolta_Content_Gatherer', 'gather' );
		$return = $ref->getReturnType();

		$this->assertNotNull( $return, 'gather() must declare a return type' );
		$this->assertSame(
			'Generator',
			$return->getName(),
			'gather() must return \\Generator, not array'
		);
	}

	public function test_gather_docblock_declares_generator(): void {
		$this->assertStringContainsString(
			'@return \Generator',
			$this->gatherer_source,
			'gather() PHPDoc must declare @return \\Generator<ContentItem>'
		);
	}

	// -------------------------------------------------------------------
	// Anti-regression: no posts_per_page => -1 in gather()
	// -------------------------------------------------------------------

	public function test_gather_does_not_use_posts_per_page_minus_one(): void {
		// Locate the gather() method body only (not gather_count).
		preg_match( '/public static function gather\(\)[^{]*\{(.+?)(?=\n\t\/\*\*|\n\tpublic static function|\n\tprivate|\n\})/s', $this->gatherer_source, $m );
		$method_body = $m[1] ?? '';

		$this->assertNotEmpty( $method_body, 'Could not locate gather() method body' );
		$this->assertDoesNotMatchRegularExpression(
			"/'posts_per_page'\s*=>\s*-1/",
			$method_body,
			'gather() must not use posts_per_page => -1 — that loads all posts into RAM at once'
		);
	}

	public function test_gather_uses_offset_for_pagination(): void {
		preg_match( '/public static function gather\(\)[^{]*\{(.+?)(?=\n\t\/\*\*|\n\tpublic static function|\n\tprivate|\n\})/s', $this->gatherer_source, $m );
		$method_body = $m[1] ?? '';

		$this->assertStringContainsString(
			'offset',
			$method_body,
			'gather() must use offset-based pagination to process posts in batches'
		);
	}

	public function test_gather_flushes_post_cache_between_batches(): void {
		$this->assertStringContainsString(
			'wp_cache_flush_group',
			$this->gatherer_source,
			'gather() must call wp_cache_flush_group() to free each batch from the WP post cache'
		);
	}

	// -------------------------------------------------------------------
	// gather_count() exists and returns int
	// -------------------------------------------------------------------

	public function test_gather_count_method_exists(): void {
		$this->assertTrue(
			method_exists( 'Scolta_Content_Gatherer', 'gather_count' ),
			'Scolta_Content_Gatherer must have a gather_count() method'
		);
	}

	public function test_gather_count_return_type_is_int(): void {
		$ref    = new ReflectionMethod( 'Scolta_Content_Gatherer', 'gather_count' );
		$return = $ref->getReturnType();

		$this->assertNotNull( $return, 'gather_count() must declare a return type' );
		$this->assertSame( 'int', $return->getName(), 'gather_count() must return int' );
	}

	public function test_gather_count_uses_ids_field(): void {
		preg_match( '/public static function gather_count\(\)[^{]*\{(.+?)(?=\n\t\/\*\*|\n\tpublic static function|\n\tprivate|\n\})/s', $this->gatherer_source, $m );
		$method_body = $m[1] ?? '';

		$this->assertStringContainsString(
			"'fields'",
			$method_body,
			"gather_count() must use 'fields' => 'ids' to avoid loading full post objects"
		);
	}

	// -------------------------------------------------------------------
	// CLI wiring: generator and logger/reporter passed to orchestrator
	// -------------------------------------------------------------------

	public function test_cli_passes_logger_to_orchestrator(): void {
		$cli_source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );

		$this->assertStringContainsString(
			'Scolta_WP_CLI_Logger',
			$cli_source,
			'do_build_php() must instantiate Scolta_WP_CLI_Logger and pass it to build()'
		);
	}

	public function test_cli_passes_progress_reporter_to_orchestrator(): void {
		$cli_source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );

		$this->assertStringContainsString(
			'Scolta_WP_CLI_Progress_Reporter',
			$cli_source,
			'do_build_php() must instantiate Scolta_WP_CLI_Progress_Reporter and pass it to build()'
		);
	}

	public function test_cli_uses_gather_count_not_count_gather(): void {
		$cli_source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );

		$this->assertStringContainsString(
			'gather_count()',
			$cli_source,
			'do_build_php() must use gather_count() for early exit — not count(gather())'
		);
	}

	public function test_cli_does_not_call_export_to_items(): void {
		$cli_source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );

		// exportToItems loads the full array — the generator path must not use it.
		$this->assertStringNotContainsString(
			'exportToItems(',
			$cli_source,
			'do_build_php() must use filterItems() (generator) not exportToItems() (array)'
		);
	}
}
