#!/usr/bin/env bash
# Offline keychain refusal checks: bash tests/deploy-credentials-test.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
source "$ROOT/tools/deploy.sh" source-only
HOST=example.invalid
USER_NAME=fixture
security() {
    printf x >> "$WORK/keychain-calls"
    printf 'synthetic-secret\n'
    [ "${UNLOCKED:-0}" = 1 ]
}
curl() {
    printf x >> "$WORK/curl-calls"
    [ "$1" = --netrc-file ] && [ -s "$2" ]
}
shipped_files() { echo lichtbild-gallery.php; }
fails=0
for call in netrc ftp cmd_audit cmd_plan; do
    if "$call" > "$WORK/output" 2>&1; then
        echo "[FAIL] $call accepted an unavailable keychain"; fails=$((fails + 1))
    elif [ -e "$WORK/netrc" ] || [ -e "$WORK/curl-calls" ]; then
        echo "[FAIL] $call retained partial credentials or attempted a connection"; fails=$((fails + 1))
    else
        echo "[OK] $call refuses without cached credentials or a connection"
    fi
done
UNLOCKED=1
if ftp && ftp && [ "$(wc -c < "$WORK/keychain-calls" | tr -d '[:space:]')" = 5 ] &&
    [ "$(wc -c < "$WORK/curl-calls" | tr -d '[:space:]')" = 2 ]; then
    echo '[OK] unlocking retries the lookup and caches only successful credentials'
else
    echo '[FAIL] unlocked credential control'; fails=$((fails + 1))
fi
# Exercise the real SIZE parser and its callers, not a pre-classified disk fixture.
curl() {
    printf x >> "$WORK/curl-calls"
    printf '%s\n' "$RESPONSE"
    return "$CURL_STATUS"
}
cases=5
for spec in '0 0 0' '0 123 123' '78 none -1' '56 none unreadable' '67 none unreadable' '56 123 unreadable' '0 none unreadable'; do
    rm -f "$WORK/ftp-auth-failed"
    read -r CURL_STATUS length expected <<< "$spec"
    RESPONSE=''
    [ "$length" != none ] && RESPONSE="Content-Length: $length"
    actual="$(remote_size lichtbild-gallery.php)"
    status=$?
    cases=$((cases + 1))
    if [ "$actual" = "$expected" ] && {
        { [ "$expected" = unreadable ] && [ "$status" -ne 0 ]; } ||
        { [ "$expected" != unreadable ] && [ "$status" -eq 0 ]; }
    }; then
        echo "[OK] SIZE status $CURL_STATUS, length $length: $expected"
    else
        echo "[FAIL] SIZE status $CURL_STATUS, length $length"; fails=$((fails + 1))
    fi
done
rm -f "$WORK/ftp-auth-failed"
: > "$WORK/curl-calls"
CURL_STATUS=67
RESPONSE=''
cases=$((cases + 1))
for attempt in 1 2 3; do ftp > "$WORK/output" 2>&1; done
if [ -f "$WORK/ftp-auth-failed" ] &&
    [ "$(wc -c < "$WORK/curl-calls" | tr -d '[:space:]')" = 1 ]; then
    echo '[OK] rejected login blocks subsequent authentication attempts in the same run'
else
    echo '[FAIL] rejected login was retried'; fails=$((fails + 1))
fi
rm -f "$WORK/ftp-auth-failed"
CURL_STATUS=56
RESPONSE=''
shipped_files() { printf '%s\n' lichtbild-gallery.php readme.txt; }
for call in cmd_audit cmd_plan; do
    : > "$WORK/curl-calls"
    "$call" > "$WORK/output" 2>&1
    status=$?
    calls="$(wc -c < "$WORK/curl-calls" | tr -d '[:space:]')"
    cases=$((cases + 1))
    if [ "$status" -ne 0 ] && [ "$calls" -gt 0 ] && [ "$calls" -le 2 ] &&
        ! grep -qE 'ABSENT|FIRST INSTALL' "$WORK/output"; then
        echo "[OK] $call stops on an unreadable connection without claiming absence"
    else
        echo "[FAIL] $call mishandled a failed connection"; fails=$((fails + 1))
    fi
done
echo "credential and connection tests: $cases, failing: $fails"
[ "$fails" -eq 0 ]
