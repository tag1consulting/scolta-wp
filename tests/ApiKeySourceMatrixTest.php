<?php

declare(strict_types=1);

use Tag1\Scolta\Health\HealthChecker;
use Tag1\Scolta\SetupCheck;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every key source, with and without stored Amazee.ai credentials, agrees.
 *
 * The defect: from_options() gave an explicit key priority over stored
 * Amazee.ai credentials while get_api_key_source() checked the credential
 * store first, so a site running on a valid SCOLTA_API_KEY was reported as
 * connected to Amazee.ai on every admin and CLI surface it has, and nothing
 * revealed which key was actually in use.
 *
 * Each cell asserts the three reporting surfaces against the same resolution:
 * the admin status field, the /health payload, and the CLI check-setup row.
 * The health payload is built exactly as Scolta_Rest_Api::handle_health()
 * builds it; that the REST handler and WP-CLI take their input from
 * resolve_api_key() rather than deriving a source of their own is pinned in
 * ApiKeySourceSingleDerivationTest.
 *
 * @see https://github.com/tag1consulting/scolta-php/issues/252
 */
class ApiKeySourceMatrixTest extends TestCase {

	/**
	 * Temporary index directory, so HealthChecker has somewhere to look.
	 *
	 * @var string
	 */
	private string $tempDir;

	protected function set_up(): void {
		$GLOBALS['wp_options']          = array();
		$GLOBALS['test_user_meta']      = array();
		$GLOBALS['test_current_user_id'] = 1;
		putenv( 'SCOLTA_API_KEY' );
		unset( $_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY'] );

		$this->tempDir = sys_get_temp_dir() . '/scolta_wp_key_matrix_' . uniqid();
		mkdir( $this->tempDir, 0755, true );
	}

	protected function tear_down(): void {
		putenv( 'SCOLTA_API_KEY' );
		@rmdir( $this->tempDir );
	}

	/**
	 * The four-by-two matrix.
	 *
	 * @dataProvider matrixProvider
	 *
	 * @param string $envKey        SCOLTA_API_KEY value, or '' for unset.
	 * @param string $databaseKey   Legacy database key, or '' for unset.
	 * @param bool   $amazeeStored  Whether Amazee credentials are stored.
	 * @param string $expected      The expected resolved source.
	 */
	public function test_every_source_agrees_across_every_surface(
		string $envKey,
		string $databaseKey,
		bool $amazeeStored,
		string $expected
	): void {
		if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
			$this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test in this process.' );
		}

		// Every row in this matrix is about which key wins and how each surface
		// reports it, not about provider selection — so each fixture selects a
		// provider. Without one, AI is off whatever key is present, which is
		// asserted separately in ManualProviderAndTwoActionConnectTest.
		$settings = array( 'ai_provider' => $amazeeStored ? 'amazee' : 'anthropic' );
		if ( $databaseKey !== '' ) {
			$settings['ai_api_key'] = $databaseKey;
		}
		if ( $amazeeStored ) {
			// A resolved gateway model, so the credentials are usable rather
			// than half-provisioned.
			$settings['amazee_model'] = 'claude-4-5-sonnet';
		}
		update_option( 'scolta_settings', $settings );

		if ( $envKey !== '' ) {
			putenv( 'SCOLTA_API_KEY=' . $envKey );
		}

		if ( $amazeeStored ) {
			( new Scolta_Amazee_Config_Storage() )->store( 'amazee-token', 'https://gateway.example/v1', 'eu' );
		}

		$resolved   = Scolta_Ai_Service::resolve_api_key();
		$overridden = $amazeeStored && ! str_starts_with( $expected, 'amazee' );

		// 1. The resolution itself.
		$this->assertSame( $expected, $resolved->source->value );
		$this->assertSame( $expected, Scolta_Ai_Service::get_api_key_source() );

		$service = Scolta_Ai_Service::from_options();
		$this->assertSame(
			str_starts_with( $expected, 'amazee' ),
			$service->is_amazee_active(),
			'is_amazee_active() must match the effective source, not the presence of credentials'
		);

		// The key that will actually be sent matches the reported source.
		$expectedKey = match ( $expected ) {
			'env' => $envKey,
			'database' => $databaseKey,
			'amazee' => 'amazee-token',
			default => '',
		};
		$this->assertSame( $expectedKey, $service->get_config()->aiApiKey );

		// 2. The admin status field.
		ob_start();
		Scolta_Admin::render_api_key_status_field();
		$markup = (string) ob_get_clean();

		if ( str_starts_with( $expected, 'amazee' ) ) {
			$this->assertStringContainsString( 'Connected to Amazee.ai', $markup );
		} elseif ( $expected === 'env' ) {
			$this->assertStringContainsString( 'SCOLTA_API_KEY environment variable', $markup );
		} elseif ( $expected === 'database' ) {
			$this->assertStringContainsString( 'stored in the database', $markup );
		} else {
			$this->assertStringContainsString( 'No API key configured', $markup );
		}

		if ( $overridden ) {
			// The whole point: credentials that lost are named, not hidden.
			$this->assertStringContainsString( 'Amazee.ai credentials stored but overridden by', $markup );
			// And never announced in a success notice.
			$this->assertStringNotContainsString( 'notice-success', $markup );
		} else {
			$this->assertStringNotContainsString( 'stored but overridden', $markup );
		}

		// 3. The health payload, built as the REST handler builds it.
		$health = ( new HealthChecker(
			config: $service->get_config(),
			indexOutputDir: $this->tempDir,
			pagefindBinaryPath: null,
			projectDir: $this->tempDir,
			cache: null,
			resolvedKey: $resolved,
		) )->check();

		$this->assertSame( $expected, $health['ai_key_source'], 'health disagrees about the source' );
		$this->assertSame( $overridden, $health['ai_amazee_overridden'], 'health hides the override' );
		$this->assertSame( $expected !== 'none', $health['ai_configured'] );

		// 4. The CLI check-setup row.
		$rows   = SetupCheck::run(
			configuredBinaryPath: null,
			projectDir: $this->tempDir,
			aiApiKey: Scolta_Ai_Service::get_api_key(),
			browserWasmDir: null,
			resolvedKey: $resolved,
		);
		$keyRow = null;
		foreach ( $rows as $row ) {
			if ( $row['name'] === 'AI API key' ) {
				$keyRow = $row;
			}
		}

		$this->assertNotNull( $keyRow );
		$this->assertSame( $resolved->describe(), $keyRow['message'], 'the CLI words it differently' );
		$this->assertSame(
			( $overridden || $expected === 'none' ) ? 'warn' : 'pass',
			$keyRow['status'],
			'the CLI must not report an override as a pass'
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
	 */
	public static function matrixProvider(): array {
		return array(
			'env, no amazee'          => array( 'sk-env-key', '', false, 'env' ),
			'env, amazee stored'      => array( 'sk-env-key', '', true, 'env' ),
			'database, no amazee'     => array( '', 'sk-db-key', false, 'database' ),
			'database, amazee stored' => array( '', 'sk-db-key', true, 'database' ),
			'amazee, no amazee'       => array( '', '', false, 'none' ),
			'amazee, amazee stored'   => array( '', '', true, 'amazee' ),
			'none, no amazee'         => array( '', '', false, 'none' ),
			'none, amazee stored'     => array( '', '', true, 'amazee' ),
		);
	}

	/**
	 * A half-provisioned install reports Amazee but withholds the key.
	 */
	public function test_half_provisioned_amazee_reports_its_source_without_a_key(): void {
		if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
			$this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test in this process.' );
		}

		// No amazee_model, so model resolution never completed.
		update_option( 'scolta_settings', array() );
		( new Scolta_Amazee_Config_Storage() )->store( 'amazee-token', 'https://gateway.example/v1', 'eu' );

		$resolved = Scolta_Ai_Service::resolve_api_key();

		$this->assertSame( 'amazee', $resolved->source->value );
		$this->assertTrue( $resolved->awaitingAmazeeModelResolution );
		$this->assertSame( '', $resolved->key, 'The gateway rejects the dated default with HTTP 400; degrade instead' );
		$this->assertSame( '', Scolta_Ai_Service::from_options()->get_config()->aiApiKey );
	}

}
