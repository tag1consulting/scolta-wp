# Postmortem: WP-CLI Indexer Dispatch Regression

**Date discovered:** 2026-04-17 (Hank's bug report)  
**Date fixed:** 2026-04-18 (commit `b896f97`)  
**Severity:** High — admin indexer setting silently ignored on every `wp scolta build`  

---

## What broke

`wp scolta build` always used the `auto` indexer regardless of the admin setting. Setting `indexer = php` in Scolta's admin panel had no effect when the command was run without an explicit `--indexer` flag. The PHP indexer was never selected via admin setting; it only fired if you typed `--indexer=php` explicitly on every invocation.

---

## Root cause

The WP-CLI docblock for the `[--indexer=<indexer>]` parameter contained this block:

```
[--indexer=<indexer>]
: Which indexer to use.
---
default: auto
options:
  - auto
  - php
  - binary
---
```

The `default: auto` line is not documentation — it is a machine-readable instruction. WP-CLI's argument parser reads the docblock at dispatch time and injects `$assoc_args['indexer'] = 'auto'` into the handler's argument array on **every invocation that doesn't pass `--indexer` explicitly**. By the time the handler reads `$assoc_args`, the key is already set.

The handler used `isset($assoc_args['indexer'])` to decide whether a flag was provided:

```php
$indexer = isset( $assoc_args['indexer'] ) ? $assoc_args['indexer'] : $indexer_setting;
```

Because WP-CLI always injects the default, `isset()` always returned `true`. The admin setting (`$indexer_setting`) was permanently unreachable — `$indexer` was always `'auto'`.

Commit `a286a93` (2026-04-17) attempted to fix this by removing `get_flag_value()` in favour of `isset()`, but both helpers see the same pre-injected `$assoc_args`. The commit was a no-op against this defect.

---

## Why the 2026-04-17 test matrix missed it

The test suite called `do_build()` directly, bypassing WP-CLI's argument parser entirely:

```php
// How tests called the handler
$cli = new Scolta_CLI();
$cli->build([], ['indexer' => 'php']); // manually constructed $assoc_args
```

No test ever exercised the path where WP-CLI builds `$assoc_args` from the docblock. The injection point is in the WP-CLI framework layer, not in the PHP handler. Any test that constructs `$assoc_args` in PHP and passes it directly to the handler will never see WP-CLI's injection — no matter how carefully the handler logic is tested.

The specific scenario that needed a test was: *call `wp scolta build` with no `--indexer` flag, with `indexer = 'php'` in admin settings, and assert the PHP pipeline runs*. This requires spawning a real `wp` subprocess or asserting at the source level that the injection cannot occur (the docblock test).

---

## Fixes applied

1. **Removed `default: auto` from the docblock** (`b896f97`). WP-CLI no longer injects a value when `--indexer` is absent. The handler now correctly falls through to `$indexer_setting`.

2. **Source-parse regression test** (`test_indexer_docblock_has_no_default_injection`). Reads the CLI source file, extracts the `[--indexer]` docblock block, and asserts `default:` is absent. This guards the injection point directly — if someone re-adds the line, the test fails immediately.

3. **Subprocess integration test** (`tests/integration/test-indexer-dispatch.sh`). Spawns a real `wp scolta build` against the DDEV test site with `indexer = php` in settings and no `--indexer` flag. Asserts the PHP-indexer log line appears in the output. This is the test that would have caught the regression before it shipped.

---

## Lesson folded back into CONVENTIONS.md

See `tests/CONVENTIONS.md` for the updated rule: **integration tests must exercise WP-CLI's argument parser, not bypass it**.
