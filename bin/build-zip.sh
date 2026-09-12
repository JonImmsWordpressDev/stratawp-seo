#!/usr/bin/env bash
#
# Build a distributable plugin zip.
#
# Usage: bin/build-zip.sh --target github|wporg [--out DIR]
#
# Two targets, because the same code must behave differently depending on
# where it was installed from:
#
#   github  The zip attached to a GitHub release. Carries a .github-release
#           provenance marker, which is the ONLY thing that enables the
#           plugin's self-updater (see github_updates_enabled() in
#           stratawp-seo.php).
#
#   wporg   The zip uploaded to the WordPress.org directory. Carries no
#           marker, so the directory stays the single source of updates —
#           WordPress.org guideline 8. Also drops dev tooling the directory
#           has no use for.
#
# Both targets are checked after packing; a zip that fails its invariants is
# deleted rather than handed over.
set -euo pipefail

TARGET=""
OUT="build"
SLUG="stratawp-seo"

while [ $# -gt 0 ]; do
  case "$1" in
    --target) TARGET="${2:-}"; shift 2 ;;
    --out)    OUT="${2:-}";    shift 2 ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

case "$TARGET" in
  github|wporg) ;;
  *) echo "error: --target must be 'github' or 'wporg'" >&2; exit 2 ;;
esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION=$(grep -m1 '^ \* Version:' stratawp-seo.php | sed 's/.*Version:[[:space:]]*//')
[ -n "$VERSION" ] || { echo "error: could not read version from stratawp-seo.php" >&2; exit 1; }

STAGE="$OUT/$SLUG"
ZIP="$OUT/${SLUG}$([ "$TARGET" = wporg ] && echo '-wporg' || echo '').zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

# Shared excludes: never ship these anywhere.
EXCLUDES=(
  --exclude='.git' --exclude='.github' --exclude='docs' --exclude='screenshots'
  --exclude='.wordpress-org' --exclude='build' --exclude='node_modules'
  --exclude='*.zip' --exclude='AGENTS.md' --exclude='CLAUDE.md'
  --exclude='.claude' --exclude='.claude-flow' --exclude='**/.claude-flow'
  --exclude='.superpowers' --exclude='.agents' --exclude='.codex'
  --exclude='.idea' --exclude='.vscode' --exclude='.mcp.json'
  --exclude='.DS_Store' --exclude='**/.DS_Store'
  --exclude='.github-release'
)

# Dev tooling: useful in the repo, noise in the directory. Anchored with a
# leading slash so "/vendor/" cannot also match admin/js/vendor/ — an
# unanchored pattern already cost us the bundled Chart.js once.
if [ "$TARGET" = wporg ]; then
  EXCLUDES+=(
    --exclude='/tests' --exclude='/e2e' --exclude='/bin'
    --exclude='/vendor' --exclude='/composer.json' --exclude='/composer.lock'
    --exclude='/phpunit.xml' --exclude='/phpunit.xml.dist'
    --exclude='/phpcs.xml' --exclude='/phpcs.xml.dist'
    --exclude='/phpstan.neon' --exclude='/phpstan.neon.dist'
    --exclude='/phpstan-baseline.neon'
    --exclude='/package.json' --exclude='/package-lock.json'
    --exclude='/pnpm-lock.yaml' --exclude='/.gitignore'
    --exclude='/.gitattributes' --exclude='/.editorconfig'
  )
fi

rsync -a "${EXCLUDES[@]}" ./ "$STAGE/"

if [ "$TARGET" = github ]; then
  printf 'Built from %s at %s\n' \
    "${GITHUB_SHA:-local}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    > "$STAGE/.github-release"
fi

(cd "$OUT" && zip -rq "$(basename "$ZIP")" "$SLUG")

# ---- invariants -----------------------------------------------------------
fail () { echo "  FAIL: $1" >&2; rm -f "$ZIP"; exit 1; }
# No pipe here, deliberately. `... | grep -q` makes grep close the pipe the
# moment it matches; the writer then takes SIGPIPE and `set -o pipefail`
# reports a SUCCESSFUL match as a failure. It only bites once the listing
# outgrows the 64K pipe buffer, so it passes on the small wporg zip and fails
# on the large github one — the worst possible way to find out. Substring
# matching in the shell has no pipe and no such trap.
LISTING="$(unzip -l "$ZIP")"
has ()  { [[ "$LISTING" == *"$1"* ]]; }

echo "== $TARGET zip: $ZIP ($VERSION, $(du -h "$ZIP" | cut -f1)) =="

has "$SLUG/stratawp-seo.php" || fail "main plugin file missing"
has "$SLUG/readme.txt"       || fail "readme.txt missing"
has "vendor/chart.umd.min.js"        || fail "bundled Chart.js missing"
has "vendor/fullcalendar.global.min.js" || fail "bundled FullCalendar missing"
has "admin/fonts/"           || fail "bundled fonts missing"

if [ "$TARGET" = github ]; then
  has '.github-release' || fail "provenance marker missing — self-updater would be dead"
  echo "  provenance marker: present (self-updater enabled)"
else
  ! has '.github-release' || fail "provenance marker present in a wporg build — the directory copy would self-update from GitHub"
  echo "  provenance marker: absent (directory handles updates)"
  ! has "$SLUG/tests/" || fail "tests/ leaked into the wporg build"
  ! has "$SLUG/composer.json" || fail "composer.json leaked into the wporg build"
fi

# No remote asset may creep back in. Guideline 7, and the reason these files
# are bundled in the first place.
if grep -rlE "cdn\.jsdelivr\.net|fonts\.googleapis\.com|unpkg\.com|cdnjs\.cloudflare\.com" \
     "$STAGE" --include='*.php' --include='*.css' 2>/dev/null \
     | grep -v '/admin/js/vendor/README.md' | grep -v '/admin/fonts/README.md' \
     | grep -v '/admin/css/fonts.css' | grep -v '/includes/class-admin-shell.php' | grep -q .; then
  fail "a remote asset URL is back in the built tree"
fi
echo "  remote assets: none"
echo "  DONE"
