<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for Scolta_Amazee_Admin_Page structure and hook registration.
 */
class AmazeeAdminPageTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options']   = [];
        $GLOBALS['test_json_response'] = null;
        $GLOBALS['test_user_meta']     = [];
        $GLOBALS['test_current_user_id'] = 1;
    }

    public function test_class_exists(): void {
        $this->assertTrue( class_exists( 'Scolta_Amazee_Admin_Page' ) );
    }

    public function test_has_init_method(): void {
        $this->assertTrue( method_exists( 'Scolta_Amazee_Admin_Page', 'init' ) );
    }

    public function test_has_add_submenu_method(): void {
        $this->assertTrue( method_exists( 'Scolta_Amazee_Admin_Page', 'add_submenu' ) );
    }

    public function test_has_enqueue_scripts_method(): void {
        $this->assertTrue( method_exists( 'Scolta_Amazee_Admin_Page', 'enqueue_scripts' ) );
    }

    public function test_has_render_page_method(): void {
        $this->assertTrue( method_exists( 'Scolta_Amazee_Admin_Page', 'render_page' ) );
    }

    public function test_has_ajax_start_trial_method(): void {
        $this->assertTrue( method_exists( 'Scolta_Amazee_Admin_Page', 'ajax_start_trial' ) );
    }

    public function test_has_ajax_request_code_method(): void {
        $this->assertTrue( method_exists( 'Scolta_Amazee_Admin_Page', 'ajax_request_code' ) );
    }

    public function test_has_ajax_verify_code_method(): void {
        $this->assertTrue( method_exists( 'Scolta_Amazee_Admin_Page', 'ajax_verify_code' ) );
    }

    public function test_has_ajax_list_regions_method(): void {
        $this->assertTrue( method_exists( 'Scolta_Amazee_Admin_Page', 'ajax_list_regions' ) );
    }

    public function test_has_ajax_connect_method(): void {
        $this->assertTrue( method_exists( 'Scolta_Amazee_Admin_Page', 'ajax_connect' ) );
    }

    public function test_has_ajax_disconnect_method(): void {
        $this->assertTrue( method_exists( 'Scolta_Amazee_Admin_Page', 'ajax_disconnect' ) );
    }

    public function test_ajax_disconnect_clears_credentials(): void {
        // Store credentials first.
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'tok', 'https://api.example.com', 'us-east-1' );
        $this->assertNotNull( $storage->load() );

        // Simulate AJAX disconnect.
        $_POST = [];
        try {
            Scolta_Amazee_Admin_Page::ajax_disconnect();
        } catch ( \RuntimeException $e ) {
            // wp_send_json_success exits via RuntimeException in test stub.
        }

        $response = $GLOBALS['test_json_response'];
        $this->assertTrue( $response['success'] );
        $this->assertSame( 'start', $response['data']['step'] );
        $this->assertNull( $storage->load() );
    }

    public function test_ajax_start_trial_rejects_invalid_email(): void {
        $_POST = array( 'email' => 'not-an-email' );
        try {
            Scolta_Amazee_Admin_Page::ajax_start_trial();
        } catch ( \RuntimeException $e ) {
            // wp_send_json_error exits via RuntimeException in test stub.
        }

        $response = $GLOBALS['test_json_response'];
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'Invalid email', $response['data']['message'] );
    }

    public function test_ajax_request_code_rejects_invalid_email(): void {
        $_POST = array( 'email' => 'bad-email' );
        try {
            Scolta_Amazee_Admin_Page::ajax_request_code();
        } catch ( \RuntimeException $e ) {
            // wp_send_json_error exits via RuntimeException in test stub.
        }

        $response = $GLOBALS['test_json_response'];
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'Invalid email', $response['data']['message'] );
    }

    public function test_ajax_verify_code_fails_without_flow_state(): void {
        $_POST = array( 'code' => '123456' );
        try {
            Scolta_Amazee_Admin_Page::ajax_verify_code();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $response = $GLOBALS['test_json_response'];
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'expired', $response['data']['message'] );
    }

    public function test_ajax_connect_fails_without_session_token(): void {
        $_POST = array( 'region_id' => 'us-east-1' );
        try {
            Scolta_Amazee_Admin_Page::ajax_connect();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $response = $GLOBALS['test_json_response'];
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'expired', $response['data']['message'] );
    }

    public function test_assets_js_file_exists(): void {
        $root = dirname( __DIR__ );
        $this->assertFileExists( $root . '/assets/js/amazee-admin.js' );
    }

    public function test_assets_css_file_exists(): void {
        $root = dirname( __DIR__ );
        $this->assertFileExists( $root . '/assets/css/amazee-admin.css' );
    }
}
