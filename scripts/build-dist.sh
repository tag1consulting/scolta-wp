#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:-dev}"
STAGE=$(mktemp -d)
PKG="scolta"

# Root files — enumerated allowlist
for f in scolta.php readme.txt LICENSE composer.json composer.lock \
         uninstall.php README.md; do
  [ -f "$f" ] && cp "$f" "$STAGE/"
done

# Source dirs — PHP only
for dir in admin includes cli; do
  if [ -d "$dir" ]; then
    find "$dir" -name '*.php' | while read -r f; do
      mkdir -p "$STAGE/$(dirname "$f")"
      cp "$f" "$STAGE/$f"
    done
  fi
done

# Assets — CSS, JS, WASM only
if [ -d "assets" ]; then
  find assets \( -name '*.css' -o -name '*.js' -o -name '*.wasm' \) | while read -r f; do
    mkdir -p "$STAGE/$(dirname "$f")"
    cp "$f" "$STAGE/$f"
  done
fi

# vendor/ — full tree minus dev cruft
cp -a vendor "$STAGE/vendor"
find "$STAGE/vendor" -type d \( -name tests -o -name test \) -exec rm -rf {} + 2>/dev/null || true
find "$STAGE/vendor" \( \
  -name 'phpunit.xml*' -o -name 'phpstan.neon*' -o -name '.php-cs-fixer*' \
  -o -name '*.sha256' -o -name '*.toml' -o -name '.deepsource.toml' \
\) -delete 2>/dev/null || true

# Exclude duplicate WASM from vendor (plugin ships its own copy in assets/wasm/)
rm -rf "$STAGE/vendor/tag1/scolta-php/assets/wasm" 2>/dev/null || true

cd "$(dirname "$STAGE")"
rm -rf "$PKG"
mv "$STAGE" "$PKG"
zip -r "$OLDPWD/$PKG-${VERSION}.zip" "$PKG/"
rm -rf "$PKG"
