#!/usr/bin/env bash
#
# Builds the distributable plugin archive -- what goes to wordpress.org, and what would go into
# SVN trunk/.
#
#   bash tools/build-zip.sh            # writes build/lichtbild-gallery-<version>.zip
#   bash tools/build-zip.sh --keep     # ...and leaves the staged directory for inspection
#
# The archive is the tracked tree minus .distignore. That direction matters: an exclusion list
# fails safe (a forgotten entry ships something harmless) where an enumerated ship list fails
# dangerous (a new class is silently left out and the plugin is fatal on activation).
#
# Excluding by name is not evidence that the result is complete, so the build ASSERTS what it
# produced: every file lichtbild-gallery.php requires, every asset the enqueue code names, the version
# agreeing in all three places, and none of the development apparatus present. A zip is a valid
# zip whether or not it holds a working plugin.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$PWD"
SLUG=lichtbild-gallery
OUT="$ROOT/build"
STAGE="$OUT/$SLUG"
KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1

fails=0
ok()   { printf '  [OK]   %s\n' "$*"; }
bad()  { printf '  [FAIL] %s\n' "$*"; fails=$((fails + 1)); }

command -v zip   >/dev/null || { echo "zip is required" >&2; exit 1; }

rm -rf "$STAGE"
mkdir -p "$STAGE"

# Ship the COMMITTED tree, not the working directory: a release is a thing that exists in git,
# and an uncommitted edit that reaches users is unreproducible afterwards. Warn rather than
# refuse, because building a candidate from a dirty tree is a legitimate thing to want.
if [ -n "$(git status --porcelain)" ]; then
	printf '  note: the working tree is dirty; this archive is built from HEAD, not from disk\n\n'
fi

git archive HEAD | tar -x -C "$STAGE"

version="$(sed -n "s/.*define( 'LICHTBILD_VERSION', '\([^']*\)' ).*/\1/p" "$STAGE/lichtbild-gallery.php" | head -1)"
[ -n "$version" ] || { echo "could not read staged LICHTBILD_VERSION" >&2; exit 1; }
echo "building ${SLUG} ${version}"
echo

# Now remove what .distignore names. This is deliberately NOT `rsync --exclude-from` combined
# with `--files-from`: rsync ignores the exclusions entirely when an explicit file list is given,
# so the first version of this script produced an archive containing the whole test suite while
# reporting nothing wrong. The assertions below are what caught it.
while IFS= read -r pat; do
	case "$pat" in ''|'#'*) continue ;; esac
	# shellcheck disable=SC2086
	( cd "$STAGE" && rm -rf $pat )
done < "$STAGE/.distignore"

echo "verifying what was produced, rather than trusting what was excluded:"

# 1. Every PHP file the bootstrap requires must be in the archive.
missing_php=0
while IFS= read -r rel; do
	[ -z "$rel" ] && continue
	if [ ! -f "$STAGE/$rel" ]; then bad "required by lichtbild-gallery.php but absent: $rel"; missing_php=$((missing_php + 1)); fi
done < <(grep -oE "LICHTBILD_DIR \. '[^']+\.php'" "$STAGE/lichtbild-gallery.php" | sed "s/LICHTBILD_DIR \. '//; s/'$//")
required_count="$(grep -cE "LICHTBILD_DIR \. '[^']+\.php'" "$STAGE/lichtbild-gallery.php" || true)"
[ "$required_count" -gt 0 ] || bad "CONTROL: found no requires in lichtbild-gallery.php at all -- the check above examined nothing"
[ "$missing_php" -eq 0 ] && ok "$required_count required PHP files, all present"

# 2. Every asset named by the enqueue code must be in the archive.
missing_asset=0
assets_checked=0
while IFS= read -r rel; do
	[ -z "$rel" ] && continue
	assets_checked=$((assets_checked + 1))
	[ -f "$STAGE/$rel" ] || { bad "enqueued but absent: $rel"; missing_asset=$((missing_asset + 1)); }
done < <(grep -rhoE "assets/[A-Za-z0-9_./-]+\.(css|js)" "$STAGE/includes/" "$STAGE/lichtbild-gallery.php" | sort -u)
[ "$assets_checked" -gt 0 ] || bad "CONTROL: found no asset references -- the check above examined nothing"
[ "$missing_asset" -eq 0 ] && ok "$assets_checked referenced assets, all present"

# 3. The block metadata and its scripts.
for f in blocks/gallery/block.json blocks/album/block.json assets/js/blocks.js; do
	[ -f "$STAGE/$f" ] && ok "present: $f" || bad "absent: $f"
done

# 4. Development apparatus must NOT be there.
for d in tests tools docs .github .githooks .git .wordpress-org AGENTS.md CHANGELOG.md TODO.md; do
	[ -e "$STAGE/$d" ] && bad "development apparatus shipped: $d" || ok "excluded: $d"
done

# 5. The three version strings must agree, because WordPress reads the header, the code reads
#    the constant, and wordpress.org reads the stable tag.
hdr="$(sed -n 's/^ \* Version: *//p' "$STAGE/lichtbild-gallery.php" | head -1 | tr -d '[:space:]')"
tag="$(sed -n 's/^Stable tag: *//p' "$STAGE/readme.txt" | head -1 | tr -d '[:space:]')"
if [ "$hdr" = "$version" ] && [ "$tag" = "$version" ]; then
	ok "version agrees in all three places: $version"
else
	bad "version disagreement -- header=$hdr constant=$version stable tag=$tag"
fi

# 6. No PHP syntax errors anywhere in what ships.
syntax_bad=0
while IFS= read -r f; do
	php -l "$f" >/dev/null 2>&1 || { bad "PHP syntax error: ${f#$STAGE/}"; syntax_bad=$((syntax_bad + 1)); }
done < <(find "$STAGE" -name '*.php')
[ "$syntax_bad" -eq 0 ] && ok "every shipped PHP file parses"

echo
if [ "$fails" -ne 0 ]; then
	printf 'build FAILED: %s problem(s); no archive written\n' "$fails"
	exit 1
fi

zipfile="$OUT/${SLUG}-${version}.zip"
rm -f "$zipfile"
( cd "$OUT" && zip -qr "$(basename "$zipfile")" "$SLUG" -x '*.DS_Store' )

# The archive must unpack to a single directory named for the slug -- wordpress.org installs it
# under that name, so a differently-named root would install to the wrong plugin directory.
roots="$(unzip -Z1 "$zipfile" | cut -d/ -f1 | sort -u)"
[ "$roots" = "$SLUG" ] && ok "archive root is a single directory: $SLUG/" || bad "archive roots: $roots"

files="$(unzip -Z1 "$zipfile" | grep -vc '/$' || true)"
size="$(du -h "$zipfile" | cut -f1)"

[ "$KEEP" -eq 1 ] || rm -rf "$STAGE"

echo
printf 'wrote %s\n' "${zipfile#$ROOT/}"
printf 'files: %s, size: %s\n' "$files" "$size"
[ "$fails" -eq 0 ] || exit 1
