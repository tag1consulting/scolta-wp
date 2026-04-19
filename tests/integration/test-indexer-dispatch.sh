#!/usr/bin/env bash
# Integration test: WP-CLI indexer dispatch respects admin setting
#
# Requires: DDEV running with the scolta WordPress test site.
# Run from: packages/scolta-wp/ or repo root.
# Usage:    tests/integration/test-indexer-dispatch.sh [ddev-project-dir]
#
# What this tests:
#   When indexer = 'php' is set in admin and wp scolta build is invoked
#   WITHOUT --indexer flag, the PHP indexer pipeline must run (not binary).
#
# This is the test that would have caught the 2026-04-17 docblock-injection
# regression. The unit tests bypassed WP-CLI's argument parser; this test
# exercises the full dispatch path.

set -uo pipefail

DDEV_DIR="${1:-/Users/jandrews/devel/tag1/scolta/test-wordpress-7}"
PASS=0
FAIL=0

log_pass() { echo "  PASS: $1"; PASS=$((PASS + 1)); }
log_fail() { echo "  FAIL: $1"; FAIL=$((FAIL + 1)); }

echo "=== WP-CLI indexer dispatch integration test ==="
echo "    DDEV site: $DDEV_DIR"
echo ""

if ! cd "$DDEV_DIR" 2>/dev/null; then
  echo "ERROR: DDEV dir not found: $DDEV_DIR"
  exit 1
fi

if ! ddev describe 2>/dev/null | grep -qiE 'running|OK'; then
  echo "ERROR: DDEV site not running. Start it with: ddev start"
  exit 1
fi

# Helper: set scolta_settings['indexer'] via wp option patch (insert if key absent, update if present).
set_indexer() {
  local value="$1"
  local current
  current=$(ddev exec wp option get scolta_settings 2>/dev/null | grep "'indexer'" | head -1 | tr -d " '," | cut -d= -f2 || echo '')
  if [ -z "$current" ]; then
    ddev exec wp option patch insert scolta_settings indexer "$value" 2>/dev/null || true
  else
    ddev exec wp option patch update scolta_settings indexer "$value" 2>/dev/null || true
  fi
}

# --- Setup: save original indexer setting ---
ORIG_INDEXER=$(ddev exec wp option get scolta_settings 2>/dev/null \
  | grep "'indexer'" | head -1 | grep -oE "=> '[^']+'" | tr -d "=> '" || echo 'auto')
ORIG_INDEXER="${ORIG_INDEXER:-auto}"
echo "Original indexer setting: $ORIG_INDEXER"

cleanup() {
  echo ""
  echo "--- Restoring original indexer setting ($ORIG_INDEXER) ---"
  set_indexer "$ORIG_INDEXER" || true
}
trap cleanup EXIT

# --- Test 1: indexer=php in admin, no --indexer flag → PHP pipeline runs ---
echo ""
echo "Test 1: admin indexer=php, no --indexer flag"
set_indexer php
VERIFY=$(ddev exec wp option get scolta_settings 2>/dev/null | grep "'indexer'" | grep "'php'" || echo '')
if [ -z "$VERIFY" ]; then
  log_fail "Could not set indexer to php"
else
  OUTPUT=$(ddev exec wp scolta build --force 2>&1 || true)

  if echo "$OUTPUT" | grep -q "Using PHP indexer pipeline"; then
    log_pass "PHP indexer pipeline ran (log line present)"
  else
    log_fail "PHP indexer pipeline did NOT run. Output was:"
    echo "$OUTPUT" | head -20 | sed 's/^/    /'
  fi

  if echo "$OUTPUT" | grep -qiE "Pagefind binary.*resolved|pagefind --site"; then
    log_fail "Binary indexer invoked despite php admin setting"
  else
    log_pass "Binary indexer not invoked"
  fi
fi

# --- Test 2: indexer=php in admin, explicit --indexer=auto → auto resolves ---
echo ""
echo "Test 2: admin indexer=php, explicit --indexer=auto → auto pipeline (not forced php)"
set_indexer php
OUTPUT=$(ddev exec wp scolta build --indexer=auto --force 2>&1 || true)

if echo "$OUTPUT" | grep -qE "Using PHP indexer pipeline|Using Pagefind:"; then
  log_pass "Explicit --indexer=auto resolved to a valid pipeline"
else
  log_fail "Could not determine which pipeline ran. Output:"
  echo "$OUTPUT" | head -10 | sed 's/^/    /'
fi

# --- Test 3: indexer=auto in admin, no --indexer flag → auto resolves ---
echo ""
echo "Test 3: admin indexer=auto, no --indexer flag → auto resolves"
set_indexer auto
OUTPUT=$(ddev exec wp scolta build --force 2>&1 || true)

if echo "$OUTPUT" | grep -qE "Using PHP indexer pipeline|Using Pagefind:"; then
  log_pass "Auto pipeline resolved successfully"
else
  log_fail "Auto pipeline did not produce expected output. Output:"
  echo "$OUTPUT" | head -10 | sed 's/^/    /'
fi

# --- Results ---
echo ""
echo "=== Results: $PASS passed, $FAIL failed ==="
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
