# Lichtbild Gallery

A WordPress gallery plugin that replaces Envira Gallery Pro on `timo-stein.com`, so the
site stops depending on a ~100 EUR/year licence it no longer pays for and is running an old
version of.

**Public and GPL-2.0-or-later**, with `LICENSE`, `README.md` and a wordpress.org-format
`readme.txt`. `github.com/tstone-1/lichtbild-gallery` has been public since 2026-08-09 and the
plugin was submitted to wordpress.org the same day; it was **approved on 2026-08-27** and is not
yet listed, because the directory page exists only once the code is committed to SVN — the dated
history is under *Submitting to wordpress.org* below.

> Until 2026-08-22 this paragraph said the repository was **still private and nothing had been
> submitted**, five days after both stopped being true, while a later section in this same file
> recorded the submission and two review rounds. That is not harmless stale prose: the
> confidentiality and account rules branch on visibility, and this is the first thing anyone
> reads. A status line carries a date because it expires — when this one and GitHub disagree,
> `gh repo view tstone-1/lichtbild-gallery --json visibility` is the answer and this is the cache.

The two rules below are what being public makes non-negotiable rather than tidy:

- **Nothing that identifies the deployment target goes in a tracked file** — see *Site access*.
  The hostname, the FTP account and the table prefix live in gitignored files, and the reason is
  that *together* they are reconnaissance even though no single one is a password.
- **Envira is named only nominatively**, to say what is read and what is replaced, and the plugin
  header, `README.md` and `LICENSE` all state that this is not affiliated with or endorsed by
  Envira Gallery or Awesome Motive. It contains no Envira code, which was established by
  comparison rather than by assertion: every source file here against all 481 Envira PHP, JS and
  CSS files, and not one pair of lines in common.

> **This plugin has been called three things: Tivira through 26.8.15, Atelier from 26.8.16 to
> 26.8.23, and Lichtbild Gallery from 26.8.24.** Tivira went because it was one letter from
> Envira's in the same product category; Atelier went because wordpress.org pended the
> submission over it, three published plugins in that directory already leading with the word.
> This paragraph is the only place the former names appear as themselves. Everywhere else —
> this file, the changelog, the deploy records — names everything by its *current* identifiers,
> including in entries describing releases that shipped under a former name, because the
> alternative is documentation that cannot be grepped against the code it documents. Read a
> class or file name in an old entry as "the thing now called that". Dates, counts and measured
> numbers are untouched. Both renames are recorded in `CHANGELOG.md`.

> **This file is the index; `docs/lessons.md` is the corpus.** It is read in full before
> every task and it only grows, so an entry that is worth keeping and is only worth reading
> once you are already in its area lives in [`docs/lessons.md`](docs/lessons.md) — verbatim,
> in its original order — and leaves one line here. The per-release deploy records are in
> [`docs/deploys.md`](docs/deploys.md). Knowing that a trap *exists* is most of its value;
> a one-line hook is never enough to avoid it, so read the entry before working in its area.
>
> `tests/docs-index-test.php` is the guard, and it runs in CI: this file has a ceiling, every
> corpus file it links to must be present and non-empty, and every entry it names must still
> exist as a heading in exactly one of them. The second of those is the one that catches a
> mistake made in a hurry — the index is tracked and the corpus was not, so committing this
> file alone would leave a fresh clone with an index pointing at nothing.

## Two generations, and both are live

**v1 is a drop-in reader.** It renders Envira's own rows in place, changing nothing. That is
still how an un-migrated site behaves, and it is what makes the switch reversible.

**v2 owns the data.** `Lichtbild_Config` defines twenty-six normalised settings and converts
Envira's ~281 keys into them; `Lichtbild_Migration` renames the post types in place and writes
the converted record alongside the original. After that the reader contains no Envira
knowledge at all — which is the difference between a migration and a rename.

`Lichtbild_Repository` reads either shape: on a migrated site a `_lichtbild_gallery` record wins,
otherwise `_eg_gallery_data` is converted on the fly. So the two generations coexist, and the
migration is not a cliff. **The "on a migrated site" is load-bearing** — see *What the second
review changed, on the write path* in [`docs/lessons.md`](docs/lessons.md) for what it cost
to leave it out.

### What independence actually required

Not the editor — the **registrations**. Galleries, albums and image tags are custom types,
and a custom type exists only while a plugin registers it. Envira owns three live URL spaces:

| URL | what |
|---|---|
| `/envira/<slug>/` | gallery permalinks — canonical, Yoast-indexed, HTTP 200 |
| `/envira_album/<slug>/` | album permalinks |
| `/envira-tag/<slug>/` | tag archives |

Delete Envira without replacing those registrations and all of it 404s. `Lichtbild_Post_Types`
takes them over, and **the type names change at migration while the URLs never do** —
`rewrite['slug']` stays pinned either way.

**Since 26.8.17 those paths are Envira's only on a site that has an Envira history.** A site with
none serves `/gallery/`, `/album/` and `/gallery-tag/`, and since 26.8.18 it also starts already
on Lichtbild's own post types rather than registering names it has no reason to carry. Both answers
are decided from one observation and recorded once, never re-derived — a site's Envira history
changes when its old records are deleted, and its published URLs must not change with it.
`lichtbild_url_slugs` filters the paths on top of that.

### Migration invariants

- **Post IDs never change.** It is `UPDATE ... SET post_type`, not a copy, so every
  `[envira-gallery id="N"]` still names the same row and no permalink moves. A
  create-and-import design would need an ID map forever.
- **Nothing is destroyed.** `_eg_gallery_data` is untouched; the converted record is written
  under a new key. Rollback restores the post types and forgets the new key — no data is
  reconstructed because none was lost.
- **`plan()` is separable from `migrate()`**, so the confirmation screen counts come from the
  code that does the work.
- **The rewrite flush is not optional.** Rules are generated from the types registered at
  flush time, so skipping it 404s every gallery until someone re-saves permalinks — which
  looks exactly like the migration having broken the site.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *The screen that runs it* — why it is not part of the settings form, why its
  post/redirect/get is load-bearing — the request that renames the types registered the old
  ones — and why every guard is in the handler rather than the markup.
- *Recovering from a migration that dies half-way* — there is no transaction. Rollback is gated
  on the rows and never on the flag, because the flag is exactly what a half-finished run gets
  wrong; and `(int)` on `$wpdb->update()` turns a failed statement into "nothing needed doing".
- *Registration must not defer to Envira once the data has moved* — `register_types()` standing
  aside while Envira is active is right up to the migration and wrong after it — nothing else
  registers `lichtbild_gallery`. The guard is `! $migrated && ...`.

## The editor, and why it requires the migration

`Lichtbild_Editor` is the last thing that kept Envira installed for **galleries**. Everything else
about a gallery had moved across; the only way to *change* one was still Envira's screen. Albums
followed two releases later — see *And the album editor, since 26.8.4* in
[`docs/lessons.md`](docs/lessons.md), which is this class's twin and had to wait for albums
to have a record of their own.

**It writes v2 records, so it refuses to run before the migration.** That is the same rule
the repository enforces, seen from the writing side: a v2 record is authoritative only on a
migrated site, so an editor that wrote one earlier would save happily and change nothing a
visitor sees. Rather than have two writable representations of one gallery — the state the
write-path review already had to eliminate once — the edit screen on an un-migrated site
says what to do instead of pretending to work.

That also settles the order of operations, which was not obvious before the editor existed:
**deactivate Envira, migrate, and only then is the site editable.** There is no window
without an editor, because the migration is what turns Lichtbild's on.

**That whole paragraph describes a site migrating from Envira, and until 26.8.18 the code applied
it to every site.** A fresh install has no Envira storage to still be on, so it now starts
migrated and edits immediately; the rule above is a fact about continuing an Envira installation,
not about the plugin. Full text: *The 26.8.18 deploy, which fixed the install nobody here has
ever done* in [`docs/deploys.md`](docs/deploys.md).

Three properties of the save path are load-bearing:

- **An absent nonce field means the request is not ours, and the response is to do nothing —
  silently.** `save_post` fires for quick edit, bulk edit, status changes and autosaves, none
  of which carry the images; reading a missing `lichtbild_items` as "the user removed every
  image" would empty a gallery on a quick-edit of its title. Note that removing this guard
  does *not* actually let such a request through — the nonce verification two statements
  later refuses an absent nonce as readily as a wrong one, and a mutation of the first
  survived until the check also asserted **no PHP warning**. That is the property only the
  first guard has, and `save_post` fires often enough that a warning per quick edit is a log
  nobody can read.
- **Order is submitted explicitly, in `lichtbild_order`, not inferred from the field order.**
  The browser serialises in DOM order and PHP preserves it, so it would usually be right —
  and "usually" is the wrong standard for the one property a drag-and-drop editor exists to
  set. Rows the order does not name are dropped, which is also how removal works.
- **Image tags are attachment terms, not gallery data**, so editing them changes that image
  everywhere it appears. That is what keeps the filter meaning the same thing on every page,
  and it is stated on the screen rather than left to be discovered.

**The media picker has to be told an image's existing tags, or adding it clears them.** A
row created from the media library would otherwise submit an empty tag field, and an empty
field that means "unchanged" is indistinguishable at the server from one that means "remove
them all" — so the fix is that the field is never blank by accident.
`Lichtbild_Editor::attachment_tags()` puts them in `wp_prepare_attachment_for_js`, gated on the
frame having been opened from one of our galleries because it costs a term lookup per
attachment and the media library is used all over the admin. The server also treats a row
with **no** `tags` key as "leave them alone", which is the belt to that brace and is checked
directly.

**`Lichtbild_Config::sanitize()` is not `fill()` with sanitising bolted on**, and confusing the
two is a silent bug in the interesting direction. They answer opposite questions about an
absent key: a *stored* record is missing one because the version that wrote it predated it,
so the default is right; a *submitted form* is missing one because an unchecked checkbox
sends nothing, so a default of `true` would make that box impossible to switch off.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *Albums own their data too, since 26.8.3* — albums spent a release looking migrated while
  still being read in Envira's format. Two conversions are deliberately unfaithful because
  Envira's own record is wrong — and a stored value nothing reads is not neutral when the code
  that would read it prefers it to the truth.
- *And the album editor, since 26.8.4* — the picker is a list of galleries rather than the
  media library, and a cover outside its member gallery is refused in `save()` rather than
  merely not offered: a chooser is markup, and markup is a suggestion.

## What an independent review found, before the migration (26.8.5)

Seven findings, all fixed, four of which would have bitten only *after* the migration — which
is the whole argument for reviewing before running it. Full text in
[`docs/lessons.md`](docs/lessons.md).

- *Three of those hid behind the test harness, and that is the more useful half* — each was
  reachable only once the stubs stopped modelling WordPress wrongly. A payload builder is part
  of the code under test.

## The original v1 decision: a drop-in, not a migration

Lichtbild does not own its data. Galleries stay exactly where Envira put them — post type
`envira`, post meta `_eg_gallery_data` — and Lichtbild reads them in place. Nothing is
migrated, nothing is written back, and no gallery is edited to make the switch.

That buys the property that matters on a live site: **switching is a plugin toggle in both
directions.** Deactivate Envira and Lichtbild takes over `[envira-gallery]`; reactivate Envira
and it takes it back. Comparing the two is a page refresh, and backing out costs nothing.

The takeover is a setting (Settings -> Lichtbild), defaulting to `auto`:

| mode | behaviour |
|---|---|
| `auto` | handle `[envira-gallery]` only while Envira is inactive (default) |
| `always` | handle it even when Envira is active — for A/B on one page |
| `never` | only `[lichtbild-gallery]` renders through Lichtbild |

`[lichtbild-gallery id="N"]` always renders through Lichtbild regardless of the setting.

**Since 26.8.23 every row above is additionally conditional on the site having an Envira
history**, so `always` does not mean always: on a site whose recorded slug scheme is `generic`,
Lichtbild claims neither Envira tag under any mode. That reads like a setting quietly not working,
and it is worth stating why it is not one — `always` exists to render `[envira-gallery]` while
Envira is active, and a site where Envira is active with galleries *has* an Envira history by
definition, so the case the setting was built for is untouched. What it can no longer do is claim
the tag on a site that has no Envira records at all, where there is nothing for it to render and
nothing to compare against. `Lichtbild_Settings::claims_envira_shortcodes()` is the predicate; the
wordpress.org round that asked for it is recorded below.

**The one state this gives up, stated because it is the only behavioural loss in the change:** a
site recorded `generic` and *later* gaining Envira records keeps that answer forever, because
`initialise()` returns immediately once a scheme is stored and never re-derives it. Install
Lichtbild on a clean site, then adopt Envira, then deactivate it, and `[envira-gallery]` stops
resolving where before 26.8.23 it would have. That permanence is the point of the scheme rather
than an oversight — it is what stops a site's published URLs moving when Envira's rows are deleted
years later — and re-deriving it here would couple the shortcode to a question the permalinks
deliberately answer once. It reaches nobody who installs this plugin for what it is for, so it is
recorded rather than fixed, and there is no `readme.txt` upgrade notice for it.

Consequence worth stating: **on an un-migrated site the admin UI is still Envira's.** In v1
Lichtbild had no editor at all, so editing a gallery meant keeping Envira installed — an
accepted trade, since the site's galleries are years old and rarely edited. That is still
the situation before the migration; afterwards `Lichtbild_Editor` takes over, and the section
above explains why it cannot take over any earlier.

## What the site actually uses

Measured against the live database, not assumed. This is what scoped v1.

- **52 galleries** carrying data (50 publish, 1 private, 2 draft), **2,264 items**,
  2,243 distinct attachments, **all plain images** — no video items exist despite
  `envira-videos` being active.
- **Embedded only as `[envira-gallery id="N"]`**, in 49 published posts. No blocks, no
  widgets, no `[envira-album]` anywhere.
- 3 albums (`_eg_album_data`), 2 published, reachable only by permalink.
- Layout: `columns = 0` + `isotope = 1` + `justified_row_height = 150` — justified rows —
  on 49 of 52. `base` gallery theme, `base_dark` lightbox.
- Pagination on 47/52, 10 per page, ajax + scroll, lightbox spanning all pages.
- Right-click protection on 47/52. Sharing in the lightbox on 50/52. EXIF on 5.
- `envira-tag`: 58 terms, 104 assignments — **on attachments**, i.e. genuinely per-image.
- Effectively unused, one gallery each: zoom, slideshow, printing, watermarking, downloads.

Of the 18 active Envira plugins, about six carry real weight.

## Envira's storage, as it actually is

- Gallery record: `_eg_gallery_data` = `{id, gallery, config}`. Not `_eris_…`, which is what
  the Envira docs and older blog posts suggest and cost a wrong first guess here.
- `gallery` is keyed by **attachment ID**; each item is
  `{status, src, title, link, alt, caption, thumb, mobile_thumb, tags}`. `src`/`link` are a
  frozen copy of the **full-size** URL from when the image was added.
- `status` is `active` or `pending`; pending items are not displayed.
- `config` carries ~281 keys per gallery, nearly all of them defaults its settings screen
  wrote out. Only about 60 vary at all across the site, and most of those vary because one
  single gallery is an outlier.
- **Envira serialises booleans inconsistently** — `1`, `'1'`, `'True'`, `true` all appear,
  and `keyboard` is literally split between `'1'` (32 galleries) and `'True'` (20).
  `Lichtbild_Config::flag()` exists for this; never compare a config value directly.
- Envira keeps its **site-wide defaults in a gallery of its own**, marked `config.type =
  'defaults'`. `Lichtbild_Repository` skips it, or it renders as an empty grid.
- **Per-image tags are not in the item record.** The Tags addon migrated them into the
  `envira-tag` taxonomy on the attachment — the `_processed_tag_upgrade` meta on 51
  galleries records that having happened — and the item's own `tags` key is now empty
  everywhere. Read the taxonomy, not the item.
- **16 galleries carry hand-written `custom_css` in ENVIRA's record, keyed on
  `#envira-gallery-<id>`; 20 carry it in Lichtbild's own.** Both numbers are right and they answer
  different questions, so say which one you mean — the 16 is what the original survey counted and
  it is the wrong figure for anything about what Lichtbild renders. The 26.8.22 record was written
  with 16 and had to be corrected: the live site carried 50 inline blocks across 41 pages, which
  is 1 front page + 20 permalinks + 20 embedding posts. `tools/export-custom-css.py` reports the
  count it actually found, and is the thing to ask.
  **Lichtbild no longer reads it** — see *The wordpress.org review, and the feature it cost* below.
  It survives in **two** records that can disagree — Envira's permanently, Lichtbild's own only
  until that gallery is next saved — and the exporter reads both.
  **On this site every declaration in all 20 blocks is commented out**, so the element it emitted
  was `#lichtbild-2423 { }` and removing it changed no pixel. Nothing is waiting to be pasted into
  Additional CSS; a reader who assumes otherwise will restore styling that never existed.

## Security posture

Independent read-only review, 2026-08-07: no critical findings, and the anonymous AJAX
authorization, attribute escaping and settings handling were confirmed sound. Three things
that review changed, all of which had looked fine:

- **Escaping a value into an HTML attribute does not sanitize it.** The caption travels to
  the browser through an `esc_attr`-escaped data attribute and is then inserted with
  `innerHTML` — and `getAttribute()` hands back the original string, so the escaping that
  made the attribute safe does nothing for the parse that follows. `Lichtbild_Item::caption()`
  applies `wp_kses_post()` at the source instead.
- **Envira's frozen `src`/`link` are database strings, not URLs.** They are used whenever an
  attachment has been deleted, and the JSON endpoint emits them without `esc_url()`. Both
  now go through `Lichtbild_Item::safe_url()`, which allows only `http`/`https`.
- **Items with unknown dimensions are kept out of the lightbox**, rather than handed to
  PhotoSwipe as `0x0` slides for its zoom arithmetic to divide by. They stay in the grid as
  ordinary links that open the file.

**The two front-end AJAX endpoints verify their nonce and never refuse on it (since 26.8.19), and
that is a decision rather than an omission.** A nonce says when a page was generated and expires
twelve hours later, while a full-page cache serves pages generated days ago — so refusing would
break pagination and filtering on cached sites at an unpredictable hour, with no error the owner
could act on. It costs nothing because both endpoints are reads that change no state and return
JSON no cross-origin script can read: the authorization is `is_viewable()`, which had to hold on
its own anyway. `Lichtbild_Album_Editor::handle_covers()` is an admin path, is never cached, and
does still refuse.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *What the second review changed, on the write path* — four write-path defects found before
  anything touched production, the first of them a comment asserting a safety property that
  nothing enforced.
- *There are three places that publish a gallery, and the rule was copied into two of them* —
  an album published a protected gallery's cover while its own permalink and both AJAX
  endpoints correctly refused. Count the publish paths, not the fixes.
- *The rule is `Lichtbild_Repository::is_viewable()`, and extracting it found two more gaps* — one
  predicate would otherwise mean one mutation, so they split into three that delete a **call
  site** and two that break a **leg** — and a stub answering for one of two post types will
  invent a status for the other.

## Traps already paid for

- **Envira's per-field EXIF toggles are load-bearing, and they are all off for identity
  fields.** Across all 52 galleries `exif_lightbox_make`, `_model` and `_capture_time` are
  `0` while aperture, shutter speed, focal length and ISO are `1`. A renderer that prints
  everything WordPress can parse therefore prints the camera body on every gallery whose
  settings say not to. `Lichtbild_Exif::fields()` takes the enabled set; it does not decide.
- **`tags_all` is a per-gallery, site-owner-translated string** — it is `Alle` on every
  gallery here, not `All`. The stored label wins over the plugin's own translation.
- **Deep links name the image, not its position.** The fragment is
  `#lichtbild-<galleryId>-i<attachmentId>`. An index is only meaningful together with the filter
  and page it was taken under, neither of which is in the URL, so an index-based fragment
  opens a different photograph for anyone whose filter differs — including the same visitor
  after a reload. Resolving one fetches the unfiltered item list, because the linked image
  may be on a page the grid has not rendered.
- **A tag filter has to span the whole gallery, not the rendered page.** The filter bar
  lists every tag in the gallery while the grid shows one page, so 17 of 40 buttons on the
  test gallery filtered a paginated grid down to nothing. Filtering is therefore server-side
  (`Lichtbild_Gallery::filtered_items()`, and `page_count()`/`page_items()` both take the tag),
  and the AJAX response re-renders the pagination nav because the server is the side that
  knows how many pages the filter leaves. A DOM-hiding filter cannot be made correct here.
- **WordPress already has the EXIF.** It parses it at upload and stores it in
  `image_meta`. Envira's addon re-reads the original file per request to get the same
  values. `lens` is the one field that genuinely is not in `image_meta`; it is off
  everywhere here and is deliberately unsupported rather than bought with a file read.

## Deliberate improvements over Envira

- **The justified grid is pure CSS.** Each item is both grown and sized in proportion to its
  own aspect ratio, so within a flex row every item lands at width `aspect * k` and
  therefore at height `k`. Envira needed isotope, which cannot lay out until the images have
  loaded; here the geometry is settled before the images arrive, so there is no reflow. The
  last row is kept at its natural size by a `::after` spacer with an enormous `flex-grow`.

  Note the precise claim: **the geometry is in the CSS, not necessarily in the first paint.**
  Shortcodes run during `the_content`, after `wp_head`, so a stylesheet enqueued at render
  time is printed in the footer by `print_late_styles()` and the first paint can be unstyled.
  `Lichtbild_Assets::maybe_enqueue_early()` looks for the shortcode in the post content during
  `wp_enqueue_scripts` to get the stylesheet into the head for the ordinary case; a gallery
  rendered from a widget or a template call still falls back to the late enqueue.
- **Grid images are WordPress derivatives with a srcset**, not the original. Envira's
  `src` is the full-size file: 239 KB versus 72 KB for the `medium_large` derivative on a
  sample image, per grid thumbnail.
- **PhotoSwipe 5 is dynamically imported on first click**, so a page of galleries costs no
  JavaScript until someone opens an image. Assets are enqueued only once a gallery has
  actually rendered; Envira enqueued site-wide.
- Intrinsic `width`/`height` on every image, so nothing shifts as the page loads.
- **The caches are primed per gallery, not per image.** Reading an item touches three things
  WordPress caches per post — the attachment row (the title and excerpt an item falls back to),
  its meta (dimensions and registered sizes) and its terms (the tag filter). Unprimed that is
  three queries per image, and `Lichtbild_Ajax::handle_items()` walks *every* item because the
  lightbox spans pages on 47 of the 51 galleries: roughly fifteen hundred queries on the
  504-image gallery, in the request a visitor waits on to open the lightbox.
  `Lichtbild_Gallery::prime()` reduces it to three.

  Two details are load-bearing. It is called with **the set about to be walked**, not always
  with everything — a paginated gallery renders ten items, and priming five hundred to read ten
  is the same mistake pointing the other way — which is why `handle_page()` primes all items
  only when a tag is applied, and the renderer only when the tag bar is on. And in
  `handle_items()` it runs **before** the filter, because deciding which items carry the tag is
  itself what reads every item's terms.

  Testing this needs a word of warning: **priming changes no output at all**, so deleting it
  leaves every other check green. `_prime_post_caches()` is recorded by the stub and the check
  asserts the *shape* of the call — one call covering every attachment, rather than none and
  rather than one per image. Mutation `A1` is the proof.

## Testing

There is no WordPress in the loop. `tests/wp-stubs.php` implements the ~25 functions Lichtbild
calls, backed by a fixture exported from the live database, and `tests/render-test.php`
renders **every gallery on the site** and asserts the markup.

```sh
# 1. one-off: tests/.db.json with the wp-config.php values (gitignored). Its "host" is NOT
#    wp-config.php's DB_HOST — that says localhost. Use the hosting account's own name.
uv run --with pymysql --with phpserialize python tests/export-fixture.py
php tests/render-test.php

# or, with no database and no credentials — this is what CI and a fresh clone run:
php tests/make-fixture.php
php tests/render-test.php tests/fixture-synthetic.json
```

238 checks over 51 galleries and 529 rendered items. Each is reported with the **population
it examined**.

That last property needs `Checks::expect()` to be true, and it is worth understanding why.
A check only exists once an assertion runs, so a conditional area — EXIF, say — does not
report `0 examined` when it stops matching; it **disappears from the report entirely**,
which reads as "not applicable" and is indistinguishable from coverage silently lapsing.
Conditional checks are therefore declared up front, and a declared check that examined
nothing is reported `[EMPTY]` and counts as failing. Mutation `M20` is the proof: it switches
the EXIF area off and four checks go red instead of vanishing.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *Declaring conditional checks by hand does not scale, and the count is the instrument* — a
  check that stops running disappears from the report rather than failing, and the generic
  instrument is the **set of check names** compared against baseline in both directions. Also
  holds the mutation harness's own honesty rules, the four checks pinned by nothing and why,
  and the fatal that pre-empted the check that should have failed.
- *And since 26.8.10 there is a second corpus, built by `tests/make-fixture.php`* — so a fresh
  clone and CI can run every check with no database — and why the generator is committed while
  its output is not.
  - *The measurement that says it is worth anything* — the row that means something is the
    pinned-check **set**, not the kill count; and a claim about what a corpus fails to cover
    needs the full red set of every mutation.
  - *Five shapes the corpus was missing, and how each was found* — each found by a check going
    red or a mutation losing a red — never by reading the suite.
  - *Three defects in the suite that only a second corpus could expose* — a gallery named by
    ID, an unguarded division that fatals rather than fails, four per-item checks that encoded
    "every item has a live attachment" without saying so — and one PHP warning that silently
    re-scored every check after it, in the direction nobody audits.
  - *CI, which is the whole point* — five PHP versions, the generator run twice and `cmp`d, and
    `git diff --exit-code` after the mutation pass.
- *PHP versions: test the one the site runs, not the one the Mac has* — the site is 8.2.30 and
  this Mac is 8.5. A version that is not installed is `[SKIP]`, never a pass, and a run with no
  summary line is `[BROKEN]`.
- *A public read endpoint cannot be gated on a nonce, and the harness said it could (26.8.19)* —
  a stub returning `true` unconditionally models code that cannot get the answer wrong, and
  correcting it turned four passing checks red at once. Holds the deep-link shim too, and what a
  test of a closed IIFE can and cannot see.

## The local WordPress

```sh
bash tools/devenv.sh setup     # build it from a dump of the live database
bash tools/devenv.sh start     # database on 3307, site at http://localhost:8080
bash tools/devenv.sh reset     # restore to the snapshot, in seconds
bash tools/devenv.sh status    # what is running, and which post types the rows are under
```

Lives at `~/Developer/wp-lichtbild`, outside this repo. WordPress **7.0.3** and PHP **8.2** to
match the live site, MariaDB **10.11** to match its engine, and the plugin is symlinked in so
an edit here is live there with nothing to sync.

It exists for the five things the stub suite structurally cannot reach: real `$wpdb` against
the production engine, real rewrite-rule generation, real object and term caches, the real
Envira plugin to coexist with, and the admin screen actually rendering. It does not replace
the stub suite — that runs in seconds with no infrastructure and covers 220 properties.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *The editors, against real infrastructure* — 26 checks across two scripts, and the
  precondition is checked before anything is changed — `reset` restores production's
  `active_plugins`, with Envira running.
- *The one thing even that does not prove: the browser round trip* — an identical render is
  also what a save that never ran produces, so the control is the whole check — and WordPress
  prints some of its own hidden inputs with single quotes.
- *The full round trip, on real infrastructure* — 53/3/58 moved and rolled back
  byte-identically, with the control that says "identical" is not vacuous. One count
  discrepancy that was Envira's doing: count in one process or not at all.
- *Five traps, four of them the harness lying rather than the code* — `localhost:3307` discards
  the port, PHP CLI writes errors to stdout, the built-in server has no rewrite engine, and
  opcache serves the build you just replaced — pointing at doing nothing.
- *Two failures the setup script itself had, and both are this project's recurring shape* — it
  printed `ready` over a wall of failures, and read an HTTP 200 as a working page over a
  zero-byte body.
- *What has to come from the server, and why* — the theme and all twenty `envira-*` directories
  over read-only FTPS; uploads deliberately not fetched, which is why Envira's own stylesheet
  is a primary source on disk.

## Local preview

Renders three real galleries — justified with pagination and EXIF, one with the tag filter
forced on, one fixed-column — with pagination, filtering and the lightbox all live, because
the preview server answers the two AJAX endpoints out of the fixture.

```sh
LICHTBILD_FIXTURE=tests/fixture.json php -S 127.0.0.1:8765 -t . tests/preview-server.php
open http://127.0.0.1:8765/
```

Images load from `timo-stein.com` itself (uploads are not hotlink-protected), so the preview
needs a network connection and touches nothing on the server.

## What happened on 2026-08-07: switchover, migration, Envira gone

The day the site changed hands, in three acts, and they are worth reading in order: each was
verified against the state the previous one left, and the second and third are the only evidence
the project's central claim was ever tested. Full text in
[`docs/lessons.md`](docs/lessons.md) — three sections that lived here until 26.8.23, when this
file reached the ceiling its own test guards.

- *The live switchover, and the three bugs it found* — every one found by a control rather than
  by a test, because each needed a state the local environment had never been in: both plugins
  active, and a URL space nobody had thought to capture. Holds the four things that generalise
  past this deployment, deploying-as-a-no-op first among them.
  - *Fixed since: the early enqueue now matches only the shortcodes we claim* — "it did not
    enqueue" is also what a scan matching nothing produces, so both directions are asserted.
  - *Closed in 26.8.6: the shortcode was the fourth publishing path* — a product decision blocked
    on an unknown cost is usually blocked on an unrun query.
  - *And the fifth publishing path, added 26.8.14, which arrived already asking* — `Lichtbild_Block`
    renders nothing itself and hands to the shortcode, so the visibility rule has no second
    implementation to drift from — and the coverage does **not** follow from the design, which
    measurement said rather than reasoning.
    - *The one real defect in this release, and no check would ever have found it* — the picker's
      choices were built on `init` — 111 queries on every front-end request, changing no rendered
      byte, so every instrument here was blind. The first measurement was a warm-cache artifact.
    - *Three things the harness could not see until it was told to* — a stub that ignores an
      argument models code that cannot get that argument wrong; and a `block.json` is a source
      file for translation that no tokenizer can see.
    - *`tests/blocks-js-test.js`, the only JavaScript this repo tests* — if `blocks.js` throws,
      both blocks are absent from the inserter and every live check still passes. Restore by
      copy, not `git checkout`.
    - *What real WordPress said, and the artifact it produced first* — a migration performed
      later in the same request leaves every object built earlier naming the rows they used to
      name — the post-types trap, one layer further in.
- *The migration, run on the live site 2026-08-07* — 159/159 semantically identical, and why a
  migration must be verified semantically rather than byte for byte: the post type is in
  WordPress's own body classes, so a byte hash reports 100% changed and tells you nothing.
  - *The regression was Yoast's, and the pre-flight should have found it* — Yoast keys its
    settings on the registered name, so renaming the taxonomy dropped the canonical from 58
    indexed URLs. Ask what **else** keys off the names you are renaming; print the plan, do not
    apply it.
- *Envira is gone (2026-08-07)* — the twenty plugin directories deleted, the three URL spaces
  still answering 200, and rollback checked rather than assumed against a real WordPress.

## Deploying to the live site

The plugin is not in the site's plugin list by default and has to be uploaded. There is no
shell on the host, so it goes over FTPS, and **that transport drops large transfers often
enough that a deploy has to be verified rather than trusted.**

Since 26.8.2 this is `tools/deploy.sh` rather than a recipe followed by hand. The first two
deploys were ad hoc, and both of the mistakes below — an unchunked upload that took the site
down, a size check that cannot see an equal-length change — were mistakes the *procedure* was
supposed to prevent and the person running it did not. A rule written down as a caution gets
nodded at; the same rule written as a line of code gets followed.

```sh
uv run --with pymysql python tools/live-urls.py > urls.txt   # from the database, not memory
bash tools/deploy.sh audit                    # what the SERVER has -- run this BEFORE editing
                                              # UPLOAD_ORDER, and paste from its answer
bash tools/deploy.sh plan                     # order, and the greps that justify it
bash tools/deploy.sh capture before.tsv urls.txt
bash tools/deploy.sh push                     # chunked, every file digest-verified
bash tools/deploy.sh capture after.tsv urls.txt
bash tools/deploy.sh compare before.tsv after.tsv
```

Four properties are worth knowing before changing it. **`UPLOAD_ORDER` is answered by the server,
not by a diff** — `audit` downloads every deployed file, digest-compares it, and reports what
differs, what is absent and what it could not read, refusing rather than summarising when it
cannot tell. It exists because that list was the previous release's five times running, and
because a `git diff` against a tag structurally cannot see a commit that edited a shipped file
after the last deploy and never shipped — which had happened. **The upload order is asserted, not
trusted** — `plan` re-derives which file defines a method and which call it, and refuses to run
if the list contradicts that; swapping two entries turns it red, which has been checked.
**`compare` asserts the join's own sanity**, because a previous deploy's ad-hoc version compared
the hash column against the status column and reported all 116 URLs as changed. And the
password is read from the keychain straight into a `.netrc` in a 0700 temp dir that is removed
on exit, so it never reaches an argument list, a log line, or a transcript.

- `curl` reports `426` (server closed the data connection) on maybe a quarter of files above
  ~15 KB. It is **not deterministic and not a size limit**: a 189 KB file went first time,
  while an 18 KB one failed seven attempts in a row and a 31 KB one succeeded on the fifth.
  Nothing in the file's content predicts it.
- **A failed transfer leaves a file of the wrong length on the server, not no file.** Three
  PHP files landed at **0 bytes** and one JavaScript file at exactly **16,384** — a block
  boundary, which is the tell that a stream was cut rather than refused. A 0-byte
  `class-lichtbild-config.php` is invisible until someone activates the plugin, and then it is a
  fatal error on a public site.
- So: **never trust curl's exit code.** The exit code was right that first time, but only
  because the abort happened to be reported.
- Verify **every** file, not the ones that reported an error. The first pass reported seven
  failures and a size audit found the same seven — but that audit was written after a first
  version that labelled every *local* file "ok" without checking the server at all, which is
  the same empty-filter-reads-as-a-pass trap the test notes are full of.
- **Comparing byte counts is not enough, and this section used to say it was.** A file whose
  new version happens to be the same length as the old one passes a size check while still
  holding the old content — and that is not a freak case: a version string of equal width
  (`26.8.0` → `26.8.1`) and a block moved within a file both produce it, and both occurred in
  the same deploy. **Verify by digest: download the file back and compare `shasum` against the
  local copy.** Size is a cheap first gate; the digest is the only thing that establishes what
  is on the server.

- *Publishing gave the live site a second update channel, and the two disagree by design* — the
  site's plugin folder is the wordpress.org slug, so WordPress can now update it from SVN with
  none of this script's verification, and the state nothing else reports is one version number
  carrying two sets of bytes. `deploy.sh channels` compares them and `push` runs it first; its
  first run found that an update would **delete the German catalogue** from a `lang="de"` site,
  because the `.mo` is deployed and deliberately unpublished.

**The deploy records are one per release that reached the site, and their index moved with
them.** Eighteen releases with thirty-two hooks is a corpus, not an index line, and it was a
seventh of this file. The list now opens
[`docs/deploys.md`](docs/deploys.md) as *The records at a glance*, which is where you already
are when the question is "what happened last time"; `tests/docs-index-test.php` guards it there
exactly as it guards this file.

Three are worth naming here, because they change how you read `plan`'s output rather than
recording one release: **26.8.7**, where `plan` passed an order that would have taken the site
down; **26.8.10**, where the stale thing was the deploy script itself; and **26.8.22**, the
first release to DELETE a method, where the ordering constraint runs backwards and `plan` said
"satisfied" over the order that would have been a fatal.

## German, and why the catalogue needs a test of its own (26.8.13)

The site is `lang="de"` and 28 visitor-facing strings had rendered in English since the
switchover. A catalogue rots on the next string added and the symptom is invisible, so it has a
test in CI. Two of its four lessons were superseded at 26.8.14 by `wp i18n make-pot`. Full text
in [`docs/lessons.md`](docs/lessons.md).

- *The catalogue has a second home since 2026-08-28, and it is the one that ends the hazard* —
  `tstone1` is PTE for de_DE, both GlotPress projects are at 206/206, and a language pack lands
  in `WP_LANG_DIR` where the deployed `.mo` is not the only source of German. Holds why six
  strings arrive as *waiting* however complete the import is.

## Two defects a person found by looking at the site (26.8.11)

The lightbox never filled the viewport and album covers left a hole — both live since the
switchover, both invisible to 207 checks and 187 mutations, because the markup was right and
the failure was in what it means to a browser. Full text in
[`docs/lessons.md`](docs/lessons.md).

- *Changing `display` orphans every rule that targets the old layout model (26.8.12)* —
  `grid-template-columns` is inert on a flex container: no error, no warning, no fallback. CSS
  has no output to assert on, so the instrument is a rendering engine — `--headless --dump-dom`
  plus a `getBoundingClientRect()` probe, measured at three stylesheets, not two.

## Site access

**The hostname, account and table prefix are deliberately not in this repository**, which is
public. An FTP hostname plus its username is two thirds of a login, and stating that the database
port answers from outside tells a reader exactly where to aim the third. None of it is secret in
the sense of being a password — and that is the point worth keeping: *the reason to withhold it is
that together it is reconnaissance, not that any one line is a credential.* A repository that
publishes its own deployment target has done an attacker's first hour of work.

They live in two gitignored places instead, both machine-local:

- `tools/deploy.env` — `LICHTBILD_DEPLOY_HOST` and `LICHTBILD_DEPLOY_USER`, read by `tools/deploy.sh`.
  Everything else in that script is parameterised off those two, the keychain lookup included, so
  there was exactly one place to change.
- `tests/.db.json` — the database connection for `tools/live-urls.py` and `tests/export-fixture.py`.
  Its `host` is **not** `wp-config.php`'s `DB_HOST`, which says `localhost`; use the hosting
  account's own name.

The password is never in either. It is in the macOS login keychain, looked up by host and account
at the moment of use, piped straight into a `.netrc` in a 0700 temp dir that is removed on exit —
never rendered, never an argument, never a log line.

**The hosting shape and the stack versions moved to `AGENTS.local.md`, which is gitignored.**
They are facts about the target rather than about the plugin, and the rule stated above applies to
them for the same reason it applies to the hostname: no single line is a credential, and together
they describe one named site's hosting. What still has to be said here, because the code only
makes sense with it: **there is no shell on the host**, which is why deployment is FTPS and why
every uploaded file has to be verified by digest rather than by exit code or size.

**If `deploy.sh` refuses with `set LICHTBILD_DEPLOY_HOST and LICHTBILD_DEPLOY_USER`, that is this
change working, not a broken script.** Recreate `tools/deploy.env` with those two lines.

## Submitting to wordpress.org — submitted 2026-08-09, three review rounds, **approved 2026-08-27**

**Approved on 2026-08-27** (`APPROVED lichtbild-gallery/tstone1/27Aug26/T1`), on the 26.8.25
archive, eighteen days after submission. **Approval creates the SVN repository and publishes
nothing** — measured the same day: `plugins.svn.wordpress.org/lichtbild-gallery/` answers 200
with an empty `trunk/`, `tags/` and `assets/` (a bogus slug there 404s, so the endpoint
discriminates), while `wordpress.org/plugins/lichtbild-gallery/` still 301s to the search URL
and `api.wordpress.org/plugins/info/1.2/` still 404s for the slug. Those last two are the
*pre-publication* shape and are not a fault; they change when the first commit lands, and until
then nobody can install this plugin.

**26.8.21 was uploaded and the directory assigned the slug `atelier`.** That slug was
surrendered on 2026-08-22 — see *Round 3 rejected the name, and nothing else* below — and a
reservation for `lichtbild-gallery` requested in its place. While a submission is unapproved its
directory URL 301s to `wordpress.org/plugins/search/<slug>/` rather than 404ing, which is the
*expected* pre-approval shape: a 404 check would report the wrong thing about a healthy
submission.

**The submission was pended by an automated pre-review on 2026-08-14** (`AUTOPREREVIEW
atelier/tstone1/14Aug26/T1`) — before any human read it, and it is a *reply* to that thread, not
a fresh upload, that puts the plugin into a named volunteer's queue. Four findings, all
addressed in 26.8.22; the one with teeth cost a feature.

- *The wordpress.org review, and the feature it cost* — a plugin may not store and print CSS
  entered through its own UI, so the setting, its conversion, its sanitiser, the textarea and the
  `<style>` all went together; nothing was destroyed, because Envira's record still holds it.
  - *The CRLF checkout, which had been quietly disabling the mutation harness* — found while
    running it, not part of the review: no `.gitattributes`, so every **multi-line** mutation
    matched nothing on Windows. The harness said `BROKEN`, which is the only reason it was
    visible.

**Round 2 arrived on 2026-08-15** (`R atelier/tstone1/14Aug26/T2`), one day after 26.8.22 was
uploaded. Three findings, none of them a security defect, and the useful thing about them is that
**two were correct readings of code that is deliberately what it is** — which is a different
review to answer than one that names a bug. Addressed in 26.8.23.

- *Round 2 found two design decisions and one real one* — the raw `$_POST` handed to a filter was
  a genuine hole and cost one argument; the Envira shortcode names and the Yoast option write are
  the plugin's purpose, and the answer to one of them was still a code change, because "correct on
  a migrating site" was being applied to every site.

**Round 3 arrived on 2026-08-22** (`R ❗TRM atelier/tstone1/14Aug26/T4`) and pended the submission
*without reading the code*: the display name and slug were too broad. It was right — the
directory's own search returns three published plugins leading with the old word — so the plugin
was renamed rather than defended, and 26.8.24 carries the new identity.

- *Round 3 rejected the name, and nothing else* — measure a candidate name against the directory's
  search API before choosing it, because the same call that convicts the old one clears the new
  one. Holds the rename recipe and its `envira` count control, and four things this rename cost
  that 26.8.16 did not: the text domain is **not** the identifier prefix (237 of 299 literals),
  `class-atelier.php` ends in `atelier.php` and collapsed two files onto one name in a way no
  inspection can undo, the `.mo` is a binary the substitution cannot reach and a stale one fails
  silently, and the `sort()`-order literal went red a second consecutive rename — in the opposite
  direction.

That earlier note saying no reply was needed described the **submission confirmation**, and it
stopped being true the moment this arrived: silence on a pended submission is a rejection after
three months, not a queue position.

**The live risk is now email, not code.** The team states that the account address must stay
operational, must not autorespond, and must not mark their mail as spam — and that a forwarder,
alias or group address must allow-list `plugins@wordpress.org` too. `mail@timo-stein.com` is on
All-Inkl (MX `v100427.kasserver.com`), so the allow-list that matters is the mailbox's own
SpamAssassin configuration in KAS, upstream of any rule in Apple Mail. A review thread that dies
in a spam folder looks exactly like a queue that is simply slow.

What follows was verified against wordpress.org rather than recalled, because most of it is the
kind of thing that gets assumed.

- **The WordPress.org account is `tstone1`, and it will never match the GitHub handle.**
  wordpress.org usernames cannot contain a hyphen, so `tstone-1` is not registrable there and the
  two handles are permanently different. `readme.txt`'s `Contributors: tstone1` is therefore
  **correct and must not be "corrected"** to match the repository — an edit that looks like fixing
  an inconsistency would name a user who cannot exist. Whitelist `plugins@wordpress.org`: the
  review thread arrives by email and dies silently if filtered.

  Registered 2026-08-09, and this entry said the opposite the day before — `profiles.wordpress.org/tstone1/`
  answered 404 on 2026-08-08 and answers 200 now, with `.../zzq-no-such-user-9182/` still 404 as the
  control that the endpoint discriminates rather than answering 200 to everything. The probe was
  right both times; the world moved. Worth stating because a fact recorded as "checked" carries a
  date for exactly this reason, and *account does not exist* is the one fact in this section that a
  single action by the user can invert overnight.
- **The slug is `lichtbild-gallery`, requested 2026-08-22, and it is permanent once approved.**
  A slug can be changed from the Plugin Submission page **until a reviewer begins** and never
  afterwards; the display name stays changeable either way. `api.wordpress.org/plugins/info/1.2/`
  404s for it, which is correct for an unapproved plugin rather than evidence of a problem — the
  control that the endpoint works is the same call for `akismet`, which returns full data.
  **Measured before the name was chosen**, and this is the evidence the reply to round 3 quotes:
  the directory's own search returns **0 results** for `lichtbild`, where a search for `atelier`
  returns three published plugins leading with that word — `atelier-product-sorting-for-woocommerce`,
  `atelier-scroll-top` and `atelier-create-cv`. Run the query rather than reasoning about the name:
  `api.wordpress.org/plugins/info/1.2/?action=query_plugins&request[search]=<term>`.
- **The listing artwork lives in `.wordpress-org/` and is not in the zip.** Four screenshots in
  the order `readme.txt` describes, plus an icon and banner built by `tools/build-brand.py` —
  the mark is a justified grid laid out by the plugin's own rule, so editing an aspect ratio
  re-solves the geometry rather than needing a bitmap redrawn. It asserts each PNG's exact
  pixel size and that the banner's wordmark still matches `Plugin Name:`, which is the one
  string no other test reads and this plugin has been renamed twice. All of it goes into the
  `assets/` directory at the root of the SVN checkout — that is the SVN root's, **not the
  plugin's own `assets/`**, and confusing the two ships two megabytes of listing images to every
  installation.
- **`Plugin URI` resolves.** `github.com/tstone-1/lichtbild-gallery` was made public on 2026-08-09, from a
  squashed root commit; the full pre-squash history is private in `tstone-1/atelier-history`. The
  two decisions were separate — wordpress.org requires neither of the other — and this one was
  taken for its own reasons.
- **Plugin Check reports 0 errors and 0 warnings on the 26.8.24 archive**, run through the
  official plugin against the actual zip. The team's own email says automated tools have false
  positives and miss things, so this is a floor rather than a prediction: the 41 warnings it used
  to report were all either analysis limits or deliberate decisions, and each now carries the
  justification in the code where a reviewer meets it.

  **This line claimed 0/0 while 26.8.22 and 26.8.23 were each shipping one warning**, and it is
  worth saying why rather than just correcting the number: `upgrade_notice_limit` fires on a
  `readme.txt` upgrade notice over 300 characters, the 26.8.22 notice was 379, and that notice was
  *added by* 26.8.22 — so the claim was written true and was falsified by the very release it was
  written for. Fixed in 26.8.24 by trimming it to 284. **Run the check per release rather than
  citing this line**, and check the whole set: measuring all four notices at once found only the
  one over, which a spot check of the newest would have missed entirely.

  Two traps in running it, both of which produce a confident wrong answer. Plugin Check derives
  the expected text domain from the **directory name**, so checking an archive extracted to
  `zzcheck-lichtbild-gallery/` reported 24 `TextDomainMismatch` ERRORs that say nothing about the
  plugin. And the working tree is symlinked into the devenv under the real slug, so checking the
  shipped bytes means parking that symlink and extracting the zip in its place — otherwise the
  thing measured is the tree, not the release.

Two guidelines are worth knowing before a reviewer raises them, and both are now satisfied.
**Guideline 17** forbids a trademark as the "sole or initial term of a plugin slug" — and it is
what round 3 was decided under, so `lichtbild-gallery` was checked against it before being chosen — and asks for "Dancing Sloths for Superbox" phrasing rather than the reverse. Neither
name leads with Envira: the plugin header's description opens "Responsive galleries for
WordPress." and `readme.txt`'s short description does not mention Envira at all. The Envira
sentence that follows is nominative use and permitted. **Guideline 4** wants documented access to
source: the unminified PhotoSwipe sources ship beside the minified ones, and a `readme.txt` FAQ
entry now says so rather than leaving it to be noticed.

**Published 2026-08-27 as r3669173**, from a checkout at `~/Developer/_svn/lichtbild-gallery`:
`tools/build-zip.sh`'s tree into `trunk/`, copied to `tags/26.8.25/`, screenshots into the
checkout's own `assets/`. Verified by exporting the committed paths back and digest-comparing
them to the reviewed zip — **including the zip the directory rebuilds and actually serves**,
which is a different archive of identical files. Two things to know for the next release:
`svn` is `brew install svn` (macOS has not shipped it since 10.15), and the SVN password is
**separate** from the wordpress.org login, set at
`profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password`. **Since 2026-08-28 it is
in the macOS login keychain**, so it no longer has to be hunted for or regenerated:

```sh
security find-internet-password -s plugins.svn.wordpress.org -a tstone1 -w \
  | svn commit -m "Lichtbild Gallery <version>" --username tstone1 --password-from-stdin --non-interactive
```

Piped, never `--password`, which puts it in the argument list; `svn` caches nothing, so
`~/.subversion/auth/svn.simple/` stays empty and the keychain is the only copy. SVN here is a
release system rather than a history — commit ready versions only.

> That comparison ran once against a zip the directory was still generating and reported all 41
> files as differences on a healthy publish — a truncated download, not a mismatch. The mechanism
> is not specific to this repo, so it lives in the shared corpus rather than here: see the entry
> on an empty side of a comparison, under *Shipping and deploying* in `agent-memory`.

**That gap is closed since 26.8.26, and walking it once found a defect no harness here could
reach.** From publication until 2026-08-28 this said nobody had ever installed the plugin from
scratch and made a gallery — the fresh-install path was written in 26.8.18 and covered by the
suite, which is not the same as walking it. `bash tools/devenv.sh fresh` now builds an empty
WordPress on its own ports, installs the plugin **from the directory**, and `fresh test` runs
`tests/fresh-install.php` (37 checks, through the editor's own save path and over HTTP).

- *The fresh install nobody had done, and the defect waiting in it (26.8.26)* — every gallery
  permalink 404'd on a new install, because the plugin had no activation hook and stored rewrite
  rules are built from the types registered at flush time: 94 rules with none of ours, against 22
  and a 200 after invalidating them. `delete_option( 'rewrite_rules' )` rather than
  `flush_rewrite_rules()`, because activation runs after `init` has fired without the plugin
  loaded. Holds the control that reactivation alone does **not** fix it, four ways the new
  harness made correct code look broken (a summary that counted zero under six failures, a
  themeless site answering 200 with an empty body), and why this environment cannot be in CI.

## What the third review found (2026-08-22)

An independent read-only review of the whole codebase; two blockers, seven warnings, three
nitpicks, all real and all addressed in 26.8.25. Full text in [`docs/lessons.md`](docs/lessons.md).

- *The third review, and the finding whose recommended fix was worse than the defect* — the
  uninstall blocker was traced by the reviewer and **measured** here, where the permalink came
  back byte-identical to a deliberately bogus slug; and the recommended repair for the migration
  finding would have produced that same state, because errors gate the schema flag and a site
  whose schema says unmigrated cannot find its own rows. Holds the four ways the new tests were
  nearly worthless — a subject taken from the code under test, an `expect()` one level too deep,
  a behaviour change that silently retired an existing mutation, two more that went `BROKEN` —
  plus Plugin Check reading the text domain from the directory name, and the gitignore near-miss
  that would have published the deployment host.

## Conventions

- CalVer `YY.M.MICRO`, matching `screenpick`/`tpdf`. Version lives in the `lichtbild-gallery.php`
  header and the `LICHTBILD_VERSION` constant — **both must agree**.
- **`CHANGELOG.md` says what changed; this file says why.** The split is stated because two
  places describing one release is how prose drifts, and drift is this project's most expensive
  habit. A release note is a fact about behaviour and belongs in the changelog even when it is
  dull; a trap, a rejected alternative, or what a verification actually cost belongs here even
  when it is long. Neither is a summary of the other, so neither goes stale by being unread.
  The deploy records carry reasoning rather than behaviour, so they are entries like any
  other: one line here, full text in [`docs/deploys.md`](docs/deploys.md).
- WordPress coding standards: tabs, `snake_case`, Yoda conditions, full docblocks on every
  class, method and property.
- Escape at output, never at assignment. `Lichtbild_Renderer::attributes()` is the one place
  that decides between `esc_url` and `esc_attr`.
- No build step. The JavaScript is a classic script that dynamically imports PhotoSwipe;
  there is deliberately no bundler, no npm dependency at runtime, and PhotoSwipe 5.4.4 is
  vendored under `assets/vendor/photoswipe/` with its MIT licence.
