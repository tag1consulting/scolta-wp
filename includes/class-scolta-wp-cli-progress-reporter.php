<?php
/**
 * WP-CLI progress reporter for the PHP indexer pipeline.
 *
 * Routes IndexBuildOrchestrator progress callbacks to WP-CLI's native
 * progress bar so operators see live chunk-by-chunk feedback instead of
 * a silent 50-minute build with only a final success line.
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Index\ProgressReporterInterface;

/**
 * Implements ProgressReporterInterface using WP-CLI's progress bar.
 *
 * @since 0.3.2
 */
class Scolta_WP_CLI_Progress_Reporter implements ProgressReporterInterface {

	/** @var object|null WP-CLI progress bar returned by make_progress_bar(). */
	private $bar = null;

	/**
	 * Start the progress bar.
	 *
	 * @param int    $totalSteps Total number of steps (chunks or pages).
	 * @param string $label      Human-readable label for the bar.
	 */
	public function start( int $totalSteps, string $label ): void {
		\WP_CLI::log( $label . '...' );
		$this->bar = \WP_CLI\Utils\make_progress_bar( $label, $totalSteps );
	}

	/**
	 * Advance the progress bar.
	 *
	 * @param int         $steps  Number of steps completed.
	 * @param string|null $detail Optional detail string (unused by WP-CLI bar).
	 */
	public function advance( int $steps = 1, ?string $detail = null ): void {
		if ( null !== $this->bar ) {
			$this->bar->tick( $steps );
		}
	}

	/**
	 * Finish the progress bar and print an optional summary.
	 *
	 * @param string|null $summary Optional summary line to print after the bar.
	 */
	public function finish( ?string $summary = null ): void {
		if ( null !== $this->bar ) {
			$this->bar->finish();
			$this->bar = null;
		}
		if ( null !== $summary ) {
			\WP_CLI::log( '  ' . $summary );
		}
	}
}
