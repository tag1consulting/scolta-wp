<?php
/**
 * WordPress-specific Amazee.ai credential storage.
 *
 * Stores LiteLLM credentials in the WordPress options table. The token is
 * encrypted at rest with scolta-php's AuthenticatedCipher (encrypt-then-MAC,
 * HKDF key separation) keyed from the site's AUTH_KEY salt. Legacy
 * unauthenticated AES-256-CBC blobs are migrated to the authenticated
 * envelope on first load.
 *
 * @package Scolta
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\Exception\CryptoException;
use Tag1\Scolta\Util\AuthenticatedCipher;

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
	 * Transient marking a credential decrypt failure notice as pending.
	 *
	 * A decrypt failure must NOT silently read as "not connected" — the
	 * administrator gets an admin notice asking them to reconnect.
	 *
	 * @var string
	 */
	public const DECRYPT_FAILURE_TRANSIENT = 'scolta_amazee_decrypt_failure_pending';

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
				'litellm_token'   => $this->cipher()->encrypt( $litellmToken ),
				'litellm_api_url' => $litellmApiUrl,
				'region'          => $region,
			),
			false
		);
	}

	/**
	 * Load stored credentials, decrypting the token.
	 *
	 * New-format tokens (AuthenticatedCipher envelopes) decrypt via the
	 * authenticated helper; any CryptoException is logged once and queues
	 * a reconnect admin notice instead of silently reporting "not
	 * connected". Legacy plain-CBC blobs decrypt via the local legacy
	 * path and are immediately re-encrypted through the helper.
	 *
	 * @return array{litellm_token: string, litellm_api_url: string, region: string}|null
	 */
	public function load(): ?array {
		$data = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $data ) || empty( $data['litellm_token'] ) ) {
			return null;
		}

		$stored = (string) $data['litellm_token'];

		if ( AuthenticatedCipher::isEnvelope( $stored ) ) {
			try {
				$token = $this->cipher()->decrypt( $stored );
			} catch ( CryptoException $e ) {
				$this->surface_decrypt_failure( $e );
				return null;
			}
		} else {
			// Legacy unauthenticated AES-256-CBC blob.
			$token = $this->legacy_decrypt( $stored );
			if ( $token === '' ) {
				$this->surface_decrypt_failure(
					new CryptoException( 'Legacy credential blob failed to decrypt.' )
				);
				return null;
			}
			// Migrate: rewrite the credential as an authenticated envelope
			// so the next load takes the verified path.
			$this->store(
				$token,
				(string) ( $data['litellm_api_url'] ?? '' ),
				(string) ( $data['region'] ?? '' )
			);
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
	 * Render and clear a pending decrypt-failure notice, if one is queued.
	 *
	 * Hooked to admin_notices unconditionally by Scolta_Admin::init().
	 * Only administrators see it, and a non-admin page view does not
	 * consume it.
	 */
	public static function maybe_render_decrypt_failure_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_transient( self::DECRYPT_FAILURE_TRANSIENT ) ) {
			return;
		}
		delete_transient( self::DECRYPT_FAILURE_TRANSIENT );
		$reconnect_url  = esc_url( admin_url( 'admin.php?page=scolta-amazee' ) );
		$reconnect_text = esc_html__( 'Reconnect to Amazee.ai', 'scolta-ai-search' );
		?>
		<div class="notice notice-error">
			<p>
				<?php
				/* translators: %s: "Reconnect to Amazee.ai" admin page link. */
				$message = esc_html__(
					'Scolta: Your stored Amazee.ai credentials could not be decrypted (this happens when the site security keys rotate, or if the stored value was modified). AI search is unavailable until you reconnect. %s.', // phpcs:ignore Generic.Files.LineLength.MaxExceeded -- translatable text must stay a single literal for gettext extraction.
					'scolta-ai-search'
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- all parts escaped above.
				printf( $message, '<a href="' . $reconnect_url . '">' . $reconnect_text . '</a>' );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Log a decrypt failure (throttled) and queue the reconnect notice.
	 *
	 * The load() method runs on every AI request, so both the log line
	 * and the notice are gated on the pending transient to fire once per
	 * day, not once per search.
	 *
	 * @param CryptoException $e The decrypt failure.
	 */
	private function surface_decrypt_failure( CryptoException $e ): void {
		if ( get_transient( self::DECRYPT_FAILURE_TRANSIENT ) ) {
			return;
		}
		set_transient( self::DECRYPT_FAILURE_TRANSIENT, '1', DAY_IN_SECONDS );
		( new Scolta_Logger() )->error(
			'Amazee.ai credential decrypt failed: {message}',
			array(
				'message'   => $e->getMessage(),
				'exception' => $e,
			)
		);
	}

	/**
	 * Build the authenticated cipher keyed from the AUTH_KEY salt.
	 *
	 * Key derivation (HKDF with separate encryption/MAC keys) lives in
	 * scolta-php — no crypto is hand-rolled here.
	 */
	private function cipher(): AuthenticatedCipher {
		return new AuthenticatedCipher( wp_salt( 'auth' ) );
	}

	/**
	 * Decrypt a value produced by the pre-1.0.5 unauthenticated path.
	 *
	 * AES-256-CBC, base64(iv + ciphertext), no MAC. Kept only so existing
	 * credentials survive the upgrade; every successful legacy decrypt is
	 * immediately re-encrypted through AuthenticatedCipher by load().
	 *
	 * @param string $encoded Base64-encoded IV + ciphertext.
	 * @return string Decrypted plaintext, or empty string on failure.
	 */
	private function legacy_decrypt( string $encoded ): string {
		$key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the legacy encrypted credential blob (IV + ciphertext), not obfuscated code.
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
