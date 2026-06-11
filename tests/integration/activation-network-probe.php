<?php
/**
 * Activation-path network regression probe.
 *
 * Exercises the REAL activation layer — register_activation_hook through
 * do_action under a working hook registry — with recorders on
 * pre_http_request (WP HTTP API) and scolta_pre_auto_provision (the
 * provisioning seam, which fires before any HTTP client is constructed).
 * Reports observations as JSON on the last line of stdout.
 *
 * Modes:
 *   activate-on   SCOLTA_AUTO_PROVISION_DEFAULT=true — provisioning must be
 *                 scheduled at activation and attempted when the scheduled
 *                 action runs (intercepted, never real).
 *   activate-off  SCOLTA_AUTO_PROVISION_DEFAULT=false — activation must
 *                 perform zero outbound HTTP, AI defaults must be off, and
 *                 the opt-in pending flag must be set.
 *   optin         constant=false — activation, then the real
 *                 admin_post_scolta_enable_ai action: provisioning must run
 *                 exactly once and the AI settings must flip on.
 *
 * Run as a subprocess by tests/ActivationNetworkTest.php: the build-time
 * constant must be defined before scolta.php loads, which an in-process
 * PHPUnit test cannot do per-test.
 */

declare(strict_types=1);

$mode = $argv[1] ?? '';
if (!in_array($mode, ['activate-on', 'activate-off', 'optin'], true)) {
    fwrite(STDERR, "Usage: activation-network-probe.php <activate-on|activate-off|optin>\n");
    exit(2);
}

// Define the build-time constant before the plugin loads. The bootstrap
// requires scolta.php, whose if-!defined guard keeps this value.
define('SCOLTA_AUTO_PROVISION_DEFAULT', $mode === 'activate-on');

// ---------------------------------------------------------------------------
// Real (minimal) hook registry — defined BEFORE tests/bootstrap.php so its
// function_exists guards skip the no-op stubs. This is the layer the original
// defect lived in: activation is exercised through register_activation_hook
// and do_action, not by hand-calling scolta_activate().
// ---------------------------------------------------------------------------
$GLOBALS['probe_hooks'] = [];

function add_filter(string $tag, $callback, int $priority = 10, int $args = 1): bool {
    $GLOBALS['probe_hooks'][$tag][$priority][] = $callback;
    ksort($GLOBALS['probe_hooks'][$tag]);
    return true;
}
function add_action(string $tag, $callback, int $priority = 10, int $args = 1): bool {
    return add_filter($tag, $callback, $priority, $args);
}
function apply_filters(string $tag, $value, ...$args) {
    foreach ($GLOBALS['probe_hooks'][$tag] ?? [] as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = $cb($value, ...$args);
        }
    }
    return $value;
}
function do_action(string $tag, ...$args): void {
    foreach ($GLOBALS['probe_hooks'][$tag] ?? [] as $callbacks) {
        foreach ($callbacks as $cb) {
            $cb(...$args);
        }
    }
}
function register_activation_hook(string $file, $callback): void {
    add_action('activate_' . basename($file), $callback);
}
function register_deactivation_hook(string $file, $callback): void {
    add_action('deactivate_' . basename($file), $callback);
}

// Admin context so scolta.php loads the admin classes and their hooks.
function is_admin(): bool { return true; }

// The enable handler redirects then exits; throwing here lets the probe
// regain control after do_action() instead of dying.
class ProbeRedirect extends RuntimeException {}
function wp_safe_redirect(string $location, int $status = 302, string $x_redirect_by = 'WordPress'): bool {
    throw new ProbeRedirect($location);
}

// Track Action Scheduler calls (the bootstrap stub records into this).
$GLOBALS['scolta_as_scheduled'] = [];

require __DIR__ . '/../bootstrap.php';

// ---------------------------------------------------------------------------
// Recorders. Registered before any action fires.
// ---------------------------------------------------------------------------
$GLOBALS['probe_http_requests'] = [];
add_filter('pre_http_request', function ($pre, ...$args) {
    $GLOBALS['probe_http_requests'][] = $args;
    return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '', 'headers' => []];
}, 10, 3);

$GLOBALS['probe_provision_calls'] = 0;
add_filter('scolta_pre_auto_provision', function ($pre) {
    $GLOBALS['probe_provision_calls']++;
    return true; // Intercept: report success without any network contact.
});

// Fresh site state.
$GLOBALS['wp_options'] = [];

// ---------------------------------------------------------------------------
// Fire the real activation hook.
// ---------------------------------------------------------------------------
do_action('activate_scolta.php');

$scheduled = array_column($GLOBALS['scolta_as_scheduled'], 'hook');

// In auto-provision mode the work is deferred to the scheduled action; run it
// the way Action Scheduler would, and let the seam intercept it.
if ($mode === 'activate-on' && in_array('scolta_amazee_provision', $scheduled, true)) {
    do_action('scolta_amazee_provision');
}

$redirect = null;
if ($mode === 'optin') {
    // The real opt-in action, as admin-post.php would dispatch it.
    try {
        do_action('admin_post_scolta_enable_ai');
    } catch (ProbeRedirect $e) {
        $redirect = $e->getMessage();
    }
}

$settings = get_option('scolta_settings', []);

echo "\n" . json_encode([
    'mode'            => $mode,
    'http_requests'   => count($GLOBALS['probe_http_requests']),
    'provision_calls' => $GLOBALS['probe_provision_calls'],
    'scheduled'       => $scheduled,
    'ai_expand_query' => (bool) ($settings['ai_expand_query'] ?? false),
    'ai_summarize'    => (bool) ($settings['ai_summarize'] ?? false),
    'optin_pending'   => (bool) get_option('scolta_ai_optin_pending', false),
    'redirect'        => $redirect,
]) . "\n";
