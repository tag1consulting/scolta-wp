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
 *   1. SCOLTA_API_KEY environment variable (production-safe)
 *   2. SCOLTA_API_KEY constant in wp-config.php
 *   3. Legacy: scolta_settings option in database (migration warning shown)
 *
 * Controllers call message() and conversation() — they never touch
 * AiClient directly. The dual-path fallback is invisible to callers.
 */

defined('ABSPATH') || exit;

use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Service\AiServiceAdapter;

class Scolta_Ai_Service extends AiServiceAdapter {

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

    // -- Snake-case aliases for inherited camelCase methods --

    /**
     * Get the Scolta configuration.
     */
    public function get_config(): ScoltaConfig {
        return $this->getConfig();
    }

    /**
     * Get the expand-query system prompt.
     */
    public function get_expand_prompt(): string {
        return $this->getExpandPrompt();
    }

    /**
     * Get the summarize system prompt.
     */
    public function get_summarize_prompt(): string {
        return $this->getSummarizePrompt();
    }

    /**
     * Get the follow-up system prompt.
     */
    public function get_follow_up_prompt(): string {
        return $this->getFollowUpPrompt();
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
    protected function tryFrameworkAi(string $systemPrompt, string $userMessage, int $maxTokens): ?string {
        if (!$this->has_wp_ai_sdk()) {
            return null;
        }

        try {
            return $this->message_via_wp_sdk($systemPrompt, $userMessage, $maxTokens);
        } catch (\Exception $e) {
            // SDK not configured or provider missing — fall through to built-in.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[scolta] WP AI SDK failed, falling back to built-in: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function tryFrameworkConversation(string $systemPrompt, array $messages, int $maxTokens): ?string {
        if (!$this->has_wp_ai_sdk()) {
            return null;
        }

        try {
            return $this->conversation_via_wp_sdk($systemPrompt, $messages, $maxTokens);
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[scolta] WP AI SDK conversation failed, falling back: ' . $e->getMessage());
            }
            return null;
        }
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
