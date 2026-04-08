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
     * POST /wp-json/scolta/v1/expand-query
     *
     * Expands a search query into 2-4 related terms using AI.
     * Caches results in WordPress transients.
     */
    public static function handle_expand(\WP_REST_Request $request): \WP_REST_Response {
        $query = $request->get_param('query');
        $ai = Scolta_Ai_Service::from_options();
        $config = $ai->get_config();

        // WordPress transient cache with generation counter for rebuild invalidation.
        $generation = (int) get_option('scolta_generation', 0);
        $cache_key = 'scolta_expand_' . $generation . '_' . hash('sha256', strtolower($query));
        if ($config->cacheTtl > 0) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return new \WP_REST_Response($cached, 200);
            }
        }

        try {
            $response = $ai->message(
                $ai->get_expand_prompt(),
                'Expand this search query: ' . $query,
                512,
            );

            // Strip markdown code fences if Claude wraps the JSON.
            $cleaned = trim($response);
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
            $cleaned = trim($cleaned);

            $terms = json_decode($cleaned, true);
            if (!is_array($terms) || count($terms) < 2) {
                $terms = [$query];
            }

            if ($config->cacheTtl > 0) {
                set_transient($cache_key, $terms, $config->cacheTtl);
            }

            return new \WP_REST_Response($terms, 200);
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[scolta] Expand failed: ' . $e->getMessage());
            }
            return new \WP_REST_Response(
                ['error' => 'Query expansion unavailable'],
                503
            );
        }
    }

    /**
     * POST /wp-json/scolta/v1/summarize
     */
    public static function handle_summarize(\WP_REST_Request $request): \WP_REST_Response {
        $query = $request->get_param('query');
        $context = $request->get_param('context');

        $ai = Scolta_Ai_Service::from_options();
        $user_message = "Search query: {$query}\n\nSearch result excerpts:\n{$context}";

        try {
            $summary = $ai->message(
                $ai->get_summarize_prompt(),
                $user_message,
                512,
            );

            return new \WP_REST_Response(['summary' => $summary], 200);
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[scolta] Summarize failed: ' . $e->getMessage());
            }
            return new \WP_REST_Response(
                ['error' => 'Summarization unavailable'],
                503
            );
        }
    }

    /**
     * POST /wp-json/scolta/v1/followup
     */
    public static function handle_followup(\WP_REST_Request $request): \WP_REST_Response {
        $messages = $request->get_param('messages');
        $ai = Scolta_Ai_Service::from_options();
        $config = $ai->get_config();
        $max_followups = $config->maxFollowUps;

        // Enforce follow-up limit server-side.
        $followups_so_far = intdiv(count($messages) - 2, 2);
        if ($followups_so_far >= $max_followups) {
            return new \WP_REST_Response([
                'error' => 'Follow-up limit reached',
                'limit' => $max_followups,
            ], 429);
        }

        try {
            $response = $ai->conversation(
                $ai->get_follow_up_prompt(),
                $messages,
                512,
            );

            $remaining = $max_followups - $followups_so_far - 1;
            return new \WP_REST_Response([
                'response'  => $response,
                'remaining' => max(0, $remaining),
            ], 200);
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[scolta] Follow-up failed: ' . $e->getMessage());
            }
            return new \WP_REST_Response(
                ['error' => 'Follow-up unavailable'],
                503
            );
        }
    }
}
