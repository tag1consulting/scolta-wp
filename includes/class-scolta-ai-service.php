<?php
/**
 * AI service adapter for WordPress.
 *
 * Dual-path AI provider support:
 *   - WP 7.0+: Detects and uses the WordPress AI Client SDK (native, multi-provider)
 *   - WP 6.x:  Falls back to scolta-php's built-in AiClient (Anthropic + OpenAI)
 *
 * API key resolution (in priority order):
 *   1. SCOLTA_API_KEY environment variable (production-safe)
 *   2. SCOLTA_API_KEY constant in wp-config.php
 *   3. Legacy: scolta_settings option in database (migration warning shown)
 *
 * Controllers call message() and conversation() — they never touch
 * AiClient directly. The dual-path fallback is invisible to callers.
 */

defined('ABSPATH') || exit;

use Tag1\Scolta\AiClient;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Prompt\DefaultPrompts;

class Scolta_Ai_Service {

    private ScoltaConfig $config;
    private ?AiClient $client = null;

    public function __construct(ScoltaConfig $config) {
        $this->config = $config;
    }

    /**
     * Create from WordPress options, with API key from environment.
     */
    public static function from_options(): self {
        $settings = get_option('scolta_settings', []);
        // Override with environment-sourced API key.
        $settings['ai_api_key'] = self::get_api_key();
        $config = ScoltaConfig::fromArray($settings);
        return new self($config);
    }

    public function get_config(): ScoltaConfig {
        return $this->config;
    }

    /**
     * Get the API key from the best available source.
     *
     * Priority: environment variable > wp-config.php constant > database (legacy).
     * Environment variables are the only production-safe path. The database
     * fallback exists solely for backward compatibility with existing installs.
     */
    public static function get_api_key(): string {
        // Primary: environment variable.
        $env = getenv('SCOLTA_API_KEY');
        if ($env !== false && $env !== '') {
            return $env;
        }

        // Also check $_ENV and $_SERVER (some hosts populate these differently).
        if (!empty($_ENV['SCOLTA_API_KEY'])) {
            return $_ENV['SCOLTA_API_KEY'];
        }
        if (!empty($_SERVER['SCOLTA_API_KEY'])) {
            return $_SERVER['SCOLTA_API_KEY'];
        }

        // wp-config.php constant (better than database, not as good as env var).
        if (defined('SCOLTA_API_KEY') && SCOLTA_API_KEY !== '') {
            return SCOLTA_API_KEY;
        }

        // Legacy: database option (backward compatibility only).
        $settings = get_option('scolta_settings', []);
        return $settings['ai_api_key'] ?? '';
    }

    /**
     * Detect where the API key is coming from, for status display.
     *
     * @return string One of 'env', 'constant', 'database', or 'none'.
     */
    public static function get_api_key_source(): string {
        $env = getenv('SCOLTA_API_KEY');
        if ($env !== false && $env !== '') {
            return 'env';
        }
        if (!empty($_ENV['SCOLTA_API_KEY']) || !empty($_SERVER['SCOLTA_API_KEY'])) {
            return 'env';
        }
        if (defined('SCOLTA_API_KEY') && SCOLTA_API_KEY !== '') {
            return 'constant';
        }
        $settings = get_option('scolta_settings', []);
        if (!empty($settings['ai_api_key'])) {
            return 'database';
        }
        return 'none';
    }

    /**
     * Check if the WordPress AI Client SDK is available (WP 7.0+).
     */
    public function has_wp_ai_sdk(): bool {
        return class_exists('\WordPress\AI\Client');
    }

    /**
     * Send a single-turn AI message.
     *
     * Tries WP AI Client SDK first (if available and configured), then
     * falls back to scolta-php's built-in AiClient.
     */
    public function message(string $system_prompt, string $user_message, int $max_tokens = 512): string {
        // Path 1: WordPress AI Client SDK (WP 7.0+).
        if ($this->has_wp_ai_sdk()) {
            try {
                return $this->message_via_wp_sdk($system_prompt, $user_message, $max_tokens);
            } catch (\Exception $e) {
                // SDK not configured or provider missing — fall through to built-in.
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[scolta] WP AI SDK failed, falling back to built-in: ' . $e->getMessage());
                }
            }
        }

        // Path 2: Built-in AiClient from scolta-php.
        return $this->get_client()->message($system_prompt, $user_message, $max_tokens);
    }

    /**
     * Send a multi-turn conversation.
     */
    public function conversation(string $system_prompt, array $messages, int $max_tokens = 512): string {
        // Path 1: WordPress AI Client SDK (WP 7.0+).
        if ($this->has_wp_ai_sdk()) {
            try {
                return $this->conversation_via_wp_sdk($system_prompt, $messages, $max_tokens);
            } catch (\Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[scolta] WP AI SDK conversation failed, falling back: ' . $e->getMessage());
                }
            }
        }

        // Path 2: Built-in.
        return $this->get_client()->conversation($system_prompt, $messages, $max_tokens);
    }

    /**
     * Get the expand-query system prompt.
     */
    public function get_expand_prompt(): string {
        if (!empty($this->config->promptExpandQuery)) {
            return $this->config->promptExpandQuery;
        }
        return DefaultPrompts::resolve(
            DefaultPrompts::EXPAND_QUERY,
            $this->config->siteName,
            $this->config->siteDescription,
        );
    }

    /**
     * Get the summarize system prompt.
     */
    public function get_summarize_prompt(): string {
        if (!empty($this->config->promptSummarize)) {
            return $this->config->promptSummarize;
        }
        return DefaultPrompts::resolve(
            DefaultPrompts::SUMMARIZE,
            $this->config->siteName,
            $this->config->siteDescription,
        );
    }

    /**
     * Get the follow-up system prompt.
     */
    public function get_follow_up_prompt(): string {
        if (!empty($this->config->promptFollowUp)) {
            return $this->config->promptFollowUp;
        }
        return DefaultPrompts::resolve(
            DefaultPrompts::FOLLOW_UP,
            $this->config->siteName,
            $this->config->siteDescription,
        );
    }

    // camelCase aliases for AiEndpointHandler duck-typed interface.
    public function getExpandPrompt(): string { return $this->get_expand_prompt(); }
    public function getSummarizePrompt(): string { return $this->get_summarize_prompt(); }
    public function getFollowUpPrompt(): string { return $this->get_follow_up_prompt(); }

    /**
     * Get the built-in AiClient (lazily instantiated).
     */
    private function get_client(): AiClient {
        if ($this->client === null) {
            $this->client = new AiClient($this->config->toAiClientConfig());
        }
        return $this->client;
    }

    /**
     * Send a message via the WordPress AI Client SDK.
     */
    private function message_via_wp_sdk(string $system_prompt, string $user_message, int $max_tokens): string {
        /** @var \WordPress\AI\Client $ai */
        $ai = \WordPress\AI\Client::instance();

        $response = $ai->prompt([
            'system'     => $system_prompt,
            'user'       => $user_message,
            'max_tokens' => $max_tokens,
        ]);

        return $response->get_text();
    }

    /**
     * Send a conversation via the WordPress AI Client SDK.
     */
    private function conversation_via_wp_sdk(string $system_prompt, array $messages, int $max_tokens): string {
        /** @var \WordPress\AI\Client $ai */
        $ai = \WordPress\AI\Client::instance();

        $sdk_messages = [];
        foreach ($messages as $msg) {
            $sdk_messages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        $response = $ai->prompt([
            'system'     => $system_prompt,
            'messages'   => $sdk_messages,
            'max_tokens' => $max_tokens,
        ]);

        return $response->get_text();
    }
}
