<?php
/**
 * Plugin Name:       Scolta AI Search
 * Plugin URI:        https://www.tag1.com/scolta
 * Description:       Zero-infrastructure AI search with Pagefind, query expansion, summarization.
 * Version:       1.0.0-dev
 * Requires at least: 6.0
 *   — No WP 6.1+ APIs used. Verified: no wp_register_block_type_from_metadata()
 *     call-style, no Interactivity API, no wp_admin_notice(), no Plugin
 *     Dependencies header. If a future change introduces a 6.x+ API, update
 *     both this header and README.md.
 * Requires PHP:      8.1
 * Author:            Tag1 Consulting
 * Author URI:        https://www.tag1.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scolta
 */

defined( 'ABSPATH' ) || exit;

define( 'SCOLTA_VERSION', '1.0.0-dev' );
define( 'SCOLTA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCOLTA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SCOLTA_PLUGIN_FILE', __FILE__ );

// Composer autoloader (scolta-php).
$scolta_autoloader = SCOLTA_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $scolta_autoloader ) ) {
	require_once $scolta_autoloader;
}

// Plugin includes.
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-tracker.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-content-source.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-amazee-config-storage.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-amazee-budget-handler.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-ai-service.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-cache-driver.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-prompt-enricher.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-content-gatherer.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-logger.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-rest-api.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-shortcode.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-rebuild-scheduler.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-auto-rebuild.php';

// Admin.
if ( is_admin() ) {
	require_once SCOLTA_PLUGIN_DIR . 'admin/class-scolta-admin.php';
	require_once SCOLTA_PLUGIN_DIR . 'admin/class-scolta-amazee-admin-page.php';
	Scolta_Amazee_Admin_Page::init();
}

// WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-wp-cli-logger.php';
	require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-wp-cli-progress-reporter.php';
	require_once SCOLTA_PLUGIN_DIR . 'cli/class-scolta-cli.php';
}

/**
 * Return the canonical default output_dir for the Pagefind index.
 *
 * The value is the parent directory — without a trailing /pagefind suffix.
 * The PHP indexer's IndexBuildOrchestrator always appends /pagefind during
 * atomicSwap(), so passing a path that already ends in /pagefind creates
 * double-nested directories. Every fallback in every file must use this
 * function instead of an inline literal.
 *
 * @return string Absolute filesystem path, no trailing slash.
 */
function scolta_default_output_dir(): string {
	return wp_upload_dir()['basedir'] . '/scolta';
}

/**
 * Remove any double-nested pagefind/pagefind directory under output_dir.
 *
 * Called after every successful index build to clean up artifacts left by
 * the old /pagefind-suffixed output_dir default. Safe to call when the
 * nested directory does not exist.
 *
 * @param string $output_dir The configured output_dir (parent of the index).
 */
function scolta_cleanup_nested_indexes( string $output_dir ): void {
	$nested_dir = rtrim( $output_dir, '/' ) . '/pagefind/pagefind';
	if ( ! is_dir( $nested_dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $nested_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $file ) {
		if ( $file->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing stale index artifact.
			rmdir( $file->getPathname() );
		} else {
			wp_delete_file( $file->getPathname() );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing stale index artifact.
	rmdir( $nested_dir );
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational notice for stale artifact removal.
	error_log( 'Scolta: Removed stale double-nested pagefind directory: ' . $nested_dir );
}

/**
 * Check whether the Pagefind binary is available and executable.
 *
 * Used during activation to auto-select the PHP indexer when no binary exists.
 *
 * @return bool True if the Pagefind binary can be found and executed.
 */
function scolta_pagefind_binary_available(): bool {
	$resolver = new \Tag1\Scolta\Binary\PagefindBinary(
		configuredPath: null,
		projectDir: SCOLTA_PLUGIN_DIR,
	);
	return $resolver->resolve() !== null;
}

/**
 * Activation: create tracker table and set default options.
 */
function scolta_activate(): void {
	Scolta_Tracker::create_table();

	$upload_dir = wp_upload_dir();

	$defaults = array(
		'ai_provider'                => 'anthropic',
		'ai_model'                   => 'claude-sonnet-4-5-20250929',
		'ai_expansion_model'         => '',
		'ai_base_url'                => '',
		'site_name'                  => get_bloginfo( 'name' ),
		'site_description'           => 'website',
		'search_page_path'           => '/scolta-search',
		'pagefind_index_path'        => wp_upload_dir()['baseurl'] . '/scolta/pagefind',
		'pagefind_binary'            => 'pagefind',
		'build_dir'                  => wp_upload_dir()['basedir'] . '/scolta/build',
		'output_dir'                 => scolta_default_output_dir(),
		'indexer'                    => 'auto',
		'memory_budget_profile'      => 'conservative',
		'auto_rebuild'               => true,
		'post_types'                 => array( 'post', 'page' ),
		'cache_ttl'                  => 2592000,
		'max_follow_ups'             => 3,
		'ai_expand_query'            => true,
		'ai_summarize'               => true,
		'ai_languages'               => array( 'en' ),
		// Scoring.
		'title_match_boost'          => 1.0,
		'title_all_terms_multiplier' => 1.5,
		'content_match_boost'        => 0.4,
		'recency_boost_max'          => 0.5,
		'recency_half_life_days'     => 365,
		'recency_penalty_after_days' => 1825,
		'recency_max_penalty'        => 0.3,
		'expand_primary_weight'      => 0.5,
		// Display.
		'excerpt_length'             => 300,
		'results_per_page'           => 10,
		'max_pagefind_results'       => 50,
		'ai_summary_top_n'           => 10,
		'ai_summary_max_chars'       => 4000,
		// Prompt overrides.
		'prompt_expand_query'        => '',
		'prompt_summarize'           => '',
		'prompt_follow_up'           => '',
	);

	// Ensure index directories exist in uploads (writable on all managed hosts).
	wp_mkdir_p( $upload_dir['basedir'] . '/scolta/build' );
	wp_mkdir_p( $upload_dir['basedir'] . '/scolta/pagefind' );

	// New installs: set defaults with autoload disabled.
	if ( false === get_option( 'scolta_settings' ) ) {
		// 'auto' default is correct — auto always means PHP on every code path.
		// No binary probe needed here.
		add_option( 'scolta_settings', $defaults, '', false );
	} else {
		// Existing installs: merge in new defaults for added fields,
		// and fix autoload flag.
		$existing = get_option( 'scolta_settings', array() );

		// Migrate build_dir/output_dir from old defaults to current uploads-based paths.
		$old_build     = wp_normalize_path( WP_CONTENT_DIR . '/scolta-build' );
		$build_matches = wp_normalize_path( $existing['build_dir'] ?? '' ) === $old_build;
		if ( ! isset( $existing['build_dir'] ) || $build_matches ) {
			$existing['build_dir'] = $upload_dir['basedir'] . '/scolta/build';
		}
		// Two old output_dir defaults that both need migration:
		// 1. ABSPATH . 'scolta-pagefind'  (very old default, pre-uploads migration)
		// 2. uploads/scolta/pagefind       (old default with /pagefind suffix that caused
		//    double-nesting in the PHP indexer's atomicSwap)
		$old_output_abspath  = wp_normalize_path( ABSPATH . 'scolta-pagefind' );
		$old_output_pagefind = wp_normalize_path( $upload_dir['basedir'] . '/scolta/pagefind' );
		$current_output      = wp_normalize_path( $existing['output_dir'] ?? '' );
		if ( ! isset( $existing['output_dir'] )
			|| $current_output === $old_output_abspath
			|| $current_output === $old_output_pagefind
		) {
			$existing['output_dir'] = scolta_default_output_dir();
		}

		$merged = array_merge( $defaults, $existing );
		update_option( 'scolta_settings', $merged );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time activation; setting autoload=no on scolta_settings.
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => 'scolta_settings' )
		);
	}

	// Queue initial index build and Amazee.ai provisioning if Action Scheduler
	// is available. Both are deferred so activation does not block on HTTP calls.
	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time() + 5, 'scolta_amazee_provision', array(), 'scolta' );
		as_schedule_single_action( time() + 10, 'scolta_rebuild_start', array(), 'scolta' );
	} else {
		// Without Action Scheduler, fall back to synchronous provisioning.
		scolta_auto_provision_amazee();
	}

	// Set transient for admin notice.
	set_transient( 'scolta_activated', true, 60 );
}
register_activation_hook( __FILE__, 'scolta_activate' );

// Register the Action Scheduler callback for background provisioning.
add_action( 'scolta_amazee_provision', 'scolta_auto_provision_amazee' );

/**
 * Attempt Amazee.ai trial provisioning at plugin activation time.
 *
 * No-op when the user has an explicit API key configured, or when
 * credentials are already stored. Failures are silenced — activation
 * succeeds regardless.
 */
function scolta_auto_provision_amazee(): void {
	$storage = new Scolta_Amazee_Config_Storage();

	\Tag1\Scolta\AiProvider\Amazee\AutoProvisioner::ensureAiAvailable(
		$storage,
		hasExplicitApiKey: scolta_has_explicit_api_key(),
	);
}

/**
 * Check whether the site has an explicit Scolta API key configured.
 *
 * Returns true when SCOLTA_API_KEY env var, $_ENV, $_SERVER, a
 * wp-config.php constant, or the database-stored legacy option is
 * non-empty, meaning the user has their own provider and
 * auto-provisioning should be skipped.
 *
 * @return bool True if an explicit API key is configured.
 */
function scolta_has_explicit_api_key(): bool {
	$env = getenv( 'SCOLTA_API_KEY' );
	if ( $env !== false && $env !== '' ) {
		return true;
	}
	if ( ! empty( $_ENV['SCOLTA_API_KEY'] ) || ! empty( $_SERVER['SCOLTA_API_KEY'] ) ) {
		return true;
	}
	if ( defined( 'SCOLTA_API_KEY' ) && SCOLTA_API_KEY !== '' ) {
		return true;
	}
	// Database-stored key (admin UI / legacy migration path).
	$settings = get_option( 'scolta_settings', array() );
	if ( ! empty( $settings['ai_api_key'] ) ) {
		return true;
	}
	return false;
}

/**
 * Show one-time admin notice after plugin activation.
 */
add_action(
	'admin_notices',
	function () {
		if ( ! get_transient( 'scolta_activated' ) ) {
			return;
		}
		delete_transient( 'scolta_activated' );
		$settings          = get_option( 'scolta_settings', array() );
		$using_php_indexer = ( $settings['indexer'] ?? 'auto' ) === 'php';
		echo '<div class="notice notice-info"><p>';
		echo 'Scolta activated!';
		if ( function_exists( 'as_schedule_single_action' ) ) {
			echo ' Your search index will be built automatically in the background.';
		} else {
			echo ' Run <code>wp scolta build</code> to build your search index.';
			echo ' Install <a href="https://actionscheduler.org/">Action Scheduler</a>'
				. ' for automatic background indexing.';
		}
		if ( $using_php_indexer ) {
			echo ' Using the PHP indexer (Pagefind binary not found).';
		}
		$settings_url = esc_url( admin_url( 'options-general.php?page=scolta' ) );
		echo wp_kses_post( ' <a href="' . $settings_url . '">View settings &rarr;</a>' );
		echo '</p></div>';
	}
);

/**
 * Deactivation: clean up transients.
 */
function scolta_deactivate(): void {
	// Clean up expand transients.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient cleanup during deactivation; bulk delete cannot use delete_transient().
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_scolta_expand_%',
			'_transient_timeout_scolta_expand_%'
		)
	);

	// Clear Action Scheduler actions.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'scolta_rebuild_start', array(), 'scolta' );
		as_unschedule_all_actions( 'scolta_process_chunk', null, 'scolta' );
		as_unschedule_all_actions( 'scolta_finalize_build', array(), 'scolta' );
		as_unschedule_all_actions( 'scolta_debounced_rebuild', array(), 'scolta' );
	}

	// Clear build locks and state.
	delete_transient( 'scolta_build_lock' );
	delete_transient( 'scolta_build_chunks' );
	delete_option( 'scolta_build_status' );
	delete_option( 'scolta_build_force' );
}
register_deactivation_hook( __FILE__, 'scolta_deactivate' );

/**
 * Content change tracking via WordPress hooks.
 *
 * This is the WordPress equivalent of Drupal's Search API tracker.
 * WordPress's hook system is one of its great strengths — we lean into it
 * rather than polling or scanning files.
 */
add_action(
	'save_post',
	function ( int $post_id, \WP_Post $post, bool $update ): void {
		$settings      = get_option( 'scolta_settings', array() );
		$tracked_types = $settings['post_types'] ?? array( 'post', 'page' );

		if ( ! in_array( $post->post_type, $tracked_types, true ) ) {
			return;
		}

		// Skip autosaves and revisions.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( $post->post_status === 'publish' ) {
			Scolta_Tracker::track( $post_id, $post->post_type, 'index' );
		} else {
			// Unpublished/drafted — remove from index.
			Scolta_Tracker::track( $post_id, $post->post_type, 'delete' );
		}
	},
	10,
	3
);

add_action(
	'before_delete_post',
	function ( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$settings      = get_option( 'scolta_settings', array() );
		$tracked_types = $settings['post_types'] ?? array( 'post', 'page' );

		if ( in_array( $post->post_type, $tracked_types, true ) ) {
			Scolta_Tracker::track( $post_id, $post->post_type, 'delete' );
		}
	}
);

/**
 * Transition hook — catches publish↔draft transitions that save_post
 * doesn't always fire for (e.g., Quick Edit, bulk actions).
 */
add_action(
	'transition_post_status',
	function ( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		$settings      = get_option( 'scolta_settings', array() );
		$tracked_types = $settings['post_types'] ?? array( 'post', 'page' );

		if ( ! in_array( $post->post_type, $tracked_types, true ) ) {
			return;
		}

		if ( $new_status === 'publish' ) {
			Scolta_Tracker::track( $post->ID, $post->post_type, 'index' );
		} elseif ( $old_status === 'publish' ) {
			// Was published, now isn't — remove from index.
			Scolta_Tracker::track( $post->ID, $post->post_type, 'delete' );
		}
	},
	10,
	3
);

/**
 * Cache resolved prompts when settings are saved.
 *
 * DefaultPrompts::resolve() is pure PHP (no WASM), so this is cheap.
 * Caching avoids repeated string replacement on every AI request.
 */
add_action(
	'update_option_scolta_settings',
	function ( $old, $new ): void {
		$site_name = $new['site_name'] ?? get_bloginfo( 'name' );
		$site_desc = $new['site_description'] ?? 'website';

		$all = array();
		foreach ( array( 'expand_query', 'summarize', 'follow_up' ) as $name ) {
			$all[ $name ] = \Tag1\Scolta\Prompt\DefaultPrompts::resolve(
				$name,
				$site_name,
				$site_desc,
			);
		}
		update_option( 'scolta_resolved_prompts', $all );
	},
	10,
	2
);

/**
 * Rebuild the resolved-prompt cache when the plugin version changes.
 *
 * The update_option_scolta_settings hook only fires on explicit settings saves,
 * so cached prompts become stale after a plugin update that changes DefaultPrompts.
 * This function detects the version mismatch and rebuilds the cache automatically.
 */
function scolta_refresh_prompt_cache_if_stale(): void {
	if ( get_option( 'scolta_prompt_cache_version', '' ) === SCOLTA_VERSION ) {
		return;
	}
	$settings  = get_option( 'scolta_settings', array() );
	$site_name = $settings['site_name'] ?? get_bloginfo( 'name' );
	$site_desc = $settings['site_description'] ?? 'website';

	$all = array();
	foreach ( array( 'expand_query', 'summarize', 'follow_up' ) as $name ) {
		$all[ $name ] = \Tag1\Scolta\Prompt\DefaultPrompts::resolve(
			$name,
			$site_name,
			$site_desc,
		);
	}
	update_option( 'scolta_resolved_prompts', $all, false );
	update_option( 'scolta_prompt_cache_version', SCOLTA_VERSION, false );
}
add_action( 'plugins_loaded', 'scolta_refresh_prompt_cache_if_stale' );

/**
 * REST API registration.
 */
add_action(
	'rest_api_init',
	function (): void {
		Scolta_Rest_Api::register_routes();
	}
);

/**
 * Register shortcode for embedding the search UI.
 */
add_action(
	'init',
	function (): void {
		Scolta_Shortcode::register();
	}
);

/**
 * Initialize background rebuild scheduler and auto-rebuild hooks.
 */
add_action( 'init', array( 'Scolta_Rebuild_Scheduler', 'init' ) );
add_action( 'init', array( 'Scolta_Auto_Rebuild', 'init' ) );
