<?php
/**
 * WordPress admin surface for Amazee.ai credential re-authentication.
 *
 * @package Scolta
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;

/**
 * Surfaces a wp-admin prompt when the stored Amazee.ai credentials are no
 * longer accepted and the operator must reconnect/upgrade.
 *
 * The scolta-php KeyExpiryRecovery records a persistent marker when an AI call
 * fails authentication against the managed Amazee.ai credentials. That failure
 * happens during front-end/REST search requests where admin_notices never
 * runs, but the marker outlives the request — so this reads it directly on the
 * next admin page load rather than persisting a separate pending transient
 * (contrast Scolta_Amazee_Budget_Handler, whose event the core layer does not
 * persist).
 *
 * Policy: when the stored Amazee.ai credentials stop being accepted the
 * operator is routed to the existing connect/upgrade flow; the adapter never
 * requests fresh credentials on their behalf. The prompt stays on every admin
 * screen until re-authentication succeeds, so a degraded site always has a
 * visible path forward — the failure is surfaced, never swallowed.
 */
class Scolta_Amazee_Reauth_Handler {

	/**
	 * Build a KeyExpiryRecovery over the WordPress credential store and cache.
	 *
	 * Mirrors the wiring in Scolta_Ai_Service::from_options() so the marker
	 * read here is the same one scolta-php records during AI calls.
	 *
	 * @return KeyExpiryRecovery The recovery helper over the shared marker store.
	 */
	private static function recovery(): KeyExpiryRecovery {
		return new KeyExpiryRecovery(
			storage: new Scolta_Amazee_Config_Storage(),
			cache: new Scolta_Cache_Driver(),
			logger: new Scolta_Logger()
		);
	}

	/**
	 * Whether the stored Amazee.ai credentials need admin re-authentication.
	 *
	 * True only on the Amazee.ai path (credentials stored) and once scolta-php
	 * has recorded the persistent marker. A cache-marker read only — never a
	 * live API call — so it is safe to call on every admin page load.
	 *
	 * @return bool True when the re-authentication prompt should show.
	 */
	public static function is_reauth_needed(): bool {
		$storage = new Scolta_Amazee_Config_Storage();
		if ( $storage->load() === null ) {
			return false;
		}
		return self::recovery()->isUpgradeNeeded();
	}

	/**
	 * Clear the re-authentication marker after a successful reconnect.
	 *
	 * Called by the Amazee.ai admin flow once fresh credentials are stored, so
	 * the notice clears on the next admin page load.
	 */
	public static function clear(): void {
		self::recovery()->clearUpgradeNeeded();
	}

	/**
	 * Render the re-authentication notice on admin pages when needed.
	 *
	 * Hooked unconditionally to admin_notices by Scolta_Admin::init(); it
	 * self-gates on capability and the credential-state marker so the prompt
	 * appears on every admin screen while AI is degraded, and never otherwise.
	 * Unlike the budget notice it is not consumed on render — it persists until
	 * the operator reconnects.
	 */
	public static function maybe_render_pending_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::is_reauth_needed() ) {
			return;
		}
		self::render_reauth_notice();
	}

	/**
	 * Render the re-authentication admin notice with a continue-with-Amazee CTA.
	 */
	public static function render_reauth_notice(): void {
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				$url  = esc_url( admin_url( 'admin.php?page=scolta-amazee' ) );
				$text = esc_html__( 'Continue with Amazee.ai', 'scolta-ai-search' );
				$link = '<a href="' . $url . '">' . $text . '</a>';
				// translators: %s: link to the Amazee.ai connection page.
				$message = esc_html__( 'Scolta: your Amazee.ai connection needs to be re-authenticated before AI search features can work again. %s to reconnect or upgrade.', 'scolta-ai-search' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				printf( $message, $link );
				?>
			</p>
		</div>
		<?php
	}
}
