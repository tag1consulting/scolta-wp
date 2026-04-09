# Changelog

All notable changes to scolta-wp will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased] (0.2.0-dev)

### Added

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
