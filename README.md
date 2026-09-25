# Lichtbild Gallery

A WordPress gallery plugin that reads Envira Gallery's data in place, and can take it over.

It was written to replace Envira Gallery Pro on one site without a licence, without re-uploading
anything, and without moving a single indexed URL. It does that, and it has been running in
production since August 2026.

> **Not affiliated with, endorsed by, or connected to Envira Gallery or Awesome Motive.**
> "Envira Gallery" is their product and their trademark. Lichtbild is an independent plugin that
> names Envira only to say what it reads and what it replaces. It contains no Envira code — the
> two share no source, which was checked rather than assumed: every Lichtbild source file was
> compared against all 481 Envira PHP, JavaScript and CSS files, and the two have not one pair of
> lines in common.

## What it does

**It reads Envira's own records where they lie.** Galleries stay in the `envira` post type under
the `_eg_gallery_data` post meta, and Lichtbild renders from that. Nothing is copied, nothing is
written back, and no gallery is edited to make the switch — so deactivating Lichtbild and
reactivating Envira is lossless in both directions, and comparing the two is a page refresh.

**It can then take ownership.** A migration renames the post types in place and converts each
record into a normalised format of its own. Post IDs never change, so every existing
`[envira-gallery id="N"]` still names the same row, and every permalink stays where it was.
Envira's original records are left untouched, which is what makes the migration reversible: a
rollback restores what was there rather than reconstructing it.

**It keeps the URLs.** This is the part that actually required work. Galleries, albums and image
tags are custom types, and a custom type exists only while a plugin registers it — so removing
Envira without replacing its registrations sends every gallery URL to a 404. Lichtbild registers
them, and the type names change at migration while `/envira/`, `/envira_album/` and `/envira-tag/`
stay pinned exactly where search engines already have them. The paths are filterable through
`lichtbild_url_slugs`; Envira's are the right default only for a site that already has them indexed.

## What you get over Envira

- **The justified grid is pure CSS.** Every item is sized and grown in proportion to its own
  aspect ratio, so a flex row settles at one shared height with no measuring and no layout pass
  after the images load. Envira needed Isotope, which cannot lay out until the images arrive.
- **Grid images are WordPress derivatives with a `srcset`**, not the full-size original — 72 KB
  against 239 KB on a sample thumbnail.
- **PhotoSwipe 5 is imported on first click**, so a page of galleries costs no JavaScript until
  someone opens an image, and assets are enqueued only once a gallery has actually rendered.
- Intrinsic `width`/`height` on every image, so nothing shifts as the page loads.
- Server-side tag filtering that spans the whole gallery rather than the rendered page.

On the site it was built for, gallery permalinks went from **395 KB to 60 KB**.

## Requirements

WordPress 6.0+, PHP 8.1+. No build step, no npm dependency at runtime, no bundler. PhotoSwipe
5.4.4 is vendored under `assets/vendor/photoswipe/` with its MIT licence.

## Installing

Copy the plugin directory into `wp-content/plugins/` and activate it. With Envira still active it
does nothing by default — the takeover setting under **Settings → Lichtbild** defaults to `auto`,
which means "handle `[envira-gallery]` only while Envira is inactive". That is deliberate: it lets
you install it on a live site and change nothing until you choose to.

| mode | behaviour |
|---|---|
| `auto` | handle `[envira-gallery]` only while Envira is inactive (default) |
| `always` | handle it even when Envira is active, for an A/B comparison on one page |
| `never` | only `[lichtbild-gallery]` renders through Lichtbild |

`[lichtbild-gallery id="N"]` always renders through Lichtbild regardless of the setting, and there are
block-editor blocks for galleries and albums.

## Migrating

Deactivate Envira, then run the migration from **Settings → Lichtbild**. It reports what it will do
before it does it, and the counts on that screen come from the same code that performs the work.
Afterwards Lichtbild owns the data and the edit screens, and Envira can be uninstalled without
taking any URL off the site.

Rollback is on the same screen and stays available: Envira's records were never touched.

## Tests

There is no WordPress in the loop for the main suite — `tests/wp-stubs.php` implements the WordPress
functions the plugin calls, and the suite renders every gallery in a fixture and asserts the markup.

```sh
php tests/make-fixture.php          # generate a synthetic corpus, no database needed
php tests/render-test.php tests/fixture-synthetic.json
php tests/mutations.php --fixture=tests/fixture-synthetic.json
php tests/i18n-test.php
```

Each check is reported with the population it examined. A check that
examined nothing is reported `[EMPTY]` and counts as failing, because a conditional check that
stops running would otherwise vanish from the report and read as "not applicable".

CI runs the suite on five PHP versions, 8.1 through 8.5. The mutations run once, on 8.2 — the
version the live site runs — because they ask whether the tests can fail, which is a question
about the suite rather than about portability.

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
