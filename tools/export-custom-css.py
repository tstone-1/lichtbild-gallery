#!/usr/bin/env python3
"""Prints the per-gallery custom CSS a site still has, ready to paste into the Customizer.

    uv run --with pymysql --with phpserialize python tools/export-custom-css.py > custom.css

Lichtbild stored a free-text CSS block per gallery through 26.8.21 and printed it in an inline
`<style>` element. 26.8.22 removed that: the wordpress.org guidelines do not permit a plugin to
store and emit arbitrary CSS entered through its own UI. This is how the CSS comes back out --
rewritten onto the ids it will need, one commented block per gallery, for
**Appearance -> Customize -> Additional CSS**.

It is lossless because the element ids do not move: a gallery is `#lichtbild-<id>` and its wrapper
`#lichtbild-<id>-wrap` whether the rule arrives inline from a plugin or from the Customizer. Only
the delivery changes.

**THERE ARE TWO PLACES THE CSS CAN BE, and reading only the obvious one silently returns a stale
value.** A migrated site keeps Envira's `_eg_gallery_data` untouched -- which is what makes the
migration reversible -- but the record Lichtbild actually *renders* from is `_lichtbild_gallery`, and
Lichtbild's own editor wrote CSS into that one. So a gallery whose CSS was edited through Lichtbild
after the migration has the current value in the v2 record and a pre-migration value in Envira's.
Reading Envira's alone would hand back the older text while reporting success.

**Run this before saving any gallery under 26.8.22.** A save rewrites the v2 record through the
new allowlist, which has no `custom_css` -- so the value in the v2 record is dropped at that
point. Envira's copy survives regardless; the v2 copy does not.

Exit status is 1 when any gallery's two copies disagree, so a caller cannot treat a conflicted
export as a clean one.
"""

import json
import pathlib
import re
import ssl
import sys

import phpserialize
import pymysql

ROOT = pathlib.Path(__file__).resolve().parent.parent
CONFIG = ROOT / "tests" / ".db.json"


def rewrite(css: str) -> str:
    """Rewrites Envira's element ids onto Lichtbild's.

    This is `Lichtbild_Config::rewrite_css()`, which was deleted with the feature, and the order of
    the two substitutions is the whole of it: `#envira-gallery-wrap-` also starts with
    `#envira-gallery-`, so applying the general rule first consumes the wrapper form and produces
    `#lichtbild-wrap-12`, which matches nothing -- the renderer emits `id="lichtbild-12-wrap"`. That
    exact bug shipped for months once; it is not hypothetical.

    Applied ONLY to Envira's copy. The v2 record was written either by the conversion, which had
    already applied this, or by hand in Lichtbild's editor against Lichtbild's own ids. Rewriting it
    again is a no-op on correct input and corrupts nothing, but it would hide the case where the
    two copies genuinely differ, which is the thing this script exists to surface.
    """
    css = re.sub(r"#envira-gallery-wrap-(\d+)", r"#lichtbild-\1-wrap", css)
    return css.replace("#envira-gallery-", "#lichtbild-")


def comment_safe(text: str) -> str:
    """Makes a string safe to place inside a CSS comment.

    A gallery title containing `*/` would otherwise end the comment early and push the rest of
    the title out as declarations, breaking the stylesheet at the point a human is least likely
    to look -- the label, not the rules.
    """
    return str(text).replace("*/", "*\\/")


def unpack(meta) -> dict:
    """Unserialises one PHP-serialised meta value, or returns {} if it cannot be read."""
    try:
        value = phpserialize.loads(
            meta.encode("utf-8") if isinstance(meta, str) else meta,
            decode_strings=True,
        )
    except (ValueError, TypeError):
        return {}
    return value if isinstance(value, dict) else {}


def choose(envira: str, lichtbild: str, migrated: bool):
    """Decides which of the two stored copies is the one the site is rendering.

    Returns `(css, source, conflict)`; `css` is the empty string when the gallery has none.

    The rule is `Lichtbild_Repository`'s, not an invention: on a migrated site the v2 record wins
    whenever it carries settings, and Envira's copy is the fallback. Getting this backwards
    returns the pre-migration text for any gallery edited through Lichtbild since -- which looks
    exactly like a correct export, because the older CSS is perfectly valid CSS.

    `conflict` is true only when both copies exist and differ. It is not an error in the data:
    the migration copied Envira's value across, and editing it in Lichtbild afterwards was always
    going to leave the two disagreeing. It is a case a human has to resolve, so it is surfaced
    rather than decided.
    """
    conflict = bool(envira) and bool(lichtbild) and envira != lichtbild

    if migrated and lichtbild:
        return lichtbild, "Lichtbild's own record (what the site renders today)", conflict
    if envira:
        return envira, "Envira's record", conflict
    if lichtbild:
        return lichtbild, "Lichtbild's own record", conflict
    return "", "", False


if not CONFIG.exists():
    sys.exit(f"[ERROR] {CONFIG} not found; it holds the live database credentials and is gitignored")

cfg = json.loads(CONFIG.read_text())
prefix = cfg["PREFIX"]

# Verified TLS is required, not merely preferred. With no TLS argument pymysql attempts TLS,
# accepts any certificate, and falls back to plaintext if the server offers none, and this
# connection crosses the internet to the production database. An SSLContext makes pymysql
# refuse a server without TLS and check the certificate and hostname. A host whose MySQL
# certificate is self-signed names that certificate (or its CA) as "ca" in tests/.db.json.
connection = pymysql.connect(
    host=cfg["host"],
    ssl=ssl.create_default_context(cafile=cfg.get("ca")),
    user=cfg["DB_USER"],
    password=cfg["DB_PASSWORD"],
    database=cfg["DB_NAME"],
    charset=cfg.get("DB_CHARSET", "utf8mb4"),
)
cursor = connection.cursor()

cursor.execute(
    f"SELECT option_value FROM {prefix}options WHERE option_name = 'lichtbild_schema_version'"
)
row = cursor.fetchone()
migrated = bool(row) and int(row[0]) >= 2

# Both records, in one pass, keyed by post. Selecting on the meta keys rather than on a post type
# is deliberate: the post type is `envira` before the migration and `lichtbild_gallery` after, and
# the whole point of this script is to work in both states.
cursor.execute(
    f"""SELECT m.post_id, p.post_title, p.post_name, m.meta_key, m.meta_value
        FROM {prefix}postmeta m
        JOIN {prefix}posts p ON p.ID = m.post_id
        WHERE m.meta_key IN ('_eg_gallery_data', '_lichtbild_gallery')
        ORDER BY m.post_id"""
)
rows = cursor.fetchall()

if not rows:
    sys.exit(
        "[ERROR] no gallery records of either kind. That is not a site without custom CSS, it is "
        "a query that matched nothing -- check the table prefix in tests/.db.json."
    )

galleries: dict[int, dict] = {}

for post_id, title, slug, meta_key, meta_value in rows:
    entry = galleries.setdefault(
        int(post_id), {"title": title or slug or post_id, "envira": "", "lichtbild": ""}
    )
    record = unpack(meta_value)

    if meta_key == "_eg_gallery_data":
        config = record.get("config") or {}
        entry["envira"] = rewrite(str(config.get("custom_css") or "").strip())
    else:
        settings = record.get("settings") or {}
        entry["lichtbild"] = str(settings.get("custom_css") or "").strip()

blocks: list[tuple[int, str, str, str]] = []
conflicts: list[int] = []

for post_id, entry in sorted(galleries.items()):
    envira, lichtbild = entry["envira"], entry["lichtbild"]
    chosen, source, conflict = choose(envira, lichtbild, migrated)

    if not chosen:
        continue

    if conflict:
        conflicts.append(post_id)
        source += " -- DIFFERS from the other copy, printed below"

    blocks.append((post_id, str(entry["title"]), chosen, source))

    if conflict:
        other = envira if chosen == lichtbild else lichtbild
        blocks.append(
            (post_id, str(entry["title"]), other, "the other copy, for comparison -- DELETE ONE")
        )

# Say what was examined, not only what was found: "no gallery has custom CSS" and "the field was
# never read" produce identical output, and only one of them is good news.
print(
    f"[INFO] {len(galleries)} galleries examined ({'migrated' if migrated else 'v1'} site), "
    f"{len({b[0] for b in blocks})} carry custom CSS",
    file=sys.stderr,
)

if conflicts:
    print(
        f"[WARN] {len(conflicts)} gallery/galleries have DIFFERENT CSS in the two records: "
        f"{conflicts}. Both are printed; keep the one you want and delete the other.",
        file=sys.stderr,
    )

if not blocks:
    print("[INFO] nothing to move; this site has no per-gallery custom CSS", file=sys.stderr)
    sys.exit(0)

print("/* Per-gallery CSS, moved out of the Lichtbild plugin in 26.8.22.")
print(" *")
print(" * Paste into Appearance -> Customize -> Additional CSS.")
print(" * A gallery is #lichtbild-<id>; its wrapper is #lichtbild-<id>-wrap.")
print(" */")

for post_id, title, css, source in blocks:
    print()
    print(f"/* #{post_id} -- {comment_safe(title)} ({comment_safe(source)}) */")
    print(css)

print(f"[OK] wrote {len(blocks)} block(s)", file=sys.stderr)
sys.exit(1 if conflicts else 0)
