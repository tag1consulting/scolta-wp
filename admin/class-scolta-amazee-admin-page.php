<?php
/**
 * Admin page for Amazee.ai configuration.
 *
 * Multi-step connection flow for Amazee.ai:
 *  - Trial path:   email → POST trial → connected.
 *  - Sign-in path: email → OTP email → enter code → select region → connected.
 *
 * In-flight flow state (email, session token) is stored in user meta and
 * cleared on completion or back navigation.
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\AiProvider\Amazee\AmazeeAccountUpgrader;
use Tag1\Scolta\AiProvider\Amazee\AmazeeApiException;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeModelResolver;
use Tag1\Scolta\AiProvider\Amazee\AmazeeTrialProvisioner;

/**
 * Admin submenu page and AJAX handlers for the Amazee.ai connection flow.
 */
class Scolta_Amazee_Admin_Page {

	/**
	 * User meta key for in-flight flow state.
	 *
	 * @var string
	 */
	private const FLOW_META_KEY = 'scolta_amazee_flow';

	/**
	 * Nonce action for AJAX requests.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'scolta_amazee';

	/**
	 * Register all WordPress hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_scolta_amazee_start_trial', array( self::class, 'ajax_start_trial' ) );
		add_action( 'wp_ajax_scolta_amazee_request_code', array( self::class, 'ajax_request_code' ) );
		add_action( 'wp_ajax_scolta_amazee_verify_code', array( self::class, 'ajax_verify_code' ) );
		add_action( 'wp_ajax_scolta_amazee_list_regions', array( self::class, 'ajax_list_regions' ) );
		add_action( 'wp_ajax_scolta_amazee_connect', array( self::class, 'ajax_connect' ) );
		add_action( 'wp_ajax_scolta_amazee_disconnect', array( self::class, 'ajax_disconnect' ) );
	}

	/**
	 * Add the Amazee.ai submenu under Settings > Scolta.
	 */
	public static function add_submenu(): void {
		add_submenu_page(
			'scolta',
			__( 'Amazee.ai', 'scolta' ),
			__( 'Amazee.ai', 'scolta' ),
			'manage_options',
			'scolta-amazee',
			array( self::class, 'render_page' ),
		);
	}

	/**
	 * Enqueue admin JS and CSS for the Amazee.ai page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_scripts( string $hook ): void {
		if ( $hook !== 'scolta_page_scolta-amazee' ) {
			return;
		}
		wp_enqueue_script(
			'scolta-amazee-admin',
			SCOLTA_PLUGIN_URL . 'assets/js/amazee-admin.js',
			array( 'jquery' ),
			SCOLTA_VERSION,
			true,
		);
		wp_localize_script(
			'scolta-amazee-admin',
			'scoltaAmazee',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			),
		);
		wp_enqueue_style(
			'scolta-amazee-admin',
			SCOLTA_PLUGIN_URL . 'assets/css/amazee-admin.css',
			array(),
			SCOLTA_VERSION,
		);
	}

	/**
	 * Render the page wrapper. JS drives step transitions; PHP renders
	 * the initial state as a non-JS fallback.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$storage = new Scolta_Amazee_Config_Storage();
		$creds   = $storage->load();
		$flow    = self::get_flow_state();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Amazee.ai Configuration', 'scolta' ); ?></h1>
			<div id="scolta-amazee-app"
				data-step="<?php echo esc_attr( self::determine_step( $creds, $flow ) ); ?>"
				data-email="<?php echo esc_attr( $flow['email'] ?? '' ); ?>">
				<?php self::render_step( $creds, $flow ); ?>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------
	// AJAX handlers
	// -----------------------------------------------------------------

	/**
	 * AJAX: Provision a free trial account.
	 */
	public static function ajax_start_trial(): void {
		self::check_nonce_and_caps();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid email address.', 'scolta' ) ) );
		}

		try {
			$storage      = new Scolta_Amazee_Config_Storage();
			$amazeeClient = new AmazeeClient();
			$provisioner  = new AmazeeTrialProvisioner(
				$amazeeClient,
				$storage,
				null,
				new AmazeeModelResolver( $amazeeClient ),
			);
			$result = $provisioner->provision( $email );

			if ( $result->aiModel !== null ) {
				$default_model   = 'claude-sonnet-4-5-20250929';
				$scolta_settings = get_option( 'scolta_settings', array() );

				if ( $scolta_settings['ai_model'] ?? $default_model === $default_model ) {
					$scolta_settings['ai_model'] = $result->aiModel;
				}
				if ( ( $scolta_settings['ai_expansion_model'] ?? '' ) === '' && $result->aiExpansionModel !== null ) {
					$scolta_settings['ai_expansion_model'] = $result->aiExpansionModel;
				}
				update_option( 'scolta_settings', $scolta_settings );

				set_transient(
					'scolta_amazee_models_notice',
					array(
						'ai_model'           => $result->aiModel,
						'ai_expansion_model' => $result->aiExpansionModel ?? '',
					),
					DAY_IN_SECONDS,
				);
			}

			self::clear_flow_state();
			wp_send_json_success(
				array(
					'step'               => 'connected',
					'ai_model'           => $result->aiModel,
					'ai_expansion_model' => $result->aiExpansionModel,
				)
			);
		} catch ( AmazeeApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Send a verification code to begin the sign-in flow.
	 */
	public static function ajax_request_code(): void {
		self::check_nonce_and_caps();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid email address.', 'scolta' ) ) );
		}

		try {
			$upgrader = new AmazeeAccountUpgrader( new AmazeeClient(), new Scolta_Amazee_Config_Storage() );
			$upgrader->requestVerificationCode( $email );
			self::save_flow_state(
				array(
					'step' => 'verification',
					'email' => $email,
				)
			);
			wp_send_json_success(
				array(
					'step' => 'verification',
					'email' => $email,
				)
			);
		} catch ( AmazeeApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Verify the email code and advance to region selection.
	 */
	public static function ajax_verify_code(): void {
		self::check_nonce_and_caps();

		$flow = self::get_flow_state();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		if ( empty( $flow['email'] ) || $code === '' ) {
			wp_send_json_error( array( 'message' => __( 'Session expired. Please start again.', 'scolta' ) ) );
		}

		try {
			$upgrader     = new AmazeeAccountUpgrader( new AmazeeClient(), new Scolta_Amazee_Config_Storage() );
			$sessionToken = $upgrader->signIn( $flow['email'], $code );
			self::save_flow_state(
				array_merge(
					$flow,
					array(
						'step' => 'region',
						'session_token' => $sessionToken,
					)
				)
			);
			wp_send_json_success( array( 'step' => 'region' ) );
		} catch ( AmazeeApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: List available regions for the authenticated account.
	 */
	public static function ajax_list_regions(): void {
		self::check_nonce_and_caps();

		$flow = self::get_flow_state();
		if ( empty( $flow['session_token'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired. Please start again.', 'scolta' ) ) );
		}

		try {
			$upgrader = new AmazeeAccountUpgrader( new AmazeeClient(), new Scolta_Amazee_Config_Storage() );
			$regions  = $upgrader->listRegions( $flow['session_token'] );
			wp_send_json_success( array( 'regions' => $regions ) );
		} catch ( AmazeeApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Create a private AI key in the selected region.
	 */
	public static function ajax_connect(): void {
		self::check_nonce_and_caps();

		$flow = self::get_flow_state();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$region_id = sanitize_text_field( wp_unslash( $_POST['region_id'] ?? '' ) );
		if ( empty( $flow['session_token'] ) || $region_id === '' ) {
			wp_send_json_error( array( 'message' => __( 'Session expired. Please start again.', 'scolta' ) ) );
		}

		try {
			$upgrader = new AmazeeAccountUpgrader( new AmazeeClient(), new Scolta_Amazee_Config_Storage() );
			$upgrader->upgrade( $flow['session_token'], $region_id );
			self::clear_flow_state();
			wp_send_json_success( array( 'step' => 'connected' ) );
		} catch ( AmazeeApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Disconnect from Amazee.ai and clear stored credentials.
	 */
	public static function ajax_disconnect(): void {
		self::check_nonce_and_caps();

		$storage = new Scolta_Amazee_Config_Storage();
		$storage->clear();
		self::clear_flow_state();
		wp_send_json_success( array( 'step' => 'start' ) );
	}

	// -----------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------

	/**
	 * Verify the AJAX nonce and require manage_options capability.
	 *
	 * Sends a JSON error response and exits if the checks fail.
	 */
	private static function check_nonce_and_caps(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'scolta' ) ), 403 );
		}
	}

	/**
	 * Determine the active step based on stored credentials and flow state.
	 *
	 * @param array|null $creds Stored Amazee credentials, or null if none.
	 * @param array      $flow  In-flight flow state for the current user.
	 * @return string One of: 'connected', 'verification', 'region', 'start'.
	 */
	private static function determine_step( ?array $creds, array $flow ): string {
		if ( $creds !== null ) {
			return 'connected';
		}
		return $flow['step'] ?? 'start';
	}

	/**
	 * Render the appropriate step form inline (non-JS fallback).
	 *
	 * @param array|null $creds Stored credentials, or null.
	 * @param array      $flow  In-flight flow state.
	 */
	private static function render_step( ?array $creds, array $flow ): void {
		$step = self::determine_step( $creds, $flow );
		switch ( $step ) {
			case 'connected':
				self::render_connected_step( $creds );
				break;
			case 'verification':
				self::render_verification_step( $flow );
				break;
			case 'region':
				self::render_region_step();
				break;
			default:
				self::render_start_step();
		}
	}

	/**
	 * Render the "connected" status view.
	 *
	 * @param array $creds Stored credentials.
	 */
	private static function render_connected_step( array $creds ): void {
		?>
		<p>
			<?php
			printf(
				/* translators: %s: Amazee.ai region name */
				esc_html__( 'Connected to Amazee.ai (region: %s).', 'scolta' ),
				'<strong>' . esc_html( $creds['region'] ) . '</strong>'
			);
			?>
		</p>
		<button type="button" id="scolta-amazee-disconnect" class="button button-secondary">
			<?php esc_html_e( 'Disconnect', 'scolta' ); ?>
		</button>
		<?php
	}

	/**
	 * Render the initial step with email input and action buttons.
	 */
	private static function render_start_step(): void {
		?>
		<p><?php esc_html_e( 'Connect Scolta to Amazee.ai for privacy-respecting, budget-aware AI search.', 'scolta' ); ?></p>
		<label for="scolta-amazee-email"><?php esc_html_e( 'Email address', 'scolta' ); ?></label>
		<input type="email" id="scolta-amazee-email" class="regular-text" />
		<p>
			<button type="button" id="scolta-amazee-trial" class="button button-primary">
				<?php esc_html_e( 'Start free trial', 'scolta' ); ?>
			</button>
			<button type="button" id="scolta-amazee-signin" class="button button-secondary">
				<?php esc_html_e( 'Sign in to existing account', 'scolta' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * Render the verification code entry step.
	 *
	 * @param array $flow In-flight flow state containing the user's email.
	 */
	private static function render_verification_step( array $flow ): void {
		?>
		<p>
			<?php
			printf(
				/* translators: %s: email address */
				esc_html__( 'A verification code has been sent to %s. Enter it below.', 'scolta' ),
				'<strong>' . esc_html( $flow['email'] ?? '' ) . '</strong>'
			);
			?>
		</p>
		<label for="scolta-amazee-code"><?php esc_html_e( 'Verification code', 'scolta' ); ?></label>
		<input type="text" id="scolta-amazee-code" class="regular-text" autocomplete="one-time-code" />
		<p>
			<button type="button" id="scolta-amazee-verify" class="button button-primary">
				<?php esc_html_e( 'Verify code', 'scolta' ); ?>
			</button>
			<button type="button" id="scolta-amazee-back" class="button button-secondary">
				<?php esc_html_e( 'Back', 'scolta' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * Render the region selection step.
	 */
	private static function render_region_step(): void {
		?>
		<p><?php esc_html_e( 'Select the region where your AI requests will be processed.', 'scolta' ); ?></p>
		<p id="scolta-amazee-regions-loading"><?php esc_html_e( 'Loading regions&hellip;', 'scolta' ); ?></p>
		<div id="scolta-amazee-regions-list" style="display:none;"></div>
		<p>
			<button type="button" id="scolta-amazee-connect" class="button button-primary" style="display:none;">
				<?php esc_html_e( 'Connect', 'scolta' ); ?>
			</button>
			<button type="button" id="scolta-amazee-back" class="button button-secondary">
				<?php esc_html_e( 'Back', 'scolta' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * Get the current flow state for the logged-in user.
	 *
	 * @return array{step?: string, email?: string, session_token?: string}
	 */
	private static function get_flow_state(): array {
		$raw = get_user_meta( get_current_user_id(), self::FLOW_META_KEY, true );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Persist flow state for the current user.
	 *
	 * @param array $state New flow state to save.
	 */
	private static function save_flow_state( array $state ): void {
		update_user_meta( get_current_user_id(), self::FLOW_META_KEY, $state );
	}

	/**
	 * Clear the in-flight flow state for the current user.
	 */
	private static function clear_flow_state(): void {
		delete_user_meta( get_current_user_id(), self::FLOW_META_KEY );
	}
}
