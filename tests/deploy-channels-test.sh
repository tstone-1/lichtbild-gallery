#!/usr/bin/env bash
#
# Proves `tools/deploy.sh channels` can reach each of its verdicts.
#
#     bash tests/deploy-channels-test.sh
#
# The subcommand answers one question: would a wordpress.org update change the bytes on the
# server? A check that only ever prints [OK] is indistinguishable from one incapable of printing
# anything else, so every verdict here is produced by constructing the archive that causes it.
#
# Offline by design. `channels --against <zip>` substitutes a local archive for the download, the
# same seam `audit --against <dir>` uses, so this runs in CI with no credentials and no network.
#
# The truncated-archive case is the one worth reading. On 2026-08-27 a comparison against the
# directory's still-generating zip reported all 41 files as differences -- an empty operand, which
# diff renders as a total mismatch. This asserts that shape is reported as a BROKEN DOWNLOAD and
# never as a difference, because "every file changed" is the most alarming thing this tool can say
# and it must not be able to say it for that reason.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$PWD"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fails=0
ok()  { printf '  [OK]   %s\n' "$*"; }
bad() { printf '  [FAIL] %s\n' "$*"; fails=$((fails + 1)); }

# expect <label> <wanted-exit> <wanted-substring> <zip>
expect() {
	local label="$1" want_exit="$2" want_text="$3" zip="$4" out code
	out="$(bash tools/deploy.sh channels --against "$zip" 2>&1)"
	code=$?

	if [ "$code" -ne "$want_exit" ]; then
		bad "$label: exit $code, expected $want_exit"
		printf '%s\n' "$out" | sed 's/^/         /'
		return
	fi

	case "$out" in
		*"$want_text"*) ok "$label: exit $want_exit, said \"$want_text\"" ;;
		*) bad "$label: exit was right but the report never said \"$want_text\""
		   printf '%s\n' "$out" | sed 's/^/         /' ;;
	esac
}

version="$(sed -n "s/.*define( 'LICHTBILD_VERSION', '\([^']*\)' ).*/\1/p" lichtbild-gallery.php | head -1)"
base="$ROOT/build/lichtbild-gallery-$version.zip"

if [ ! -f "$base" ]; then
	bash tools/build-zip.sh >/dev/null || { echo "[FAIL] could not build the archive to test against"; exit 1; }
fi

[ -f "$base" ] || { echo "[FAIL] no archive at $base"; exit 1; }

# The control. Without it, every case below could be passing for the wrong reason -- a comparison
# that finds a difference in ANY archive proves nothing by finding one in a mutated archive.
expect "identical archives agree" 0 "[OK] the two channels carry identical bytes" "$base"

# 1. same version, different bytes -- the state nothing else reports.
work="$TMP/differs"; mkdir -p "$work"
( cd "$work" && unzip -q "$base" && printf '\n/* drift */\n' >> lichtbild-gallery/assets/css/lichtbild.css \
  && zip -qr "$TMP/differs.zip" lichtbild-gallery )
expect "a changed file is a difference" 1 "[DIFFERS]    assets/css/lichtbild.css" "$TMP/differs.zip"

# 2. a file the directory ships and the deploy does not -- how an update ADDS something.
work="$TMP/extra"; mkdir -p "$work"
( cd "$work" && unzip -q "$base" && echo "surprise" > lichtbild-gallery/not-from-here.php \
  && zip -qr "$TMP/extra.zip" lichtbild-gallery )
expect "a file only in the directory is a difference" 1 "[ONLY THERE] not-from-here.php" "$TMP/extra.zip"

# 3. a shipped file missing from the directory build, and NOT one of the by-design ones.
work="$TMP/gone"; mkdir -p "$work"
( cd "$work" && unzip -q "$base" && rm lichtbild-gallery/includes/class-lichtbild-item.php \
  && zip -qr "$TMP/gone.zip" lichtbild-gallery )
expect "a missing shipped file is a difference" 1 "[ONLY HERE]  includes/class-lichtbild-item.php" "$TMP/gone.zip"

# 4. the by-design absence stays by design, and does not become a difference.
#    Its own control: the run above proves an ordinary missing file DOES fail, so this passing
#    is the exemption working rather than the check being blind to absences.
expect "the unpublished catalogue is by design" 0 "[BY DESIGN]" "$base"
expect "and it is still named as a hazard" 0 "[HAZARD]" "$base"

# 5. a truncated download is a broken download, never a difference.
head -c 4000 "$base" > "$TMP/truncated.zip"
expect "a truncated archive is refused" 2 "is not a readable zip" "$TMP/truncated.zip"

out="$(bash tools/deploy.sh channels --against "$TMP/truncated.zip" 2>&1)"
case "$out" in
	*"[DIFFERS]"*|*"[ONLY HERE]"*|*"[ONLY THERE]"*)
		bad "a truncated archive was reported as differences, which is the 2026-08-27 mistake" ;;
	*)  ok "a truncated archive produced no difference lines at all" ;;
esac

# 6. an archive that is not this plugin.
( cd "$TMP" && mkdir -p wrong/somethingelse && echo x > wrong/somethingelse/a.php \
  && cd wrong && zip -qr "$TMP/wrong.zip" somethingelse )
expect "an archive without the plugin root is refused" 2 "has no lichtbild-gallery/ directory" "$TMP/wrong.zip"

echo
if [ "$fails" -eq 0 ]; then
	echo "deploy channels: every verdict reachable"
else
	echo "deploy channels: $fails failing"
fi

exit $(( fails > 0 ? 1 : 0 ))
