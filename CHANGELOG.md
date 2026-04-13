# Changelog

All notable changes to scolta-wp will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased] (0.2.0-dev)

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
