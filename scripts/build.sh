#!/bin/bash
# Universally WordPress Plugin — production build.
# Single source of truth: produces the same zip locally and in CI.
#
# Usage:
#   bash scripts/build.sh [VERSION]
#
# If VERSION is omitted, it's read from plugin/universally.php's UNIVERSALLY_VERSION
# constant. CI invokes with the tag/dispatch version; local users typically run it
# bare after `npm run release` has updated the source files.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

SLUG="universally-language-translation-multilingual-tool"
PLUGIN_DIR="plugin"
STAGE_PARENT="build/staging"
STAGE="${STAGE_PARENT}/${SLUG}"
DIST_DIR="dist"

# Resolve VERSION: explicit arg wins; otherwise read from source.
# `|| true` defers a missing/malformed source line to our own error message
# instead of letting `pipefail` exit silently.
VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    VERSION=$(grep "const UNIVERSALLY_VERSION" "${PLUGIN_DIR}/universally.php" \
        | sed -E "s/.*['\"]([^'\"]*)['\"].*/\1/" || true)
fi
if [ -z "$VERSION" ]; then
    echo "Error: could not resolve version (no arg and none found in universally.php)" >&2
    exit 1
fi
echo "Building v${VERSION}"

# Cross-platform sed -i (BSD vs GNU).
sed_in_place() {
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "$@"
    else
        sed -i "$@"
    fi
}

# ----- 1. Build panel JS -----
# Skip `npm ci` when node_modules is already populated. CI restores it from
# actions/cache (keyed on package-lock.json); locals keep theirs from prior
# `npm install`. `npm ci` always wipes and reinstalls, so guarding here avoids
# the most expensive step on cache-hit runs.
(
    cd "${PLUGIN_DIR}"
    if [ ! -d node_modules ]; then
        npm ci
    fi
    npx wp-scripts build --webpack-src-dir=panel/js --output-path=panel/build
)

# No POT step: wp.org's translate.wordpress.org auto-extracts the POT from
# plugin source on every release, so shipping one in the zip is duplicate work.

# ----- 2. Stage production tree -----
# Only clean our own staging parent — don't touch other build/* dirs developers
# might have (e.g. test scratchpads, IDE artifacts).
rm -rf "${STAGE_PARENT}" && mkdir -p "${STAGE}"

cp "${PLUGIN_DIR}/universally.php" "${STAGE}/"
cp "${PLUGIN_DIR}/uninstall.php"   "${STAGE}/"
cp "${PLUGIN_DIR}/config.php"      "${STAGE}/"
cp "${PLUGIN_DIR}/readme.txt"      "${STAGE}/"
cp -r "${PLUGIN_DIR}/app"          "${STAGE}/app"
cp -r "${PLUGIN_DIR}/includes"     "${STAGE}/includes"
cp -r "${PLUGIN_DIR}/assets"       "${STAGE}/assets"

mkdir -p "${STAGE}/panel"
cp -r "${PLUGIN_DIR}/panel/src" "${STAGE}/panel/src"

mkdir -p "${STAGE}/panel/build"
cp "${PLUGIN_DIR}/panel/build/index.js"         "${STAGE}/panel/build/"
cp "${PLUGIN_DIR}/panel/build/index.asset.php"  "${STAGE}/panel/build/"
for f in "${PLUGIN_DIR}/panel/build"/style-index*.css; do
    [ -f "$f" ] && cp "$f" "${STAGE}/panel/build/"
done
[ -d "${PLUGIN_DIR}/panel/build/blocks" ] && cp -r "${PLUGIN_DIR}/panel/build/blocks" "${STAGE}/panel/build/blocks"

cp "${PLUGIN_DIR}/composer.json" "${STAGE}/"

# ----- 3. Version-stamp the staged files (never touch source) -----
sed_in_place "s/\* Version:.*/* Version: ${VERSION}/" "${STAGE}/universally.php"
sed_in_place "s/const UNIVERSALLY_VERSION = .*/const UNIVERSALLY_VERSION = '${VERSION}';/" "${STAGE}/universally.php"
# Stable tag only updates for stable releases (no '-' suffix).
if [[ "${VERSION}" != *-* ]]; then
    sed_in_place "s/^Stable tag:.*/Stable tag: ${VERSION}/" "${STAGE}/readme.txt"
fi

# ----- 4. Composer production deps -----
( cd "${STAGE}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )
rm -f "${STAGE}/composer.lock"

# ----- 5. Strip dev/OS files -----
find "${STAGE}" \( -name ".DS_Store" -o -name ".gitkeep" -o -name "Thumbs.db" -o -name "*.map" \) -delete 2>/dev/null || true

# ----- 6. Zip -----
mkdir -p "${DIST_DIR}"
ZIP_PATH="${DIST_DIR}/${SLUG}-${VERSION}.zip"
( cd "${STAGE_PARENT}" && zip -r "../../${ZIP_PATH}" "${SLUG}" -q )
echo "Built ${ZIP_PATH}"
