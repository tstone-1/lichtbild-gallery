#!/usr/bin/env bash
# Offline transport failure tests: bash tests/deploy-upload-test.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
source "$ROOT/tools/deploy.sh" source-only
TEST_TMP="$(mktemp -d)"
trap 'rm -rf "$TEST_TMP" "$WORK"' EXIT
mkdir -p "$TEST_TMP/local" "$TEST_TMP/remote"
ROOT="$TEST_TMP/local"
REMOTE="$TEST_TMP/remote"
HOST=example.invalid
REMOTE_DIR=/plugin
printf '<?php /* old */\n' > "$REMOTE/code.php"
cp "$REMOTE/code.php" "$TEST_TMP/original"
{ printf '<?php /* '; head -c 18000 /dev/zero | tr '\0' x; printf ' */\n'; } > "$ROOT/code.php"
MODE=success
unsafe=0
published=0

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
        if [ "$MODE" = corrupt ]; then printf z >> "$path"; fi
        # Observe the live bytes after EVERY transfer, not just the final state.
        cmp -s "$REMOTE/code.php" "$TEST_TMP/original" || unsafe=1
    fi
    if [ -n "$to" ]; then
        [ "$MODE" != rename-fail ] || return 1
        mv -f "$REMOTE/$from" "$REMOTE/$to" || return 1
        published=$((published + 1))
    fi
}
remote_size() { wc -c < "$REMOTE/$1" | tr -d '[:space:]'; }
remote_digest() {
    if [ "$MODE" = digest-fail ]; then printf mismatch; else shasum "$REMOTE/$1" | cut -d' ' -f1; fi
}
fails=0
for MODE in fail digest-fail rename-fail success; do
    cp "$TEST_TMP/original" "$REMOTE/code.php"
    unsafe=0; published=0
    put_chunked code.php > "$TEST_TMP/log" 2>&1
    code=$?
    if [ "$MODE" = success ]; then
        if [ "$code" -eq 0 ] && [ "$unsafe" -eq 0 ] && [ "$published" -eq 1 ] && cmp -s "$REMOTE/code.php" "$ROOT/code.php"; then
            echo '[OK] complete verified bytes replace the live file once'
        else echo '[FAIL] successful publication'; cat "$TEST_TMP/log"; fails=$((fails + 1)); fi
    else
        if [ "$code" -ne 0 ] && [ "$unsafe" -eq 0 ] && [ "$published" -eq 0 ] && cmp -s "$REMOTE/code.php" "$TEST_TMP/original"; then
            echo "[OK] $MODE preserves live bytes throughout"
        else echo "[FAIL] $MODE"; cat "$TEST_TMP/log"; fails=$((fails + 1)); fi
    fi
done
MODE=success
check_atomic_replace || fails=$((fails + 1))
MODE=rename-fail
if check_atomic_replace; then echo '[FAIL] refused rename passed preflight'; fails=$((fails + 1)); else echo '[OK] preflight rejects unsupported replacement'; fi
# Verify the production caller cannot bypass a failed preflight.
cmd_plan() { return 0; }
cmd_channels() { return 0; }
put_chunked() { echo called > "$TEST_TMP/unexpected"; }
if cmd_push || [ -e "$TEST_TMP/unexpected" ]; then
    echo '[FAIL] push ignored failed rename preflight'; fails=$((fails + 1))
else echo '[OK] failed rename preflight stops push'; fi
echo "upload tests: 7, failing: $fails"
[ "$fails" -eq 0 ]
