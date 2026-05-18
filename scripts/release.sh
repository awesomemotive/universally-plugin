#!/bin/bash
# Universally WordPress Plugin — release script.
# Bumps version, builds the production tree, zips it, commits + tags + pushes.
# CI (.github/workflows/release.yml) picks up the tag and deploys to wp.org SVN.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

PLUGIN_DIR="plugin"
PLUGIN_NAME="universally-language-translation-multilingual-tool"
BUILD_DIR="build"
DIST_DIR="dist"

print_message() { echo -e "${2}${1}${NC}"; }

get_current_version() {
    grep "const UNIVERSALLY_VERSION" "${PLUGIN_DIR}/universally.php" | sed "s/.*'\(.*\)'.*/\1/"
}

bump_version() {
    local version="$1" type="$2" major minor patch
    local stable="${version%%-*}"
    major=$(echo "$stable" | cut -d. -f1)
    minor=$(echo "$stable" | cut -d. -f2)
    patch=$(echo "$stable" | cut -d. -f3)

    # If current is a prerelease and we're picking "patch", promote to the
    # stable form (no increment) — the prerelease was preparing for this X.Y.Z.
    if [ "$stable" != "$version" ] && [ "$type" = "patch" ]; then
        echo "$stable"
        return
    fi

    case "$type" in
        patch) patch=$((patch + 1)) ;;
        minor) minor=$((minor + 1)); patch=0 ;;
        major) major=$((major + 1)); minor=0; patch=0 ;;
    esac
    echo "${major}.${minor}.${patch}"
}

next_prerelease_for() {
    # Args: base_version (X.Y.Z), channel ("beta" or "rc")
    # Returns: ${base}-${channel}.${N} where N is one greater than the highest
    # existing tag for that base+channel, or 1 if none exist.
    local base="$1" channel="$2" last
    last=$(git tag --list "v${base}-${channel}.*" 2>/dev/null \
        | sed -E "s/^v${base}-${channel}\\.//" \
        | sort -n | tail -n1)
    if [ -z "$last" ]; then
        echo "${base}-${channel}.1"
    else
        echo "${base}-${channel}.$((last + 1))"
    fi
}

update_version_in_file() {
    local new_version="$1" plugin_file="$2"
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s/\* Version:.*/* Version: ${new_version}/" "$plugin_file"
        sed -i '' "s/const UNIVERSALLY_VERSION = .*/const UNIVERSALLY_VERSION = '${new_version}';/" "$plugin_file"
    else
        sed -i "s/\* Version:.*/* Version: ${new_version}/" "$plugin_file"
        sed -i "s/const UNIVERSALLY_VERSION = .*/const UNIVERSALLY_VERSION = '${new_version}';/" "$plugin_file"
    fi
}

# Update wp.org readme's "Stable tag:" header. Only call for stable releases —
# wp.org's "Stable tag" must point to the latest released stable version,
# never a beta/rc.
update_readme_stable_tag() {
    local new_version="$1" readme_file="$2"
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s/^Stable tag:.*/Stable tag: ${new_version}/" "$readme_file"
    else
        sed -i "s/^Stable tag:.*/Stable tag: ${new_version}/" "$readme_file"
    fi
}

update_pkg_version() {
    local new_version="$1" pkg_file="$2"
    node -e "
const fs=require('fs');
const p=JSON.parse(fs.readFileSync('${pkg_file}','utf8'));
p.version='${new_version}';
fs.writeFileSync('${pkg_file}', JSON.stringify(p, null, 2) + '\n');
"
}

file_size_human() {
    local f="$1" s
    if [[ "$OSTYPE" == "darwin"* ]]; then
        s=$(stat -f%z "$f" 2>/dev/null || echo "0")
    else
        s=$(stat -c%s "$f" 2>/dev/null || echo "0")
    fi
    [ "$s" -gt 0 ] && echo "$(( s / 1024 ))KB" || echo "unknown size"
}

# Production-clean staging tree.
stage_production_tree() {
    local version="$1"
    local stage="${BUILD_DIR}/dist"
    rm -rf "$stage"
    mkdir -p "${stage}/${PLUGIN_NAME}"

    if [ ! -f "${PLUGIN_DIR}/panel/build/index.js" ]; then
        print_message "Error: Panel JS not built. Building now..." "$YELLOW"
        (cd "${PLUGIN_DIR}" && npm ci && npx wp-scripts build --webpack-src-dir=panel/js --output-path=panel/build)
    fi

    print_message "Copying production files..." "$YELLOW"
    cp "${PLUGIN_DIR}/universally.php" "${stage}/${PLUGIN_NAME}/"
    cp "${PLUGIN_DIR}/uninstall.php"   "${stage}/${PLUGIN_NAME}/"
    cp "${PLUGIN_DIR}/config.php"      "${stage}/${PLUGIN_NAME}/"
    cp "${PLUGIN_DIR}/readme.txt"      "${stage}/${PLUGIN_NAME}/"
    cp -r "${PLUGIN_DIR}/app"          "${stage}/${PLUGIN_NAME}/app"
    cp -r "${PLUGIN_DIR}/includes"     "${stage}/${PLUGIN_NAME}/includes"
    cp -r "${PLUGIN_DIR}/assets"       "${stage}/${PLUGIN_NAME}/assets"
#    cp -r "${PLUGIN_DIR}/languages"    "${stage}/${PLUGIN_NAME}/languages"

    mkdir -p "${stage}/${PLUGIN_NAME}/panel/build"
    cp "${PLUGIN_DIR}/panel/build/index.js"         "${stage}/${PLUGIN_NAME}/panel/build/"
    cp "${PLUGIN_DIR}/panel/build/index.asset.php"  "${stage}/${PLUGIN_NAME}/panel/build/"
    for f in "${PLUGIN_DIR}/panel/build"/style-index*.css; do
        [ -f "$f" ] && cp "$f" "${stage}/${PLUGIN_NAME}/panel/build/"
    done
    [ -d "${PLUGIN_DIR}/panel/build/blocks" ] && \
        cp -r "${PLUGIN_DIR}/panel/build/blocks" "${stage}/${PLUGIN_NAME}/panel/build/blocks"
    cp -r "${PLUGIN_DIR}/panel/src" "${stage}/${PLUGIN_NAME}/panel/src"
    cp "${PLUGIN_DIR}/composer.json" "${stage}/${PLUGIN_NAME}/"

    update_version_in_file "$version" "${stage}/${PLUGIN_NAME}/universally.php"

    if command -v composer &>/dev/null; then
        print_message "Running composer optimization..." "$YELLOW"
        (cd "${stage}/${PLUGIN_NAME}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
        rm -f "${stage}/${PLUGIN_NAME}/composer.lock"
    else
        print_message "Composer not found, copying existing vendor..." "$YELLOW"
        cp -r "${PLUGIN_DIR}/vendor" "${stage}/${PLUGIN_NAME}/vendor"
    fi

    find "$stage" \( -name ".DS_Store" -o -name ".gitkeep" -o -name "Thumbs.db" -o -name "*.map" \) -delete 2>/dev/null || true

    local zip_path="${DIST_DIR}/${PLUGIN_NAME}-${version}.zip"
    mkdir -p "$DIST_DIR"
    print_message "Creating distribution zip..." "$YELLOW"
    (cd "$stage" && zip -r "../../${zip_path}" "${PLUGIN_NAME}" -q)

    rm -rf "${DIST_DIR:?}/${PLUGIN_NAME}"
    unzip -q "$zip_path" -d "$DIST_DIR"
    print_message "Distribution: ${zip_path} ($(file_size_human "$zip_path"))" "$GREEN"
    print_message "Extracted:    ${DIST_DIR}/${PLUGIN_NAME}/" "$GREEN"
}

confirm_clean_worktree() {
    if [ -n "$(git status --porcelain)" ]; then
        print_message "Error: working tree is not clean. Commit or stash first." "$RED"
        git status --short
        exit 1
    fi
}

main() {
    print_message "===================================" "$GREEN"
    print_message " Universally WordPress Plugin Release" "$GREEN"
    print_message "===================================" "$GREEN"
    echo

    if [ ! -d "$PLUGIN_DIR" ]; then
        print_message "Error: plugin directory '${PLUGIN_DIR}' not found at ${REPO_ROOT}/${PLUGIN_DIR}" "$RED"
        exit 1
    fi

    confirm_clean_worktree

    local current_version v_patch v_minor v_major
    current_version=$(get_current_version)
    v_patch=$(bump_version "$current_version" "patch")
    v_minor=$(bump_version "$current_version" "minor")
    v_major=$(bump_version "$current_version" "major")

    print_message "Current version: ${current_version}" "$YELLOW"
    echo

    # Step 1: bump type — what version are we targeting?
    print_message "Select bump type:" "$CYAN"
    print_message "  1) patch    → ${v_patch}" "$NC"
    print_message "  2) minor    → ${v_minor}" "$NC"
    print_message "  3) major    → ${v_major}    (extra confirmation)" "$NC"
    print_message "  4) custom   (enter the exact version — skips channel prompt)" "$NC"
    print_message "  5) rebuild  ${current_version}    (force re-release; skips channel prompt)" "$NC"
    echo
    read -rp "Bump [1]: " bump_choice
    bump_choice=${bump_choice:-1}

    local base_version new_version force_tag=false skip_channel=false
    case "$bump_choice" in
        1) base_version="$v_patch" ;;
        2) base_version="$v_minor" ;;
        3)
            print_message "WARNING: major version bump (${current_version} → ${v_major})." "$RED"
            read -rp "Type 'major' to confirm: " confirm
            [ "$confirm" = "major" ] || { print_message "Aborted." "$RED"; exit 1; }
            base_version="$v_major"
            ;;
        4)
            read -rp "Enter version: " new_version
            skip_channel=true
            ;;
        5)
            print_message "Rebuilding ${current_version}. This force-replaces the existing tag." "$YELLOW"
            read -rp "Type 'rebuild' to confirm: " confirm
            [ "$confirm" = "rebuild" ] || { print_message "Aborted." "$RED"; exit 1; }
            new_version="$current_version"
            force_tag=true
            skip_channel=true
            ;;
        *) print_message "Invalid choice." "$RED"; exit 1 ;;
    esac

    # Step 2: channel — stable, beta, or rc?
    if [ "$skip_channel" = false ]; then
        local beta_preview rc_preview
        beta_preview=$(next_prerelease_for "$base_version" "beta")
        rc_preview=$(next_prerelease_for "$base_version" "rc")
        echo
        print_message "Select channel:" "$CYAN"
        print_message "  1) stable   → ${base_version}" "$NC"
        print_message "  2) beta     → ${beta_preview}" "$NC"
        print_message "  3) rc       → ${rc_preview}" "$NC"
        echo
        read -rp "Channel [1]: " ch_choice
        ch_choice=${ch_choice:-1}

        case "$ch_choice" in
            1) new_version="$base_version" ;;
            2) new_version="$beta_preview" ;;
            3) new_version="$rc_preview" ;;
            *) print_message "Invalid choice." "$RED"; exit 1 ;;
        esac
    fi

    if ! [[ $new_version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?$ ]]; then
        print_message "Error: invalid version format. Use semver (e.g., 1.0.0 or 1.0.0-beta.1)" "$RED"
        exit 1
    fi

    echo
    print_message "Releasing v${new_version}..." "$GREEN"

    local is_stable=false
    if [[ ! "$new_version" =~ - ]]; then
        is_stable=true
    fi

    if [ "$force_tag" = false ]; then
        update_version_in_file "$new_version" "${PLUGIN_DIR}/universally.php"
        update_pkg_version "$new_version" "${PLUGIN_DIR}/package.json"
        if [ "$is_stable" = true ]; then
            update_readme_stable_tag "$new_version" "${PLUGIN_DIR}/readme.txt"
        fi
    fi

    stage_production_tree "$new_version"
    rm -rf "$BUILD_DIR"

    if [ "$force_tag" = false ]; then
        git add "${PLUGIN_DIR}/universally.php" "${PLUGIN_DIR}/package.json"
        if [ "$is_stable" = true ]; then
            git add "${PLUGIN_DIR}/readme.txt"
        fi
        git commit -m "Release ${new_version}"
    fi

    if [ "$force_tag" = true ]; then
        git tag -d "v${new_version}" 2>/dev/null || true
        git push --delete origin "v${new_version}" 2>/dev/null || true
        git tag -a "v${new_version}" -m "Release ${new_version}"
        git push --follow-tags
    else
        git tag -a "v${new_version}" -m "Release ${new_version}"
        git push --follow-tags
    fi

    echo
    print_message "Pushed v${new_version}. CI will deploy to wp.org SVN if this is a stable tag." "$GREEN"
}

main "$@"
