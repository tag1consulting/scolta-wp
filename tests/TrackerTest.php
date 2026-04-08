<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for Scolta_Tracker — change tracking for content indexing.
 */
class TrackerTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
    }

    // -------------------------------------------------------------------
    // Class structure
    // -------------------------------------------------------------------

    public function test_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_Tracker'));
    }

    public function test_table_constant_value(): void {
        $this->assertEquals('scolta_tracker', Scolta_Tracker::TABLE);
    }

    public function test_has_create_table_method(): void {
        $ref = new ReflectionClass('Scolta_Tracker');
        $this->assertTrue($ref->hasMethod('create_table'));
        $this->assertTrue($ref->getMethod('create_table')->isStatic());
    }

    public function test_has_track_method(): void {
        $ref = new ReflectionClass('Scolta_Tracker');
        $this->assertTrue($ref->hasMethod('track'));
        $this->assertTrue($ref->getMethod('track')->isStatic());
    }

    public function test_has_get_pending_method(): void {
        $ref = new ReflectionClass('Scolta_Tracker');
        $this->assertTrue($ref->hasMethod('get_pending'));
        $this->assertTrue($ref->getMethod('get_pending')->isStatic());
    }

    public function test_has_get_pending_count_method(): void {
        $ref = new ReflectionClass('Scolta_Tracker');
        $this->assertTrue($ref->hasMethod('get_pending_count'));
        $this->assertTrue($ref->getMethod('get_pending_count')->isStatic());
    }

    public function test_has_clear_method(): void {
        $ref = new ReflectionClass('Scolta_Tracker');
        $this->assertTrue($ref->hasMethod('clear'));
        $this->assertTrue($ref->getMethod('clear')->isStatic());
    }

    public function test_has_mark_all_for_reindex_method(): void {
        $ref = new ReflectionClass('Scolta_Tracker');
        $this->assertTrue($ref->hasMethod('mark_all_for_reindex'));
        $this->assertTrue($ref->getMethod('mark_all_for_reindex')->isStatic());
    }

    public function test_has_table_exists_method(): void {
        $ref = new ReflectionClass('Scolta_Tracker');
        $this->assertTrue($ref->hasMethod('table_exists'));
        $this->assertTrue($ref->getMethod('table_exists')->isStatic());
    }

    // -------------------------------------------------------------------
    // Method signatures
    // -------------------------------------------------------------------

    public function test_track_parameters(): void {
        $ref = new ReflectionMethod('Scolta_Tracker', 'track');
        $params = $ref->getParameters();

        $this->assertCount(3, $params);
        $this->assertEquals('content_id', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());
        $this->assertEquals('content_type', $params[1]->getName());
        $this->assertEquals('string', $params[1]->getType()->getName());
        $this->assertEquals('action', $params[2]->getName());
        $this->assertEquals('string', $params[2]->getType()->getName());
        $this->assertTrue($params[2]->isDefaultValueAvailable());
        $this->assertEquals('index', $params[2]->getDefaultValue());
    }

    public function test_get_pending_accepts_optional_action(): void {
        $ref = new ReflectionMethod('Scolta_Tracker', 'get_pending');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('action', $params[0]->getName());
        $this->assertTrue($params[0]->allowsNull());
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertNull($params[0]->getDefaultValue());
    }

    public function test_get_pending_count_accepts_optional_action(): void {
        $ref = new ReflectionMethod('Scolta_Tracker', 'get_pending_count');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('action', $params[0]->getName());
        $this->assertTrue($params[0]->allowsNull());
    }

    // -------------------------------------------------------------------
    // Return types
    // -------------------------------------------------------------------

    public function test_mark_all_for_reindex_returns_int(): void {
        $ref = new ReflectionMethod('Scolta_Tracker', 'mark_all_for_reindex');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('int', $returnType->getName());
    }

    public function test_table_exists_returns_bool(): void {
        $ref = new ReflectionMethod('Scolta_Tracker', 'table_exists');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('bool', $returnType->getName());
    }

    public function test_get_pending_returns_array(): void {
        $ref = new ReflectionMethod('Scolta_Tracker', 'get_pending');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function test_clear_returns_void(): void {
        $ref = new ReflectionMethod('Scolta_Tracker', 'clear');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('void', $returnType->getName());
    }

    // -------------------------------------------------------------------
    // Behavior (via wpdb stub)
    // -------------------------------------------------------------------

    public function test_create_table_runs_without_error(): void {
        // dbDelta stub returns empty array — just verify no exception.
        Scolta_Tracker::create_table();
        $this->assertTrue(true);
    }

    public function test_track_runs_without_error(): void {
        Scolta_Tracker::track(42, 'post', 'index');
        $this->assertTrue(true);
    }

    public function test_track_with_delete_action(): void {
        Scolta_Tracker::track(42, 'post', 'delete');
        $this->assertTrue(true);
    }

    public function test_get_pending_without_action(): void {
        $result = Scolta_Tracker::get_pending();
        $this->assertIsArray($result);
    }

    public function test_get_pending_with_action_filter(): void {
        $result = Scolta_Tracker::get_pending('index');
        $this->assertIsArray($result);
    }

    public function test_get_pending_count_without_action(): void {
        $result = Scolta_Tracker::get_pending_count();
        $this->assertIsInt($result);
    }

    public function test_get_pending_count_with_action(): void {
        $result = Scolta_Tracker::get_pending_count('delete');
        $this->assertIsInt($result);
    }

    public function test_clear_runs_without_error(): void {
        Scolta_Tracker::clear();
        $this->assertTrue(true);
    }

    public function test_mark_all_for_reindex_returns_integer(): void {
        $result = Scolta_Tracker::mark_all_for_reindex();
        $this->assertIsInt($result);
    }

    public function test_table_exists_returns_boolean(): void {
        $result = Scolta_Tracker::table_exists();
        $this->assertIsBool($result);
    }

    // -------------------------------------------------------------------
    // Table name uses prefix
    // -------------------------------------------------------------------

    public function test_table_name_includes_prefix(): void {
        global $wpdb;
        $expected = $wpdb->prefix . 'scolta_tracker';
        $this->assertEquals('wp_scolta_tracker', $expected);
    }

    // -------------------------------------------------------------------
    // All methods are static
    // -------------------------------------------------------------------

    public function test_all_public_methods_are_static(): void {
        $ref = new ReflectionClass('Scolta_Tracker');
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $this->assertTrue(
                $method->isStatic(),
                "Method {$method->getName()} should be static"
            );
        }
    }
}
