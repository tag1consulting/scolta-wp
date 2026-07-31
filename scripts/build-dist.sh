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

# WordPress.org policy: the distributed build must not contact any remote
# service without explicit admin opt-in. Nothing is rewritten here to achieve
# that — the plugin source is opt-in on every build, and scripts/validate-dist.sh
# asserts it on the built archive.

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
find "$STAGE/vendor" -type d \( -name tests -o -name test -o -name '.github' \) -exec rm -rf {} + 2>/dev/null || true
find "$STAGE/vendor" \( \
  -name 'phpunit.xml*' -o -name 'phpstan.neon*' -o -name '.php-cs-fixer*' \
  -o -name '*.sha256' -o -name '*.toml' -o -name '.deepsource.toml' \
  -o -name '.git*' -o -name '*.yml' -o -name '*.yaml' -o -name '*.html' \
  -o -name '*.xml' -o -name '*.dist' -o -name '*.neon' -o -name '*.log' \
  -o -name 'package-lock.json' -o -name '.editorconfig' \
\) -delete 2>/dev/null || true

# Prune bundled dependency documentation from the dist. CHANGELOGs, READMEs,
# UPGRADING notes, docs/, and PROVENANCE only bloat the archive and describe
# internals no consumer of the plugin ZIP needs. LICENSE*/COPYING* files are
# kept (license terms require them; readme.txt justifies them), and the root
# scolta/README.md lives outside vendor/, so this sweep never touches it.
find "$STAGE/vendor" -type f -name '*.md' \
  ! -iname 'LICENSE*' ! -iname 'COPYING*' -delete 2>/dev/null || true

# Exclude duplicate WASM from vendor (plugin ships its own copy in assets/wasm/)
rm -rf "$STAGE/vendor/tag1/scolta-php/assets/wasm" 2>/dev/null || true

cd "$(dirname "$STAGE")"
rm -rf "$PKG"
mv "$STAGE" "$PKG"
zip -r "$OLDPWD/$PKG-${VERSION}.zip" "$PKG/"
rm -rf "$PKG"
