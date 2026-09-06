#!/usr/bin/env bash
#
# Uploads the plugin to the live site over FTPS and proves what landed.
#
# Usage:
#   bash tools/deploy.sh audit         # ask the SERVER what it has: every shipped file, by digest
#   bash tools/deploy.sh plan          # what would be uploaded, and in what order
#   bash tools/deploy.sh urls          # enumerate every URL the plugin owns, from the database
#   bash tools/deploy.sh capture <out> # fetch those URLs and record a normalised hash each
#   bash tools/deploy.sh fingerprint <out>  # the same URLs, recorded SEMANTICALLY
#   bash tools/deploy.sh push          # upload, chunked, verifying every file by digest
#   bash tools/deploy.sh compare <a> <b>
#
# The host has no shell, so everything here goes through curl over FTPS, and the transport is
# the reason this file exists rather than a one-liner. Three properties are not negotiable,
# each of them paid for on a previous deploy:
#
#   - CHUNK BY DEFAULT. A whole-file PUT of a 19 KB file failed six times out of six and left
#     it at 0 bytes; because that file is require_once'd, the live site returned HTTP 500 on
#     every page including the homepage. The same file went up first time in 8 KB pieces.
#     Trying whole-file first buys nothing when it works and costs an outage when it does not.
#   - VERIFY BY DIGEST, NEVER BY SIZE. A file whose new version is the same length as the old
#     passes a byte-count check while still holding the old content, and that is not a freak
#     case: a version string of equal width (26.8.0 -> 26.8.1) and a block moved within a file
#     both produce it, and both occurred in one deploy.
#   - ORDER MATTERS WHEN ONE FILE GAINS A METHOD ANOTHER CALLS. A caller landing first gives
#     visitors "Call to undefined method" until the definition arrives. UPLOAD_ORDER below is
#     derived from grep, not from memory, and `plan` prints the reasoning.
#
# The password is read from the login keychain into a .netrc that lives in a 0700 temp dir and
# is removed on exit. It is never rendered, never passed as an argument, and never logged.

set -uo pipefail

# The host and account are NOT literals here, and that is a publication requirement rather than a
# style preference: an FTP hostname plus its username is two thirds of a credential, and this
# repository is public. The password was never here — it comes from the login keychain below — but
# publishing the other two says exactly where to point a credential-stuffing tool.
#
# They come from the environment, or from `tools/deploy.env`, which is gitignored. Sourcing it is
# guarded on the file existing so a fresh clone gets the error message below rather than a
# `No such file` from the shell.
#
# Note this file is the ONLY thing that had to change: `netrc()` already looked the password up by
# ("$HOST", "$USER_NAME") rather than by a hardcoded service name, so parameterising the two
# carried the keychain lookup with it for free.
[ -f "$(dirname "${BASH_SOURCE[0]}")/deploy.env" ] && . "$(dirname "${BASH_SOURCE[0]}")/deploy.env"

HOST="${LICHTBILD_DEPLOY_HOST:-}"
USER_NAME="${LICHTBILD_DEPLOY_USER:-}"
REMOTE_DIR="${LICHTBILD_DEPLOY_DIR:-/wp-content/plugins/lichtbild-gallery}"

# The wordpress.org slug, which is also the plugin FOLDER name on the server -- and they are the
# same string for a reason that matters: WordPress matches an installed plugin to the directory by
# folder name, so this one value is what subscribes the live site to directory updates. It is not
# a deployment secret; the listing is public.
SLUG="lichtbild-gallery"

# Two subcommands never open a connection, and neither may require a deployment target -- that
# is not a convenience, it is what lets tests/deploy-order-test.sh and tests/deploy-audit-test.sh
# run in CI, on a runner with no credentials and no route to the host. A check nothing exercises
# is a check nobody knows the state of.
#
#   order-check <dir>    compares two trees already on disk
#   audit --against <dir>  the same seam: a directory stands in for the server, so every verdict
#                          the classifier can reach is provable without a deploy
NEEDS_TARGET=1

case "${1:-}" in
	order-check) NEEDS_TARGET=0 ;;
	audit) [ "${2:-}" = "--against" ] && NEEDS_TARGET=0 ;;
	channels) NEEDS_TARGET=0 ;;
esac

if [ "$NEEDS_TARGET" -eq 1 ] && { [ -z "$HOST" ] || [ -z "$USER_NAME" ]; }; then
	echo "[ERROR] set LICHTBILD_DEPLOY_HOST and LICHTBILD_DEPLOY_USER, or create tools/deploy.env:" >&2
	echo "          LICHTBILD_DEPLOY_HOST=ftp.example.com" >&2
	echo "          LICHTBILD_DEPLOY_USER=account" >&2
	echo "        The password is read from the macOS login keychain for that host and account," >&2
	echo "        never from a file: security add-internet-password -s <host> -a <account> -w" >&2
	exit 2
fi
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHUNK=8192

# Callers before definitions is a fatal error for a visitor, so this list is ordered, not
# alphabetical, and every constraint below is asserted by `plan` from a grep rather than taken
# on trust. It is the set that changed since the deployed release; re-uploading a file that did
# not change buys nothing and adds a transfer that can fail.
#
# 26.8.23 has ONE cross-file constraint and it points the USUAL way, which is worth saying out
# loud one release after the only one that inverted: this release ADDS a method, so the
# definition lands before its callers.
#
#   1. `Lichtbild_Settings::claims_envira_shortcodes()` is NEW, and BOTH
#      `class-lichtbild-shortcode.php` and `class-lichtbild-assets.php` stop calling
#      `should_take_over()` and call it instead. Land either caller before
#      `class-lichtbild-settings.php` and every front-end request hits "Call to undefined method"
#      until the definition arrives -- `register_shortcodes()` runs on `init` and
#      `maybe_enqueue_early()` on `wp_enqueue_scripts`, so that is every page on the site, not
#      only the ones with a gallery. Settings first, then shortcode, then assets.
#
# `class-lichtbild-config.php` has no cross-file edge at all this release: it drops the second
# argument it passes to the `lichtbild_config_sanitize` filter, and passing a hook fewer arguments
# than a callback declared is not an error in PHP. There is no third-party callback on this site
# in any case. `readme.txt` is inert.
#
# NOTHING is removed this release, so the backwards constraint that 26.8.22 needed does not
# apply -- and that is asserted rather than remembered: `check_removed_methods()` reports
# "methods dropped this release: none", which is the emptiness case its own test covers (case C).
#
# `plan` CAN see none of this either way. Its walk asks which requires are new and whether an
# asset precedes the bootstrap; a method added to an existing class appears in neither question,
# and the grep it runs looks for callers of a CLASS name rather than of a method -- and
# `Lichtbild_Settings` was already required, already constructed, already there. It will print
# "constraints: satisfied" over an order that is a fatal on every page. The order above is
# derived from the diff by hand and the reasoning is written down here for the next reader.
#
# `lichtbild-gallery.php` is last for the usual reason: LICHTBILD_VERSION is the `?ver=` on the assets. No
# asset changed this release, so the window is harmless either way; the position costs nothing
# and stating a rule once per release is cheaper than deciding whether it applies.
#
# 26.8.25 is the twelve files `audit` reported as differing from the server's inert copy, which
# is what that subcommand is for. The plugin directory is on the server and still not in
# `active_plugins`, so ordering remains moot for the same reason it was at 26.8.24 -- PHP opens
# none of it while it uploads -- and the bootstrap is last anyway, because the day this list is
# pasted into a live directory that habit is the only thing standing between a half-uploaded set
# and a fatal.
#
# 26.8.24 was the one release where this list was NOT the answer to "what changed": the plugin was
# renamed, so every file has a new name and the whole plugin goes into a NEW directory that the
# server does not have. The set is therefore the complete deployed set -- the archive's files
# minus SERVER_ABSENT, plus SERVER_EXTRA -- and an audit of the old directory could not have
# produced it, because not one of these paths exists there.
#
# Ordering cannot break anything on this deploy and the bootstrap is still last. The new
# directory is inert until someone activates the plugin in wp-admin, so a half-uploaded set is a
# plugin nobody is running, which is the opposite of 26.8.7's hazard. Verify by digest anyway:
# the transport drops large transfers, and an inactive plugin with a 0-byte class is a fatal the
# moment it IS activated.
#
# LICENSE, languages/lichtbild-gallery-de_DE.po and languages/lichtbild-gallery.pot are ABSENT from the server and
# deliberately stay that way: none is read at runtime, none has ever been deployed, and each is
# one more transfer that can fail for no behavioural gain. LICENSE ships in the wordpress.org
# ZIP, which is a different artifact.
# 26.8.27 has two constructor/method edges and both are asserted here rather than left to the
# alphabetical audit output. `Lichtbild_Gallery` calls the new `use_live_metadata()` method, so
# Item lands before Gallery. The Block constructor gains a third required argument, which makes
# its order the mirror of the usual definition-before-caller rule: the container lands first.
# PHP accepts its third argument while the deployed two-argument constructor is still present;
# putting Block first would make the deployed container call a three-argument constructor with
# only two and fatal every request. Config precedes the editor and gallery code that reads its
# new setting. The new Block PHP precedes its JavaScript, so an editor can see the old interface
# over the new endpoint but never the new interface over an endpoint that does not exist.
#
# The bootstrap remains last because it changes `LICHTBILD_VERSION`, which releases every new
# asset URL from cache only after all five assets have landed and been digest-verified.
# 26.9.0 is the ten changed runtime files reported by the server audit, plus the readme
# and bootstrap version bump. The metabox base introduces complete_section() and
# render_save_notice(), so it must precede both editor subclasses that call/register them.
# The block script sends images_complete before the endpoint starts requiring it: the old
# endpoint accepts the additional field, while the reverse order would refuse new galleries.
# The catalogue lands before the new save notice can render. The remaining PHP changes add
# no cross-file requirements or required arguments. Bootstrap stays last so asset cache keys
# move only after every changed asset and PHP file has been verified.
UPLOAD_ORDER=(
	"languages/lichtbild-gallery-de_DE.mo"
	"assets/js/blocks.js"
	"assets/js/lichtbild.js"
	"includes/class-lichtbild-item.php"
	"includes/class-lichtbild-metabox-editor.php"
	"includes/class-lichtbild-editor.php"
	"includes/class-lichtbild-album-editor.php"
	"includes/class-lichtbild-block.php"
	"includes/class-lichtbild-settings.php"
	"uninstall.php"
	"readme.txt"
	"lichtbild-gallery.php"
)

# The order the checks below actually run against. It is UPLOAD_ORDER for every real invocation;
# `order-check` replaces it so a test can hand in a deliberately wrong order and require the
# check to go red. Without that seam the only way to exercise this is to break a live deploy.
ORDER=( "${UPLOAD_ORDER[@]}" )

WORK="$(mktemp -d)"
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT

# ---------------------------------------------------------------------------------------------
# Credentials. Read once, into a file only this user can read, and never into a variable that
# could reach a log line or an argument list.
# ---------------------------------------------------------------------------------------------
netrc() {
	if [ ! -s "$WORK/netrc" ]; then
		umask 077
		{
			printf 'machine %s login %s password ' "$HOST" "$USER_NAME"
			security find-internet-password -s "$HOST" -a "$USER_NAME" -w
		} > "$WORK/netrc" || {
			echo "[ERROR] no keychain entry for $USER_NAME@$HOST" >&2
			exit 2
		}
	fi

	printf '%s' "$WORK/netrc"
}

ftp() {
	# --ftp-create-dirs so a new subdirectory in UPLOAD_ORDER works. `languages/` did not exist
	# on the server before 26.8.13, and without this the first chunk fails with a bare "550" that
	# reads like a permissions problem rather than a missing directory.
	curl --netrc-file "$(netrc)" --ssl-reqd --ftp-create-dirs --max-time 120 -sS "$@"
}

remote_size() {
	# -I on an FTP URL asks for SIZE. A missing file gives a non-zero exit, reported as -1 so
	# the caller can tell "absent" from "zero bytes" -- the two mean very different things here.
	local out
	out="$(ftp -I "ftp://$HOST$REMOTE_DIR/$1" 2>/dev/null | tr -d '\r' | awk '/Content-Length/{print $2}')"

	if [ -z "$out" ]; then
		printf '%s' "-1"
	else
		printf '%s' "$out"
	fi
}

remote_digest() {
	# The only statement that establishes what is on the server. Downloads it back rather than
	# trusting anything the upload reported.
	ftp -o "$WORK/verify.bin" "ftp://$HOST$REMOTE_DIR/$1" 2>/dev/null || {
		printf '%s' "unreadable"
		return
	}

	shasum "$WORK/verify.bin" | cut -d' ' -f1
}

# ---------------------------------------------------------------------------------------------
# Upload one file in CHUNK-sized pieces, re-reading the remote length after each so a cut stream
# is caught at the piece that failed rather than at the end.
# ---------------------------------------------------------------------------------------------
put_chunked() {
	local rel="$1" local_path="$ROOT/$1" want got expected piece n=0 attempt
	want="$(shasum "$local_path" | cut -d' ' -f1)"

	rm -rf "$WORK/pieces"
	mkdir -p "$WORK/pieces"
	split -b "$CHUNK" "$local_path" "$WORK/pieces/p"

	expected=0

	for piece in "$WORK/pieces"/p*; do
		n=$((n + 1))
		attempt=0

		while : ; do
			attempt=$((attempt + 1))

			if [ "$n" -eq 1 ]; then
				ftp -T "$piece" "ftp://$HOST$REMOTE_DIR/$rel" >/dev/null 2>&1
			else
				ftp --append -T "$piece" "ftp://$HOST$REMOTE_DIR/$rel" >/dev/null 2>&1
			fi

			expected=$((expected + $(wc -c < "$piece")))
			got="$(remote_size "$rel")"

			if [ "$got" = "$expected" ]; then
				break
			fi

			# A partial append corrupts every later offset, so the file is cut back to the last
			# known-good length before retrying rather than appended to again.
			echo "    piece $n attempt $attempt: remote $got, expected $expected -- retrying" >&2
			expected=$((expected - $(wc -c < "$piece")))

			if [ "$attempt" -ge 6 ]; then
				echo "[ERROR] $rel: piece $n did not land after $attempt attempts" >&2
				return 1
			fi

			# Truncate back by re-uploading everything already verified.
			if [ "$expected" -eq 0 ]; then
				: > "$WORK/empty"
				ftp -T "$WORK/empty" "ftp://$HOST$REMOTE_DIR/$rel" >/dev/null 2>&1
			else
				head -c "$expected" "$local_path" > "$WORK/head.bin"
				ftp -T "$WORK/head.bin" "ftp://$HOST$REMOTE_DIR/$rel" >/dev/null 2>&1
			fi
		done
	done

	got="$(remote_digest "$rel")"

	if [ "$got" != "$want" ]; then
		echo "[ERROR] $rel: digest mismatch after upload (remote $got, local $want)" >&2
		return 1
	fi

	printf '  [OK]   %-42s %6s bytes, %d chunks, digest verified\n' "$rel" "$expected" "$n"
}

# ---------------------------------------------------------------------------------------------
# The constraint that points BACKWARDS, and the reason this function exists at all.
#
# Every ordering rule above is about a definition ARRIVING: land it before its caller, or the
# caller is a fatal in the window between the two files. 26.8.22 was the first release to DELETE
# one -- `Lichtbild_Gallery::custom_css()`, with `Lichtbild_Renderer` as the file that stops calling
# it -- and the familiar rule gives exactly the wrong answer there. The caller has to go FIRST,
# because it is the DEPLOYED caller, still on the server, that would be left naming a method the
# new file no longer defines.
#
# `cmd_plan` printed "constraints: satisfied" over that order and was right to by its own lights:
# it asks which `require`s are new and whether an asset precedes the bootstrap, and a deleted
# method is in neither question. Its class-level grep cannot see it either -- the class is still
# required, still constructed, still there; it is one method that went away.
#
# The comparison is against the DEPLOYED tree, not against the previous release commit, for the
# reason the whole script exists: the version of record is what the server serves. A commit that
# edited a shipped file after the last deploy and never shipped is invisible to `git diff` against
# a tag, and 26.8.22 met exactly that (9e3ab3c, five files).
#
# Three outcomes rather than two, because "I could not tell" must never be delivered as a pass:
#
#   ERROR      a call SURVIVES the release -- some local file still calls a method nothing
#              defines any more. That is a broken release rather than an ordering problem, and
#              it also covers every caller OUTSIDE this upload for free: a file that is not in
#              the upload is byte-identical to the deployed one, so if its deployed copy calls
#              the method, so does its local copy.
#   ERROR      the deployed caller IS in this upload and is ordered at or after the file that
#              drops the definition.
#   AMBIGUOUS  another class still defines a method of that name, so `->name(` cannot say which
#              object it belongs to. Reported for a human rather than guessed either way -- a
#              grep that resolves this would be claiming a type checker it does not have.
# ---------------------------------------------------------------------------------------------

# Method and function DEFINITIONS in one PHP file, one name per line, sorted.
#
# Anchored at the start of the line so a CALL never matches: `public function custom_css(` is a
# definition, `$gallery->custom_css(` is not, and the difference is entirely the anchor plus the
# modifier prefix. A bare `function lichtbild_load_textdomain(` at column 0 is a definition too.
php_defs() {
	grep -oE '^[[:space:]]*((public|protected|private|static|abstract|final)[[:space:]]+)*function[[:space:]]+&?[A-Za-z_][A-Za-z0-9_]*' "$1" 2>/dev/null |
		sed -E 's/.*function[[:space:]]+&?//' |
		sort -u
}

# Does file $1 CALL method $2? Both spellings, and a space before the paren is legal PHP.
calls_method() {
	grep -qE "(->|::)[[:space:]]*$2[[:space:]]*\(" "$1" 2>/dev/null
}

order_position() {
	local n=0 rel

	for rel in "${ORDER[@]}"; do
		n=$((n + 1))

		if [ "$rel" = "$1" ]; then
			printf '%s' "$n"

			return
		fi
	done

	printf '%s' "0"
}

# Every shipped PHP file in the local tree, which is where a surviving call would be.
local_php() {
	printf '%s\n' "$ROOT/lichtbild-gallery.php" "$ROOT/uninstall.php" "$ROOT"/includes/*.php
}

check_removed_methods() {
	local ref="$1"
	local ok=1 removed=0 pairs=0 ambiguous=0
	local rel m f defining_pos caller caller_pos survivors elsewhere

	for rel in "${ORDER[@]}"; do
		case "$rel" in
			*.php) ;;
			*) continue ;;
		esac

		# Absent from the deployed tree means the file is new, and a new file cannot have
		# removed anything. Absent locally would be a deletion, which this script does not do.
		[ -f "$ref/$rel" ] || continue
		[ -f "$ROOT/$rel" ] || continue

		while IFS= read -r m; do
			[ -z "$m" ] && continue

			removed=$((removed + 1))
			defining_pos="$(order_position "$rel")"

			survivors=""
			elsewhere=0

			for f in $(local_php); do
				[ -f "$f" ] || continue

				if calls_method "$f" "$m"; then
					survivors="$survivors ${f#"$ROOT"/}"
				fi

				if [ "$f" != "$ROOT/$rel" ] && php_defs "$f" | grep -qx "$m"; then
					elsewhere=1
				fi
			done

			if [ "$elsewhere" -eq 1 ]; then
				echo "  AMBIGUOUS $rel drops $m(), but another class still defines that name;"
				echo "            a grep cannot say which object a call names -- check by hand"
				ambiguous=$((ambiguous + 1))

				continue
			fi

			if [ -n "$survivors" ]; then
				echo "[ERROR] $rel drops $m(), but$survivors still calls it -- no order fixes that" >&2
				ok=0

				continue
			fi

			for caller in "${ORDER[@]}"; do
				[ "$caller" = "$rel" ] && continue
				[ -f "$ref/$caller" ] || continue
				calls_method "$ref/$caller" "$m" || continue

				pairs=$((pairs + 1))
				caller_pos="$(order_position "$caller")"

				if [ "$caller_pos" -ge "$defining_pos" ]; then
					echo "[ERROR] $rel drops $m() and the DEPLOYED $caller still calls it," >&2
					echo "        but $caller is ordered after it -- every request that reaches" >&2
					echo "        that call between the two uploads is a fatal" >&2
					ok=0
				else
					echo "  REMOVED   $m(): $caller (deployed caller) precedes $rel (definition)"
				fi
			done
		done < <(comm -23 <(php_defs "$ref/$rel") <(php_defs "$ROOT/$rel"))
	done

	if [ "$removed" -eq 0 ]; then
		echo "  methods dropped this release:       none, so nothing to sequence backwards"
	else
		echo "  methods dropped this release:       $removed, ordering pairs checked: $pairs, ambiguous: $ambiguous"
	fi

	[ "$ok" -eq 1 ]
}

# Populate a local mirror of the DEPLOYED tree for the check above.
#
# A file that cannot be read is refused rather than skipped. Skipping is the failure this script
# has already made twice in other places: an unreadable file and a file with nothing removed
# produce the same silence, and the silent one reads as a pass.
fetch_deployed() {
	local dest="$1" rel size

	mkdir -p "$dest/includes"

	for rel in "${ORDER[@]}"; do
		case "$rel" in
			*.php) ;;
			*) continue ;;
		esac

		if ftp -o "$dest/$rel" "ftp://$HOST$REMOTE_DIR/$rel" 2>/dev/null && [ -s "$dest/$rel" ]; then
			continue
		fi

		rm -f "$dest/$rel"
		size="$(remote_size "$rel")"

		if [ "$size" != "-1" ]; then
			echo "[ERROR] the deployed $rel is $size bytes but could not be read; the" >&2
			echo "        removed-method check would silently cover nothing" >&2

			return 1
		fi
	done
}

# ---------------------------------------------------------------------------------------------
# `audit` -- ask the SERVER what it has.
#
# UPLOAD_ORDER above is a claim about what changed since the deployed release, and it has been
# the PREVIOUS release's list five times in a row. Every deploy record since 26.8.21 ends by
# saying the fix is to ask the server rather than to diff a tag, and every one of those audits ran
# as a throwaway script outside the repository -- so the only durable artifact was a comment that
# was stale again by the next release. This is that audit, kept.
#
# Why the server and not `git diff <last-release-tag>`: a diff can only see commits. On 26.8.22
# the audit found five shipped files that a commit had edited AFTER the previous deploy and that
# had never shipped; no diff against a tag can see those, because from the tag's point of view
# they are simply part of the release. The server is the only thing that knows what the server has.
#
# Read-only. It opens no write connection and changes nothing.
# ---------------------------------------------------------------------------------------------

# Files that ship in the wordpress.org archive but are DELIBERATELY absent from the server: none
# is read at runtime, none has ever been deployed, and each is one more transfer that can fail for
# no behavioural gain. They stay in the audit's universe rather than being filtered out of it, so
# that "absent" is a verdict the audit reaches and reports rather than a question it never asks --
# a file quietly excluded from the set is a file nothing will ever notice missing.
SERVER_ABSENT=(
	"LICENSE"
	"languages/lichtbild-gallery.pot"
)

# The other direction, and it is not symmetric: files that go to THIS SITE but not into the
# wordpress.org archive. `.distignore` excludes the compiled German catalogue because a
# directory-hosted plugin gets its translations from translate.wordpress.org, and shipping one
# would override the community's -- but this site is not directory-hosted, the catalogue is what
# renders 28 strings in German there, and it has been deployed since 26.8.13.
#
# So the deployed set is NOT the archive's set, and deriving the audit's universe from
# `.distignore` alone would leave the one file whose absence is silent -- the strings quietly
# revert to English -- as the one file the audit never asks about.
SERVER_EXTRA=(
	"languages/lichtbild-gallery-de_DE.mo"
)

# The distributed file set, derived the way tools/build-zip.sh derives it: the TRACKED tree minus
# .distignore, root-anchored exactly as that script's `rm -rf $pat` is. Sharing the derivation is
# the point -- an audit with its own hand-written list of shipped files would drift from the thing
# it audits, which is the defect this whole subcommand exists to end.
#
# It FAILS CLOSED when `git ls-files` answers nothing. An empty list and a healthy tree with
# nothing shippable produce the same silence, and a deploy tool that reports "0 differences" over
# an empty universe is the exact shape of the bug that once reported 40 differing files and 201
# phantom leftovers on a healthy site.
shipped_files() {
	local tracked path pat drop patterns=()

	tracked="$(cd "$ROOT" && git ls-files 2>/dev/null)"

	if [ -z "$tracked" ]; then
		echo "[ERROR] git ls-files returned nothing, so the shipped set is unknown; refusing" >&2
		echo "        rather than auditing an empty universe and calling it clean" >&2

		return 1
	fi

	while IFS= read -r pat; do
		case "$pat" in ''|'#'*) continue ;; esac
		patterns+=( "$pat" )
	done < "$ROOT/.distignore"

	# Same reasoning one level down: no patterns means every development file is "shipped", and
	# an audit that reports the test suite as missing from the server is worse than none.
	if [ "${#patterns[@]}" -eq 0 ]; then
		echo "[ERROR] .distignore is missing or empty, so the shipped set cannot be derived" >&2

		return 1
	fi

	while IFS= read -r path; do
		[ -z "$path" ] && continue
		drop=0

		for pat in "${patterns[@]}"; do
			# Unquoted on purpose: these are globs, and `docs` must match `docs/lessons.md`
			# the same way `rm -rf docs` does.
			# shellcheck disable=SC2254
			case "$path" in
				$pat|$pat/*) drop=1; break ;;
			esac
		done

		[ "$drop" -eq 0 ] && printf '%s\n' "$path"
	done <<< "$tracked"

	# Re-added after the exclusions, and only if the file is really in the tree: a name in
	# SERVER_EXTRA that no longer exists would otherwise be audited forever as ABSENT, which is a
	# false finding that never goes away and therefore stops being read.
	for path in "${SERVER_EXTRA[@]}"; do
		if [ -f "$ROOT/$path" ]; then
			printf '%s\n' "$path"
		else
			echo "[ERROR] SERVER_EXTRA names $path, which is not in the tree" >&2

			return 1
		fi
	done
}

# Is $1 deployed on purpose but deliberately absent from the wordpress.org build?
in_server_extra() {
	local rel

	for rel in "${SERVER_EXTRA[@]}"; do
		[ "$rel" = "$1" ] && return 0
	done

	return 1
}

# Is $1 one of the files deliberately never deployed?
expected_absent() {
	local rel

	for rel in "${SERVER_ABSENT[@]}"; do
		[ "$rel" = "$1" ] && return 0
	done

	return 1
}

# Is $1 in UPLOAD_ORDER?
in_upload_order() {
	local rel

	for rel in "${UPLOAD_ORDER[@]}"; do
		[ "$rel" = "$1" ] && return 0
	done

	return 1
}

cmd_audit() {
	local against="" rel local_sum remote_sum src shipped
	local same=0 changed=0 gone=0 expected=0 unreadable=0 surprising=0
	local missing_from_order="" stale_in_order=""

	# Newline-delimited AND newline-terminated, so the membership test below can anchor on both
	# ends. Matching a bare substring would let `readme.txt` be answered by `docs/readme.txt`.
	local needed_list=$'\n'

	shipped="$(shipped_files)" || return 1

	if [ "${1:-}" = "--against" ]; then
		against="${2:?usage: audit --against <deployed-tree-dir> [file ...]}"

		[ -d "$against" ] || {
			echo "[ERROR] no such deployed tree: $against" >&2

			return 2
		}

		shift 2

		# Any files after the directory replace UPLOAD_ORDER for the cross-check below, the same
		# seam `order-check` has and for the same reason: the list changes every release, so a
		# test asserting against the real one would be asserting about this month's release
		# rather than about the check. Only this subcommand runs, so nothing else sees it.
		[ "$#" -gt 0 ] && UPLOAD_ORDER=( "$@" )

		echo "auditing against a tree on disk: $against"
	else
		echo "auditing the deployed tree, file by file, by digest"
	fi

	echo

	while IFS= read -r rel; do
		[ -z "$rel" ] && continue
		local_sum="$(shasum "$ROOT/$rel" | cut -d' ' -f1)"
		src="$WORK/audit.bin"
		rm -f "$src"

		if [ -n "$against" ]; then
			# The disk seam. `-r` before `-f` so a file that exists and cannot be read is
			# UNREADABLE rather than absent: those two mean opposite things, and the silent
			# one reads as a pass.
			if [ -e "$against/$rel" ] && [ ! -r "$against/$rel" ]; then
				printf '  UNREADABLE %s\n' "$rel"
				unreadable=$((unreadable + 1))

				continue
			fi

			[ -f "$against/$rel" ] && cp "$against/$rel" "$src" 2>/dev/null
		else
			ftp -o "$src" "ftp://$HOST$REMOTE_DIR/$rel" 2>/dev/null || rm -f "$src"

			# A failed fetch has two causes that mean opposite things, and only SIZE separates
			# them: -1 is genuinely absent, anything else is a file that is there and could not
			# be read.
			if [ ! -f "$src" ] && [ "$(remote_size "$rel")" != "-1" ]; then
				printf '  UNREADABLE %s\n' "$rel"
				unreadable=$((unreadable + 1))

				continue
			fi
		fi

		if [ ! -f "$src" ]; then
			if expected_absent "$rel"; then
				printf '  absent     %-46s (deliberately never deployed)\n' "$rel"
				expected=$((expected + 1))
			else
				printf '  ABSENT     %-46s never deployed, and nothing says it should not be\n' "$rel"
				gone=$((gone + 1))

				# An absent file needs uploading exactly as much as a changed one -- more, since
				# the plugin may be fatal without it -- so it belongs in the set UPLOAD_ORDER is
				# checked against. Calling only the changed ones "needed" would report a missing
				# class file as an UPLOAD_ORDER entry that is merely superfluous.
				needed_list="$needed_list$rel"$'\n'

				in_upload_order "$rel" || missing_from_order="$missing_from_order $rel"
			fi

			continue
		fi

		if expected_absent "$rel"; then
			printf '  NOTE       %-46s is on the server though it is never deployed\n' "$rel"
			surprising=$((surprising + 1))
		fi

		remote_sum="$(shasum "$src" | cut -d' ' -f1)"

		if [ "$local_sum" = "$remote_sum" ]; then
			same=$((same + 1))

			continue
		fi

		printf '  CHANGED    %-46s local %s bytes, deployed %s\n' "$rel" \
			"$(wc -c < "$ROOT/$rel" | tr -d ' ')" "$(wc -c < "$src" | tr -d ' ')"
		changed=$((changed + 1))
		needed_list="$needed_list$rel"$'\n'

		in_upload_order "$rel" || missing_from_order="$missing_from_order $rel"
	done <<< "$shipped"

	# The audit is only worth running if it can also say the shipped set was non-empty. A run
	# that walked nothing prints the same reassuring zeros as a clean one.
	if [ "$((same + changed + gone + expected + unreadable))" -eq 0 ]; then
		echo "[ERROR] the audit examined no files at all; the shipped set is empty" >&2

		return 1
	fi

	for rel in "${UPLOAD_ORDER[@]}"; do
		case "$needed_list" in
			*$'\n'"$rel"$'\n'*) ;;
			*) stale_in_order="$stale_in_order $rel" ;;
		esac
	done

	printf '\nsame: %d  changed: %d  absent: %d (%d of them expected)  unreadable: %d\n' \
		"$same" "$changed" "$((gone + expected))" "$expected" "$unreadable"

	# BOTH directions, because one of them alone is the dangerous half missing: an UPLOAD_ORDER
	# entry the server already matches costs one needless transfer, while a file that differs and
	# is not listed is a file this release silently does not deploy. Set-versus-set, which is also
	# the shape a previous both-directions check here got wrong by comparing counts.
	echo
	if [ -z "$missing_from_order" ] && [ -z "$stale_in_order" ]; then
		echo "UPLOAD_ORDER: in sync with the server -- it names exactly the files that differ"
	elif [ "$needed_list" = $'\n' ]; then
		# The state immediately after a successful deploy, and it is worth naming rather than
		# reporting as a discrepancy: the server matches this checkout everywhere, so UPLOAD_ORDER
		# is simply the list the last push used. Nothing to do.
		echo "UPLOAD_ORDER: nothing differs, so it is last release's list -- which is what a"
		echo "              just-deployed site looks like:$stale_in_order"
	else
		[ -n "$missing_from_order" ] &&
			echo "UPLOAD_ORDER: DIFFERS from the server but is not listed, so it would NOT be uploaded:$missing_from_order"
		[ -n "$stale_in_order" ] &&
			echo "UPLOAD_ORDER: listed though the server already matches it:$stale_in_order"
		echo
		echo "the files that need uploading, unordered -- SEQUENCE THEM BY HAND before pasting:"
		printf '%s' "$needed_list" | sed '/^$/d; s/^/	"/; s/$/"/'
	fi

	[ "$unreadable" -eq 0 ] || {
		echo >&2
		echo "[ERROR] $unreadable file(s) could not be read; the audit covers less than it appears to" >&2

		return 1
	}

	return 0
}

cmd_plan() {
	ORDER=( "${UPLOAD_ORDER[@]}" )

	echo "upload order, and why it is this order:"
	echo

	local i=0

	for rel in "${UPLOAD_ORDER[@]}"; do
		i=$((i + 1))
		printf '  %d. %-44s %8s bytes\n' "$i" "$rel" "$(wc -c < "$ROOT/$rel" | tr -d ' ')"
	done

	echo
	echo "ordering constraints, derived rather than remembered:"

	# Labelled "required by lichtbild-gallery.php", not "new" -- this is every require in the LOCAL
	# bootstrap, and the new ones are worked out below by asking the server. The label used to
	# say "new classes required by lichtbild-gallery.php" over a list of all nineteen, which reads exactly
	# like the server fetch having failed and every require having defaulted to new. It cost a
	# detour through the fetch code on the 26.8.10 deploy to establish that nothing was wrong.
	# A report that describes itself inaccurately is a defect in the report.
	echo "  required by the local lichtbild-gallery.php:   $(cd "$ROOT" && grep -c "class-lichtbild-[a-z-]*\.php" lichtbild-gallery.php | tr -d ' ') classes"
	echo "  in this upload, absent on server:   $(for rel in "${UPLOAD_ORDER[@]}"; do [ "$(remote_size "$rel")" = "-1" ] && printf '%s ' "$rel"; done)"

	local ok=1
	local new_requires=0

	position() {
		local n=0

		for rel in "${UPLOAD_ORDER[@]}"; do
			n=$((n + 1))

			if [ "$rel" = "$1" ]; then
				printf '%s' "$n"

				return
			fi
		done

		printf '%s' "0"
	}

	# The one rule, stated generally rather than as this release's instance of it: a file that
	# NAMES a class must not land before the file that REQUIRES that class does -- otherwise the
	# consumer is a fatal "class not found" on every page it touches.
	#
	# Naming a class means calling it, constructing it, OR EXTENDING it, and the third was missing
	# until 26.8.7 needed it. A grep for `Foo::` and `new Foo(` cannot see `extends Foo`, so a
	# subclass ordered before its parent's require passed this check and would have taken every
	# admin page down -- the check was not lenient here, it was blind. `abstract` classes make the
	# gap worse, because the two spellings it did look for are exactly the ones you cannot use on
	# one: an abstract class is never `new`ed, so the only edge that exists is the invisible one.
	#
	# It only binds when the require is NEW, and whether it is new is a fact about the server,
	# not about this checkout. So the server's own lichtbild-gallery.php is fetched and read. Two releases
	# running, the interesting answer came from asking the server rather than from remembering:
	# 26.8.4's new class files legitimately preceded the gate because they did not yet exist
	# there, and 26.8.5 has no constraint at all because the requires are already in place.
	local gate server_bootstrap bootstrap_size
	gate="$(position lichtbild-gallery.php)"
	server_bootstrap="$(ftp "ftp://$HOST$REMOTE_DIR/lichtbild-gallery.php" 2>/dev/null)"

	# An empty answer has TWO causes that mean opposite things, and this script's whole history is
	# of empty answers being read as facts. So it is resolved by SIZE, which distinguishes them:
	# `remote_size` reports -1 for a file that is not there and a byte count for one that is.
	#
	#   -1  the bootstrap is genuinely absent -> first install of a new plugin directory. Nothing
	#       on the server executes any of it, so there is no order to get wrong. Say so and stop
	#       checking, rather than inventing a constraint or refusing to run.
	#   >=0 the file exists and could not be read -> a transport failure. Refuse, exactly as
	#       before: "cannot tell which requires are new" is the one answer that must never be
	#       delivered quietly, because every ordering guarantee below rests on having read it.
	if [ -z "$server_bootstrap" ]; then
		bootstrap_size="$(remote_size lichtbild-gallery.php)"

		if [ "$bootstrap_size" != "-1" ]; then
			echo "[ERROR] the deployed lichtbild-gallery.php is $bootstrap_size bytes but could not be read;" >&2
			echo "        cannot tell which requires are new, so the ordering check would cover nothing" >&2

			return 1
		fi

		echo "  FIRST INSTALL: no lichtbild-gallery.php on the server, so this is a new plugin directory."
		echo "  Ordering is moot: the directory is not in active_plugins, so PHP opens none of it"
		echo "  while it uploads. Activate only after every file is digest-verified."
		echo
		echo "constraints: none applicable (first install, plugin inactive during upload)"

		return 0
	fi

	while IFS= read -r class_file; do
		[ -z "$class_file" ] && continue

		# Already required on the server: landing a consumer first is safe.
		case "$server_bootstrap" in
			*"$class_file"*) continue ;;
		esac

		new_requires=$((new_requires + 1))

		echo "  NEW require this release: $class_file"

		# `^class` alone does not match `abstract class Foo` or `final class Foo`, and the empty
		# answer did not read as "cannot tell" -- it fell through the `continue` below and skipped
		# the constraint check entirely, reporting "constraints: satisfied" over an order that was
		# a fatal on every admin page. Caught by mutating the order and finding the check still
		# green, which is the only reason it was caught at all.
		local class_name
		class_name="$(cd "$ROOT" && grep -oE '^(abstract |final )?class [A-Za-z_]+' "includes/$class_file" | head -1 | awk '{print $NF}')"

		# A new require whose class name cannot be read is a check that covered NOTHING. That is
		# the one answer this must never give quietly, so it stops the deploy rather than skipping.
		if [ -z "$class_name" ]; then
			echo "[ERROR] cannot read a class name from includes/$class_file; the ordering check would cover nothing" >&2
			ok=0

			continue
		fi

		while IFS= read -r consumer; do
			[ -z "$consumer" ] && continue
			[ "$(position "$consumer")" -eq 0 ] && continue
			[ "$(remote_size "$consumer")" = "-1" ] && continue

			if [ "$gate" -ge "$(position "$consumer")" ]; then
				echo "[ERROR] $consumer names $class_name but is ordered before the file that requires it" >&2
				ok=0
			fi
		done < <(cd "$ROOT" && grep -l "${class_name}::\|new ${class_name}(\|extends ${class_name}" includes/*.php)

		if [ "$(position "includes/$class_file")" -eq 0 ] || [ "$(position "includes/$class_file")" -ge "$gate" ]; then
			echo "[ERROR] includes/$class_file is missing from the order, or ordered after lichtbild-gallery.php requires it" >&2
			ok=0
		fi
	done < <(cd "$ROOT" && grep -o "class-lichtbild-[a-z-]*\.php" lichtbild-gallery.php)

	[ "$new_requires" -eq 0 ] && echo "  new requires this release:          none, so nothing to sequence"

	# The second constraint, which is not about PHP and which the walk above is structurally
	# blind to: a stylesheet or script is cache-busted by `LICHTBILD_VERSION`, and that constant
	# lives in lichtbild-gallery.php. Land the bootstrap first and a browser asking for `?ver=<new>` is
	# served the OLD asset and caches it under the new name -- where nothing corrects it until
	# the next version bump, because the URL never changes again. No grep can see this edge:
	# nothing in the code names the file, the dependency runs through a query argument.
	#
	# It bound on 26.8.15 (the stylesheet) and again on 26.8.19 (the script), and both times it
	# was carried by a note in the deploy record. Twice is the rule rather than the exception, and
	# a rule written as a caution gets nodded at while the same rule written as a line of code
	# gets followed -- which is the argument this whole script was built on.
	# Counted separately from the ones that pass, because a single tally cannot say which of two
	# opposite things happened: with `lichtbild-gallery.php` ordered first, every asset FAILS the rule, and
	# a pass-counter left at zero then prints "none in this upload" directly beneath the error
	# saying otherwise. Same defect this script already fixed once in the requires label -- a
	# report that describes itself inaccurately is a defect in the report.
	local assets_seen=0
	local assets_first=0

	if [ "$gate" -gt 0 ]; then
		for rel in "${UPLOAD_ORDER[@]}"; do
			case "$rel" in
				assets/*)
					assets_seen=$((assets_seen + 1))

					if [ "$(position "$rel")" -ge "$gate" ]; then
						echo "[ERROR] $rel is ordered at or after lichtbild-gallery.php, which carries the ?ver= it is cached under" >&2
						ok=0
					else
						assets_first=$((assets_first + 1))
					fi
					;;
			esac
		done
	fi

	if [ "$assets_seen" -eq 0 ]; then
		echo "  versioned assets in this upload:    none, so no cache-buster to sequence"
	else
		echo "  versioned assets before the bootstrap: $assets_first of $assets_seen"
	fi

	# The third constraint, and the only one that runs backwards. See check_removed_methods().
	if fetch_deployed "$WORK/deployed"; then
		check_removed_methods "$WORK/deployed" || ok=0
	else
		ok=0
	fi

	echo

	[ "$ok" -eq 1 ] && echo "constraints: satisfied"
	[ "$ok" -eq 1 ] || return 1
}

# ---------------------------------------------------------------------------------------------
# channels [--against <zip>] -- do the two release channels agree?
#
# Until 2026-08-27 this site had exactly one way to receive the plugin: the FTPS deploy below,
# which verifies every file by digest because the transport drops transfers and once left a
# 0-byte class file on a public site. Publishing to wordpress.org added a second: WordPress
# matches an installed plugin to the directory by FOLDER SLUG, and this install's folder is
# `lichtbild-gallery`, so the site now checks api.wordpress.org and can be updated from SVN --
# with no digest verification, no `capture`, and no `compare`.
#
# Three states, and only the third is silent:
#
#   site ahead              no update is offered, but the site runs code the directory cannot
#                           reproduce, and the next published version must clear that number
#   directory ahead         an update is offered, possibly applied automatically, and the site
#                           changes without a single check this script performs
#   same version, different bytes
#                           invisible. A file deployed without a version bump is flattened by
#                           the next update, and nothing anywhere reports it
#
# The third is the same blindness `audit` already exists for one layer up: comparing versions is
# comparing a label, exactly as comparing byte counts was, and this repository has already paid
# for believing a label once.
#
# Needs no deployment target -- it compares the LOCAL shipped tree against the published zip, so
# it runs in CI and on a fresh clone. `--against <zip>` substitutes a local archive for the
# download, which is how tests/deploy-channels-test.sh proves each verdict offline.
cmd_channels() {
	local against="" version zip tmp published rel want got
	local only_here=0 only_there=0 differing=0 by_design=0

	[ "${1:-}" = "--against" ] && { against="${2:?usage: channels --against <zip>}"; }

	version="$(sed -n "s/.*define( 'LICHTBILD_VERSION', '\([^']*\)' ).*/\1/p" "$ROOT/lichtbild-gallery.php" | head -1)"

	if [ -z "$version" ]; then
		echo "[ERROR] could not read LICHTBILD_VERSION, so there is no version to compare" >&2
		return 2
	fi

	echo "local version: $version"

	tmp="$(mktemp -d)"
	# shellcheck disable=SC2064
	trap "rm -rf '$tmp'" RETURN

	if [ -n "$against" ]; then
		[ -f "$against" ] || { echo "[ERROR] no such archive: $against" >&2; return 2; }
		zip="$against"
		echo "comparing against: $against"
	else
		zip="$tmp/published.zip"
		local url="https://downloads.wordpress.org/plugin/${SLUG}.${version}.zip"

		if ! curl -fsSL -o "$zip" "$url"; then
			echo
			echo "[WARN] the directory does not serve ${SLUG} ${version}."
			echo "       The site would be AHEAD of wordpress.org: no update is offered, so"
			echo "       nothing breaks today, but this version exists only on the server and"
			echo "       the next published release must be at least $version."
			return 1
		fi

		echo "comparing against: $url"
	fi

	# A truncated download is not a mismatch, and on 2026-08-27 one was read as forty-one of
	# them: the directory was still generating the archive, the extraction produced nothing, and
	# an empty operand renders as a total difference. Establish the bytes are an archive before
	# reading any comparison against them.
	if ! unzip -tq "$zip" >/dev/null 2>&1; then
		echo "[ERROR] the published archive is not a readable zip ($(wc -c < "$zip" | tr -d ' ') bytes)." >&2
		echo "        That is an incomplete download, NOT a difference; retry before concluding" >&2
		echo "        anything about the two channels." >&2
		return 2
	fi

	published="$tmp/published"
	mkdir -p "$published"
	unzip -q "$zip" -d "$published"

	[ -d "$published/$SLUG" ] || {
		echo "[ERROR] the published archive has no $SLUG/ directory at its root" >&2
		return 2
	}

	echo

	local -a shipped
	while IFS= read -r rel; do
		[ -n "$rel" ] && shipped+=( "$rel" )
	done < <(shipped_files) || return 1

	[ "${#shipped[@]}" -gt 0 ] || {
		echo "[ERROR] the shipped set is empty, so this compared nothing" >&2
		return 2
	}

	for rel in "${shipped[@]}"; do
		if expected_absent "$rel"; then
			continue
		fi

		if [ ! -f "$published/$SLUG/$rel" ]; then
			# A SERVER_EXTRA file is deployed on purpose and excluded from the directory
			# build on purpose -- the German catalogue, because a hosted plugin gets its
			# translations from translate.wordpress.org. Absent there is CORRECT, and
			# reporting it as a difference every run is how a finding stops being read.
			# It is still a hazard, counted and named below rather than waved through.
			if in_server_extra "$rel"; then
				printf '  [BY DESIGN]  %s (deployed, deliberately not published)\n' "$rel"
				by_design=$((by_design + 1))
			else
				printf '  [ONLY HERE]  %s\n' "$rel"
				only_here=$((only_here + 1))
			fi
			continue
		fi

		want="$(shasum -a 256 "$ROOT/$rel" | cut -d' ' -f1)"
		got="$(shasum -a 256 "$published/$SLUG/$rel" | cut -d' ' -f1)"

		if [ "$want" != "$got" ]; then
			printf '  [DIFFERS]    %s\n' "$rel"
			differing=$((differing + 1))
		fi
	done

	# The other direction. A file the directory ships and the deploy does not is how an update
	# adds something to the site that no deploy would ever have put there.
	while IFS= read -r rel; do
		rel="${rel#./}"
		[ -f "$ROOT/$rel" ] || {
			printf '  [ONLY THERE] %s\n' "$rel"
			only_there=$((only_there + 1))
		}
	done < <(cd "$published/$SLUG" && find . -type f | sort)

	echo
	printf 'compared %d shipped file(s): %d differing, %d only here, %d only in the directory, %d by design\n' \
		"${#shipped[@]}" "$differing" "$only_here" "$only_there" "$by_design"

	if [ "$differing" -eq 0 ] && [ "$only_here" -eq 0 ] && [ "$only_there" -eq 0 ]; then
		echo
		echo "[OK] the two channels carry identical bytes at $version for every file both ship."

		if [ "$by_design" -gt 0 ]; then
			echo
			echo "[HAZARD] $by_design file(s) are deployed but deliberately absent from the"
			echo "         directory build, so a wordpress.org update REMOVES them from the"
			echo "         server. The German catalogue is one of them and the site is lang=de,"
			echo "         so an update silently reverts 28 visitor-facing strings to English."
			echo "         This is the concrete reason to keep plugin auto-updates OFF for this"
			echo "         install and to apply releases through \`push\` instead."
		fi

		return 0
	fi

	echo
	echo "[ACTION REQUIRED] the two channels disagree at the SAME version number, which is the"
	echo "                  state nothing else reports. A wordpress.org update will overwrite"
	echo "                  the server's copy with the directory's. Publish this tree to SVN, or"
	echo "                  bump the version, before deploying again."
	return 1
}

cmd_push() {
	cmd_plan || return 1

	# A pre-flight, not a gate. Knowing before the upload is strictly more useful than after,
	# but an emergency fix to a live site must not be blocked on a wordpress.org release, so
	# this reports and continues. `channels` exits non-zero for a caller that wants a gate.
	echo
	echo "release channels, before touching the server:"
	cmd_channels || true

	echo
	echo "uploading in ${CHUNK}-byte chunks:"

	for rel in "${UPLOAD_ORDER[@]}"; do
		put_chunked "$rel" || {
			echo "[ERROR] stopping: $rel did not land" >&2
			return 1
		}
	done

	echo
	echo "re-verifying every file by digest, after the whole set has landed:"

	local bad=0

	for rel in "${UPLOAD_ORDER[@]}"; do
		local want got
		want="$(shasum "$ROOT/$rel" | cut -d' ' -f1)"
		got="$(remote_digest "$rel")"

		if [ "$want" = "$got" ]; then
			printf '  [OK]   %s\n' "$rel"
		else
			printf '  [FAIL] %s (remote %s, local %s)\n' "$rel" "$got" "$want"
			bad=$((bad + 1))
		fi
	done

	echo
	echo "files: ${#UPLOAD_ORDER[@]}, mismatching: $bad"

	return "$bad"
}

# Reduces a page to WHICH PHOTOGRAPHS IT SHOWS, and nothing else.
#
# `capture` and `fingerprint` are not two ways of doing one job, and using the wrong one is how a
# verification lies in whichever direction is least convenient:
#
#   - A DEPLOY must not change a rendered byte, so `capture` hashes the whole page. That is the
#     strongest possible statement and the right one when nothing about the markup should move.
#   - A MIGRATION changes the post type, and WordPress puts the post type in its own body and
#     post classes -- `single-envira` becomes `single-lichtbild_gallery` on every page. A byte hash
#     therefore reports 100% changed and tells you nothing. Measured once: all 110 local URLs
#     "changed", none of them meaningfully.
#
# So the migration's question is not "are the bytes identical" but "are these the same
# photographs, in the same number, however they are wrapped". Upload filenames with their size
# suffixes stripped answer exactly that, and the tile count stops a page that lost every image
# from matching an empty one.
lichtbild_semantic() {
	local page
	page="$(cat)"

	{
		printf '%s' "$page" |
			grep -o 'wp-content/uploads/[^"'"'"' )]*\.\(jpg\|jpeg\|png\|gif\|webp\)' |
			sed 's/-[0-9]\{2,\}x[0-9]\{2,\}\././' |
			sort -u
		# The tile pattern matches the LEGACY class name as well, and that is not tidiness -- it is
		# what makes this instrument usable across the 26.8.16 rename at all.
		#
		# A fingerprint exists to compare a page before a change against the same page after it,
		# so anything in it that is spelled differently on the two sides reports a difference that
		# is purely the instrument's. Counting only `lichtbild-item` against a pre-rename page finds
		# zero and records `tiles:0`, so EVERY page carrying a gallery compares as changed -- 52 of
		# 111 in the local rehearsal -- while the tag archives, having no tiles, compare as
		# identical. That shape is the tell: a difference that tracks whether the page has any
		# tiles at all, rather than which photographs are on it.
		#
		# Proved rather than assumed: recomputing a changed page's hash with the tile count forced
		# to 0 reproduced the before-hash exactly, so the image sets and titles were identical and
		# the count was the whole difference.
		#
		# Safe to drop the `tivira` alternative once no capture predating 26.8.16 is still being
		# compared against -- and harmless to leave, since a page cannot carry both.
		printf 'tiles:%s\n' "$(printf '%s' "$page" | grep -oE '(lichtbild|tivira)-(album-)?item' | wc -l | tr -d ' ')"

		# The document title, because the image set alone is blind on any page that has no
		# images -- and 60 of this site's 159 URLs are exactly that: 58 tag archives, which
		# list attachments the theme renders no thumbnails for, plus the protected gallery.
		# Without this the strongest thing "unchanged" could mean for those is "still empty",
		# which is satisfied by a page that broke into an empty one. The title comes from the
		# term or the post, so the migration must not move it.
		printf '%s' "$page" | grep -o '<title>[^<]*</title>' | head -1
	} | shasum | cut -d' ' -f1
}

cmd_urls() {
	uv run --with pymysql python "$ROOT/tools/live-urls.py"
}

# Record only complete observations. A failed transfer can still print an HTTP status and
# partial body, so require curl's success and a non-empty body before hashing either format.
cmd_observe() {
	local mode="$1" out="$2" urls="${3:-$WORK/urls.txt}"
	local u code hash count=0

	[ -s "$urls" ] || {
		echo "[ERROR] no URL list at $urls; run 'urls' first" >&2
		return 2
	}

	: > "$out" || return 1
	: > "$WORK/observations.tsv"

	while IFS= read -r u || [ -n "$u" ]; do
		[ -z "$u" ] && continue
		if ! code="$(curl -sS --max-time 30 -o "$WORK/body.html" -w '%{http_code}' "$u")"; then
			echo "[ERROR] could not capture $u" >&2
			return 1
		fi
		if [[ ! "$code" =~ ^[1-5][0-9][0-9]$ ]] || [ ! -s "$WORK/body.html" ]; then
			echo "[ERROR] invalid status or empty body for $u" >&2
			return 1
		fi

		if [ "$mode" = fingerprinted ]; then
			if ! hash="$(lichtbild_semantic < "$WORK/body.html")"; then
				echo "[ERROR] could not fingerprint $u; the response must contain a document title" >&2
				return 1
			fi
		else
			# Only asset versions may change during an otherwise equivalent deploy.
			hash="$(sed 's/?ver=[0-9.]*//g' "$WORK/body.html" | shasum | cut -d' ' -f1)" || return 1
		fi
		printf '%s\t%s\t%s\n' "$u" "$hash" "$code" >> "$WORK/observations.tsv"
		count=$((count + 1))
	done < "$urls"

	if [ "$count" -eq 0 ]; then
		echo "[ERROR] the URL list contained no URLs" >&2
		return 1
	fi
	cat "$WORK/observations.tsv" > "$out" || return 1
	printf '%s %s urls, non-200: %s\n' "$mode" "$count" \
		"$(awk -F'\t' '$3!=200' "$out" | wc -l | tr -d ' ')"
}

cmd_capture() { cmd_observe captured "$@"; }
cmd_fingerprint() { cmd_observe fingerprinted "$@"; }

cmd_compare() {
	local a="$1" b="$2"

	# Joining two empty or failed captures says nothing about the site. Validate each input
	# before joining, including unique URL keys so duplicates cannot hide dropped rows.
	local input
	for input in "$a" "$b"; do
		if [ ! -s "$input" ] || ! awk -F'\t' '
			NF != 3 || $1 == "" || $2 == "" || $3 !~ /^[1-5][0-9][0-9]$/ { bad = 1 }
			seen[$1]++ { bad = 1 }
			END { exit (bad || NR == 0) ? 1 : 0 }
		' "$input"; then
			echo "[ERROR] empty, malformed or duplicate observations in $input" >&2
			return 1
		fi
	done

	join -t "$(printf '\t')" -j1 <(sort "$a") <(sort "$b") > "$WORK/joined.tsv"

	local rows changed misjoined
	rows="$(wc -l < "$WORK/joined.tsv" | tr -d ' ')"
	changed="$(awk -F'\t' '$2!=$4' "$WORK/joined.tsv" | wc -l | tr -d ' ')"

	# The join's own sanity. A previous deploy compared the hash column against the status
	# column and reported all 116 URLs as changed; it failed safe, but only by luck.
	misjoined="$(awk -F'\t' 'NF!=5' "$WORK/joined.tsv" | wc -l | tr -d ' ')"

	local statuses before_rows after_rows
	statuses="$(awk -F'\t' '$3!=$5' "$WORK/joined.tsv" | wc -l | tr -d ' ')"
	before_rows="$(wc -l < "$a" | tr -d ' ')"
	after_rows="$(wc -l < "$b" | tr -d ' ')"

	printf 'joined %s of %s rows, malformed %s, changed %s, status differences %s\n' \
		"$rows" "$before_rows" "$misjoined" "$changed" "$statuses"

	awk -F'\t' '$2!=$4 {print "  CHANGED  "$1}' "$WORK/joined.tsv"
	awk -F'\t' '$3!=$5 {print "  STATUS   "$1"  "$3" -> "$5}' "$WORK/joined.tsv"

	# BOTH directions. The row count was checked against the first capture only, so a URL that
	# exists in the second and not the first -- a page the deploy ADDED, or a capture taken from
	# a different url list -- joined to nothing and was invisible. A comparison that cannot see
	# what appeared is not a comparison of two states.
	[ "$rows" = "$before_rows" ] || {
		echo "[ERROR] the join dropped rows present in $a; the comparison covers less than it appears to" >&2
		return 1
	}

	[ "$rows" = "$after_rows" ] || {
		echo "[ERROR] $b has $after_rows rows and only $rows joined; something appeared that the before-capture never had" >&2
		return 1
	}

	# The exit code, which is the whole reason this is a gate rather than a report. It printed
	# `changed 1` beside `CHANGED <url>` and exited 0, so the documented release ceremony's
	# verification step was green over a page whose bytes had moved -- and anything scripted
	# around it, branching on `$?`, could never have noticed.
	if [ "$misjoined" != "0" ] || [ "$changed" != "0" ] || [ "$statuses" != "0" ]; then
		echo "[ERROR] the site is not what it was: $changed changed, $statuses status differences, $misjoined malformed" >&2
		return 1
	fi

	return 0
}

case "${1:-}" in
	# audit [--against <dir>] -- what does the server actually have? Read-only, and with
	# `--against` it reads a directory instead, which is how tests/deploy-audit-test.sh proves
	# every verdict without a deploy.
	audit)
		shift
		cmd_audit "$@"
		;;
	plan) cmd_plan ;;
	urls) cmd_urls ;;
	# channels [--against <zip>] -- see the block above cmd_channels. Offline with --against.
	channels)
		shift
		cmd_channels "$@"
		;;
	push) cmd_push ;;
	capture) cmd_capture "${2:?usage: capture <out> [urls]}" "${3:-}" ;;
	fingerprint) cmd_fingerprint "${2:?usage: fingerprint <out> [urls]}" "${3:-}" ;;
	compare) cmd_compare "${2:?usage: compare <before> <after>}" "${3:?}" ;;
	# order-check <deployed-tree> [file ...] -- the removed-method constraint on its own, against
	# a tree on disk instead of the server, with an order the caller chooses. Offline by design:
	# it is how tests/deploy-order-test.sh proves the check can go red without breaking a deploy.
	order-check)
		shift
		REF="${1:?usage: order-check <deployed-tree-dir> [file ...]}"
		shift

		[ -d "$REF" ] || {
			echo "[ERROR] no such deployed tree: $REF" >&2
			exit 2
		}

		[ "$#" -gt 0 ] && ORDER=( "$@" )
		check_removed_methods "$REF"
		;;
	*)
		sed -n '2,30p' "${BASH_SOURCE[0]}"
		exit 1
		;;
esac
