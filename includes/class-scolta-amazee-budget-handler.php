<?php
/**
 * WordPress handler for Amazee.ai budget-exceeded events.
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;

/**
 * Shows a throttled admin notice when the Amazee.ai budget is exceeded.
 *
 * The notice fires at most once per 24 hours so the administrator is
 * informed without being flooded on every AI request during the outage.
 */
class Scolta_Amazee_Budget_Handler {

	/**
	 * Transient key used to throttle duplicate notices.
	 *
	 * @var string
	 */
	private const THROTTLE_TRANSIENT = 'scolta_amazee_budget_notice_sent';

	/**
	 * Transient key marking a notice as pending display in the admin.
	 *
	 * Budget errors surface during front-end/REST search requests where
	 * admin_notices never fires, so the event is persisted here and
	 * rendered on the next admin page load instead.
	 *
	 * @var string
	 */
	public const PENDING_TRANSIENT = 'scolta_amazee_budget_notice_pending';

	/**
	 * Handle a budget-exceeded exception.
	 *
	 * Persists a pending-notice transient (throttled to once per 24 hours);
	 * {@see maybe_render_pending_notice()} renders it on the next admin
	 * page load. Hooking admin_notices directly here would be a no-op:
	 * budget errors fire during REST search requests, not admin requests.
	 *
	 * @param AmazeeBudgetExceededException $e The exception to handle.
	 */
	public function handle( AmazeeBudgetExceededException $e ): void {
		if ( get_transient( self::THROTTLE_TRANSIENT ) ) {
			return;
		}
		set_transient( self::THROTTLE_TRANSIENT, '1', DAY_IN_SECONDS );
		set_transient( self::PENDING_TRANSIENT, '1', DAY_IN_SECONDS );
	}

	/**
	 * Render and clear a pending budget notice, if one is queued.
	 *
	 * Hooked to admin_notices unconditionally by Scolta_Admin::init().
	 * The pending transient is only cleared once an administrator has
	 * actually seen the notice.
	 */
	public static function maybe_render_pending_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_transient( self::PENDING_TRANSIENT ) ) {
			return;
		}
		delete_transient( self::PENDING_TRANSIENT );
		self::render_budget_notice();
	}

	/**
	 * Render the budget-exceeded admin notice.
	 */
	public static function render_budget_notice(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				$upgrade_url  = esc_url( admin_url( 'admin.php?page=scolta-amazee' ) );
				$upgrade_text = esc_html__( 'Upgrade your Amazee.ai account', 'scolta-ai-search' );
				$upgrade_link = '<a href="' . $upgrade_url . '">' . $upgrade_text . '</a>';
				// translators: %s: link to the Amazee.ai settings page.
				$message = esc_html__( 'Scolta: Your Amazee.ai free trial budget has been exceeded. AI-powered search features are temporarily unavailable. %s to restore AI search.', 'scolta-ai-search' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				printf( $message, $upgrade_link );
				?>
			</p>
		</div>
		<?php
	}
}
