<?php
/**
 * WordPress-specific Amazee.ai credential storage.
 *
 * Stores LiteLLM credentials in the WordPress options table.
 * The token is encrypted at rest using AES-256-CBC with a key
 * derived from the site's AUTH_KEY salt.
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

/**
 * Implements ConfigStorageInterface using the WordPress options API.
 */
class Scolta_Amazee_Config_Storage implements ConfigStorageInterface {

	/**
	 * WordPress option key for stored credentials.
	 *
	 * @var string
	 */
	private const OPTION_KEY = 'scolta_amazee_credentials';

	/**
	 * Store LiteLLM credentials, encrypting the token at rest.
	 *
	 * @param string $litellmToken  LiteLLM API token.
	 * @param string $litellmApiUrl LiteLLM proxy base URL.
	 * @param string $region        Region identifier.
	 */
	public function store( string $litellmToken, string $litellmApiUrl, string $region ): void {
		update_option(
			self::OPTION_KEY,
			array(
				'litellm_token'   => $this->encrypt( $litellmToken ),
				'litellm_api_url' => $litellmApiUrl,
				'region'          => $region,
			),
			false
		);
	}

	/**
	 * Load stored credentials, decrypting the token.
	 *
	 * @return array{litellm_token: string, litellm_api_url: string, region: string}|null
	 */
	public function load(): ?array {
		$data = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $data ) || empty( $data['litellm_token'] ) ) {
			return null;
		}
		$token = $this->decrypt( $data['litellm_token'] );
		if ( $token === '' ) {
			return null;
		}
		return array(
			'litellm_token'   => $token,
			'litellm_api_url' => $data['litellm_api_url'] ?? '',
			'region'          => $data['region'] ?? '',
		);
	}

	/**
	 * Delete stored credentials.
	 */
	public function clear(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Encrypt a plaintext string with AES-256-CBC.
	 *
	 * The returned value is base64-encoded IV + ciphertext.
	 *
	 * @param string $value Plaintext to encrypt.
	 * @return string Encrypted, base64-encoded value.
	 */
	private function encrypt( string $value ): string {
		$key        = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$iv         = random_bytes( 16 );
		$ciphertext = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt a value previously produced by encrypt().
	 *
	 * Returns an empty string on failure (bad key, corrupted data, etc.).
	 *
	 * @param string $encoded Base64-encoded IV + ciphertext.
	 * @return string Decrypted plaintext, or empty string on failure.
	 */
	private function decrypt( string $encoded ): string {
		$key  = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$data = base64_decode( $encoded, true );
		if ( $data === false || strlen( $data ) < 17 ) {
			return '';
		}
		$iv         = substr( $data, 0, 16 );
		$ciphertext = substr( $data, 16 );
		$result     = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return $result !== false ? $result : '';
	}
}
