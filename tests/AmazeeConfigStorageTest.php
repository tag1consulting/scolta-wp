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

    // -------------------------------------------------------------------
    // AuthenticatedCipher adoption (encrypt-then-MAC) + legacy migration
    // -------------------------------------------------------------------

    /**
     * Produce a blob in the pre-1.0.5 unauthenticated format
     * (base64(iv + AES-256-CBC ciphertext), key from AUTH_KEY salt).
     */
    private function legacy_encrypt( string $value ): string {
        $key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $iv  = random_bytes( 16 );
        return base64_encode( $iv . openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv ) );
    }

    public function test_token_at_rest_is_authenticated_envelope(): void {
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'secret-token', 'https://api.example.com', 'eu-west-1' );

        $raw = get_option( 'scolta_amazee_credentials' );
        $this->assertTrue(
            \Tag1\Scolta\Util\AuthenticatedCipher::isEnvelope( $raw['litellm_token'] ),
            'stored tokens must use the scolta-enc:v1 authenticated envelope'
        );
    }

    public function test_legacy_blob_migrates_to_envelope_on_first_load(): void {
        update_option(
            'scolta_amazee_credentials',
            array(
                'litellm_token'   => $this->legacy_encrypt( 'legacy-token' ),
                'litellm_api_url' => 'https://api.example.com',
                'region'          => 'us-east-1',
            )
        );

        $storage = new Scolta_Amazee_Config_Storage();
        $loaded  = $storage->load();

        $this->assertSame( 'legacy-token', $loaded['litellm_token'], 'legacy CBC blobs must still decrypt' );

        $raw = get_option( 'scolta_amazee_credentials' );
        $this->assertTrue(
            \Tag1\Scolta\Util\AuthenticatedCipher::isEnvelope( $raw['litellm_token'] ),
            'a successful legacy decrypt must immediately rewrite the credential as an authenticated envelope'
        );
        $this->assertSame( 'us-east-1', $raw['region'], 'migration must preserve the other credential fields' );
        $this->assertSame( 'legacy-token', $storage->load()['litellm_token'], 'the migrated envelope must round-trip' );
    }

    public function test_tampered_envelope_surfaces_notice_instead_of_silent_disconnect(): void {
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'secret-token', 'https://api.example.com', 'eu-west-1' );

        // Flip a character in the base64 payload — the MAC must reject it.
        $raw   = get_option( 'scolta_amazee_credentials' );
        $token = $raw['litellm_token'];
        $token[ strlen( $token ) - 2 ] = ( $token[ strlen( $token ) - 2 ] === 'A' ) ? 'B' : 'A';
        $raw['litellm_token'] = $token;
        update_option( 'scolta_amazee_credentials', $raw );

        $this->assertNull( $storage->load(), 'a tampered credential must not decrypt' );
        $this->assertNotFalse(
            get_transient( Scolta_Amazee_Config_Storage::DECRYPT_FAILURE_TRANSIENT ),
            'a decrypt failure must queue the reconnect admin notice, not silently report "not connected"'
        );

        ob_start();
        Scolta_Amazee_Config_Storage::maybe_render_decrypt_failure_notice();
        $output = ob_get_clean();
        $this->assertStringContainsString( 'notice-error', $output );
        $this->assertStringContainsString( 'could not be decrypted', $output );
        $this->assertFalse(
            get_transient( Scolta_Amazee_Config_Storage::DECRYPT_FAILURE_TRANSIENT ),
            'the notice clears once an administrator has seen it'
        );
    }

    public function test_undecryptable_legacy_blob_surfaces_notice(): void {
        // Truncated legacy blob (shorter than IV + 1 block) — deterministic
        // decrypt failure, the same end state as an AUTH_KEY rotation.
        update_option(
            'scolta_amazee_credentials',
            array(
                'litellm_token'   => base64_encode( random_bytes( 8 ) ),
                'litellm_api_url' => 'https://api.example.com',
                'region'          => 'us-east-1',
            )
        );

        $storage = new Scolta_Amazee_Config_Storage();
        $this->assertNull( $storage->load() );
        $this->assertNotFalse(
            get_transient( Scolta_Amazee_Config_Storage::DECRYPT_FAILURE_TRANSIENT ),
            'a failed legacy decrypt (e.g. AUTH_KEY rotation) must surface the reconnect notice'
        );
    }

    public function test_admin_init_hooks_decrypt_failure_notice(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
        $this->assertStringContainsString(
            "array( Scolta_Amazee_Config_Storage::class, 'maybe_render_decrypt_failure_notice' )",
            $source,
            'Scolta_Admin::init() must hook the decrypt-failure notice renderer unconditionally'
        );
    }

    public function test_no_hand_rolled_encryption_remains(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-amazee-config-storage.php' );
        $this->assertStringNotContainsString(
            'openssl_encrypt',
            $source,
            'all encryption goes through AuthenticatedCipher; only the legacy DECRYPT path may remain'
        );
    }
}
