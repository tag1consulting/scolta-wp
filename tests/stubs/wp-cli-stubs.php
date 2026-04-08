<?php
/**
 * Minimal WP_CLI stubs for testing CLI class structure.
 *
 * The CLI class is only loaded when WP_CLI is defined. These stubs
 * provide just enough of the WP_CLI API to load and inspect the class.
 */

class WP_CLI {
    public static function add_command(string $name, $callable, array $args = []): void {}
    public static function log(string $msg): void {}
    public static function success(string $msg): void {}
    public static function warning(string $msg): void {}
    public static function error(string $msg, bool $exit = true): void {
        if ($exit) {
            throw new \RuntimeException($msg);
        }
    }
}
