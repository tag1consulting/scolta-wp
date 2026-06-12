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

# ---------------------------------------------------------------------------
# Outbound-HTTP surface under change control (WP.org Guidelines 7 & 9).
# Round 3's root cause was a new feature adding a remote call with nobody
# noticing. Same philosophy as the non-source manifest: the artifact's
# network surface is committed, reviewed, and any drift fails the build.
#   1. Every outbound-HTTP call site in shipped PHP (vendor INCLUDED — the
#      AI clients live there) must match the committed call-site fixture.
#   2. Every https?:// host literal in shipped FIRST-PARTY PHP (plugin code
#      plus vendor/tag1/scolta-php/src — first-party remote calls go through
#      Guzzle, so a new endpoint adds no new transport call site; the host
#      literal is what changes) must be listed in the hosts fixture.
#   3. Every host the fixture marks `service` must appear in readme.txt's
#      "== External Services ==" section — the disclosure travels in the zip.
# ---------------------------------------------------------------------------
CALLSITES_FIXTURE="$SCRIPT_DIR/../tests/fixtures/dist-network-callsites.txt"
HOSTS_FIXTURE="$SCRIPT_DIR/../tests/fixtures/dist-network-hosts.txt"

EXTRACT_DIR=$(mktemp -d)
unzip -q "$ZIP" -d "$EXTRACT_DIR"

# (1) Call-site manifest: "relative/path.php<TAB>marker", sorted, deduped.
# No line numbers — they churn; a file gaining a marker it already has is
# not a new surface.
: > network-callsites-raw.txt
HTTP_MARKERS="wp_remote_get wp_remote_post wp_remote_request wp_remote_head curl_init curl_exec fsockopen stream_socket_client"
for marker in $HTTP_MARKERS; do
  # `|| true`: a marker with zero hits is normal, not an error (pipefail).
  { grep -rlE "(^|[^A-Za-z0-9_\$])${marker}[[:space:]]*\(" --include='*.php' "$EXTRACT_DIR/scolta" 2>/dev/null || true; } \
    | while IFS= read -r f; do
        printf '%s\t%s\n' "${f#"$EXTRACT_DIR"/scolta/}" "$marker" >> network-callsites-raw.txt
      done
done
# file_get_contents / fopen count only with an http literal on the same line
# (their overwhelmingly common use is local files).
for marker in file_get_contents fopen; do
  { grep -rlE "(^|[^A-Za-z0-9_\$])${marker}[[:space:]]*\(" --include='*.php' "$EXTRACT_DIR/scolta" 2>/dev/null || true; } \
    | while IFS= read -r f; do
        if grep -E "(^|[^A-Za-z0-9_\$])${marker}[[:space:]]*\(" "$f" | grep -q "http"; then
          printf '%s\t%s\n' "${f#"$EXTRACT_DIR"/scolta/}" "${marker}+http" >> network-callsites-raw.txt
        fi
      done
done
sort -u network-callsites-raw.txt > network-callsites.txt

if [ ! -f "$CALLSITES_FIXTURE" ]; then
  echo "ERROR: network call-site fixture missing: $CALLSITES_FIXTURE"
  echo "Seed it from a known-good build (review every entry against readme.txt"
  echo "'External Services' first): cp network-callsites.txt $CALLSITES_FIXTURE"
  exit 1
fi
if ! diff -u <(grep -vE '^#|^$' "$CALLSITES_FIXTURE") network-callsites.txt; then
  echo "ERROR: New outbound-HTTP call site. Check WP.org Guidelines 7 & 9 (remote"
  echo "calls must be opt-in, OFF by default in the .org build, behind the"
  echo "SCOLTA_AUTO_PROVISION_DEFAULT / explicit-key consent gates), disclose the"
  echo "service in readme.txt External Services, then update this manifest"
  echo "(tests/fixtures/dist-network-callsites.txt) in an explicit commit."
  exit 1
fi
echo "Network call-site manifest OK: archive matches tests/fixtures/dist-network-callsites.txt."

# (2) First-party host literals must all be enumerated in the hosts fixture.
if [ ! -f "$HOSTS_FIXTURE" ]; then
  echo "ERROR: network hosts fixture missing: $HOSTS_FIXTURE"
  exit 1
fi
{ grep -rhEo 'https?://[A-Za-z0-9._-]+' --include='*.php' \
  "$EXTRACT_DIR/scolta/includes" "$EXTRACT_DIR/scolta/admin" "$EXTRACT_DIR/scolta/cli" \
  "$EXTRACT_DIR/scolta/scolta.php" "$EXTRACT_DIR/scolta/uninstall.php" \
  "$EXTRACT_DIR/scolta/vendor/tag1/scolta-php/src" 2>/dev/null || true; } \
  | sed -E 's#^https?://##' | sort -u > network-hosts-found.txt
grep -vE '^#|^$' "$HOSTS_FIXTURE" | cut -f1 | sort -u > network-hosts-allowed.txt
NEW_HOSTS=$(comm -23 network-hosts-found.txt network-hosts-allowed.txt)
GONE_HOSTS=$(comm -13 network-hosts-found.txt network-hosts-allowed.txt)
if [ -n "$NEW_HOSTS" ]; then
  echo "ERROR: first-party shipped PHP references hosts not in tests/fixtures/dist-network-hosts.txt:"
  echo "$NEW_HOSTS"
  echo "If the plugin can contact the host, it is a remote service: it must be opt-in"
  echo "(WP.org Guidelines 7 & 9) and disclosed in readme.txt External Services, then"
  echo "added to the fixture as 'service'. A documentation-only URL is added as"
  echo "'reference'. Update the fixture in an explicit commit."
  exit 1
fi
if [ -n "$GONE_HOSTS" ]; then
  echo "ERROR: tests/fixtures/dist-network-hosts.txt lists hosts no longer present in"
  echo "first-party shipped PHP (stale fixture — remove them in an explicit commit):"
  echo "$GONE_HOSTS"
  exit 1
fi
echo "Network hosts OK: first-party host literals match tests/fixtures/dist-network-hosts.txt."

# (3) Every `service` host must be disclosed in the SHIPPED readme.txt.
awk '/^== External Services ==/{f=1;next} /^== /{f=0} f' "$EXTRACT_DIR/scolta/readme.txt" > readme-external-services.txt
DISCLOSURE_FAIL=0
while IFS=$'\t' read -r host category; do
  [ "$category" = "service" ] || continue
  if ! grep -qF "$host" readme-external-services.txt; then
    echo "ERROR: host '$host' is marked 'service' in dist-network-hosts.txt but is not"
    echo "disclosed in the shipped readme.txt '== External Services ==' section."
    DISCLOSURE_FAIL=1
  fi
done < <(grep -vE '^#|^$' "$HOSTS_FIXTURE")
if [ "$DISCLOSURE_FAIL" -ne 0 ]; then
  exit 1
fi
echo "External Services disclosure OK: every contactable host is documented in readme.txt."

# ---------------------------------------------------------------------------
# Shipped-binary justification sync: every enumerated binary exception above
# must be justified by name in the shipped readme.txt under "Source code and
# compiled assets" — round 3 re-flagged binaries whose justification lived
# only in PR threads. Keep this list in sync with the presence assertions and
# the allowlist exceptions earlier in this script.
# ---------------------------------------------------------------------------
awk '/^== Source code and compiled assets ==/{f=1;next} /^== /{f=0} f' "$EXTRACT_DIR/scolta/readme.txt" > readme-compiled-assets.txt
JUSTIFY_FAIL=0
for basename in scolta_core_bg.wasm pagefind.js pagefind-worker.js wasm.en.pagefind wasm.unknown.pagefind; do
  if ! grep -qF "$basename" readme-compiled-assets.txt; then
    echo "ERROR: shipped binary '$basename' is not justified in the shipped readme.txt"
    echo "'== Source code and compiled assets ==' section. The justification must"
    echo "travel in the zip — reviewers do not read our PR threads."
    JUSTIFY_FAIL=1
  fi
done
if [ "$JUSTIFY_FAIL" -ne 0 ]; then
  exit 1
fi
echo "Binary justification OK: every enumerated binary is documented in readme.txt."

rm -rf "$EXTRACT_DIR"

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

echo "Archive structure OK: correct directory, required files present, opt-in default false, no unjustified files, network surface under change control."
rm -f zip-contents.txt zip-files.txt zip-nonsource.txt \
  network-callsites-raw.txt network-callsites.txt \
  network-hosts-found.txt network-hosts-allowed.txt \
  readme-external-services.txt readme-compiled-assets.txt
