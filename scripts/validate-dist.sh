#!/usr/bin/env bash
set -euo pipefail

ZIP="${1:?Usage: validate-dist.sh <zip-file>}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST="$SCRIPT_DIR/../tests/fixtures/dist-manifest-nonsource.txt"

unzip -l "$ZIP" > zip-contents.txt
cat zip-contents.txt

# File listing (paths only, no directory entries) for the sweeps below.
unzip -Z1 "$ZIP" | grep -v '/$' | sort > zip-files.txt

# Top-level directory must be scolta/
if ! grep -qE '^[[:space:]]+[0-9].*scolta/$' zip-contents.txt; then
  echo "ERROR: Top-level directory is not scolta/"
  exit 1
fi

# vendor/autoload.php must be present
if ! grep -q 'scolta/vendor/autoload.php' zip-contents.txt; then
  echo "ERROR: vendor/autoload.php is missing from archive"
  exit 1
fi

# Main plugin file must be present
if ! grep -q 'scolta/scolta.php' zip-contents.txt; then
  echo "ERROR: scolta.php is missing from archive"
  exit 1
fi

# WASM must be present at the canonical plugin location
if ! grep -q 'scolta/assets/wasm/scolta_core_bg.wasm' zip-contents.txt; then
  echo "ERROR: scolta_core_bg.wasm is missing from assets/wasm/"
  exit 1
fi

# WASM must not be duplicated from vendor/tag1/scolta-php/assets/wasm/
if grep -q 'scolta/vendor/tag1/scolta-php/assets/wasm/' zip-contents.txt; then
  echo "ERROR: Archive contains duplicate WASM from vendor/tag1/scolta-php/assets/wasm/:"
  grep 'scolta/vendor/tag1/scolta-php/assets/wasm/' zip-contents.txt
  exit 1
fi

# Pagefind runtime files must be present — the indexer copies them into every
# generated index; without them client-side search breaks silently (the
# FormatWriters skip missing assets without error).
for f in pagefind.js pagefind-worker.js wasm.en.pagefind wasm.unknown.pagefind; do
  if ! grep -qx "scolta/vendor/tag1/scolta-php/assets/pagefind/$f" zip-files.txt; then
    echo "ERROR: required Pagefind runtime file missing from archive: $f"
    exit 1
  fi
done

# No nested vendor/ directories inside vendor packages
if grep -qE 'scolta/vendor/[^/]+/vendor/' zip-contents.txt; then
  echo "ERROR: Archive contains nested vendor/ directories (path-repo build leak):"
  grep -E 'scolta/vendor/[^/]+/vendor/' zip-contents.txt | head -20
  exit 1
fi

# No tests/ or test/ content in vendor directories
if grep -qE 'scolta/vendor/.+/tests?/' zip-contents.txt; then
  echo "ERROR: Archive contains vendor tests/ or test/ content (must be excluded):"
  grep -E 'scolta/vendor/.+/tests?/' zip-contents.txt
  exit 1
fi

# WP.org policy: the distributed build must default auto-provisioning OFF.
# Activation in the shipped zip must not contact any remote service until an
# administrator explicitly opts in.
if [ "$(unzip -p "$ZIP" scolta/scolta.php | grep -cF "define( 'SCOLTA_AUTO_PROVISION_DEFAULT', false );")" -ne 1 ]; then
  echo "ERROR: scolta.php in the archive does not default SCOLTA_AUTO_PROVISION_DEFAULT to false."
  echo "The WordPress.org build must be opt-in: no remote calls until the admin enables AI features."
  exit 1
fi
if unzip -p "$ZIP" scolta/scolta.php | grep -qF "define( 'SCOLTA_AUTO_PROVISION_DEFAULT', true );"; then
  echo "ERROR: scolta.php in the archive still contains the auto-provision true default."
  exit 1
fi
echo "Opt-in default OK: SCOLTA_AUTO_PROVISION_DEFAULT is false in the archive."

# ---------------------------------------------------------------------------
# Fail-closed sweep of the ENTIRE archive, vendor included. Every file must
# either match the extension allowlist or be an explicitly enumerated
# exception. Anything else fails with its path printed. This checks the
# CLASS of WP.org "not permitted files" findings, not last round's instances.
# ---------------------------------------------------------------------------
SWEEP_FAIL=0
while IFS= read -r path; do
  base="${path##*/}"
  case "$base" in
    # Extension allowlist.
    *.php|*.js|*.css|*.wasm|*.pagefind|*.json|*.lock|*.txt|*.md) continue ;;
    # Dependency license files — retained as required by their license terms
    # (e.g. Apache-2.0 §4 for voku/portable-utf8). Justified in readme.txt
    # under "Source code and compiled assets".
    LICENSE|LICENSE.*|LICENSE-*|COPYING|COPYING.*) continue ;;
  esac
  case "$path" in
    # Pagefind runtime — unmodified upstream runtime the indexer copies into
    # every generated index. Justified in readme.txt.
    scolta/vendor/tag1/scolta-php/assets/pagefind/pagefind.js|\
    scolta/vendor/tag1/scolta-php/assets/pagefind/pagefind-worker.js|\
    scolta/vendor/tag1/scolta-php/assets/pagefind/wasm.en.pagefind|\
    scolta/vendor/tag1/scolta-php/assets/pagefind/wasm.unknown.pagefind) continue ;;
    # Root metadata.
    scolta/composer.json|scolta/composer.lock) continue ;;
  esac
  echo "ERROR: file not covered by the dist allowlist: $path"
  SWEEP_FAIL=1
done < zip-files.txt
if [ "$SWEEP_FAIL" -ne 0 ]; then
  echo "Fail-closed sweep FAILED. Either the file must not ship (fix build-dist.sh),"
  echo "or it is genuinely required: add it to the allowlist here WITH a justification"
  echo "comment and document it in readme.txt under 'Source code and compiled assets'."
  exit 1
fi
echo "Fail-closed sweep OK: every file matches the extension allowlist or an enumerated exception."

# ---------------------------------------------------------------------------
# Non-source manifest under change control: the sorted list of every
# non-php/css/js file in the zip must match the committed fixture. Any new
# binary/data file fails CI until the manifest is updated in an explicit,
# justified commit. This is the reviewer-flaggable surface.
# ---------------------------------------------------------------------------
grep -vE '\.(php|css|js)$' zip-files.txt > zip-nonsource.txt || true
if [ ! -f "$MANIFEST" ]; then
  echo "ERROR: dist manifest fixture missing: $MANIFEST"
  echo "Generate it from a known-good build: grep -vE '\\.(php|css|js)\$' zip-files.txt > $MANIFEST"
  exit 1
fi
if ! diff -u "$MANIFEST" zip-nonsource.txt; then
  echo "ERROR: non-source files in the archive differ from the committed manifest."
  echo "If the change is intentional and every new file is justified, regenerate"
  echo "tests/fixtures/dist-manifest-nonsource.txt in an explicit commit."
  exit 1
fi
echo "Non-source manifest OK: archive matches tests/fixtures/dist-manifest-nonsource.txt."

# ZIP must be under 5 MB (rc4 was 2.2 MB; 5 MB gives headroom for growth)
ZIP_SIZE=$(stat --format=%s "$ZIP" 2>/dev/null || stat -f%z "$ZIP" 2>/dev/null)
MAX_SIZE=$((5 * 1024 * 1024))
if [ "$ZIP_SIZE" -gt "$MAX_SIZE" ]; then
  echo "ERROR: ZIP is $(( ZIP_SIZE / 1024 / 1024 )) MB — exceeds 5 MB limit."
  echo "This usually means dev dependencies or test fixtures leaked into the archive."
  echo "Top 20 largest files:"
  unzip -l "$ZIP" | sort -rn -k1 | head -20
  exit 1
fi
echo "ZIP size OK: $(( ZIP_SIZE / 1024 )) KB"

echo "Archive structure OK: correct directory, required files present, opt-in default false, no unjustified files."
rm -f zip-contents.txt zip-files.txt zip-nonsource.txt
