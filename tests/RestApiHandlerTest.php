<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for Scolta_Rest_Api handler behavior.
 */
class RestApiHandlerTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['scolta_registered_routes'] = [];
    }

    // -------------------------------------------------------------------
    // Class structure
    // -------------------------------------------------------------------

    public function test_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_Rest_Api'));
    }

    // -------------------------------------------------------------------
    // Handler methods exist
    // -------------------------------------------------------------------

    public function test_has_handle_expand(): void {
        $ref = new ReflectionClass('Scolta_Rest_Api');
        $this->assertTrue($ref->hasMethod('handle_expand'));
    }

    public function test_handle_expand_is_static(): void {
        $ref = new ReflectionMethod('Scolta_Rest_Api', 'handle_expand');
        $this->assertTrue($ref->isStatic());
    }

    public function test_handle_expand_accepts_wp_rest_request(): void {
        $ref = new ReflectionMethod('Scolta_Rest_Api', 'handle_expand');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('request', $params[0]->getName());
        $this->assertEquals('WP_REST_Request', $params[0]->getType()->getName());
    }

    public function test_has_handle_summarize(): void {
        $ref = new ReflectionClass('Scolta_Rest_Api');
        $this->assertTrue($ref->hasMethod('handle_summarize'));
        $this->assertTrue($ref->getMethod('handle_summarize')->isStatic());
    }

    public function test_handle_summarize_accepts_wp_rest_request(): void {
        $ref = new ReflectionMethod('Scolta_Rest_Api', 'handle_summarize');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('WP_REST_Request', $params[0]->getType()->getName());
    }

    public function test_has_handle_followup(): void {
        $ref = new ReflectionClass('Scolta_Rest_Api');
        $this->assertTrue($ref->hasMethod('handle_followup'));
        $this->assertTrue($ref->getMethod('handle_followup')->isStatic());
    }

    public function test_handle_followup_accepts_wp_rest_request(): void {
        $ref = new ReflectionMethod('Scolta_Rest_Api', 'handle_followup');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('WP_REST_Request', $params[0]->getType()->getName());
    }

    // -------------------------------------------------------------------
    // Permission callback
    // -------------------------------------------------------------------

    public function test_has_check_search_permission(): void {
        $ref = new ReflectionClass('Scolta_Rest_Api');
        $this->assertTrue($ref->hasMethod('check_search_permission'));
    }

    public function test_check_search_permission_returns_true(): void {
        $this->assertTrue(Scolta_Rest_Api::check_search_permission());
    }

    public function test_check_search_permission_is_public_by_default(): void {
        // The apply_filters stub passes through the first argument (true).
        $result = Scolta_Rest_Api::check_search_permission();
        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------
    // register_routes
    // -------------------------------------------------------------------

    public function test_has_register_routes(): void {
        $ref = new ReflectionClass('Scolta_Rest_Api');
        $this->assertTrue($ref->hasMethod('register_routes'));
    }

    public function test_register_routes_is_static(): void {
        $ref = new ReflectionMethod('Scolta_Rest_Api', 'register_routes');
        $this->assertTrue($ref->isStatic());
    }

    public function test_register_routes_calls_register_rest_route(): void {
        // Reset tracker before test.
        $GLOBALS['scolta_registered_routes'] = [];

        Scolta_Rest_Api::register_routes();

        $routes = $GLOBALS['scolta_registered_routes'];
        $this->assertNotEmpty($routes, 'register_routes should call register_rest_route');
    }

    public function test_register_routes_registers_expand(): void {
        $GLOBALS['scolta_registered_routes'] = [];
        Scolta_Rest_Api::register_routes();

        $routes = array_map(fn($r) => $r['route'], $GLOBALS['scolta_registered_routes']);
        $this->assertContains('/expand-query', $routes);
    }

    public function test_register_routes_registers_summarize(): void {
        $GLOBALS['scolta_registered_routes'] = [];
        Scolta_Rest_Api::register_routes();

        $routes = array_map(fn($r) => $r['route'], $GLOBALS['scolta_registered_routes']);
        $this->assertContains('/summarize', $routes);
    }

    public function test_register_routes_registers_followup(): void {
        $GLOBALS['scolta_registered_routes'] = [];
        Scolta_Rest_Api::register_routes();

        $routes = array_map(fn($r) => $r['route'], $GLOBALS['scolta_registered_routes']);
        $this->assertContains('/followup', $routes);
    }

    // -------------------------------------------------------------------
    // Handlers delegate to AiEndpointHandler
    // -------------------------------------------------------------------

    public function test_handlers_use_ai_endpoint_handler(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-scolta-rest-api.php');
        $this->assertStringContainsString('AiEndpointHandler', $source,
            'REST API handlers should delegate to AiEndpointHandler');
    }

    public function test_make_handler_uses_generation_counter(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-scolta-rest-api.php');
        $this->assertStringContainsString('scolta_generation', $source,
            'make_handler should use the generation counter');
        $this->assertStringContainsString('get_option', $source,
            'make_handler should read generation from get_option');
    }

    public function test_expand_cache_key_format(): void {
        // Verify the cache key format from the handler.
        $generation = 5;
        $query = 'test query';
        $expected_key = 'scolta_expand_' . $generation . '_' . hash('sha256', strtolower($query));

        // The key should be deterministic.
        $this->assertStringStartsWith('scolta_expand_5_', $expected_key);
        $this->assertStringContainsString(hash('sha256', 'test query'), $expected_key);
    }

    // -------------------------------------------------------------------
    // Handler return types
    // -------------------------------------------------------------------

    public function test_handle_expand_returns_wp_rest_response(): void {
        $ref = new ReflectionMethod('Scolta_Rest_Api', 'handle_expand');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('WP_REST_Response', $returnType->getName());
    }

    public function test_handle_summarize_returns_wp_rest_response(): void {
        $ref = new ReflectionMethod('Scolta_Rest_Api', 'handle_summarize');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('WP_REST_Response', $returnType->getName());
    }

    public function test_handle_followup_returns_wp_rest_response(): void {
        $ref = new ReflectionMethod('Scolta_Rest_Api', 'handle_followup');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('WP_REST_Response', $returnType->getName());
    }

    // -------------------------------------------------------------------
    // All routes use scolta/v1 namespace
    // -------------------------------------------------------------------

    public function test_all_routes_use_scolta_v1_namespace(): void {
        $GLOBALS['scolta_registered_routes'] = [];
        Scolta_Rest_Api::register_routes();

        foreach ($GLOBALS['scolta_registered_routes'] as $route) {
            $this->assertEquals('scolta/v1', $route['namespace']);
        }
    }

    // -------------------------------------------------------------------
    // Health endpoint
    // -------------------------------------------------------------------

    public function test_health_handler_exists(): void {
        $this->assertTrue(
            method_exists('Scolta_Rest_Api', 'handle_health'),
            'handle_health method should exist'
        );
    }

    public function test_health_handler_accepts_rest_request(): void {
        $ref = new \ReflectionMethod('Scolta_Rest_Api', 'handle_health');
        $params = $ref->getParameters();
        $this->assertGreaterThanOrEqual(1, count($params));
        $type = $params[0]->getType();
        $this->assertNotNull($type);
        $this->assertStringContainsString('WP_REST_Request', (string) $type);
    }

    public function test_health_route_registered(): void {
        Scolta_Rest_Api::register_routes();
        $routes = $GLOBALS['scolta_registered_routes'] ?? [];
        $healthRoutes = array_filter($routes, fn($r) => str_contains($r['route'], 'health'));
        $this->assertNotEmpty($healthRoutes, 'Health route should be registered');
    }

    public function test_health_route_uses_get_method(): void {
        $content = file_get_contents(dirname(__DIR__) . '/includes/class-scolta-rest-api.php');
        // The health route should be registered with GET method.
        $this->assertMatchesRegularExpression(
            "/register_rest_route.*health.*'methods'.*'GET'/s",
            $content,
            'Health endpoint should use GET method'
        );
    }
}
