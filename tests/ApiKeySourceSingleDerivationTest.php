<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Nothing outside Scolta_Ai_Service::resolve_api_key() decides where the key came from.
 *
 * The defect was two derivations of one fact with opposite precedence:
 * from_options() preferred an explicit key, get_api_key_source() checked the
 * Amazee credential store first. Making them agree would have left them free
 * to drift again, so what this pins is the structural property — one decision
 * point.
 *
 * @see https://github.com/tag1consulting/scolta-php/issues/252
 */
class ApiKeySourceSingleDerivationTest extends TestCase {

	/**
	 * Files allowed to read the credential store or the environment directly.
	 *
	 * The AI service holds resolve_api_key(); the storage class is the
	 * credential store itself; the reauth handler and provisioning paths
	 * manage credentials rather than reporting on which key is in use.
	 *
	 * @var list<string>
	 */
	private const ALLOWED = array(
		'includes/class-scolta-ai-service.php',
		'includes/class-scolta-amazee-config-storage.php',
		'includes/class-scolta-amazee-reauth-handler.php',
		'includes/class-scolta-amazee-budget-handler.php',
		// The Amazee.ai management page provisions, upgrades and clears
		// credentials. It acts on the store; it does not report which key a
		// request will use.
		'admin/class-scolta-amazee-admin-page.php',
		'scolta.php',
	);

	/**
	 * No reporting surface reads the credential store to answer "which key".
	 */
	public function test_only_the_service_and_the_store_read_stored_credentials(): void {
		$offenders = array();
		foreach ( $this->pluginFiles() as $relative => $contents ) {
			if ( in_array( $relative, self::ALLOWED, true ) ) {
				continue;
			}
			if ( str_contains( $contents, 'litellm_token' )
				|| str_contains( $contents, 'new Scolta_Amazee_Config_Storage' ) ) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Reading the credential store outside the resolver is how a surface ends up reporting "
			. "Amazee as active when an explicit key won:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * No reporting surface reads SCOLTA_API_KEY to work out a source of its own.
	 */
	public function test_only_the_service_reads_the_environment_variable(): void {
		$offenders = array();
		foreach ( $this->pluginFiles() as $relative => $contents ) {
			if ( in_array( $relative, self::ALLOWED, true ) ) {
				continue;
			}
			if ( str_contains( $contents, "getenv( 'SCOLTA_API_KEY' )" )
				|| str_contains( $contents, "getenv('SCOLTA_API_KEY')" ) ) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Take the key and its source from Scolta_Ai_Service::resolve_api_key():\n"
			. implode( "\n", $offenders )
		);
	}

	/**
	 * Every reporting surface derives from the shared resolution.
	 */
	public function test_every_reporting_surface_derives_from_the_resolution(): void {
		$surfaces = array(
			'admin/class-scolta-admin.php'      => 'the admin screens',
			'includes/class-scolta-rest-api.php' => 'the health payload',
			'cli/class-scolta-cli.php'          => 'the WP-CLI status and check-setup commands',
		);

		foreach ( $surfaces as $file => $label ) {
			$contents = (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
			$this->assertStringContainsString(
				'resolve_api_key()',
				$contents,
				sprintf( '%s must report from the shared resolution', $label )
			);
		}
	}

	/**
	 * Read every PHP source file the plugin ships, excluding vendor and tests.
	 *
	 * @return array<string, string> Relative path => contents.
	 */
	private function pluginFiles(): array {
		$root  = dirname( __DIR__ );
		$files = array();

		foreach ( array( 'includes', 'admin', 'cli' ) as $dir ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof SplFileInfo || $file->getExtension() !== 'php' ) {
					continue;
				}
				$files[ str_replace( $root . '/', '', $file->getPathname() ) ] = (string) file_get_contents( $file->getPathname() );
			}
		}
		$files['scolta.php'] = (string) file_get_contents( $root . '/scolta.php' );

		$this->assertNotEmpty( $files, 'Found no plugin sources to scan' );

		return $files;
	}

}
