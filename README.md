# Scolta for WordPress

WordPress plugin providing AI-powered search with Pagefind. Delivers client-side search with optional AI query expansion, summarization, and follow-up conversations.

## How It Works

1. **Indexing** -- The `wp scolta build` command exports published posts/pages as HTML files with Pagefind data attributes, then runs the Pagefind CLI to build a static search index.
2. **Search** -- Entirely client-side. The browser loads `pagefind.js`, searches the static index, and scolta.js handles scoring, filtering, and result rendering.
3. **AI features** -- Optional. When an API key is configured, the plugin provides REST API endpoints for query expansion, result summarization, and follow-up conversations powered by Anthropic or OpenAI.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- [Extism](https://extism.org) shared library (for WASM scoring)
- PHP FFI enabled (`ffi.enable=true`)
- Pagefind CLI (`npm install -g pagefind`)

## Installation

1. Install the plugin via Composer or by copying to `wp-content/plugins/scolta/`:

```bash
# The plugin ships with its own vendor directory including scolta-php.
cd wp-content/plugins/scolta
composer install
```

2. Activate the plugin in **Plugins > Installed Plugins**.

### Install Extism (if not already present)

```bash
curl -s https://get.extism.org/cli | bash -s -- -y
sudo extism lib install --version latest
sudo ldconfig  # Linux only
```

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

This verifies PHP version, FFI extension, Extism library, WASM binary, Pagefind binary, AI provider configuration, and cache backend. Fix any items marked as failed before proceeding.

## Configuration

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
wp scolta check-setup              # Verify PHP, Extism, Pagefind, and configuration
```

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

```bash
# Unit tests (no WordPress bootstrap required)
cd packages/scolta-wp
./vendor/bin/phpunit

# Integration tests (requires DDEV with WordPress installed)
cd test-wordpress-7
ddev wp eval-file tests/integration-test.php
```

## Troubleshooting

### "FFI not enabled" or WASM load failure

```bash
php -r "echo extension_loaded('ffi') ? 'OK' : 'MISSING';"
php -r "echo class_exists('\Extism\Plugin') ? 'OK' : 'MISSING';"
```

Install Extism if missing:

```bash
curl -s https://get.extism.org/cli | bash -s -- -y
sudo extism lib install --version latest
sudo ldconfig  # Linux only
```

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
