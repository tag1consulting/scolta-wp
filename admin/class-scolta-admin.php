<?php
/**
 * Scolta admin settings page.
 *
 * WordPress's Settings API handles nonce verification, option serialization,
 * and form rendering. Everything in a single serialized option (scolta_settings).
 *
 * API key is NOT stored in the database. It comes from environment variables
 * or wp-config.php constants. The admin shows status indicators for the key
 * source but never provides an input field for it.
 *
 * @package Scolta
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page, dashboard widget, and admin AJAX handlers for Scolta.
 */
class Scolta_Admin {

	/**
	 * Hook into WordPress admin.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_settings_page' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_scripts' ) );
		add_action( 'admin_notices', array( self::class, 'maybe_show_setup_notice' ) );
		add_action( 'wp_dashboard_setup', array( self::class, 'add_dashboard_widget' ) );

		// AJAX handler for removing legacy DB key.
		add_action( 'wp_ajax_scolta_remove_db_key', array( self::class, 'ajax_remove_db_key' ) );

		// AJAX handler for testing LLM connection.
		add_action( 'wp_ajax_scolta_test_connection', array( self::class, 'ajax_scolta_test_connection' ) );

		// Admin POST handler for the "Rebuild Now" button in the status summary.
		add_action( 'admin_post_scolta_rebuild_now', array( self::class, 'handle_rebuild_now' ) );

		// Admin POST handler for dismissing the rebuild result notice.
		add_action( 'admin_post_scolta_dismiss_rebuild_notice', array( self::class, 'handle_dismiss_rebuild_notice' ) );

		// Show rebuild result notices.
		add_action( 'admin_notices', array( self::class, 'maybe_show_rebuild_notice' ) );

		// AI features opt-in flow (builds with auto-provisioning disabled,
		// e.g. the WordPress.org distribution): availability notice, the
		// explicit enable action, its result notice, and server-side
		// notice dismissal.
		add_action( 'admin_notices', array( self::class, 'maybe_show_ai_optin_notice' ) );
		add_action( 'admin_notices', array( self::class, 'maybe_show_ai_optin_result_notice' ) );
		add_action( 'admin_post_scolta_enable_ai', array( self::class, 'handle_enable_ai' ) );
		add_action( 'admin_post_scolta_dismiss_ai_optin_notice', array( self::class, 'handle_dismiss_ai_optin_notice' ) );

		// Show auto-configured Amazee.ai model notice.
		add_action( 'admin_notices', array( self::class, 'maybe_show_amazee_models_notice' ) );

		// Show a pending Amazee.ai budget-exceeded notice. The budget error
		// fires during front-end/REST search requests where admin_notices
		// never runs, so the handler persists a transient and this hook
		// renders it on the next admin page load.
		add_action( 'admin_notices', array( Scolta_Amazee_Budget_Handler::class, 'maybe_render_pending_notice' ) );

		// Show a pending Amazee.ai credential decrypt-failure notice (same
		// persisted-transient pattern — the failure surfaces during AI
		// requests, not admin page loads).
		add_action( 'admin_notices', array( Scolta_Amazee_Config_Storage::class, 'maybe_render_decrypt_failure_notice' ) );
	}

	/**
	 * Enqueue admin scripts for the Scolta settings page.
	 *
	 * Registers a handle with no source file and attaches all settings-page
	 * inline scripts via wp_add_inline_script() rather than echoing <script>
	 * tags directly in field renderer callbacks.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_admin_scripts( string $hook ): void {
		if ( 'settings_page_scolta' !== $hook ) {
			return;
		}

		wp_register_script( 'scolta-admin', false, array(), SCOLTA_VERSION, true );
		wp_enqueue_script( 'scolta-admin' );

		wp_localize_script(
			'scolta-admin',
			'scoltaAdminL10n',
			array(
				'confirmRemoveDbKey' => __( 'Remove the API key from the database? Make sure you have set the SCOLTA_API_KEY environment variable first.', 'scolta-ai-search' ),
				'testing'            => __( 'Testing…', 'scolta-ai-search' ),
				'connected'          => __( 'Connected', 'scolta-ai-search' ),
				'failed'             => __( 'Failed', 'scolta-ai-search' ),
				'networkError'       => __( 'Network error', 'scolta-ai-search' ),
				'defaultBadge'       => __( '(default)', 'scolta-ai-search' ),
			)
		);

		// Reset-to-default buttons for the custom prompt textareas. The
		// script handle loads in the footer, after the settings fields exist.
		wp_add_inline_script(
			'scolta-admin',
			'(function(){
	document.querySelectorAll(".scolta-prompt-reset").forEach(function(btn){
		btn.addEventListener("click", function(){
			var ta = document.getElementById(btn.dataset.textareaId);
			if (!ta) return;
			ta.value = ta.dataset.defaultPrompt;
			var wrap = btn.closest("div");
			var badge = wrap ? wrap.querySelector(".scolta-badge") : null;
			if (badge) {
				badge.textContent = (window.scoltaAdminL10n || {}).defaultBadge || "(default)";
				badge.style.cssText = "color:#888;font-style:italic;margin-left:0.5em;";
			}
			btn.remove();
		});
	});
}());'
		);

		wp_add_inline_script(
			'scolta-admin',
			'(function(){
	var sel = document.getElementById("scolta_preset");
	if (!sel) return;
	sel.addEventListener("change", function(){
		document.querySelectorAll(".scolta-preset-desc").forEach(function(el){
			el.style.display = "none";
		});
		var active = document.querySelector(".scolta-preset-desc--" + sel.value);
		if (active) active.style.display = "";
	});
}());'
		);

		wp_add_inline_script(
			'scolta-admin',
			'(function(){
	var btn = document.getElementById("scolta-remove-db-key");
	if (!btn) return;
	btn.addEventListener("click", function() {
		var l10n = window.scoltaAdminL10n || {};
		if (!confirm(l10n.confirmRemoveDbKey || "")) return;
		var nonceField = document.getElementById("scolta_remove_db_key_nonce");
		var data = new FormData();
		data.append("action", "scolta_remove_db_key");
		if (nonceField) data.append("_wpnonce", nonceField.value);
		fetch(ajaxurl, { method: "POST", body: data })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				var status = document.getElementById("scolta-remove-db-key-status");
				if (status) status.textContent = d.success ? " Removed." : " Failed.";
				if (d.success) location.reload();
			});
	});
}());'
		);

		wp_add_inline_script(
			'scolta-admin',
			'(function(){
	var btn = document.getElementById("scolta-test-connection-btn");
	if (!btn) return;
	function setResult(result, color, label, detail) {
		result.textContent = "";
		var span = document.createElement("span");
		span.style.color = color;
		span.textContent = label;
		result.appendChild(span);
		if (detail) result.appendChild(document.createTextNode(detail));
	}
	btn.addEventListener("click", function() {
		var l10n = window.scoltaAdminL10n || {};
		var result = document.getElementById("scolta-test-result");
		btn.disabled = true;
		setResult(result, "#666", l10n.testing || "Testing…");
		result.style.display = "inline";
		var data = new FormData();
		data.append("action", "scolta_test_connection");
		data.append("nonce", btn.dataset.nonce);
		fetch(ajaxurl, { method: "POST", body: data })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.success) {
					setResult(result, "#28a745", "✓ " + (l10n.connected || "Connected"),
						" (" + d.data.provider + " / " + d.data.model + ", " + d.data.response_time + "ms)");
				} else {
					setResult(result, "#dc3545", "✗ " + (l10n.failed || "Failed") + ":", " " + d.data.error);
				}
			})
			.catch(function() {
				setResult(result, "#dc3545", "✗ " + (l10n.networkError || "Network error"));
			})
			.finally(function() { btn.disabled = false; });
	});
}());'
		);
	}

	/**
	 * Add the settings page under Settings > Scolta.
	 */
	public static function add_settings_page(): void {
		add_options_page(
			__( 'Scolta AI Search', 'scolta-ai-search' ),
			__( 'Scolta', 'scolta-ai-search' ),
			'manage_options',
			'scolta',
			array( self::class, 'render_settings_page' )
		);
	}

	/**
	 * Register all settings, sections, and fields.
	 */
	public static function register_settings(): void {
		register_setting(
			'scolta_settings_group',
			'scolta_settings',
			array(
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		// --- Section: AI Provider ---
		add_settings_section( 'scolta_ai_section', __( 'AI Provider', 'scolta-ai-search' ), array( self::class, 'render_ai_section' ), 'scolta' );

		// Only show manual AI config fields when WP AI Client SDK is NOT available.
		if ( ! class_exists( '\WordPress\AI\Client' ) ) {
			add_settings_field( 'ai_provider', __( 'Provider', 'scolta-ai-search' ), array( self::class, 'render_ai_provider_field' ), 'scolta', 'scolta_ai_section' );
			add_settings_field( 'ai_api_key_status', __( 'API Key', 'scolta-ai-search' ), array( self::class, 'render_api_key_status_field' ), 'scolta', 'scolta_ai_section' );
			add_settings_field( 'ai_model', __( 'Model', 'scolta-ai-search' ), array( self::class, 'render_ai_model_field' ), 'scolta', 'scolta_ai_section' );
			add_settings_field( 'ai_expansion_model', __( 'Expansion Model', 'scolta-ai-search' ), array( self::class, 'render_ai_expansion_model_field' ), 'scolta', 'scolta_ai_section' );
			add_settings_field( 'ai_base_url', __( 'Base URL', 'scolta-ai-search' ), array( self::class, 'render_ai_base_url_field' ), 'scolta', 'scolta_ai_section' );
		}

		add_settings_field( 'ai_expand_query', __( 'AI Query Expansion', 'scolta-ai-search' ), array( self::class, 'render_ai_expand_field' ), 'scolta', 'scolta_ai_section' );
		add_settings_field( 'ai_summarize', __( 'AI Summarization', 'scolta-ai-search' ), array( self::class, 'render_ai_summarize_field' ), 'scolta', 'scolta_ai_section' );
		add_settings_field( 'max_follow_ups', __( 'Max Follow-ups', 'scolta-ai-search' ), array( self::class, 'render_max_followups_field' ), 'scolta', 'scolta_ai_section' );
		add_settings_field( 'ai_languages', __( 'AI Languages', 'scolta-ai-search' ), array( self::class, 'render_ai_languages_field' ), 'scolta', 'scolta_ai_section' );

		// --- Section: Content ---
		add_settings_section( 'scolta_content_section', __( 'Content', 'scolta-ai-search' ), array( self::class, 'render_content_section' ), 'scolta' );
		add_settings_field( 'post_types', __( 'Post Types', 'scolta-ai-search' ), array( self::class, 'render_post_types_field' ), 'scolta', 'scolta_content_section' );
		add_settings_field( 'site_name', __( 'Site Name', 'scolta-ai-search' ), array( self::class, 'render_site_name_field' ), 'scolta', 'scolta_content_section' );
		add_settings_field( 'site_description', __( 'Site Description', 'scolta-ai-search' ), array( self::class, 'render_site_description_field' ), 'scolta', 'scolta_content_section' );

		// --- Section: Search Customization ---
		add_settings_section( 'scolta_search_customization_section', __( 'Search Customization', 'scolta-ai-search' ), array( self::class, 'render_search_customization_section' ), 'scolta' );
		add_settings_field( 'sortable_fields', __( 'Sortable Fields', 'scolta-ai-search' ), array( self::class, 'render_sortable_fields_field' ), 'scolta', 'scolta_search_customization_section' );
		add_settings_field( 'sortable_field_descriptions', __( 'Sortable Field Descriptions', 'scolta-ai-search' ), array( self::class, 'render_sortable_field_descriptions_field' ), 'scolta', 'scolta_search_customization_section' );
		add_settings_field( 'filter_fields', __( 'Filter Fields', 'scolta-ai-search' ), array( self::class, 'render_filter_fields_field' ), 'scolta', 'scolta_search_customization_section' );
		add_settings_field( 'filter_field_descriptions', __( 'Filter Field Descriptions', 'scolta-ai-search' ), array( self::class, 'render_filter_field_descriptions_field' ), 'scolta', 'scolta_search_customization_section' );

		// --- Section: Pagefind ---
		add_settings_section( 'scolta_pagefind_section', __( 'Pagefind', 'scolta-ai-search' ), array( self::class, 'render_pagefind_section' ), 'scolta' );
		add_settings_field( 'indexer', __( 'Indexer', 'scolta-ai-search' ), array( self::class, 'render_indexer_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'pagefind_binary', __( 'Binary Path', 'scolta-ai-search' ), array( self::class, 'render_pagefind_binary_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'build_dir', __( 'Build Directory', 'scolta-ai-search' ), array( self::class, 'render_build_dir_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'output_dir', __( 'Output Directory', 'scolta-ai-search' ), array( self::class, 'render_output_dir_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'auto_rebuild', __( 'Auto Rebuild', 'scolta-ai-search' ), array( self::class, 'render_auto_rebuild_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'auto_rebuild_delay', __( 'Rebuild Delay (seconds)', 'scolta-ai-search' ), array( self::class, 'render_auto_rebuild_delay_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'memory_budget_profile', __( 'Memory Budget', 'scolta-ai-search' ), array( self::class, 'render_memory_budget_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'chunk_size', __( 'Chunk Size', 'scolta-ai-search' ), array( self::class, 'render_chunk_size_field' ), 'scolta', 'scolta_pagefind_section' );

		// --- Section: Site Type ---
		add_settings_section( 'scolta_site_type_section', __( 'Site Type', 'scolta-ai-search' ), array( self::class, 'render_site_type_section' ), 'scolta' );
		add_settings_field( 'preset', __( 'What kind of site is this?', 'scolta-ai-search' ), array( self::class, 'render_preset_field' ), 'scolta', 'scolta_site_type_section' );

		// --- Section: Scoring ---
		add_settings_section( 'scolta_scoring_section', __( 'Scoring', 'scolta-ai-search' ), array( self::class, 'render_scoring_section' ), 'scolta' );
		add_settings_field( 'title_match_boost', __( 'Title Match Boost', 'scolta-ai-search' ), array( self::class, 'render_title_boost_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'title_all_terms_multiplier', __( 'Title All-Terms Multiplier', 'scolta-ai-search' ), array( self::class, 'render_title_all_terms_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'content_match_boost', __( 'Content Match Boost', 'scolta-ai-search' ), array( self::class, 'render_content_boost_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_boost_max', __( 'Recency Boost', 'scolta-ai-search' ), array( self::class, 'render_recency_boost_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_half_life_days', __( 'Recency Half-life (days)', 'scolta-ai-search' ), array( self::class, 'render_recency_halflife_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_penalty_after_days', __( 'Recency Penalty After (days)', 'scolta-ai-search' ), array( self::class, 'render_recency_penalty_days_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_max_penalty', __( 'Recency Max Penalty', 'scolta-ai-search' ), array( self::class, 'render_recency_max_penalty_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'expand_primary_weight', __( 'Expand Primary Weight', 'scolta-ai-search' ), array( self::class, 'render_expand_weight_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'expand_subword_max_frequency', __( 'Search Breadth (advanced)', 'scolta-ai-search' ), array( self::class, 'render_subword_freq_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'language', __( 'Scoring Language', 'scolta-ai-search' ), array( self::class, 'render_language_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'custom_stop_words', __( 'Custom Stop Words', 'scolta-ai-search' ), array( self::class, 'render_custom_stop_words_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'expand_subword_deny_list', __( 'Sub-word Guard Denylist', 'scolta-ai-search' ), array( self::class, 'render_expand_subword_deny_list_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'expansion_combine_mode', __( 'Expansion Combine Mode', 'scolta-ai-search' ), array( self::class, 'render_expansion_combine_mode_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_strategy', __( 'Recency Strategy', 'scolta-ai-search' ), array( self::class, 'render_recency_strategy_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_curve', __( 'Custom Recency Curve', 'scolta-ai-search' ), array( self::class, 'render_recency_curve_field' ), 'scolta', 'scolta_scoring_section' );

		// --- Section: Display ---
		add_settings_section( 'scolta_display_section', __( 'Display', 'scolta-ai-search' ), array( self::class, 'render_display_section' ), 'scolta' );
		add_settings_field( 'excerpt_length', __( 'Excerpt Length', 'scolta-ai-search' ), array( self::class, 'render_excerpt_length_field' ), 'scolta', 'scolta_display_section' );
		add_settings_field( 'results_per_page', __( 'Results Per Page', 'scolta-ai-search' ), array( self::class, 'render_results_per_page_field' ), 'scolta', 'scolta_display_section' );
		add_settings_field( 'max_pagefind_results', __( 'Max Pagefind Results', 'scolta-ai-search' ), array( self::class, 'render_max_pagefind_results_field' ), 'scolta', 'scolta_display_section' );
		add_settings_field( 'ai_summary_top_n', __( 'AI Summary Top N', 'scolta-ai-search' ), array( self::class, 'render_ai_summary_top_n_field' ), 'scolta', 'scolta_display_section' );
		add_settings_field( 'ai_summary_max_chars', __( 'AI Summary Max Chars', 'scolta-ai-search' ), array( self::class, 'render_ai_summary_max_chars_field' ), 'scolta', 'scolta_display_section' );
		add_settings_field( 'show_attribution', __( 'Scolta Attribution', 'scolta-ai-search' ), array( self::class, 'render_show_attribution_field' ), 'scolta', 'scolta_display_section' );

		// --- Section: Cache ---
		add_settings_section( 'scolta_cache_section', __( 'Cache', 'scolta-ai-search' ), array( self::class, 'render_cache_section' ), 'scolta' );
		add_settings_field( 'cache_ttl', __( 'Query Expansion Cache Duration', 'scolta-ai-search' ), array( self::class, 'render_cache_ttl_field' ), 'scolta', 'scolta_cache_section' );

		// --- Section: Custom Prompts (Advanced) ---
		add_settings_section( 'scolta_prompts_section', __( 'Custom Prompts (Advanced)', 'scolta-ai-search' ), array( self::class, 'render_prompts_section' ), 'scolta' );
		add_settings_field( 'prompt_expand_query', __( 'Expand Query Prompt', 'scolta-ai-search' ), array( self::class, 'render_prompt_expand_field' ), 'scolta', 'scolta_prompts_section' );
		add_settings_field( 'prompt_summarize', __( 'Summarize Prompt', 'scolta-ai-search' ), array( self::class, 'render_prompt_summarize_field' ), 'scolta', 'scolta_prompts_section' );
		add_settings_field( 'prompt_follow_up', __( 'Follow-up Prompt', 'scolta-ai-search' ), array( self::class, 'render_prompt_followup_field' ), 'scolta', 'scolta_prompts_section' );
	}

	// -----------------------------------------------------------------
	// Section descriptions
	// -----------------------------------------------------------------

	/**
	 * Render the AI section description.
	 */
	public static function render_ai_section(): void {
		if ( class_exists( '\WordPress\AI\Client' ) ) {
			echo '<p class="description">';
			echo wp_kses_post(
				sprintf(
					/* translators: %s: URL to WordPress AI connectors settings page */
					__( 'AI is configured through the <a href="%s">WordPress AI Client SDK</a>. Scolta will use your configured AI provider automatically.', 'scolta-ai-search' ),
					esc_url( admin_url( 'options-general.php?page=ai-connectors' ) )
				)
			);
			echo '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Configure the AI provider for query expansion, summarization, and follow-up conversations.', 'scolta-ai-search' ) . '</p>';
		}
	}

	/**
	 * Render the Content section description.
	 */
	public static function render_content_section(): void {
		echo '<p class="description">' . esc_html__( 'Choose which content types to index and how your site is identified in search results.', 'scolta-ai-search' ) . '</p>';
	}

	/**
	 * Render the Search Customization section description.
	 */
	public static function render_search_customization_section(): void {
		echo '<p class="description">' . esc_html__( 'Optional. Configure sortable fields and filter dimensions so the AI can detect sort and filter intent in search queries.', 'scolta-ai-search' ) . '</p>';
	}

	/**
	 * Render the Pagefind section description.
	 */
	public static function render_pagefind_section(): void {
		echo '<p class="description">' . esc_html__( 'Pagefind builds a static search index from your exported content.', 'scolta-ai-search' ) . '</p>';
	}

	/**
	 * Render the Site Type section description.
	 */
	public static function render_site_type_section(): void {
		echo '<p class="description">' . esc_html__( 'Start here. Pick the closest match for your site — this gives you a good set of defaults. Presets adjust how Scolta ranks search results — how much weight goes to titles vs. page content, whether newer content ranks higher, and how broadly Scolta interprets what you searched for. The preset is a starting point, not a constraint: you can optionally change any individual setting in the Scoring section below.', 'scolta-ai-search' ) . '</p>';
	}

	/**
	 * Render the site-type preset select with per-preset descriptions.
	 */
	public static function render_preset_field(): void {
		$presets        = \Tag1\Scolta\Config\ScoltaConfig::getPresets();
		$current_preset = self::get_setting( 'preset', 'none' );
		$valid_presets  = array_keys( $presets );
		if ( ! in_array( $current_preset, $valid_presets, true ) ) {
			$current_preset = 'none';
		}
		echo '<select name="scolta_settings[preset]" id="scolta_preset">';
		foreach ( $presets as $key => $meta ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $current_preset, $key, false ),
				esc_html( $meta['label'] )
			);
		}
		echo '</select>';
		echo '<div class="scolta-preset-descriptions" style="margin-top:8px;">';
		foreach ( $presets as $key => $meta ) {
			printf(
				'<p class="description scolta-preset-desc scolta-preset-desc--%s"%s><strong>%s:</strong> %s</p>',
				esc_attr( $key ),
				$key !== $current_preset ? ' style="display:none"' : '',
				esc_html( $meta['label'] ),
				esc_html( $meta['description'] )
			);
		}
		echo '</div>';
	}

	/**
	 * Render the Scoring section description, noting the active preset.
	 */
	public static function render_scoring_section(): void {
		$current_preset = self::get_setting( 'preset', 'none' );
		if ( $current_preset !== 'none' ) {
			$presets = \Tag1\Scolta\Config\ScoltaConfig::getPresets();
			$label   = isset( $presets[ $current_preset ] ) ? $presets[ $current_preset ]['label'] : $current_preset;
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: preset label */
						__( 'These settings were populated by the %s preset. Change any value here and your change takes priority — the preset only fills in what you haven\'t touched.', 'scolta-ai-search' ),
						$label
					)
				)
			);
		} else {
			echo '<p class="description">' . esc_html__( 'Configure each scoring parameter individually. Fine-tune how search results are ranked. Defaults work well for most sites.', 'scolta-ai-search' ) . '</p>';
		}
	}

	/**
	 * Render the Display section description.
	 */
	public static function render_display_section(): void {
		echo '<p class="description">' . esc_html__( 'Control the search results display and AI summarization context.', 'scolta-ai-search' ) . '</p>';
	}

	/**
	 * Render the Cache section description.
	 */
	public static function render_cache_section(): void {
		echo '<p class="description">' . esc_html__( 'AI query expansion results are cached to reduce API calls.', 'scolta-ai-search' ) . '</p>';
	}

	/**
	 * Render the Custom Prompts section description.
	 */
	public static function render_prompts_section(): void {
		echo '<p class="description">' . esc_html__( 'Override the built-in AI system prompts. Leave empty to use the defaults.', 'scolta-ai-search' ) . '</p>';
	}

	// -----------------------------------------------------------------
	// Field renderers
	// -----------------------------------------------------------------

	/**
	 * Read one key from the scolta_settings option.
	 *
	 * @param string $key           Settings key to read.
	 * @param mixed  $default_value Value returned when the key is not set.
	 */
	private static function get_setting( string $key, mixed $default_value = '' ): mixed {
		$settings = get_option( 'scolta_settings', array() );
		return $settings[ $key ] ?? $default_value;
	}

	/**
	 * Render the AI provider select field.
	 */
	public static function render_ai_provider_field(): void {
		// The explicitly-saved provider always wins for the displayed selection.
		// API-key source auto-detection (e.g. an auto-provisioned Amazee trial)
		// is only a fallback for the empty state — when no provider was ever
		// saved. Activation seeds ai_provider='anthropic', so in practice the
		// fallback only applies to uninitialized/legacy settings (#123).
		$saved = self::get_setting( 'ai_provider', '' );
		if ( '' !== $saved ) {
			$value = $saved;
		} else {
			$source = Scolta_Ai_Service::get_api_key_source();
			$value  = ( 'amazee' === $source ) ? 'amazee' : 'anthropic';
		}
		?>
		<select name="scolta_settings[ai_provider]" id="scolta_ai_provider">
			<option value="anthropic" <?php selected( $value, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude)', 'scolta-ai-search' ); ?></option>
			<option value="openai" <?php selected( $value, 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'scolta-ai-search' ); ?></option>
			<option value="amazee" <?php selected( $value, 'amazee' ); ?>><?php esc_html_e( 'Amazee.ai (managed gateway)', 'scolta-ai-search' ); ?></option>
		</select>
		<?php if ( 'amazee' === $value ) : ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to Amazee.ai settings page */
				esc_html__( 'Amazee.ai provides a managed AI gateway with a free trial. %s', 'scolta-ai-search' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=scolta-amazee' ) ) . '">' . esc_html__( 'Configure Amazee.ai settings', 'scolta-ai-search' ) . '</a>'
			);
			?>
		</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render API key status (read-only — no input field).
	 */
	public static function render_api_key_status_field(): void {
		$source = Scolta_Ai_Service::get_api_key_source();

		switch ( $source ) {
			case 'amazee':
				echo '<div class="notice notice-success inline"><p>';
				echo esc_html__( 'Connected to Amazee.ai (managed gateway).', 'scolta-ai-search' );
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=scolta-amazee' ) ) . '">' . esc_html__( 'Amazee.ai settings', 'scolta-ai-search' ) . '</a>';
				echo '</p></div>';
				break;

			case 'env':
				echo '<div class="notice notice-success inline"><p>';
				echo esc_html__( 'API key loaded from SCOLTA_API_KEY environment variable.', 'scolta-ai-search' );
				echo '</p></div>';
				break;

			case 'constant':
				echo '<div class="notice notice-info inline"><p>';
				echo esc_html__( 'API key loaded from SCOLTA_API_KEY constant in wp-config.php.', 'scolta-ai-search' );
				echo '</p><p class="description">';
				echo esc_html__( 'For production hosting, consider using an environment variable instead.', 'scolta-ai-search' );
				echo '</p></div>';
				break;

			case 'database':
				echo '<div class="notice notice-error inline"><p>';
				echo '<strong>' . esc_html__( 'Security warning:', 'scolta-ai-search' ) . '</strong> ';
				echo esc_html__( 'API key is stored in the database, which is insecure. Migrate it to an environment variable by setting SCOLTA_API_KEY on your hosting platform, then remove the key from the database.', 'scolta-ai-search' );
				echo '</p><p>';
				echo '<button type="button" class="button" id="scolta-remove-db-key">';
				echo esc_html__( 'Remove key from database', 'scolta-ai-search' );
				echo '</button>';
				echo '<span id="scolta-remove-db-key-status"></span>';
				wp_nonce_field( 'scolta_remove_db_key', 'scolta_remove_db_key_nonce' );
				echo '</p></div>';
				break;

			default:
				echo '<div class="notice notice-error inline"><p>';
				echo esc_html__( 'No API key configured. Set the SCOLTA_API_KEY environment variable on your hosting platform.', 'scolta-ai-search' );
				echo '</p><p class="description">';
				printf(
					/* translators: %s: PHP code snippet */
					esc_html__( 'For local development, add %s to your wp-config.php.', 'scolta-ai-search' ),
					'<code>putenv(\'SCOLTA_API_KEY=sk-...\');</code>'
				);
				echo '</p></div>';
				break;
		}

		if ( $source !== 'none' ) {
			$nonce = wp_create_nonce( 'scolta_test_connection' );
			echo '<div style="margin-top: 10px;">';
			echo '<button type="button" class="button" id="scolta-test-connection-btn" data-nonce="' . esc_attr( $nonce ) . '">';
			echo esc_html__( 'Test Connection', 'scolta-ai-search' );
			echo '</button>';
			echo '<span id="scolta-test-result" style="margin-left: 10px; display: none;"></span>';
			echo '</div>';
		}
	}

	/**
	 * Render the AI model identifier field.
	 */
	public static function render_ai_model_field(): void {
		$value = self::get_setting( 'ai_model', 'claude-sonnet-4-5-20250929' );
		?>
		<input type="text" name="scolta_settings[ai_model]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Model identifier. e.g., claude-sonnet-4-5-20250929 or gpt-4o', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the optional query-expansion model override field.
	 */
	public static function render_ai_expansion_model_field(): void {
		$value = self::get_setting( 'ai_expansion_model', '' );
		?>
		<input type="text" name="scolta_settings[ai_expansion_model]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Optional model for query expansion only. Leave empty to use the main Model for all operations. Example: claude-haiku-4-5-20251001', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the AI base URL override field.
	 */
	public static function render_ai_base_url_field(): void {
		$value = self::get_setting( 'ai_base_url', '' );
		?>
		<input type="url" name="scolta_settings[ai_base_url]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="https://" />
		<p class="description"><?php esc_html_e( 'Override the default API endpoint. Must be an http(s) URL; leave empty for the provider default.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the AI query expansion enable checkbox.
	 */
	public static function render_ai_expand_field(): void {
		$value = self::get_setting( 'ai_expand_query', true );
		?>
		<label>
			<input type="checkbox" name="scolta_settings[ai_expand_query]" value="1" <?php checked( $value ); ?> />
			<?php esc_html_e( 'Use AI to expand search queries into related terms', 'scolta-ai-search' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the AI summarization enable checkbox.
	 */
	public static function render_ai_summarize_field(): void {
		$value = self::get_setting( 'ai_summarize', true );
		?>
		<label>
			<input type="checkbox" name="scolta_settings[ai_summarize]" value="1" <?php checked( $value ); ?> />
			<?php esc_html_e( 'Generate AI summaries of search results', 'scolta-ai-search' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the maximum follow-up questions field.
	 */
	public static function render_max_followups_field(): void {
		$value = self::get_setting( 'max_follow_ups', 3 );
		?>
		<input type="number" name="scolta_settings[max_follow_ups]" value="<?php echo esc_attr( $value ); ?>" min="0" max="10" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum conversational follow-up messages per search session. 0 to disable.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the AI languages field.
	 */
	public static function render_ai_languages_field(): void {
		$value = self::get_setting( 'ai_languages', array( 'en' ) );
		if ( ! is_array( $value ) ) {
			$value = array( 'en' );
		}
		$display = implode( ', ', $value );
		?>
		<input type="text" name="scolta_settings[ai_languages]" value="<?php echo esc_attr( $display ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Comma-separated language codes (e.g., en, es, fr). When multiple languages are configured, AI responses will match the language of the user\'s query.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the indexed post types checkbox list.
	 */
	public static function render_post_types_field(): void {
		$selected = self::get_setting( 'post_types', array( 'post', 'page' ) );
		if ( ! is_array( $selected ) ) {
			$selected = array( 'post', 'page' );
		}
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( $post_types as $pt ) {
			if ( $pt->name === 'attachment' ) {
				continue;
			}
			?>
			<label style="display: block; margin-bottom: 4px;">
				<input type="checkbox" name="scolta_settings[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $selected, true ) ); ?> />
				<?php echo esc_html( $pt->labels->name ); ?> <code>(<?php echo esc_html( $pt->name ); ?>)</code>
			</label>
			<?php
		}
		?>
		<p class="description"><?php esc_html_e( 'Content types to include in the search index.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the site name field.
	 */
	public static function render_site_name_field(): void {
		$value = self::get_setting( 'site_name', get_bloginfo( 'name' ) );
		?>
		<input type="text" name="scolta_settings[site_name]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Used in AI prompts and search result attribution. Defaults to your site title.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the site description field.
	 */
	public static function render_site_description_field(): void {
		$value = self::get_setting( 'site_description', 'website' );
		?>
		<input type="text" name="scolta_settings[site_description]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Brief description for AI context. e.g., "technology blog" or "university research portal"', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the sortable fields list.
	 */
	public static function render_sortable_fields_field(): void {
		$fields = self::get_setting( 'sortable_fields', array() );
		$value  = implode( ', ', is_array( $fields ) ? $fields : array() );
		?>
		<input type="text" name="scolta_settings[sortable_fields]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Comma-separated field names that Pagefind emits as data-pagefind-sort attributes. e.g., date, price, word_count. Leave empty to disable sort-intent detection.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the sortable field descriptions textarea.
	 */
	public static function render_sortable_field_descriptions_field(): void {
		$descs = self::get_setting( 'sortable_field_descriptions', array() );
		$lines = array();
		if ( is_array( $descs ) ) {
			foreach ( $descs as $field => $desc ) {
				$lines[] = $field . '|' . $desc;
			}
		}
		$value = implode( "\n", $lines );
		?>
		<textarea name="scolta_settings[sortable_field_descriptions]" rows="4" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'One entry per line: field_name|Human-readable description. e.g., word_count|Article length in words. Descriptions help the AI map natural language to the correct field.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the filter fields list.
	 */
	public static function render_filter_fields_field(): void {
		$fields = self::get_setting( 'filter_fields', array() );
		$value  = implode( ', ', is_array( $fields ) ? $fields : array() );
		?>
		<input type="text" name="scolta_settings[filter_fields]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Comma-separated filter dimension names matching data-pagefind-filter attributes. e.g., topic, era, region. Leave empty to disable filter-intent detection.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the filter field descriptions textarea.
	 */
	public static function render_filter_field_descriptions_field(): void {
		$descs = self::get_setting( 'filter_field_descriptions', array() );
		$lines = array();
		if ( is_array( $descs ) ) {
			foreach ( $descs as $field => $desc ) {
				$lines[] = $field . '|' . $desc;
			}
		}
		$value = implode( "\n", $lines );
		?>
		<textarea name="scolta_settings[filter_field_descriptions]" rows="4" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'One entry per line: filter_name|Human-readable description with valid values. e.g., topic|Subject area. Values: Science, History, Biography. Helps the AI map user language to filter values.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the indexer pipeline select field.
	 */
	public static function render_indexer_field(): void {
		$value = self::get_setting( 'indexer', 'auto' );
		?>
		<select name="scolta_settings[indexer]" id="scolta_indexer">
			<option value="auto" <?php selected( $value, 'auto' ); ?>><?php esc_html_e( 'Auto (PHP indexer — recommended, works on all hosts)', 'scolta-ai-search' ); ?></option>
			<option value="php" <?php selected( $value, 'php' ); ?>><?php esc_html_e( 'PHP (pure-PHP, no binary needed)', 'scolta-ai-search' ); ?></option>
			<option value="binary" <?php selected( $value, 'binary' ); ?>><?php esc_html_e( 'Binary (requires Pagefind CLI)', 'scolta-ai-search' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Auto uses the PHP indexer, which works on all hosting environments and supports fast incremental re-indexing. Use Binary only if you need the Pagefind CLI explicitly.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the memory budget profile field.
	 */
	public static function render_memory_budget_field(): void {
		$profile    = self::get_setting( 'memory_budget_profile', 'conservative' );
		$limit_text = \Tag1\Scolta\Index\MemoryBudgetSuggestion::getMemoryLimitText();
		$fit        = \Tag1\Scolta\Index\MemoryBudgetSuggestion::checkProfileFit( $profile );
		?>
		<p class="description" style="margin-bottom:8px">
			<?php esc_html_e( 'How much RAM to use while building the search index. Enter a profile name (conservative, balanced, aggressive) or an exact value like 256M or 1G. It never exceeds the PHP memory limit your host allows.', 'scolta-ai-search' ); ?>
		</p>
		<input
			type="text"
			name="scolta_settings[memory_budget_profile]"
			id="scolta_memory_budget_profile"
			value="<?php echo esc_attr( $profile ); ?>"
			list="scolta_memory_budget_list"
			class="regular-text"
		/>
		<datalist id="scolta_memory_budget_list">
			<option value="conservative"><?php esc_html_e( 'Conservative — peak ≤ 96 MB (default, safe for shared hosting)', 'scolta-ai-search' ); ?></option>
			<option value="balanced"><?php esc_html_e( 'Balanced — ~384 MB (recommended for dedicated VMs)', 'scolta-ai-search' ); ?></option>
			<option value="aggressive"><?php esc_html_e( 'Aggressive — ~1 GB (high-memory servers only)', 'scolta-ai-search' ); ?></option>
		</datalist>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Detected PHP memory_limit value e.g. "256 MB" */
				esc_html__( 'Your current PHP memory limit is %s. The conservative profile fits within 128 MB and is safe for most shared hosts.', 'scolta-ai-search' ),
				esc_html( $limit_text )
			);
			?>
		</p>
		<?php if ( 'warn' === $fit['status'] ) : ?>
		<p class="description" style="color:#d63638">
			<?php echo esc_html( $fit['warning'] ); ?>
		</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'Can be overridden per-run with --memory-budget on wp scolta build.', 'scolta-ai-search' ); ?>
		</p>
		<?php
	}

	/**
	 * Render chunk size field.
	 *
	 * @since 0.3.2
	 */
	public static function render_chunk_size_field(): void {
		$chunk_size = self::get_setting( 'chunk_size', '' );
		?>
		<input
			type="number"
			name="scolta_settings[chunk_size]"
			id="scolta_chunk_size"
			value="<?php echo esc_attr( (string) $chunk_size ); ?>"
			min="1"
			step="1"
			class="small-text"
			placeholder="<?php esc_attr_e( 'profile default', 'scolta-ai-search' ); ?>"
		/>
		<p class="description">
			<?php esc_html_e( 'Pages indexed per chunk during a PHP build. Leave blank to use the memory budget profile default (50 for conservative, 200 for balanced, 500 for aggressive). Lower values reduce peak RSS; higher values reduce merge overhead on large corpora.', 'scolta-ai-search' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Can be overridden per-run with --chunk-size on wp scolta build.', 'scolta-ai-search' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the Pagefind binary path field.
	 */
	public static function render_pagefind_binary_field(): void {
		$value = self::get_setting( 'pagefind_binary', 'pagefind' );
		?>
		<input type="text" name="scolta_settings[pagefind_binary]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php printf( /* translators: %s: WP-CLI command */ esc_html__( 'Path to the Pagefind binary. Run "%s" to download it.', 'scolta-ai-search' ), 'wp scolta download-pagefind' ); ?></p>
		<?php
	}

	/**
	 * Render the build directory field.
	 */
	public static function render_build_dir_field(): void {
		$value = self::get_setting( 'build_dir', wp_upload_dir()['basedir'] . '/scolta/build' );
		?>
		<input type="text" name="scolta_settings[build_dir]" value="<?php echo esc_attr( $value ); ?>" class="large-text" />
		<p class="description"><?php esc_html_e( 'Where exported HTML files are written during index builds. Defaults to wp-content/uploads/scolta/build, which works on managed hosts. If your host allows it, can be outside the web root for better security.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the output directory field.
	 */
	public static function render_output_dir_field(): void {
		$value = self::get_setting( 'output_dir', scolta_default_output_dir() );
		?>
		<input type="text" name="scolta_settings[output_dir]" value="<?php echo esc_attr( $value ); ?>" class="large-text" />
		<p class="description"><?php esc_html_e( 'Parent directory for the Pagefind search index. Must be web-accessible. The PHP indexer writes the index to a pagefind/ subdirectory here. Defaults to wp-content/uploads/scolta.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the auto-rebuild enable checkbox.
	 */
	public static function render_auto_rebuild_field(): void {
		$value = self::get_setting( 'auto_rebuild', true );
		?>
		<label>
			<input type="checkbox" name="scolta_settings[auto_rebuild]" value="1" <?php checked( $value ); ?> />
			<?php esc_html_e( 'Automatically rebuild the Pagefind index when content is exported via WP-CLI', 'scolta-ai-search' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the auto-rebuild delay field.
	 *
	 * @since 0.2.0
	 */
	public static function render_auto_rebuild_delay_field(): void {
		$delay = (int) self::get_setting( 'auto_rebuild_delay', 300 );
		echo '<input type="number" name="scolta_settings[auto_rebuild_delay]"'
			. ' value="' . esc_attr( (string) $delay ) . '" min="60" max="3600" step="1" />';
		echo '<p class="description">' . esc_html__( 'Seconds to wait after the last content change before rebuilding the index. Minimum 60. Default 300 (5 minutes). Higher values batch more changes together.', 'scolta-ai-search' ) . '</p>';
	}

	// -- Scoring fields --

	/**
	 * Render the title match boost field.
	 */
	public static function render_title_boost_field(): void {
		$value = self::get_setting( 'title_match_boost', 2.0 );
		?>
		<input type="number" name="scolta_settings[title_match_boost]" value="<?php echo esc_attr( $value ); ?>" min="0" max="10" step="0.1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Bonus for search terms in the title. Default: 2.0', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the all-terms-in-title bonus field.
	 */
	public static function render_title_all_terms_field(): void {
		$value = self::get_setting( 'title_all_terms_multiplier', 1.5 );
		?>
		<input type="number" name="scolta_settings[title_all_terms_multiplier]" value="<?php echo esc_attr( $value ); ?>" min="0" max="10" step="0.1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Extra boost when ALL search terms appear in the title. Default: 1.5', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the content match boost field.
	 */
	public static function render_content_boost_field(): void {
		$value = self::get_setting( 'content_match_boost', 0.4 );
		?>
		<input type="number" name="scolta_settings[content_match_boost]" value="<?php echo esc_attr( $value ); ?>" min="0" max="10" step="0.1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Bonus for search terms in the body content. Default: 0.4', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the recency boost field.
	 */
	public static function render_recency_boost_field(): void {
		$value = self::get_setting( 'recency_boost_max', 0.25 );
		?>
		<input type="number" name="scolta_settings[recency_boost_max]" value="<?php echo esc_attr( $value ); ?>" min="0" max="5" step="0.1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum boost for recent content. Default: 0.25', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the recency half-life field.
	 */
	public static function render_recency_halflife_field(): void {
		$value = self::get_setting( 'recency_half_life_days', 365 );
		?>
		<input type="number" name="scolta_settings[recency_half_life_days]" value="<?php echo esc_attr( $value ); ?>" min="1" max="3650" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Days until the recency boost decays to half. Default: 365', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the recency penalty days field.
	 */
	public static function render_recency_penalty_days_field(): void {
		$value = self::get_setting( 'recency_penalty_after_days', 1825 );
		?>
		<input type="number" name="scolta_settings[recency_penalty_after_days]" value="<?php echo esc_attr( $value ); ?>" min="0" max="7300" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Content older than this gets a negative adjustment. Default: 1825 (5 years)', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the recency maximum penalty field.
	 */
	public static function render_recency_max_penalty_field(): void {
		$value = self::get_setting( 'recency_max_penalty', 0.3 );
		?>
		<input type="number" name="scolta_settings[recency_max_penalty]" value="<?php echo esc_attr( $value ); ?>" min="0" max="1" step="0.1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum penalty for very old content. Default: 0.3', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the expanded-term weight field.
	 */
	public static function render_expand_weight_field(): void {
		$value = self::get_setting( 'expand_primary_weight', 0.5 );
		?>
		<input type="number" name="scolta_settings[expand_primary_weight]" value="<?php echo esc_attr( $value ); ?>" min="0" max="1" step="0.05" class="small-text" />
		<p class="description"><?php esc_html_e( 'Weight for the primary expanded term (subsequent terms decay). Default: 0.5', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the sub-word frequency guard threshold field.
	 */
	public static function render_subword_freq_field(): void {
		$value = self::get_setting( 'expand_subword_max_frequency', 0.05 );
		?>
		<input type="number" name="scolta_settings[expand_subword_max_frequency]" value="<?php echo esc_attr( $value ); ?>" min="0" max="1" step="0.01" class="small-text" />
		<p class="description"><?php esc_html_e( 'Advanced: how aggressively multi-word searches broaden. Higher returns more results but can pull in loosely-related matches; lower keeps results tight. Most sites should pick a Site Type preset above instead of changing this by hand. Default: 0.05 (the Recipe & Content Catalog preset raises it to 0.10).', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the index language select field.
	 */
	public static function render_language_field(): void {
		$value     = self::get_setting( 'language', 'en' );
		$languages = array(
			'ar' => 'Arabic (ar)',
			'ca' => 'Catalan (ca)',
			'da' => 'Danish (da)',
			'de' => 'German (de)',
			'el' => 'Greek (el)',
			'en' => 'English (en)',
			'es' => 'Spanish (es)',
			'et' => 'Estonian (et)',
			'eu' => 'Basque (eu)',
			'fi' => 'Finnish (fi)',
			'fr' => 'French (fr)',
			'ga' => 'Irish (ga)',
			'hi' => 'Hindi (hi)',
			'hu' => 'Hungarian (hu)',
			'hy' => 'Armenian (hy)',
			'id' => 'Indonesian (id)',
			'it' => 'Italian (it)',
			'lt' => 'Lithuanian (lt)',
			'ne' => 'Nepali (ne)',
			'nl' => 'Dutch (nl)',
			'no' => 'Norwegian (no)',
			'pl' => 'Polish (pl)',
			'pt' => 'Portuguese (pt)',
			'ro' => 'Romanian (ro)',
			'ru' => 'Russian (ru)',
			'sr' => 'Serbian (sr)',
			'sv' => 'Swedish (sv)',
			'ta' => 'Tamil (ta)',
			'tr' => 'Turkish (tr)',
			'yi' => 'Yiddish (yi)',
		);
		echo '<select name="scolta_settings[language]" id="scolta_language">';
		foreach ( $languages as $code => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $value, $code, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Language used for stop word filtering during scoring. Choose the primary language of your site content. Default: en', 'scolta-ai-search' ) . '</p>';
	}

	/**
	 * Render the custom stop words field.
	 */
	public static function render_custom_stop_words_field(): void {
		$value = self::get_setting( 'custom_stop_words', array() );
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		$display = implode( ', ', $value );
		?>
		<input type="text" name="scolta_settings[custom_stop_words]" value="<?php echo esc_attr( $display ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Comma-separated extra stop words to exclude from scoring, beyond the language built-in list. e.g. drupal, cms, site', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the sub-word guard denylist field.
	 *
	 * Guard-only veto list: words here are never auto-exempted from the sub-word
	 * frequency guard even when the user types them, so a typed-but-generic word
	 * cannot re-flood results. Unlike custom stop words, listed words stay
	 * searchable and scorable.
	 *
	 * @return void
	 */
	public static function render_expand_subword_deny_list_field(): void {
		$value = self::get_setting( 'expand_subword_deny_list', array() );
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		$display = implode( ', ', $value );
		?>
		<input type="text" name="scolta_settings[expand_subword_deny_list]" value="<?php echo esc_attr( $display ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Comma-separated words that are never auto-exempted from the sub-word frequency guard, even when typed (e.g. a generic word like "hot" on a recipe site). Unlike custom stop words, these stay searchable and scorable. Leave empty unless a typed common word floods results.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the expansion combine mode field.
	 *
	 * Controls how a multi-term query expansion's per-sub-query result sets are
	 * combined into the AI-summary candidate set. "relevance_union" (default)
	 * keeps the historical behavior; "round_robin" deals the top few from each
	 * sub-query so the summarizer sees breadth across distinct sub-topics
	 * (scolta-php#170). The visible result list stays relevance-sorted either way.
	 *
	 * @return void
	 */
	public static function render_expansion_combine_mode_field(): void {
		$value = self::get_setting( 'expansion_combine_mode', 'relevance_union' );
		?>
		<select name="scolta_settings[expansion_combine_mode]" id="scolta_expansion_combine_mode">
			<option value="relevance_union" <?php selected( $value, 'relevance_union' ); ?>><?php esc_html_e( 'Relevance union (default)', 'scolta-ai-search' ); ?></option>
			<option value="round_robin" <?php selected( $value, 'round_robin' ); ?>><?php esc_html_e( 'Round-robin (breadth across sub-queries)', 'scolta-ai-search' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'How a multi-term query expansion combines its per-sub-query results into the AI-summary candidate set. Relevance union keeps the historical behavior. Round-robin deals the top few from each sub-query so the summary sees breadth across distinct sub-topics. The visible result list stays relevance-sorted either way. Default: Relevance union.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the recency decay strategy select field.
	 */
	public static function render_recency_strategy_field(): void {
		$value = self::get_setting( 'recency_strategy', 'exponential' );
		?>
		<select name="scolta_settings[recency_strategy]" id="scolta_recency_strategy">
			<option value="exponential" <?php selected( $value, 'exponential' ); ?>><?php esc_html_e( 'Exponential (default)', 'scolta-ai-search' ); ?></option>
			<option value="linear" <?php selected( $value, 'linear' ); ?>><?php esc_html_e( 'Linear', 'scolta-ai-search' ); ?></option>
			<option value="step" <?php selected( $value, 'step' ); ?>><?php esc_html_e( 'Step', 'scolta-ai-search' ); ?></option>
			<option value="none" <?php selected( $value, 'none' ); ?>><?php esc_html_e( 'None (disable recency scoring)', 'scolta-ai-search' ); ?></option>
			<option value="custom" <?php selected( $value, 'custom' ); ?>><?php esc_html_e( 'Custom (piecewise-linear curve)', 'scolta-ai-search' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Decay function for recency boost. Custom uses the control points in the field below.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the custom recency curve JSON field.
	 */
	public static function render_recency_curve_field(): void {
		$raw = self::get_setting( 'recency_curve', array() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- round-trips the validated numeric curve into the form field; output is identical to wp_json_encode() for this data.
		$display = ! empty( $raw ) ? json_encode( $raw ) : '';
		?>
		<input type="text" name="scolta_settings[recency_curve]" value="<?php echo esc_attr( $display ); ?>" class="large-text" />
		<p class="description"><?php esc_html_e( 'JSON array of [days, boost] control points for the custom strategy. e.g. [[0, 1.0], [180, 0.5], [365, 0.0]]. Only used when strategy is "custom".', 'scolta-ai-search' ); ?></p>
		<?php
	}

	// -- Display fields --

	/**
	 * Render the excerpt length field.
	 */
	public static function render_excerpt_length_field(): void {
		$value = self::get_setting( 'excerpt_length', 300 );
		?>
		<input type="number" name="scolta_settings[excerpt_length]" value="<?php echo esc_attr( $value ); ?>" min="50" max="1000" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Characters shown in result excerpts. Default: 300', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the results-per-page field.
	 */
	public static function render_results_per_page_field(): void {
		$value = self::get_setting( 'results_per_page', 10 );
		?>
		<input type="number" name="scolta_settings[results_per_page]" value="<?php echo esc_attr( $value ); ?>" min="1" max="100" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Results shown before "show more". Default: 10', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the maximum Pagefind results field.
	 */
	public static function render_max_pagefind_results_field(): void {
		$value = self::get_setting( 'max_pagefind_results', 50 );
		?>
		<input type="number" name="scolta_settings[max_pagefind_results]" value="<?php echo esc_attr( $value ); ?>" min="10" max="500" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum results fetched from Pagefind before scoring. Default: 50', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the AI summary top-N results field.
	 */
	public static function render_ai_summary_top_n_field(): void {
		$value = self::get_setting( 'ai_summary_top_n', 10 );
		?>
		<input type="number" name="scolta_settings[ai_summary_top_n]" value="<?php echo esc_attr( $value ); ?>" min="1" max="20" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Number of top results sent to AI for summarization. Default: 10', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the AI summary max excerpt characters field.
	 */
	public static function render_ai_summary_max_chars_field(): void {
		$value = self::get_setting( 'ai_summary_max_chars', 4000 );
		?>
		<input type="number" name="scolta_settings[ai_summary_max_chars]" value="<?php echo esc_attr( $value ); ?>" min="500" max="10000" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum characters per result excerpt sent to AI. Default: 4000', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Render the "Powered by Scolta" attribution checkbox.
	 */
	public static function render_show_attribution_field(): void {
		$value = self::get_setting( 'show_attribution', false );
		?>
		<label>
			<input type="checkbox" name="scolta_settings[show_attribution]" value="1" <?php checked( $value ); ?> />
			<?php esc_html_e( 'Show "Powered by Scolta" on the search page', 'scolta-ai-search' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Off by default. Enable only with explicit site administrator consent.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	// -- Cache field --

	/**
	 * Render the AI response cache TTL field.
	 */
	public static function render_cache_ttl_field(): void {
		$value = self::get_setting( 'cache_ttl', 2592000 );
		?>
		<input type="number" name="scolta_settings[cache_ttl]" value="<?php echo esc_attr( $value ); ?>" min="0" max="7776000" step="1" class="regular-text" />
		<p class="description">
		<?php
			esc_html_e( 'Seconds. 0 = disabled. Common values: 86400 (1 day), 604800 (7 days), 2592000 (30 days, default).', 'scolta-ai-search' );
		?>
		</p>
		<?php
	}

	// -- Prompt override fields --

	/**
	 * Render the query expansion prompt override field.
	 */
	public static function render_prompt_expand_field(): void {
		$value      = self::get_effective_prompt( 'prompt_expand_query', \Tag1\Scolta\Prompt\DefaultPrompts::EXPAND_QUERY );
		$is_default = empty( self::get_setting( 'prompt_expand_query', '' ) );
		self::render_prompt_field(
			'prompt_expand_query',
			$value,
			$is_default,
			__( 'Edit the query expansion system prompt. Clear the field and save to reset to the default.', 'scolta-ai-search' )
		);
	}

	/**
	 * Render the summarization prompt override field.
	 */
	public static function render_prompt_summarize_field(): void {
		$value      = self::get_effective_prompt( 'prompt_summarize', \Tag1\Scolta\Prompt\DefaultPrompts::SUMMARIZE );
		$is_default = empty( self::get_setting( 'prompt_summarize', '' ) );
		self::render_prompt_field(
			'prompt_summarize',
			$value,
			$is_default,
			__( 'Edit the summarization system prompt. Clear the field and save to reset to the default.', 'scolta-ai-search' )
		);
	}

	/**
	 * Render the follow-up prompt override field.
	 */
	public static function render_prompt_followup_field(): void {
		$value      = self::get_effective_prompt( 'prompt_follow_up', \Tag1\Scolta\Prompt\DefaultPrompts::FOLLOW_UP );
		$is_default = empty( self::get_setting( 'prompt_follow_up', '' ) );
		self::render_prompt_field(
			'prompt_follow_up',
			$value,
			$is_default,
			__( 'Edit the follow-up system prompt. Clear the field and save to reset to the default.', 'scolta-ai-search' )
		);
	}

	/**
	 * Render a prompt textarea with reset button.
	 *
	 * @param string $key     Settings key.
	 * @param string $value   Current effective prompt text (custom or default).
	 * @param bool   $is_default Whether the current value is the built-in default.
	 * @param string $description Help text.
	 */
	private static function render_prompt_field( string $key, string $value, bool $is_default, string $description ): void {
		$default_text = self::get_default_prompt_template( $key );
		$textarea_id  = 'scolta-prompt-' . $key;
		$badge        = $is_default
			? '<span class="scolta-badge" style="color:#888;font-style:italic;margin-left:0.5em;">' . esc_html__( '(default)', 'scolta-ai-search' ) . '</span>'
			: '<span class="scolta-badge" style="color:#0073aa;font-weight:600;margin-left:0.5em;">' . esc_html__( '(customized)', 'scolta-ai-search' ) . '</span>';
		?>
		<div>
			<?php echo wp_kses_post( $badge ); ?>
			<?php if ( ! $is_default ) : ?>
				<button type="button" class="button-link scolta-prompt-reset" style="margin-left:0.5em;color:#b32d2e;" data-textarea-id="<?php echo esc_attr( $textarea_id ); ?>"><?php esc_html_e( 'Reset to default', 'scolta-ai-search' ); ?></button>
			<?php endif; ?>
		</div>
		<textarea id="<?php echo esc_attr( $textarea_id ); ?>" name="scolta_settings[<?php echo esc_attr( $key ); ?>]" rows="8" class="large-text" data-default-prompt="<?php echo esc_attr( $default_text ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php echo esc_html( $description ); ?> <?php esc_html_e( 'Supports {SITE_NAME} and {SITE_DESCRIPTION} placeholders.', 'scolta-ai-search' ); ?></p>
		<?php
	}

	/**
	 * Get the effective prompt: saved custom value, or the built-in default.
	 *
	 * @param string $setting_key The settings key (e.g., 'prompt_expand_query').
	 * @param string $template_name The DefaultPrompts constant.
	 * @return string The prompt text to display.
	 */
	private static function get_effective_prompt( string $setting_key, string $template_name ): string {
		$saved = self::get_setting( $setting_key, '' );
		if ( ! empty( $saved ) ) {
			return $saved;
		}
		return self::get_default_prompt_template( $template_name );
	}

	/**
	 * Get the default prompt template text.
	 *
	 * Returns the raw template with {SITE_NAME} and {SITE_DESCRIPTION}
	 * placeholders intact.
	 *
	 * @param string $name Prompt template name constant or settings key.
	 * @return string The template text.
	 */
	private static function get_default_prompt_template( string $name ): string {
		// Map settings keys to template names.
		$map           = array(
			'prompt_expand_query' => \Tag1\Scolta\Prompt\DefaultPrompts::EXPAND_QUERY,
			'prompt_summarize'    => \Tag1\Scolta\Prompt\DefaultPrompts::SUMMARIZE,
			'prompt_follow_up'    => \Tag1\Scolta\Prompt\DefaultPrompts::FOLLOW_UP,
		);
		$template_name = $map[ $name ] ?? $name;

		return \Tag1\Scolta\Prompt\DefaultPrompts::getTemplate( $template_name );
	}

	// -----------------------------------------------------------------
	// Sanitization
	// -----------------------------------------------------------------

	/**
	 * Sanitize all settings before WordPress saves them.
	 *
	 * API key is NEVER saved to the database from this form.
	 *
	 * @param array $input Raw form values from the settings page.
	 */
	public static function sanitize_settings( array $input ): array {
		$clean    = array();
		$existing = get_option( 'scolta_settings', array() );

		// Site Type preset.
		$valid_presets   = array_keys( \Tag1\Scolta\Config\ScoltaConfig::getPresets() );
		$raw_preset      = sanitize_key( $input['preset'] ?? 'none' );
		$clean['preset'] = in_array( $raw_preset, $valid_presets, true ) ? $raw_preset : 'none';

		// When the preset changes, inject preset values into $input so the individual
		// sanitizers below pick them up. If a user also manually changed a field in the
		// same save, their value was already in $input from the form submission. Since we
		// only do this on preset change, the previous save's individual overrides are
		// preserved on subsequent saves with the same preset.
		$previous_preset = $existing['preset'] ?? 'none';
		if ( $clean['preset'] !== 'none' && $clean['preset'] !== $previous_preset ) {
			foreach ( \Tag1\Scolta\Config\ScoltaConfig::getPresetValues( $clean['preset'] ) as $key => $value ) {
				$input[ $key ] = $value;
			}
		}

		// AI provider.
		$clean['ai_provider'] = in_array( $input['ai_provider'] ?? '', array( 'anthropic', 'openai', 'amazee' ), true )
			? $input['ai_provider']
			: 'anthropic';

		// Model.
		$clean['ai_model']           = sanitize_text_field( $input['ai_model'] ?? 'claude-sonnet-4-5-20250929' );
		$clean['ai_expansion_model'] = sanitize_text_field( $input['ai_expansion_model'] ?? '' );

		// Base URL must be an http(s) URL — it is the endpoint AI requests
		// are sent to, so a non-URL or non-http scheme is dropped entirely.
		$raw_base_url         = esc_url_raw( trim( (string) ( $input['ai_base_url'] ?? '' ) ), array( 'http', 'https' ) );
		$clean['ai_base_url'] = $raw_base_url;

		// AI feature toggles.
		$clean['ai_expand_query'] = ! empty( $input['ai_expand_query'] );
		$clean['ai_summarize']    = ! empty( $input['ai_summarize'] );
		$clean['max_follow_ups']  = max( 0, min( 10, (int) ( $input['max_follow_ups'] ?? 3 ) ) );

		// AI languages.
		$languages_raw = $input['ai_languages'] ?? 'en';
		if ( is_array( $languages_raw ) ) {
			$languages_raw = implode( ',', $languages_raw );
		}
		$languages             = array_values(
			array_filter(
				array_map(
					fn( $lang ) => sanitize_text_field( trim( $lang ) ),
					explode( ',', $languages_raw )
				)
			)
		);
		$clean['ai_languages'] = ! empty( $languages ) ? $languages : array( 'en' );

		// Content settings.
		$post_types                = $input['post_types'] ?? array( 'post', 'page' );
		$clean['post_types']       = array_map( 'sanitize_key', (array) $post_types );
		$clean['site_name']        = sanitize_text_field( $input['site_name'] ?? get_bloginfo( 'name' ) );
		$clean['site_description'] = sanitize_text_field( $input['site_description'] ?? 'website' );

		// Search customization: sortable fields.
		$sortable_raw             = sanitize_text_field( $input['sortable_fields'] ?? '' );
		$clean['sortable_fields'] = array_values(
			array_filter(
				array_map(
					fn( $f ) => sanitize_key( trim( $f ) ),
					explode( ',', $sortable_raw )
				)
			)
		);

		// Sortable field descriptions (field|description per line).
		$clean['sortable_field_descriptions'] = self::parse_key_value_lines(
			$input['sortable_field_descriptions'] ?? ''
		);

		// Filter fields.
		$filter_raw             = sanitize_text_field( $input['filter_fields'] ?? '' );
		$clean['filter_fields'] = array_values(
			array_filter(
				array_map(
					fn( $f ) => sanitize_key( trim( $f ) ),
					explode( ',', $filter_raw )
				)
			)
		);

		// Filter field descriptions (filter|description per line).
		$clean['filter_field_descriptions'] = self::parse_key_value_lines(
			$input['filter_field_descriptions'] ?? ''
		);

		// Indexer.
		$clean['indexer'] = in_array( $input['indexer'] ?? '', array( 'auto', 'php', 'binary' ), true )
			? $input['indexer']
			: 'auto';

		// Memory budget — accepts named profiles or raw byte strings (e.g. "256M").
		$raw_budget                     = sanitize_text_field( $input['memory_budget_profile'] ?? '' );
		$clean['memory_budget_profile'] = self::is_valid_memory_budget( $raw_budget ) ? $raw_budget : 'conservative';

		// Chunk size — empty string means "use profile default".
		$raw_chunk = $input['chunk_size'] ?? '';
		if ( '' === $raw_chunk ) {
			$clean['chunk_size'] = '';
		} else {
			$clean['chunk_size'] = max( 1, (int) $raw_chunk );
		}

		// Pagefind paths.
		$clean['pagefind_binary']    = sanitize_text_field( $input['pagefind_binary'] ?? 'pagefind' );
		$clean['build_dir']          = wp_normalize_path( $input['build_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/build' );
		$clean['output_dir']         = wp_normalize_path( $input['output_dir'] ?? scolta_default_output_dir() );
		$clean['auto_rebuild']       = ! empty( $input['auto_rebuild'] );
		$clean['auto_rebuild_delay'] = max( 60, min( 3600, (int) ( $input['auto_rebuild_delay'] ?? 300 ) ) );

		// Scoring fields.
		$clean['title_match_boost']            = max( 0.0, min( 10.0, (float) ( $input['title_match_boost'] ?? 2.0 ) ) );
		$clean['title_all_terms_multiplier']   = max( 0.0, min( 10.0, (float) ( $input['title_all_terms_multiplier'] ?? 1.5 ) ) );
		$clean['content_match_boost']          = max( 0.0, min( 10.0, (float) ( $input['content_match_boost'] ?? 0.4 ) ) );
		$clean['recency_boost_max']            = max( 0.0, min( 5.0, (float) ( $input['recency_boost_max'] ?? 0.25 ) ) );
		$clean['recency_half_life_days']       = max( 1, min( 3650, (int) ( $input['recency_half_life_days'] ?? 365 ) ) );
		$clean['recency_penalty_after_days']   = max( 0, min( 7300, (int) ( $input['recency_penalty_after_days'] ?? 1825 ) ) );
		$clean['recency_max_penalty']          = max( 0.0, min( 1.0, (float) ( $input['recency_max_penalty'] ?? 0.3 ) ) );
		$clean['expand_primary_weight']        = max( 0.0, min( 1.0, (float) ( $input['expand_primary_weight'] ?? 0.5 ) ) );
		$clean['expand_subword_max_frequency'] = max( 0.0, min( 1.0, (float) ( $input['expand_subword_max_frequency'] ?? 0.05 ) ) );

		$valid_languages   = array( 'ar', 'ca', 'da', 'de', 'el', 'en', 'es', 'et', 'eu', 'fi', 'fr', 'ga', 'hi', 'hu', 'hy', 'id', 'it', 'lt', 'ne', 'nl', 'no', 'pl', 'pt', 'ro', 'ru', 'sr', 'sv', 'ta', 'tr', 'yi' );
		$clean['language'] = in_array( $input['language'] ?? '', $valid_languages, true )
			? $input['language']
			: 'en';

		$stop_words_raw             = $input['custom_stop_words'] ?? '';
		$clean['custom_stop_words'] = array_values(
			array_filter(
				array_map(
					fn( $w ) => sanitize_text_field( trim( $w ) ),
					explode( ',', $stop_words_raw )
				)
			)
		);

		$deny_list_raw                     = $input['expand_subword_deny_list'] ?? '';
		$clean['expand_subword_deny_list'] = array_values(
			array_filter(
				array_map(
					fn( $w ) => strtolower( sanitize_text_field( trim( $w ) ) ),
					explode( ',', $deny_list_raw )
				)
			)
		);

		$clean['expansion_combine_mode'] = in_array( $input['expansion_combine_mode'] ?? '', array( 'relevance_union', 'round_robin' ), true )
			? $input['expansion_combine_mode']
			: 'relevance_union';

		$clean['recency_strategy'] = in_array( $input['recency_strategy'] ?? '', array( 'exponential', 'linear', 'step', 'none', 'custom' ), true )
			? $input['recency_strategy']
			: 'exponential';

		$curve_raw              = $input['recency_curve'] ?? '';
		$clean['recency_curve'] = self::sanitize_recency_curve( is_string( $curve_raw ) ? $curve_raw : '' );

		// Display — all 6 fields.
		$clean['excerpt_length']       = max( 50, min( 1000, (int) ( $input['excerpt_length'] ?? 300 ) ) );
		$clean['results_per_page']     = max( 1, min( 100, (int) ( $input['results_per_page'] ?? 10 ) ) );
		$clean['max_pagefind_results'] = max( 10, min( 500, (int) ( $input['max_pagefind_results'] ?? 50 ) ) );
		$clean['ai_summary_top_n']     = max( 1, min( 20, (int) ( $input['ai_summary_top_n'] ?? 10 ) ) );
		$clean['ai_summary_max_chars'] = max( 500, min( 10000, (int) ( $input['ai_summary_max_chars'] ?? 4000 ) ) );
		$clean['show_attribution']     = ! empty( $input['show_attribution'] );

		// Cache.
		$clean['cache_ttl'] = max( 0, min( 7776000, (int) ( $input['cache_ttl'] ?? 2592000 ) ) );

		// Prompt overrides — store empty string if the value matches the
		// built-in default, so we don't persist a copy of the default text.
		$clean['prompt_expand_query'] = self::sanitize_prompt( $input['prompt_expand_query'] ?? '', 'prompt_expand_query' );
		$clean['prompt_summarize']    = self::sanitize_prompt( $input['prompt_summarize'] ?? '', 'prompt_summarize' );
		$clean['prompt_follow_up']    = self::sanitize_prompt( $input['prompt_follow_up'] ?? '', 'prompt_follow_up' );

		// Preserve internal settings not exposed in the form.
		$clean['search_page_path']    = $existing['search_page_path'] ?? '/scolta-search';
		$clean['pagefind_index_path'] = $existing['pagefind_index_path'] ?? wp_upload_dir()['baseurl'] . '/scolta/pagefind';

		// Preserve legacy API key if it exists (for backward compat until user removes it).
		if ( ! empty( $existing['ai_api_key'] ) ) {
			$clean['ai_api_key'] = $existing['ai_api_key'];
		}

		return $clean;
	}

	/**
	 * Decode and validate a custom recency curve JSON string.
	 *
	 * The expected shape is [[days, boost], ...] — a list of two-element
	 * numeric pairs. Anything else (objects, ragged rows, non-numeric
	 * entries) is rejected wholesale so a malformed value cannot reach
	 * the scoring config.
	 *
	 * @param string $raw Raw JSON from the settings form.
	 * @return array<int, array{0: int, 1: float}> Validated curve, or empty array.
	 */
	private static function sanitize_recency_curve( string $raw ): array {
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$curve = array();
		foreach ( $decoded as $pair ) {
			if ( ! is_array( $pair ) || array_keys( $pair ) !== array( 0, 1 )
				|| ! is_numeric( $pair[0] ) || ! is_numeric( $pair[1] ) ) {
				return array();
			}
			$curve[] = array( (int) $pair[0], (float) $pair[1] );
		}
		return $curve;
	}

	/**
	 * Check whether a memory budget string is a valid profile name or byte value.
	 *
	 * Accepts named profiles (conservative, balanced, aggressive) and raw byte
	 * strings understood by MemoryBudget::fromString(), such as "256M" or "1G".
	 *
	 * @param string $value Raw submitted value.
	 * @return bool True if the value is usable.
	 */
	private static function is_valid_memory_budget( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}
		if ( in_array( $value, array( 'conservative', 'balanced', 'aggressive' ), true ) ) {
			return true;
		}
		// Accept byte strings: digits followed by optional K/M/G suffix.
		return (bool) preg_match( '/^\d+[KkMmGg]?$/', $value );
	}

	/**
	 * Sanitize a prompt field value.
	 *
	 * If the submitted text matches the built-in default, store empty
	 * string so the prompt automatically picks up future default changes.
	 *
	 * The default comparison MUST happen before length truncation: the
	 * defaults live in scolta-php and may exceed the storage cap, and a
	 * truncated submission would otherwise never match its own default
	 * and get stored as a stale "custom" prompt. The default is passed
	 * through the same sanitizer so the comparison survives anything
	 * sanitize_textarea_field() strips from the round-tripped form value.
	 *
	 * @param string $value     The submitted prompt text.
	 * @param string $key       The settings key (e.g., 'prompt_expand_query').
	 * @return string Sanitized value, or empty if it matches the default.
	 */
	private static function sanitize_prompt( string $value, string $key ): string {
		$sanitized = sanitize_textarea_field( $value );
		$default   = self::get_default_prompt_template( $key );
		if ( $default !== '' && trim( $sanitized ) === trim( sanitize_textarea_field( $default ) ) ) {
			return '';
		}
		return mb_substr( $sanitized, 0, 5000 );
	}

	/**
	 * Parse a textarea of "key|value" lines into an associative array.
	 *
	 * Lines without a pipe separator are silently skipped.
	 * Keys are sanitized with sanitize_key(); values with sanitize_text_field().
	 *
	 * @param string $raw Raw textarea value.
	 * @return array<string, string> Parsed key → value map.
	 */
	private static function parse_key_value_lines( string $raw ): array {
		$result = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || ! str_contains( $line, '|' ) ) {
				continue;
			}
			[ $key, $val ] = explode( '|', $line, 2 );
			$key           = sanitize_key( trim( $key ) );
			$val           = sanitize_text_field( trim( $val ) );
			if ( '' !== $key ) {
				$result[ $key ] = $val;
			}
		}
		return $result;
	}

	// -----------------------------------------------------------------
	// AJAX: remove legacy DB key
	// -----------------------------------------------------------------

	/**
	 * AJAX handler that removes the legacy API key from the database.
	 */
	public static function ajax_remove_db_key(): void {
		check_ajax_referer( 'scolta_remove_db_key' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'scolta-ai-search' ) );
		}

		$settings = get_option( 'scolta_settings', array() );
		unset( $settings['ai_api_key'] );
		update_option( 'scolta_settings', $settings );

		wp_send_json_success( __( 'API key removed from database', 'scolta-ai-search' ) );
	}

	/**
	 * AJAX handler for testing the LLM connection.
	 *
	 * Sends a minimal one-token prompt to the configured provider and
	 * returns timing and provider info on success, or an error message.
	 */
	public static function ajax_scolta_test_connection(): void {
		check_ajax_referer( 'scolta_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Insufficient permissions.', 'scolta-ai-search' ) ), 403 );
		}

		$api_key = Scolta_Ai_Service::get_api_key();
		if ( empty( $api_key ) && ! class_exists( '\WordPress\AI\Client' ) ) {
			wp_send_json_error( array( 'error' => __( 'No API key configured.', 'scolta-ai-search' ) ) );
		}

		try {
			$service    = Scolta_Ai_Service::from_options();
			$config     = $service->getConfig();
			$start_time = microtime( true );
			$service->message( 'Respond with only the word OK.', 'Test', 5 );
			$elapsed_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

			wp_send_json_success(
				array(
					'provider'      => ucfirst( $config->aiProvider ),
					'model'         => $config->aiModel,
					'response_time' => $elapsed_ms,
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'error' => $e->getMessage() ) );
		}
	}

	// -----------------------------------------------------------------
	// Page renderer
	// -----------------------------------------------------------------

	/**
	 * Render the full Scolta settings page.
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		settings_errors( 'scolta_settings' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php self::render_ai_optin_box(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'scolta_settings_group' );
				do_settings_sections( 'scolta' );
				submit_button( __( 'Save Settings', 'scolta-ai-search' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Quick Start', 'scolta-ai-search' ); ?></h2>
			<ol>
				<li><?php printf( /* translators: %s: WP-CLI command */ esc_html__( 'Build the search index: %s', 'scolta-ai-search' ), '<code>wp scolta build</code>' ); ?></li>
				<li><?php printf( /* translators: %s: shortcode */ esc_html__( 'Add the search UI to any page: %s', 'scolta-ai-search' ), '<code>[scolta_search]</code>' ); ?></li>
				<li><?php printf( /* translators: %s: WP-CLI command */ esc_html__( 'Check status: %s', 'scolta-ai-search' ), '<code>wp scolta status</code>' ); ?></li>
			</ol>

			<?php self::render_status_summary(); ?>
		</div>
		<?php
	}

	/**
	 * Render the Index Status table and "Rebuild Index Now" button.
	 *
	 * Shows tracker state, build/output directory checks, binary
	 * availability, and index health at the bottom of the settings page.
	 */
	private static function render_status_summary(): void {
		$settings        = get_option( 'scolta_settings', array() );
		$build_dir       = $settings['build_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/build';
		$output_dir      = $settings['output_dir'] ?? scolta_default_output_dir();
		$indexer_setting = $settings['indexer'] ?? 'auto';

		$binary_resolver  = new \Tag1\Scolta\Binary\PagefindBinary(
			configuredPath: $settings['pagefind_binary'] ?? null,
			projectDir: SCOLTA_PLUGIN_DIR,
		);
		$binary_status    = $binary_resolver->status();
		$binary_available = $binary_status['available'];

		// PHP pipeline is active for auto and php; only binary uses the binary pipeline.
		$uses_php_pipeline = ( $indexer_setting !== 'binary' );

		echo '<h2>' . esc_html__( 'Index Status', 'scolta-ai-search' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width: 600px;">';

		// Tracker.
		if ( \Scolta_Tracker::table_exists() ) {
			$pending = \Scolta_Tracker::get_pending_count();
			echo '<tr><td>' . esc_html__( 'Pending changes', 'scolta-ai-search' ) . '</td>';
			echo '<td>' . esc_html( (string) $pending ) . '</td></tr>';
		} else {
			echo '<tr><td>' . esc_html__( 'Tracker', 'scolta-ai-search' ) . '</td>';
			echo '<td><span style="color: #d63638;">' . esc_html__( 'Table missing — deactivate and reactivate the plugin', 'scolta-ai-search' ) . '</span></td></tr>';
		}

		// Build directory — the binary pipeline writes intermediate HTML files here.
		// The PHP pipeline writes the index format directly; no HTML staging files are produced.
		if ( ! $uses_php_pipeline ) {
			if ( is_dir( $build_dir ) ) {
				$html_count = \Tag1\Scolta\Export\ContentExporter::countHtmlFiles( $build_dir );
				echo '<tr><td>' . esc_html__( 'Exported HTML files', 'scolta-ai-search' ) . '</td>';
				echo '<td>' . esc_html( (string) $html_count ) . '</td></tr>';
			} else {
				echo '<tr><td>' . esc_html__( 'Build directory', 'scolta-ai-search' ) . '</td>';
				echo '<td>' . esc_html__( 'Not created yet', 'scolta-ai-search' ) . '</td></tr>';
			}
		}

		// Pagefind index — detect subdirectory (PHP pipeline) or flat (binary pipeline).
		if ( file_exists( $output_dir . '/pagefind/pagefind.js' ) ) {
			$index_dir  = $output_dir . '/pagefind';
			$index_file = $index_dir . '/pagefind.js';
		} else {
			$index_dir  = $output_dir;
			$index_file = $output_dir . '/pagefind.js';
		}
		if ( file_exists( $index_file ) ) {
			$mtime          = filemtime( $index_file );
			$glob_result    = glob( $index_dir . '/fragment/*' );
			$fragment_count = count( ! empty( $glob_result ) ? $glob_result : array() );
			echo '<tr><td>' . esc_html__( 'Index fragments', 'scolta-ai-search' ) . '</td>';
			echo '<td>' . esc_html( (string) $fragment_count ) . '</td></tr>';
			echo '<tr><td>' . esc_html__( 'Last built', 'scolta-ai-search' ) . '</td>';
			echo '<td>' . esc_html( $mtime ? wp_date( 'Y-m-d H:i:s', $mtime ) : __( 'Unknown', 'scolta-ai-search' ) ) . '</td></tr>';
		} else {
			echo '<tr><td>' . esc_html__( 'Pagefind index', 'scolta-ai-search' ) . '</td>';
			echo '<td>' . esc_html__( 'Not built yet — run: wp scolta build', 'scolta-ai-search' ) . '</td></tr>';
		}

		// Active indexer.
		if ( 'php' === $indexer_setting ) {
			$active_indexer = __( 'PHP indexer (forced)', 'scolta-ai-search' );
		} elseif ( 'binary' === $indexer_setting ) {
			$active_indexer = $binary_available
				? __( 'Pagefind binary', 'scolta-ai-search' )
				: __( 'Pagefind binary (not found — check binary path)', 'scolta-ai-search' );
		} else {
			// auto: always PHP regardless of binary availability.
			$active_indexer = __( 'PHP indexer (recommended)', 'scolta-ai-search' );
		}
		echo '<tr><td>' . esc_html__( 'Active indexer', 'scolta-ai-search' ) . '</td>';
		echo '<td>' . esc_html( $active_indexer ) . '</td></tr>';

		// AI provider.
		if ( class_exists( '\WordPress\AI\Client' ) ) {
			echo '<tr><td>' . esc_html__( 'AI Provider', 'scolta-ai-search' ) . '</td>';
			echo '<td>' . esc_html__( 'WordPress AI Client SDK (WP 7.0+)', 'scolta-ai-search' ) . '</td></tr>';
		} else {
			$provider = $settings['ai_provider'] ?? 'anthropic';
			$source   = Scolta_Ai_Service::get_api_key_source();
			echo '<tr><td>' . esc_html__( 'AI Provider', 'scolta-ai-search' ) . '</td>';
			echo '<td>' . esc_html( ucfirst( $provider ) );
			if ( $source === 'none' ) {
				echo ' <span style="color: #d63638;">(' . esc_html__( 'no API key', 'scolta-ai-search' ) . ')</span>';
			} elseif ( $source === 'database' ) {
				echo ' <span style="color: #dba617;">(' . esc_html__( 'key in DB — migrate to env var', 'scolta-ai-search' ) . ')</span>';
			}
			echo '</td></tr>';
		}

		echo '</table>';

		// Rebuild Now button.
		echo '<p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		wp_nonce_field( 'scolta_rebuild_now', 'scolta_rebuild_nonce' );
		echo '<input type="hidden" name="action" value="scolta_rebuild_now">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Rebuild Index Now', 'scolta-ai-search' ) . '</button>';
		echo '</form>';
		echo '&nbsp;<span class="description">' . esc_html__( 'Runs a full index rebuild (equivalent to wp scolta build). Large sites may time out — use WP-CLI for those.', 'scolta-ai-search' ) . '</span>';
		echo '</p>';
	}

	/**
	 * Show a persistent notice after a "Rebuild Index Now" form submission.
	 *
	 * Reads from a transient set by handle_rebuild_now() with a 7-day TTL.
	 * The notice persists across page loads until the user explicitly dismisses
	 * it (server-side) or a new rebuild starts (which replaces the notice_id).
	 * Per-user dismissal is tracked in user meta so different admins can each
	 * see and dismiss the notice independently.
	 */
	public static function maybe_show_rebuild_notice(): void {
		$notice = get_transient( 'scolta_rebuild_notice' );
		if ( ! $notice || ! is_array( $notice ) ) {
			return;
		}

		// Per-user dismissal: if this user already dismissed this notice_id, skip.
		$notice_id = $notice['notice_id'] ?? '';
		if ( $notice_id !== '' ) {
			$dismissed = get_user_meta( get_current_user_id(), 'scolta_dismissed_rebuild_notice', true );
			if ( $dismissed === $notice_id ) {
				return;
			}
		}

		// Build the server-side dismiss URL.
		$dismiss_url = '';
		if ( $notice_id !== '' ) {
			$dismiss_url = wp_nonce_url(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- builds an admin-post query arg; the value is a sanitize_key()'d notice id, so urlencode and rawurlencode produce identical output.
				admin_url( 'admin-post.php?action=scolta_dismiss_rebuild_notice&scolta_notice_id=' . urlencode( $notice_id ) ),
				'scolta_dismiss_' . $notice_id
			);
		}

		$dismiss_link = $dismiss_url
			? ' <a href="' . esc_url( $dismiss_url ) . '" style="margin-left:1em">' . esc_html__( 'Dismiss', 'scolta-ai-search' ) . '</a>'
			: '';

		$result = $notice['result'] ?? '';

		if ( $result === 'ok' ) {
			$pages = (int) ( $notice['pages'] ?? 0 );
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html(
				sprintf(
				/* translators: %d: number of pages indexed */
					__( 'Scolta index rebuilt successfully. %d pages indexed.', 'scolta-ai-search' ),
					$pages
				)
			);
			echo wp_kses_post( $dismiss_link );
			echo '</p></div>';
		} elseif ( $result === 'no_content' ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Scolta rebuild: no published content found. Check your post types setting.', 'scolta-ai-search' );
			echo wp_kses_post( $dismiss_link );
			echo '</p></div>';
		} elseif ( $result === 'no_items' ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Scolta rebuild: no items passed the content filter. Your posts may be too short.', 'scolta-ai-search' );
			echo wp_kses_post( $dismiss_link );
			echo '</p></div>';
		} elseif ( $result === 'locked' ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Scolta rebuild: a rebuild is already in progress. Wait for it to finish and try again.', 'scolta-ai-search' );
			echo wp_kses_post( $dismiss_link );
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'Scolta rebuild failed. Try running wp scolta build from the command line for more details.', 'scolta-ai-search' );
			echo wp_kses_post( $dismiss_link );
			echo '</p></div>';
		}
	}

	/**
	 * Handle the admin-post action for dismissing the rebuild notice.
	 *
	 * Sets user meta so maybe_show_rebuild_notice() skips this notice_id
	 * for the current user on all future page loads.
	 */
	public static function handle_dismiss_rebuild_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to dismiss this notice.', 'scolta-ai-search' ), 403 );
		}

		$notice_id = sanitize_key( $_GET['scolta_notice_id'] ?? '' );
		check_admin_referer( 'scolta_dismiss_' . $notice_id );

		update_user_meta( get_current_user_id(), 'scolta_dismissed_rebuild_notice', $notice_id );

		wp_safe_redirect( admin_url( 'options-general.php?page=scolta' ) );
		exit;
	}

	// -----------------------------------------------------------------
	// AI features opt-in (builds with auto-provisioning disabled)
	// -----------------------------------------------------------------

	/**
	 * Whether the AI features opt-in is pending admin consent.
	 *
	 * True only when activation recorded the pending flag (builds with
	 * SCOLTA_AUTO_PROVISION_DEFAULT false, e.g. the WordPress.org
	 * distribution) and no explicit API key has been configured since —
	 * configuring a key is itself the consent act.
	 *
	 * @return bool True when the opt-in notice and control should be offered.
	 */
	public static function ai_optin_pending(): bool {
		return get_option( 'scolta_ai_optin_pending' )
			&& ! scolta_has_explicit_api_key();
	}

	/**
	 * Show the AI features availability notice while the opt-in is pending.
	 *
	 * Rendered on the plugins and Scolta settings screens. The notice only
	 * points to the settings page; the "Enable AI features" control there
	 * carries the full disclosure and the confirmation step.
	 */
	public static function maybe_show_ai_optin_notice(): void {
		if ( ! self::ai_optin_pending() || get_option( 'scolta_ai_optin_notice_dismissed' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_scolta' ), true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=scolta_dismiss_ai_optin_notice' ),
			'scolta_dismiss_ai_optin_notice'
		);

		echo '<div class="notice notice-info"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: URL of the Scolta settings page */
				__( '<strong>Scolta AI Search:</strong> AI features (query expansion and result summaries) are available — <a href="%s">enable them in Scolta settings</a>. Scolta makes no remote requests until you enable them.', 'scolta-ai-search' ),
				esc_url( admin_url( 'options-general.php?page=scolta' ) )
			)
		);
		echo wp_kses_post( ' <a href="' . esc_url( $dismiss_url ) . '" style="margin-left:1em">' . esc_html__( 'Dismiss', 'scolta-ai-search' ) . '</a>' );
		echo '</p></div>';
	}

	/**
	 * Show the result notice after the "Enable AI features" action ran.
	 */
	public static function maybe_show_ai_optin_result_notice(): void {
		$result = get_transient( 'scolta_ai_optin_result' );
		if ( ! $result ) {
			return;
		}
		delete_transient( 'scolta_ai_optin_result' );

		if ( 'ok' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Scolta AI features enabled. Query expansion and result summaries are now active.', 'scolta-ai-search' );
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'Scolta could not provision the Amazee.ai trial — AI features remain off. Check your site’s outbound connectivity and try again, or configure your own API key.', 'scolta-ai-search' );
			echo '</p></div>';
		}
	}

	/**
	 * Render the explicit "Enable AI features" opt-in control.
	 *
	 * Shown above the settings form while the opt-in recorded at activation
	 * is pending. States exactly what enabling does — provisioning a free
	 * Amazee.ai trial sends the site admin email address to api.amazee.ai —
	 * with links to Amazee.ai's terms and privacy policy.
	 */
	private static function render_ai_optin_box(): void {
		if ( ! self::ai_optin_pending() ) {
			return;
		}
		?>
		<div class="notice notice-info inline" style="margin: 1em 0 1.5em; padding: 0.5em 1em 1em;">
			<h2><?php esc_html_e( 'Enable AI features?', 'scolta-ai-search' ); ?></h2>
			<p><?php esc_html_e( 'AI query expansion and result summaries are currently OFF, and Scolta makes no remote requests of any kind.', 'scolta-ai-search' ); ?></p>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: Amazee.ai terms of service URL, 2: Amazee.ai privacy policy URL */
						__( 'Enabling AI features provisions a free Amazee.ai trial: your site admin email address will be sent to amazee.ai (api.amazee.ai), and AI search queries plus result excerpts will be processed by the Amazee.ai gateway. See the Amazee.ai <a href="%1$s">Terms of Service</a> and <a href="%2$s">Privacy Policy</a>.', 'scolta-ai-search' ),
						'https://amazee.ai/terms',
						'https://amazee.ai/privacy'
					)
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: wp-config.php constant example */
						__( 'Prefer your own provider? Configure an API key instead (e.g. %s in wp-config.php) and turn on the AI settings below — no trial is provisioned and nothing is sent to amazee.ai when a key is present.', 'scolta-ai-search' ),
						'<code>SCOLTA_API_KEY</code>'
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scolta_enable_ai" />
				<?php wp_nonce_field( 'scolta_enable_ai' ); ?>
				<?php submit_button( __( 'Enable AI features', 'scolta-ai-search' ), 'primary', 'scolta-enable-ai', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the explicit "Enable AI features" opt-in action.
	 *
	 * Provisions the Amazee.ai trial (unless an explicit API key or stored
	 * credentials already provide AI access), then enables the AI feature
	 * settings and clears the pending flag. On provisioning failure the AI
	 * features stay off, the pending flag is kept, and an error notice is
	 * queued for the next admin page load.
	 */
	public static function handle_enable_ai(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to enable AI features.', 'scolta-ai-search' ), 403 );
		}
		check_admin_referer( 'scolta_enable_ai' );

		$success = scolta_has_explicit_api_key();
		if ( ! $success ) {
			$storage = new Scolta_Amazee_Config_Storage();
			$success = $storage->load() !== null || scolta_auto_provision_amazee();
		}

		if ( $success ) {
			$settings                    = get_option( 'scolta_settings', array() );
			$settings['ai_expand_query'] = true;
			$settings['ai_summarize']    = true;
			update_option( 'scolta_settings', $settings );
			delete_option( 'scolta_ai_optin_pending' );
			delete_option( 'scolta_ai_optin_notice_dismissed' );
			set_transient( 'scolta_ai_optin_result', 'ok', 5 * MINUTE_IN_SECONDS );
		} else {
			set_transient( 'scolta_ai_optin_result', 'error', 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=scolta' ) );
		exit;
	}

	/**
	 * Handle the admin-post action for dismissing the AI opt-in notice.
	 *
	 * Site-wide dismissal: the notice stays gone, but the "Enable AI
	 * features" control remains available on the settings page.
	 */
	public static function handle_dismiss_ai_optin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to dismiss this notice.', 'scolta-ai-search' ), 403 );
		}
		check_admin_referer( 'scolta_dismiss_ai_optin_notice' );

		update_option( 'scolta_ai_optin_notice_dismissed', true, false );

		wp_safe_redirect( admin_url( 'options-general.php?page=scolta' ) );
		exit;
	}

	/**
	 * Show a one-time notice when Amazee.ai auto-selected AI models after provisioning.
	 *
	 * Shown once, then the transient is deleted so it doesn't repeat.
	 */
	public static function maybe_show_amazee_models_notice(): void {
		$notice = get_transient( 'scolta_amazee_models_notice' );
		if ( ! $notice || ! is_array( $notice ) ) {
			return;
		}
		delete_transient( 'scolta_amazee_models_notice' );

		$model = sanitize_text_field( $notice['ai_model'] ?? '' );
		if ( $model === '' ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: Claude model name */
				__( 'Scolta: Amazee.ai connected. AI model automatically set to %s. You can change it on the Scolta settings page.', 'scolta-ai-search' ),
				$model,
			)
		);
		echo '</p></div>';
	}

	/**
	 * Show a dismissible notice if the plugin needs configuration.
	 */
	public static function maybe_show_setup_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_scolta' ), true ) ) {
			return;
		}

		if ( class_exists( '\WordPress\AI\Client' ) ) {
			return;
		}

		// While the AI features opt-in is pending, AI is off by design — a
		// missing API key is not a problem to warn about; the opt-in notice
		// and settings-page control carry the messaging.
		if ( self::ai_optin_pending() ) {
			return;
		}

		$source = Scolta_Ai_Service::get_api_key_source();
		if ( $source === 'none' ) {
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p>' . wp_kses_post(
				sprintf(
					/* translators: 1: environment variable name, 2: URL to settings page */
					__( '<strong>Scolta AI Search</strong> needs an API key for AI features. Set the %1$s environment variable, or <a href="%2$s">view setup instructions</a>.', 'scolta-ai-search' ),
					'<code>SCOLTA_API_KEY</code>',
					esc_url( admin_url( 'options-general.php?page=scolta' ) )
				)
			) . '</p>';
			echo '</div>';
		} elseif ( $source === 'database' ) {
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p>' . wp_kses_post(
				sprintf(
					/* translators: %s: URL to settings page */
					__( '<strong>Scolta AI Search:</strong> Your API key is stored in the database, which is insecure. <a href="%s">Migrate to an environment variable</a>.', 'scolta-ai-search' ),
					esc_url( admin_url( 'options-general.php?page=scolta' ) )
				)
			) . '</p>';
			echo '</div>';
		}

		// Only warn about missing binary when explicitly configured to use binary pipeline.
		$settings        = get_option( 'scolta_settings', array() );
		$indexer_setting = $settings['indexer'] ?? 'auto';
		if ( $indexer_setting === 'binary' ) {
			$resolver      = new \Tag1\Scolta\Binary\PagefindBinary(
				configuredPath: $settings['pagefind_binary'] ?? null,
				projectDir: SCOLTA_PLUGIN_DIR,
			);
			$binary_status = $resolver->status();
			if ( ! $binary_status['available'] ) {
				echo '<div class="notice notice-error is-dismissible">';
				echo '<p>' . wp_kses_post(
					sprintf(
					/* translators: %s: shell command */
						__( '<strong>Scolta:</strong> Pagefind binary not found, but indexer is set to "binary". Install Pagefind (%s) or change indexer to "auto" in settings.', 'scolta-ai-search' ),
						'<code>npm install -g pagefind</code>'
					)
				) . '</p>';
				echo '</div>';
			}
		}
	}

	// -----------------------------------------------------------------
	// Dashboard widget
	// -----------------------------------------------------------------

	/**
	 * Register the Scolta dashboard widget.
	 *
	 * @since 0.2.0
	 */
	public static function add_dashboard_widget(): void {
		// The rebuild POST handler is gated on manage_options; without this
		// guard the widget still renders index status and a broken rebuild
		// button for every role that can reach the dashboard.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'scolta_dashboard_widget',
			__( 'Scolta Search', 'scolta-ai-search' ),
			array( self::class, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the Scolta dashboard widget.
	 *
	 * Shows index status (fragment count + last build time), AI configuration
	 * status, a one-click rebuild button, and a link to the settings page.
	 *
	 * @since 0.2.0
	 */
	public static function render_dashboard_widget(): void {
		$settings = get_option( 'scolta_settings', array() );
		$health   = self::get_health_status();

		echo '<div class="scolta-dashboard-widget">';

		// Index status.
		$index_exists = $health['index_exists'];
		$last_build   = $health['index']['last_modified'];
		$page_count   = $health['index']['fragment_count'];

		if ( $index_exists ) {
			$age = $last_build ? human_time_diff( strtotime( $last_build ) ) . ' ' . __( 'ago', 'scolta-ai-search' ) : __( 'unknown', 'scolta-ai-search' );
			printf(
				'<p><strong>%s</strong> %s</p>',
				esc_html__( 'Index:', 'scolta-ai-search' ),
				/* translators: 1: number of pages, 2: human time string */
				esc_html( sprintf( __( '%1$d pages, last built %2$s', 'scolta-ai-search' ), $page_count, $age ) )
			);
		} else {
			echo '<p><strong>' . esc_html__( 'Index:', 'scolta-ai-search' ) . '</strong> ' . esc_html__( 'Not built yet', 'scolta-ai-search' ) . '</p>';
		}

		// AI status.
		$ai_configured = \Scolta_Ai_Service::get_api_key_source() !== 'none';
		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html__( 'AI:', 'scolta-ai-search' ),
			esc_html( $ai_configured ? __( 'Configured', 'scolta-ai-search' ) : __( 'Not configured', 'scolta-ai-search' ) )
		);

		// Rebuild button.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="scolta_rebuild_now">';
		wp_nonce_field( 'scolta_rebuild_now', 'scolta_rebuild_nonce' );
		submit_button( __( 'Rebuild Now', 'scolta-ai-search' ), 'secondary', 'submit', false );
		echo '</form>';

		// Link to full settings.
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'options-general.php?page=scolta' ) ),
			esc_html__( 'Settings →', 'scolta-ai-search' )
		);

		echo '</div>';
	}

	/**
	 * Return a summary of the current Scolta index status.
	 *
	 * Used by both the settings-page status table and the dashboard widget
	 * so the filesystem reads happen in one place.
	 *
	 * @return array{
	 *     index_exists: bool,
	 *     index: array{fragment_count: int, last_modified: string|null},
	 * }
	 *
	 * @since 0.2.0
	 */
	public static function get_health_status(): array {
		$settings = get_option( 'scolta_settings', array() );
		// Builder-identical normalization — see scolta_normalize_output_dir().
		$output_dir = scolta_normalize_output_dir( $settings['output_dir'] ?? scolta_default_output_dir() );
		$index_file = $output_dir . '/pagefind/pagefind.js';

		if ( ! file_exists( $index_file ) ) {
			return array(
				'index_exists' => false,
				'index'        => array(
					'fragment_count' => 0,
					'last_modified'  => null,
				),
			);
		}

		$index_dir      = $output_dir . '/pagefind';
		$mtime          = filemtime( $index_file );
		$glob_result    = glob( $index_dir . '/fragment/*' );
		$fragment_count = count( ! empty( $glob_result ) ? $glob_result : array() );

		return array(
			'index_exists' => true,
			'index'        => array(
				'fragment_count' => $fragment_count,
				'last_modified'  => $mtime ? gmdate( 'c', $mtime ) : null,
			),
		);
	}

	/**
	 * Handle the "Rebuild Index Now" form submission from the status summary.
	 *
	 * Runs the same PHP-indexer build pipeline as `wp scolta build` but
	 * from within the admin context. Redirects back to the settings page
	 * with a notice showing the result.
	 *
	 * Large sites should use WP-CLI to avoid hitting the PHP time limit.
	 */
	public static function handle_rebuild_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to rebuild the Scolta index.', 'scolta-ai-search' ), 403 );
		}

		check_admin_referer( 'scolta_rebuild_now', 'scolta_rebuild_nonce' );

		$settings   = get_option( 'scolta_settings', array() );
		$output_dir = $settings['output_dir'] ?? scolta_default_output_dir();

		$redirect = admin_url( 'options-general.php?page=scolta' );

		// Each rebuild gets a unique ID so per-user dismissals of old notices
		// don't suppress the new one.
		$notice_id = 'scolta_rebuild_' . bin2hex( random_bytes( 8 ) );

		// Clear any previous notice (and its dismissal state — new notice_id handles that).
		delete_transient( 'scolta_rebuild_notice' );

		// Honor the shared build lock. The REST rebuild endpoint responds 409
		// while it is held; running a second build concurrently would race the
		// scheduler on the same state/output directories.
		if ( get_transient( Scolta_Rebuild_Scheduler::LOCK_KEY ) ) {
			set_transient(
				'scolta_rebuild_notice',
				array(
					'result'    => 'locked',
					'notice_id' => $notice_id,
				),
				DAY_IN_SECONDS * 7
			);
			wp_safe_redirect( $redirect );
			exit;
		}
		set_transient( Scolta_Rebuild_Scheduler::LOCK_KEY, time(), Scolta_Rebuild_Scheduler::LOCK_TTL );

		try {
			$total = \Scolta_Content_Gatherer::gather_count();

			if ( 0 === $total ) {
				set_transient(
					'scolta_rebuild_notice',
					array(
						'result'    => 'no_content',
						'notice_id' => $notice_id,
					),
					DAY_IN_SECONDS * 7
				);
			} else {
				// Same streamed, budget-aware pipeline as `wp scolta build`:
				// the orchestrator consumes the gatherer's generator directly
				// (never materializing the corpus) and honors the configured
				// memory_budget_profile / chunk_size settings.
				$upload_dir = wp_upload_dir();
				$state_dir  = $upload_dir['basedir'] . '/scolta/state';

				$budget = \Tag1\Scolta\Config\MemoryBudgetConfig::fromCliAndConfig(
					null,
					null,
					fn() => array(
						'profile'    => $settings['memory_budget_profile'] ?? 'conservative',
						'chunk_size' => $settings['chunk_size'] ?? null,
					),
				);
				$intent = \Tag1\Scolta\Index\BuildIntentFactory::fromFlags( false, false, $total, $budget );

				$orchestrator = new \Tag1\Scolta\Index\IndexBuildOrchestrator( // logger is passed to build() below.
					$state_dir,
					$output_dir,
					wp_salt( 'auth' ),
				);

				$exporter = new \Tag1\Scolta\Export\ContentExporter( $output_dir );
				$items    = $exporter->filterItems(
					\Scolta_Content_Gatherer::gather( $orchestrator->getTimestampManifest(), false )
				);

				$report = $orchestrator->build( $intent, $items, new Scolta_Logger() );

				if ( $report->success && $report->pagesProcessed > 0 ) {
					scolta_cleanup_nested_indexes( $output_dir );
					$generation = (int) get_option( 'scolta_generation', 0 );
					update_option( 'scolta_generation', $generation + 1 );
					$notice = array(
						'result'    => 'ok',
						'pages'     => $report->pagesProcessed,
						'notice_id' => $notice_id,
					);
					set_transient( 'scolta_rebuild_notice', $notice, DAY_IN_SECONDS * 7 );
				} elseif ( $report->success ) {
					set_transient(
						'scolta_rebuild_notice',
						array(
							'result'    => 'no_items',
							'notice_id' => $notice_id,
						),
						DAY_IN_SECONDS * 7
					);
				} else {
					( new Scolta_Logger() )->error(
						'Admin rebuild failed: {message}',
						array( 'message' => (string) ( $report->error ?? 'unknown' ) )
					);
					set_transient(
						'scolta_rebuild_notice',
						array(
							'result'    => 'error',
							'notice_id' => $notice_id,
						),
						DAY_IN_SECONDS * 7
					);
				}
			}
		} catch ( \Throwable $e ) {
			( new Scolta_Logger() )->error(
				'Admin rebuild failed: {message}',
				array(
					'message'   => $e->getMessage(),
					'exception' => $e,
				)
			);
			set_transient(
				'scolta_rebuild_notice',
				array(
					'result'    => 'error',
					'notice_id' => $notice_id,
				),
				DAY_IN_SECONDS * 7
			);
		} finally {
			// The lock must clear on every path — wp_safe_redirect() + exit
			// happens after this block, so a thrown build error cannot leave
			// a stale lock blocking the next rebuild for LOCK_TTL seconds.
			delete_transient( Scolta_Rebuild_Scheduler::LOCK_KEY );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}

// Initialize admin hooks.
Scolta_Admin::init();
