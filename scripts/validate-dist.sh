#!/usr/bin/env bash
set -euo pipefail

ZIP="${1:?Usage: validate-dist.sh <zip-file>}"

unzip -l "$ZIP" > zip-contents.txt
cat zip-contents.txt

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

# No nested vendor/ directories inside vendor packages
if grep -qE 'scolta/vendor/[^/]+/vendor/' zip-contents.txt; then
  echo "ERROR: Archive contains nested vendor/ directories (path-repo build leak):"
  grep -E 'scolta/vendor/[^/]+/vendor/' zip-contents.txt | head -20
  exit 1
fi

# No tests/ or test/ content in vendor directories
if grep -qE 'scolta/vendor/.+/tests/' zip-contents.txt; then
  echo "ERROR: Archive contains vendor tests/ content (must be excluded):"
  grep -E 'scolta/vendor/.+/tests/' zip-contents.txt
  exit 1
fi
if grep -qE 'scolta/vendor/.+/test/' zip-contents.txt; then
  echo "ERROR: Archive contains vendor test/ content (must be excluded):"
  grep -E 'scolta/vendor/.+/test/' zip-contents.txt
  exit 1
fi

# Disallowed extensions (WP.org review requirements)
FAIL=0
for ext in '.sha256' '.toml' '.dist' '.neon'; do
  if grep -qE "\\${ext}$" zip-contents.txt; then
    echo "ERROR: Archive contains ${ext} files:"
    grep -E "\\${ext}$" zip-contents.txt
    FAIL=1
  fi
done
if grep -qE 'phpunit\.xml' zip-contents.txt; then
  echo "ERROR: Archive contains phpunit.xml files:"
  grep -E 'phpunit\.xml' zip-contents.txt
  FAIL=1
fi
if grep -q '\.log$' zip-contents.txt; then
  echo "ERROR: Archive contains .log files:"
  grep '\.log$' zip-contents.txt
  FAIL=1
fi
# Dependency LICENSE files are ALLOWED (required by their licenses)
# .wasm files are ALLOWED (runtime asset)
# .pagefind files are ALLOWED (bundled indexer runtime)
[ $FAIL -eq 1 ] && exit 1

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

echo "Archive structure OK: correct directory, vendor/autoload.php, scolta.php all present, no forbidden files."
rm -f zip-contents.txt
