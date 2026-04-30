# Changelog

All notable changes to scolta-wp will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased]

_No changes yet._

## [0.3.7] - 2026-04-30

### Fixed
- **JS/CSS/WASM assets are now bundled in `assets/`** and referenced from there instead of from `vendor/tag1/scolta-php/assets/`. Previously, `class-scolta-shortcode.php` pointed at the vendor path which does not exist when scolta-wp is installed as a dependency of a Composer-managed WordPress site (the plugin's own `vendor/` directory is not committed). Assets are now at `assets/js/scolta.js`, `assets/css/scolta.css`, and `assets/wasm/`.

### Improved
- Documentation: emphasized search capabilities for dynamic WordPress sites, cross-platform messaging.

## [0.3.6] - 2026-04-29

### Added
- **`ai_expansion_model` setting**: Optional model for query expansion. When set, expand-query uses this model; summarize and follow-up continue using `ai_model`. Leave empty (default) for the previous single-model behavior. Configurable via the AI settings section in the admin panel.

## [0.3.5] - 2026-04-28

### Fixed
- **Admin UI render and sanitize fallbacks now match registered defaults** — `expand_primary_weight`, `ai_summary_top_n`, and `ai_summary_max_chars` fallbacks were still using old values (0.7, 5, 2000). Admin UI now shows the correct defaults (0.5, 10, 4000) for installations that have not explicitly saved these settings, and sanitization produces the correct value when the field is absent from submitted input.

### Changed
- **Default `expand_primary_weight` lowered to 0.5** (was 0.7) — gives AI-expanded terms more influence for intent-based queries. To restore the previous behavior, set `expand_primary_weight: 0.7` in config.
- **Default `ai_summary_top_n` raised to 10** (was 5) — AI sees more results and curates better for constraint queries and diverse result sets.
- **Default `ai_summary_max_chars` raised to 4000** (was 2000) — supports the increased `ai_summary_top_n` with enough excerpt content for quality curation.

## [0.3.4] - 2026-04-27

### Fixed
- **Hygiene:** Added `JSON_THROW_ON_ERROR` to `json_decode` on GitHub API response in `ScoltaCli::downloadPagefind()` — malformed API responses now produce a clear error instead of silently continuing with a null object.
- **Hygiene:** Added TOCTOU-safe comment to intentional `@rmdir` call in `uninstall.php`.
- **Hygiene:** Added source-parse test ensuring `json_decode` on remote API responses always uses `JSON_THROW_ON_ERROR`.
- Fix: Rebuild notice dismiss never persisted — `uniqid(..., true)` generated IDs containing periods, which `sanitize_key()` stripped, causing ID mismatch between storage and lookup.
- Fix: Dashboard "AI: Not configured" did not detect API keys set via environment variable or wp-config constant — only checked database storage and WP AI Client SDK.
- Fix: Plugin description truncated on Plugins page — multi-line header not parsed by WordPress.

### Added
- **`Scolta_Cache_Driver` behavior tests.** New `ScoltaCacheBehaviorTest`: verifies the driver contract (get/set/miss/array values) and end-to-end handler+driver caching — second call to `handleExpandQuery`/`handleSummarize` serves from the WordPress transient cache (AI called once), while `cacheTtl=0` calls the AI service both times.
- **Config test gap fixes.** Added `test_config_maps_custom_stop_words` (property + JS output); `test_config_maps_ai_provider`, `test_config_maps_ai_model`, `test_config_maps_ai_base_url`, and `test_empty_ai_base_url_omitted_from_client_config` (AI client config pipeline).
- **Custom prompt tests (Phase 4).** Added `test_get_summarize_prompt_uses_custom_override` and `test_get_follow_up_prompt_uses_custom_override` confirming all three prompt types return their override raw without `{SITE_NAME}` substitution.
- **Cache behavior tests (Phase 3).** Added `test_config_maps_cache_ttl` and `test_config_maps_cache_ttl_zero_disables_caching` to confirm `cache_ttl` is mapped through to `ScoltaConfig::$cacheTtl` including the zero/disable case.
- **Display behavior tests (Phase 2).** Added config-mapping tests for all five display fields (`excerpt_length`, `results_per_page`, `max_pagefind_results`, `ai_summary_top_n`, `ai_summary_max_chars`) and an end-to-end test that they propagate to `toJsScoringConfig()` output.
- **Scoring behavior tests (Phase 1).** Added config-mapping tests for phrase proximity fields (`phrase_adjacent_multiplier`, `phrase_near_multiplier`, `phrase_near_window`, `phrase_window`), `ai_languages`, `recency_strategy`, and `recency_curve` to `AiServiceTest`.

## [0.3.3] - 2026-04-26

### Added
- **`Scolta_Logger`**: PSR-3 fallback logger for non-CLI contexts (cron, Action Scheduler, AJAX). Routes warning/error/critical to `error_log()`; info and debug are dropped. Loaded unconditionally so `IndexBuildOrchestrator` output is not silently discarded in background builds.
- **`--strict-errors` flag on `wp scolta build`**: Makes `Scolta_WP_CLI_Logger` route PSR-3 error/critical/alert/emergency to `WP_CLI::error()` (exits with non-zero) instead of the default `WP_CLI::warning()` (non-fatal). Useful in CI pipelines.
- **Chunk detail via `--debug`**: `Scolta_WP_CLI_Progress_Reporter::advance()` now passes the detail string (e.g. `"Chunk 5 (100 pages)"`) to `WP_CLI::debug()`, visible with `--debug`.

### Changed
- **`wp scolta build` and `wp scolta diagnose`**: Budget and chunk-size resolution now delegated to `MemoryBudgetConfig::fromCliAndConfig()` (scolta-php), removing ~8 lines of duplicated precedence logic from each command.
- **`do_build_php()` intent construction**: Replaced inline `match(true)` with `BuildIntentFactory::fromFlags()` (scolta-php).
- **Anti-pattern CI check.** New `antipatterns` CI job catches unbounded `WP_Query` (`posts_per_page => -1`).
- **scolta-php dependency bumped to `^0.3.3`** (atomic manifest writes, CRC32 chunk validation, stale lock detection).

## [0.3.2] - 2026-04-24

Coordinated release. Fixes memory and CLI visibility regressions surfaced by a 44,107-page real-world WordPress deployment.

### Fixed
- **Silent CLI during 52-minute build**: `do_build_php()` was passing neither a `LoggerInterface` nor a `ProgressReporterInterface` to `IndexBuildOrchestrator::build()`, so all progress output went to `NullLogger`/`NullProgressReporter`. Added `Scolta_WP_CLI_Logger` (PSR-3 bridge to `WP_CLI::log`/`::warning`/`::debug`) and `Scolta_WP_CLI_Progress_Reporter` (wraps `WP_CLI\Utils\make_progress_bar`); both are now passed to `build()`. (#6)
- **Peak RAM on large corpora (observed 2,870 MB on 44k-page site)**: `Scolta_Content_Gatherer::gather()` used `WP_Query( 'posts_per_page' => -1 )` and returned a materialized `ContentItem[]` array, pre-loading every post into memory before the streaming indexer ran. Now returns a `\Generator` that paginates `WP_Query` in batches of 100 and calls `wp_cache_flush_group('posts')` between batches. Posts now flow through the pipeline one batch at a time. The gather-upstream eager-load was always present; 0.3.1 appeared to fix OOM because the streaming finalize removed the finalize-time doubling, but gather-side was unfixed until now. (#6)

### Added
- **Flexible memory budget and chunk size**: The Memory Budget admin field now accepts profile names (`conservative`, `balanced`, `aggressive`) **or** a raw byte value (`256M`, `1G`) in addition to the three preset profiles. A new **Chunk Size** admin field lets you set pages-per-chunk independently of the memory budget — leave it blank to use the profile default (50/200/500). Both values can also be overridden per-run via `--memory-budget=<budget>` and `--chunk-size=<n>` on `wp scolta build` and `wp scolta diagnose`. (#8)
- **`wp scolta diagnose`**: New WP-CLI command that profiles the PHP indexer pipeline in three isolated phases — gather (WP_Query + `apply_filters('the_content')` + `get_permalink`), HtmlCleaner, and indexer — on a configurable post sample (default 500). Prints per-phase ms/post, projects the full-corpus duration, and emits a recommendation identifying the dominant bottleneck. Use this to determine whether a slow build is caused by WordPress content filters, HTML cleaning, or the indexer itself. (#8)
- **`Scolta_Content_Gatherer::gatherCount(): int`**: COUNT-only query using `WP_Query` with `fields='ids'`, returning just the integer count. Used by `do_build_php()` for the early-exit check and to pre-size `BuildIntent` without loading post content. (#6)

## [0.3.1] - 2026-04-23

### Fixed
- **Release packaging**: Release workflow now triggers on both `v0.x.x` and bare `0.x.x` tag formats. The 0.3.0 release was tagged without the `v` prefix, so no workflow ran and no binary assets were attached — making every download fatal on activation (missing `vendor/autoload.php`). Workflow now accepts both formats.

### Added
- **Zip structure regression test**: New `validate-zip` CI job downloads the release asset after each release, asserts the top-level directory is `scolta-wp/`, and asserts `vendor/autoload.php` and `scolta.php` are present. Prevents future broken releases from shipping undetected.
- **Memory budget profile selector**: Admin settings page (Pagefind section) now includes a Memory Budget select field so admins can persist their preferred profile without using the `--memory-budget` CLI flag. `wp scolta build` reads this value as the default.
- **Memory budget UI clarity**: The memory budget field now explains that the budget is advisory within the existing PHP `memory_limit` — admins do not need to edit `php.ini`. The current PHP memory limit is displayed inline. A warning appears when the selected profile's target RAM exceeds 70% of the detected `memory_limit`.

## [0.3.0] - 2026-04-23

### Added
- **`--memory-budget=<profile>` flag**: Pass `conservative` (default), `balanced`, or `aggressive` to `wp scolta build`.
- **`--resume` flag**: Resume a previously interrupted PHP index build.
- **`--restart` flag**: Discard interrupted state and force a clean rebuild.

### Changed
- **`do_build_php()`** rewritten to use `IndexBuildOrchestrator::build()` — 85 lines down to ~40.
- Inherits all scolta-php 0.3.0 improvements: `MemoryBudget`, `BuildIntent`, `BuildCoordinator`, streaming pipeline, OOM fix.

### Fixed
- **Status command indexer section**: `wp scolta status` now shows `--- Indexer ---` (was `--- Pagefind Binary ---`) with active indexer selection logic matching the Laravel/Drupal adapters.

## [0.2.4] - 2026-04-21

### Changed
- Inherits all scolta-php 0.2.4 fixes and features (phrase-proximity scoring, WASM config key fix, quoted-phrase forced-mode, second WASM rebuild)

### Added
- **REST API smoke test suite** (`tests/RestApiSmokeTest.php`): Eight test methods covering all six registered WP REST routes (`/expand-query`, `/summarize`, `/followup`, `/health`, `/build-progress`, `/rebuild-now`). Guards against handler crashes, verifies valid HTTP status codes, asserts `/health` and `/build-progress` return 200 with array bodies, and asserts `/rebuild-now` returns 409 when a build lock is already held. Previously, only four routes were covered; `build-progress` and `rebuild-now` had no handler-invocation tests.

### Fixed
- **`test_no_api_key_returns_error` test failure**: Test now properly clears `SCOLTA_API_KEY` from `getenv`, `$_ENV`, and `$_SERVER` before simulating the no-key guard, restoring all values in a `finally` block. Previously the test assumed no env key was set, but DDEV injects the key into the container environment.
- **Version strings**: Plugin header, `SCOLTA_VERSION` constant, and `composer.json` all bumped to `0.2.4-dev`; `VersionConsistencyTest` guards against future drift.
- **CLI handler invocation**: Added `CliIndexerDispatchTest` confirming `do_build_php()` and `do_build_binary()` are actually invoked. `do_build_php`/`do_build_binary` promoted from `private` to `protected` to allow the test-double subclass.
- **Admin rebuild notice persistence**: Notice no longer vanishes after first page view. Transient is now read without immediate deletion; per-user dismissal is tracked via user meta (`scolta_dismissed_rebuild_notice`) keyed to a unique `notice_id`. TTL extended to 7 days.
- **CLI indexer dispatch**: `wp scolta build` now correctly uses the admin-configured indexer when no `--indexer` flag is passed. The WP-CLI docblock had `default: auto` in the `[--indexer]` parameter block, causing WP-CLI to inject `$assoc_args['indexer'] = 'auto'` on every invocation — making the admin setting permanently unreachable. Removed the `default:` line so the flag is only set when explicitly passed.
- **`rebuild-index` with PHP pipeline**: `wp scolta rebuild-index` now emits a clear error when the active indexer is set to PHP, explaining that the command is binary-only (the PHP pipeline writes the index directly; no HTML staging files exist to re-index). Suggests `wp scolta build` instead.
- **Admin "Exported HTML files" counter**: The dashboard widget no longer shows a misleading `0` for sites using the PHP indexer. The HTML file count row is now hidden when the PHP pipeline is active (forced or auto-resolved), since the PHP indexer writes the index format directly without intermediate HTML staging files.
- **Shortcode and admin status: PHP vs binary pipeline index detection**: `[scolta_search]` and the admin status panel now correctly detect whether the index was built by the PHP pipeline (`{output_dir}/pagefind/pagefind.js`) or the binary pipeline (`{output_dir}/pagefind.js`). Previously both always checked only the flat path, so PHP-pipeline sites never rendered the search UI (shortcode returned the "not built" error for admins, empty for visitors) and never showed index stats in the admin.

## [0.2.3] - 2026-04-17

### Fixed
- Admin rebuild notice now shows exactly once after rebuild (replaced query-param approach with 60-second transient)
- CLI `--indexer` flag now correctly falls back to the admin setting default when the flag is absent

### Added
- "Test Connection" button in the AI settings panel (LLM endpoint + API key verification)

### Changed
- Inherits all scolta-php 0.2.3 fixes and features (filter sidebar, N-set merge, AI context, PII sanitization, priority pages)

## [0.2.2] - 2026-04-16

### Added

- **Scoring language:** Settings field to select the ISO 639-1 language code for stop word filtering (`language`).
- **Custom stop words:** Text field for comma-separated extra stop words (`custom_stop_words`).
- **Recency strategy:** Select field for recency decay function — `exponential`, `linear`, `step`, `none`, or `custom` (`recency_strategy`).
- **Custom recency curve:** Text field for JSON `[[days, boost], …]` control points (`recency_curve`).
- All four new fields are sanitized and validated in `sanitize_settings()`.
- **PHP indexer progress bar:** `wp scolta build --indexer=php` now shows a progress bar while processing index chunks via `WP_CLI\Utils\make_progress_bar()`.
- **Per-chunk timing:** PHP indexer now logs elapsed seconds per chunk so stalled chunks are identifiable.
- **`BUILDING.md`:** Documents the distribution ZIP build process for WordPress.org submission.

### Fixed

- **Managed hosting compatibility:** `build_dir` and `output_dir` now default to `wp_upload_dir()['basedir']/scolta/{build,pagefind}` instead of `WP_CONTENT_DIR` and `ABSPATH` paths that are read-only on many managed hosts. Activation migrates existing installs with old defaults automatically.
- **Download-pagefind install location:** `wp scolta download-pagefind` now installs into `plugin-dir/.scolta/bin/` via `PagefindBinary::downloadTargetDir()` — the same path `resolve()` searches. Previously installed to `plugin-dir/bin/` (unreachable by resolver) or `ABSPATH/.scolta/bin/` (may be read-only). Updates `.gitignore` to `/.scolta/`.
- **Fatal error fix:** Admin settings page ("Active indexer" row) and dashboard widget no longer crash with a fatal error when `Scolta_Admin` calls `$resolver->isExecutable()` (private). Changed to `$resolver->status()['available']`, the correct public API.
- **is_executable() check after chmod:** `download-pagefind` now warns if execute permission could not be set after `chmod(0755)`.
- **CLI PHP noise:** All WP-CLI command handlers now suppress `display_errors` during execution to prevent PHP notices from corrupting WP-CLI output.
- **Pagefind subprocess timeout:** `run_pagefind()` now uses `proc_open()` with a 5-minute timeout and non-blocking stream reads instead of `shell_exec()`, preventing hung builds from blocking indefinitely.
- **First-run auto-setup:** `scolta_activate()` now calls `wp_mkdir_p()` to create index directories in uploads, auto-selects the PHP indexer when no Pagefind binary is found on new installs, and migrates old `WP_CONTENT_DIR`/`ABSPATH`-based directory defaults on updates.
- **Release ZIP structure:** Release workflow now creates a `scolta-wp/` folder inside the ZIP archive, which is required by the WordPress plugin updater. Previously the archive was flat and could not be installed via the admin UI.
- **CI linting enforced:** Removed `continue-on-error: true` from the linting step in CI. Fixed all WordPress coding standards violations (tabs, Yoda conditions, missing translator comments, unescaped output, SQL placeholders, short ternaries). Lint now fails the build if violations are introduced.
- **Uninstall cleanup:** `uninstall.php` now removes the `uploads/scolta/` directory tree in addition to options and the tracker table.
- **Version floor:** Plugin header `Requires at least` lowered from `6.5` to `6.0` — no WP 6.5+ APIs are used. Matches README.

## [0.2.1] - 2026-04-15

### Fixed

- **Security:** `get_client_ip()` no longer trusts `X-Forwarded-For` by default. Proxy header trust now requires explicit opt-in via the `scolta_trust_proxy_headers` option, preventing IP spoofing in rate-limit bypass attacks.
- **UX:** Admin notice for missing Pagefind binary corrected — "14 languages" instead of "English-only"; message now accurately describes PHP indexer capability.

## [0.2.0] - 2026-04-13

### Fixed

- **Security:** AI endpoints (`/expand-query`, `/summarize`, `/followup`) had no rate limiting, allowing API quota exhaustion. Added per-IP rate limiting via WordPress transients: default 10 requests/minute/IP, configurable via `scolta_ai_rate_limit` filter. Returns 429 with `Retry-After: 60` header when exceeded.
- **UX:** When the Pagefind binary is not installed and Scolta falls back to the PHP indexer, the admin settings page and Plugins page now show an info notice explaining the limitation and how to install the binary.

### Added

- First-run auto-build: plugin activation now queues an initial index build via Action Scheduler (if available), so sites get a working search index immediately after install
- Activation admin notice: one-time info banner after activation confirms the index build is queued and links to settings; suggests Action Scheduler install or `wp scolta build` if Action Scheduler is not present
- Index-missing validation in `[scolta_search]` shortcode: admins see a styled warning with a link to build the index; non-admins see nothing until the index exists
- Action Scheduler integration: `Scolta_Rebuild_Scheduler` processes PHP index builds in background chunks via WordPress Action Scheduler, avoiding PHP timeout issues on large sites
- Auto-rebuild: `Scolta_Auto_Rebuild` listens for content changes (`save_post`, `before_delete_post`) and schedules a debounced rebuild after a configurable delay (default 300 seconds)
- REST endpoint `GET /wp-json/scolta/v1/build-progress` returns current build status with stale lock detection (admin only)
- REST endpoint `POST /wp-json/scolta/v1/rebuild-now` triggers an immediate background rebuild via Action Scheduler (admin only)
- Plugin deactivation now clears all Action Scheduler actions, build locks, and build state
- PHP indexer integration: `wp scolta build --indexer=php` builds the search index using the pure-PHP indexer from scolta-php, eliminating the need for the Pagefind binary
- `--indexer` flag on `wp scolta build` command to select indexing pipeline (auto/php/binary), overriding the admin setting
- `--force` flag on `wp scolta build` to skip fingerprint check and force rebuild with the PHP indexer
- `Scolta_Content_Gatherer` class to gather WordPress content as `ContentItem` objects for the PHP indexer pipeline
- Indexer dropdown setting in admin Settings > Scolta > Pagefind section (Auto, PHP, Binary)
- Auto-routing: when indexer is set to "auto" and the Pagefind binary is unavailable, the PHP indexer is used automatically

### Changed

- Migrated from server-side WASM scoring to client-side WASM: scoring now runs in the browser via WebAssembly instead of requiring Extism/FFI on the server
- Shortcode now passes `wasmPath` in the localized JS config, pointing to the WASM JS glue file at `vendor/tag1/scolta-php/assets/wasm/scolta_core.js`
- Server-side Extism shared library and PHP FFI are no longer required
- Prompt resolution now uses pure PHP (`DefaultPrompts::resolve()`) — no WASM or FFI dependency
- AI service reads cached prompts from `scolta_resolved_prompts` option with fallback to `DefaultPrompts::resolve()`

### Removed

- Extism runtime install step from CI workflow (no longer needed)
- PHP FFI extension and `ffi.enable=true` from CI setup
- `continue-on-error: true` from CI lint step (linting is now enforced)
- Extism/FFI skip guards from test cases (prompts are now always available as pure PHP)

### Added

- `ai_languages` setting for multilingual AI response support, configurable via Settings > Scolta (comma-separated language codes)
- `make_handler()` now passes `aiLanguages` from config to `AiEndpointHandler`
- `Scolta_Prompt_Enricher` class using `apply_filters('scolta_prompt', ...)` for site-specific prompt enrichment
- `make_handler()` now passes the enricher to `AiEndpointHandler`

### Previously added

- 7 WP-CLI commands: `scolta build`, `scolta export`, `scolta rebuild-index`, `scolta status`, `scolta clear-cache`, `scolta download-pagefind`, `scolta check-setup`
- `[scolta_search]` shortcode for embedding the search UI on any page
- 4 REST API endpoints: `expand-query`, `summarize`, `followup`, `health` at `/wp-json/scolta/v1/`
- Admin settings page at Settings > Scolta with AI, scoring, display, cache, and prompt configuration
- `Scolta_Cache_Driver` implementing `CacheDriverInterface` for WordPress transients API
- Content change tracking hooks on `save_post`, `delete_post`, and `transition_post_status`
- Incremental build support via `--incremental` flag on build and export commands
- API key sourced from environment variable or `wp-config.php` constant (never stored in database)
- Plugin activation/deactivation hooks for tracker table setup and cleanup
- Settings stored as a single serialized option (`scolta_settings`)
- Asset enqueueing via `wp_enqueue_script` and `wp_enqueue_style` from scolta-php vendor path
