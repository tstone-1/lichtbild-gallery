#!/usr/bin/env bash
# Offline transport failure tests: bash tests/deploy-upload-test.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
source "$ROOT/tools/deploy.sh" source-only
TEST_TMP="$(mktemp -d)"
trap 'rm -rf "$TEST_TMP" "$WORK"' EXIT
mkdir -p "$TEST_TMP/local" "$TEST_TMP/remote" "$TEST_TMP/no-hooks"
ROOT="$TEST_TMP/local"
REMOTE="$TEST_TMP/remote"
HOST=example.invalid
REMOTE_DIR=/plugin
printf '<?php /* old */\n' > "$REMOTE/code.php"
cp "$REMOTE/code.php" "$TEST_TMP/original"
{ printf '<?php /* '; head -c 18000 /dev/zero | tr '\0' x; printf ' */\n'; } > "$ROOT/code.php"
printf '<?php /* later */\n' > "$ROOT/later.php"
# `push` refuses an upload set that differs from HEAD, so the local tree is a committed repo.
# Without that, every push case below would stop at the commit check and pass for that reason.
fixture_git() { git -C "$ROOT" -c core.hooksPath="$TEST_TMP/no-hooks" -c commit.gpgsign=false \
    -c user.name=Fixture -c user.email=fixture@example.test "$@"; }
fixture_git init --quiet
fixture_git add code.php later.php
fixture_git commit --quiet -m Fixture
MODE=success
unsafe=0
published=0

# Counted as they run, and the total is asserted: a case that silently stops running would
# otherwise leave the summary line unchanged, which is the failure a printed constant cannot see.
cases=0
fails=0
pass() { cases=$((cases + 1)); echo "[OK] $1"; }
fail() { cases=$((cases + 1)); fails=$((fails + 1)); echo "[FAIL] $1"; }

# Only the transport is faked. The production splitter, retry loop, verifier and publisher run.
ftp() {
    local append=0 upload='' quote='' from='' to='' url='' arg
    while [ "$#" -gt 0 ]; do
        arg="$1"; shift
        case "$arg" in
            --append) append=1 ;;
            -T) upload="$1"; shift ;;
            --quote)
                quote="$1"; shift
                case "$quote" in
                    'RNFR '*) from="${quote#RNFR $REMOTE_DIR/}" ;;
                    'RNTO '*) to="${quote#RNTO $REMOTE_DIR/}" ;;
                    'DELE '*) rm -f "$REMOTE/${quote#DELE $REMOTE_DIR/}" ;;
                esac ;;
            ftp://*) url="$arg" ;;
        esac
    done
    if [ -n "$upload" ]; then
        local path="$REMOTE/${url#ftp://$HOST$REMOTE_DIR/}"
        if [ "$append" -eq 1 ] && [ "$MODE" = fail ]; then
            head -c 3 "$upload" >> "$path"
            return 1
        fi
        if [ "$append" -eq 1 ]; then cat "$upload" >> "$path"; else cat "$upload" > "$path"; fi
        # Observe the live bytes after EVERY transfer, not just the final state.
        cmp -s "$REMOTE/code.php" "$TEST_TMP/original" || unsafe=1
    fi
    if [ -n "$to" ]; then
        [ "$MODE" != rename-fail ] || return 1
        mv -f "$REMOTE/$from" "$REMOTE/$to" || return 1
        published=$((published + 1))
        # The staged bytes verified, and the server published something else. Only the check
        # after the rename can see this, which is why this mode exists.
        if [ "$MODE" = published-corrupt ]; then printf z >> "$REMOTE/$to"; fi
    fi
}
remote_size() { wc -c < "$REMOTE/$1" | tr -d '[:space:]'; }
remote_digest() {
    if [ "$MODE" = digest-fail ]; then printf mismatch; else shasum "$REMOTE/$1" | cut -d' ' -f1; fi
}

for MODE in fail digest-fail rename-fail success; do
    cp "$TEST_TMP/original" "$REMOTE/code.php"
    unsafe=0; published=0
    put_chunked code.php > "$TEST_TMP/log" 2>&1
    code=$?
    if [ "$MODE" = success ]; then
        if [ "$code" -eq 0 ] && [ "$unsafe" -eq 0 ] && [ "$published" -eq 1 ] && cmp -s "$REMOTE/code.php" "$ROOT/code.php"; then
            pass 'complete verified bytes replace the live file once'
        else fail 'successful publication'; cat "$TEST_TMP/log"; fi
    else
        if [ "$code" -ne 0 ] && [ "$unsafe" -eq 0 ] && [ "$published" -eq 0 ] && cmp -s "$REMOTE/code.php" "$TEST_TMP/original"; then
            pass "$MODE preserves live bytes throughout"
        else fail "$MODE"; cat "$TEST_TMP/log"; fi
    fi
done

MODE=published-corrupt
cp "$TEST_TMP/original" "$REMOTE/code.php"
published=0
put_chunked code.php > "$TEST_TMP/log" 2>&1
code=$?
if [ "$code" -ne 0 ] && [ "$published" -eq 1 ] && grep -q 'published digest could not be verified' "$TEST_TMP/log"; then
    pass 'published bytes that differ from the verified staged copy fail the upload'
else fail 'published-corrupt'; cat "$TEST_TMP/log"; fi

MODE=success
if check_atomic_replace > "$TEST_TMP/log" 2>&1; then pass 'preflight accepts a server that renames over a file'
else fail 'preflight on a working server'; cat "$TEST_TMP/log"; fi
MODE=rename-fail
if check_atomic_replace > "$TEST_TMP/log" 2>&1; then fail 'refused rename passed preflight'
else pass 'preflight rejects unsupported replacement'; fi

# The production caller, with only the planning steps stubbed out.
cmd_plan() { return 0; }
cmd_channels() { return 0; }
UPLOAD_ORDER=( code.php later.php )

# A failure after the rename stops the loop: the files after it, the version-bumping bootstrap
# among them in a real upload, must not land on top of a file whose published bytes are wrong.
eval "$(declare -f check_atomic_replace | sed '1s/^check_atomic_replace/real_check_atomic_replace/')"
check_atomic_replace() { return 0; }
MODE=published-corrupt
cp "$TEST_TMP/original" "$REMOTE/code.php"
rm -f "$REMOTE/later.php"
if cmd_push > "$TEST_TMP/log" 2>&1 || [ -e "$REMOTE/later.php" ]; then
    fail 'push continued past a published digest mismatch'; cat "$TEST_TMP/log"
else pass 'a published digest mismatch stops push before the next file'; fi
check_atomic_replace() { real_check_atomic_replace "$@"; }

# From here on the upload itself is a stub that records it was reached and lands the bytes, so
# the re-verification pass in `push` has something true to compare against.
MODE=rename-fail
put_chunked() { echo called > "$TEST_TMP/unexpected"; cp "$ROOT/$1" "$REMOTE/$1"; }
if cmd_push > "$TEST_TMP/log" 2>&1 || [ -e "$TEST_TMP/unexpected" ]; then
    fail 'push ignored failed rename preflight'
elif ! grep -q 'cannot verify replacement by rename' "$TEST_TMP/log"; then
    fail 'push stopped, but not at the rename preflight'; cat "$TEST_TMP/log"
else pass 'failed rename preflight stops push'; fi

# The commit check runs before anything opens a connection, so the planning stubs record calls.
planned=0
cmd_plan() { planned=1; return 0; }
MODE=success
rm -f "$TEST_TMP/unexpected"
printf '<?php /* edited, not committed */\n' > "$ROOT/later.php"
if cmd_push > "$TEST_TMP/log" 2>&1 || [ -e "$TEST_TMP/unexpected" ] || [ "$planned" -ne 0 ]; then
    fail 'push uploaded an uncommitted file'; cat "$TEST_TMP/log"
elif ! grep -q 'differs from HEAD' "$TEST_TMP/log" || grep -q 'edited, not committed' "$TEST_TMP/log"; then
    fail 'push refused, but not for the uncommitted file'; cat "$TEST_TMP/log"
else pass 'an uncommitted upload file stops push before any connection'; fi

# The override is explicit, and with it the same tree goes through to the upload.
if LICHTBILD_DEPLOY_DIRTY=1 cmd_push > "$TEST_TMP/log" 2>&1 && [ -e "$TEST_TMP/unexpected" ]; then
    pass 'LICHTBILD_DEPLOY_DIRTY=1 uploads the working tree on purpose'
else fail 'override did not reach the upload'; cat "$TEST_TMP/log"; fi
fixture_git checkout --quiet -- later.php

# A tree git cannot answer for is refused, not treated as clean.
rm -f "$TEST_TMP/unexpected"
mv "$ROOT/.git" "$TEST_TMP/hidden-git"
if GIT_CEILING_DIRECTORIES="$TEST_TMP" cmd_push > "$TEST_TMP/log" 2>&1 || [ -e "$TEST_TMP/unexpected" ]; then
    fail 'push uploaded from a tree git could not read'; cat "$TEST_TMP/log"
elif ! grep -q 'could not ask git' "$TEST_TMP/log"; then
    fail 'push stopped, but not at the commit check'; cat "$TEST_TMP/log"
else pass 'an unreadable git state stops push'; fi
mv "$TEST_TMP/hidden-git" "$ROOT/.git"

# And the clean committed tree is not refused by the check itself.
if cmd_push > "$TEST_TMP/log" 2>&1 && [ -e "$TEST_TMP/unexpected" ]; then
    pass 'a committed upload set passes the commit check'
else fail 'clean committed tree refused'; cat "$TEST_TMP/log"; fi

expected=13
echo "upload tests: $cases of $expected, failing: $fails"
[ "$fails" -eq 0 ] && [ "$cases" -eq "$expected" ]
