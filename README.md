# Scolta for WordPress

[![CI](https://github.com/tag1consulting/scolta-wp/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-wp/actions/workflows/ci.yml)

WordPress 6.x plugin — WP-CLI commands, Settings API page, `[scolta_search]` shortcode, and AI-powered search built on Pagefind.

## Status

Beta. Scolta is installable and in active use on WordPress sites. The plugin API documented here will not break within the 0.x minor series without a deprecation notice. Expect breaking changes before 1.0. Test in staging before deploying to production. File bugs at the repo issue tracker.

## What Is Scolta?

Scolta is a scoring, ranking, and AI layer built on [Pagefind](https://pagefind.app/). Pagefind is the search engine: it builds a static inverted index at publish time, runs a browser-side WASM search engine, produces word-position data, and generates highlighted excerpts. Scolta takes Pagefind's result set and re-ranks it with configurable boosts — title match weight, content match weight, recency decay curves, and phrase-proximity multipliers. No search server required. Queries resolve in the visitor's browser against a pre-built static index.

This plugin is the WordPress adapter. It provides WP-CLI commands for building and maintaining the index, a Settings API admin page, a `[scolta_search]` shortcode, content change tracking, and REST API endpoints for the AI features. The actual scoring, indexing logic, memory management, and AI communication live in [scolta-php](https://github.com/tag1consulting/scolta-php), which this plugin depends on via Composer. Scoring runs client-side via the `scolta.js` browser asset and the pre-built WASM module shipped with scolta-php.

The LLM tier — query expansion, result summarization, follow-up questions — is optional. When enabled, it sends the query text and selected result excerpts to a configured LLM provider (Anthropic, OpenAI, or a self-hosted Ollama endpoint). The base search tier shares nothing with any third party.

## Running Example

The examples in this README and the other Scolta repos use a recipe catalog as the concrete data set. Recipes are a good showcase because recipe vocabulary has genuine cross-dialect mismatches:

- A search for `aubergine parmesan` should surface *Eggplant Parmigiana*.
- A search for `chinese noodle soup` should surface *Lanzhou Beef Noodles*, *Wonton Soup*, and *Dan Dan Noodles*.
- A search for `gluten free pasta` should surface *Zucchini Spaghetti with Pesto* and *Rice Noodle Stir-Fry*.
- A search for `quick dinner under 30 min` should surface *Pad Kra Pao*, *Dan Dan Noodles*, and *Steak Frites*.

Here is how to model and index the recipe catalog in WordPress:

**1. Register a `recipe` custom post type** with custom fields: `_recipe_cuisine`, `_recipe_diet`, `_recipe_cook_time`.

```php
// In your theme's functions.php or a plugin
register_post_type('recipe', [
    'label'       => 'Recipes',
    'public'      => true,
    'has_archive' => true,
    'supports'    => ['title', 'editor', 'custom-fields'],
]);
```

**2. Add the `scolta_content_item` filter** to include the regional synonyms in the indexed content:

```php
add_filter('scolta_content_item', function ($item, $post) {
    if ($post->post_type !== 'recipe') {
        return $item;
    }
    $cuisine = get_post_meta($post->ID, '_recipe_cuisine', true);
    $diet    = get_post_meta($post->ID, '_recipe_diet', true);

    return new \Tag1\Scolta\Export\ContentItem(
        id:       $item->id,
        title:    $item->title,
        bodyHtml: $item->bodyHtml
                . '<p>Cuisine: ' . esc_html($cuisine) . '</p>'
                . '<p>Diet: ' . esc_html($diet) . '</p>',
        url:      $item->url,
        date:     $item->date,
        siteName: $item->siteName,
    );
}, 10, 2);
```

**3. Enable the recipe post type** in Settings > Scolta > Content > Post types to index.

**4. Build the index**:

```bash
wp scolta build
```

**5. Add `[scolta_search]` to any page.** Visit the page and search for `aubergine parmesan`. Scolta surfaces *Eggplant Parmigiana* because Pagefind's stemmer matches both "aubergine" and "eggplant" in the indexed content, and Scolta's title boost lifts the most relevant result.

The recipe fixture HTML files live in [scolta-php](https://github.com/tag1consulting/scolta-php) at `tests/fixtures/recipes/` if you want a pre-built data set without a WordPress database.

## Quick Install

```bash
# 1. Install the plugin (upload via wp-admin or copy to wp-content/plugins/scolta/)

# 2. Activate in wp-admin > Plugins

# 3. Build the search index
wp scolta build

# 4. Add [scolta_search] to any page

# 5. Set your API key to unlock AI features
```

Add to `wp-config.php`:

```php
define('SCOLTA_API_KEY', 'sk-ant-...');
```

With an API key configured, search queries are automatically expanded with related terms, results include an AI summary, and visitors can ask follow-up questions.

## Verify It Works

```bash
wp scolta check-setup
```

This verifies PHP version, index directories, indexer selection, AI provider configuration, and binary availability. Fix any items marked as failed before proceeding.

```bash
wp scolta status
```

The REST health endpoint also reports current state: `GET /wp-json/scolta/v1/health`

## What Scolta Replaces (and What It Doesn't)

Scolta is a practical replacement for hosted search SaaS (Algolia, Coveo, SearchStax) and for WordPress search plugins that drive Elasticsearch or Solr (SearchWP with Elasticsearch, ElasticPress) when the use case is content search on a WordPress site.

Scolta is not a replacement for:

- Plugins that enforce per-post access control at the search layer (membership sites where search results must respect individual user permissions).
- Elasticsearch setups used for log analytics, WooCommerce inventory search at scale, or complex aggregation queries.
- Vector databases used as a general retrieval layer for RAG pipelines.
- Enterprise search with audit logging, retention policies, or SSO-gated content visibility.

If ElasticPress or SearchWP is serving basic full-text search on a content site with no per-post ACL, Scolta is a simpler replacement that works on managed WordPress hosting without a search server.

## Memory and Scale

The default memory profile is `conservative`, which targets a peak RSS under 96 MB and works on shared hosting with a 128 MB PHP `memory_limit`. Scolta never silently upgrades to a larger profile.

The **Settings > Scolta > Memory Budget** field accepts a profile name _or_ an exact byte value:

```
conservative   # peak ≤ 96 MB  — default, safe for shared hosting (50 posts/chunk)
balanced       # peak ≤ 384 MB — recommended for VMs              (200 posts/chunk)
aggressive     # peak ≤ 1 GB   — high-memory servers              (500 posts/chunk)
256M           # custom byte value — routes to the nearest profile's tuning
```

The **Settings > Scolta > Chunk Size** field sets pages-per-chunk independently of the memory budget. Leave it blank to use the profile default. Lower values reduce peak RAM; higher values reduce merge overhead on large corpora.

Both settings can be overridden per-run:

```bash
wp scolta build --memory-budget=balanced
wp scolta build --memory-budget=256M --chunk-size=100
wp scolta build --indexer=php --chunk-size=30  # low-RAM override
```

Tested ceiling at the `conservative` profile: 50,000 pages. Higher counts likely work; not certified yet.

## AI Features and Privacy

Scolta's AI tier is optional. When enabled:

- The LLM receives: the query text, and the titles and excerpts of the top N results (default: 5, configurable via `ai_summary_top_n`).
- The LLM does not receive: the full index contents, full page text, user session data, or visitor identity.
- Which provider receives the query data depends on your `ai_provider` setting: `anthropic`, `openai`, or a self-hosted endpoint via `ai_base_url`.

The base search tier — Pagefind index lookup and Scolta WASM scoring — runs entirely in the visitor's browser with no server-side involvement beyond serving static index files.

## Configuration

### AI Provider

Configure at **Settings > Scolta > AI Provider**, or via `wp-config.php` constants.

| Setting | Option key | Default | Description |
| ------- | ---------- | ------- | ----------- |
| Provider | `ai_provider` | `anthropic` | `anthropic` or `openai` |
| API key | env/constant only | — | `SCOLTA_API_KEY` env var or `define('SCOLTA_API_KEY', '...')` in wp-config.php |
| Model | `ai_model` | `claude-sonnet-4-5-20250929` | LLM model identifier |
| Base URL | `ai_base_url` | provider default | Custom endpoint for proxies or Azure OpenAI |
| Query expansion | `ai_expand_query` | `true` | Toggle AI query expansion on/off |
| Summarization | `ai_summarize` | `true` | Toggle AI result summarization on/off |
| Summary top N | `ai_summary_top_n` | `5` | How many top results to send to AI for summarization |
| Summary max chars | `ai_summary_max_chars` | `2000` | Max content characters sent to AI per request |
| Max follow-ups | `max_follow_ups` | `3` | Follow-up questions allowed per session |
| AI languages | `ai_languages` | `['en']` | Languages the AI responds in (matches user query language) |

These are stored in the `scolta_settings` WordPress option. Use **Settings > Scolta** to edit them, or update programmatically:

```php
$settings = get_option('scolta_settings', []);
$settings['ai_model'] = 'claude-opus-4-6';
$settings['ai_languages'] = ['en', 'fr', 'de'];
update_option('scolta_settings', $settings);
```

### Search Scoring

Configure at **Settings > Scolta > Scoring**.

| Setting | Option key | Default | Description |
| ------- | ---------- | ------- | ----------- |
| Title match boost | `title_match_boost` | `1.0` | Boost when query terms appear in the title |
| Title all-terms multiplier | `title_all_terms_multiplier` | `1.5` | Extra multiplier when ALL terms match the title |
| Content match boost | `content_match_boost` | `0.4` | Boost for query term matches in body/excerpt |
| Expand primary weight | `expand_primary_weight` | `0.7` | Weight for original query results vs AI-expanded results (higher = original query dominates) |
| Recency strategy | `recency_strategy` | `exponential` | Decay function: `exponential`, `linear`, `step`, `none`, or `custom` |
| Recency boost max | `recency_boost_max` | `0.5` | Maximum positive boost for very recent content |
| Recency half-life days | `recency_half_life_days` | `365` | Days until recency boost halves |
| Recency penalty after days | `recency_penalty_after_days` | `1825` | Age before content gets a penalty (~5 years) |
| Recency max penalty | `recency_max_penalty` | `0.3` | Maximum negative penalty for very old content |
| Language | `language` | `en` | ISO 639-1 code for stop word filtering |
| Custom stop words | `custom_stop_words` | `[]` | Extra stop words beyond the language's built-in list |

**News site** (recency matters a lot):

```php
$settings = get_option('scolta_settings', []);
$settings['recency_boost_max']           = 0.8;
$settings['recency_half_life_days']      = 30;
$settings['recency_penalty_after_days']  = 365;
$settings['recency_max_penalty']         = 0.5;
update_option('scolta_settings', $settings);
```

**Documentation site** (recency doesn't matter, titles matter a lot):

```php
$settings = get_option('scolta_settings', []);
$settings['recency_strategy']           = 'none';
$settings['title_match_boost']          = 2.0;
$settings['title_all_terms_multiplier'] = 2.5;
update_option('scolta_settings', $settings);
```

**Recipe catalog** (no recency, title precision matters):

```php
$settings = get_option('scolta_settings', []);
$settings['recency_strategy']           = 'none';
$settings['title_match_boost']          = 1.5;
$settings['title_all_terms_multiplier'] = 2.0;
update_option('scolta_settings', $settings);
```

### Display

Configure at **Settings > Scolta > Display**.

| Setting | Option key | Default | Description |
| ------- | ---------- | ------- | ----------- |
| Excerpt length | `excerpt_length` | `300` | Characters shown in result excerpts |
| Results per page | `results_per_page` | `10` | Results shown per page |
| Max Pagefind results | `max_pagefind_results` | `50` | Total results fetched from index before scoring |

### Site Identity

Configure at **Settings > Scolta > Content**.

| Setting | Option key | Default | Description |
| ------- | ---------- | ------- | ----------- |
| Site name | `site_name` | blog name | Included in AI prompts so the AI knows what site it's searching |
| Site description | `site_description` | `website` | Brief description for AI context |

### Custom Prompts

Override the built-in AI prompts at **Settings > Scolta > Custom Prompts**, or use the `scolta_prompt` filter:

```php
add_filter('scolta_prompt', function (string $prompt, string $promptName, array $context): string {
    if ($promptName === 'summarize') {
        $prompt .= "\n\nFocus on cuisine type and dietary information.";
    }
    return $prompt;
}, 10, 3);
```

`$promptName` is one of `expand_query`, `summarize`, or `follow_up`.

## Debugging

### "Pagefind binary not found"

On managed hosting (WP Engine, Kinsta, Flywheel, Pantheon), `exec()` is disabled and the binary cannot run. The plugin falls back to the PHP indexer automatically — the search experience is identical. To confirm:

```bash
wp scolta check-setup
wp scolta status
```

If you want the binary on a host that supports it:

```bash
wp scolta download-pagefind
```

The PHP indexer supports 14 languages via Snowball stemming. The Pagefind binary supports 33+ languages and is 5–10× faster for large sites, but requires Node.js ≥ 18 or a direct binary download.

### "AI features not working"

1. Verify API key: `wp scolta check-setup`
2. Clear stale cache: `wp scolta clear-cache`
3. Confirm the model name is current at **Settings > Scolta > AI Provider**

### "AI summary says 'I don't have enough context'"

Increase how much content is sent to the AI:

```php
$settings = get_option('scolta_settings', []);
$settings['ai_summary_top_n']     = 10;
$settings['ai_summary_max_chars'] = 4000;
update_option('scolta_settings', $settings);
```

### "AI responses are in the wrong language"

Set `ai_languages` to match your site's language(s):

```php
$settings = get_option('scolta_settings', []);
$settings['ai_languages'] = ['de'];  // or ['en', 'fr', 'de'] for multilingual
update_option('scolta_settings', $settings);
```

### "Expanded queries return irrelevant results"

Lower `expand_primary_weight` to give more weight to the original query, or disable expansion:

```php
$settings = get_option('scolta_settings', []);
$settings['expand_primary_weight'] = 0.9;
// or: $settings['ai_expand_query'] = false;
update_option('scolta_settings', $settings);
```

### "No search results"

1. Check index status: `wp scolta status`
2. Run a full rebuild: `wp scolta build`
3. Confirm the Pagefind output directory is web-accessible (**Settings > Scolta > Pagefind**)
4. Flush rewrite rules: `wp rewrite flush`

### "Build is slow"

Use `wp scolta diagnose` to identify which phase dominates:

```bash
wp scolta diagnose             # sample 500 posts (fast)
wp scolta diagnose --count=2000  # larger sample for better projection accuracy
```

Output example:

```
=== Scolta PHP Indexer Diagnostics ===

Site: 44,107 published posts  (gather_count in 0.012s)
Sample: 500 posts  (--count=500)

Phase 1/3  gather  [WP_Query + apply_filters("the_content") + get_permalink]
  500 posts  18.40s  36.8 ms/post
  ! High ms/post — apply_filters("the_content") is likely slow.
    Common causes: Yoast SEO, Gutenberg do_blocks(), WooCommerce, Elementor.

Phase 2/3  HtmlCleaner  [strip HTML tags, check minimum content length]
  500 posts  1.70s  3.4 ms/post  (487 passed, 3% too short)

Phase 3/3  indexer  [tokenize, stem, chunk, merge, write — conservative budget]
  487 posts  0.89s  1.8 ms/post

=== Projected for full corpus (44,107 posts) ===

  gather:      27m 0s    86% of total
  HtmlCleaner: 2m 30s     8% of total
  indexer:     1m 19s     4% of total
  ----------------------------
  estimated total: 30m 49s

Recommendation: gather phase dominates (86% of build time).
  apply_filters("the_content") runs every active plugin filter on each post.
  Use the scolta_content_item filter to substitute $post->post_content
  (raw storage, no plugin processing) or do_blocks($post->post_content)
  (renders blocks only, skips SEO/analytics hooks).
```

**If gather dominates (>50%):** `apply_filters('the_content', ...)` is the bottleneck. Use the `scolta_content_item` filter to replace the body HTML with raw `$post->post_content` or `do_blocks($post->post_content)`:

```php
add_filter('scolta_content_item', function ($item, $post) {
    // Use do_blocks() instead of apply_filters('the_content'):
    // renders Gutenberg blocks but skips Yoast, WooCommerce, etc.
    return new \Tag1\Scolta\Export\ContentItem(
        id:       $item->id,
        title:    $item->title,
        bodyHtml: do_blocks($post->post_content),
        url:      $item->url,
        date:     $item->date,
        siteName: $item->siteName,
    );
}, 10, 2);
```

**If indexer dominates (>50%):** Increase the chunk size to reduce merge overhead. Either use a larger profile (`--memory-budget=balanced`, which sets 200 posts/chunk) or set the chunk size directly (`--chunk-size=200`) while keeping your current memory profile.

**For per-phase wall-clock breakdowns during a live build**, run with `--debug`:

```bash
wp scolta build --indexer=php --debug 2>&1 | grep '\[scolta\]'
```

Each `[scolta]` line now includes `+Xs` elapsed since build start, making it easy to see which chunk or merge step is slow.

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
wp scolta build                          # Full build: mark all content, export HTML, run indexer
wp scolta build --incremental            # Only process tracked changes
wp scolta build --skip-pagefind          # Export HTML without rebuilding index
wp scolta build --indexer=php            # Force PHP indexer regardless of setting
wp scolta build --force                  # Skip fingerprint check, force full rebuild
wp scolta build --memory-budget=balanced # Use balanced memory profile
wp scolta build --resume                 # Resume an interrupted PHP build
wp scolta export                         # Export content to HTML only
wp scolta export --incremental           # Only export tracked changes
wp scolta rebuild-index                  # Rebuild index from existing HTML files
wp scolta diagnose                       # Profile gather / HtmlCleaner / indexer phases
wp scolta diagnose --count=2000          # Larger sample for more accurate projection
wp scolta status                         # Show tracker, content, index, and AI status
wp scolta clear-cache                    # Clear Scolta AI response caches
wp scolta download-pagefind              # Download the Pagefind binary for your platform
wp scolta check-setup                    # Verify PHP, indexer, and configuration
```

## REST API Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| POST | `/wp-json/scolta/v1/expand-query` | Expand a search query into related terms |
| POST | `/wp-json/scolta/v1/summarize` | Summarize search results |
| POST | `/wp-json/scolta/v1/followup` | Continue a search conversation |
| GET | `/wp-json/scolta/v1/health` | Health check (indexer status, AI availability) |
| GET | `/wp-json/scolta/v1/build-progress` | Current build status (admin only) |
| POST | `/wp-json/scolta/v1/rebuild-now` | Trigger immediate background rebuild (admin only) |

Endpoints are public by default. Use the `scolta_search_permission` filter to restrict access.

## Extend Indexed Content

By default, Scolta indexes all published posts and pages. Add custom post types at **Settings > Scolta > Content > Post types to index**.

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

## Optional Upgrades

### Upgrade to the Pagefind binary indexer

The plugin auto-selects the PHP indexer on managed hosts. On hosts that support binaries, the Pagefind binary is 5–10× faster. The search experience is identical either way — both produce a Pagefind-compatible index.

```bash
wp scolta download-pagefind
# or:
npm install -g pagefind
```

Change **Settings > Scolta > Indexer** to "Auto" or "Binary" and rebuild.

### Keeping the Index Fresh

When **auto_rebuild** is enabled in Settings > Scolta, the plugin listens for content changes (post saves and deletes) and automatically schedules a debounced rebuild after a configurable delay (default: 5 minutes). This requires Action Scheduler.

Three paths are available, in order of reliability:

#### Path A: Action Scheduler (recommended)

Install [Action Scheduler](https://actionscheduler.org/) to get automatic background index builds when content changes. WooCommerce sites already have it — just enable **auto_rebuild** in Settings > Scolta.

#### Path B: System cron

For hosts with SSH access. This is the most reliable option after Action Scheduler because it runs on the system clock and doesn't depend on WordPress page loads.

```
*/15 * * * * cd /var/www/html && wp scolta build --incremental 2>&1 | logger -t scolta
```

Adjust the path and interval to taste. `--incremental` only processes tracked changes, so runs are fast when nothing has changed.

#### Path C: WP-Cron via WP Crontrol

For users without SSH access who can't install Action Scheduler. Install [WP Crontrol](https://wordpress.org/plugins/wp-crontrol/), then add this to your theme's `functions.php` or a custom plugin:

```php
add_action( 'scolta_scheduled_rebuild', function () {
    if ( class_exists( 'Scolta_Rebuild_Scheduler' ) ) {
        Scolta_Rebuild_Scheduler::start_rebuild();
    }
} );
```

In Tools > Cron Events, add a new cron event: hook name `scolta_scheduled_rebuild`, recurrence "Every 15 minutes" (or "Twice hourly").

**Caveat:** WP-Cron events are triggered by page loads, not by the system clock. On low-traffic sites the rebuild may not run on schedule. If the site gets consistent traffic this works fine; otherwise Path B is more reliable.

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

## Credits

Scolta is built on [Pagefind](https://pagefind.app/) by [CloudCannon](https://cloudcannon.com/). Without Pagefind, Scolta has no search to score — the index format, WASM search engine, word-position data, and excerpt generation are all Pagefind's. Scolta's contribution is the layer that sits on top: configurable scoring, multi-adapter ranking parity, AI features, and platform glue.

## License

GPL-2.0-or-later

## Related Packages

- [scolta-core](https://github.com/tag1consulting/scolta-core) — Rust/WASM scoring, ranking, and AI layer that runs in the browser.
- [scolta-php](https://github.com/tag1consulting/scolta-php) — PHP library that indexes content into Pagefind-compatible indexes, plus the shared orchestration and AI client.
- [scolta-drupal](https://github.com/tag1consulting/scolta-drupal) — Drupal 10/11 Search API backend with Drush commands, admin settings form, and a search block.
- [scolta-laravel](https://github.com/tag1consulting/scolta-laravel) — Laravel 11/12 package with Artisan commands, a `Searchable` trait for Eloquent models, and a Blade search component.
