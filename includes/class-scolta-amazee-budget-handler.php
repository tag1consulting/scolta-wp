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
	 * Handle a budget-exceeded exception.
	 *
	 * Schedules an admin_notices hook (once per 24 hours).
	 *
	 * @param AmazeeBudgetExceededException $e The exception to handle.
	 */
	public function handle( AmazeeBudgetExceededException $e ): void {
		if ( get_transient( self::THROTTLE_TRANSIENT ) ) {
			return;
		}
		set_transient( self::THROTTLE_TRANSIENT, '1', DAY_IN_SECONDS );
		add_action( 'admin_notices', array( self::class, 'render_budget_notice' ) );
	}

	/**
	 * Render the budget-exceeded admin notice.
	 */
	public static function render_budget_notice(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				// translators: Shown when Amazee.ai budget is exhausted.
				esc_html_e(
					'Scolta: Amazee.ai budget exceeded. AI search is temporarily unavailable.',
					'scolta'
				);
				?>
			</p>
		</div>
		<?php
	}
}
