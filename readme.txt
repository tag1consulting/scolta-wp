=== Scolta AI Search ===

Contributors: tag1consulting
Tags: search, ai, pagefind, artificial intelligence, semantic search
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable Tag: 1.0.0-rc2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zero-infrastructure AI search powered by Pagefind — static indexes, browser-side WASM scoring, and optional AI query expansion and summaries.

== Description ==

Scolta AI Search is built and maintained by [Tag1 Consulting](https://www.tag1.com/), one of the leading open-source consulting firms in the world.

Scolta AI Search is a scoring, ranking, and AI layer built on [Pagefind](https://pagefind.app/). It builds a static inverted index at publish time and runs a browser-side WASM search engine — no search server required.

**Key features:**

* Static search index — no Elasticsearch, Solr, or external search service needed
* Works on managed hosting (WP Engine, Kinsta, Flywheel, Pantheon) where running a search server is not possible
* Optional AI query expansion and result summarization (Anthropic, OpenAI, or self-hosted)
* Configurable scoring: title match boost, content match boost, recency decay curves
* `[scolta_search]` shortcode to embed the search UI on any page
* WP-CLI commands for building and maintaining the search index
* Content change tracking with automatic background rebuilds via Action Scheduler
* Conservative memory profile (≤96 MB peak) safe for shared hosting

**Privacy:** The base search tier runs entirely in the visitor's browser — no server-side involvement beyond serving static files. The AI tier is optional and only sends the search query text and selected result excerpts to your configured AI provider.

**Requirements:** WordPress 6.0+, PHP 8.1+. The Pagefind binary is optional — the PHP indexer works without it.

== Installation ==

1. Upload the `scolta` directory to `/wp-content/plugins/`, or install via the WordPress plugin screen.
2. Activate through the Plugins screen.
3. Build the search index: `wp scolta build`
4. Add `[scolta_search]` to any page.
5. Optionally configure an AI provider key in `wp-config.php`: `define('SCOLTA_API_KEY', 'sk-ant-...');`

== Frequently Asked Questions ==

= Does this require a search server? =

No. Scolta uses Pagefind's static index — all search runs in the visitor's browser. No Elasticsearch, Solr, or external service required.

= Does this work on managed hosting? =

Yes. The PHP indexer works without `exec()` or Node.js, making it compatible with WP Engine, Kinsta, Flywheel, Pantheon, and other managed hosts.

= Is the AI tier required? =

No. The base search tier works without any API key. AI query expansion and result summarization are optional features.

= What AI providers are supported? =

Anthropic (Claude), OpenAI, and any OpenAI-compatible endpoint (including self-hosted Ollama). Amazee.ai trial credits are provisioned automatically on first activation when no API key is configured.

= How do I keep the search index up to date? =

Install [Action Scheduler](https://actionscheduler.org/) and enable **auto_rebuild** in Settings > Scolta. The plugin will automatically queue a rebuild whenever content is saved. WooCommerce sites already have Action Scheduler installed.

== Screenshots ==

1. Search results with AI-powered summary and query expansion
2. Settings page — AI provider, scoring, and indexer configuration
3. WP-CLI status output showing tracker and index state

== Changelog ==

= 1.0.0-rc2 =
* First release candidate.
* PHP indexer as the default (no binary required).
* Amazee.ai trial provisioning on activation.
* Action Scheduler integration for automatic background rebuilds.
* Configurable memory profiles: conservative (96 MB), balanced (384 MB), aggressive (1 GB).

== Upgrade Notice ==

= 1.0.0-rc2 =
First release candidate. No stable upgrade path from pre-1.0 versions.

== About Tag1 Consulting ==

Scolta is designed, built, and maintained by [Tag1 Consulting](https://www.tag1.com/). Tag1 has been delivering technology leadership since 2007 and is one of the leading open-source consulting firms in the world.

Tag1 offers AI strategy, architecture, and implementation consulting — from evaluating whether AI search is right for your organization, to production deployment and ongoing tuning. If you need help integrating Scolta, customizing scoring for your content model, or connecting it to your AI provider of choice, [get in touch](https://www.tag1.com/).
