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
# service without explicit admin opt-in. Flip the auto-provision default to
# false in the staged copy only. Fail closed if the expected define line is
# not found exactly once — a drifted scolta.php must break the build, not
# silently ship an auto-provisioning zip.
DEFINE_TRUE="define( 'SCOLTA_AUTO_PROVISION_DEFAULT', true );"
DEFINE_FALSE="define( 'SCOLTA_AUTO_PROVISION_DEFAULT', false );"
COUNT=$(grep -cF "$DEFINE_TRUE" "$STAGE/scolta.php" || true)
if [ "$COUNT" -ne 1 ]; then
  echo "ERROR: expected exactly 1 occurrence of \"$DEFINE_TRUE\" in scolta.php, found $COUNT." >&2
  echo "The opt-in flip in build-dist.sh no longer matches scolta.php — fix one of them." >&2
  exit 1
fi
sed -i.bak "s/$DEFINE_TRUE/$DEFINE_FALSE/" "$STAGE/scolta.php"
rm -f "$STAGE/scolta.php.bak"
if [ "$(grep -cF "$DEFINE_FALSE" "$STAGE/scolta.php")" -ne 1 ]; then
  echo "ERROR: auto-provision default flip did not take effect in staged scolta.php." >&2
  exit 1
fi

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
