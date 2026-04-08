<?php
/**
 * WP_CLI\Utils function stubs for testing.
 */

namespace WP_CLI\Utils;

function get_flag_value(array $assoc_args, string $key, $default = null) {
    return $assoc_args[$key] ?? $default;
}

function make_progress_bar(string $msg, int $total) {
    return new class {
        public function tick(): void {}
        public function finish(): void {}
    };
}
