<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the Sub-word Guard Denylist field
 * (render_expand_subword_deny_list_field() + sanitizer).
 *
 * Release-gate coverage: the admin field must render its saved value so a
 * site can configure the scolta-php sub-word query-term exemption denylist
 * (scolta-php#156 follow-up).
 *
 * The admin class is only loaded when is_admin() returns true; load it
 * explicitly for testing.
 */
class AdminSubwordDenyListFieldTest extends TestCase {

	public static function set_up_before_class(): void {
		if ( ! class_exists( 'Scolta_Admin' ) ) {
			require_once dirname( __DIR__ ) . '/admin/class-scolta-admin.php';
		}
	}

	protected function set_up(): void {
		$GLOBALS['wp_options'] = array();
	}

	private function renderField(): string {
		ob_start();
		Scolta_Admin::render_expand_subword_deny_list_field();
		return (string) ob_get_clean();
	}

	public function test_renders_empty_value_by_default(): void {
		$html = $this->renderField();
		$this->assertStringContainsString( 'name="scolta_settings[expand_subword_deny_list]"', $html );
		$this->assertStringContainsString( 'value=""', $html );
	}

	public function test_renders_saved_value_comma_joined(): void {
		update_option( 'scolta_settings', array( 'expand_subword_deny_list' => array( 'hot', 'easy' ) ) );
		$html = $this->renderField();
		$this->assertStringContainsString( 'value="hot, easy"', $html );
	}

	public function test_sanitize_parses_comma_list_to_lowercase_tokens(): void {
		$clean = Scolta_Admin::sanitize_settings( array( 'expand_subword_deny_list' => 'Hot, Easy , dishes' ) );
		$this->assertSame( array( 'hot', 'easy', 'dishes' ), $clean['expand_subword_deny_list'] );
	}

	public function test_sanitize_defaults_to_empty_array_when_missing(): void {
		$clean = Scolta_Admin::sanitize_settings( array() );
		$this->assertSame( array(), $clean['expand_subword_deny_list'] );
	}
}
