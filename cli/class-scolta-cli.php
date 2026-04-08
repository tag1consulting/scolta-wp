<?php
/**
 * WP-CLI commands for Scolta.
 *
 * WordPress's WP-CLI is the developer interface for everything that
 * doesn't happen in the admin. Index builds, status checks, binary
 * management — all here.
 *
 * ## EXAMPLES
 *
 *     # Full rebuild: mark all content, export, build Pagefind index
 *     wp scolta build
 *
 *     # Incremental: only process tracked changes
 *     wp scolta build --incremental
 *
 *     # Check index and tracker status
 *     wp scolta status
 *
 *     # Download the Pagefind binary (for hosts without npm)
 *     wp scolta download-pagefind
 *
 *     # Rebuild Pagefind index only (skip content export)
 *     wp scolta rebuild-index
 */

defined('ABSPATH') || exit;

use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Export\ContentExporter;

class Scolta_CLI {

    /**
     * Build or rebuild the Scolta search index.
     *
     * Processes content through three stages:
     * 1. Mark content for indexing (full) or read tracker (incremental)
     * 2. Export content as HTML files with Pagefind attributes
     * 3. Run Pagefind CLI to build the static search index
     *
     * ## OPTIONS
     *
     * [--incremental]
     * : Only process content that changed since the last build.
     *   Without this flag, all published content is reindexed.
     *
     * [--skip-pagefind]
     * : Export HTML files but don't run the Pagefind CLI.
     *   Useful when you want to inspect the exported HTML.
     *
     * ## EXAMPLES
     *
     *     wp scolta build
     *     wp scolta build --incremental
     *
     * @subcommand build
     */
    public function build(array $args, array $assoc_args): void {
        try {
            $this->do_build($args, $assoc_args);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    private function do_build(array $args, array $assoc_args): void {
        $incremental = \WP_CLI\Utils\get_flag_value($assoc_args, 'incremental', false);
        $skip_pagefind = \WP_CLI\Utils\get_flag_value($assoc_args, 'skip-pagefind', false);

        $settings = get_option('scolta_settings', []);
        $config = ScoltaConfig::fromArray($settings);
        $build_dir = $settings['build_dir'] ?? WP_CONTENT_DIR . '/scolta-build';
        $output_dir = $settings['output_dir'] ?? ABSPATH . 'scolta-pagefind';
        $post_types = $settings['post_types'] ?? ['post', 'page'];
        $binary = $settings['pagefind_binary'] ?? 'pagefind';

        $source = new \Scolta_Content_Source($config);
        $exporter = new ContentExporter($build_dir);

        // Step 1: Determine what to index.
        if ($incremental) {
            $pending_count = \Scolta_Tracker::get_pending_count();
            if ($pending_count === 0) {
                \WP_CLI::success('No changes pending. Index is up to date.');
                return;
            }
            \WP_CLI::log("Step 1: Processing {$pending_count} tracked changes...");
        } else {
            \WP_CLI::log('Step 1: Marking all published content for reindex...');
            $count = \Scolta_Tracker::mark_all_for_reindex();
            \WP_CLI::log("  Marked {$count} items.");

            // For full rebuild, clean the build directory.
            $exporter->prepareOutputDir();
        }

        // Step 2: Export content to HTML.
        \WP_CLI::log('Step 2: Exporting content to HTML...');

        // Handle deletions first.
        $deleted_ids = $source->get_deleted_ids();
        foreach ($deleted_ids as $id) {
            $filepath = rtrim($build_dir, '/') . '/' . $id . '.html';
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
        if (count($deleted_ids) > 0) {
            \WP_CLI::log("  Removed " . count($deleted_ids) . " deleted items.");
        }

        // Export new/changed content.
        $items = $incremental
            ? $source->get_changed_content()
            : $source->get_published_content($post_types);

        $exported = 0;
        $skipped = 0;
        $progress = null;

        if (!$incremental) {
            $total = $source->get_total_count($post_types);
            $progress = \WP_CLI\Utils\make_progress_bar('Exporting', $total);
        }

        foreach ($items as $item) {
            if ($exporter->export($item)) {
                $exported++;
            } else {
                $skipped++;
            }
            if ($progress) {
                $progress->tick();
            }
        }

        if ($progress) {
            $progress->finish();
        }

        \WP_CLI::log("  Exported: {$exported}, Skipped (insufficient content): {$skipped}");

        // Clear the tracker after successful export.
        \Scolta_Tracker::clear();

        // Step 3: Build Pagefind index.
        if ($skip_pagefind) {
            \WP_CLI::success("Export complete. Skipped Pagefind build (--skip-pagefind).");
            return;
        }

        \WP_CLI::log('Step 3: Building Pagefind index...');
        $this->run_pagefind($binary, $build_dir, $output_dir);
    }

    /**
     * Rebuild the Pagefind index from existing HTML files.
     *
     * Skips the content export step — useful when you've edited the
     * HTML files directly or want to rebuild after a Pagefind update.
     *
     * @subcommand rebuild-index
     */
    public function rebuild_index(array $args, array $assoc_args): void {
        try {
            $settings = get_option('scolta_settings', []);
            $binary = $settings['pagefind_binary'] ?? 'pagefind';
            $build_dir = $settings['build_dir'] ?? WP_CONTENT_DIR . '/scolta-build';
            $output_dir = $settings['output_dir'] ?? ABSPATH . 'scolta-pagefind';

            \WP_CLI::log('Rebuilding Pagefind index from existing HTML files...');
            $this->run_pagefind($binary, $build_dir, $output_dir);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Show Scolta index status.
     *
     * Displays tracker state, index stats, binary availability, and
     * configuration summary.
     *
     * @subcommand status
     */
    public function status(array $args, array $assoc_args): void {
        try {
            $this->do_status();
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    private function do_status(): void {
        $settings = get_option('scolta_settings', []);
        $post_types = $settings['post_types'] ?? ['post', 'page'];
        $build_dir = $settings['build_dir'] ?? WP_CONTENT_DIR . '/scolta-build';
        $output_dir = $settings['output_dir'] ?? ABSPATH . 'scolta-pagefind';
        $binary = $settings['pagefind_binary'] ?? 'pagefind';

        // Tracker status.
        \WP_CLI::log('--- Tracker ---');
        if (!\Scolta_Tracker::table_exists()) {
            \WP_CLI::warning('Tracker table does not exist. Run: wp scolta activate');
        } else {
            $pending_index = \Scolta_Tracker::get_pending_count('index');
            $pending_delete = \Scolta_Tracker::get_pending_count('delete');
            \WP_CLI::log("  Pending index:  {$pending_index}");
            \WP_CLI::log("  Pending delete: {$pending_delete}");
        }

        // Content counts.
        \WP_CLI::log('--- Content ---');
        $config = ScoltaConfig::fromArray($settings);
        $source = new \Scolta_Content_Source($config);
        $total = $source->get_total_count($post_types);
        \WP_CLI::log("  Published posts ({$this->join_types($post_types)}): {$total}");

        // Build directory.
        \WP_CLI::log('--- Build Directory ---');
        if (is_dir($build_dir)) {
            $html_count = count(glob($build_dir . '/*.html') ?: []);
            \WP_CLI::log("  Path:       {$build_dir}");
            \WP_CLI::log("  HTML files: {$html_count}");
        } else {
            \WP_CLI::log("  Path: {$build_dir} (does not exist)");
        }

        // Pagefind index.
        \WP_CLI::log('--- Pagefind Index ---');
        $index_file = $output_dir . '/pagefind.js';
        if (file_exists($index_file)) {
            $fragment_count = count(glob($output_dir . '/fragment/*') ?: []);
            $mtime = filemtime($index_file);
            \WP_CLI::log("  Path:       {$output_dir}");
            \WP_CLI::log("  Fragments:  {$fragment_count}");
            \WP_CLI::log("  Last built: " . ($mtime ? date('Y-m-d H:i:s', $mtime) : 'unknown'));
        } else {
            \WP_CLI::log("  Path: {$output_dir} (no index built yet)");
        }

        // Pagefind binary.
        \WP_CLI::log('--- Pagefind Binary ---');
        $version_output = shell_exec(escapeshellcmd($binary) . ' --version 2>&1');
        if ($version_output) {
            \WP_CLI::log("  Binary:  {$binary}");
            \WP_CLI::log("  Version: " . trim($version_output));
        } else {
            \WP_CLI::warning("Pagefind binary not found at: {$binary}");
            \WP_CLI::log("  Install: npm install -g pagefind");
            \WP_CLI::log("  Or:      wp scolta download-pagefind");
        }

        // AI provider.
        \WP_CLI::log('--- AI Provider ---');
        $ai = \Scolta_Ai_Service::from_options();
        if ($ai->has_wp_ai_sdk()) {
            \WP_CLI::log("  Provider: WordPress AI Client SDK (WP 7.0+)");
        } else {
            $provider = $settings['ai_provider'] ?? 'anthropic';
            $key_source = \Scolta_Ai_Service::get_api_key_source();
            \WP_CLI::log("  Provider: {$provider} (built-in)");
            $source_label = match ($key_source) {
                'env'      => 'environment variable',
                'constant' => 'wp-config.php constant',
                'database' => 'database (INSECURE — migrate to env var)',
                default    => 'NOT SET',
            };
            \WP_CLI::log("  API key:  {$source_label}");
            if ($key_source === 'database') {
                \WP_CLI::warning('API key stored in database. Set SCOLTA_API_KEY environment variable and remove from DB.');
            }
        }
    }

    /**
     * Clear all Scolta caches.
     *
     * Increments the generation counter to invalidate all cached AI
     * responses (expansion, summarization) and deletes any stale
     * transients with the old prefix.
     *
     * @subcommand clear-cache
     */
    public function clear_cache(array $args, array $assoc_args): void {
        try {
            $generation = (int) get_option('scolta_generation', 0);
            update_option('scolta_generation', $generation + 1);

            // Also clean up any stale transients from old generations.
            global $wpdb;
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    '%_transient_scolta_%'
                )
            );

            \WP_CLI::success("Scolta caches cleared (generation counter incremented, {$deleted} transients deleted).");
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Download the Pagefind binary for the current platform.
     *
     * For hosts without npm/Node.js — downloads the pre-built binary
     * directly from GitHub releases.
     *
     * @subcommand download-pagefind
     */
    public function download_pagefind(array $args, array $assoc_args): void {
        try {
            $this->do_download_pagefind();
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    private function do_download_pagefind(): void {
        $settings = get_option('scolta_settings', []);
        $target_dir = WP_CONTENT_DIR . '/scolta-bin';

        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        // Detect platform.
        $os = PHP_OS_FAMILY;
        $arch = php_uname('m');

        $platform = match (true) {
            $os === 'Linux' && $arch === 'x86_64'  => 'x86_64-unknown-linux-musl',
            $os === 'Linux' && str_contains($arch, 'aarch64') => 'aarch64-unknown-linux-musl',
            $os === 'Darwin' && str_contains($arch, 'arm') => 'aarch64-apple-darwin',
            $os === 'Darwin' => 'x86_64-apple-darwin',
            default => null,
        };

        if ($platform === null) {
            \WP_CLI::error("Unsupported platform: {$os} {$arch}. Install Pagefind via npm instead.");
            return;
        }

        // Fetch latest release version from GitHub API.
        $api_url = 'https://api.github.com/repos/CloudCannon/pagefind/releases/latest';
        $response = wp_remote_get($api_url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            \WP_CLI::error('Failed to check latest Pagefind version: ' . $response->get_error_message());
            return;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);
        $version = ltrim($release['tag_name'] ?? '', 'v');

        if (empty($version)) {
            \WP_CLI::error('Could not determine latest Pagefind version.');
            return;
        }

        $filename = "pagefind-v{$version}-{$platform}.tar.gz";
        $download_url = "https://github.com/CloudCannon/pagefind/releases/download/v{$version}/{$filename}";

        \WP_CLI::log("Downloading Pagefind v{$version} for {$platform}...");
        \WP_CLI::log("  URL: {$download_url}");

        $tmp_file = download_url($download_url, 60);
        if (is_wp_error($tmp_file)) {
            \WP_CLI::error('Download failed: ' . $tmp_file->get_error_message());
            return;
        }

        // Extract the binary.
        $target_binary = $target_dir . '/pagefind';

        // Use tar to extract.
        $result = shell_exec("tar -xzf " . escapeshellarg($tmp_file) . " -C " . escapeshellarg($target_dir) . " pagefind 2>&1");
        unlink($tmp_file);

        if (!file_exists($target_binary)) {
            \WP_CLI::error("Extraction failed. Binary not found at {$target_binary}");
            return;
        }

        chmod($target_binary, 0755);

        // Update settings to point to the downloaded binary.
        $settings['pagefind_binary'] = $target_binary;
        update_option('scolta_settings', $settings);

        \WP_CLI::success("Pagefind v{$version} installed to {$target_binary}");
        \WP_CLI::log("Settings updated — Scolta will now use this binary.");
    }

    /**
     * Run the Pagefind CLI and report results.
     */
    private function run_pagefind(string $binary, string $build_dir, string $output_dir): void {
        if (!is_dir($build_dir)) {
            \WP_CLI::error("Build directory does not exist: {$build_dir}");
            return;
        }

        $html_count = count(glob($build_dir . '/*.html') ?: []);
        if ($html_count === 0) {
            \WP_CLI::error("No HTML files in {$build_dir}. Export content first.");
            return;
        }

        if (!is_dir($output_dir)) {
            mkdir($output_dir, 0755, true);
        }

        $cmd = escapeshellcmd($binary)
            . ' --site ' . escapeshellarg($build_dir)
            . ' --output-path ' . escapeshellarg($output_dir)
            . ' 2>&1';

        \WP_CLI::log("  Running: {$cmd}");

        $output = shell_exec($cmd);
        $success = file_exists($output_dir . '/pagefind.js');

        if ($success) {
            $fragment_count = count(glob($output_dir . '/fragment/*') ?: []);
            // Increment generation counter to invalidate cached expansions/summaries.
            $generation = (int) get_option('scolta_generation', 0);
            update_option('scolta_generation', $generation + 1);
            \WP_CLI::success("Pagefind index built: {$html_count} files, {$fragment_count} fragments.");
        } else {
            \WP_CLI::error("Pagefind build failed.\n{$output}");
        }
    }

    /**
     * Join post type names for display.
     */
    private function join_types(array $types): string {
        return implode(', ', $types);
    }
}

// Register WP-CLI commands.
\WP_CLI::add_command('scolta', 'Scolta_CLI');
