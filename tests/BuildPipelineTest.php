<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Regression tests for the build-pipeline unification: scheduler and admin
 * builds route through IndexBuildOrchestrator (streamed, budget-aware),
 * and both content pipelines share one post→ContentItem mapper.
 */
class BuildPipelineTest extends TestCase {

	protected function set_up(): void {
		$GLOBALS['wp_options'] = [];
		unset( $GLOBALS['scolta_test_post_counts'] );
	}

	protected function tear_down(): void {
		unset( $GLOBALS['scolta_test_post_counts'] );
	}

	private static function scheduler_source(): string {
		return file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-rebuild-scheduler.php' );
	}

	// -------------------------------------------------------------------
	// Scheduler: no eager corpus load, no full-corpus transient
	// -------------------------------------------------------------------

	public function test_scheduler_does_not_materialize_the_corpus(): void {
		$source = self::scheduler_source();
		$this->assertStringNotContainsString(
			'exportToItems(',
			$source,
			'the scheduler must stream the gatherer generator (filterItems), never materialize it'
		);
		$this->assertStringContainsString( 'filterItems(', $source );
		$this->assertStringContainsString( 'IndexBuildOrchestrator', $source );
	}

	public function test_scheduler_writes_no_full_corpus_transient(): void {
		$source = self::scheduler_source();
		$this->assertStringNotContainsString(
			"'scolta_build_chunks'",
			$source,
			'chunk state lives in the orchestrator state dir, not a transient that can exceed max_allowed_packet'
		);
		$this->assertStringNotContainsString( 'array_chunk(', $source );
	}

	public function test_scheduler_budget_reads_admin_settings(): void {
		$source = self::scheduler_source();
		$this->assertStringContainsString( 'MemoryBudgetConfig::fromCliAndConfig', $source );
		$this->assertStringContainsString( "memory_budget_profile", $source );
		$this->assertStringContainsString( "'chunk_size'", $source );
	}

	// -------------------------------------------------------------------
	// handle_start(): respects a non-default chunk_size, writes only
	// lightweight status (counts), never the corpus
	// -------------------------------------------------------------------

	public function test_handle_start_respects_non_default_chunk_size(): void {
		$GLOBALS['scolta_test_post_counts'] = array(
			'post' => 90,
			'page' => 10,
		);
		update_option(
			'scolta_settings',
			array(
				'post_types' => array( 'post', 'page' ),
				'chunk_size' => 7,
			)
		);

		Scolta_Rebuild_Scheduler::handle_start();

		$status = get_option( 'scolta_build_status' );
		$this->assertIsArray( $status );
		$this->assertSame( 'running', $status['status'] );
		$this->assertSame( 100, $status['total_pages'] );
		$this->assertSame(
			(int) ceil( 100 / 7 ),
			$status['total_chunks'],
			'the configured chunk_size must drive the chunk math (the old path hardcoded 100)'
		);
		$this->assertFalse(
			get_transient( 'scolta_build_chunks' ),
			'handle_start must not serialize chunk payloads into a transient'
		);
	}

	public function test_handle_start_with_no_content_finishes_idle(): void {
		update_option( 'scolta_settings', array( 'post_types' => array( 'post' ) ) );

		Scolta_Rebuild_Scheduler::handle_start();

		$status = get_option( 'scolta_build_status' );
		$this->assertSame( 'idle', $status['status'] );
	}

	// -------------------------------------------------------------------
	// Admin rebuild: same orchestrator pipeline as the CLI
	// -------------------------------------------------------------------

	public function test_admin_rebuild_streams_through_orchestrator(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		$start  = strpos( $source, 'function handle_rebuild_now' );
		$this->assertNotFalse( $start );
		$body = substr( $source, $start );

		$this->assertStringContainsString( 'IndexBuildOrchestrator', $body );
		$this->assertStringContainsString( 'filterItems', $body );
		$this->assertStringNotContainsString( 'exportToItems(', $body );
		$this->assertStringNotContainsString( 'array_chunk(', $body );
		$this->assertStringContainsString(
			'memory_budget_profile',
			$body,
			'the admin build must honor the memory settings the UI documents as applying to PHP builds'
		);
	}

	// -------------------------------------------------------------------
	// Shared post→ContentItem mapper parity
	// -------------------------------------------------------------------

	private function make_post(): WP_Post {
		return WP_Post::make(
			array(
				'ID'            => 42,
				'post_type'     => 'post',
				'post_title'    => 'Cats &amp; Dogs',
				'post_content'  => '<p>Hello world</p>',
				'post_date'     => '2024-03-05 10:00:00',
				'post_modified' => '2026-01-01 09:00:00',
			)
		);
	}

	public function test_both_pipelines_produce_identical_content_items(): void {
		$post = $this->make_post();

		$gatherer_item = Scolta_Content_Gatherer::to_content_item( $post, 'My Site' );

		$source = new Scolta_Content_Source(
			\Tag1\Scolta\Config\ScoltaConfig::fromArray( array( 'site_name' => 'My Site' ) )
		);
		$method      = new ReflectionMethod( $source, 'post_to_content_item' );
		$source_item = $method->invoke( $source, $post );

		$this->assertNotNull( $gatherer_item );
		$this->assertNotNull( $source_item );
		$this->assertSame( $gatherer_item->id, $source_item->id, 'ID scheme must not diverge between pipelines' );
		$this->assertSame( $gatherer_item->title, $source_item->title );
		$this->assertSame( $gatherer_item->date, $source_item->date, 'recency date semantic must not diverge between pipelines' );
		$this->assertSame( $gatherer_item->url, $source_item->url );
		$this->assertSame( $gatherer_item->siteName, $source_item->siteName );
	}

	public function test_mapper_uses_post_id_scheme_and_publish_date(): void {
		$item = Scolta_Content_Gatherer::to_content_item( $this->make_post(), 'My Site' );

		$this->assertSame( 'post-42', $item->id, 'canonical id scheme is post-{ID} (the historical PHP-indexer scheme)' );
		$this->assertSame( '2024-03-05', $item->date, 'recency follows the publish date, not post_modified' );
	}

	public function test_mapper_decodes_title_entities(): void {
		$item = Scolta_Content_Gatherer::to_content_item( $this->make_post(), 'My Site' );
		$this->assertSame( 'Cats & Dogs', $item->title );
	}

	public function test_mapper_returns_null_for_empty_content(): void {
		$post               = $this->make_post();
		$post->post_content = '<p>   </p>';
		$this->assertNull( Scolta_Content_Gatherer::to_content_item( $post, 'My Site' ) );
	}

	public function test_content_source_has_no_second_mapper(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-content-source.php' );
		$this->assertStringNotContainsString(
			'new ContentItem(',
			$source,
			'Scolta_Content_Source must delegate to the shared mapper, not construct ContentItems itself'
		);
		$this->assertStringContainsString( 'Scolta_Content_Gatherer::to_content_item', $source );
	}

	// -------------------------------------------------------------------
	// Amazee admin JS i18n (single source of truth with the PHP templates)
	// -------------------------------------------------------------------

	public function test_amazee_admin_js_uses_wp_i18n(): void {
		$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/amazee-admin.js' );
		$this->assertStringContainsString( 'wp.i18n', $js );
		$this->assertStringNotContainsString(
			"'<p>Connect Scolta to Amazee.ai",
			$js,
			'step UI strings must go through wp.i18n.__(), not hardcoded English'
		);
	}

	public function test_amazee_admin_js_shares_php_source_strings(): void {
		$js  = file_get_contents( dirname( __DIR__ ) . '/assets/js/amazee-admin.js' );
		$php = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-amazee-admin-page.php' );

		// Same msgids in both renderers — one set of translations.
		foreach ( array(
			'Connect Scolta to Amazee.ai for privacy-respecting, budget-aware AI search.',
			'A verification code has been sent to %s. Enter it below.',
			'Connected to Amazee.ai (region: %s).',
			'Select the region where your AI requests will be processed.',
		) as $msgid ) {
			$this->assertStringContainsString( $msgid, $js, "JS must use the shared msgid: $msgid" );
			$this->assertStringContainsString( $msgid, $php, "PHP must use the shared msgid: $msgid" );
		}
	}

	public function test_amazee_admin_script_loads_translations(): void {
		$php = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-amazee-admin-page.php' );
		$this->assertStringContainsString( "wp_set_script_translations( 'scolta-amazee-admin', 'scolta-ai-search' )", $php );
		$this->assertStringContainsString( "'wp-i18n'", $php, 'the script must declare the wp-i18n dependency' );
	}
}
