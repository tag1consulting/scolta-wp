<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

/**
 * Tests for Scolta_Amazee_Config_Storage.
 */
class AmazeeConfigStorageTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
    }

    public function test_class_exists(): void {
        $this->assertTrue( class_exists( 'Scolta_Amazee_Config_Storage' ) );
    }

    public function test_implements_config_storage_interface(): void {
        $ref = new \ReflectionClass( 'Scolta_Amazee_Config_Storage' );
        $this->assertTrue( $ref->implementsInterface( ConfigStorageInterface::class ) );
    }

    public function test_load_returns_null_when_nothing_stored(): void {
        $storage = new Scolta_Amazee_Config_Storage();
        $this->assertNull( $storage->load() );
    }

    public function test_store_and_load_roundtrip(): void {
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'my-token', 'https://api.example.com', 'us-east-1' );

        $loaded = $storage->load();
        $this->assertIsArray( $loaded );
        $this->assertSame( 'my-token', $loaded['litellm_token'] );
        $this->assertSame( 'https://api.example.com', $loaded['litellm_api_url'] );
        $this->assertSame( 'us-east-1', $loaded['region'] );
    }

    public function test_token_is_encrypted_at_rest(): void {
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'secret-token', 'https://api.example.com', 'eu-west-1' );

        // The raw option should NOT contain the plaintext token.
        $raw = get_option( 'scolta_amazee_credentials' );
        $this->assertIsArray( $raw );
        $this->assertNotSame( 'secret-token', $raw['litellm_token'] );
    }

    public function test_clear_removes_stored_credentials(): void {
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'token', 'https://api.example.com', 'us-west-2' );
        $this->assertNotNull( $storage->load() );

        $storage->clear();
        $this->assertNull( $storage->load() );
    }

    public function test_load_returns_null_when_token_empty(): void {
        // Simulate corrupt/missing token value.
        update_option( 'scolta_amazee_credentials', array( 'litellm_token' => '', 'litellm_api_url' => 'x', 'region' => 'x' ) );
        $storage = new Scolta_Amazee_Config_Storage();
        $this->assertNull( $storage->load() );
    }

    public function test_store_option_key(): void {
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'tok', 'https://x.example.com', 'ap-southeast-1' );
        $this->assertArrayHasKey( 'scolta_amazee_credentials', $GLOBALS['wp_options'] );
    }
}
