# Scolta for WordPress

[![CI](https://github.com/tag1consulting/scolta-wp/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-wp/actions/workflows/ci.yml)

Scolta is a browser-side search engine: the index lives in static files, scoring runs in the browser via WebAssembly, and an optional AI layer handles query expansion and summarization. No search server required. "Scolta" is archaic Italian for sentinel — someone watching for what matters.

This plugin is the WordPress adapter. It provides WP-CLI commands, a Settings API page, a `[scolta_search]` shortcode, REST API endpoints, and content change tracking.

## Quick Install

```bash
# 1. Install the plugin (upload via wp-admin or copy to wp-content/plugins/scolta/)
#    The plugin ships with its own vendor directory.

# 2. Activate in wp-admin > Plugins

# 3. Build the search index
wp scolta build

# 4. Add [scolta_search] to any page
```

To enable AI features (query expansion, summarization, follow-up), set the API key before building:

```bash
export SCOLTA_API_KEY=sk-ant-...
```

Or in `wp-config.php`:

```php
define('SCOLTA_API_KEY', 'sk-ant-...');
```

Then configure AI provider, model, and other options at **Settings > Scolta**.

## Verify It Works

```bash
wp scolta check-setup
```

This verifies PHP version, index directories, indexer selection, AI provider configuration, and binary availability. Fix any items marked as failed before proceeding.

Check current index status:

```bash
wp scolta status
```

The REST health endpoint also reports current state:

```
GET /wp-json/scolta/v1/health
```

## Optional Upgrades

### Upgrade to the Pagefind binary indexer

The plugin auto-selects the PHP indexer on managed hosts where `exec()` is disabled. On hosts where you can install binaries, the Pagefind binary is 5–10× faster:

```bash
wp scolta download-pagefind
# or:
npm install -g pagefind
```

Then change **Settings > Scolta > Indexer** to "Auto" or "Binary" and rebuild:

```bash
wp scolta build
```

See [scolta-php README](../scolta-php/README.md) for a full indexer comparison table.

### Enable Action Scheduler for background indexing

Install [Action Scheduler](https://actionscheduler.org/) (or WooCommerce, which bundles it) to get automatic background index builds when content changes. Without it, rebuilds require a manual `wp scolta build` or a cron job.

### Index custom post types

Go to **Settings > Scolta > Content > Post types to index** and add your custom post types (WooCommerce products, CPTs, etc.).

### Extend indexed content

Use the `scolta_content_item` filter to append custom fields before a post is indexed:

```php
add_filter('scolta_content_item', function ($item, $post) {
    $extra = get_field('product_specs', $post->ID);
    if ($extra) {
        $item = new \Tag1\Scolta\Export\ContentItem(
            id:       $item->id,
            title:    $item->title,
            bodyHtml: $item->bodyHtml . '<p>' . esc_html($extra) . '</p>',
            url:      $item->url,
            date:     $item->date,
            siteName: $item->siteName,
        );
    }
    return $item;
}, 10, 2);
```

## Debugging

### "Pagefind binary not found"

On managed hosting (WP Engine, Kinsta, Flywheel, Pantheon), `exec()` is disabled and the binary cannot run. The plugin falls back to the PHP indexer automatically. To confirm:

```bash
wp scolta check-setup
wp scolta status
```

If you want the binary on a host that supports it:

```bash
wp scolta download-pagefind
```

### "AI features not working"

1. Verify API key: `wp scolta check-setup`
2. Clear stale cache: `wp scolta clear-cache`
3. Confirm the model name is current at **Settings > Scolta > AI Provider**

### "No search results"

1. Check index status: `wp scolta status`
2. Run a full rebuild: `wp scolta build`
3. Confirm the Pagefind output directory is web-accessible (check **Settings > Scolta > Pagefind**)
4. Flush rewrite rules: `wp rewrite flush`

### "Build hangs or times out"

The plugin uses `proc_open()` with a 5-minute timeout for Pagefind binary builds. PHP indexer builds run in chunks via Action Scheduler to avoid PHP timeouts. If builds stall:

```bash
wp scolta status        # check for a stale build lock
wp scolta build --force # clear lock and force rebuild
```

### "Fatal error on Settings page after upgrade"

Run `wp scolta check-setup` from CLI to check for configuration issues. If the admin page is unreachable, deactivate and reactivate the plugin to re-run the activation migration.

## WP-CLI Commands

```bash
wp scolta build                    # Full build: mark all content, export HTML, run indexer
wp scolta build --incremental      # Only process tracked changes
wp scolta build --skip-pagefind    # Export HTML without rebuilding index
wp scolta build --indexer=php      # Force PHP indexer regardless of setting
wp scolta build --force            # Skip fingerprint check, force full rebuild
wp scolta export                   # Export content to HTML only
wp scolta export --incremental     # Only export tracked changes
wp scolta rebuild-index            # Rebuild index from existing HTML files
wp scolta status                   # Show tracker, content, index, and AI status
wp scolta clear-cache              # Clear Scolta AI response caches
wp scolta download-pagefind        # Download the Pagefind binary for your platform
wp scolta check-setup              # Verify PHP, indexer, and configuration
```

## REST API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/wp-json/scolta/v1/expand-query` | Expand a search query into related terms |
| POST | `/wp-json/scolta/v1/summarize` | Summarize search results |
| POST | `/wp-json/scolta/v1/followup` | Continue a search conversation |
| GET | `/wp-json/scolta/v1/health` | Health check (indexer status, AI availability) |
| GET | `/wp-json/scolta/v1/build-progress` | Current build status (admin only) |
| POST | `/wp-json/scolta/v1/rebuild-now` | Trigger immediate background rebuild (admin only) |

Endpoints are public by default. Use the `scolta_search_permission` filter to restrict access.

## Requirements

- WordPress 6.0+
- PHP 8.1+

The Pagefind binary is optional — the PHP indexer works without it.

## Testing

**Unit tests** (no WordPress required):

```bash
cd packages/scolta-wp
./vendor/bin/phpunit
```

**Integration tests** (requires DDEV):

```bash
cd test-wordpress-7
ddev wp eval-file tests/integration-test.php
```

## Architecture

```text
scolta-wp (this plugin)            scolta-php              scolta-core (browser WASM)
  WP-CLI commands ───────────> ContentExporter ──────> cleanHtml()
  Scolta_Rest_Api ───────────> AiClient                buildPagefindHtml()
  Scolta_Admin ──────────────> ScoltaConfig
  Scolta_Shortcode ──────────> DefaultPrompts            (runs in browser)
  Scolta_Cache_Driver ───────> CacheDriverInterface     scoreResults()
  Scolta_Rebuild_Scheduler ──> PhpIndexer               mergeResults()
  Scolta_Auto_Rebuild ───────> PagefindBinary
```

This plugin handles WordPress-specific concerns: WP-CLI commands, Settings API, shortcodes, REST endpoints, post hooks for change tracking, Action Scheduler integration, and asset enqueueing. It depends on scolta-php and never on scolta-core directly. Scoring runs client-side via WebAssembly loaded by `scolta.js`.

```text
scolta.php                              Plugin entry point, activation hooks
includes/
  class-scolta-tracker.php              Content change tracking table
  class-scolta-content-gatherer.php     Gathers WP posts as ContentItems
  class-scolta-ai-service.php           AI service wrapper
  class-scolta-rest-api.php             REST endpoint registration
  class-scolta-shortcode.php            [scolta_search] shortcode
  class-scolta-rebuild-scheduler.php    Action Scheduler integration
  class-scolta-auto-rebuild.php         Auto-rebuild on content change
  class-scolta-cache-driver.php         WordPress transients cache adapter
admin/
  class-scolta-admin.php                Settings page (Settings API)
cli/
  class-scolta-cli.php                  WP-CLI commands
vendor/
  tag1/scolta-php/                      Shared PHP library + assets
```

## License

GPL-2.0-or-later
