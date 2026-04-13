# Scolta for WordPress

[![CI](https://github.com/tag1consulting/scolta-wp/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-wp/actions/workflows/ci.yml)

Scolta adds AI-powered search to your WordPress site. Search runs entirely in the browser using Pagefind — no search server needed. Optional AI features handle query expansion, result summarization, and follow-up conversations. Works with any content type, any language.

## Quickstart

```bash
# 1. Install the scolta plugin (upload or Composer)

# 2. Activate in wp-admin > Plugins

# 3. Verify prerequisites
wp scolta check-setup

# 4. Build the search index
wp scolta build

# 5. Add [scolta_search] shortcode to any page.
```

## Configuration

Set the API key to enable AI features:

```bash
export SCOLTA_API_KEY=sk-ant-...
```

Or add to `wp-config.php`:

```php
define('SCOLTA_API_KEY', 'sk-ant-...');
```

Then configure settings at **Settings > Scolta** -- provider, model, scoring, and display options.

See [CONFIG_REFERENCE.md](../../docs/CONFIG_REFERENCE.md) for the full list of settings.

## Prompt Enrichment

The built-in expand, summarize, and follow-up prompts can be customized via the settings page under **Custom Prompts**. Pre-filled defaults make editing easy. You can also set site name and description to give the AI better context about your content. See [ENRICHMENT.md](../../docs/ENRICHMENT.md) for details on prompt customization.

## How It Works

1. **Indexing** -- The `wp scolta build` command exports published posts/pages as HTML files with Pagefind data attributes, then runs the Pagefind CLI to build a static search index.
2. **Search** -- Entirely client-side. The browser loads `pagefind.js`, searches the static index, and scolta.js handles scoring, filtering, and result rendering.
3. **AI features** -- Optional. When an API key is configured, the plugin provides REST API endpoints for query expansion, result summarization, and follow-up conversations powered by Anthropic or OpenAI.

## Architecture

Scolta is a multi-package system. This WordPress plugin is a platform adapter that sits on top of the shared PHP library:

```
scolta-wp (this plugin)            scolta-php              scolta-core (WASM)
  WP-CLI commands ───────────> ContentExporter ──────> cleanHtml()
  Scolta_Ai_Service ─────────> AiClient                buildPagefindHtml()
  Scolta_Admin ──────────────> ScoltaConfig
  Scolta_Shortcode ──────────> DefaultPrompts            (client-side)
  Scolta_Rest_Api ───────────> PagefindBinary           scoreResults()
  Scolta_Cache_Driver ───────> CacheDriverInterface     mergeResults()
```

The WordPress plugin handles CMS-specific concerns: WP-CLI commands, Settings API integration, shortcodes, REST API endpoints, post hooks for change tracking, and asset enqueueing. Scoring runs client-side via WebAssembly loaded by scolta.js. HTML processing runs server-side via scolta-php. Prompt resolution and config generation are pure PHP. This plugin never depends on scolta-core directly.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- Pagefind CLI (`npm install -g pagefind`)

## Installation

1. Install the plugin via Composer or by copying to `wp-content/plugins/scolta/`:

```bash
# The plugin ships with its own vendor directory including scolta-php.
cd wp-content/plugins/scolta
composer install
```

2. Activate the plugin in **Plugins > Installed Plugins**.

## Setup

### 1. Set the API Key (Optional, for AI features)

Set via environment variable (recommended):

```bash
export SCOLTA_API_KEY=sk-ant-...
```

Or add to `wp-config.php`:

```php
define('SCOLTA_API_KEY', 'sk-ant-...');
```

### 2. Build the Search Index

```bash
wp scolta build
```

### 3. Add the Search Shortcode

Create a page and add the shortcode:

```
[scolta_search]
```

### 4. Configure Settings

Go to **Settings > Scolta** to configure AI provider, scoring parameters, display options, and custom prompts.

## Verify Your Setup

After installation, run the setup check to verify all prerequisites:

```bash
wp scolta check-setup
```

This verifies PHP version, Pagefind binary, AI provider configuration, and cache backend. Fix any items marked as failed before proceeding.

## Configuration Details

The settings page at **Settings > Scolta** provides:

- **AI Provider** -- Provider selection (Anthropic/OpenAI), model, feature toggles
- **Content** -- Post types to index, site name and description
- **Pagefind** -- Binary path, build/output directories, auto-rebuild toggle
- **Scoring** -- Title/content match boosts, recency decay, expanded term weights
- **Display** -- Excerpt length, results per page, AI summary parameters
- **Cache** -- TTL for AI query expansion caching
- **Custom Prompts** -- Override the built-in expand, summarize, and follow-up prompts (pre-filled with defaults for easy editing)
- **API Key Status** -- Shows where the key is configured (env var, constant, or not set)

## REST API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/wp-json/scolta/v1/expand-query` | Expand a search query into related terms |
| POST | `/wp-json/scolta/v1/summarize` | Summarize search results |
| POST | `/wp-json/scolta/v1/followup` | Continue a search conversation |

Endpoints are public by default. Filter `scolta_search_permission` to restrict access.

## WP-CLI Commands

```bash
wp scolta build                    # Full build: mark all content, export HTML, run Pagefind
wp scolta build --incremental      # Only process tracked changes
wp scolta build --skip-pagefind    # Export HTML without rebuilding index
wp scolta export                   # Export content to HTML only
wp scolta export --incremental     # Only export tracked changes
wp scolta rebuild-index            # Rebuild Pagefind index from existing HTML
wp scolta status                   # Show tracker, content, index, and AI status
wp scolta clear-cache              # Clear Scolta AI response caches
wp scolta download-pagefind        # Download the Pagefind binary for your platform
wp scolta check-setup              # Verify PHP, Pagefind, and configuration
```

## Content Coverage

By default, Scolta indexes all published posts and pages. You can add custom post types (including WooCommerce products and CPTs) via **Settings > Scolta > Content > Post types to index**.

### What gets indexed

- **Post content** — `the_content` filter is applied before indexing. Any plugin that renders through this filter (ACF blocks, Elementor, shortcodes) is indexed automatically.
- **Post title** — sanitized and tokenized for search.
- **Post URL and date** — used for display and recency scoring.

### What is NOT indexed by default

- Custom fields / post meta (unless rendered through `the_content`)
- ACF field groups that output to a custom field rather than post content
- Taxonomy terms (categories, tags, custom taxonomies)
- Widget or sidebar content

### Extending with the `scolta_content_item` filter

Use the `scolta_content_item` filter to inject any additional data before a post is indexed:

```php
add_filter( 'scolta_content_item', function( $item, $post ) {
    // Append ACF field content to the indexed body.
    $extra = get_field( 'product_specs', $post->ID );
    if ( $extra ) {
        $item = new \Tag1\Scolta\Export\ContentItem(
            id:       $item->id,
            title:    $item->title,
            bodyHtml: $item->bodyHtml . '<p>' . esc_html( $extra ) . '</p>',
            url:      $item->url,
            date:     $item->date,
            siteName: $item->siteName,
        );
    }
    return $item;
}, 10, 2 );
```

You can use the same pattern to override the title, URL, date, or site name — for example, to use a WooCommerce product's short description instead of the full post content.

## Content Tracking

The plugin automatically tracks content changes:

- **Post publish/update** -- Marked for re-indexing
- **Post delete** -- Marked for removal from index
- **`wp scolta build --incremental`** -- Processes only tracked changes

## Plugin Structure

```
scolta.php                              # Plugin entry point, activation hooks
includes/
  class-scolta-content-source.php       # WordPress content source (WP_Query)
  class-scolta-ai-service.php           # AI service wrapper
  class-scolta-rest-api.php             # REST endpoint registration
  class-scolta-shortcode.php            # [scolta_search] shortcode
  class-scolta-tracker.php              # Content change tracking
admin/
  class-scolta-admin.php                # Settings page (Settings API)
cli/
  class-scolta-cli.php                  # WP-CLI commands
vendor/
  tag1/scolta-php/                      # PHP language binding + shared assets
```

## Testing

**Unit tests** (fast, no WordPress required -- 247 tests):

```bash
cd packages/scolta-wp
./vendor/bin/phpunit
```

**Integration tests** (requires DDEV -- 34 tests):

```bash
cd test-wordpress-7
ddev wp eval-file tests/integration-test.php
```

## Indexer

Scolta auto-detects the best available indexer (`indexer: auto` default). See [scolta-php README](../scolta-php/README.md) for the full comparison table.

| Feature | PHP Indexer | Pagefind Binary |
| ------- | ----------- | --------------- |
| Languages with stemming | 15 (Snowball) | 33+ |
| Speed (1 000 pages) | ~3–4 seconds | ~0.3–0.5 seconds |
| Shared / managed hosting | Yes | Only if binary installable |

**To upgrade to the binary indexer:**

```bash
npm install -g pagefind
# or:
wp scolta download-pagefind
```

Verify: `wp scolta check-setup` — the health endpoint also reports `indexer_active`.

## Hosting

See the [Scolta Hosting Guide](../scolta-php/HOSTING.md) for platform-specific
deployment guidance, indexer selection, and ephemeral filesystem handling.

## Troubleshooting

### "Pagefind binary not found"

```bash
wp scolta download-pagefind
# or
npm install -g pagefind
```

### "AI features not working"

1. Verify API key: `wp scolta check-setup`
2. Clear stale cache: `wp scolta clear-cache`

### "No search results"

1. Check index status: `wp scolta status`
2. Run a full build: `wp scolta build`
3. Verify the Pagefind output directory is web-accessible
4. Try flushing rewrite rules: `wp rewrite flush`

## License

GPL-2.0-or-later
