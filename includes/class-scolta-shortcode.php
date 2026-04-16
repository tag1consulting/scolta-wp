<?php
/**
 * Shortcode and block registration for the Scolta search UI.
 *
 * WordPress gives users two ways to embed content:
 *   - [scolta_search] shortcode (works everywhere, Classic + Block editors)
 *   - Gutenberg block (future — requires @wordpress/scripts build pipeline)
 *
 * The shortcode outputs a container div and enqueues scolta.js + config.
 * The actual search UI is rendered client-side by scolta.js, identical to
 * how it works on Drupal and Laravel. One JS file, three platforms.
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Config\ScoltaConfig;

class Scolta_Shortcode {

	/**
	 * Register the [scolta_search] shortcode.
	 */
	public static function register(): void {
		add_shortcode( 'scolta_search', array( self::class, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * Outputs the search container and enqueues the shared scolta.js
	 * library with WordPress-specific configuration injected via
	 * wp_localize_script() — the WordPress-standard way to pass PHP
	 * config to JavaScript.
	 *
	 * @param array $atts Shortcode attributes (currently unused, reserved for future).
	 * @return string HTML output.
	 */
	public static function render( array $atts = array() ): string {
		$settings     = get_option( 'scolta_settings', array() );
		$output_dir   = $settings['output_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/pagefind';
		$index_exists = file_exists( $output_dir . '/pagefind/pagefind-entry.json' );

		if ( ! $index_exists ) {
			if ( current_user_can( 'manage_options' ) ) {
				$settings_url = esc_url( admin_url( 'options-general.php?page=scolta' ) );
				return '<div class="scolta-no-index"'
					. ' style="padding:20px;background:#fff3cd;border:1px solid #ffc107;'
					. 'border-radius:4px;margin:20px 0;">'
					. '<p><strong>Scolta:</strong> Search index has not been built yet.</p>'
					. '<p><a href="' . $settings_url . '">Build now &rarr;</a>'
					. ' or run <code>wp scolta build</code></p></div>';
			}
			return ''; // Hide search box for non-admins when index doesn't exist.
		}

		$config = ScoltaConfig::fromArray( $settings );

		// Determine the Pagefind index URL path.
		// The output dir is an absolute filesystem path — convert to URL.
		$output_dir   = $settings['output_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/pagefind';
		$pagefind_url = self::dir_to_url( $output_dir );

		// Enqueue the shared scolta.js and scolta.css from the Composer package.
		$scolta_js_path = SCOLTA_PLUGIN_DIR . 'vendor/tag1/scolta-php/assets/js/scolta.js';
		if ( file_exists( $scolta_js_path ) ) {
			wp_enqueue_script(
				'scolta-search',
				SCOLTA_PLUGIN_URL . 'vendor/tag1/scolta-php/assets/js/scolta.js',
				array(),
				SCOLTA_VERSION,
				true // Load in footer.
			);
		}

		$scolta_css_path = SCOLTA_PLUGIN_DIR . 'vendor/tag1/scolta-php/assets/css/scolta.css';
		if ( file_exists( $scolta_css_path ) ) {
			wp_enqueue_style(
				'scolta-search',
				SCOLTA_PLUGIN_URL . 'vendor/tag1/scolta-php/assets/css/scolta.css',
				array(),
				SCOLTA_VERSION
			);
		}

		// Enqueue Pagefind UI CSS (from the built index).
		$pagefind_css = $output_dir . '/pagefind-ui.css';
		if ( file_exists( $pagefind_css ) ) {
			wp_enqueue_style(
				'scolta-pagefind-ui',
				$pagefind_url . '/pagefind-ui.css',
				array(),
				SCOLTA_VERSION
			);
		}

		// Pass config to JS via wp_localize_script.
		// This sets window.scolta before scolta.js runs.
		wp_localize_script(
			'scolta-search',
			'scolta',
			array(
				'scoring'            => $config->toJsScoringConfig(),
				'wasmPath'           => SCOLTA_PLUGIN_URL
					. 'vendor/tag1/scolta-php/assets/wasm/scolta_core.js',
				'endpoints'          => array(
					'expand'    => rest_url( 'scolta/v1/expand-query' ),
					'summarize' => rest_url( 'scolta/v1/summarize' ),
					'followup'  => rest_url( 'scolta/v1/followup' ),
				),
				'pagefindPath'       => $pagefind_url . '/pagefind.js',
				'siteName'           => ! empty( $config->siteName )
					? $config->siteName
					: get_bloginfo( 'name' ),
				'container'          => '#scolta-search',
				'allowedLinkDomains' => array(),
				'disclaimer'         => '',
				'nonce'              => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Output the container that scolta.js targets.
		return '<div id="scolta-search"></div>';
	}

	/**
	 * Convert an absolute filesystem path to a site-relative URL.
	 *
	 * WordPress doesn't have a single built-in for this, but the math
	 * is straightforward: strip the ABSPATH prefix and prepend the site URL.
	 */
	private static function dir_to_url( string $dir ): string {
		// Normalize both paths for comparison.
		$real_abspath = realpath( ABSPATH );
		$abspath      = rtrim( ! empty( $real_abspath ) ? $real_abspath : ABSPATH, '/' );
		$real_dir     = realpath( $dir );
		$dir          = rtrim( ! empty( $real_dir ) ? $real_dir : $dir, '/' );

		if ( str_starts_with( $dir, $abspath ) ) {
			$relative = substr( $dir, strlen( $abspath ) );
			return site_url( $relative );
		}

		// If the dir is in wp-content, use content_url().
		$content_dir = rtrim( WP_CONTENT_DIR, '/' );
		if ( str_starts_with( $dir, $content_dir ) ) {
			$relative = substr( $dir, strlen( $content_dir ) );
			return content_url( $relative );
		}

		// Fallback: assume it's relative to site root.
		return site_url( '/scolta-pagefind' );
	}
}
