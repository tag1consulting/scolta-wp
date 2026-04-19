# Scolta-WP Test Conventions

## General principles

- Tests run without a live WordPress database via the stub bootstrap (`tests/bootstrap.php`). WP functions like `get_option`, `update_option`, `set_transient`, and `wp_salt` are stubbed in-process.
- All new public methods MUST have unit tests. All CLI commands MUST have source-parse tests covering their docblocks.
- Every regression gets a test named after the broken behaviour, not the fix.

---

## WP-CLI commands: always test the framework layer

**Rule: integration tests for WP-CLI commands must exercise WP-CLI's argument parser, not bypass it.**

### Why this matters

WP-CLI reads command docblocks at dispatch time and injects default values into `$assoc_args` before the PHP handler runs. A docblock like:

```
[--indexer=<indexer>]
: ...
---
default: auto
---
```

causes WP-CLI to inject `$assoc_args['indexer'] = 'auto'` on every invocation without an explicit flag. No amount of `isset()` or `get_flag_value()` in the PHP handler can distinguish an injected default from an explicit flag — they see the same `$assoc_args` array.

Tests that construct `$assoc_args` directly in PHP and call the handler method bypass this layer entirely:

```php
// THIS IS NOT SUFFICIENT for WP-CLI dispatch testing:
$cli = new Scolta_CLI();
$cli->build([], ['indexer' => 'php']);  // $assoc_args manually built — WP-CLI never touched it
```

This pattern tests the PHP handler logic but not the WP-CLI integration. It will pass even when the docblock has a broken `default:` that would override every real invocation.

### What you MUST do instead

For every WP-CLI command that reads from `$assoc_args`:

1. **Source-parse test**: Assert the docblock has no `default:` on parameters where admin settings should take precedence when no flag is passed. See `test_indexer_docblock_has_no_default_injection()` as the canonical example.

2. **Subprocess integration test**: For the critical dispatch paths, add a shell-script integration test (in `tests/integration/`) that spawns a real `wp` process with specific settings and no explicit flags, and asserts the correct code path executed. These tests require DDEV running (`ddev exec`) and are marked as DDEV-only — they are NOT run in the unit-test CI matrix.

3. **Unit test for handler logic**: Continue testing the handler logic directly (correct when `$assoc_args` is explicitly passed), but label it clearly so future maintainers understand what it does and doesn't cover.

### Historical incident

2026-04-17: `wp scolta build` ignored the admin `indexer` setting because the `[--indexer]` docblock had `default: auto`. WP-CLI injected the default on every invocation. Unit tests passed because they bypassed the injection layer. See `POSTMORTEM-dispatch-regression.md` for full details.

---

## Transient-based UI state

- Transients used for one-time admin notices MUST be deleted on first read, not after TTL expiry.
- The TTL is a failsafe, not the primary dismissal mechanism.
- Tests MUST assert the transient is absent after `maybe_show_*` runs.

---

## Source-parse tests

Source-parse tests (`assertStringContainsString` / `assertStringNotContainsString` / `assertDoesNotMatchRegularExpression` against the raw PHP file) are a legitimate and important test class for catching configuration errors that unit-testing the runtime logic cannot catch:

- WP-CLI docblock defaults
- Prohibited function usage (`get_flag_value`, `serialize`)
- Required guard patterns (`check_admin_referer`, `current_user_can`)

These tests are cheap, fast, and prevent an entire class of regressions. Add one whenever you remove a dangerous pattern from source.
