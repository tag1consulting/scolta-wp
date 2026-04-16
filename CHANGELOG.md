# Changelog

All notable changes to scolta-wp will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased]

## [0.2.2] - Unreleased

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
