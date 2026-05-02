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
 */

defined( 'ABSPATH' ) || exit;

class Scolta_Admin {

	/**
	 * Hook into WordPress admin.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_settings_page' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
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
	}

	/**
	 * Add the settings page under Settings > Scolta.
	 */
	public static function add_settings_page(): void {
		add_options_page(
			__( 'Scolta AI Search', 'scolta' ),
			__( 'Scolta', 'scolta' ),
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
		add_settings_section( 'scolta_ai_section', __( 'AI Provider', 'scolta' ), array( self::class, 'render_ai_section' ), 'scolta' );

		// Only show manual AI config fields when WP AI Client SDK is NOT available.
		if ( ! class_exists( '\WordPress\AI\Client' ) ) {
			add_settings_field( 'ai_provider', __( 'Provider', 'scolta' ), array( self::class, 'render_ai_provider_field' ), 'scolta', 'scolta_ai_section' );
			add_settings_field( 'ai_api_key_status', __( 'API Key', 'scolta' ), array( self::class, 'render_api_key_status_field' ), 'scolta', 'scolta_ai_section' );
			add_settings_field( 'ai_model', __( 'Model', 'scolta' ), array( self::class, 'render_ai_model_field' ), 'scolta', 'scolta_ai_section' );
			add_settings_field( 'ai_expansion_model', __( 'Expansion Model', 'scolta' ), array( self::class, 'render_ai_expansion_model_field' ), 'scolta', 'scolta_ai_section' );
			add_settings_field( 'ai_base_url', __( 'Base URL', 'scolta' ), array( self::class, 'render_ai_base_url_field' ), 'scolta', 'scolta_ai_section' );
		}

		add_settings_field( 'ai_expand_query', __( 'AI Query Expansion', 'scolta' ), array( self::class, 'render_ai_expand_field' ), 'scolta', 'scolta_ai_section' );
		add_settings_field( 'ai_summarize', __( 'AI Summarization', 'scolta' ), array( self::class, 'render_ai_summarize_field' ), 'scolta', 'scolta_ai_section' );
		add_settings_field( 'max_follow_ups', __( 'Max Follow-ups', 'scolta' ), array( self::class, 'render_max_followups_field' ), 'scolta', 'scolta_ai_section' );
		add_settings_field( 'ai_languages', __( 'AI Languages', 'scolta' ), array( self::class, 'render_ai_languages_field' ), 'scolta', 'scolta_ai_section' );

		// --- Section: Content ---
		add_settings_section( 'scolta_content_section', __( 'Content', 'scolta' ), array( self::class, 'render_content_section' ), 'scolta' );
		add_settings_field( 'post_types', __( 'Post Types', 'scolta' ), array( self::class, 'render_post_types_field' ), 'scolta', 'scolta_content_section' );
		add_settings_field( 'site_name', __( 'Site Name', 'scolta' ), array( self::class, 'render_site_name_field' ), 'scolta', 'scolta_content_section' );
		add_settings_field( 'site_description', __( 'Site Description', 'scolta' ), array( self::class, 'render_site_description_field' ), 'scolta', 'scolta_content_section' );

		// --- Section: Pagefind ---
		add_settings_section( 'scolta_pagefind_section', __( 'Pagefind', 'scolta' ), array( self::class, 'render_pagefind_section' ), 'scolta' );
		add_settings_field( 'indexer', __( 'Indexer', 'scolta' ), array( self::class, 'render_indexer_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'pagefind_binary', __( 'Binary Path', 'scolta' ), array( self::class, 'render_pagefind_binary_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'build_dir', __( 'Build Directory', 'scolta' ), array( self::class, 'render_build_dir_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'output_dir', __( 'Output Directory', 'scolta' ), array( self::class, 'render_output_dir_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'auto_rebuild', __( 'Auto Rebuild', 'scolta' ), array( self::class, 'render_auto_rebuild_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'auto_rebuild_delay', __( 'Rebuild Delay (seconds)', 'scolta' ), array( self::class, 'render_auto_rebuild_delay_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'memory_budget_profile', __( 'Memory Budget', 'scolta' ), array( self::class, 'render_memory_budget_field' ), 'scolta', 'scolta_pagefind_section' );
		add_settings_field( 'chunk_size', __( 'Chunk Size', 'scolta' ), array( self::class, 'render_chunk_size_field' ), 'scolta', 'scolta_pagefind_section' );

		// --- Section: Site Type ---
		add_settings_section( 'scolta_site_type_section', __( 'Site Type', 'scolta' ), array( self::class, 'render_site_type_section' ), 'scolta' );
		add_settings_field( 'preset', __( 'What kind of site is this?', 'scolta' ), array( self::class, 'render_preset_field' ), 'scolta', 'scolta_site_type_section' );

		// --- Section: Scoring ---
		add_settings_section( 'scolta_scoring_section', __( 'Scoring', 'scolta' ), array( self::class, 'render_scoring_section' ), 'scolta' );
		add_settings_field( 'title_match_boost', __( 'Title Match Boost', 'scolta' ), array( self::class, 'render_title_boost_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'title_all_terms_multiplier', __( 'Title All-Terms Multiplier', 'scolta' ), array( self::class, 'render_title_all_terms_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'content_match_boost', __( 'Content Match Boost', 'scolta' ), array( self::class, 'render_content_boost_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_boost_max', __( 'Recency Boost', 'scolta' ), array( self::class, 'render_recency_boost_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_half_life_days', __( 'Recency Half-life (days)', 'scolta' ), array( self::class, 'render_recency_halflife_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_penalty_after_days', __( 'Recency Penalty After (days)', 'scolta' ), array( self::class, 'render_recency_penalty_days_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_max_penalty', __( 'Recency Max Penalty', 'scolta' ), array( self::class, 'render_recency_max_penalty_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'expand_primary_weight', __( 'Expand Primary Weight', 'scolta' ), array( self::class, 'render_expand_weight_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'language', __( 'Scoring Language', 'scolta' ), array( self::class, 'render_language_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'custom_stop_words', __( 'Custom Stop Words', 'scolta' ), array( self::class, 'render_custom_stop_words_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_strategy', __( 'Recency Strategy', 'scolta' ), array( self::class, 'render_recency_strategy_field' ), 'scolta', 'scolta_scoring_section' );
		add_settings_field( 'recency_curve', __( 'Custom Recency Curve', 'scolta' ), array( self::class, 'render_recency_curve_field' ), 'scolta', 'scolta_scoring_section' );

		// --- Section: Display ---
		add_settings_section( 'scolta_display_section', __( 'Display', 'scolta' ), array( self::class, 'render_display_section' ), 'scolta' );
		add_settings_field( 'excerpt_length', __( 'Excerpt Length', 'scolta' ), array( self::class, 'render_excerpt_length_field' ), 'scolta', 'scolta_display_section' );
		add_settings_field( 'results_per_page', __( 'Results Per Page', 'scolta' ), array( self::class, 'render_results_per_page_field' ), 'scolta', 'scolta_display_section' );
		add_settings_field( 'max_pagefind_results', __( 'Max Pagefind Results', 'scolta' ), array( self::class, 'render_max_pagefind_results_field' ), 'scolta', 'scolta_display_section' );
		add_settings_field( 'ai_summary_top_n', __( 'AI Summary Top N', 'scolta' ), array( self::class, 'render_ai_summary_top_n_field' ), 'scolta', 'scolta_display_section' );
		add_settings_field( 'ai_summary_max_chars', __( 'AI Summary Max Chars', 'scolta' ), array( self::class, 'render_ai_summary_max_chars_field' ), 'scolta', 'scolta_display_section' );

		// --- Section: Cache ---
		add_settings_section( 'scolta_cache_section', __( 'Cache', 'scolta' ), array( self::class, 'render_cache_section' ), 'scolta' );
		add_settings_field( 'cache_ttl', __( 'Query Expansion Cache Duration', 'scolta' ), array( self::class, 'render_cache_ttl_field' ), 'scolta', 'scolta_cache_section' );

		// --- Section: Custom Prompts (Advanced) ---
		add_settings_section( 'scolta_prompts_section', __( 'Custom Prompts (Advanced)', 'scolta' ), array( self::class, 'render_prompts_section' ), 'scolta' );
		add_settings_field( 'prompt_expand_query', __( 'Expand Query Prompt', 'scolta' ), array( self::class, 'render_prompt_expand_field' ), 'scolta', 'scolta_prompts_section' );
		add_settings_field( 'prompt_summarize', __( 'Summarize Prompt', 'scolta' ), array( self::class, 'render_prompt_summarize_field' ), 'scolta', 'scolta_prompts_section' );
		add_settings_field( 'prompt_follow_up', __( 'Follow-up Prompt', 'scolta' ), array( self::class, 'render_prompt_followup_field' ), 'scolta', 'scolta_prompts_section' );
	}

	// -----------------------------------------------------------------
	// Section descriptions
	// -----------------------------------------------------------------

	public static function render_ai_section(): void {
		if ( class_exists( '\WordPress\AI\Client' ) ) {
			echo '<p class="description">';
			echo wp_kses_post(
				sprintf(
					/* translators: %s: URL to WordPress AI connectors settings page */
					__( 'AI is configured through the <a href="%s">WordPress AI Client SDK</a>. Scolta will use your configured AI provider automatically.', 'scolta' ),
					esc_url( admin_url( 'options-general.php?page=ai-connectors' ) )
				)
			);
			echo '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Configure the AI provider for query expansion, summarization, and follow-up conversations.', 'scolta' ) . '</p>';
		}
	}

	public static function render_content_section(): void {
		echo '<p class="description">' . esc_html__( 'Choose which content types to index and how your site is identified in search results.', 'scolta' ) . '</p>';
	}

	public static function render_pagefind_section(): void {
		echo '<p class="description">' . esc_html__( 'Pagefind builds a static search index from your exported content.', 'scolta' ) . '</p>';
	}

	public static function render_site_type_section(): void {
		echo '<p class="description">' . esc_html__( 'Start here. Pick the closest match for your site — this gives you a good set of defaults. Presets adjust how Scolta ranks search results — how much weight goes to titles vs. page content, whether newer content ranks higher, and how broadly Scolta interprets what you searched for. The preset is a starting point, not a constraint: you can optionally change any individual setting in the Scoring section below.', 'scolta' ) . '</p>';
	}

	public static function render_preset_field(): void {
		$presets         = \Tag1\Scolta\Config\ScoltaConfig::getPresets();
		$current_preset  = self::get_setting( 'preset', 'none' );
		$valid_presets   = array_keys( $presets );
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
		echo '<script>
(function(){
	var sel = document.getElementById("scolta_preset");
	if (!sel) return;
	sel.addEventListener("change", function(){
		document.querySelectorAll(".scolta-preset-desc").forEach(function(el){
			el.style.display = "none";
		});
		var active = document.querySelector(".scolta-preset-desc--" + sel.value);
		if (active) active.style.display = "";
	});
}());
</script>';
	}

	public static function render_scoring_section(): void {
		$current_preset = self::get_setting( 'preset', 'none' );
		if ( $current_preset !== 'none' ) {
			$presets = \Tag1\Scolta\Config\ScoltaConfig::getPresets();
			$label   = isset( $presets[ $current_preset ] ) ? $presets[ $current_preset ]['label'] : $current_preset;
			printf(
				'<p class="description">%s</p>',
				esc_html( sprintf(
					/* translators: %s: preset label */
					__( 'These settings were populated by the %s preset. Change any value here and your change takes priority — the preset only fills in what you haven\'t touched.', 'scolta' ),
					$label
				) )
			);
		} else {
			echo '<p class="description">' . esc_html__( 'Configure each scoring parameter individually. Fine-tune how search results are ranked. Defaults work well for most sites.', 'scolta' ) . '</p>';
		}
	}

	public static function render_display_section(): void {
		echo '<p class="description">' . esc_html__( 'Control the search results display and AI summarization context.', 'scolta' ) . '</p>';
	}

	public static function render_cache_section(): void {
		echo '<p class="description">' . esc_html__( 'AI query expansion results are cached to reduce API calls.', 'scolta' ) . '</p>';
	}

	public static function render_prompts_section(): void {
		echo '<p class="description">' . esc_html__( 'Override the built-in AI system prompts. Leave empty to use the defaults.', 'scolta' ) . '</p>';
	}

	// -----------------------------------------------------------------
	// Field renderers
	// -----------------------------------------------------------------

	private static function get_setting( string $key, mixed $default = '' ): mixed {
		$settings = get_option( 'scolta_settings', array() );
		return $settings[ $key ] ?? $default;
	}

	public static function render_ai_provider_field(): void {
		$value = self::get_setting( 'ai_provider', 'anthropic' );
		?>
		<select name="scolta_settings[ai_provider]" id="scolta_ai_provider">
			<option value="anthropic" <?php selected( $value, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude)', 'scolta' ); ?></option>
			<option value="openai" <?php selected( $value, 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'scolta' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Render API key status (read-only — no input field).
	 */
	public static function render_api_key_status_field(): void {
		$source = Scolta_Ai_Service::get_api_key_source();

		switch ( $source ) {
			case 'env':
				echo '<div class="notice notice-success inline"><p>';
				echo esc_html__( 'API key loaded from SCOLTA_API_KEY environment variable.', 'scolta' );
				echo '</p></div>';
				break;

			case 'constant':
				echo '<div class="notice notice-info inline"><p>';
				echo esc_html__( 'API key loaded from SCOLTA_API_KEY constant in wp-config.php.', 'scolta' );
				echo '</p><p class="description">';
				echo esc_html__( 'For production hosting, consider using an environment variable instead.', 'scolta' );
				echo '</p></div>';
				break;

			case 'database':
				echo '<div class="notice notice-error inline"><p>';
				echo '<strong>' . esc_html__( 'Security warning:', 'scolta' ) . '</strong> ';
				echo esc_html__( 'API key is stored in the database, which is insecure. Migrate it to an environment variable by setting SCOLTA_API_KEY on your hosting platform, then remove the key from the database.', 'scolta' );
				echo '</p><p>';
				echo '<button type="button" class="button" id="scolta-remove-db-key">';
				echo esc_html__( 'Remove key from database', 'scolta' );
				echo '</button>';
				echo '<span id="scolta-remove-db-key-status"></span>';
				wp_nonce_field( 'scolta_remove_db_key', 'scolta_remove_db_key_nonce' );
				echo '</p></div>';
				echo '<script>
                    document.getElementById("scolta-remove-db-key")?.addEventListener("click", function() {
                        if (!confirm("' . esc_js( __( 'Remove the API key from the database? Make sure you have set the SCOLTA_API_KEY environment variable first.', 'scolta' ) ) . '")) return;
                        var data = new FormData();
                        data.append("action", "scolta_remove_db_key");
                        data.append("_wpnonce", document.getElementById("scolta_remove_db_key_nonce").value);
                        fetch(ajaxurl, { method: "POST", body: data })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                document.getElementById("scolta-remove-db-key-status").textContent = d.success ? " Removed." : " Failed.";
                                if (d.success) location.reload();
                            });
                    });
                </script>';
				break;

			default:
				echo '<div class="notice notice-error inline"><p>';
				echo esc_html__( 'No API key configured. Set the SCOLTA_API_KEY environment variable on your hosting platform.', 'scolta' );
				echo '</p><p class="description">';
				printf(
					/* translators: %s: PHP code snippet */
					esc_html__( 'For local development, add %s to your wp-config.php.', 'scolta' ),
					'<code>putenv(\'SCOLTA_API_KEY=sk-...\');</code>'
				);
				echo '</p></div>';
				break;
		}

		if ( $source !== 'none' ) {
			$nonce = wp_create_nonce( 'scolta_test_connection' );
			echo '<div style="margin-top: 10px;">';
			echo '<button type="button" class="button" id="scolta-test-connection-btn" data-nonce="' . esc_attr( $nonce ) . '">';
			echo esc_html__( 'Test Connection', 'scolta' );
			echo '</button>';
			echo '<span id="scolta-test-result" style="margin-left: 10px; display: none;"></span>';
			echo '</div>';
			echo '<script>
                document.getElementById("scolta-test-connection-btn")?.addEventListener("click", function() {
                    var btn = this;
                    var result = document.getElementById("scolta-test-result");
                    btn.disabled = true;
                    result.innerHTML = "<span style=\"color:#666\">' . esc_js( __( 'Testing\u2026', 'scolta' ) ) . '</span>";
                    result.style.display = "inline";
                    var data = new FormData();
                    data.append("action", "scolta_test_connection");
                    data.append("nonce", btn.dataset.nonce);
                    fetch(ajaxurl, { method: "POST", body: data })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d.success) {
                                result.innerHTML = "<span style=\"color:#28a745\">\u2713 ' . esc_js( __( 'Connected', 'scolta' ) ) . '</span> (" + d.data.provider + " / " + d.data.model + ", " + d.data.response_time + "ms)";
                            } else {
                                result.innerHTML = "<span style=\"color:#dc3545\">\u2717 ' . esc_js( __( 'Failed', 'scolta' ) ) . ':</span> " + d.data.error;
                            }
                        })
                        .catch(function() {
                            result.innerHTML = "<span style=\"color:#dc3545\">\u2717 ' . esc_js( __( 'Network error', 'scolta' ) ) . '</span>";
                        })
                        .finally(function() { btn.disabled = false; });
                });
            </script>';
		}
	}

	public static function render_ai_model_field(): void {
		$value = self::get_setting( 'ai_model', 'claude-sonnet-4-5-20250929' );
		?>
		<input type="text" name="scolta_settings[ai_model]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Model identifier. e.g., claude-sonnet-4-5-20250929 or gpt-4o', 'scolta' ); ?></p>
		<?php
	}

	public static function render_ai_expansion_model_field(): void {
		$value = self::get_setting( 'ai_expansion_model', '' );
		?>
		<input type="text" name="scolta_settings[ai_expansion_model]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Optional model for query expansion only. Leave empty to use the main Model for all operations. Example: claude-haiku-4-5-20251001', 'scolta' ); ?></p>
		<?php
	}

	public static function render_ai_base_url_field(): void {
		$value = self::get_setting( 'ai_base_url', '' );
		?>
		<input type="text" name="scolta_settings[ai_base_url]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Override the default API endpoint. Leave empty for the provider default.', 'scolta' ); ?></p>
		<?php
	}

	public static function render_ai_expand_field(): void {
		$value = self::get_setting( 'ai_expand_query', true );
		?>
		<label>
			<input type="checkbox" name="scolta_settings[ai_expand_query]" value="1" <?php checked( $value ); ?> />
			<?php esc_html_e( 'Use AI to expand search queries into related terms', 'scolta' ); ?>
		</label>
		<?php
	}

	public static function render_ai_summarize_field(): void {
		$value = self::get_setting( 'ai_summarize', true );
		?>
		<label>
			<input type="checkbox" name="scolta_settings[ai_summarize]" value="1" <?php checked( $value ); ?> />
			<?php esc_html_e( 'Generate AI summaries of search results', 'scolta' ); ?>
		</label>
		<?php
	}

	public static function render_max_followups_field(): void {
		$value = self::get_setting( 'max_follow_ups', 3 );
		?>
		<input type="number" name="scolta_settings[max_follow_ups]" value="<?php echo esc_attr( $value ); ?>" min="0" max="10" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum conversational follow-up messages per search session. 0 to disable.', 'scolta' ); ?></p>
		<?php
	}

	public static function render_ai_languages_field(): void {
		$value = self::get_setting( 'ai_languages', array( 'en' ) );
		if ( ! is_array( $value ) ) {
			$value = array( 'en' );
		}
		$display = implode( ', ', $value );
		?>
		<input type="text" name="scolta_settings[ai_languages]" value="<?php echo esc_attr( $display ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Comma-separated language codes (e.g., en, es, fr). When multiple languages are configured, AI responses will match the language of the user\'s query.', 'scolta' ); ?></p>
		<?php
	}

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
		<p class="description"><?php esc_html_e( 'Content types to include in the search index.', 'scolta' ); ?></p>
		<?php
	}

	public static function render_site_name_field(): void {
		$value = self::get_setting( 'site_name', get_bloginfo( 'name' ) );
		?>
		<input type="text" name="scolta_settings[site_name]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Used in AI prompts and search result attribution. Defaults to your site title.', 'scolta' ); ?></p>
		<?php
	}

	public static function render_site_description_field(): void {
		$value = self::get_setting( 'site_description', 'website' );
		?>
		<input type="text" name="scolta_settings[site_description]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Brief description for AI context. e.g., "technology blog" or "university research portal"', 'scolta' ); ?></p>
		<?php
	}

	public static function render_indexer_field(): void {
		$value = self::get_setting( 'indexer', 'auto' );
		?>
		<select name="scolta_settings[indexer]" id="scolta_indexer">
			<option value="auto" <?php selected( $value, 'auto' ); ?>><?php esc_html_e( 'Auto (use binary if available, otherwise PHP)', 'scolta' ); ?></option>
			<option value="php" <?php selected( $value, 'php' ); ?>><?php esc_html_e( 'PHP (built-in, no binary needed)', 'scolta' ); ?></option>
			<option value="binary" <?php selected( $value, 'binary' ); ?>><?php esc_html_e( 'Binary (requires Pagefind CLI)', 'scolta' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'The PHP indexer builds the search index without requiring the Pagefind binary. Auto selects PHP when the binary is unavailable.', 'scolta' ); ?></p>
		<?php
	}

	public static function render_memory_budget_field(): void {
		$profile    = self::get_setting( 'memory_budget_profile', 'conservative' );
		$limit_text = \Tag1\Scolta\Index\MemoryBudgetSuggestion::getMemoryLimitText();
		$fit        = \Tag1\Scolta\Index\MemoryBudgetSuggestion::checkProfileFit( $profile );
		?>
		<p class="description" style="margin-bottom:8px">
			<?php esc_html_e( 'How much RAM to use while building the search index. Enter a profile name (conservative, balanced, aggressive) or an exact value like 256M or 1G. It never exceeds the PHP memory limit your host allows.', 'scolta' ); ?>
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
			<option value="conservative"><?php esc_html_e( 'Conservative — peak ≤ 96 MB (default, safe for shared hosting)', 'scolta' ); ?></option>
			<option value="balanced"><?php esc_html_e( 'Balanced — ~384 MB (recommended for dedicated VMs)', 'scolta' ); ?></option>
			<option value="aggressive"><?php esc_html_e( 'Aggressive — ~1 GB (high-memory servers only)', 'scolta' ); ?></option>
		</datalist>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Detected PHP memory_limit value e.g. "256 MB" */
				esc_html__( 'Your current PHP memory limit is %s. The conservative profile fits within 128 MB and is safe for most shared hosts.', 'scolta' ),
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
			<?php esc_html_e( 'Can be overridden per-run with --memory-budget on wp scolta build.', 'scolta' ); ?>
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
			placeholder="<?php esc_attr_e( 'profile default', 'scolta' ); ?>"
		/>
		<p class="description">
			<?php esc_html_e( 'Pages indexed per chunk during a PHP build. Leave blank to use the memory budget profile default (50 for conservative, 200 for balanced, 500 for aggressive). Lower values reduce peak RSS; higher values reduce merge overhead on large corpora.', 'scolta' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Can be overridden per-run with --chunk-size on wp scolta build.', 'scolta' ); ?>
		</p>
		<?php
	}

	public static function render_pagefind_binary_field(): void {
		$value = self::get_setting( 'pagefind_binary', 'pagefind' );
		?>
		<input type="text" name="scolta_settings[pagefind_binary]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php printf( /* translators: %s: WP-CLI command */ esc_html__( 'Path to the Pagefind binary. Run "%s" to download it.', 'scolta' ), 'wp scolta download-pagefind' ); ?></p>
		<?php
	}

	public static function render_build_dir_field(): void {
		$value = self::get_setting( 'build_dir', wp_upload_dir()['basedir'] . '/scolta/build' );
		?>
		<input type="text" name="scolta_settings[build_dir]" value="<?php echo esc_attr( $value ); ?>" class="large-text" />
		<p class="description"><?php esc_html_e( 'Where exported HTML files are written during index builds. Defaults to wp-content/uploads/scolta/build, which works on managed hosts. If your host allows it, can be outside the web root for better security.', 'scolta' ); ?></p>
		<?php
	}

	public static function render_output_dir_field(): void {
		$value = self::get_setting( 'output_dir', wp_upload_dir()['basedir'] . '/scolta/pagefind' );
		?>
		<input type="text" name="scolta_settings[output_dir]" value="<?php echo esc_attr( $value ); ?>" class="large-text" />
		<p class="description"><?php esc_html_e( 'Directory for the Pagefind search index. Must be web-accessible. Defaults to wp-content/uploads/scolta/pagefind.', 'scolta' ); ?></p>
		<?php
	}

	public static function render_auto_rebuild_field(): void {
		$value = self::get_setting( 'auto_rebuild', true );
		?>
		<label>
			<input type="checkbox" name="scolta_settings[auto_rebuild]" value="1" <?php checked( $value ); ?> />
			<?php esc_html_e( 'Automatically rebuild the Pagefind index when content is exported via WP-CLI', 'scolta' ); ?>
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
			. ' value="' . esc_attr( $delay ) . '" min="60" max="3600" step="60" />';
		echo '<p class="description">' . esc_html__( 'Seconds to wait after the last content change before rebuilding the index. Minimum 60. Default 300 (5 minutes). Higher values batch more changes together.', 'scolta' ) . '</p>';
	}

	// -- Scoring fields --

	public static function render_title_boost_field(): void {
		$value = self::get_setting( 'title_match_boost', 1.0 );
		?>
		<input type="number" name="scolta_settings[title_match_boost]" value="<?php echo esc_attr( $value ); ?>" min="0" max="10" step="0.1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Bonus for search terms in the title. Default: 1.0', 'scolta' ); ?></p>
		<?php
	}

	public static function render_title_all_terms_field(): void {
		$value = self::get_setting( 'title_all_terms_multiplier', 1.5 );
		?>
		<input type="number" name="scolta_settings[title_all_terms_multiplier]" value="<?php echo esc_attr( $value ); ?>" min="0" max="10" step="0.1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Extra boost when ALL search terms appear in the title. Default: 1.5', 'scolta' ); ?></p>
		<?php
	}

	public static function render_content_boost_field(): void {
		$value = self::get_setting( 'content_match_boost', 0.4 );
		?>
		<input type="number" name="scolta_settings[content_match_boost]" value="<?php echo esc_attr( $value ); ?>" min="0" max="10" step="0.1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Bonus for search terms in the body content. Default: 0.4', 'scolta' ); ?></p>
		<?php
	}

	public static function render_recency_boost_field(): void {
		$value = self::get_setting( 'recency_boost_max', 0.5 );
		?>
		<input type="number" name="scolta_settings[recency_boost_max]" value="<?php echo esc_attr( $value ); ?>" min="0" max="5" step="0.1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum boost for recent content. Default: 0.5', 'scolta' ); ?></p>
		<?php
	}

	public static function render_recency_halflife_field(): void {
		$value = self::get_setting( 'recency_half_life_days', 365 );
		?>
		<input type="number" name="scolta_settings[recency_half_life_days]" value="<?php echo esc_attr( $value ); ?>" min="1" max="3650" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Days until the recency boost decays to half. Default: 365', 'scolta' ); ?></p>
		<?php
	}

	public static function render_recency_penalty_days_field(): void {
		$value = self::get_setting( 'recency_penalty_after_days', 1825 );
		?>
		<input type="number" name="scolta_settings[recency_penalty_after_days]" value="<?php echo esc_attr( $value ); ?>" min="0" max="7300" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Content older than this gets a negative adjustment. Default: 1825 (5 years)', 'scolta' ); ?></p>
		<?php
	}

	public static function render_recency_max_penalty_field(): void {
		$value = self::get_setting( 'recency_max_penalty', 0.3 );
		?>
		<input type="number" name="scolta_settings[recency_max_penalty]" value="<?php echo esc_attr( $value ); ?>" min="0" max="1" step="0.1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum penalty for very old content. Default: 0.3', 'scolta' ); ?></p>
		<?php
	}

	public static function render_expand_weight_field(): void {
		$value = self::get_setting( 'expand_primary_weight', 0.5 );
		?>
		<input type="number" name="scolta_settings[expand_primary_weight]" value="<?php echo esc_attr( $value ); ?>" min="0" max="1" step="0.05" class="small-text" />
		<p class="description"><?php esc_html_e( 'Weight for the primary expanded term (subsequent terms decay). Default: 0.5', 'scolta' ); ?></p>
		<?php
	}

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
		echo '<p class="description">' . esc_html__( 'Language used for stop word filtering during scoring. Choose the primary language of your site content. Default: en', 'scolta' ) . '</p>';
	}

	public static function render_custom_stop_words_field(): void {
		$value = self::get_setting( 'custom_stop_words', array() );
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		$display = implode( ', ', $value );
		?>
		<input type="text" name="scolta_settings[custom_stop_words]" value="<?php echo esc_attr( $display ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Comma-separated extra stop words to exclude from scoring, beyond the language built-in list. e.g. drupal, cms, site', 'scolta' ); ?></p>
		<?php
	}

	public static function render_recency_strategy_field(): void {
		$value = self::get_setting( 'recency_strategy', 'exponential' );
		?>
		<select name="scolta_settings[recency_strategy]" id="scolta_recency_strategy">
			<option value="exponential" <?php selected( $value, 'exponential' ); ?>><?php esc_html_e( 'Exponential (default)', 'scolta' ); ?></option>
			<option value="linear" <?php selected( $value, 'linear' ); ?>><?php esc_html_e( 'Linear', 'scolta' ); ?></option>
			<option value="step" <?php selected( $value, 'step' ); ?>><?php esc_html_e( 'Step', 'scolta' ); ?></option>
			<option value="none" <?php selected( $value, 'none' ); ?>><?php esc_html_e( 'None (disable recency scoring)', 'scolta' ); ?></option>
			<option value="custom" <?php selected( $value, 'custom' ); ?>><?php esc_html_e( 'Custom (piecewise-linear curve)', 'scolta' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Decay function for recency boost. Custom uses the control points in the field below.', 'scolta' ); ?></p>
		<?php
	}

	public static function render_recency_curve_field(): void {
		$raw     = self::get_setting( 'recency_curve', array() );
		$display = ! empty( $raw ) ? json_encode( $raw ) : '';
		?>
		<input type="text" name="scolta_settings[recency_curve]" value="<?php echo esc_attr( $display ); ?>" class="large-text" />
		<p class="description"><?php esc_html_e( 'JSON array of [days, boost] control points for the custom strategy. e.g. [[0, 1.0], [180, 0.5], [365, 0.0]]. Only used when strategy is "custom".', 'scolta' ); ?></p>
		<?php
	}

	// -- Display fields --

	public static function render_excerpt_length_field(): void {
		$value = self::get_setting( 'excerpt_length', 300 );
		?>
		<input type="number" name="scolta_settings[excerpt_length]" value="<?php echo esc_attr( $value ); ?>" min="50" max="1000" step="50" class="small-text" />
		<p class="description"><?php esc_html_e( 'Characters shown in result excerpts. Default: 300', 'scolta' ); ?></p>
		<?php
	}

	public static function render_results_per_page_field(): void {
		$value = self::get_setting( 'results_per_page', 10 );
		?>
		<input type="number" name="scolta_settings[results_per_page]" value="<?php echo esc_attr( $value ); ?>" min="5" max="100" step="5" class="small-text" />
		<p class="description"><?php esc_html_e( 'Results shown before "show more". Default: 10', 'scolta' ); ?></p>
		<?php
	}

	public static function render_max_pagefind_results_field(): void {
		$value = self::get_setting( 'max_pagefind_results', 50 );
		?>
		<input type="number" name="scolta_settings[max_pagefind_results]" value="<?php echo esc_attr( $value ); ?>" min="10" max="500" step="10" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum results fetched from Pagefind before scoring. Default: 50', 'scolta' ); ?></p>
		<?php
	}

	public static function render_ai_summary_top_n_field(): void {
		$value = self::get_setting( 'ai_summary_top_n', 10 );
		?>
		<input type="number" name="scolta_settings[ai_summary_top_n]" value="<?php echo esc_attr( $value ); ?>" min="1" max="20" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Number of top results sent to AI for summarization. Default: 10', 'scolta' ); ?></p>
		<?php
	}

	public static function render_ai_summary_max_chars_field(): void {
		$value = self::get_setting( 'ai_summary_max_chars', 4000 );
		?>
		<input type="number" name="scolta_settings[ai_summary_max_chars]" value="<?php echo esc_attr( $value ); ?>" min="500" max="10000" step="500" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum characters per result excerpt sent to AI. Default: 4000', 'scolta' ); ?></p>
		<?php
	}

	// -- Cache field --

	public static function render_cache_ttl_field(): void {
		$value = self::get_setting( 'cache_ttl', 2592000 );
		?>
		<input type="number" name="scolta_settings[cache_ttl]" value="<?php echo esc_attr( $value ); ?>" min="0" max="7776000" step="1" class="regular-text" />
		<p class="description">
		<?php
			esc_html_e( 'Seconds. 0 = disabled. Common values: 86400 (1 day), 604800 (7 days), 2592000 (30 days, default).', 'scolta' );
		?>
		</p>
		<?php
	}

	// -- Prompt override fields --

	public static function render_prompt_expand_field(): void {
		$value      = self::get_effective_prompt( 'prompt_expand_query', \Tag1\Scolta\Prompt\DefaultPrompts::EXPAND_QUERY );
		$is_default = empty( self::get_setting( 'prompt_expand_query', '' ) );
		self::render_prompt_field(
			'prompt_expand_query',
			$value,
			$is_default,
			__( 'Edit the query expansion system prompt. Clear the field and save to reset to the default.', 'scolta' )
		);
	}

	public static function render_prompt_summarize_field(): void {
		$value      = self::get_effective_prompt( 'prompt_summarize', \Tag1\Scolta\Prompt\DefaultPrompts::SUMMARIZE );
		$is_default = empty( self::get_setting( 'prompt_summarize', '' ) );
		self::render_prompt_field(
			'prompt_summarize',
			$value,
			$is_default,
			__( 'Edit the summarization system prompt. Clear the field and save to reset to the default.', 'scolta' )
		);
	}

	public static function render_prompt_followup_field(): void {
		$value      = self::get_effective_prompt( 'prompt_follow_up', \Tag1\Scolta\Prompt\DefaultPrompts::FOLLOW_UP );
		$is_default = empty( self::get_setting( 'prompt_follow_up', '' ) );
		self::render_prompt_field(
			'prompt_follow_up',
			$value,
			$is_default,
			__( 'Edit the follow-up system prompt. Clear the field and save to reset to the default.', 'scolta' )
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
		$badge        = $is_default
			? '<span style="color:#888;font-style:italic;margin-left:0.5em;">' . esc_html__( '(default)', 'scolta' ) . '</span>'
			: '<span style="color:#0073aa;font-weight:600;margin-left:0.5em;">' . esc_html__( '(customized)', 'scolta' ) . '</span>';
		?>
		<div>
			<?php echo wp_kses_post( $badge ); ?>
			<?php if ( ! $is_default ) : ?>
				<button type="button" class="button-link" style="margin-left:0.5em;color:#b32d2e;" onclick="
					var ta = this.closest('div').querySelector('textarea');
					ta.value = ta.dataset.defaultPrompt;
					ta.dataset.cleared = '1';
					this.closest('div').querySelector('.scolta-badge').innerHTML = '<?php echo esc_js( '<span style=&quot;color:#888;font-style:italic;&quot;>' . esc_html__( '(default)', 'scolta' ) . '</span>' ); ?>';
					this.remove();
				"><?php esc_html_e( 'Reset to default', 'scolta' ); ?></button>
			<?php endif; ?>
		</div>
		<textarea name="scolta_settings[<?php echo esc_attr( $key ); ?>]" rows="8" class="large-text" data-default-prompt="<?php echo esc_attr( $default_text ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php echo esc_html( $description ); ?> <?php esc_html_e( 'Supports {SITE_NAME} and {SITE_DESCRIPTION} placeholders.', 'scolta' ); ?></p>
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
		$clean['ai_provider'] = in_array( $input['ai_provider'] ?? '', array( 'anthropic', 'openai' ), true )
			? $input['ai_provider']
			: 'anthropic';

		// Model.
		$clean['ai_model']          = sanitize_text_field( $input['ai_model'] ?? 'claude-sonnet-4-5-20250929' );
		$clean['ai_expansion_model'] = sanitize_text_field( $input['ai_expansion_model'] ?? '' );
		$clean['ai_base_url']       = sanitize_text_field( $input['ai_base_url'] ?? '' );

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

		// Indexer.
		$clean['indexer'] = in_array( $input['indexer'] ?? '', array( 'auto', 'php', 'binary' ), true )
			? $input['indexer']
			: 'auto';

		// Memory budget — accepts named profiles or raw byte strings (e.g. "256M").
		$raw_budget = sanitize_text_field( $input['memory_budget_profile'] ?? '' );
		$clean['memory_budget_profile'] = self::is_valid_memory_budget( $raw_budget ) ? $raw_budget : 'conservative';

		// Chunk size — empty string means "use profile default".
		$raw_chunk = $input['chunk_size'] ?? '';
		if ( '' === $raw_chunk || null === $raw_chunk ) {
			$clean['chunk_size'] = '';
		} else {
			$clean['chunk_size'] = max( 1, (int) $raw_chunk );
		}

		// Pagefind paths.
		$clean['pagefind_binary']    = sanitize_text_field( $input['pagefind_binary'] ?? 'pagefind' );
		$clean['build_dir']          = wp_normalize_path( $input['build_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/build' );
		$clean['output_dir']         = wp_normalize_path( $input['output_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/pagefind' );
		$clean['auto_rebuild']       = ! empty( $input['auto_rebuild'] );
		$clean['auto_rebuild_delay'] = max( 60, min( 3600, (int) ( $input['auto_rebuild_delay'] ?? 300 ) ) );

		// Scoring — all 12 fields.
		$clean['title_match_boost']          = max( 0.0, min( 10.0, (float) ( $input['title_match_boost'] ?? 1.0 ) ) );
		$clean['title_all_terms_multiplier'] = max( 0.0, min( 10.0, (float) ( $input['title_all_terms_multiplier'] ?? 1.5 ) ) );
		$clean['content_match_boost']        = max( 0.0, min( 10.0, (float) ( $input['content_match_boost'] ?? 0.4 ) ) );
		$clean['recency_boost_max']          = max( 0.0, min( 5.0, (float) ( $input['recency_boost_max'] ?? 0.5 ) ) );
		$clean['recency_half_life_days']     = max( 1, min( 3650, (int) ( $input['recency_half_life_days'] ?? 365 ) ) );
		$clean['recency_penalty_after_days'] = max( 0, min( 7300, (int) ( $input['recency_penalty_after_days'] ?? 1825 ) ) );
		$clean['recency_max_penalty']        = max( 0.0, min( 1.0, (float) ( $input['recency_max_penalty'] ?? 0.3 ) ) );
		$clean['expand_primary_weight']      = max( 0.0, min( 1.0, (float) ( $input['expand_primary_weight'] ?? 0.5 ) ) );

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

		$clean['recency_strategy'] = in_array( $input['recency_strategy'] ?? '', array( 'exponential', 'linear', 'step', 'none', 'custom' ), true )
			? $input['recency_strategy']
			: 'exponential';

		$curve_raw              = $input['recency_curve'] ?? '';
		$curve_decoded          = json_decode( $curve_raw, true );
		$clean['recency_curve'] = is_array( $curve_decoded ) ? $curve_decoded : array();

		// Display — all 5 fields.
		$clean['excerpt_length']       = max( 50, min( 1000, (int) ( $input['excerpt_length'] ?? 300 ) ) );
		$clean['results_per_page']     = max( 5, min( 100, (int) ( $input['results_per_page'] ?? 10 ) ) );
		$clean['max_pagefind_results'] = max( 10, min( 500, (int) ( $input['max_pagefind_results'] ?? 50 ) ) );
		$clean['ai_summary_top_n']     = max( 1, min( 20, (int) ( $input['ai_summary_top_n'] ?? 10 ) ) );
		$clean['ai_summary_max_chars'] = max( 500, min( 10000, (int) ( $input['ai_summary_max_chars'] ?? 4000 ) ) );

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
	 * Sanitize a prompt field value.
	 *
	 * If the submitted text matches the built-in default, store empty
	 * string so the prompt automatically picks up future default changes.
	 *
	 * @param string $value     The submitted prompt text.
	 * @param string $key       The settings key (e.g., 'prompt_expand_query').
	 * @return string Sanitized value, or empty if it matches the default.
	 */
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

	private static function sanitize_prompt( string $value, string $key ): string {
		$sanitized = mb_substr( sanitize_textarea_field( $value ), 0, 5000 );
		$default   = self::get_default_prompt_template( $key );
		if ( $default !== '' && trim( $sanitized ) === trim( $default ) ) {
			return '';
		}
		return $sanitized;
	}

	// -----------------------------------------------------------------
	// AJAX: remove legacy DB key
	// -----------------------------------------------------------------

	public static function ajax_remove_db_key(): void {
		check_ajax_referer( 'scolta_remove_db_key' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$settings = get_option( 'scolta_settings', array() );
		unset( $settings['ai_api_key'] );
		update_option( 'scolta_settings', $settings );

		wp_send_json_success( 'API key removed from database' );
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
			wp_send_json_error( array( 'error' => __( 'Insufficient permissions.', 'scolta' ) ), 403 );
		}

		$api_key = Scolta_Ai_Service::get_api_key();
		if ( empty( $api_key ) && ! class_exists( '\WordPress\AI\Client' ) ) {
			wp_send_json_error( array( 'error' => __( 'No API key configured.', 'scolta' ) ) );
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

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		settings_errors( 'scolta_settings' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'scolta_settings_group' );
				do_settings_sections( 'scolta' );
				submit_button( __( 'Save Settings', 'scolta' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Quick Start', 'scolta' ); ?></h2>
			<ol>
				<li><?php printf( /* translators: %s: WP-CLI command */ esc_html__( 'Build the search index: %s', 'scolta' ), '<code>wp scolta build</code>' ); ?></li>
				<li><?php printf( /* translators: %s: shortcode */ esc_html__( 'Add the search UI to any page: %s', 'scolta' ), '<code>[scolta_search]</code>' ); ?></li>
				<li><?php printf( /* translators: %s: WP-CLI command */ esc_html__( 'Check status: %s', 'scolta' ), '<code>wp scolta status</code>' ); ?></li>
			</ol>

			<?php self::render_status_summary(); ?>
		</div>
		<?php
	}

	private static function render_status_summary(): void {
		$settings        = get_option( 'scolta_settings', array() );
		$build_dir       = $settings['build_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/build';
		$output_dir      = $settings['output_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/pagefind';
		$indexer_setting = $settings['indexer'] ?? 'auto';

		$binary_resolver  = new \Tag1\Scolta\Binary\PagefindBinary(
			configuredPath: $settings['pagefind_binary'] ?? null,
			projectDir: SCOLTA_PLUGIN_DIR,
		);
		$binary_status    = $binary_resolver->status();
		$binary_available = $binary_status['available'];

		// PHP pipeline is active when forced, or when auto-detect finds no binary.
		$uses_php_pipeline = ( 'php' === $indexer_setting )
			|| ( 'auto' === $indexer_setting && ! $binary_available );

		echo '<h2>' . esc_html__( 'Index Status', 'scolta' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width: 600px;">';

		// Tracker.
		if ( \Scolta_Tracker::table_exists() ) {
			$pending = \Scolta_Tracker::get_pending_count();
			echo '<tr><td>' . esc_html__( 'Pending changes', 'scolta' ) . '</td>';
			echo '<td>' . esc_html( $pending ) . '</td></tr>';
		} else {
			echo '<tr><td>' . esc_html__( 'Tracker', 'scolta' ) . '</td>';
			echo '<td><span style="color: #d63638;">' . esc_html__( 'Table missing — deactivate and reactivate the plugin', 'scolta' ) . '</span></td></tr>';
		}

		// Build directory — the binary pipeline writes intermediate HTML files here.
		// The PHP pipeline writes the index format directly; no HTML staging files are produced.
		if ( ! $uses_php_pipeline ) {
			if ( is_dir( $build_dir ) ) {
				$glob_result = glob( $build_dir . '/*.html' );
				$html_count  = count( ! empty( $glob_result ) ? $glob_result : array() );
				echo '<tr><td>' . esc_html__( 'Exported HTML files', 'scolta' ) . '</td>';
				echo '<td>' . esc_html( $html_count ) . '</td></tr>';
			} else {
				echo '<tr><td>' . esc_html__( 'Build directory', 'scolta' ) . '</td>';
				echo '<td>' . esc_html__( 'Not created yet', 'scolta' ) . '</td></tr>';
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
			echo '<tr><td>' . esc_html__( 'Index fragments', 'scolta' ) . '</td>';
			echo '<td>' . esc_html( $fragment_count ) . '</td></tr>';
			echo '<tr><td>' . esc_html__( 'Last built', 'scolta' ) . '</td>';
			echo '<td>' . esc_html( $mtime ? wp_date( 'Y-m-d H:i:s', $mtime ) : __( 'Unknown', 'scolta' ) ) . '</td></tr>';
		} else {
			echo '<tr><td>' . esc_html__( 'Pagefind index', 'scolta' ) . '</td>';
			echo '<td>' . esc_html__( 'Not built yet — run: wp scolta build', 'scolta' ) . '</td></tr>';
		}

		// Active indexer.
		if ( 'php' === $indexer_setting ) {
			$active_indexer = __( 'PHP indexer (forced)', 'scolta' );
		} elseif ( 'binary' === $indexer_setting ) {
			$active_indexer = $binary_available
				? __( 'Pagefind binary', 'scolta' )
				: __( 'Pagefind binary (not found — check binary path)', 'scolta' );
		} else {
			$active_indexer = $binary_available
				? __( 'Pagefind binary (auto-detected)', 'scolta' )
				: __( 'PHP indexer (Pagefind binary not found)', 'scolta' );
		}
		echo '<tr><td>' . esc_html__( 'Active indexer', 'scolta' ) . '</td>';
		echo '<td>' . esc_html( $active_indexer ) . '</td></tr>';

		// AI provider.
		if ( class_exists( '\WordPress\AI\Client' ) ) {
			echo '<tr><td>' . esc_html__( 'AI Provider', 'scolta' ) . '</td>';
			echo '<td>' . esc_html__( 'WordPress AI Client SDK (WP 7.0+)', 'scolta' ) . '</td></tr>';
		} else {
			$provider = $settings['ai_provider'] ?? 'anthropic';
			$source   = Scolta_Ai_Service::get_api_key_source();
			echo '<tr><td>' . esc_html__( 'AI Provider', 'scolta' ) . '</td>';
			echo '<td>' . esc_html( ucfirst( $provider ) );
			if ( $source === 'none' ) {
				echo ' <span style="color: #d63638;">(' . esc_html__( 'no API key', 'scolta' ) . ')</span>';
			} elseif ( $source === 'database' ) {
				echo ' <span style="color: #dba617;">(' . esc_html__( 'key in DB — migrate to env var', 'scolta' ) . ')</span>';
			}
			echo '</td></tr>';
		}

		echo '</table>';

		// Rebuild Now button.
		echo '<p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		wp_nonce_field( 'scolta_rebuild_now', 'scolta_rebuild_nonce' );
		echo '<input type="hidden" name="action" value="scolta_rebuild_now">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Rebuild Index Now', 'scolta' ) . '</button>';
		echo '</form>';
		echo '&nbsp;<span class="description">' . esc_html__( 'Runs a full index rebuild (equivalent to wp scolta build). Large sites may time out — use WP-CLI for those.', 'scolta' ) . '</span>';
		echo '</p>';
	}

	/**
	 * Show a notice after a "Rebuild Index Now" form submission.
	 */
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
				admin_url( 'admin-post.php?action=scolta_dismiss_rebuild_notice&scolta_notice_id=' . urlencode( $notice_id ) ),
				'scolta_dismiss_' . $notice_id
			);
		}

		$dismiss_link = $dismiss_url
			? ' <a href="' . esc_url( $dismiss_url ) . '" style="margin-left:1em">' . esc_html__( 'Dismiss', 'scolta' ) . '</a>'
			: '';

		$result = $notice['result'] ?? '';

		if ( $result === 'ok' ) {
			$pages = (int) ( $notice['pages'] ?? 0 );
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html(
				sprintf(
				/* translators: %d: number of pages indexed */
					__( 'Scolta index rebuilt successfully. %d pages indexed.', 'scolta' ),
					$pages
				)
			);
			echo wp_kses_post( $dismiss_link );
			echo '</p></div>';
		} elseif ( $result === 'no_content' ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Scolta rebuild: no published content found. Check your post types setting.', 'scolta' );
			echo wp_kses_post( $dismiss_link );
			echo '</p></div>';
		} elseif ( $result === 'no_items' ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Scolta rebuild: no items passed the content filter. Your posts may be too short.', 'scolta' );
			echo wp_kses_post( $dismiss_link );
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'Scolta rebuild failed. Try running wp scolta build from the command line for more details.', 'scolta' );
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
			wp_die( esc_html__( 'You do not have permission to dismiss this notice.', 'scolta' ), 403 );
		}

		$notice_id = sanitize_key( $_GET['scolta_notice_id'] ?? '' );
		check_admin_referer( 'scolta_dismiss_' . $notice_id );

		update_user_meta( get_current_user_id(), 'scolta_dismissed_rebuild_notice', $notice_id );

		wp_safe_redirect( admin_url( 'options-general.php?page=scolta' ) );
		exit;
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

		$source = Scolta_Ai_Service::get_api_key_source();
		if ( $source === 'none' ) {
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p>' . wp_kses_post(
				sprintf(
					/* translators: 1: environment variable name, 2: URL to settings page */
					__( '<strong>Scolta AI Search</strong> needs an API key for AI features. Set the %1$s environment variable, or <a href="%2$s">view setup instructions</a>.', 'scolta' ),
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
					__( '<strong>Scolta AI Search:</strong> Your API key is stored in the database, which is insecure. <a href="%s">Migrate to an environment variable</a>.', 'scolta' ),
					esc_url( admin_url( 'options-general.php?page=scolta' ) )
				)
			) . '</p>';
			echo '</div>';
		}

		// Show upgrade notice when the Pagefind binary is not installed.
		$settings        = get_option( 'scolta_settings', array() );
		$indexer_setting = $settings['indexer'] ?? 'auto';
		if ( $indexer_setting !== 'php' ) {
			$resolver      = new \Tag1\Scolta\Binary\PagefindBinary(
				configuredPath: $settings['pagefind_binary'] ?? null,
				projectDir: SCOLTA_PLUGIN_DIR,
			);
			$binary_status = $resolver->status();
			if ( ! $binary_status['available'] ) {
				echo '<div class="notice notice-info is-dismissible">';
				echo '<p>' . wp_kses_post(
					sprintf(
					/* translators: %s: shell command */
						__( '<strong>Scolta:</strong> Pagefind binary not found. Using PHP indexer (14 languages). For faster indexing and 33+ language support, install Pagefind: %s', 'scolta' ),
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
		wp_add_dashboard_widget(
			'scolta_dashboard_widget',
			__( 'Scolta Search', 'scolta' ),
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
		$index_exists = $health['index_exists'] ?? false;
		$last_build   = $health['index']['last_modified'] ?? null;
		$page_count   = $health['index']['fragment_count'] ?? 0;

		if ( $index_exists ) {
			$age = $last_build ? human_time_diff( strtotime( $last_build ) ) . ' ' . __( 'ago', 'scolta' ) : __( 'unknown', 'scolta' );
			printf(
				'<p><strong>%s</strong> %s</p>',
				esc_html__( 'Index:', 'scolta' ),
				/* translators: 1: number of pages, 2: human time string */
				esc_html( sprintf( __( '%1$d pages, last built %2$s', 'scolta' ), $page_count, $age ) )
			);
		} else {
			echo '<p><strong>' . esc_html__( 'Index:', 'scolta' ) . '</strong> ' . esc_html__( 'Not built yet', 'scolta' ) . '</p>';
		}

		// AI status.
		$ai_configured = \Scolta_Ai_Service::get_api_key_source() !== 'none';
		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html__( 'AI:', 'scolta' ),
			esc_html( $ai_configured ? __( 'Configured', 'scolta' ) : __( 'Not configured', 'scolta' ) )
		);

		// Rebuild button.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="scolta_rebuild_now">';
		wp_nonce_field( 'scolta_rebuild_now', 'scolta_rebuild_nonce' );
		submit_button( __( 'Rebuild Now', 'scolta' ), 'secondary', 'submit', false );
		echo '</form>';

		// Link to full settings.
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'options-general.php?page=scolta' ) ),
			esc_html__( 'Settings →', 'scolta' )
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
		$settings   = get_option( 'scolta_settings', array() );
		$output_dir = $settings['output_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/pagefind';
		$index_file = $output_dir . '/pagefind.js';

		if ( ! file_exists( $index_file ) ) {
			return array(
				'index_exists' => false,
				'index'        => array(
					'fragment_count' => 0,
					'last_modified'  => null,
				),
			);
		}

		$mtime          = filemtime( $index_file );
		$glob_result    = glob( $output_dir . '/fragment/*' );
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
			wp_die( esc_html__( 'You do not have permission to rebuild the Scolta index.', 'scolta' ), 403 );
		}

		check_admin_referer( 'scolta_rebuild_now', 'scolta_rebuild_nonce' );

		$settings   = get_option( 'scolta_settings', array() );
		$output_dir = $settings['output_dir'] ?? wp_upload_dir()['basedir'] . '/scolta/pagefind';

		$redirect = admin_url( 'options-general.php?page=scolta' );

		// Each rebuild gets a unique ID so per-user dismissals of old notices
		// don't suppress the new one.
		$notice_id = 'scolta_rebuild_' . bin2hex( random_bytes( 8 ) );

		// Clear any previous notice (and its dismissal state — new notice_id handles that).
		delete_transient( 'scolta_rebuild_notice' );

		try {
			$raw_items = \Scolta_Content_Gatherer::gather();

			if ( empty( $raw_items ) ) {
				set_transient(
					'scolta_rebuild_notice',
					array(
						'result' => 'no_content',
						'notice_id' => $notice_id,
					),
					DAY_IN_SECONDS * 7
				);
				wp_safe_redirect( $redirect );
				exit;
			}

			$exporter = new \Tag1\Scolta\Export\ContentExporter( $output_dir );
			$items    = $exporter->exportToItems( $raw_items );

			if ( empty( $items ) ) {
				set_transient(
					'scolta_rebuild_notice',
					array(
						'result' => 'no_items',
						'notice_id' => $notice_id,
					),
					DAY_IN_SECONDS * 7
				);
				wp_safe_redirect( $redirect );
				exit;
			}

			$upload_dir = wp_upload_dir();
			$state_dir  = $upload_dir['basedir'] . '/scolta/state';
			$indexer    = new \Tag1\Scolta\Index\PhpIndexer( $state_dir, $output_dir, wp_salt( 'auth' ) );

			$chunks = array_chunk( $items, 100 );
			foreach ( $chunks as $i => $chunk ) {
				$indexer->processChunk( $chunk, $i, count( $items ) );
			}

			$result = $indexer->finalize();

			if ( $result->success ) {
				$generation = (int) get_option( 'scolta_generation', 0 );
				update_option( 'scolta_generation', $generation + 1 );
				$notice = array(
					'result'    => 'ok',
					'pages'     => $result->pageCount,
					'notice_id' => $notice_id,
				);
				set_transient( 'scolta_rebuild_notice', $notice, DAY_IN_SECONDS * 7 );
				wp_safe_redirect( $redirect );
			} else {
				set_transient(
					'scolta_rebuild_notice',
					array(
						'result' => 'error',
						'notice_id' => $notice_id,
					),
					DAY_IN_SECONDS * 7
				);
				wp_safe_redirect( $redirect );
			}
		} catch ( \Throwable $e ) {
			set_transient(
				'scolta_rebuild_notice',
				array(
					'result' => 'error',
					'notice_id' => $notice_id,
				),
				DAY_IN_SECONDS * 7
			);
			wp_safe_redirect( $redirect );
		}

		exit;
	}
}

// Initialize admin hooks.
Scolta_Admin::init();
