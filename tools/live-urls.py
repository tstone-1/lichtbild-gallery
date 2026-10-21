#!/usr/bin/env python3
"""Prints every URL the plugin owns on the live site, one per line.

    uv run --with pymysql python tools/live-urls.py > urls.txt

The list comes from the database rather than from memory, and that is the entire point. The
first live deploy captured ten probe URLs chosen by hand, not one of which was an album -- and
the album page was the thing that broke, which stayed broken in production until a local
comparison found it. Enumerating from the schema means the surface cannot quietly shrink to
whatever came to mind.

What it covers, and why each is here:

- every published gallery permalink        -- the canonical, indexed URLs
- every published album permalink          -- the space that was forgotten once
- every image-tag archive                  -- the third registered URL space
- the protected gallery                    -- a control: it must keep answering with its form
- every post embedding a gallery shortcode -- the shortcode path, which the permalinks miss

Non-public galleries are deliberately excluded: they 404 for an anonymous fetch, so including
them would put four guaranteed non-200s in every capture and train the reader to skim past the
count that matters.
"""

import json
import pathlib
import re
import ssl
import sys

import pymysql

ROOT = pathlib.Path(__file__).resolve().parent.parent
CONFIG = ROOT / "tests" / ".db.json"

if not CONFIG.exists():
    sys.exit(f"[ERROR] {CONFIG} not found; it holds the live database credentials and is gitignored")

cfg = json.loads(CONFIG.read_text())
prefix = cfg["PREFIX"]

# Verified TLS is required, not merely preferred. With no TLS argument pymysql attempts TLS,
# accepts any certificate, and falls back to plaintext if the server offers none, and this
# connection crosses the internet to the production database. An SSLContext makes pymysql
# refuse a server without TLS and check the certificate and hostname. A host whose MySQL
# certificate is self-signed names that certificate (or its CA) as "ca" in tests/.db.json.
cursor = pymysql.connect(
    host=cfg["host"],
    ssl=ssl.create_default_context(cafile=cfg.get("ca")),
    user=cfg["DB_USER"],
    password=cfg["DB_PASSWORD"],
    database=cfg["DB_NAME"],
    charset=cfg.get("DB_CHARSET", "utf8mb4"),
).cursor()

cursor.execute(f"SELECT option_value FROM {prefix}options WHERE option_name = 'siteurl'")
site = cursor.fetchone()[0].rstrip("/")

urls: list[str] = []

# WHICH post types the rows are under depends on the migration; WHICH paths they answer on does
# not. Lichtbild pins `rewrite['slug']` to Envira's names in both directions precisely so the live,
# indexed URLs never move -- so the type names have to be read from the schema option while the
# path segments stay constant. Building the path out of the post type is the obvious shortcut and
# it is wrong: after the migration it produces /lichtbild_gallery/<slug>/, which 404s.
cursor.execute(f"SELECT option_value FROM {prefix}options WHERE option_name = 'lichtbild_schema_version'")
row = cursor.fetchone()
migrated = bool(row) and int(row[0]) >= 2

GALLERY_TYPE = "lichtbild_gallery" if migrated else "envira"
ALBUM_TYPE = "lichtbild_album" if migrated else "envira_album"
TAG_TAXONOMY = "lichtbild_tag" if migrated else "envira-tag"

# The path segments, which never change. Kept beside the type names so the pairing is visible.
PATHS = {GALLERY_TYPE: "envira", ALBUM_TYPE: "envira_album"}
TAG_PATH = "envira-tag"

print(f"# schema: {'migrated' if migrated else 'v1'}; "
      f"types {GALLERY_TYPE}/{ALBUM_TYPE}/{TAG_TAXONOMY}", file=sys.stderr)

# Galleries and albums, published only.
cursor.execute(
    f"""SELECT post_type, post_name FROM {prefix}posts
        WHERE post_type IN (%s, %s) AND post_status = 'publish' AND post_name <> ''
        ORDER BY post_type, post_name""",
    (GALLERY_TYPE, ALBUM_TYPE),
)
permalinks = cursor.fetchall()
for post_type, slug in permalinks:
    urls.append(f"{site}/{PATHS[post_type]}/{slug}/")

# Tag archives.
cursor.execute(
    f"""SELECT t.slug FROM {prefix}terms t
        JOIN {prefix}term_taxonomy tt ON tt.term_id = t.term_id
        WHERE tt.taxonomy = %s ORDER BY t.slug""",
    (TAG_TAXONOMY,),
)
tags = cursor.fetchall()
for (slug,) in tags:
    urls.append(f"{site}/{TAG_PATH}/{slug}/")

# An empty result here is not an empty site, it is a query asking for the wrong names -- and it
# fails in the direction that looks like success: the capture still runs, still reports 0
# non-200, still compares clean, and covers none of the URLs this plugin exists to protect.
# That is exactly what happened the first time this ran after the migration.
if not permalinks or not tags:
    sys.exit(
        f"[ERROR] enumerated {len(permalinks)} permalinks and {len(tags)} tag archives under "
        f"{GALLERY_TYPE}/{ALBUM_TYPE}/{TAG_TAXONOMY}. A live site has both; this means the type "
        f"names are wrong for the schema state, not that the site is empty."
    )

# Posts and pages that embed a gallery. A permalink capture cannot see these, and they are the
# only URLs that exercise the shortcode path at all.
cursor.execute(
    f"""SELECT post_name, post_content FROM {prefix}posts
        WHERE post_status = 'publish' AND post_type IN ('post', 'page')
          AND (post_content LIKE '%%[envira-gallery%%' OR post_content LIKE '%%[lichtbild-gallery%%'
               OR post_content LIKE '%%[envira-album%%' OR post_content LIKE '%%[lichtbild-album%%')
        ORDER BY post_name"""
)
embed = 0
for slug, content in cursor.fetchall():
    if slug and re.search(r"\[(envira|lichtbild)-(gallery|album)", content or ""):
        urls.append(f"{site}/{slug}/")
        embed += 1

# The front page, which none of the queries above can reach and which every deploy from 26.8.1
# to 26.8.14 therefore verified nothing about. On timo-stein.com it is the blog index: it renders
# the latest posts' content in full, so it draws TEN galleries and 132 figures -- more gallery
# markup than any single permalink on the site, on its most-visited URL.
#
# It is invisible to all three queries by construction. It is not a gallery or album permalink,
# not a tag archive, and not a post whose own `post_content` holds a shortcode -- the shortcodes
# are in the posts it aggregates, each of which IS enumerated, which is exactly why the gap
# survived: the content was covered, so nothing looked wrong.
#
# Found by checking after a deploy whether a page with no gallery loads no assets, picking the
# home page as the example, and getting the opposite answer. Same lesson as 26.8.2, where ten
# probe URLs contained no album and the album regression reached production: enumerate the URL
# *spaces* a plugin can appear in, not a sample of the ones you thought of.
#
# Deliberately last so the count reads as "the enumerated surface plus the front page".
urls.append(f"{site}/")

seen = set()
unique = [u for u in urls if not (u in seen or seen.add(u))]

print("\n".join(unique))
print(f"[urls] {len(unique)} total, {embed} of them embedding posts", file=sys.stderr)
