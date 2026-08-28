#!/usr/bin/env bash
#
# Builds and drives a local WordPress carrying a copy of the live site's database, so the
# migration can be run against a real MariaDB, real rewrite rules and the real Envira plugin.
#
#     bash tools/devenv.sh setup     # build it (needs tests/.db.json)
#     bash tools/devenv.sh start     # start database + web server
#     bash tools/devenv.sh stop      # stop both
#     bash tools/devenv.sh reset     # restore the database to the imported snapshot
#     bash tools/devenv.sh snapshot  # take a new snapshot of the current state
#     bash tools/devenv.sh status    # what is running, and what state the data is in
#     bash tools/devenv.sh wp <...>  # run wp-cli against it
#
#     bash tools/devenv.sh fresh          # a SECOND, empty site with the plugin installed
#                                         # from wordpress.org -- see cmd_fresh
#     bash tools/devenv.sh fresh test     # the stranger's path, end to end
#     bash tools/devenv.sh fresh --from <zip>   # ... using a locally built archive instead,
#                                               # which is how a fix to that path is tested
#                                               # BEFORE it is published
#     bash tools/devenv.sh fresh <cmd>    # start | stop | status | wp, against that site
#
# WHY THIS EXISTS, given there is already a 90-check suite that needs none of it: the stubs
# model WordPress, and five things are structurally beyond them — real `$wpdb` against the
# production engine, real rewrite-rule generation, real object and term caches, the real
# Envira plugin to coexist with, and the admin screen actually rendering. Every one of those
# is load-bearing for a migration that renames post types on a live site.
#
# THREE DECISIONS WORTH KNOWING:
#
# - **The database is disposable and self-contained.** Its data directory lives under the
#   environment directory on a non-default port, rather than `brew services` installing a
#   daemon that starts at login forever. A test environment should not outlive the test.
# - **PHP is pinned to the version the live site runs**, not the newest installed. The whole
#   point is fidelity; measuring on 8.5 when production is 8.2 answers a question nobody
#   asked.
# - **Uploads are not copied.** 2,243 image files prove nothing the database does not already
#   carry: dimensions come from `_wp_attachment_metadata`, so the justified layout is exact
#   either way. A must-use plugin points attachment URLs at the live domain instead.
#
# Nothing here ever writes to the live site. The only remote access is a read-only dump, and
# that is done by `tests/export-fixture.py`'s sibling path, not by this script.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Two environments, one script. `fresh` is a separate site on separate ports with its own data
# directory, so it can run beside the migration environment without either touching the other --
# and it must be separate, because the two model opposite sites: one continues an Envira
# installation, the other has never heard of Envira and installs the plugin from wordpress.org.
FRESH=0
if [ "${1:-}" = "fresh" ]; then
	FRESH=1
	shift
fi

if [ "$FRESH" = 1 ]; then
	ENV_DIR="${LICHTBILD_FRESH_DEVENV:-$HOME/Developer/wp-lichtbild-fresh}"
	DB_PORT=3308
	WP_PORT=8081
else
	ENV_DIR="${LICHTBILD_DEVENV:-$HOME/Developer/wp-lichtbild}"
	DB_PORT=3307
	WP_PORT=8080
fi

MARIADB_PREFIX=/opt/homebrew/opt/mariadb@10.11
PHP_VERSION=8.2
PHP_BIN="/opt/homebrew/opt/php@${PHP_VERSION}/bin/php"

DB_SOCKET="$ENV_DIR/mysql.sock"
DB_DATADIR="$ENV_DIR/mysql"
DB_LOG="$ENV_DIR/mysql.log"

WP_DIR="$ENV_DIR/wordpress"
WP_URL="http://localhost:$WP_PORT"
WP_LOG="$ENV_DIR/web.log"

SNAPSHOT_DIR="$ENV_DIR/db-snapshots"
PRODUCTION_SQL="$SNAPSHOT_DIR/production.sql"
BASELINE_SQL="$SNAPSHOT_DIR/baseline.sql"

# Local credentials. Deliberately trivial: this database listens on a non-default port, holds
# a copy of public gallery content, and is thrown away. The production credentials never
# appear here — they are read from tests/.db.json only when a dump is taken.
DB_NAME=lichtbild_dev
DB_USER=root

# The table prefix belongs to the PRODUCTION site, not to this environment -- the local database
# is restored from a dump of it, so it inherits whatever prefix that site uses. That makes it a
# detail about the deployment target rather than about this software, which is why it is read from
# the gitignored `tools/deploy.env` (or the environment) instead of sitting here as a literal in a
# public repository. `wp_` is WordPress's own default and the right fallback for anyone else.
[ -f "$(dirname "${BASH_SOURCE[0]}")/deploy.env" ] && . "$(dirname "${BASH_SOURCE[0]}")/deploy.env"
# The production dump carries the live site's prefix, and this environment IS that dump, so
# `wp_` was never right here: it made cmd_reset's verification query name a table that does
# not exist, so the count came back empty and the check could neither pass nor fail honestly.
DB_PREFIX="${LICHTBILD_DB_PREFIX:-wp_ts_}"

say() { printf '[devenv] %s\n' "$*"; }
die() { printf '[devenv] ERROR: %s\n' "$*" >&2; exit 1; }

mysql_client() { "$MARIADB_PREFIX/bin/mariadb" --socket="$DB_SOCKET" -u "$DB_USER" "$@"; }

db_running() { [ -S "$DB_SOCKET" ] && mysql_client -e "SELECT 1" >/dev/null 2>&1; }

web_running() { [ -f "$ENV_DIR/web.pid" ] && kill -0 "$(cat "$ENV_DIR/web.pid")" 2>/dev/null; }

wp_cli() {
	# Invoked through the pinned interpreter explicitly, so the environment is configured by
	# the same PHP it will be tested on.
	#
	# `display_errors=stderr` is the load-bearing flag, and not for tidiness: PHP CLI sends
	# errors to **stdout** by default, and wp-cli 2.12's own vendored code is not
	# deprecation-clean on 8.2+ — so `$(wp core version)` came back as a notice followed by a
	# newline followed by the version, and every captured value was silently wrong. Sending
	# them to stderr makes command substitution mean what it looks like it means.
	# `memory_limit` is raised because wp-cli extracts the core zip in memory: a full download
	# (one that keeps the bundled themes) dies at 128M with "Allowed memory size exhausted" in
	# Extractor.php, and the failure lands on `core download` rather than on anything to do
	# with this plugin.
	"$PHP_BIN" -d display_errors=stderr -d error_reporting="E_ALL & ~E_DEPRECATED" \
		-d memory_limit=512M \
		/opt/homebrew/bin/wp --path="$WP_DIR" "$@"
}

# wp-cli output is captured in several places; a stray newline turns an equality check into a
# puzzle. Trimmed once here rather than at each call site.
wp_value() { wp_cli "$@" 2>/dev/null | tr -d '[:space:]'; }

start_db() {
	[ -d "$DB_DATADIR" ] || die "no database yet - run 'setup' first"

	if db_running; then
		say "database already running on port $DB_PORT"
		return 0
	fi

	say "starting MariaDB on port $DB_PORT"
	"$MARIADB_PREFIX/bin/mariadbd" \
		--datadir="$DB_DATADIR" \
		--socket="$DB_SOCKET" \
		--port="$DB_PORT" \
		--bind-address=127.0.0.1 \
		--skip-networking=0 \
		--pid-file="$ENV_DIR/mysql.pid" \
		>>"$DB_LOG" 2>&1 &

	# Wait for it to answer rather than sleeping a guessed interval, and fail loudly if it
	# never does — a server that did not come up must not look like one that did.
	for _ in $(seq 1 60); do
		db_running && { say "database up"; return 0; }
		sleep 0.5
	done

	tail -5 "$DB_LOG" >&2
	die "database did not start within 30s - see $DB_LOG"
}

stop_db() {
	if ! db_running; then
		say "database not running"
		return 0
	fi

	say "stopping database"
	"$MARIADB_PREFIX/bin/mariadb-admin" --socket="$DB_SOCKET" -u "$DB_USER" shutdown 2>/dev/null

	for _ in $(seq 1 40); do
		db_running || { say "database stopped"; return 0; }
		sleep 0.5
	done

	die "database did not stop"
}

start_web() {
	if web_running; then
		say "web server already running at $WP_URL"
		return 0
	fi

	write_router

	# The router is not optional. PHP's built-in server has no rewrite engine, so without it
	# every pretty permalink — `/envira/<slug>/` included — returns 404 from the server before
	# WordPress is ever loaded. Since those URLs are the entire point of this environment, a
	# missing router would make the environment agree that Lichtbild had broken them.
	say "starting web server at $WP_URL (PHP $PHP_VERSION)"
	"$PHP_BIN" -S "localhost:$WP_PORT" -t "$WP_DIR" "$ENV_DIR/router.php" >>"$WP_LOG" 2>&1 &
	echo $! > "$ENV_DIR/web.pid"

	for _ in $(seq 1 40); do
		if curl -sS -o /dev/null "$WP_URL/wp-admin/install.php" 2>/dev/null; then
			say "web server up"
			return 0
		fi
		sleep 0.5
	done

	tail -5 "$WP_LOG" >&2
	die "web server did not answer - see $WP_LOG"
}

stop_web() {
	if ! web_running; then
		say "web server not running"
		return 0
	fi

	kill "$(cat "$ENV_DIR/web.pid")" 2>/dev/null
	rm -f "$ENV_DIR/web.pid"
	say "web server stopped"
}

cmd_setup() {
	command -v /opt/homebrew/bin/wp >/dev/null || die "wp-cli missing: brew install wp-cli"
	[ -x "$PHP_BIN" ] || die "PHP $PHP_VERSION missing: brew install php@$PHP_VERSION"
	[ -x "$MARIADB_PREFIX/bin/mariadbd" ] || die "MariaDB missing: brew install mariadb@10.11"
	[ -r "$PRODUCTION_SQL" ] || die "no production dump at $PRODUCTION_SQL"

	mkdir -p "$ENV_DIR" "$SNAPSHOT_DIR"

	if [ ! -d "$DB_DATADIR" ]; then
		say "initialising database data directory"
		"$MARIADB_PREFIX/bin/mariadb-install-db" \
			--datadir="$DB_DATADIR" --auth-root-authentication-method=normal \
			>>"$DB_LOG" 2>&1 || die "mariadb-install-db failed - see $DB_LOG"
	fi

	start_db

	say "creating database $DB_NAME"
	mysql_client -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4;"

	# `mariadb-install-db` only creates root@localhost, which is a *socket* identity. WordPress
	# connects over TCP, and to the server that is a different account entirely — so without
	# this grant it is refused however correct the password is.
	mysql_client -e "CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1'; GRANT ALL PRIVILEGES ON *.* TO '$DB_USER'@'127.0.0.1' WITH GRANT OPTION; FLUSH PRIVILEGES;"

	# The dump carries its own CREATE DATABASE/USE for the production name, and importing that
	# would silently populate a differently-named database while this one stayed empty. Strip
	# both so the data lands where the rest of this script expects it.
	say "importing production dump (this is the site's real content)"
	grep -vE "^(CREATE DATABASE|USE )" "$PRODUCTION_SQL" | mysql_client "$DB_NAME" \
		|| die "import failed"

	local galleries
	galleries="$(mysql_client -N -B "$DB_NAME" -e "SELECT COUNT(*) FROM ${DB_PREFIX}posts WHERE post_type='envira';")"
	[ "$galleries" -gt 0 ] || die "import produced no galleries - refusing to continue"
	say "imported: $galleries galleries"

	if [ ! -f "$WP_DIR/wp-load.php" ]; then
		say "downloading WordPress (matching the live site's version)"
		wp_cli core download --version=7.0.3 --force --skip-content || die "core download failed"
	fi

	# `127.0.0.1`, never `localhost`. MySQL treats `localhost` as "use the unix socket" and
	# throws the port away, so `localhost:3307` quietly connects to the default socket, which
	# on this machine is nothing at all. It surfaces as "Error establishing a database
	# connection", which reads like a credentials problem and is not one.
	say "writing wp-config.php"
	wp_cli config create \
		--dbname="$DB_NAME" --dbuser="$DB_USER" --dbhost="127.0.0.1:$DB_PORT" \
		--dbprefix="$DB_PREFIX" --force --skip-check \
		--extra-php <<-'PHP' || die "config create failed"
		define( 'WP_DEBUG', true );
		define( 'WP_DEBUG_LOG', true );
		define( 'WP_DEBUG_DISPLAY', false );
		define( 'DISALLOW_FILE_MODS', true );
		// Neither of these should ever reach the network from a copy of a live site.
		define( 'WP_HTTP_BLOCK_EXTERNAL', true );
		define( 'AUTOMATIC_UPDATER_DISABLED', true );
		PHP

	# The first command that actually goes through WordPress, and therefore the first that can
	# prove the connection works. An earlier version of this script let these fail silently and
	# printed "ready" anyway — every `wp` call had died on "Error establishing a database
	# connection" and the summary line said nothing about it, which is the same defect this
	# project keeps finding in its own harnesses: an unchecked step reads exactly like a
	# successful one.
	say "pointing the site at $WP_URL"
	wp_cli option update siteurl "$WP_URL" >/dev/null || die "WordPress cannot reach the database - see wp-config.php DB_HOST"
	wp_cli option update home "$WP_URL" >/dev/null || die "failed to set home"

	# Pretty permalinks, because the entire /envira/ URL claim is meaningless under plain ones.
	wp_cli rewrite structure '/%postname%/' --hard >/dev/null || die "failed to set permalink structure"

	install_uploads_proxy
	link_plugin
	verify

	say "taking the baseline snapshot"
	cmd_snapshot

	say ""
	say "ready. 'bash tools/devenv.sh start' then open $WP_URL/wp-admin/"
}

# Positive evidence that the environment is what it claims to be, rather than an absence of
# error messages. Each of these is something a later test would silently depend on.
verify() {
	say "verifying"

	local version galleries prefix
	version="$(wp_value core version)"
	[ "$version" = "7.0.3" ] || die "WordPress reports '$version', expected 7.0.3 to match the live site"

	galleries="$(wp_value post list --post_type=envira --format=count)"
	[ "${galleries:-0}" -ge 50 ] || die "WordPress can see only ${galleries:-0} galleries; the import did not land where it is read from"

	prefix="$(wp_value eval 'global $wpdb; echo $wpdb->prefix;')"
	[ "$prefix" = "$DB_PREFIX" ] || die "table prefix is '$prefix', expected $DB_PREFIX"

	say "  WordPress $version, $galleries galleries, prefix $prefix"
}

write_router() {
	# Stands in for the .htaccess rules WordPress writes on Apache: serve a real file if one
	# exists, otherwise hand the request to index.php and let WordPress route it.
	cat > "$ENV_DIR/router.php" <<-'PHP'
	<?php
	/**
	 * Router for PHP's built-in server, standing in for WordPress's .htaccess rules.
	 *
	 * The built-in server has no rewrite engine, so a request for /envira/some-gallery/ would
	 * 404 before WordPress loaded. Those permalinks are the reason this environment exists.
	 */

	$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	$file = __DIR__ . '/wordpress' . $path;

	// An existing file is served as-is, which is what the `-f` condition does on Apache.
	if ( '' !== $path && '/' !== substr( $path, -1 ) && file_exists( $file ) && is_file( $file ) ) {
		return false;
	}

	// wp-admin and friends have their own entry points; only pretty permalinks are rewritten.
	if ( preg_match( '#^/(wp-admin|wp-includes|wp-content)/#', $path ) ) {
		return false;
	}

	$_SERVER['SCRIPT_NAME'] = '/index.php';
	require __DIR__ . '/wordpress/index.php';
	PHP

	say "wrote the permalink router"
}

install_uploads_proxy() {
	local mu="$WP_DIR/wp-content/mu-plugins"
	mkdir -p "$mu"

	# Attachment *metadata* is in the database, so sizes, srcset and the justified geometry are
	# all exact without a single image file present. Only the bytes come from the live domain,
	# and only because copying 2,243 files would prove nothing further.
	cat > "$mu/lichtbild-devenv-uploads.php" <<-'PHP'
	<?php
	/**
	 * Plugin Name: Lichtbild dev environment - serve uploads from the live site
	 *
	 * This install has a copy of the live database and none of its uploads. Rather than copy
	 * thousands of image files, attachment URLs are rewritten to the live domain, which is
	 * enough because dimensions come from the database rather than from the files.
	 *
	 * Read-only and one-directional: nothing here ever writes to the live site.
	 */

	defined( 'ABSPATH' ) || exit;

	const LICHTBILD_DEVENV_LIVE = 'https://timo-stein.com';

	/**
	 * Rewrites a local upload URL to the live site.
	 *
	 * @param string $url Local URL.
	 *
	 * @return string URL on the live domain.
	 */
	function lichtbild_devenv_live_url( $url ) {
		$local = home_url();

		if ( false === strpos( $url, '/wp-content/uploads/' ) ) {
			return $url;
		}

		return str_replace( $local, LICHTBILD_DEVENV_LIVE, $url );
	}

	add_filter( 'wp_get_attachment_url', 'lichtbild_devenv_live_url', 99 );
	add_filter( 'wp_get_attachment_image_src', function ( $image ) {
		if ( is_array( $image ) && isset( $image[0] ) ) {
			$image[0] = lichtbild_devenv_live_url( $image[0] );
		}

		return $image;
	}, 99 );
	add_filter( 'wp_calculate_image_srcset', function ( $sources ) {
		foreach ( $sources as $width => $source ) {
			$sources[ $width ]['url'] = lichtbild_devenv_live_url( $source['url'] );
		}

		return $sources;
	}, 99 );

	// A copy of a live site must not send mail to that site's real correspondents.
	add_filter( 'pre_wp_mail', '__return_false', 99 );
	PHP

	say "installed uploads proxy and mail block"
}

link_plugin() {
	local target="$WP_DIR/wp-content/plugins/lichtbild-gallery"

	# Symlinked rather than copied, so an edit in the repo is live here with no sync step and
	# no second copy to drift.
	rm -rf "$target"
	ln -s "$ROOT" "$target"
	say "linked the plugin: $target -> $ROOT"
}

# The site nobody had ever built: an empty WordPress that installs Lichtbild Gallery FROM
# wordpress.org, the way a stranger does. Everything above models the site this plugin was
# written for -- a live Envira installation being taken over -- and that site can never exercise
# the fresh-install path, because it starts with 52 galleries and the plugin symlinked in from
# the working tree.
#
# Three differences from `setup` are deliberate and each one is the point:
#
# - **The plugin is installed, not linked.** What runs here is the archive the directory serves,
#   which is a different set of bytes from the working tree: no `.po`/`.mo`, no `tools/`, no
#   tests. `channels` compares those two sets; this runs one of them.
# - **`DISALLOW_FILE_MODS` and `WP_HTTP_BLOCK_EXTERNAL` are OFF.** They are on in `setup` so a
#   copy of the live site can never phone home. Here WordPress must reach api.wordpress.org --
#   that request IS the subject.
# - **WordPress is whatever the directory currently ships**, not pinned to the live site's
#   version, because a stranger installs on today's WordPress.
cmd_fresh() {
	local from_zip=""
	[ "${1:-}" = "--from" ] && { from_zip="${2:?usage: fresh --from <zip>}"; }

	command -v /opt/homebrew/bin/wp >/dev/null || die "wp-cli missing: brew install wp-cli"
	[ -x "$PHP_BIN" ] || die "PHP $PHP_VERSION missing: brew install php@$PHP_VERSION"
	[ -x "$MARIADB_PREFIX/bin/mariadbd" ] || die "MariaDB missing: brew install mariadb@10.11"

	mkdir -p "$ENV_DIR"

	if [ ! -d "$DB_DATADIR" ]; then
		say "initialising database data directory"
		"$MARIADB_PREFIX/bin/mariadb-install-db" \
			--datadir="$DB_DATADIR" --auth-root-authentication-method=normal \
			>>"$DB_LOG" 2>&1 || die "mariadb-install-db failed - see $DB_LOG"
	fi

	start_db

	say "creating database $DB_NAME (empty - this site has no history)"
	mysql_client -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4;"
	mysql_client -e "CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1'; GRANT ALL PRIVILEGES ON *.* TO '$DB_USER'@'127.0.0.1' WITH GRANT OPTION; FLUSH PRIVILEGES;"

	# No `--skip-content` here, unlike `setup`: that flag omits the bundled themes, and a site
	# with no theme serves an EMPTY body with HTTP 200 -- every markup check then fails while
	# the same gallery renders perfectly through `do_shortcode()`, which reads as a plugin
	# defect and is a missing theme. A stranger's WordPress has the default theme.
	say "downloading WordPress (latest, because that is what a stranger installs on)"
	wp_cli core download --force || die "core download failed"

	say "writing wp-config.php"
	wp_cli config create \
		--dbname="$DB_NAME" --dbuser="$DB_USER" --dbhost="127.0.0.1:$DB_PORT" \
		--dbprefix="$DB_PREFIX" --force --skip-check \
		--extra-php <<-'PHP' || die "config create failed"
		define( 'WP_DEBUG', true );
		define( 'WP_DEBUG_LOG', true );
		define( 'WP_DEBUG_DISPLAY', false );
		// No DISALLOW_FILE_MODS and no WP_HTTP_BLOCK_EXTERNAL here, unlike the migration
		// environment: this site has to be able to reach wordpress.org and install a plugin,
		// which is the whole thing being tested.
		PHP

	say "installing WordPress"
	wp_cli core install \
		--url="$WP_URL" --title="Lichtbild fresh-install test" \
		--admin_user=admin --admin_password=admin --admin_email=nobody@example.invalid \
		--skip-email >/dev/null || die "core install failed - see wp-config.php DB_HOST"

	wp_cli rewrite structure '/%postname%/' --hard >/dev/null || die "failed to set permalink structure"

	# The subject. `--activate` on the same line so a plugin that fatals on activation fails
	# here rather than in a later check that would blame something else.
	#
	# `--from <zip>` installs a locally built archive instead, which is what makes this
	# environment useful BEFORE a release rather than only after one: a fix to the fresh-install
	# path cannot be tested against the directory, because the directory still serves the build
	# without it.
	if [ -n "$from_zip" ]; then
		[ -r "$from_zip" ] || die "no such archive: $from_zip"
		say "installing lichtbild-gallery from $from_zip (LOCAL BUILD, not the directory)"
		wp_cli plugin install "$from_zip" --activate --force || die "install from the archive failed"
	else
		say "installing lichtbild-gallery from wordpress.org"
		wp_cli plugin install lichtbild-gallery --activate || die "install from the directory failed"
	fi

	verify_fresh "$from_zip"

	say ""
	say "ready. 'bash tools/devenv.sh fresh start' then 'bash tools/devenv.sh fresh test'"
}

# Positive evidence, same as `verify` above: each line is something a later check would depend
# on silently. The version comparison is against the directory's own answer rather than against
# a number written here, so it cannot go stale.
verify_fresh() {
	say "verifying"

	local wp_version installed expected

	wp_version="$(wp_value core version)"
	installed="$(wp_value plugin get lichtbild-gallery --field=version)"
	[ -n "$installed" ] || die "the plugin is not installed"

	# The version is compared against whichever source it came FROM -- the directory's own
	# answer, or the working tree's -- so neither comparison can go stale, and installing a
	# local build never looks like a directory install that fetched the wrong version.
	if [ -n "${1:-}" ]; then
		expected="$(sed -n "s/.*define( 'LICHTBILD_VERSION', '\([^']*\)' ).*/\1/p" "$ROOT/lichtbild-gallery.php" | head -1)"
		[ "$installed" = "$expected" ] || die "installed $installed from the archive, but the working tree says $expected"
	else
		expected="$(curl -s 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=lichtbild-gallery' \
			| sed -n 's/.*"version":"\([^"]*\)".*/\1/p' | head -1)"
		[ -n "$expected" ] || die "could not read the published version from api.wordpress.org"
		[ "$installed" = "$expected" ] || die "installed $installed but the directory serves $expected"
	fi

	wp_cli plugin is-active lichtbild-gallery || die "the plugin installed but is not active"

	# A published build carries the .pot and NOT the catalogues, which is what makes the
	# language-pack path the only source of German on a directory install. Asserted here
	# because it is the difference between this site and the linked working tree.
	local plugdir="$WP_DIR/wp-content/plugins/lichtbild-gallery"
	[ -f "$plugdir/languages/lichtbild-gallery.pot" ] || die "the installed build has no .pot"
	[ -z "$(ls "$plugdir/languages/"*.mo 2>/dev/null)" ] || die "the installed build ships a .mo, which the published one does not"

	local source_name="the directory"
	[ -n "${1:-}" ] && source_name="the archive"

	say "  WordPress $wp_version, lichtbild-gallery $installed (matches $source_name), active"
}

# Runs tests/fresh-install.php against the fresh site. Separate from `cmd_fresh` so the
# environment can be rebuilt without re-running the checks and vice versa -- and so a failing
# check is a failing check, not a failed build.
cmd_fresh_test() {
	db_running || die "database not running: bash tools/devenv.sh fresh start"
	web_running || die "web server not running: bash tools/devenv.sh fresh start"

	# The HTTP half of the test fetches this site over the loopback, so the checks are only
	# meaningful while the server is up -- asserted above rather than discovered as six failed
	# checks that look like a broken plugin.
	wp_cli eval-file "$ROOT/tests/fresh-install.php"
}

cmd_snapshot() {
	db_running || start_db
	mkdir -p "$SNAPSHOT_DIR"

	"$MARIADB_PREFIX/bin/mariadb-dump" --socket="$DB_SOCKET" -u "$DB_USER" \
		--single-transaction --quick --no-tablespaces --default-character-set=utf8mb4 \
		"$DB_NAME" > "$BASELINE_SQL" 2>/dev/null || die "snapshot failed"

	grep -qi "Dump completed" "$BASELINE_SQL" || die "snapshot has no completion marker - truncated"
	say "snapshot: $(du -h "$BASELINE_SQL" | cut -f1) at $BASELINE_SQL"
}

cmd_reset() {
	[ -r "$BASELINE_SQL" ] || die "no snapshot at $BASELINE_SQL - run 'snapshot' first"
	db_running || start_db

	say "restoring the database to its snapshot"
	mysql_client -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4;"
	mysql_client "$DB_NAME" < "$BASELINE_SQL" || die "restore failed"

	# The point of a reset is that the migration can be run again, so verify the thing the
	# migration consumes actually came back rather than trusting the exit code.
	local galleries
	galleries="$(mysql_client -N -B "$DB_NAME" -e "SELECT COUNT(*) FROM ${DB_PREFIX}posts WHERE post_type='envira';")"
	say "restored: $galleries galleries under post_type=envira"
	[ "$galleries" -gt 0 ] || die "restore produced no galleries"
}

cmd_status() {
	printf '%-22s %s\n' "environment:" "$ENV_DIR"
	printf '%-22s %s\n' "database:" "$(db_running && echo "running on $DB_PORT" || echo 'stopped')"
	printf '%-22s %s\n' "web:" "$(web_running && echo "running at $WP_URL" || echo 'stopped')"
	printf '%-22s %s\n' "php:" "$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo missing)"

	db_running || return 0

	printf '%-22s %s\n' "wordpress:" "$(wp_cli core version 2>/dev/null || echo '?')"
	echo
	printf '%-22s %s\n' "envira galleries:" "$(mysql_client -N -B "$DB_NAME" -e "SELECT COUNT(*) FROM ${DB_PREFIX}posts WHERE post_type='envira';" 2>/dev/null)"
	printf '%-22s %s\n' "lichtbild galleries:" "$(mysql_client -N -B "$DB_NAME" -e "SELECT COUNT(*) FROM ${DB_PREFIX}posts WHERE post_type='lichtbild_gallery';" 2>/dev/null)"
	printf '%-22s %s\n' "schema option:" "$(mysql_client -N -B "$DB_NAME" -e "SELECT option_value FROM ${DB_PREFIX}options WHERE option_name='lichtbild_schema_version';" 2>/dev/null || echo '(unset)')"
	echo
	printf '%-22s %s\n' "active plugins:" ""
	wp_cli plugin list --status=active --field=name 2>/dev/null | sed 's/^/  /'
}

# `fresh` was consumed at the top of the file, so the subcommand is $1 either way and every
# command below acts on whichever environment that selected. `fresh` alone means `fresh setup`,
# and so does `fresh --from <zip>` -- without that second case an option lands in the subcommand
# position, matches nothing, and the script prints its usage instead of building anything.
if [ "$FRESH" = 1 ] && { [ -z "${1:-}" ] || [ "${1#--}" != "${1:-}" ]; }; then
	set -- setup "$@"
fi

case "${1:-}" in
	setup)    shift || true
	          if [ "$FRESH" = 1 ]; then cmd_fresh "$@"; else cmd_setup; fi ;;
	test)     [ "$FRESH" = 1 ] || die "'test' is a fresh-environment command: tools/devenv.sh fresh test"
	          cmd_fresh_test ;;
	start)    start_db; start_web ;;
	stop)     stop_web; stop_db ;;
	reset)    cmd_reset ;;
	snapshot) cmd_snapshot ;;
	status)   cmd_status ;;
	wp)       shift; wp_cli "$@" ;;
	*)        sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
