<?php
/**
 * WordPress prompt enricher using the filter system.
 *
 * Bridges scolta-php's PromptEnricherInterface with WordPress filters,
 * allowing themes and plugins to modify AI prompts via apply_filters().
 *
 * Usage:
 *   add_filter('scolta_prompt', function (string $prompt, string $name, array $context): string {
 *       if ($name === 'summarize') {
 *           $prompt .= "\n\nAlways mention our 30-day return policy.";
 *       }
 *       return $prompt;
 *   }, 10, 3);
 *
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Prompt\PromptEnricherInterface;

class Scolta_Prompt_Enricher implements PromptEnricherInterface {

	/**
	 * Enrich a resolved prompt by running it through WordPress filters.
	 *
	 * @param string $resolvedPrompt The prompt text after WASM template resolution.
	 * @param string $promptName     The prompt identifier ('expand_query', 'summarize', etc.).
	 * @param array  $context        Additional context (query, search results, messages).
	 * @return string The filtered prompt text.
	 */
	// phpcs:ignore Generic.Files.LineLength.MaxExceeded
	public function enrich( string $resolvedPrompt, string $promptName, array $context = array() ): string {
		/**
		 * Filter the AI prompt before it is sent to the LLM provider.
		 *
		 * @since 0.2.0
		 *
		 * @param string $resolvedPrompt The prompt text after WASM template resolution.
		 * @param string $promptName     The prompt identifier ('expand_query', 'summarize', etc.).
		 * @param array  $context        Additional context (query, search results, messages).
		 */
		return apply_filters( 'scolta_prompt', $resolvedPrompt, $promptName, $context );
	}
}
