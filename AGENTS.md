# Lichtbild Gallery

A WordPress gallery plugin that replaces Envira Gallery Pro on `timo-stein.com`, so the
site stops depending on a ~100 EUR/year licence it no longer pays for and is running an old
version of.

**Public and GPL-2.0-or-later**, with `LICENSE`, `README.md` and a wordpress.org-format
`readme.txt`. `github.com/tstone-1/lichtbild-gallery` has been public since 2026-08-09 and the
plugin was submitted to wordpress.org the same day, approved and published on **2026-08-27**, and
is listed at `wordpress.org/plugins/lichtbild-gallery/`. The current release is **26.9.1**, live
on the site and in the directory since 2026-09-25; the dated history is under *Submitting to
wordpress.org* below.

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

- *Editor storage and save invariants* — Full context in [docs/lessons.md](docs/lessons.md).

## What an independent review found, before the migration (26.8.5)

Seven findings, all fixed, four of which would have bitten only *after* the migration — which
is the whole argument for reviewing before running it. Full text in
[`docs/lessons.md`](docs/lessons.md).

- *Three of those hid behind the test harness, and that is the more useful half* — each was
  reachable only once the stubs stopped modelling WordPress wrongly. A payload builder is part
  of the code under test.

## The original v1 decision: a drop-in, not a migration

- *Legacy shortcode takeover decisions* — Full context in [docs/lessons.md](docs/lessons.md).

## What the site actually uses

- *Original deployment inventory* — Full context in [docs/lessons.md](docs/lessons.md).

## Envira's storage, as it actually is

- *Legacy record format and custom CSS* — Full context in [docs/lessons.md](docs/lessons.md).

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

- *Rendering and cache design* — Full context in [docs/lessons.md](docs/lessons.md).

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

- *Deployment transport and verification* — Full context in [docs/lessons.md](docs/lessons.md).

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

## Submitting to wordpress.org

- *Directory submission and publication history* — Full context in [docs/lessons.md](docs/lessons.md).

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
