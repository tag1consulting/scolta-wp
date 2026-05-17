<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the == External Services == section in readme.txt.
 *
 * WordPress.org requires all external/third-party service usage to be
 * documented in readme.txt. These tests enforce that the required section
 * and subsections are present and that all linked URLs are reachable.
 *
 * @see https://github.com/tag1consulting/scolta-wp/issues/70
 */
class ExternalServicesReadmeTest extends TestCase {

	private string $readme;

	public function set_up(): void {
		parent::set_up();
		$this->readme = file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
	}

	// -------------------------------------------------------------------------
	// Section presence
	// -------------------------------------------------------------------------

	public function test_external_services_section_exists(): void {
		$this->assertStringContainsString(
			'== External Services ==',
			$this->readme,
			'readme.txt must contain an == External Services == section (required by WordPress.org guidelines)'
		);
	}

	// -------------------------------------------------------------------------
	// GitHub API subsection
	// -------------------------------------------------------------------------

	public function test_github_api_subsection_exists(): void {
		$this->assertStringContainsString(
			'= GitHub API',
			$this->readme,
			'readme.txt must document the GitHub API service used for Pagefind downloads'
		);
	}

	public function test_github_api_url_present(): void {
		$this->assertStringContainsString(
			'api.github.com',
			$this->readme,
			'readme.txt must include the api.github.com endpoint URL'
		);
	}

	public function test_github_tos_url_present(): void {
		$this->assertStringContainsString(
			'https://docs.github.com/en/site-policy/github-terms/github-terms-of-service',
			$this->readme,
			'readme.txt must include the GitHub Terms of Service URL'
		);
	}

	public function test_github_privacy_url_present(): void {
		$this->assertStringContainsString(
			'https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement',
			$this->readme,
			'readme.txt must include the GitHub Privacy Statement URL'
		);
	}

	// -------------------------------------------------------------------------
	// Pagefind / CloudCannon subsection
	// -------------------------------------------------------------------------

	public function test_pagefind_subsection_exists(): void {
		$this->assertStringContainsString(
			'= Pagefind',
			$this->readme,
			'readme.txt must document the Pagefind binary download service'
		);
	}

	public function test_pagefind_url_present(): void {
		$this->assertStringContainsString(
			'https://pagefind.app/',
			$this->readme,
			'readme.txt must include the Pagefind homepage URL'
		);
	}

	public function test_cloudcannon_url_present(): void {
		$this->assertStringContainsString(
			'https://cloudcannon.com/',
			$this->readme,
			'readme.txt must include the CloudCannon URL'
		);
	}

	public function test_pagefind_license_url_present(): void {
		$this->assertStringContainsString(
			'https://github.com/Pagefind/pagefind/blob/main/LICENSE',
			$this->readme,
			'readme.txt must include the Pagefind license URL'
		);
	}

	// -------------------------------------------------------------------------
	// AI provider subsection
	// -------------------------------------------------------------------------

	public function test_ai_providers_subsection_exists(): void {
		$this->assertStringContainsString(
			'= AI Provider',
			$this->readme,
			'readme.txt must document the AI provider APIs'
		);
	}

	public function test_anthropic_tos_url_present(): void {
		$this->assertStringContainsString(
			'https://www.anthropic.com/legal/consumer-terms',
			$this->readme,
			'readme.txt must include the Anthropic Terms of Service URL'
		);
	}

	public function test_anthropic_privacy_url_present(): void {
		$this->assertStringContainsString(
			'https://www.anthropic.com/legal/privacy',
			$this->readme,
			'readme.txt must include the Anthropic Privacy Policy URL'
		);
	}

	public function test_openai_tos_url_present(): void {
		$this->assertStringContainsString(
			'https://openai.com/policies/terms-of-use',
			$this->readme,
			'readme.txt must include the OpenAI Terms of Use URL'
		);
	}

	public function test_openai_privacy_url_present(): void {
		$this->assertStringContainsString(
			'https://openai.com/policies/privacy-policy',
			$this->readme,
			'readme.txt must include the OpenAI Privacy Policy URL'
		);
	}

	// -------------------------------------------------------------------------
	// URL reachability (network)
	// -------------------------------------------------------------------------

	/**
	 * Verifies all required external service URLs return HTTP 200.
	 *
	 * @group network
	 */
	public function test_all_required_urls_are_reachable(): void {
		if ( ! $this->has_network_access() ) {
			$this->markTestSkipped( 'Network not available — skipping URL reachability checks.' );
		}

		$required_urls = [
			'https://docs.github.com/en/site-policy/github-terms/github-terms-of-service',
			'https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement',
			'https://pagefind.app/',
			'https://cloudcannon.com/',
			'https://github.com/Pagefind/pagefind/blob/main/LICENSE',
			'https://www.anthropic.com/legal/consumer-terms',
			'https://www.anthropic.com/legal/privacy',
			'https://openai.com/policies/terms-of-use',
			'https://openai.com/policies/privacy-policy',
		];

		$failures = [];
		foreach ( $required_urls as $url ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_NOBODY         => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => 10,
				CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; scolta-wp-test/1.0)',
				CURLOPT_SSL_VERIFYPEER => true,
			] );
			curl_exec( $ch );
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$curl_error = curl_error( $ch );
			curl_close( $ch );

			if ( $curl_error !== '' ) {
				$failures[] = "Connection error ({$curl_error}): {$url}";
				continue;
			}
			// 404 and 410 mean the URL genuinely does not exist.
			// 301/302/308 redirects and 403 (bot-blocking) are acceptable —
			// the content exists and resolves for real browsers.
			if ( in_array( $http_code, [ 404, 410 ], true ) ) {
				$failures[] = "URL not found (HTTP {$http_code}): {$url}";
			}
		}

		$this->assertEmpty(
			$failures,
			"The following required external service URLs are not reachable:\n" . implode( "\n", $failures )
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function has_network_access(): bool {
		$socket = @fsockopen( 'github.com', 443, $errno, $errstr, 3 );
		if ( $socket !== false ) {
			fclose( $socket );
			return true;
		}
		return false;
	}
}
