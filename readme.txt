=== Scolta AI Search ===

Contributors: tag1consulting
Tags: search, ai, pagefind, artificial intelligence, semantic search
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 8.1
Stable Tag: 1.0.5-dev
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

= Does this work with WooCommerce? =

Yes. WooCommerce product metadata (price, SKU, stock status, categories) is automatically extracted and indexed. Price is emitted as a sortable field so search results can be sorted by price. WooCommerce ships with Action Scheduler, so automatic background rebuilds work out of the box with no additional dependencies.

= How do I keep the search index up to date? =

Install [Action Scheduler](https://actionscheduler.org/) and enable **auto_rebuild** in Settings > Scolta. The plugin will automatically queue a rebuild whenever content is saved. WooCommerce sites already have Action Scheduler installed.

= Why am I getting fewer search results than before? =

Scolta defaults to a conservative search breadth so generic words don't flood your results. On a recipe, product, or catalog site, that can hide useful domain words you actually want to match. Go to **Settings > Scolta > Site Type** and choose the **Recipe & Content Catalog** preset, then save and run `wp scolta build`. The preset widens the search breadth and tunes ranking for catalog-style content. See the "Tuning search breadth" section of the plugin README for the full guide.

== Screenshots ==

1. Search results with AI-powered summary and query expansion
2. Settings page — AI provider, scoring, and indexer configuration
3. WP-CLI status output showing tracker and index state

== Changelog ==

= 1.0.1 =
* WordPress.org review fixes: dist allowlist with CI guard, REST SCOLTA_PLUGIN_DIR, removed CLI display_errors.
* Decoupled release build from lockstep scolta-php tagging (composer.lock pins scolta-php 1.0.0 from Packagist).
* Plugin Check fixes: Requires-at-least 6.1, $_SERVER sanitization.
* Extracted dist build/validate into reusable scripts.
* Added check-wp-version job to release workflow.

= 1.0.0 =
* First stable release.
* Exact title match boost, filter exact-match-first, expansion merge scoring fix.
* Facet count refresh and multi-value OR fix, sort intersection replaced with filter+sort discovery.
* Minimum stability changed to stable, WP_Filesystem for cleanup, uninstall handler improvements.

= 1.0.0-rc4 =
* Health endpoint now includes index detail: fragment count, last-build timestamp, and integrity status.
* Exclude vendor test directories and duplicate WASM from release ZIP.
* Align readme.txt Stable Tag with SCOLTA_VERSION.
* Add External Services section to readme.txt for WordPress.org compliance.
* Replace inline script tags with wp_add_inline_script() for WordPress.org compliance.
* Show Scolta attribution setting (default: off).
* dir_to_url() now uses wp_upload_dir() baseurl for index paths under the uploads directory.
* Text domain changed to scolta-ai-search for WordPress.org translation support.

= 1.0.0-rc3 =
* PCP distribution build: zero errors, zero warnings.
* VCS repository for scolta-php in composer.json (replaces local path reference).
* expand_primary_weight now correctly weights original vs. expansion results.
* WordPress Plugin Check (PCP) compliance for wordpress.org submission.
* Documentation now clearly attributes Scolta to Tag1 Consulting.

= 1.0.0-rc2 =
* First release candidate.
* PHP indexer as the default (no binary required).
* Amazee.ai trial provisioning on activation.
* Action Scheduler integration for automatic background rebuilds.
* Configurable memory profiles: conservative (96 MB), balanced (384 MB), aggressive (1 GB).

== Upgrade Notice ==

= 1.0.1 =
WordPress.org resubmission: review fixes, Plugin Check compliance, decoupled release build. No data migration needed.

= 1.0.0 =
First stable release. Upgrades from rc2/rc3/rc4 are seamless. If upgrading from pre-1.0 (0.3.x or earlier), rebuild your search index after updating: wp scolta build --force.

== External Services ==

This plugin connects to the following external services under specific conditions. No data is sent automatically — all connections are triggered by admin action or explicit site configuration.

= GitHub API (api.github.com) =

**When:** An administrator runs the `wp scolta download-pagefind` WP-CLI command to download the Pagefind binary.
**What is sent:** A standard HTTPS GET request to `https://api.github.com/repos/CloudCannon/pagefind/releases/latest`. No personally identifiable information is transmitted beyond the standard HTTP request headers (IP address, user agent).
**Service:** GitHub, operated by GitHub, Inc. (a subsidiary of Microsoft Corporation).
**Terms of Service:** https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
**Privacy Statement:** https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

= Pagefind Binary (GitHub Releases / CloudCannon) =

**When:** The `wp scolta download-pagefind` WP-CLI command downloads the Pagefind binary from GitHub Releases after querying the GitHub API above.
**What is sent:** A standard HTTPS GET request to download the release archive. No personally identifiable information is transmitted beyond the standard HTTP request headers.
**Service:** Pagefind is an open-source project (MIT license) created and maintained by CloudCannon.
**Pagefind:** https://pagefind.app/
**CloudCannon:** https://cloudcannon.com/
**Pagefind License:** https://github.com/Pagefind/pagefind/blob/main/LICENSE

= AI Provider APIs =

**When:** A user performs a search and the site administrator has enabled AI features (`ai: true` in the Scolta configuration). AI features are disabled by default and require an API key to be configured.
**What is sent:** The user's search query text and selected page content excerpts (for result summarization) are sent to the configured AI provider's API endpoint.
**Providers:** The specific provider depends on site configuration. Supported providers are:

* **Anthropic (Claude)** — processes search queries and page excerpts.
  Terms of Service: https://www.anthropic.com/legal/consumer-terms
  Privacy Policy: https://www.anthropic.com/legal/privacy

* **OpenAI** — processes search queries and page excerpts.
  Terms of Use: https://openai.com/policies/terms-of-use
  Privacy Policy: https://openai.com/policies/privacy-policy

* **OpenAI-compatible endpoints** (including self-hosted Ollama and other providers) — any endpoint configured by the site administrator that speaks the OpenAI API protocol. Review the terms and privacy policy of your chosen provider.

* **WordPress AI Services (wp-ai-services plugin)** — delegates to whichever provider is configured in that plugin. Review the terms and privacy policy of that provider.

No AI API calls are made unless the site administrator has explicitly enabled AI features and configured a valid API key.

= Amazee.ai (amazee.ai) =

**When:** An administrator starts a trial or signs in via Settings > Scolta > Amazee.ai. On activation, if no API key is configured, the plugin may auto-provision trial credentials via Action Scheduler.
**What is sent:** The administrator's email address (for trial/sign-in), and AI search queries and result excerpts when the Amazee.ai gateway is the active AI provider.
**Service:** Amazee.ai, a privacy-respecting AI gateway. Credentials are stored encrypted (AES-256-CBC) in the WordPress options table.
**Terms of Service:** https://amazee.ai/terms
**Privacy Policy:** https://amazee.ai/privacy

== About Tag1 Consulting ==

Scolta is designed, built, and maintained by [Tag1 Consulting](https://www.tag1.com/). Tag1 has been delivering technology leadership since 2007 and is one of the leading open-source consulting firms in the world.

Tag1 offers AI strategy, architecture, and implementation consulting — from evaluating whether AI search is right for your organization, to production deployment and ongoing tuning. If you need help integrating Scolta, customizing scoring for your content model, or connecting it to your AI provider of choice, [get in touch](https://www.tag1.com/).
