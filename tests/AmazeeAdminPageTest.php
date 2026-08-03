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

    public function test_ajax_start_trial_needs_no_email(): void {
        // Replaces a test that asserted the opposite — that the demo rejected a
        // request without a valid email. Trying the demo must cost an operator
        // no input at all; an address is what the account path collects, because
        // amazee.ai needs one to issue a real account. Rejecting here put the
        // account path's cost on the cheapest way to evaluate Scolta's AI.
        // Bounded to this one handler's body: "Invalid email" and $_POST reads
        // are correct in the sign-in handler further down the same file, and a
        // whole-file match would find them there.
        $body = $this->methodBody( 'ajax_start_trial' );

        $this->assertStringContainsString(
            'provision()',
            $body,
            'The demo must call provision() with no email.'
        );
        $this->assertStringNotContainsString(
            'Invalid email',
            $body,
            'The demo must not reject a request for want of an email.'
        );
        $this->assertStringNotContainsString(
            "\$_POST['email']",
            $body,
            'The demo must not read an email from the request at all.'
        );
    }

    /**
     * The source of one method of the Amazee admin page, brace-matched.
     */
    private function methodBody( string $name ): string {
        $source = (string) file_get_contents(
            dirname( __DIR__ ) . '/admin/class-scolta-amazee-admin-page.php'
        );

        $start = strpos( $source, "function {$name}(" );
        $this->assertNotFalse( $start, "method {$name}() not found" );

        $open = strpos( $source, '{', $start );
        $depth = 0;
        for ( $i = $open; $i < strlen( $source ); $i++ ) {
            if ( $source[ $i ] === '{' ) {
                $depth++;
            } elseif ( $source[ $i ] === '}' ) {
                $depth--;
                if ( $depth === 0 ) {
                    return substr( $source, $open, $i - $open + 1 );
                }
            }
        }

        $this->fail( "unbalanced braces in {$name}()" );
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
