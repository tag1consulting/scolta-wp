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
    }

    /**
     * Permission check: anyone can search.
     *
     * AI features are public by default (same as Drupal's implementation).
     * Rate limiting is handled at the application level. Sites that want
     * to restrict AI to logged-in users can filter this.
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
}
