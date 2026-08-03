<?php
/**
 * Provider selection is manual, and connecting Amazee.ai takes two clicks.
 *
 * The policy this pins:
 *
 * - **No default provider.** Activation seeds none, the select opens on a
 *   placeholder, and no code path substitutes 'anthropic' for an empty value.
 *   While none is selected AI is off and search is unaffected.
 * - **Amazee is never auto-enabled.** Nothing establishes a connection except
 *   an administrator's click. "Try the demo" takes no email and no other
 *   input; "Enter your Amazee credentials" runs the email → code → region
 *   flow. There is no paste-your-API-key form, matching amazee.ai's own
 *   ai_provider_amazeeio module.
 * - **Provenance is recorded, not guessed.** Which of the two actions ran is
 *   written to the credential store when it runs.
 *
 * @package Scolta
 */

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource;
use Tag1\Scolta\Config\AmazeeCredentials;
use Tag1\Scolta\Config\ApiKeyResolver;
use Tag1\Scolta\Config\ApiKeySource;

/**
 * Structural and library-level coverage of the manual-provider policy.
 */
class ManualProviderAndTwoActionConnectTest extends TestCase {

	/**
	 * Plugin root.
	 *
	 * @var string
	 */
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__ );
	}

	// -------------------------------------------------------------------
	// No default provider
	// -------------------------------------------------------------------

	public function test_activation_seeds_no_provider(): void {
		$plugin = $this->file( 'scolta.php' );

		$this->assertMatchesRegularExpression(
			"/'ai_provider'\s*=>\s*''/",
			$plugin,
			'Activation must seed no provider.'
		);
		$this->assertDoesNotMatchRegularExpression(
			"/'ai_provider'\s*=>\s*'anthropic'/",
			$plugin,
			'Seeding anthropic at activation is exactly the assumption being removed.'
		);
	}

	public function test_the_provider_select_has_a_placeholder_and_no_inferred_selection(): void {
		$admin = $this->file( 'admin/class-scolta-admin.php' );

		$this->assertStringContainsString( '- Select a provider -', $admin );
		// The old empty-state fallback derived a selection from the API-key
		// source, so a site that had never chosen anything displayed a provider
		// as though it had.
		$this->assertStringNotContainsString(
			"resolve_api_key()->isAmazee() ? 'amazee' : 'anthropic'",
			$admin,
			'The provider select must not infer a selection from the key source.'
		);
	}

	public function test_no_surface_coalesces_an_empty_provider_to_anthropic(): void {
		$offenders = array();
		foreach ( $this->provider_reading_files() as $relative => $contents ) {
			if ( preg_match( "/(\?\?|\?:)\s*'anthropic'/", $contents ) ) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"These files substitute 'anthropic' for an unselected provider; report the empty value instead:\n"
			. implode( "\n", $offenders )
		);
	}

	public function test_an_unrecognised_provider_fails_closed_rather_than_to_anthropic(): void {
		$admin = $this->file( 'admin/class-scolta-admin.php' );

		$this->assertMatchesRegularExpression(
			"/in_array\(\s*\\\$input\['ai_provider'\][^)]*array\(\s*'',\s*'anthropic'/s",
			$admin,
			"'' must be an accepted provider value: no selection is a real choice."
		);
	}

	/**
	 * A key with no provider selected is not reported as a working setup.
	 */
	public function test_key_without_a_provider_resolves_as_ai_off(): void {
		$resolved = ApiKeyResolver::resolve( array( 'env' => 'sk-env' ), null, '' );

		$this->assertFalse( $resolved->providerSelected() );
		$this->assertFalse( $resolved->aiEnabled() );
		$this->assertSame( 'warning', $resolved->severity() );
	}

	// -------------------------------------------------------------------
	// Two actions, and nothing before one of them
	// -------------------------------------------------------------------

	public function test_the_demo_takes_no_email_anywhere(): void {
		$page = $this->file( 'admin/class-scolta-amazee-admin-page.php' );
		$js   = $this->file( 'assets/js/amazee-admin.js' );

		$this->assertStringContainsString( 'Try the demo', $page );
		$this->assertStringContainsString( 'Try the demo', $js );
		// The browser must not even collect one for this action.
		$this->assertStringNotContainsString(
			"post( 'scolta_amazee_start_trial', { email: email }",
			$js,
			'The demo request must carry no email.'
		);
	}

	public function test_the_demo_is_established_by_an_explicit_provision_call(): void {
		// AutoProvisioner::ensureAiAvailable() self-heals stored credentials and
		// establishes nothing, so routing the click through it would leave the
		// button silently doing nothing. The establishing call is explicit.
		$plugin = $this->file( 'scolta.php' );

		$this->assertMatchesRegularExpression(
			'/function scolta_auto_provision_amazee\(\).*?AmazeeTrialProvisioner/s',
			$plugin,
			'The demo click must call the trial provisioner directly.'
		);
		$this->assertMatchesRegularExpression(
			'/function scolta_auto_provision_amazee\(\).*?\)\s*\)->provision\(\)/s',
			$plugin,
			'The demo must be provisioned with no email.'
		);
	}

	public function test_no_request_or_activation_path_establishes_a_connection(): void {
		// Exactly one caller, and it is the administrator action.
		$admin  = $this->file( 'admin/class-scolta-admin.php' );
		$plugin = $this->file( 'scolta.php' );

		$this->assertSame(
			1,
			substr_count( $admin, 'scolta_auto_provision_amazee()' ),
			'The demo must be established from exactly one place.'
		);
		$this->assertMatchesRegularExpression(
			'/function handle_enable_ai.*?scolta_auto_provision_amazee\(\)/s',
			$admin,
			'That one place must be the administrator action.'
		);
		// Bounded to the activation function's own body: an unbounded pattern
		// spans the whole file, where the two names appear in sequence for
		// unrelated reasons.
		$this->assertStringNotContainsString(
			'scolta_auto_provision_amazee',
			$this->function_body( $plugin, 'scolta_activate' ),
			'Activation must not establish a connection.'
		);
	}

	public function test_there_is_no_manual_api_key_path(): void {
		// Email-only, matching amazee.ai's own module: the account flow returns
		// the credentials and Scolta stores them.
		$page = $this->file( 'admin/class-scolta-amazee-admin-page.php' );

		$this->assertStringContainsString( 'Enter your Amazee credentials', $page );
		$this->assertStringContainsString( 'never generate or paste an API key', $page );
		$this->assertStringNotContainsString( 'id="scolta-amazee-token"', $page );
	}

	public function test_a_consumed_demo_points_at_the_account_path(): void {
		$admin = $this->file( 'admin/class-scolta-admin.php' );

		$this->assertStringContainsString( 'only be used once per site', $admin );
	}

	// -------------------------------------------------------------------
	// Provenance
	// -------------------------------------------------------------------

	public function test_the_credential_store_records_and_clears_the_connection_source(): void {
		$storage = $this->file( 'includes/class-scolta-amazee-config-storage.php' );

		$this->assertStringContainsString( 'implements ProvenanceAwareConfigStorageInterface', $storage );
		$this->assertStringContainsString( 'public function storeConnectionSource(', $storage );
		$this->assertStringContainsString( 'public function loadConnectionSource(', $storage );
		// A stale record would be paired with whatever connection comes next.
		$this->assertMatchesRegularExpression(
			'/function clear\(\).*?SOURCE_OPTION_KEY/s',
			$storage,
			'clear() must delete the recorded connection source.'
		);
	}

	public function test_the_admin_and_cli_report_the_recorded_provenance(): void {
		$admin = $this->file( 'admin/class-scolta-admin.php' );
		$cli   = $this->file( 'cli/class-scolta-cli.php' );

		foreach ( array( $admin, $cli ) as $contents ) {
			$this->assertStringContainsString( 'ApiKeySource::AmazeeDemo', $contents );
			$this->assertStringContainsString( 'ApiKeySource::AmazeeAccount', $contents );
		}
	}

	/**
	 * Each recorded source produces its own reported source, and none is guessed.
	 */
	public function test_recorded_provenance_drives_the_reported_source(): void {
		$cases = array(
			array( AmazeeConnectionSource::Demo, ApiKeySource::AmazeeDemo ),
			array( AmazeeConnectionSource::Account, ApiKeySource::AmazeeAccount ),
			array( null, ApiKeySource::Amazee ),
		);

		foreach ( $cases as list( $recorded, $expected ) ) {
			$resolved = ApiKeyResolver::resolve(
				array(),
				AmazeeCredentials::fromArray(
					array(
						'litellm_token'   => 'tok',
						'litellm_api_url' => 'https://gw.amazee.ai',
					),
					true,
					$recorded
				),
				'amazee'
			);

			$this->assertSame( $expected, $resolved->source );
			$this->assertTrue( $resolved->source->isAmazee() );
		}
	}

	public function test_no_operator_facing_wording_claims_an_automatic_trial(): void {
		$offenders = array();
		foreach ( $this->operator_facing_files() as $relative => $contents ) {
			// The prefix, so 'auto-provisioning' is caught as well as
			// 'auto-provisioned'.
			foreach ( array( 'auto-provision', 'auto provision' ) as $banned ) {
				if ( stripos( $contents, $banned ) !== false ) {
					$offenders[] = "{$relative}: {$banned}";
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"No connection is provisioned automatically, so nothing may describe one:\n"
			. implode( "\n", $offenders )
		);
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/**
	 * Read a plugin file relative to the plugin root.
	 *
	 * @param string $relative Path relative to the plugin root.
	 */
	private function file( string $relative ): string {
		$path = $this->root . '/' . $relative;
		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * The brace-matched body of one top-level function in a source string.
	 *
	 * @param string $source The full file contents.
	 * @param string $name   The function name.
	 */
	private function function_body( string $source, string $name ): string {
		$start = strpos( $source, "function {$name}(" );
		$this->assertNotFalse( $start, "function {$name}() not found" );

		$open  = (int) strpos( $source, '{', (int) $start );
		$depth = 0;
		for ( $i = $open; $i < strlen( $source ); $i++ ) {
			if ( '{' === $source[ $i ] ) {
				++$depth;
			} elseif ( '}' === $source[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $source, $open, $i - $open + 1 );
				}
			}
		}

		$this->fail( "unbalanced braces in {$name}()" );
	}

	/**
	 * Files that read the configured provider and could re-introduce a default.
	 *
	 * @return array<string, string>
	 */
	private function provider_reading_files(): array {
		$out = array();
		foreach ( array( 'includes/class-scolta-ai-service.php', 'admin/class-scolta-admin.php', 'cli/class-scolta-cli.php' ) as $relative ) {
			$out[ $relative ] = $this->file( $relative );
		}

		return $out;
	}

	/**
	 * Operator-facing sources, where stale provenance wording would surface.
	 *
	 * @return array<string, string>
	 */
	private function operator_facing_files(): array {
		$out = array();
		foreach ( array( 'admin', 'cli', 'includes' ) as $dir ) {
			foreach ( (array) glob( $this->root . '/' . $dir . '/*.php' ) as $file ) {
				$out[ $dir . '/' . basename( (string) $file ) ] = (string) file_get_contents( (string) $file );
			}
		}

		return $out;
	}
}
