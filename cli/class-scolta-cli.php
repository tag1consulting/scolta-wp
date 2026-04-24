<?php
/**
 * WP-CLI commands for Scolta.
 *
 * WordPress's WP-CLI is the developer interface for everything that
 * doesn't happen in the admin. Index builds, status checks, binary
 * management — all here.
 *
 * ## EXAMPLES
 *
 *     # Full rebuild: mark all content, export, build Pagefind index
 *     wp scolta build
 *
 *     # Incremental: only process tracked changes
 *     wp scolta build --incremental
 *
 *     # Check index and tracker status
 *     wp scolta status
 *
 *     # Download the Pagefind binary (for hosts without npm)
 *     wp scolta download-pagefind
 *
 *     # Rebuild Pagefind index only (skip content export)
 *     wp scolta rebuild-index
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;

class Scolta_CLI {

	/**
	 * Build or rebuild the Scolta search index.
	 *
	 * Routes between two indexing pipelines:
	 * - Binary: mark → export HTML → run Pagefind CLI (original pipeline)
	 * - PHP: gather → filter → chunk → processChunk → finalize (new pipeline)
	 *
	 * The --indexer flag controls which pipeline is used. With "auto" (the
	 * default), the PHP indexer is used when the Pagefind binary is unavailable.
	 *
	 * ## OPTIONS
	 *
	 * [--incremental]
	 * : Only process content that changed since the last build.
	 *   Without this flag, all published content is reindexed.
	 *   Only applies to the binary indexer pipeline.
	 *
	 * [--skip-pagefind]
	 * : Export HTML files but don't run the Pagefind CLI.
	 *   Useful when you want to inspect the exported HTML.
	 *   Only applies to the binary indexer pipeline.
	 *
	 * [--indexer=<indexer>]
	 * : Which indexer to use. Overrides the admin setting.
	 *   When omitted, the admin setting is used (Settings › Scolta › Pagefind › Indexer).
	 * ---
	 * options:
	 *   - auto
	 *   - php
	 *   - binary
	 * ---
	 *
	 * [--force]
	 * : Skip fingerprint check and rebuild even if content hasn't changed.
	 *   Only applies to the PHP indexer pipeline.
	 *
	 * [--memory-budget=<budget>]
	 * : Memory profile or explicit limit for the PHP indexer.
	 *   Accepts named profiles (conservative, balanced, aggressive) or a raw byte
	 *   value such as 256M or 1G. Default: the admin setting (conservative).
	 *
	 * [--chunk-size=<n>]
	 * : Pages per chunk during a PHP index build. Overrides the profile default
	 *   (50/200/500 for conservative/balanced/aggressive). Lower values reduce
	 *   peak RSS; higher values reduce merge overhead on large corpora.
	 *
	 * [--resume]
	 * : Resume a previously interrupted PHP index build from the last committed chunk.
	 *
	 * [--restart]
	 * : Discard any interrupted state and force a clean rebuild.
	 *
	 * ## EXAMPLES
	 *
	 *     wp scolta build
	 *     wp scolta build --indexer=php
	 *     wp scolta build --indexer=php --force
	 *     wp scolta build --indexer=php --memory-budget=balanced
	 *     wp scolta build --indexer=php --memory-budget=256M --chunk-size=100
	 *     wp scolta build --indexer=php --resume
	 *     wp scolta build --incremental
	 *
	 * @subcommand build
	 */
	public function build( array $args, array $assoc_args ): void {
		$prev = ini_get( 'display_errors' );
		ini_set( 'display_errors', '0' );
		try {
			$this->do_build( $args, $assoc_args );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		} finally {
			ini_set( 'display_errors', $prev );
		}
	}

	private function do_build( array $args, array $assoc_args ): void {
		$settings = get_option( 'scolta_settings', array() );
		$config   = ScoltaConfig::fromArray( $settings );

		// Determine which indexer to use.
		$indexer_setting = $settings['indexer'] ?? 'auto';
		$indexer         = isset( $assoc_args['indexer'] ) ? $assoc_args['indexer'] : $indexer_setting;

		if ( $indexer === 'php' ) {
			$this->do_build_php( $assoc_args, $settings );
			return;
		}

		if ( $indexer === 'auto' ) {
			$resolver = new PagefindBinary(
				configuredPath: $settings['pagefind_binary'] ?? null,
				projectDir: SCOLTA_PLUGIN_DIR,
			);
			if ( $resolver->resolve() === null ) {
				\WP_CLI::log( 'Pagefind binary not available — using PHP indexer.' );
				$this->do_build_php( $assoc_args, $settings );
				return;
			}
		}

		// Binary indexer pipeline.
		$this->do_build_binary( $args, $assoc_args, $settings, $config );
	}

	/**
	 * PHP indexer pipeline via IndexBuildOrchestrator.
	 *
	 * @param array $assoc_args CLI associative arguments.
	 * @param array $settings   Plugin settings.
	 */
	protected function do_build_php( array $assoc_args, array $settings ): void {
		$force         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$resume        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'resume', false );
		$restart       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'restart', false );
		$saved_profile = $settings['memory_budget_profile'] ?? 'conservative';
		$budget_str    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'memory-budget', $saved_profile );
		$output_dir    = $settings['output_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/pagefind';
		$state_dir     = $this->get_state_dir();
		$budget        = MemoryBudget::fromString( $budget_str );

		// Chunk size: --chunk-size flag overrides saved setting, which overrides profile default.
		$saved_chunk = $settings['chunk_size'] ?? '';
		$chunk_size  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'chunk-size', $saved_chunk );
		if ( '' !== (string) $chunk_size && null !== $chunk_size ) {
			$budget = $budget->withChunkSize( max( 1, (int) $chunk_size ) );
		}

		\WP_CLI::log( 'Using PHP indexer pipeline.' );

		$total_count = \Scolta_Content_Gatherer::gather_count();
		if ( 0 === $total_count ) {
			\WP_CLI::warning( 'No published content found. Check post_types setting.' );
			return;
		}

		// Stream content one post at a time — no full pre-load into RAM.
		$exporter = new ContentExporter( $output_dir );
		$items    = $exporter->filterItems( \Scolta_Content_Gatherer::gather() );

		$intent = match ( true ) {
			(bool) $resume  => BuildIntent::resume( $budget ),
			(bool) $restart => BuildIntent::restart( $total_count, $budget ),
			default         => BuildIntent::fresh( $total_count, $budget ),
		};

		$logger       = new \Scolta_WP_CLI_Logger();
		$reporter     = new \Scolta_WP_CLI_Progress_Reporter();
		$orchestrator = new IndexBuildOrchestrator( $state_dir, $output_dir, $this->get_hmac_secret() );
		$report       = $orchestrator->build( $intent, $items, $logger, $reporter );

		if ( $report->success ) {
			$generation = (int) get_option( 'scolta_generation', 0 );
			update_option( 'scolta_generation', $generation + 1 );
			\WP_CLI::success(
				sprintf(
					'PHP indexer complete: %d pages in %.1fs (%s peak RAM).',
					$report->pagesProcessed,
					$report->durationSeconds,
					$report->peakMemoryMb()
				)
			);
		} else {
			\WP_CLI::error( $report->error ?? 'Unknown indexer error' );
		}
	}

	/**
	 * Binary indexer pipeline: mark → export HTML → run Pagefind CLI.
	 *
	 * @param array        $args       CLI positional arguments.
	 * @param array        $assoc_args CLI associative arguments.
	 * @param array        $settings   Plugin settings.
	 * @param ScoltaConfig $config     Scolta configuration.
	 */
	protected function do_build_binary( array $args, array $assoc_args, array $settings, ScoltaConfig $config ): void {
		$incremental   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'incremental', false );
		$skip_pagefind = \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-pagefind', false );

		$build_dir  = $settings['build_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/build';
		$output_dir = $settings['output_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/pagefind';
		$post_types = $settings['post_types'] ?? array( 'post', 'page' );

		$source   = new \Scolta_Content_Source( $config );
		$exporter = new ContentExporter( $build_dir );

		// Step 1: Determine what to index.
		if ( $incremental ) {
			$pending_count = \Scolta_Tracker::get_pending_count();
			if ( $pending_count === 0 ) {
				\WP_CLI::success( 'No changes pending. Index is up to date.' );
				return;
			}
			\WP_CLI::log( "Step 1: Processing {$pending_count} tracked changes..." );
		} else {
			\WP_CLI::log( 'Step 1: Marking all published content for reindex...' );
			$count = \Scolta_Tracker::mark_all_for_reindex();
			\WP_CLI::log( "  Marked {$count} items." );

			// For full rebuild, clean the build directory.
			$exporter->prepareOutputDir();
		}

		// Step 2: Export content to HTML.
		\WP_CLI::log( 'Step 2: Exporting content to HTML...' );

		// Handle deletions first.
		$deleted_ids = $source->get_deleted_ids();
		foreach ( $deleted_ids as $id ) {
			$filepath = rtrim( $build_dir, '/' ) . '/' . $id . '.html';
			if ( file_exists( $filepath ) ) {
				unlink( $filepath );
			}
		}
		if ( count( $deleted_ids ) > 0 ) {
			\WP_CLI::log( '  Removed ' . count( $deleted_ids ) . ' deleted items.' );
		}

		// Export new/changed content.
		$items = $incremental
			? $source->get_changed_content()
			: $source->get_published_content( $post_types );

		$exported = 0;
		$skipped  = 0;
		$progress = null;

		if ( ! $incremental ) {
			$total    = $source->get_total_count( $post_types );
			$progress = \WP_CLI\Utils\make_progress_bar( 'Exporting', $total );
		}

		foreach ( $items as $item ) {
			if ( $exporter->export( $item ) ) {
				++$exported;
			} else {
				++$skipped;
			}
			if ( $progress ) {
				$progress->tick();
			}
		}

		if ( $progress ) {
			$progress->finish();
		}

		\WP_CLI::log( "  Exported: {$exported}, Skipped (insufficient content): {$skipped}" );

		// Clear the tracker after successful export.
		\Scolta_Tracker::clear();

		// Step 3: Build Pagefind index.
		if ( $skip_pagefind ) {
			\WP_CLI::success( 'Export complete. Skipped Pagefind build (--skip-pagefind).' );
			return;
		}

		\WP_CLI::log( 'Step 3: Building Pagefind index...' );
		$resolver = new PagefindBinary(
			configuredPath: $settings['pagefind_binary'] ?? null,
			projectDir: SCOLTA_PLUGIN_DIR,
		);
		$binary   = $resolver->resolve();
		if ( $binary === null ) {
			$status = $resolver->status();
			\WP_CLI::error( $status['message'] );
			return;
		}
		\WP_CLI::log( "Using Pagefind: {$binary} (resolved via {$resolver->resolvedVia()})" );
		$this->run_pagefind( $binary, $build_dir, $output_dir );
	}

	/**
	 * Profile PHP indexer performance in three isolated phases.
	 *
	 * Runs gather, HTML cleaning, and indexing separately on a sample of real
	 * posts and prints per-phase timing, a projected full-corpus duration, and
	 * a recommendation. Use this to identify whether build slowness comes from
	 * WordPress content filters, HtmlCleaner, or the indexer itself.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<n>]
	 * : Number of posts to sample.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--memory-budget=<budget>]
	 * : Memory profile or explicit limit for the indexer phase.
	 *   Accepts named profiles (conservative, balanced, aggressive) or a raw byte
	 *   value such as 256M. Default: the admin setting (conservative).
	 *
	 * [--chunk-size=<n>]
	 * : Pages per chunk for the indexer phase. Overrides the profile default.
	 *
	 * ## EXAMPLES
	 *
	 *     # Profile with default 500-post sample
	 *     wp scolta diagnose
	 *
	 *     # Larger sample for more accurate projection on a 44k-post site
	 *     wp scolta diagnose --count=2000
	 *
	 *     # Test a custom budget before committing it to settings
	 *     wp scolta diagnose --count=1000 --memory-budget=256M --chunk-size=100
	 *
	 * @subcommand diagnose
	 */
	public function diagnose( array $args, array $assoc_args ): void {
		$prev = ini_get( 'display_errors' );
		ini_set( 'display_errors', '0' );
		try {
			$this->do_diagnose( $assoc_args );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		} finally {
			ini_set( 'display_errors', $prev );
		}
	}

	/**
	 * Run the three-phase diagnostic.
	 *
	 * @param array $assoc_args CLI associative arguments.
	 */
	private function do_diagnose( array $assoc_args ): void {
		$limit      = max( 1, (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'count', 500 ) );
		$settings   = get_option( 'scolta_settings', array() );
		$budget_str = \WP_CLI\Utils\get_flag_value( $assoc_args, 'memory-budget', $settings['memory_budget_profile'] ?? 'conservative' );
		$budget     = MemoryBudget::fromString( $budget_str );

		// Chunk size: --chunk-size flag overrides saved setting, which overrides profile default.
		$saved_chunk = $settings['chunk_size'] ?? '';
		$chunk_size  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'chunk-size', $saved_chunk );
		if ( '' !== (string) $chunk_size && null !== $chunk_size ) {
			$budget = $budget->withChunkSize( max( 1, (int) $chunk_size ) );
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( '=== Scolta PHP Indexer Diagnostics ===' );
		\WP_CLI::log( '' );

		// Step 0: count posts.
		$t0         = microtime( true );
		$total      = \Scolta_Content_Gatherer::gather_count();
		$count_time = microtime( true ) - $t0;
		$sample     = min( $limit, $total );

		\WP_CLI::log(
			sprintf(
				'Site: %s published posts  (gather_count in %.3fs)',
				number_format( $total ),
				$count_time
			)
		);
		\WP_CLI::log( sprintf( 'Sample: %s posts  (--count=%s)', number_format( $sample ), $limit ) );
		\WP_CLI::log( '' );

		if ( 0 === $total ) {
			\WP_CLI::warning( 'No published content found. Check the post_types setting.' );
			return;
		}

		// Phase 1: gather — WP_Query batches + apply_filters('the_content') + get_permalink.
		\WP_CLI::log( 'Phase 1/3  gather  [WP_Query + apply_filters("the_content") + get_permalink]' );
		$t0    = microtime( true );
		$items = array();
		$n     = 0;
		foreach ( \Scolta_Content_Gatherer::gather() as $item ) {
			$items[] = $item;
			++$n;
			if ( $n >= $sample ) {
				break;
			}
		}
		$gather_time = microtime( true ) - $t0;
		$gather_ms   = $gather_time / $n * 1000;

		\WP_CLI::log( sprintf( '  %s posts  %.2fs  %.1f ms/post', number_format( $n ), $gather_time, $gather_ms ) );

		if ( $gather_ms > 20 ) {
			\WP_CLI::log( '  ! High ms/post — apply_filters("the_content") is likely slow.' );
			\WP_CLI::log( '    Common causes: Yoast SEO, Gutenberg do_blocks(), WooCommerce, Elementor.' );
		}

		\WP_CLI::log( '' );

		// Phase 2: HtmlCleaner — strip tags and measure content length.
		\WP_CLI::log( 'Phase 2/3  HtmlCleaner  [strip HTML tags, check minimum content length]' );
		$exporter    = new ContentExporter( sys_get_temp_dir() . '/scolta-diag-' . uniqid() );
		$t0          = microtime( true );
		$filtered    = iterator_to_array( $exporter->filterItems( $items ) );
		$filter_time = microtime( true ) - $t0;
		$filter_ms   = $filter_time / $n * 1000;
		$passed      = count( $filtered );
		$skip_pct    = $n > 0 ? round( ( $n - $passed ) / $n * 100 ) : 0;

		\WP_CLI::log(
			sprintf(
				'  %s posts  %.2fs  %.1f ms/post  (%s passed, %s%% too short)',
				number_format( $n ),
				$filter_time,
				$filter_ms,
				number_format( $passed ),
				$skip_pct
			)
		);
		\WP_CLI::log( '' );

		if ( 0 === $passed ) {
			\WP_CLI::warning( 'All sampled posts filtered out (content too short). Nothing to index.' );
			return;
		}

		// Phase 3: indexer — tokenize, stem, chunk, merge, write.
		\WP_CLI::log( 'Phase 3/3  indexer  [tokenize, stem, chunk, merge, write — ' . $budget_str . ' budget]' );
		$state_dir  = sys_get_temp_dir() . '/scolta-diag-state-' . uniqid();
		$output_dir = sys_get_temp_dir() . '/scolta-diag-out-' . uniqid();

		$orchestrator = new IndexBuildOrchestrator( $state_dir, $output_dir );
		$intent       = BuildIntent::fresh( $passed, $budget );

		$t0         = microtime( true );
		$orchestrator->build( $intent, $filtered );
		$index_time = microtime( true ) - $t0;
		$index_ms   = $index_time / $passed * 1000;

		\WP_CLI::log(
			sprintf(
				'  %s posts  %.2fs  %.1f ms/post',
				number_format( $passed ),
				$index_time,
				$index_ms
			)
		);
		\WP_CLI::log( '' );

		// Clean up temp directories.
		foreach ( array( $state_dir, $output_dir ) as $dir ) {
			if ( is_dir( $dir ) ) {
				$it = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ( $it as $f ) {
					$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
				}
				rmdir( $dir );
			}
		}

		// Projection to full corpus.
		$proj_gather = $gather_time / $n * $total;
		$proj_filter = $filter_time / $n * $total;
		$proj_index  = $index_time / $passed * ( $total * ( $passed / $n ) );
		$proj_total  = $proj_gather + $proj_filter + $proj_index;

		\WP_CLI::log( '=== Projected for full corpus (' . number_format( $total ) . ' posts) ===' );
		\WP_CLI::log( '' );

		$pct = static function ( float $part, float $whole ): string {
			return $whole > 0 ? sprintf( '%.0f%%', $part / $whole * 100 ) : '0%';
		};

		\WP_CLI::log(
			sprintf(
				'  gather:      %-10s  %s of total',
				$this->format_duration( $proj_gather ),
				$pct( $proj_gather, $proj_total )
			)
		);
		\WP_CLI::log(
			sprintf(
				'  HtmlCleaner: %-10s  %s of total',
				$this->format_duration( $proj_filter ),
				$pct( $proj_filter, $proj_total )
			)
		);
		\WP_CLI::log(
			sprintf(
				'  indexer:     %-10s  %s of total',
				$this->format_duration( $proj_index ),
				$pct( $proj_index, $proj_total )
			)
		);
		\WP_CLI::log( '  ' . str_repeat( '-', 28 ) );
		\WP_CLI::log( '  estimated total: ' . $this->format_duration( $proj_total ) );
		\WP_CLI::log( '' );

		// Recommendation.
		if ( $proj_gather / $proj_total > 0.5 ) {
			\WP_CLI::log( 'Recommendation: gather phase dominates (' . $pct( $proj_gather, $proj_total ) . ' of build time).' );
			\WP_CLI::log( '  apply_filters("the_content") runs every active plugin filter on each post.' );
			\WP_CLI::log( '  Use the scolta_content_item filter to substitute $post->post_content' );
			\WP_CLI::log( '  (raw storage, no plugin processing) or do_blocks($post->post_content)' );
			\WP_CLI::log( '  (renders blocks only, skips SEO/analytics hooks).' );
		} elseif ( $proj_filter / $proj_total > 0.3 ) {
			\WP_CLI::log( 'Recommendation: HtmlCleaner dominates (' . $pct( $proj_filter, $proj_total ) . ' of build time).' );
			\WP_CLI::log( '  Posts with large rendered HTML (Gutenberg page-builder blocks, Elementor)' );
			\WP_CLI::log( '  are expensive to parse. Consider truncating bodyHtml via scolta_content_item.' );
		} else {
			\WP_CLI::log( 'Recommendation: indexer dominates (' . $pct( $proj_index, $proj_total ) . ' of build time).' );
			\WP_CLI::log( '  Try --memory-budget=balanced (200 posts/chunk instead of 50) to reduce' );
			\WP_CLI::log( '  merge overhead, or --memory-budget=aggressive if RSS headroom allows.' );
		}
	}

	/**
	 * Format a duration in seconds as a human-readable string.
	 *
	 * @param float $seconds Raw duration in seconds.
	 * @return string E.g. "2m 15s" or "45s".
	 */
	public function format_duration( float $seconds ): string {
		$s = (int) round( $seconds );
		if ( $s < 60 ) {
			return sprintf( '%ds', $s );
		}
		return sprintf( '%dm %ds', intdiv( $s, 60 ), $s % 60 );
	}

	/**
	 * Get the state directory for the PHP indexer.
	 *
	 * @return string Absolute path to the state directory.
	 */
	private function get_state_dir(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/scolta/state';
	}

	/**
	 * Get the HMAC secret for index integrity verification.
	 *
	 * @return string HMAC secret derived from WordPress auth salt.
	 */
	private function get_hmac_secret(): string {
		return wp_salt( 'auth' );
	}

	/**
	 * Export content as HTML files for Pagefind indexing.
	 *
	 * Runs only the content export step — does not build the Pagefind index.
	 * Useful for inspecting exported HTML.
	 *
	 * ## OPTIONS
	 *
	 * [--incremental]
	 * : Only process content that changed since the last build.
	 *
	 * @subcommand export
	 */
	public function export( array $args, array $assoc_args ): void {
		$prev = ini_get( 'display_errors' );
		ini_set( 'display_errors', '0' );
		try {
			$incremental = \WP_CLI\Utils\get_flag_value( $assoc_args, 'incremental', false );
			$settings    = get_option( 'scolta_settings', array() );
			$config      = ScoltaConfig::fromArray( $settings );
			$build_dir   = $settings['build_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/build';
			$post_types  = $settings['post_types'] ?? array( 'post', 'page' );

			$source   = new \Scolta_Content_Source( $config );
			$exporter = new ContentExporter( $build_dir );

			if ( $incremental ) {
				$pending = \Scolta_Tracker::get_pending_count();
				if ( $pending === 0 ) {
					\WP_CLI::success( 'No changes pending. Nothing to export.' );
					return;
				}
				\WP_CLI::log( "Processing {$pending} tracked changes..." );
			} else {
				$count = \Scolta_Tracker::mark_all_for_reindex();
				\WP_CLI::log( "Marked {$count} items for export." );
				$exporter->prepareOutputDir();
			}

			$deleted_ids = $source->get_deleted_ids();
			foreach ( $deleted_ids as $id ) {
				$filepath = rtrim( $build_dir, '/' ) . '/' . $id . '.html';
				if ( file_exists( $filepath ) ) {
					unlink( $filepath );
				}
			}

			$items = $incremental
				? $source->get_changed_content()
				: $source->get_published_content( $post_types );

			$exported = 0;
			$skipped  = 0;
			foreach ( $items as $item ) {
				$exporter->export( $item ) ? $exported++ : $skipped++;
			}

			\WP_CLI::log( "  Exported: {$exported}, Skipped: {$skipped}" );
			\WP_CLI::log( "  Output directory: {$build_dir}" );
			\Scolta_Tracker::clear();
			\WP_CLI::success( 'Export complete.' );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		} finally {
			ini_set( 'display_errors', $prev );
		}
	}

	/**
	 * Rebuild the Pagefind index from existing HTML files.
	 *
	 * Skips the content export step — useful when you've edited the
	 * HTML files directly or want to rebuild after a Pagefind update.
	 *
	 * @subcommand rebuild-index
	 */
	public function rebuild_index( array $args, array $assoc_args ): void {
		$prev = ini_get( 'display_errors' );
		ini_set( 'display_errors', '0' );
		try {
			$settings        = get_option( 'scolta_settings', array() );
			$indexer_setting = $settings['indexer'] ?? 'auto';

			if ( 'php' === $indexer_setting ) {
				\WP_CLI::error(
					'The `rebuild-index` command re-runs the Pagefind binary on existing HTML export files. ' .
					'Your active indexer is set to PHP, which writes the index directly without HTML staging files. ' .
					'Use `wp scolta build` to rebuild the index.'
				);
				return;
			}

			$build_dir  = $settings['build_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/build';
			$output_dir = $settings['output_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/pagefind';

			$resolver = new PagefindBinary(
				configuredPath: $settings['pagefind_binary'] ?? null,
				projectDir: SCOLTA_PLUGIN_DIR,
			);
			$binary   = $resolver->resolve();
			if ( $binary === null ) {
				\WP_CLI::error( $resolver->status()['message'] );
				return;
			}

			\WP_CLI::log( 'Rebuilding Pagefind index from existing HTML files...' );
			$this->run_pagefind( $binary, $build_dir, $output_dir );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		} finally {
			ini_set( 'display_errors', $prev );
		}
	}

	/**
	 * Show Scolta index status.
	 *
	 * Displays tracker state, index stats, binary availability, and
	 * configuration summary.
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc_args ): void {
		$prev = ini_get( 'display_errors' );
		ini_set( 'display_errors', '0' );
		try {
			$this->do_status();
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		} finally {
			ini_set( 'display_errors', $prev );
		}
	}

	private function do_status(): void {
		$settings   = get_option( 'scolta_settings', array() );
		$post_types = $settings['post_types'] ?? array( 'post', 'page' );
		$build_dir  = $settings['build_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/build';
		$output_dir = $settings['output_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/pagefind';

		// Tracker status.
		\WP_CLI::log( '--- Tracker ---' );
		if ( ! \Scolta_Tracker::table_exists() ) {
			\WP_CLI::warning( 'Tracker table does not exist. Run: wp scolta activate' );
		} else {
			$pending_index  = \Scolta_Tracker::get_pending_count( 'index' );
			$pending_delete = \Scolta_Tracker::get_pending_count( 'delete' );
			\WP_CLI::log( "  Pending index:  {$pending_index}" );
			\WP_CLI::log( "  Pending delete: {$pending_delete}" );
		}

		// Content counts.
		\WP_CLI::log( '--- Content ---' );
		$config = ScoltaConfig::fromArray( $settings );
		$source = new \Scolta_Content_Source( $config );
		$total  = $source->get_total_count( $post_types );
		\WP_CLI::log( "  Published posts ({$this->join_types($post_types)}): {$total}" );

		// Build directory.
		\WP_CLI::log( '--- Build Directory ---' );
		if ( is_dir( $build_dir ) ) {
			$glob_result = glob( $build_dir . '/*.html' );
			$html_count  = count( ! empty( $glob_result ) ? $glob_result : array() );
			\WP_CLI::log( "  Path:       {$build_dir}" );
			\WP_CLI::log( "  HTML files: {$html_count}" );
		} else {
			\WP_CLI::log( "  Path: {$build_dir} (does not exist)" );
		}

		// Pagefind index.
		\WP_CLI::log( '--- Pagefind Index ---' );
		$index_file = $output_dir . '/pagefind.js';
		if ( file_exists( $index_file ) ) {
			$glob_result    = glob( $output_dir . '/fragment/*' );
			$fragment_count = count( ! empty( $glob_result ) ? $glob_result : array() );
			$mtime          = filemtime( $index_file );
			\WP_CLI::log( "  Path:       {$output_dir}" );
			\WP_CLI::log( "  Fragments:  {$fragment_count}" );
			$built = $mtime ? wp_date( 'Y-m-d H:i:s', $mtime ) : 'unknown';
			\WP_CLI::log( '  Last built: ' . $built );
		} else {
			\WP_CLI::log( "  Path: {$output_dir} (no index built yet)" );
		}

		// Indexer selection and active state.
		\WP_CLI::log( '--- Indexer ---' );
		$resolver        = new PagefindBinary(
			configuredPath: $settings['pagefind_binary'] ?? null,
			projectDir: SCOLTA_PLUGIN_DIR,
		);
		$binary_status   = $resolver->status();
		$indexer_setting = $settings['indexer'] ?? 'auto';
		if ( $indexer_setting === 'php' ) {
			$active_indexer = 'php (forced)';
		} elseif ( $indexer_setting === 'binary' ) {
			$active_indexer = $binary_status['available'] ? 'binary' : 'binary (not found — check path)';
		} else {
			$active_indexer = $binary_status['available'] ? 'binary (auto-detected)' : 'php (binary not found)';
		}
		\WP_CLI::log( "  Active indexer: {$active_indexer}" );
		if ( $binary_status['available'] ) {
			\WP_CLI::log( "  Binary:         {$binary_status['message']}" );
		} else {
			\WP_CLI::warning( '  Binary:         NOT AVAILABLE' );
			\WP_CLI::log( "  {$binary_status['message']}" );
			if ( $active_indexer !== 'php (forced)' ) {
				\WP_CLI::log( '  To upgrade: npm install -g pagefind  OR  wp scolta download-pagefind' );
			}
		}

		// AI provider.
		\WP_CLI::log( '--- AI Provider ---' );
		$ai = \Scolta_Ai_Service::from_options();
		if ( $ai->has_wp_ai_sdk() ) {
			\WP_CLI::log( '  Provider: WordPress AI Client SDK (WP 7.0+)' );
		} else {
			$provider   = $settings['ai_provider'] ?? 'anthropic';
			$key_source = \Scolta_Ai_Service::get_api_key_source();
			\WP_CLI::log( "  Provider: {$provider} (built-in)" );
			$source_label = match ( $key_source ) {
				'env'      => 'environment variable',
				'constant' => 'wp-config.php constant',
				'database' => 'database (INSECURE — migrate to env var)',
				default    => 'NOT SET',
			};
			\WP_CLI::log( "  API key:  {$source_label}" );
			if ( $key_source === 'database' ) {
				\WP_CLI::warning( 'API key stored in database. Set SCOLTA_API_KEY environment variable and remove from DB.' );
			}
		}
	}

	/**
	 * Clear all Scolta caches.
	 *
	 * Increments the generation counter to invalidate all cached AI
	 * responses (expansion, summarization) and deletes any stale
	 * transients with the old prefix.
	 *
	 * @subcommand clear-cache
	 */
	public function clear_cache( array $args, array $assoc_args ): void {
		$prev = ini_get( 'display_errors' );
		ini_set( 'display_errors', '0' );
		try {
			$generation = (int) get_option( 'scolta_generation', 0 );
			update_option( 'scolta_generation', $generation + 1 );

			// Also clean up any stale transients from old generations.
			global $wpdb;
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					'%_transient_scolta_%'
				)
			);

			\WP_CLI::success( "Scolta caches cleared (generation counter incremented, {$deleted} transients deleted)." );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		} finally {
			ini_set( 'display_errors', $prev );
		}
	}

	/**
	 * Verify Scolta dependencies and configuration.
	 *
	 * Checks PHP version, Pagefind binary, AI key, and browser WASM assets.
	 *
	 * @subcommand check-setup
	 */
	public function check_setup( array $args, array $assoc_args ): void {
		$prev = ini_get( 'display_errors' );
		ini_set( 'display_errors', '0' );
		try {
			$settings = get_option( 'scolta_settings', array() );
			$ai       = \Scolta_Ai_Service::from_options();

			$results = \Tag1\Scolta\SetupCheck::run(
				configuredBinaryPath: $settings['pagefind_binary'] ?? null,
				projectDir: SCOLTA_PLUGIN_DIR,
				aiApiKey: $ai->get_api_key(),
			);

			foreach ( $results as $r ) {
				$icon = match ( $r['status'] ) {
					'pass' => '[OK]',
					'warn' => '[!!]',
					'fail' => '[FAIL]',
				};
				if ( $r['status'] === 'fail' ) {
					\WP_CLI::error( "{$icon} {$r['name']}: {$r['message']}", false );
				} elseif ( $r['status'] === 'warn' ) {
					\WP_CLI::warning( "{$icon} {$r['name']}: {$r['message']}" );
				} else {
					\WP_CLI::log( "{$icon} {$r['name']}: {$r['message']}" );
				}
			}

			$exit = \Tag1\Scolta\SetupCheck::exitCode( $results );
			if ( $exit === 0 ) {
				\WP_CLI::success( 'All critical checks passed.' );
			} else {
				\WP_CLI::error( 'One or more critical checks failed.' );
			}
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		} finally {
			ini_set( 'display_errors', $prev );
		}
	}

	/**
	 * Download the Pagefind binary for the current platform.
	 *
	 * For hosts without npm/Node.js — downloads the pre-built binary
	 * directly from GitHub releases.
	 *
	 * @subcommand download-pagefind
	 */
	public function download_pagefind( array $args, array $assoc_args ): void {
		$prev = ini_get( 'display_errors' );
		ini_set( 'display_errors', '0' );
		try {
			$this->do_download_pagefind();
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		} finally {
			ini_set( 'display_errors', $prev );
		}
	}

	private function do_download_pagefind(): void {
		$settings = get_option( 'scolta_settings', array() );
		// Use PagefindBinary::downloadTargetDir() so the install location
		// matches what resolve() searches — plugin-dir/.scolta/bin/pagefind.
		$resolver   = new PagefindBinary(
			configuredPath: $settings['pagefind_binary'] ?? null,
			projectDir: SCOLTA_PLUGIN_DIR,
		);
		$target_dir = $resolver->downloadTargetDir(); // creates dir if needed

		// Detect platform.
		$os   = PHP_OS_FAMILY;
		$arch = php_uname( 'm' );

		$platform = match ( true ) {
			$os === 'Linux' && $arch === 'x86_64'  => 'x86_64-unknown-linux-musl',
			$os === 'Linux' && str_contains( $arch, 'aarch64' ) => 'aarch64-unknown-linux-musl',
			$os === 'Darwin' && str_contains( $arch, 'arm' ) => 'aarch64-apple-darwin',
			$os === 'Darwin' => 'x86_64-apple-darwin',
			default => null,
		};

		if ( $platform === null ) {
			\WP_CLI::error( "Unsupported platform: {$os} {$arch}. Install Pagefind via npm instead." );
			return;
		}

		// Fetch latest release version from GitHub API.
		$api_url  = 'https://api.github.com/repos/CloudCannon/pagefind/releases/latest';
		$response = wp_remote_get( $api_url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			\WP_CLI::error( 'Failed to check latest Pagefind version: ' . $response->get_error_message() );
			return;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		$version = ltrim( $release['tag_name'] ?? '', 'v' );

		if ( empty( $version ) ) {
			\WP_CLI::error( 'Could not determine latest Pagefind version.' );
			return;
		}

		$filename     = "pagefind-v{$version}-{$platform}.tar.gz";
		$download_url = "https://github.com/CloudCannon/pagefind/releases/download/v{$version}/{$filename}";

		\WP_CLI::log( "Downloading Pagefind v{$version} for {$platform}..." );
		\WP_CLI::log( "  URL: {$download_url}" );

		$tmp_file = download_url( $download_url, 60 );
		if ( is_wp_error( $tmp_file ) ) {
			\WP_CLI::error( 'Download failed: ' . $tmp_file->get_error_message() );
			return;
		}

		// Extract the binary.
		$target_binary = $target_dir . '/pagefind';

		// Use tar to extract.
		$result = shell_exec( 'tar -xzf ' . escapeshellarg( $tmp_file ) . ' -C ' . escapeshellarg( $target_dir ) . ' pagefind 2>&1' );
		unlink( $tmp_file );

		if ( ! file_exists( $target_binary ) ) {
			\WP_CLI::error( "Extraction failed. Binary not found at {$target_binary}" );
			return;
		}

		chmod( $target_binary, 0755 );

		if ( ! is_executable( $target_binary ) ) {
			\WP_CLI::warning( "Could not set execute permission on {$target_binary}. You may need to run: chmod +x {$target_binary}" );
		}

		// Update settings to point to the downloaded binary.
		$settings['pagefind_binary'] = $target_binary;
		update_option( 'scolta_settings', $settings );

		\WP_CLI::success( "Pagefind v{$version} installed to {$target_binary}" );
		\WP_CLI::log( 'Settings updated — Scolta will now use this binary.' );
	}

	/**
	 * Run the Pagefind CLI and report results.
	 */
	private function run_pagefind( string $binary, string $build_dir, string $output_dir ): void {
		if ( ! is_dir( $build_dir ) ) {
			\WP_CLI::error( "Build directory does not exist: {$build_dir}" );
			return;
		}

		$glob_html  = glob( $build_dir . '/*.html' );
		$html_count = count( ! empty( $glob_html ) ? $glob_html : array() );
		if ( $html_count === 0 ) {
			\WP_CLI::error( "No HTML files in {$build_dir}. Export content first." );
			return;
		}

		if ( ! is_dir( $output_dir ) ) {
			mkdir( $output_dir, 0755, true );
		}

		$cmd = escapeshellcmd( $binary )
			. ' --site ' . escapeshellarg( $build_dir )
			. ' --output-path ' . escapeshellarg( $output_dir );

		\WP_CLI::log( "  Running: {$cmd}" );

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process     = proc_open( $cmd, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			\WP_CLI::error( 'Failed to start Pagefind process.' );
			return;
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$output  = '';
		$timeout = 300; // 5 minutes.
		$start   = time();

		while ( true ) {
			$chunk = fread( $pipes[1], 8192 );
			if ( $chunk !== false && $chunk !== '' ) {
				$output .= $chunk;
			}
			$chunk = fread( $pipes[2], 8192 );
			if ( $chunk !== false && $chunk !== '' ) {
				$output .= $chunk;
			}

			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$output .= stream_get_contents( $pipes[1] );
				$output .= stream_get_contents( $pipes[2] );
				break;
			}

			if ( ( time() - $start ) > $timeout ) {
				proc_terminate( $process, 15 );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				proc_close( $process );
				\WP_CLI::error( "Pagefind timed out after {$timeout}s. Try running it manually: {$cmd}" );
				return;
			}

			usleep( 100000 ); // 100ms poll.
		}

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		$success = file_exists( $output_dir . '/pagefind.js' );

		if ( $success ) {
			$glob_frags     = glob( $output_dir . '/fragment/*' );
			$fragment_count = count( ! empty( $glob_frags ) ? $glob_frags : array() );
			// Increment generation counter to invalidate cached expansions/summaries.
			$generation = (int) get_option( 'scolta_generation', 0 );
			update_option( 'scolta_generation', $generation + 1 );
			\WP_CLI::success( "Pagefind index built: {$html_count} files, {$fragment_count} fragments." );
		} else {
			\WP_CLI::error( "Pagefind build failed.\n{$output}" );
		}
	}

	/**
	 * Join post type names for display.
	 */
	private function join_types( array $types ): string {
		return implode( ', ', $types );
	}
}

// Register WP-CLI commands.
\WP_CLI::add_command( 'scolta', 'Scolta_CLI' );
