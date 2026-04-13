<?php
/**
 * PHPUnit bootstrap for scolta-wp tests.
 *
 * Provides minimal WordPress function stubs so plugin classes can be
 * loaded and tested without a full WordPress installation. These stubs
 * only define what scolta-wp actually calls — not the entire WP API.
 */

// WordPress constants expected by the plugin.
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}

// Create the fake WP admin directory so require_once in Tracker doesn't fail.
@mkdir(ABSPATH . 'wp-admin/includes', 0755, true);
if (!file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
    file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n// Stub for testing.\n");
}
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

// Plugin constants are defined by scolta.php when it's loaded below.
// We don't pre-define them here to avoid redefinition warnings.

// ---------------------------------------------------------------------------
// Minimal WordPress function stubs
// ---------------------------------------------------------------------------

// Options store (in-memory for tests).
$GLOBALS['wp_options'] = [];

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) {
        return $GLOBALS['wp_options'][$name] ?? $default;
    }
}
if (!function_exists('add_option')) {
    function add_option(string $name, $value = '', string $deprecated = '', $autoload = 'yes'): bool {
        $GLOBALS['wp_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool {
        $GLOBALS['wp_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $name): bool {
        unset($GLOBALS['wp_options'][$name]);
        return true;
    }
}

// Transients (backed by options store).
if (!function_exists('get_transient')) {
    function get_transient(string $name) {
        return $GLOBALS['wp_options']['_transient_' . $name] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $name, $value, int $expiration = 0): bool {
        $GLOBALS['wp_options']['_transient_' . $name] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $name): bool {
        unset($GLOBALS['wp_options']['_transient_' . $name]);
        return true;
    }
}

// Hook stubs (no-ops for unit tests).
if (!function_exists('add_action')) {
    function add_action(string $tag, $callback, int $priority = 10, int $args = 1): bool { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter(string $tag, $callback, int $priority = 10, int $args = 1): bool { return true; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) { return $value; }
}
if (!function_exists('do_action')) {
    function do_action(string $tag, ...$args): void {}
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, $callback): void {}
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, $callback): void {}
}

// Post stubs.
if (!function_exists('get_post')) {
    function get_post($post = null, string $output = 'OBJECT', string $filter = 'raw') {
        return $post;
    }
}
if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision($post): bool { return false; }
}
if (!function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave($post): bool { return false; }
}
if (!function_exists('get_permalink')) {
    function get_permalink($post = 0): string { return 'https://example.com/post/' . $post; }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string {
        return match ($show) {
            'name' => 'Test WordPress Site',
            'url' => 'https://example.com',
            default => '',
        };
    }
}

// Sanitization stubs.
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string { return trim(strip_tags($str)); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $str): string { return strip_tags($str); }
}

// Escaping stubs.
if (!function_exists('esc_html')) {
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url(string $url): string { return filter_var($url, FILTER_SANITIZE_URL) ?: ''; }
}

// i18n stubs.
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string { return $text; }
}
if (!function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void { echo $text; }
}

// Settings API stubs.
if (!function_exists('register_setting')) {
    function register_setting(string $group, string $name, array $args = []): void {}
}
if (!function_exists('add_settings_section')) {
    function add_settings_section(string $id, string $title, $callback, string $page, array $args = []): void {}
}
if (!function_exists('add_settings_field')) {
    function add_settings_field(string $id, string $title, $callback, string $page, string $section = '', array $args = []): void {}
}
if (!function_exists('add_options_page')) {
    function add_options_page(string $title, string $menu, string $cap, string $slug, $callback = '', ?int $pos = null): string { return $slug; }
}

// REST API stubs (with optional tracking for tests).
if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool {
        if (isset($GLOBALS['scolta_registered_routes'])) {
            $GLOBALS['scolta_registered_routes'][] = ['namespace' => $namespace, 'route' => $route];
        }
        return true;
    }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string { return 'https://example.com/wp-json/' . ltrim($path, '/'); }
}

// Script/style stubs (with optional tracking for tests).
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], $ver = false, $args = []): void {
        if (isset($GLOBALS['scolta_enqueued_scripts'])) {
            $GLOBALS['scolta_enqueued_scripts'][] = $handle;
        }
    }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = false, string $media = 'all'): void {
        if (isset($GLOBALS['scolta_enqueued_styles'])) {
            $GLOBALS['scolta_enqueued_styles'][] = $handle;
        }
    }
}
if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $name, array $data): bool {
        if (isset($GLOBALS['scolta_localized_scripts'])) {
            $GLOBALS['scolta_localized_scripts'][$handle] = $data;
        }
        return true;
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string { return 'test-nonce-' . md5($action); }
}

// Shortcode stub (with optional tracking for tests).
if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void {
        if (isset($GLOBALS['scolta_registered_shortcodes'])) {
            $GLOBALS['scolta_registered_shortcodes'][$tag] = $callback;
        }
    }
}

// User capability stubs.
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool { return true; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $data): string { return $data; }
}

// Dashboard widget stubs.
if (!function_exists('wp_add_dashboard_widget')) {
    function wp_add_dashboard_widget(string $id, string $name, $callback, $control_cb = null, $callback_args = null): void {
        if (!isset($GLOBALS['wp_meta_boxes'])) {
            $GLOBALS['wp_meta_boxes'] = [];
        }
        $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'][$id] = [
            'id' => $id, 'title' => $name, 'callback' => $callback,
        ];
    }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string {
        $field = '<input type="hidden" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="test-nonce" />';
        if ($echo) { echo $field; }
        return $field;
    }
}
if (!function_exists('submit_button')) {
    function submit_button(string $text = 'Save Changes', string $type = 'primary', string $name = 'submit', bool $wrap = true): void {
        echo '<input type="submit" name="' . esc_attr($name) . '" class="button button-' . esc_attr($type) . '" value="' . esc_attr($text) . '" />';
    }
}
if (!function_exists('human_time_diff')) {
    function human_time_diff(int $from, int $to = 0): string {
        $to = $to === 0 ? time() : $to;
        $diff = abs($to - $from);
        if ($diff < 60) { return "{$diff} seconds"; }
        $min = (int) ($diff / 60);
        return "{$min} minutes";
    }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null, ?object $timezone = null): string {
        return date($format, $timestamp ?? time());
    }
}
if (!function_exists('checked')) {
    function checked($checked, $current = true, bool $echo = true): string {
        $result = (string) $checked === (string) $current ? ' checked="checked"' : '';
        if ($echo) { echo $result; }
        return $result;
    }
}
if (!function_exists('get_admin_page_title')) {
    function get_admin_page_title(): string { return 'Scolta AI Search'; }
}
if (!function_exists('settings_errors')) {
    function settings_errors(string $setting = '', bool $sanitize = false, bool $hide_on_update = false): void {}
}
if (!function_exists('settings_fields')) {
    function settings_fields(string $option_group): void {}
}
if (!function_exists('do_settings_sections')) {
    function do_settings_sections(string $page): void {}
}
if (!function_exists('get_current_screen')) {
    function get_current_screen(): ?object { return null; }
}

// Action Scheduler stubs.
if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = [], $group = '') { return 1; }
}
if (!function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions($hook, $args = null, $group = '') {}
}

// Misc stubs.
if (!function_exists('is_admin')) {
    function is_admin(): bool { return false; }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string { return '/wp-content/plugins/' . basename(dirname($file)) . '/'; }
}
if (!function_exists('wp_count_posts')) {
    function wp_count_posts(string $type = 'post'): object {
        return (object) ['publish' => 0, 'draft' => 0, 'trash' => 0];
    }
}

// WP_REST_Request stub (minimal).
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $params = [];
        public function __construct(string $method = 'GET', string $route = '') {}
        public function set_param(string $key, $value): void { $this->params[$key] = $value; }
        public function get_param(string $key) { return $this->params[$key] ?? null; }
        public function get_params(): array { return $this->params; }
    }
}

// WP_REST_Response stub (minimal).
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public int $status;
        public array $headers = [];
        public function __construct($data = null, int $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
        public function header(string $name, string $value): void {
            $this->headers[$name] = $value;
        }
        public function get_headers(): array { return $this->headers; }
    }
}

// WP_Post stub (minimal).
if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_title = '';
        public string $post_content = '';
        public string $post_date = '';
        public string $post_modified = '';

        public static function make(array $props): self {
            $post = new self();
            foreach ($props as $k => $v) { $post->$k = $v; }
            return $post;
        }
    }
}

// wpdb stub (minimal).
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public string $posts = 'wp_posts';
        public function prepare(string $query, ...$args): string { return vsprintf(str_replace('%s', "'%s'", $query), $args); }
        public function query(string $query) { return 0; }
        public function get_results(string $query, string $output = 'OBJECT'): array { return []; }
        public function get_var(string $query) { return null; }
        public function update(string $table, array $data, array $where): int { return 1; }
        public function replace(string $table, array $data): int { return 1; }
        public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
    };
}

// dbDelta stub.
if (!function_exists('dbDelta')) {
    function dbDelta(string $sql): array { return []; }
}

// Drupal\Core\Site\Settings stub (used in AI service via fallback).
// Not needed — WP plugin doesn't use Drupal Settings.

// Additional function stubs for tests.
if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql', bool $gmt = false): string {
        return gmdate('Y-m-d H:i:s');
    }
}
if (!function_exists('site_url')) {
    function site_url(string $path = ''): string { return 'https://example.com' . $path; }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string { return 'https://example.com/wp-admin/' . ltrim($path, '/'); }
}
if (!function_exists('content_url')) {
    function content_url(string $path = ''): string { return 'https://example.com/wp-content' . $path; }
}
if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path(string $path): string { return str_replace('\\', '/', $path); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
}
if (!function_exists('setup_postdata')) {
    function setup_postdata($post): bool { return true; }
}
if (!function_exists('wp_reset_postdata')) {
    function wp_reset_postdata(): void {}
}
if (!function_exists('get_the_title')) {
    function get_the_title($post = 0): string {
        if ($post instanceof WP_Post) { return $post->post_title; }
        return '';
    }
}
if (!function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names'): array { return []; }
}
if (!function_exists('get_the_date')) {
    function get_the_date(string $format = '', $post = null): string {
        if ($post instanceof WP_Post && !empty($post->post_date)) {
            return date($format ?: 'F j, Y', strtotime($post->post_date));
        }
        return date($format ?: 'F j, Y');
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array {
        return [
            'basedir' => WP_CONTENT_DIR . '/uploads',
            'baseurl' => 'https://example.com/wp-content/uploads',
            'path' => WP_CONTENT_DIR . '/uploads/' . date('Y/m'),
            'url' => 'https://example.com/wp-content/uploads/' . date('Y/m'),
        ];
    }
}
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string {
        return 'test-salt-' . $scheme;
    }
}

// WP_Query stub (minimal).
if (!class_exists('WP_Query')) {
    class WP_Query {
        public array $posts = [];
        public int $max_num_pages = 0;
        public function __construct(array $args = []) {}
    }
}

// Load Composer autoloader (for scolta-php classes).
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load the full plugin file. Hook registrations are no-ops via our stubs.
// scolta.php includes all the class files and defines activate/deactivate.
require_once dirname(__DIR__) . '/scolta.php';
