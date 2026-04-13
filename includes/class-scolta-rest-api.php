<?php
/**
 * REST API endpoints for Scolta AI features.
 *
 * WordPress REST API is the right tool for this — it handles
 * authentication, nonce verification, permission callbacks, and
 * schema validation out of the box. No need to build custom AJAX
 * handlers like the bad old days.
 *
 * Endpoints mirror Drupal's exactly so scolta.js works identically:
 *   POST /wp-json/scolta/v1/expand-query
 *   POST /wp-json/scolta/v1/summarize
 *   POST /wp-json/scolta/v1/followup
 */

defined('ABSPATH') || exit;

use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiEndpointHandler;

class Scolta_Rest_Api {

    /**
     * Register all Scolta REST routes.
     */
    public static function register_routes(): void {
        register_rest_route('scolta/v1', '/expand-query', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_expand'],
            'permission_callback' => [self::class, 'check_search_permission'],
            'args' => [
                'query' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        return is_string($value) && strlen($value) > 0 && strlen($value) <= 500;
                    },
                ],
            ],
        ]);

        register_rest_route('scolta/v1', '/summarize', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_summarize'],
            'permission_callback' => [self::class, 'check_search_permission'],
            'args' => [
                'query' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        return is_string($value) && strlen($value) > 0 && strlen($value) <= 500;
                    },
                ],
                'context' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'wp_kses_post',
                    'validate_callback' => function ($value) {
                        return is_string($value) && strlen($value) > 0 && strlen($value) <= 50000;
                    },
                ],
            ],
        ]);

        register_rest_route('scolta/v1', '/followup', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_followup'],
            'permission_callback' => [self::class, 'check_search_permission'],
            'args' => [
                'messages' => [
                    'required'          => true,
                    'type'              => 'array',
                    'sanitize_callback' => function ($value) {
                        if (!is_array($value)) {
                            return [];
                        }
                        return array_map(function ($msg) {
                            return [
                                'role'    => sanitize_text_field($msg['role'] ?? ''),
                                'content' => wp_kses_post($msg['content'] ?? ''),
                            ];
                        }, $value);
                    },
                    'validate_callback' => function ($value) {
                        if (!is_array($value) || empty($value)) {
                            return false;
                        }
                        foreach ($value as $msg) {
                            if (empty($msg['role']) || empty($msg['content'])) {
                                return false;
                            }
                            if (!in_array($msg['role'], ['user', 'assistant'], true)) {
                                return false;
                            }
                        }
                        // Last message must be from user.
                        return end($value)['role'] === 'user';
                    },
                ],
            ],
        ]);

        register_rest_route('scolta/v1', '/health', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_health'],
            'permission_callback' => [self::class, 'check_search_permission'],
        ]);

        register_rest_route('scolta/v1', '/build-progress', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_build_progress'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);

        register_rest_route('scolta/v1', '/rebuild-now', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_rebuild_now'],
            'permission_callback' => fn() => current_user_can('manage_options'),
            'args' => [
                'force' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
    }

    /**
     * Permission check: anyone can search.
     *
     * AI features are public by default (same as Drupal's implementation).
     * Per-IP rate limiting is enforced via check_rate_limit() inside each
     * handler. Sites that want to restrict AI to logged-in users can
     * filter 'scolta_search_permission'.
     */
    public static function check_search_permission(): bool {
        /**
         * Filter whether the current request is allowed to use Scolta AI.
         *
         * @param bool $allowed Default true (public access).
         */
        return apply_filters('scolta_search_permission', true);
    }

    /**
     * Check per-IP rate limit for AI endpoints.
     *
     * Uses WordPress transients (one-minute windows) to count requests per IP.
     * Returns a WP_REST_Response with 429 status if the limit is exceeded,
     * or null if the request is allowed.
     *
     * Default: 10 AI requests/minute/IP. Filterable via 'scolta_ai_rate_limit'.
     *
     * @return \WP_REST_Response|null 429 response if rate-limited, null if allowed.
     */
    public static function check_rate_limit(): ?\WP_REST_Response {
        $limit = (int) apply_filters('scolta_ai_rate_limit', 10);
        if ($limit <= 0) {
            return null; // Rate limiting disabled.
        }

        $ip = self::get_client_ip();
        $window = (int) floor(time() / 60); // 1-minute window.
        $key = 'scolta_rl_' . md5($ip) . '_' . $window;

        $count = (int) get_transient($key);
        if ($count >= $limit) {
            $response = new \WP_REST_Response(
                ['error' => __('Too many requests. Please slow down.', 'scolta')],
                429
            );
            $response->header('Retry-After', '60');
            return $response;
        }

        // Increment counter; TTL is 90s so the key always expires after the window.
        set_transient($key, $count + 1, 90);
        return null;
    }

    /**
     * Get the client IP address, respecting common proxy headers.
     *
     * @return string
     */
    private static function get_client_ip(): string {
        // Prefer X-Forwarded-For when behind a trusted proxy.
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Build an AiEndpointHandler from current WordPress config.
     */
    private static function make_handler(\Scolta_Ai_Service $ai): AiEndpointHandler {
        $config = $ai->get_config();
        $generation = (int) get_option('scolta_generation', 0);

        return new AiEndpointHandler(
            $ai,
            $config->cacheTtl > 0 ? new \Scolta_Cache_Driver() : new NullCacheDriver(),
            $generation,
            $config->cacheTtl,
            $config->maxFollowUps,
            new \Scolta_Prompt_Enricher(),
            $config->aiLanguages,
        );
    }

    /**
     * POST /wp-json/scolta/v1/expand-query
     *
     * Expands a search query into 2-4 related terms using AI.
     */
    public static function handle_expand(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_response = self::check_rate_limit();
        if ($rate_limit_response !== null) {
            return $rate_limit_response;
        }

        $ai = Scolta_Ai_Service::from_options();
        $handler = self::make_handler($ai);

        $result = $handler->handleExpandQuery($request->get_param('query'));

        if ($result['ok']) {
            return new \WP_REST_Response($result['data'], 200);
        }

        if (isset($result['exception']) && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[scolta] Expand failed: ' . $result['exception']->getMessage() . "\n" . $result['exception']->getTraceAsString());
        }

        return new \WP_REST_Response(['error' => $result['error']], $result['status']);
    }

    /**
     * POST /wp-json/scolta/v1/summarize
     */
    public static function handle_summarize(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_response = self::check_rate_limit();
        if ($rate_limit_response !== null) {
            return $rate_limit_response;
        }

        $ai = Scolta_Ai_Service::from_options();
        $handler = self::make_handler($ai);

        $result = $handler->handleSummarize(
            $request->get_param('query'),
            $request->get_param('context'),
        );

        if ($result['ok']) {
            return new \WP_REST_Response($result['data'], 200);
        }

        if (isset($result['exception']) && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[scolta] Summarize failed: ' . $result['exception']->getMessage() . "\n" . $result['exception']->getTraceAsString());
        }

        return new \WP_REST_Response(['error' => $result['error']], $result['status']);
    }

    /**
     * POST /wp-json/scolta/v1/followup
     */
    public static function handle_followup(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_response = self::check_rate_limit();
        if ($rate_limit_response !== null) {
            return $rate_limit_response;
        }

        $ai = Scolta_Ai_Service::from_options();
        $handler = self::make_handler($ai);

        $result = $handler->handleFollowUp($request->get_param('messages'));

        if ($result['ok']) {
            return new \WP_REST_Response($result['data'], 200);
        }

        if (isset($result['exception']) && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[scolta] Follow-up failed: ' . $result['exception']->getMessage() . "\n" . $result['exception']->getTraceAsString());
        }

        $response = ['error' => $result['error']];
        if (isset($result['limit'])) {
            $response['limit'] = $result['limit'];
        }

        return new \WP_REST_Response($response, $result['status']);
    }

    /**
     * GET /wp-json/scolta/v1/health
     *
     * Returns service status for monitoring tools.
     */
    public static function handle_health(\WP_REST_Request $request): \WP_REST_Response {
        $settings = get_option('scolta_settings', []);
        $ai = \Scolta_Ai_Service::from_options();

        $checker = new \Tag1\Scolta\Health\HealthChecker(
            config: $ai->get_config(),
            indexOutputDir: $settings['output_dir'] ?? ABSPATH . 'scolta-pagefind',
            pagefindBinaryPath: $settings['pagefind_binary'] ?? null,
            projectDir: ABSPATH,
        );

        return new \WP_REST_Response($checker->check(), 200);
    }

    /**
     * GET /wp-json/scolta/v1/build-progress
     *
     * Returns current build status for admin polling.
     * Includes stale lock detection to recover from crashed builds.
     *
     * @since 0.2.0
     */
    public static function handle_build_progress(\WP_REST_Request $request): \WP_REST_Response {
        $status = get_option('scolta_build_status', ['status' => 'idle']);

        // Stale lock detection: if lock has exceeded TTL, clear it.
        $lock_time = get_transient(Scolta_Rebuild_Scheduler::LOCK_KEY);
        if ($lock_time && (time() - (int) $lock_time) > Scolta_Rebuild_Scheduler::LOCK_TTL) {
            delete_transient(Scolta_Rebuild_Scheduler::LOCK_KEY);
            $status = [
                'status'  => 'idle',
                'message' => __('Previous rebuild timed out. Lock cleared.', 'scolta'),
            ];
            update_option('scolta_build_status', $status);
        }

        return new \WP_REST_Response($status, 200);
    }

    /**
     * POST /wp-json/scolta/v1/rebuild-now
     *
     * Triggers an immediate rebuild via Action Scheduler.
     *
     * @since 0.2.0
     */
    public static function handle_rebuild_now(\WP_REST_Request $request): \WP_REST_Response {
        if (get_transient(Scolta_Rebuild_Scheduler::LOCK_KEY)) {
            return new \WP_REST_Response(['error' => __('Rebuild already in progress.', 'scolta')], 409);
        }

        $force = $request->get_param('force') ?? false;
        Scolta_Rebuild_Scheduler::start_rebuild((bool) $force);
        return new \WP_REST_Response(['message' => __('Rebuild scheduled.', 'scolta')], 200);
    }
}
