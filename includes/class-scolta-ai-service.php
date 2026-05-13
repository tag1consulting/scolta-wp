<?php
/**
 * AI service adapter for WordPress.
 *
 * Extends the shared AiServiceAdapter base class, adding only
 * WordPress-specific behavior:
 *   - WP 7.0+: Detects and uses the WordPress AI Client SDK (native, multi-provider)
 *   - WP 6.x:  Falls back to scolta-php's built-in AiClient (Anthropic + OpenAI)
 *
 * API key resolution (in priority order):
 *   1. Amazee.ai stored credentials (litellm token via OpenAI-compatible endpoint)
 *   2. SCOLTA_API_KEY environment variable (production-safe)
 *   3. SCOLTA_API_KEY constant in wp-config.php
 *   4. Legacy: scolta_settings option in database (migration warning shown)
 *
 * Controllers call message() and conversation() — they never touch
 * AiClient directly. The dual-path fallback is invisible to callers.
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Service\AiServiceAdapter;

class Scolta_Ai_Service extends AiServiceAdapter {

	/**
	 * Budget handler, set when Amazee credentials are active.
	 *
	 * @var Scolta_Amazee_Budget_Handler|null
	 */
	private ?Scolta_Amazee_Budget_Handler $budget_handler = null;

	/**
	 * Create from WordPress options.
	 *
	 * Priority: env var / wp-config.php constant / legacy database key >
	 * Amazee.ai stored credentials. Amazee is only used when no explicit
	 * key is configured so users who set their own key are never silently
	 * rerouted to the Amazee LiteLLM proxy.
	 */
	public static function from_options(): self {
		$settings    = get_option( 'scolta_settings', array() );
		$explicit_key = self::get_api_key();

		if ( $explicit_key !== '' ) {
			// User has their own key — use it, never touch Amazee credentials.
			$settings['ai_api_key'] = $explicit_key;
			return new self( ScoltaConfig::fromArray( $settings ) );
		}

		$storage = new Scolta_Amazee_Config_Storage();
		$creds   = $storage->load();
		if ( $creds !== null ) {
			$settings['ai_provider'] = 'openai';
			$settings['ai_api_key']  = $creds['litellm_token'];
			$settings['ai_base_url'] = $creds['litellm_api_url'];
		}

		$config  = ScoltaConfig::fromArray( $settings );
		$service = new self( $config );
		if ( $creds !== null ) {
			$service->budget_handler = new Scolta_Amazee_Budget_Handler();
		}
		return $service;
	}

	// -- Snake-case aliases for inherited camelCase methods --

	/**
	 * Get the Scolta configuration.
	 */
	public function get_config(): ScoltaConfig {
		return $this->getConfig();
	}

	/**
	 * Get the expand-query system prompt.
	 *
	 * Checks cached resolved prompts first, falls back to
	 * DefaultPrompts::resolve() (pure PHP, zero-cost).
	 */
	public function get_expand_prompt(): string {
		return $this->getCachedPrompt( 'expand_query' ) ?? $this->getExpandPrompt();
	}

	/**
	 * Get the summarize system prompt.
	 *
	 * Checks cached resolved prompts first, falls back to
	 * DefaultPrompts::resolve() (pure PHP, zero-cost).
	 */
	public function get_summarize_prompt(): string {
		return $this->getCachedPrompt( 'summarize' ) ?? $this->getSummarizePrompt();
	}

	/**
	 * Get the follow-up system prompt.
	 *
	 * Checks cached resolved prompts first, falls back to
	 * DefaultPrompts::resolve() (pure PHP, zero-cost).
	 */
	public function get_follow_up_prompt(): string {
		return $this->getCachedPrompt( 'follow_up' ) ?? $this->getFollowUpPrompt();
	}

	/**
	 * Get a cached resolved prompt, if available.
	 *
	 * Only returns a cached prompt when no custom override is configured,
	 * since custom overrides bypass the default templates entirely.
	 *
	 * @param string $name Prompt name (expand_query, summarize, follow_up).
	 * @return string|null The cached prompt, or null if not cached or custom override is set.
	 */
	private function getCachedPrompt( string $name ): ?string {
		// Custom overrides bypass caching.
		$config     = $this->getConfig();
		$custom_map = array(
			'expand_query' => $config->promptExpandQuery,
			'summarize'    => $config->promptSummarize,
			'follow_up'    => $config->promptFollowUp,
		);
		if ( ! empty( $custom_map[ $name ] ?? '' ) ) {
			return null;
		}

		$cached = get_option( 'scolta_resolved_prompts', array() );
		return ! empty( $cached[ $name ] ) ? $cached[ $name ] : null;
	}

	// -- WordPress-specific API key resolution --

	/**
	 * Get the API key from the best available source.
	 *
	 * Priority: environment variable > wp-config.php constant > database (legacy).
	 * Environment variables are the only production-safe path. The database
	 * fallback exists solely for backward compatibility with existing installs.
	 */
	public static function get_api_key(): string {
		// Primary: environment variable.
		$env = getenv( 'SCOLTA_API_KEY' );
		if ( $env !== false && $env !== '' ) {
			return $env;
		}

		// Also check $_ENV and $_SERVER (some hosts populate these differently).
		// Server environment variables: not user input, no sanitization needed.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( ! empty( $_ENV['SCOLTA_API_KEY'] ) ) {
			return $_ENV['SCOLTA_API_KEY'];
		}
		if ( ! empty( $_SERVER['SCOLTA_API_KEY'] ) ) {
			return $_SERVER['SCOLTA_API_KEY'];
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		// wp-config.php constant (better than database, not as good as env var).
		if ( defined( 'SCOLTA_API_KEY' ) && SCOLTA_API_KEY !== '' ) {
			return SCOLTA_API_KEY;
		}

		// Legacy: database option (backward compatibility only).
		$settings = get_option( 'scolta_settings', array() );
		return $settings['ai_api_key'] ?? '';
	}

	/**
	 * Detect where the API key is coming from, for status display.
	 *
	 * @return string One of 'amazee', 'env', 'constant', 'database', or 'none'.
	 */
	public static function get_api_key_source(): string {
		$storage = new Scolta_Amazee_Config_Storage();
		if ( $storage->load() !== null ) {
			return 'amazee';
		}
		$env = getenv( 'SCOLTA_API_KEY' );
		if ( $env !== false && $env !== '' ) {
			return 'env';
		}
		if ( ! empty( $_ENV['SCOLTA_API_KEY'] ) || ! empty( $_SERVER['SCOLTA_API_KEY'] ) ) {
			return 'env';
		}
		if ( defined( 'SCOLTA_API_KEY' ) && SCOLTA_API_KEY !== '' ) {
			return 'constant';
		}
		$settings = get_option( 'scolta_settings', array() );
		if ( ! empty( $settings['ai_api_key'] ) ) {
			return 'database';
		}
		return 'none';
	}

	/**
	 * Check whether Amazee.ai credentials are stored and active.
	 */
	public function is_amazee_active(): bool {
		return $this->budget_handler !== null;
	}

	/**
	 * Check if the WordPress AI Client SDK is available (WP 7.0+).
	 */
	public function has_wp_ai_sdk(): bool {
		return class_exists( '\WordPress\AI\Client' );
	}

	// -- Snake-case alias for built-in client access --

	/**
	 * Get the built-in AiClient (lazily instantiated).
	 */
	public function get_client(): \Tag1\Scolta\AiClient {
		return $this->getClient();
	}

	// -- Framework AI integration --

	/**
	 * {@inheritdoc}
	 */
	// phpcs:ignore Generic.Files.LineLength.MaxExceeded
	protected function tryFrameworkAi( string $systemPrompt, string $userMessage, int $maxTokens ): ?string {
		if ( ! $this->has_wp_ai_sdk() ) {
			return null;
		}

		try {
			return $this->message_via_wp_sdk( $systemPrompt, $userMessage, $maxTokens );
		} catch ( \Exception $e ) {
			// SDK not configured or provider missing — fall through to built-in.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$msg = '[scolta] WP AI SDK failed, falling back to built-in: ';
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only logging guarded by WP_DEBUG.
				error_log( $msg . $e->getMessage() );
			}
			return null;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	// phpcs:ignore Generic.Files.LineLength.MaxExceeded
	protected function tryFrameworkConversation( string $systemPrompt, array $messages, int $maxTokens ): ?string {
		if ( ! $this->has_wp_ai_sdk() ) {
			return null;
		}

		try {
			return $this->conversation_via_wp_sdk( $systemPrompt, $messages, $maxTokens );
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$msg = '[scolta] WP AI SDK conversation failed, falling back: ';
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only logging guarded by WP_DEBUG.
				error_log( $msg . $e->getMessage() );
			}
			return null;
		}
	}

	/**
	 * Send a message via the WordPress AI Client SDK.
	 */
	// phpcs:ignore Generic.Files.LineLength.MaxExceeded
	private function message_via_wp_sdk( string $system_prompt, string $user_message, int $max_tokens ): string {
		/** @var \WordPress\AI\Client $ai */
		$ai = \WordPress\AI\Client::instance();

		$response = $ai->prompt(
			array(
				'system'     => $system_prompt,
				'user'       => $user_message,
				'max_tokens' => $max_tokens,
			)
		);

		return $response->get_text();
	}

	/**
	 * Send a conversation via the WordPress AI Client SDK.
	 */
	// phpcs:ignore Generic.Files.LineLength.MaxExceeded
	private function conversation_via_wp_sdk( string $system_prompt, array $messages, int $max_tokens ): string {
		/** @var \WordPress\AI\Client $ai */
		$ai = \WordPress\AI\Client::instance();

		$sdk_messages = array();
		foreach ( $messages as $msg ) {
			$sdk_messages[] = array(
				'role'    => $msg['role'],
				'content' => $msg['content'],
			);
		}

		$response = $ai->prompt(
			array(
				'system'     => $system_prompt,
				'messages'   => $sdk_messages,
				'max_tokens' => $max_tokens,
			)
		);

		return $response->get_text();
	}

	// -- Amazee.ai budget exception handling --

	/**
	 * {@inheritdoc}
	 *
	 * Converts Amazee.ai budget errors to AmazeeBudgetExceededException.
	 *
	 * @param string $systemPrompt System prompt.
	 * @param string $userMessage  User message.
	 * @param int    $maxTokens    Maximum tokens.
	 * @return string AI response.
	 */
	// phpcs:ignore Generic.Files.LineLength.MaxExceeded
	public function message( string $systemPrompt, string $userMessage, int $maxTokens = 512 ): string {
		try {
			return parent::message( $systemPrompt, $userMessage, $maxTokens );
		} catch ( \RuntimeException $e ) {
			$this->handle_possible_budget_exception( $e );
			throw $e;
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * Converts Amazee.ai budget errors to AmazeeBudgetExceededException.
	 *
	 * @param string $systemPrompt System prompt.
	 * @param array  $messages     Conversation messages.
	 * @param int    $maxTokens    Maximum tokens.
	 * @return string AI response.
	 */
	// phpcs:ignore Generic.Files.LineLength.MaxExceeded
	public function conversation( string $systemPrompt, array $messages, int $maxTokens = 512 ): string {
		try {
			return parent::conversation( $systemPrompt, $messages, $maxTokens );
		} catch ( \RuntimeException $e ) {
			$this->handle_possible_budget_exception( $e );
			throw $e;
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * Converts Amazee.ai budget errors to AmazeeBudgetExceededException.
	 *
	 * @param string $operation    Operation name.
	 * @param string $systemPrompt System prompt.
	 * @param string $userMessage  User message.
	 * @param int    $maxTokens    Maximum tokens.
	 * @return string AI response.
	 */
	// phpcs:ignore Generic.Files.LineLength.MaxExceeded
	public function messageForOperation( string $operation, string $systemPrompt, string $userMessage, int $maxTokens = 512 ): string {
		try {
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			return parent::messageForOperation( $operation, $systemPrompt, $userMessage, $maxTokens );
		} catch ( \RuntimeException $e ) {
			$this->handle_possible_budget_exception( $e );
			throw $e;
		}
	}

	/**
	 * Convert a budget-exceeded RuntimeException to AmazeeBudgetExceededException.
	 *
	 * No-op if the exception message does not contain the Amazee budget signal.
	 *
	 * @param \RuntimeException $e The exception to inspect.
	 * @throws AmazeeBudgetExceededException When the budget message is detected.
	 */
	private function handle_possible_budget_exception( \RuntimeException $e ): void {
		if ( ! str_contains( $e->getMessage(), 'Budget has been exceeded!' ) ) {
			return;
		}
		$budget_exception = new AmazeeBudgetExceededException( $e );
		if ( $this->budget_handler !== null ) {
			$this->budget_handler->handle( $budget_exception );
		}
		throw $budget_exception;
	}
}
