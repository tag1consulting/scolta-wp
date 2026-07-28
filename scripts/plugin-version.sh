#!/usr/bin/env bash
#
# Print the plugin version.
#
# The plugin header in scolta.php is the source of the version: it is what
# WordPress itself reads, and it is kept in step with the SCOLTA_VERSION
# constant and readme.txt's Stable Tag by scripts/validate-release.php.
#
# composer.json deliberately declares no "version" key. Declaring one in a
# package published from version control overrides the version Composer
# derives from the branch or tag, which is what the extra.branch-alias beside
# it exists to describe. Everything that used to read the version out of
# composer.json reads it through this script instead, so there is one place
# to change if the source ever moves.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_FILE="$SCRIPT_DIR/../scolta.php"

VERSION=$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' "$PLUGIN_FILE" \
  | sed -E 's/.*Version:[[:space:]]*//' \
  | tr -d '[:space:]')

if [ -z "$VERSION" ]; then
  echo "ERROR: no 'Version:' header found in $PLUGIN_FILE" >&2
  exit 1
fi

printf '%s\n' "$VERSION"
