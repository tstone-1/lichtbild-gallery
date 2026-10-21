#!/usr/bin/env python3
"""Export a render fixture from a live WordPress database.

    uv run --with pymysql --with phpserialize python tests/export-fixture.py

Reads connection details from tests/.db.json (gitignored), which looks like:

    {"host": "…", "DB_NAME": "…", "DB_USER": "…", "DB_PASSWORD": "…", "PREFIX": "wp_"}

Those values live in the site's wp-config.php, with one exception that will otherwise waste
an afternoon: "host" is NOT wp-config.php's DB_HOST. That says "localhost", which is true
from the web server and useless from here. The database is reachable from outside on the
hosting account's own name, so "host" has to be set to that; DB_HOST is ignored if present. The output, tests/fixture.json, holds the
site's own gallery content and is gitignored for that reason — regenerate it rather than
sharing it.

Serialized blobs are carried as base64 so PHP's unserialize() sees the exact bytes MySQL
stored. Decoding them to text and re-encoding would corrupt any string whose byte length
prefix disagrees with its character count, which is the usual outcome of a utf8mb4
conversion somewhere in the site's history.
"""
import base64
import json
import pathlib
import ssl
import sys

import phpserialize
import pymysql

HERE = pathlib.Path(__file__).parent
CONF = HERE / ".db.json"
OUT = HERE / "fixture.json"

if not CONF.is_file():
    sys.exit(f"[ERROR] missing {CONF} - see the docstring for its shape")

cfg = json.loads(CONF.read_text())

if "host" not in cfg:
    sys.exit(
        f"[ERROR] {CONF} has no 'host' - see the docstring. It is the hosting account's own "
        "name, not wp-config.php's DB_HOST, which says localhost and cannot be reached."
    )

# Verified TLS is required, not merely preferred. With no TLS argument pymysql attempts TLS,
# accepts any certificate, and falls back to plaintext if the server offers none, and this
# connection crosses the internet to the production database. An SSLContext makes pymysql
# refuse a server without TLS and check the certificate and hostname. A host whose MySQL
# certificate is self-signed names that certificate (or its CA) as "ca" in tests/.db.json.
con = pymysql.connect(
    host=cfg["host"],
    ssl=ssl.create_default_context(cafile=cfg.get("ca")),
    user=cfg["DB_USER"],
    password=cfg["DB_PASSWORD"],
    database=cfg["DB_NAME"],
    charset="utf8mb4",
    connect_timeout=20,
)
p = cfg.get("PREFIX", "wp_")


def b64(value):
    """Return a base64 string of the raw bytes of a database value."""
    raw = value if isinstance(value, bytes) else value.encode("utf8", "surrogateescape")
    return base64.b64encode(raw).decode()


c = con.cursor()

# `post_password` is carried as a BOOLEAN and never as its value. What the suite needs is the
# distinction between a protected gallery and a public one; the password itself is a secret and
# has no business in a file on a laptop. Exporting only the status left the corpus unable to
# represent a protected gallery at all, so every check about them had to be hand-built — which
# is how the album cover grid published one for a while with 128 checks passing.
c.execute(
    f"""SELECT p.ID, p.post_title, p.post_status, p.post_name, p.post_password, m.meta_value
        FROM {p}posts p JOIN {p}postmeta m ON m.post_id = p.ID
        WHERE p.post_type = 'envira' AND m.meta_key = '_eg_gallery_data'
        ORDER BY p.ID"""
)

galleries = []
attachment_ids = set()

for gid, title, status, name, password, blob in c.fetchall():
    galleries.append(
        {
            "id": gid,
            "title": title,
            "status": status,
            "name": name,
            "protected": bool(password),
            "meta": b64(blob),
        }
    )
    try:
        data = phpserialize.loads(
            blob.encode("utf8", "surrogateescape"), decode_strings=True
        )
    except Exception as error:  # noqa: BLE001 - a bad blob is data to report, not to raise on
        print(f"[WARN] gallery {gid} did not unserialize: {error}")
        continue
    for key in data.get("gallery") or {}:
        if str(key).isdigit():
            attachment_ids.add(int(key))

c.execute(
    f"""SELECT p.ID, p.post_title, p.post_status, p.post_password, m.meta_value
        FROM {p}posts p JOIN {p}postmeta m ON m.post_id = p.ID
        WHERE p.post_type = 'envira_album' AND m.meta_key = '_eg_album_data'
        ORDER BY p.ID"""
)
albums = [
    {
        "id": aid,
        "title": title,
        "status": status,
        "protected": bool(password),
        "meta": b64(blob),
    }
    for aid, title, status, password, blob in c.fetchall()
]

ids = sorted(attachment_ids)
attachments = {}

for start in range(0, len(ids), 500):
    chunk = ids[start : start + 500]
    holes = ",".join(["%s"] * len(chunk))

    c.execute(f"SELECT ID, post_title, post_excerpt FROM {p}posts WHERE ID IN ({holes})", chunk)
    for aid, title, excerpt in c.fetchall():
        entry = attachments.setdefault(aid, {})
        entry["title"] = title
        entry["excerpt"] = excerpt

    c.execute(
        f"""SELECT post_id, meta_key, meta_value FROM {p}postmeta
            WHERE post_id IN ({holes})
            AND meta_key IN ('_wp_attachment_metadata', '_wp_attachment_image_alt')""",
        chunk,
    )
    for pid, key, value in c.fetchall():
        entry = attachments.setdefault(pid, {})
        if key == "_wp_attachment_metadata":
            entry["meta"] = b64(value)
        else:
            entry["alt"] = value

    c.execute(
        f"""SELECT r.object_id, t.slug, t.name FROM {p}term_relationships r
            JOIN {p}term_taxonomy tt ON tt.term_taxonomy_id = r.term_taxonomy_id
            JOIN {p}terms t ON t.term_id = tt.term_id
            WHERE tt.taxonomy = 'envira-tag' AND r.object_id IN ({holes})""",
        chunk,
    )
    for oid, slug, name in c.fetchall():
        attachments.setdefault(oid, {}).setdefault("tags", []).append(
            {"slug": slug, "name": name}
        )

c.execute(f"SELECT option_value FROM {p}options WHERE option_name = 'siteurl'")
siteurl = c.fetchone()[0]
con.close()

OUT.write_text(
    json.dumps(
        {
            "siteurl": siteurl,
            "galleries": galleries,
            "albums": albums,
            "attachments": attachments,
        }
    )
)

missing = [i for i in ids if i not in attachments]
print(f"galleries={len(galleries)} albums={len(albums)} attachments={len(attachments)}")
print(f"referenced attachment ids={len(ids)}, with no row at all={len(missing)}")
print(f"wrote {OUT} ({OUT.stat().st_size} bytes)")
