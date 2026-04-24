<?php
/**
 * PSR-3 logger bridge for WP-CLI.
 *
 * Routes IndexBuildOrchestrator log calls (memory telemetry, phase markers,
 * warnings) to WP-CLI output so operators see per-chunk memory stats and
 * phase boundaries during a long build.
 */

defined( 'ABSPATH' ) || exit;

use Psr\Log\AbstractLogger;

/**
 * Forwards PSR-3 log calls to WP-CLI output methods.
 *
 * - debug   → WP_CLI::debug() (visible only with --debug)
 * - info / notice → WP_CLI::log()
 * - warning → WP_CLI::warning()
 * - error and above → WP_CLI::warning() with an [error] prefix (non-fatal)
 *
 * @since 0.3.2
 */
class Scolta_WP_CLI_Logger extends AbstractLogger {

	/**
	 * Log a message at the given level.
	 *
	 * @param mixed   $level   PSR-3 log level string.
	 * @param string  $message Message with optional {placeholder} tokens.
	 * @param mixed[] $context Values to interpolate into the message.
	 */
	public function log( $level, $message, array $context = array() ): void {
		$formatted = $this->interpolate( (string) $message, $context );
		match ( (string) $level ) {
			'debug'                              => \WP_CLI::debug( $formatted, 'scolta' ),
			'warning'                            => \WP_CLI::warning( $formatted ),
			'error', 'critical', 'alert',
			'emergency'                          => \WP_CLI::warning( '[error] ' . $formatted ),
			default                              => \WP_CLI::log( $formatted ),
		};
	}

	/**
	 * Interpolate context values into a message template.
	 *
	 * Replaces {key} tokens with their string representations.
	 *
	 * @param string  $message  The message template.
	 * @param mixed[] $context  Map of placeholder keys to values.
	 * @return string           The interpolated message.
	 */
	private function interpolate( string $message, array $context ): string {
		$replace = array();
		foreach ( $context as $key => $val ) {
			$has_to_string = is_object( $val ) && method_exists( $val, '__toString' );
			if ( is_scalar( $val ) || $has_to_string ) {
				$replace[ '{' . $key . '}' ] = (string) $val;
			}
		}
		return strtr( $message, $replace );
	}
}
