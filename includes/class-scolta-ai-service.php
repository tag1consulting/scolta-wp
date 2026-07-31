<?php
/**
 * AI service adapter for WordPress.
 *
 * Extends the shared AiServiceAdapter base class, adding only
 * WordPress-specific behavior:
 *   - WP 7.0+: Detects and uses the WordPress AI Client SDK (native, multi-provider)
 *   - WP 6.x:  Falls back to scolta-php's built-in AiClient (Anthropic + OpenAI)
 *
 * API key resolution (in priority order), applied by
 * Tag1\Scolta\Config\ApiKeyResolver:
 *   1. SCOLTA_API_KEY environment variable (production-safe)
 *   2. SCOLTA_API_KEY constant in wp-config.php
 *   3. Legacy: scolta_settings option in database (migration warning shown)
 *   4. Amazee.ai stored credentials (litellm token via OpenAI-compatible endpoint)
 *
 * An explicit key beating stored Amazee credentials is deliberate: a site
 * that configured its own provider is never silently rerouted through the
 * managed gateway. This docblock used to list Amazee first, which is the
 * order the old get_api_key_source() applied and the opposite of the order
 * from_options() actually used.
 *
 * Controllers call message() and conversation() — they never touch
 * AiClient directly. The dual-path fallback is invisible to callers.
 *
 * @package Scolta
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\AiProvider\Amazee\BudgetAwareProviderDecorator;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Config\AmazeeCredentials;
use Tag1\Scolta\Config\ApiKeyResolver;
use Tag1\Scolta\Config\ResolvedApiKey;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * WordPress adapter around the shared scolta-php AI service.
 */
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
		$settings = get_option( 'scolta_settings', array() );

		// One resolution, shared with every surface that reports on it. The
		// key, its source and the provider that goes with it arrive together,
		// so the admin screens, /health and WP-CLI cannot describe this
		// differently from the client that is about to send it
		// (tag1consulting/scolta-php#252).
		$resolved = self::resolve_api_key();

		if ( $resolved->isAmazee() ) {
			$settings['ai_provider'] = $resolved->provider;
			$settings['ai_base_url'] = $resolved->baseUrl;

			if ( $resolved->isConfigured() ) {
				$settings['ai_api_key'] = $resolved->key;
				// Substitute the gateway-scoped model names, which is the only
				// place they are ever read. ai_model / ai_expansion_model keep
				// whatever provider-native IDs the administrator chose, so
				// flipping back to a direct key restores them untouched — and
				// flipping back to Amazee restores the alias without
				// re-provisioning.
				// Non-empty by construction: the resolution only carries a key
				// when model resolution has succeeded, which is exactly the
				// assertion that this is set.
				$settings['ai_model'] = (string) ( $settings['amazee_model'] ?? '' );
				// An operator's native expansion model must not leak to the
				// gateway, which would reject it, so an unresolved expansion
				// model falls back to the gateway's primary rather than theirs.
				$gateway_expansion              = $settings['amazee_expansion_model'] ?? '';
				$settings['ai_expansion_model'] = (string) $gateway_expansion;
			} else {
				// Half-provisioned: credentials are stored but model resolution
				// never succeeded, so settings still carry the shipped dated
				// default — which the Amazee LiteLLM gateway rejects with HTTP
				// 400, breaking AI permanently and silently. The resolver
				// withholds the key for exactly this state: a key-less client
				// throws ApiKeyMissingException, which the REST controllers
				// degrade to an unexpanded/no-summary HTTP 200 (the same path
				// as a wholly unconfigured site), never a 400. It self-heals
				// when provisioning next re-resolves against the stored key.
				unset( $settings['ai_api_key'] );
			}
		} elseif ( $resolved->isConfigured() ) {
			// The operator's own key. Amazee credentials, if any are stored,
			// are left untouched — and are reported as overridden rather than
			// silently ignored.
			$settings['ai_api_key'] = $resolved->key;
		}

		$config  = ScoltaConfig::fromArray( $settings );
		$service = new self( $config );
		if ( $resolved->isAmazee() ) {
			$storage                 = new Scolta_Amazee_Config_Storage();
			$service->budget_handler = new Scolta_Amazee_Budget_Handler();
			// Amazee.ai path only. Policy: when the stored credentials are no
			// longer accepted, KeyExpiryRecovery records the failure so /health
			// reports AI degraded and sets a persistent marker the admin UI
			// reads to route the operator to reconnect/upgrade
			// (Scolta_Amazee_Reauth_Handler). It never requests fresh
			// credentials on this path and never retries — the request degrades
			// gracefully. The gate is the resolution rather than the presence
			// of credentials, so a user's own key is never touched even when
			// credentials are also stored; budget-exhaustion is excluded by
			// KeyExpiryRecovery and follows the budget path instead.
			$service->setKeyExpiryRecovery(
				new KeyExpiryRecovery(
					storage: $storage,
					cache: new Scolta_Cache_Driver(),
					logger: new Scolta_Logger()
				)
			);
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
		// Passing no credentials is what makes this the explicit-only
		// accessor; the ordering itself is the resolver's, applied over the
		// same candidate list resolve_api_key() uses, so "which explicit key
		// wins" is answered in one place rather than in two that can drift.
		return ApiKeyResolver::resolve( self::explicit_key_candidates() )->key;
	}

	/**
	 * The explicit key candidates, in this platform's precedence order.
	 *
	 * @return array<string, string> Candidate keys, keyed by ApiKeySource backing value.
	 */
	private static function explicit_key_candidates(): array {
		// Primary: environment variable. Some hosts populate $_ENV/$_SERVER
		// instead, so all three spellings feed the one 'env' candidate.
		$env = getenv( 'SCOLTA_API_KEY' );
		if ( $env === false || $env === '' ) {
			if ( ! empty( $_ENV['SCOLTA_API_KEY'] ) ) {
				$env = sanitize_text_field( wp_unslash( $_ENV['SCOLTA_API_KEY'] ) );
			} elseif ( ! empty( $_SERVER['SCOLTA_API_KEY'] ) ) {
				$env = sanitize_text_field( wp_unslash( $_SERVER['SCOLTA_API_KEY'] ) );
			} else {
				$env = '';
			}
		}

		$settings = get_option( 'scolta_settings', array() );

		// wp-config.php constant: better than the database, not as good as an
		// environment variable.
		$constant = ( defined( 'SCOLTA_API_KEY' ) && SCOLTA_API_KEY !== '' ) ? SCOLTA_API_KEY : '';

		return array(
			'env'      => $env,
			'constant' => $constant,
			// Legacy: database option, backward compatibility only.
			'database' => $settings['ai_api_key'] ?? '',
		);
	}

	/**
	 * Resolve the effective API key, its source and its provider.
	 *
	 * The single derivation. Everything that reports on the API key — the
	 * admin screens, /health, WP-CLI, and from_options() itself — takes its
	 * answer from here rather than working it out again.
	 *
	 * That is the fix for tag1consulting/scolta-php#252: from_options() gave
	 * an explicit key priority over stored Amazee.ai credentials while
	 * get_api_key_source() checked the credential store first, so a site
	 * running on a perfectly valid SCOLTA_API_KEY was reported as connected
	 * to Amazee.ai on every surface it has.
	 */
	public static function resolve_api_key(): ResolvedApiKey {
		$settings = get_option( 'scolta_settings', array() );
		$provider = $settings['ai_provider'] ?? '';
		$provider = ( is_string( $provider ) && $provider !== '' ) ? $provider : 'anthropic';

		return ApiKeyResolver::resolve(
			self::explicit_key_candidates(),
			AmazeeCredentials::fromStorage(
				new Scolta_Amazee_Config_Storage(),
				operatorChosen: $provider === 'amazee',
				// A half-provisioned install — credentials stored, model
				// resolution never completed — reports Amazee as its source
				// but hands back no key.
				modelResolved: scolta_amazee_models_resolved()
			),
			$provider
		);
	}

	/**
	 * Detect where the API key is coming from, for status display.
	 *
	 * @return string The resolved source: 'env', 'constant', 'database',
	 *   'amazee:operator', 'amazee:auto', or 'none'. The two Amazee cases
	 *   replace the former single 'amazee' — a provider the operator selected
	 *   and a free trial that provisioned itself mean different things to
	 *   somebody reading a status line.
	 */
	public static function get_api_key_source(): string {
		return self::resolve_api_key()->source->value;
	}

	/**
	 * Check whether Amazee.ai credentials are stored and active.
	 *
	 * Derived from the shared resolution, never from the credential store:
	 * credentials that lost to an explicit key are stored, not active.
	 */
	public function is_amazee_active(): bool {
		return self::resolve_api_key()->isAmazee();
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
	 *
	 * @param string $systemPrompt System prompt for the request.
	 * @param string $userMessage  User message to send.
	 * @param int    $maxTokens    Maximum tokens for the response.
	 */
	protected function tryFrameworkAi(
		string $systemPrompt,
		string $userMessage,
		int $maxTokens
	): ?string {
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
	 *
	 * @param string $systemPrompt System prompt for the conversation.
	 * @param array  $messages     Conversation messages (role/content pairs).
	 * @param int    $maxTokens    Maximum tokens for the response.
	 */
	protected function tryFrameworkConversation(
		string $systemPrompt,
		array $messages,
		int $maxTokens
	): ?string {
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
	 *
	 * @param string $system_prompt System prompt for the request.
	 * @param string $user_message  User message to send.
	 * @param int    $max_tokens    Maximum tokens for the response.
	 */
	private function message_via_wp_sdk(
		string $system_prompt,
		string $user_message,
		int $max_tokens
	): string {
		/**
		 * The WP AI Client SDK singleton.
		 *
		 * @var \WordPress\AI\Client $ai
		 */
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
	 *
	 * @param string $system_prompt System prompt for the conversation.
	 * @param array  $messages      Conversation messages (role/content pairs).
	 * @param int    $max_tokens    Maximum tokens for the response.
	 */
	private function conversation_via_wp_sdk(
		string $system_prompt,
		array $messages,
		int $max_tokens
	): string {
		/**
		 * The WP AI Client SDK singleton.
		 *
		 * @var \WordPress\AI\Client $ai
		 */
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

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- overrides a camelCase vendor base method.
	/**
	 * {@inheritdoc}
	 *
	 * Converts Amazee.ai budget errors to AmazeeBudgetExceededException, notifies
	 * the budget handler, and re-throws. No-op unless scolta-php's
	 * BudgetAwareProviderDecorator::isBudgetError() recognizes the exception
	 * (it owns the Amazee budget signal — no duplicated magic string here).
	 * Invoked by the base AI methods' catch block.
	 *
	 * Named in camelCase (not the WordPress snake_case convention) because it
	 * overrides the protected hook on the vendor base class AiServiceAdapter.
	 *
	 * @param \RuntimeException $e The exception to inspect.
	 * @throws AmazeeBudgetExceededException When the budget message is detected.
	 */
	protected function handlePossibleBudgetException( \RuntimeException $e ): void {
		if ( ! BudgetAwareProviderDecorator::isBudgetError( $e ) ) {
			return;
		}
		$budget_exception = new AmazeeBudgetExceededException( $e );
		if ( $this->budget_handler !== null ) {
			$this->budget_handler->handle( $budget_exception );
		}
		throw $budget_exception;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
