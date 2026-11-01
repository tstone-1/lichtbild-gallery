#!/usr/bin/env bash
#
# Runs the render suite under every PHP version installed, and reports a table.
#
#     bash tests/php-matrix.sh [path/to/fixture.json]
#
# The suite is a plain PHP program with no WordPress and no database, so running it across
# versions costs nothing but wall clock — which makes it the cheapest possible check on the
# one thing a single-version run cannot see: whether the plugin's own code is valid and
# behaves identically on the interpreter the live site actually uses.
#
# That matters here because the versions do not line up. This machine ships PHP 8.5;
# timo-stein.com runs 8.2. Every result until this script existed was measured on an
# interpreter the site does not have, which is the same family of mistake as benchmarking a
# debug build: not wrong so much as answering a question nobody asked.
#
# Only versions actually installed are run, and the script says which it skipped rather than
# quietly covering less than it appears to. A version that is absent is not a pass.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE="${1:-$ROOT/tests/fixture.json}"

# Ordered oldest first, so a floor that has moved shows up as the first red row rather than
# somewhere in the middle. `Requires PHP` in lichtbild-gallery.php must name the oldest one that passes.
VERSIONS=(8.2 8.3 8.4 8.5)

# The version the live site runs. A failure here is a production failure; a failure anywhere
# else is a portability finding.
PRODUCTION=8.2

if [ ! -r "$FIXTURE" ]; then
	echo "[ERROR] fixture not readable: $FIXTURE"
	echo "        regenerate it with tests/export-fixture.py"
	exit 2
fi

printf '%-10s %-10s %-9s %-8s %s\n' VERSION BINARY CHECKS FAILING RESULT
printf -- '-%.0s' {1..64}; echo

ran=0
bad=0
missing=()

for version in "${VERSIONS[@]}"; do
	binary="/opt/homebrew/opt/php@${version}/bin/php"

	# The current default formula is `php`, not `php@<latest>`, so fall back to it when the
	# versioned path is absent but the default happens to be this version.
	if [ ! -x "$binary" ]; then
		if [ -x /opt/homebrew/bin/php ] && [ "$(/opt/homebrew/bin/php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" = "$version" ]; then
			binary=/opt/homebrew/bin/php
		else
			missing+=("$version")
			printf '%-10s %-10s %-9s %-8s %s\n' "$version" "-" "-" "-" "[SKIP] not installed"
			continue
		fi
	fi

	output="$("$binary" "$ROOT/tests/render-test.php" "$FIXTURE" 2>&1)"
	status=$?

	# Positive evidence the run happened. A crash produces no summary line, and treating that
	# as a pass is exactly how a matrix comes to cover nothing while printing a full table.
	summary="$(printf '%s\n' "$output" | grep -E '^checks: [0-9]+, failing: [0-9]+$' | tail -1)"

	if [ -z "$summary" ]; then
		bad=$((bad + 1))
		printf '%-10s %-10s %-9s %-8s %s\n' "$version" "$("$binary" -r 'echo PHP_VERSION;')" "-" "-" "[BROKEN] no summary (exit $status)"
		printf '%s\n' "$output" | tail -4 | sed 's/^/           /'
		continue
	fi

	checks="$(printf '%s' "$summary" | sed -E 's/^checks: ([0-9]+).*/\1/')"
	failing="$(printf '%s' "$summary" | sed -E 's/.*failing: ([0-9]+)$/\1/')"
	ran=$((ran + 1))

	if [ "$failing" = "0" ] && [ "$status" = "0" ]; then
		note="[OK]"
		[ "$version" = "$PRODUCTION" ] && note="[OK] <- production"
	else
		bad=$((bad + 1))
		note="[FAIL]"
		[ "$version" = "$PRODUCTION" ] && note="[FAIL] <- production, fix first"
	fi

	printf '%-10s %-10s %-9s %-8s %s\n' "$version" "$("$binary" -r 'echo PHP_VERSION;')" "$checks" "$failing" "$note"
done

printf -- '-%.0s' {1..64}; echo
echo "ran: $ran, failing: $bad, skipped: ${#missing[@]}"

# A production version that never ran is not a pass, however green the rest of the table is.
if printf '%s\n' "${missing[@]:-}" | grep -qx "$PRODUCTION"; then
	echo "[ERROR] PHP $PRODUCTION is what the live site runs and it was not tested."
	echo "        brew install php@$PRODUCTION"
	exit 2
fi

[ "$bad" -eq 0 ] || exit 1
