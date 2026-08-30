# Changelog

What changed in each release, and nothing else. The *reasoning* — why a decision was taken,
what a trap cost, how a deploy was verified — lives in `AGENTS.md`, which is the documentation
of record; this file is the short answer to "what shipped in 26.8.4".

Versions are CalVer `YY.M.MICRO` and live in the `lichtbild-gallery.php` header and the `LICHTBILD_VERSION`
constant, which must agree. Dates are the deploy date.

There is no 26.8.3. Albums gained a record of their own under that number, but the album editor
landed before anything was deployed, so the two shipped together as 26.8.4.

> **This plugin was called Tivira through 26.8.15 and Atelier from 26.8.16 to 26.8.23.** Every
> entry below 26.8.24 describes code that shipped under a former name, and names it by its
> *current* identifiers — `Lichtbild_Repository`, `lichtbild.css`, `lichtbild_gallery` — because the
> alternative is a changelog that cannot be grepped against the code it documents. So read a file
> or class name in an old entry as "the thing now called that", not as the string that was on disk
> at the time. Nothing else about those entries was altered: the dates, the counts, the measured
> numbers and the reasoning are as they were written.

## [26.8.27] - 2026-08-30

### Added
- **A gallery can now be created directly from the Gallery block.** Name it, choose images in
  the Media Library, and the block creates one canonical draft gallery and stores only its ID.
  The gallery remains reusable and editable from the Lichtbild screen; failed record writes are
  cleaned up rather than leaving empty drafts behind.
- **Galleries can opt into current Media Library titles, captions and alt text.** The existing
  per-gallery values remain the default and the fallback, so migrated galleries do not change
  until the setting is enabled and switching it off is lossless.
- **A WordPress Playground blueprint now powers a no-install demo** from the plugin listing.

### Fixed
- **An un-migrated Envira site no longer counts the posts table on every predicate call.**
  `Lichtbild_Settings::initialise()` resolves an absent schema option from the rows, and on a
  site continuing an Envira installation that option stays absent until the migration runs —
  so every `has_migrated()` and `slug_scheme()` call re-ran the `COUNT(*)`: measured at 17
  uncached queries for one minimal request, in the configuration the plugin documents as
  "install beside Envira and change nothing". Introduced in 26.8.25 by the uninstall fix.
  `initialise()` now runs once per instance; the suite counts `get_var()` calls across five
  predicate calls and mutation `MEMO1` proves the count is wired to the code.
- **The gallery edit screen primes its attachments in one call.** Each row read the attachment,
  its meta and its terms unprimed — three queries per image, fifteen hundred on the 504-image
  gallery — while the album editor's twin already primed. Asserted by call shape, as the
  lightbox endpoint's priming is; mutation `PRIME2`.

### Changed
- Fixed-column galleries now cap themselves at three columns on narrow screens and two on
  phones, without increasing galleries configured for only one or two columns.
- The wordpress.org description now leads with visitor and site-owner benefits, the banner has
  a user-facing tagline, WordPress 7.1 is recorded as tested, and the obsolete pre-rename editor
  screenshot has been removed.
- `slug_scheme()` has its docblock back on the right method; `has_migrated()`'s docblock says
  where its "never inferred from rows" rule has an exception; `migrate()`'s return shape names
  `warnings`; the `'""'` alt sentinel in `Lichtbild_Item::alt()` says that it has not been
  observed on any record here and why it stays; `lichtbild.js` drops a `closest` feature guard
  that protected one of five unguarded calls.

## [26.8.26] - 2026-08-28

The first release cut after anyone installed this plugin the way a stranger does. That path had
been written in 26.8.18, covered by the suite and by a stripped local WordPress, and never once
walked end to end; walking it found one defect, in the step no test could reach.

### Fixed
- **Gallery, album and tag permalinks no longer 404 on a fresh install.** Rewrite rules are
  generated from the post types registered at flush time and then stored, so a site whose rules
  were built before this plugin existed carries no `/gallery/` rule and nothing regenerates one.
  Every gallery permalink answered 404 until someone happened to re-save Settings -> Permalinks,
  which reads as the plugin being broken. Measured on a clean WordPress 7.1 with the published
  26.8.25 installed from the directory: 94 rewrite rules, none of them Lichtbild's, and a 404 on
  a gallery whose shortcode rendered perfectly on a page in the same run. The plugin now
  invalidates the rules on activation, and the same install answers 200 with 22 rules of its own.

## [26.8.25] - 2026-08-22

Answers an independent read-only review of the whole codebase at `511df7e`. Two blockers, both
confirmed by measurement rather than by reading, and every warning it raised.

### Fixed
- **Deleting the plugin and installing it again no longer hides the galleries it kept.**
  `uninstall.php` removes Lichtbild's options while deliberately retaining the migrated posts and
  Envira's rollback records — settings are a plugin's to delete, photographs are not. With the
  schema gone, `Lichtbild_Settings::initialise()` found Envira's retained meta, concluded the site
  had never migrated, and registered `envira` against rows named `lichtbild_gallery`. Measured on
  a full copy of the live site: 52 galleries in the table, **0** under the registered type, and
  the gallery permalink returning a 404 byte-identical to a deliberately bogus slug. It now
  rebuilds the schema from the rows themselves, before the early return that had made the wrong
  answer permanent on the first request that asked.

- **Album membership asks about the gallery, not only about the album.** The save guard
  authorised the album; `collect_items()` then stored any gallery ID the repository could resolve,
  so somebody who could edit one album could POST another author's private or draft gallery and
  have the editor render its title and cover thumbnail back to them. `handle_covers()` had checked
  both all along. The picker, the save and the render of an already-stored member now all ask.

- **The gallery and album pickers are capability-filtered.** They list `private`, `draft` and
  `pending` objects by design, so an unfiltered listing handed every author the titles of every
  other author's unpublished galleries. Block pickers require `read_post`, which maps to `read`
  for a published post; the album editor requires `edit_post`, because album membership
  republishes a gallery's cover and title.

- **The pagination failure message is no longer empty.** `wp_localize_script()` is given `i18n`
  and the script read `LichtbildSettings.strings`, so every lookup fell through to `''` — for as
  long as the message has existed. Neither language errors on that.

- **A failed PhotoSwipe import no longer kills every later click.** The rejected module promise
  was cached forever while the click handler had already suppressed native navigation, so one
  transient failure made every image inert until a reload. The cache is cleared on rejection, and
  a click that cannot open the lightbox follows the link instead.

- **The tag filter announces which tag is applied.** `is-current` is a CSS hook and says nothing
  to a screen reader; the buttons now carry `aria-pressed`, which the adjacent pagination has
  exposed as `aria-current` since it was written.

- **`tools/deploy.sh compare` fails when the site changed.** It printed `changed 1` beside the
  URL and exited **0**, so the release ceremony's verification step could not go red. It now
  returns non-zero on a changed body, a changed status or a malformed row, and compares the URL
  sets in both directions — a page that only the *after* capture had was previously invisible.

- **`tools/build-zip.sh` no longer requires `rsync`**, which it has not used since the build
  became `git archive | tar`. The only surviving mentions explain why it is not used.

### Changed
- **The migration reports auxiliary writes that fail, and still advances the schema.** The
  standalone-setting copy and the Yoast carry-over were not read back, so a targeted failure was
  silent and the success notice claimed a copied-key count that never landed. Both are verified
  now and reported as **warnings** beside the success message. Deliberately not as errors: by
  that point the rows have moved, and a site whose schema says otherwise registers `envira` and
  finds none of them — the state measured above. Losing a Yoast title is a bad day; hiding every
  gallery is a broken site.

- The `readme.txt` upgrade notice for 26.8.22 was 379 characters against Plugin Check's limit of
  300, so the archive had been shipping one warning since that release while `AGENTS.md` claimed
  zero. Trimmed to 284; all four notices measured, not just the newest.

### Added
- `tests/deploy-compare-test.sh`, six cases, in CI. The first exists so the other five mean
  something: a gate that refuses two identical captures is as useless as one that refuses nothing.
- Nine checks and six mutations covering the above; 247 checks and 215 mutations in total, all
  killed by their predicted check.

## [26.8.24] - 2026-08-22

Answers the third wordpress.org review round (`R ❗TRM atelier/tstone1/14Aug26/T4`, 2026-08-22),
which pended the submission over the plugin's name without reading the code.

### Changed
- **Renamed to Lichtbild Gallery**, slug `lichtbild-gallery`, everywhere: 3,692 identifiers
  across 65 files, 27 of which were also renamed on disk. The directory's own search returns
  three published plugins leading with the former word, so the finding was correct and the name
  changed rather than being defended; the same query returns nothing for the new one.

  Verified by a control rather than by reading: the count of `envira` occurrences was **1270
  before and 1270 after**, so nothing in the compatibility layer was caught up in the pass. The
  eight remaining occurrences of the old name are deliberate — two wordpress.org review-thread
  IDs, the separate `tstone-1/atelier-history` repository, and the surrendered slug named in
  prose.

  **Nothing a visitor can see changed.** `/envira/`, `/envira_album/` and `/envira-tag/` stay
  pinned where they are, `[envira-gallery]` keeps working, and the 49 posts embedding galleries
  were not touched.

- **The text domain is `lichtbild-gallery`, not the identifier prefix.** 237 of the 299 quoted
  `atelier` literals were the text-domain argument of an i18n call and became the slug; the other
  62 are the asset handle, the nonce action, the settings group, the `plugins_loaded` callback
  and the migrated-record array key, and became `lichtbild`.

- Post types, meta keys and options move with the name: `atelier_gallery` → `lichtbild_gallery`,
  `_atelier_gallery` → `_lichtbild_gallery`, `atelier_schema_version` →
  `lichtbild_schema_version`. On a live site that is a data migration, run using **only
  already-proven code** exactly as 26.8.16 was: roll back to Envira's types with the existing
  rollback, swap the plugin, then re-run the existing migration against the new names.

- `Plugin URI` and `Report-Msgid-Bugs-To` now name `github.com/tstone-1/lichtbild-gallery`. The
  review email lists the plugin's URLs among the places the flagged term may not appear.

- **One check of 238 went red, and it was right to.** `the shortcode registry follows the
  takeover setting` compares against a literal array in `sort()` order, and `lichtbild` sorts
  *after* `envira` where the former name sorted before — the mirror of the failure the same check
  produced at 26.8.16. All 209 mutations still kill.

- The German catalogue was rebuilt: `languages/lichtbild-gallery-de_DE.mo` carried 55 strings
  under the old identity, which gettext would have missed on entirely, rendering the German admin
  in English with nothing logged.

## [26.8.23] - 2026-08-15

Answers the second wordpress.org review round (`R atelier/tstone1/14Aug26/T2`, 2026-08-15).

### Changed
- **Envira's shortcode names are claimed only on a site with an Envira history.** The takeover
  mode answers "is Envira running?", and on a site that never had Envira the answer is "no, so
  take over" — which registered `[envira-gallery]` and `[envira-album]` on a site where no post
  contains either. `Lichtbild_Settings::claims_envira_shortcodes()` requires the takeover mode
  *and* the recorded slug scheme, the same observation the URL paths are pinned to, so a site
  continuing an Envira installation is unaffected and a fresh install registers only its own
  two tags. `Lichtbild_Shortcode::register_shortcodes()` and
  `Lichtbild_Assets::maybe_enqueue_early()` both read the new predicate, so the registry and the
  early asset scan cannot drift apart.

### Removed
- **The raw submission is no longer passed to the `lichtbild_config_sanitize` filter.** It was
  there as context, and it handed every callback on the hook an unsanitised `$_POST` array —
  a sanitising function offering a way around itself. Everything a callback legitimately needs
  is in the sanitised record it already receives. The filter now takes one argument.

## [26.8.22] - 2026-08-14

Answers the wordpress.org pre-review that pended the submission on 2026-08-14.

### Removed
- **Per-gallery Custom CSS.** The Plugin Directory does not permit a plugin to store and print
  arbitrary CSS entered through its own UI, so the setting, the textarea, the conversion of
  Envira's `custom_css`, the sanitiser and the inline `<style>` element were all removed —
  `Lichtbild_Config::defaults()` is now twenty-five settings, and `Lichtbild_Config::css()`,
  `Lichtbild_Config::rewrite_css()` and `Lichtbild_Gallery::custom_css()` are gone.

  **The upgrade deletes no CSS**, and the new `tools/export-custom-css.py` prints what a site
  still holds — selectors already rewritten — ready to paste into Appearance -> Customize ->
  Additional CSS. The ids do not change, so the same rules keep matching the same elements; only
  the delivery moves.

  **Two records can hold it, and only one of them is permanent.** Envira's `_eg_gallery_data` is
  untouched by anything Lichtbild does, so its copy survives indefinitely. But on a migrated site
  the record Lichtbild renders from is `_lichtbild_gallery`, and Lichtbild's own editor wrote CSS
  there — so a gallery edited after the migration has its *current* value only in that record,
  and **saving that gallery under 26.8.22 rewrites the record through the new allowlist and drops
  it**. Export before saving galleries. The exporter reads both records, prefers the one the site
  is actually rendering, and exits non-zero after printing both if they disagree.
- **The bundled German translation, from the distributed package.** Plugins on wordpress.org get
  their translations from translate.wordpress.org. The catalogue stays in the repository and is
  still installed on sites that deploy from it; it is excluded from the release zip.
- **`load_plugin_textdomain()`.** Unnecessary for anything the directory serves since WordPress
  4.6, and the bundled catalogue it existed for is no longer in the package. `Domain Path`
  remains, so a copy installed from elsewhere still loads a catalogue in `languages/`.

### Added
- `.gitattributes`, pinning the working tree to LF. Git for Windows defaults to CRLF checkouts,
  which made every multi-line entry in `tests/mutations.php` match nothing — the harness reported
  `BROKEN` rather than a false pass, but most of the suite's falsifiability had stopped running
  on that machine. The full pass now completes at 206 mutations, 206 killed.

## [26.8.21] - 2026-08-09

### Fixed
- **A gallery renders on its own permalink on a site that never had Envira.** It answered HTTP
  200 with the post title and none of its photographs. `Lichtbild_Settings::standalone()` falls
  back to Envira's own option when Lichtbild's is unset, which is right for a site migrating from
  Envira -- the switch is meant to be invisible, and the migration copies the value across. A
  site with no Envira history has neither: no option to read and no migration to have copied
  one, so it inherited Envira's default of off. The fallback is now consulted only where it
  means something, and a site on Lichtbild's own storage from the start renders the gallery.

  Found by installing the plugin on a clean WordPress and making a gallery, which nothing and
  nobody had done before -- every fixture in the suite has an Envira history, so the whole
  suite was blind to it. Pinned now by a check that goes red when the fix is reverted, with the
  Envira-history leg as its control.

## [26.8.20] - 2026-08-09

### Fixed
- **The selected button in the tag filter is legible again.** It was painted in the same colour
  as its own background, so the label of whichever tag was currently applied could not be read at
  all -- a contrast ratio of 1:1. Two rules had accumulated for the same selector, one resolving
  to white on white and the other, which won, to dark on dark; `currentColor` cannot fill a
  button and colour its label at once, because both resolve to the same value. The fill and the
  label are now stated as an explicit pair, exposed as the `--lichtbild-tag-fill` and
  `--lichtbild-tag-label` custom properties so a theme can change them. Measured contrast is
  17.4:1.

  No gallery on a site that has not switched the tag filter on was affected.

## [26.8.19] - 2026-08-08

### Fixed
- **A gallery on a cached site keeps working.** The two front-end AJAX endpoints — pagination,
  tag filtering, and the lightbox on a gallery whose images span pages — refused any request
  whose nonce had expired. A nonce is a fact about *when a page was generated* and stops being
  valid twelve hours later, while a full-page cache serves pages generated days ago, so on a
  cached site the nonce a logged-out visitor holds is routinely stale before they click
  anything. They are now verified and never refused on: both endpoints are reads that change no
  state and return JSON no cross-origin script can read, and their authorization has always been
  the visibility check, which is untouched and still refuses a draft, private or
  password-protected gallery. The album cover endpoint, which is an admin screen and never
  cached, still refuses a missing or wrong nonce.

- **Deep links shared before the rename open the image they name again.** The lightbox writes a
  fragment identifying the gallery and the photograph, and people paste those into messages and
  bookmark them; links made before 26.8.16 carry the former prefix and cannot be recalled. They
  are resolved once more, and upgraded in the address bar as soon as the lightbox they opened
  writes its own — reading accepts both spellings, writing only ever produces the current one.

### Changed
- The test harness's `check_ajax_referer()` verifies the nonce instead of returning `true`
  unconditionally. Every check that reached an endpoint through it had been passing without ever
  setting a nonce, so the suite could not distinguish a handler that refuses a bad one from a
  handler that never looks — which is the exact distinction the two endpoints above now sit on
  opposite sides of. Four existing checks failed the moment it was corrected.

## [26.8.18] - 2026-08-08

### Fixed
- **A site that never had Envira now starts on Lichtbild's own storage**, which is what makes the
  plugin honestly installable by someone else. Schema 1 does not mean "new" — it means *still on
  Envira's storage*, sensible for a site mid-migration and nonsensical for one that has never had
  Envira. Left at 1, a fresh install registered post types literally named `envira`,
  `envira_album` and `envira-tag` — visible in admin URLs, body classes and sitemap filenames —
  while every editor screen refused to work, telling the owner their gallery was "still stored in
  Envira Gallery's format" on a site where no such record has ever existed. The only route to a
  first gallery was to run a migration of zero rows.

  Fixing the URL paths in 26.8.17 addressed only half of that: the paths stopped carrying
  Envira's name, the type names did not.

- **Rollback is refused on a site with no Envira history.** That hazard is created by the fix
  above: a fresh install is now already migrated, so an unguarded rollback would offer to move
  the owner's galleries onto post types named after a plugin they never installed. Refused in the
  handler rather than merely hidden on the screen, for the same reason the album cover picker is
  — markup is a suggestion.

### Changed
- The slug scheme and the storage schema are decided from **one** observation and written
  together. Deriving them separately was wrong in a way worth recording: schema initialisation
  ran first and marked a fresh site migrated, the slug scheme then asked `has_migrated()`, saw
  `true`, and concluded the site had migrated *from Envira* — so a brand-new install got Envira's
  URL paths, the precise opposite of the intent.

## [26.8.17] - 2026-08-08

### Changed
- **URL paths default to generic on a site with no Envira history** — `/gallery/`, `/album/`,
  `/gallery-tag/`. A site that already publishes Envira's paths keeps them, so nothing indexed
  moves. The answer is recorded once and never re-derived: a site's Envira history changes when
  its old records are deleted, and its published URLs must not change with it.
- `readme.txt`, so the plugin can be submitted to the WordPress plugin directory at all, and the
  one `translators:` comment the official Plugin Check asked for. It now passes that check with
  **zero errors** against the 40 files that actually ship.

### Fixed
- **Converted custom CSS targeting a gallery's wrapper matched no element.** The converter
  produced `#lichtbild-wrap-<id>` while the renderer emits `id="lichtbild-<id>-wrap"`. Both spellings
  were pinned by checks, each against the code that produced it, so neither could fail. The
  editor's help text taught the broken form too. No stored rule on this site used it.
- **Bundled translations never loaded on WordPress 6.x.** The header promises 6.0, but every 6.x
  just-in-time loader reads only from `WP_LANG_DIR`, so a self-hosted copy rendered every string
  in English with nothing logged. Loaded on `init`, which is late enough not to warn on 6.7+.
- **A failed pagination request said nothing.** `is-error` matched no CSS rule and the
  `loadFailed` string was localised and never read, so a network blip left the previous page in
  place with no feedback. It now shows a message in a polite live region.
- `languages/lichtbild-gallery.pot` named the plugin's former identity in `Report-Msgid-Bugs-To`, because
  `wp i18n make-pot` derives that from the containing directory name.
- The German catalogue carried two `Plural-Forms` headers, the second an unfilled template
  placeholder; a parser lets the later one win.

## [26.8.16] - 2026-08-08

### Changed
- **Renamed from Tivira to Lichtbild**, everywhere: 3,311 identifiers across 66 files, 27 of which
  were also renamed on disk. The old name was one letter from Envira's in the same product
  category, which is a trademark exposure that a disclaimer cannot cure, and it was cheaper to
  settle before the repository became public than after.

  The rename was mechanical — three case-sensitive substitutions and `git mv` — and verified by a
  control rather than by reading it: the count of `envira` occurrences was **892 before and 892
  after**, so nothing in the compatibility layer was caught up in it. That control is the whole
  reason the change is trustworthy, because `envira` and the old name share four letters and a
  careless pattern would have eaten both.

  **One check of 220 went red, and it was right to.** `the shortcode registry follows the takeover
  setting` compares against a literal array in `sort()` order — and `lichtbild` sorts *before*
  `envira` where the old name sorted *after*. The two orders had coincided under the old name, so
  nothing had ever forced the distinction. A literal encoding a derived property is precisely what
  a rename cannot update, and the failure was the suite working. All 197 mutations still kill.

  Nothing a visitor can see changed. `/envira/`, `/envira_album/` and `/envira-tag/` stay pinned
  exactly where they are, `[envira-gallery]` keeps working, and the 49 posts embedding galleries
  were not touched.

- Post types, meta keys and options move with the name: `tivira_gallery` → `lichtbild_gallery`,
  `_tivira_gallery` → `_lichtbild_gallery`, `tivira_schema_version` → `lichtbild_schema_version`. On a
  live site that is a data migration, and it was run using **only already-proven code**: roll back
  to Envira's types with the existing rollback, swap the plugin, then re-run the existing
  migration against the new names. No new migration path was written, so nothing new could be
  wrong. The precondition — that no gallery had been edited since the migration, which is what
  makes rollback lossless — was checked against the live database rather than assumed.

### Added
- `LICENSE` (GPL-2.0-or-later), a `README.md`, and an explicit statement in both that this is not
  affiliated with or endorsed by Envira Gallery or Awesome Motive. It names Envira only to say
  what it reads and what it replaces.

### Fixed
- `tools/deploy.sh` takes the host and account from the environment or a gitignored
  `tools/deploy.env` instead of carrying them as literals, so the repository no longer publishes
  the site's FTP hostname and username.

## [26.8.15] - 2026-08-08

### Fixed
- Galleries and albums are centred in the content column again. `.lichtbild-wrap` and
  `.lichtbild-album` set `margin: 0 0 1.5em`, which has the same specificity as the theme rule that
  centres content blocks — Twenty Twenty's `margin-left/right: auto` on `.entry-content > *` —
  and a plugin stylesheet is always the later one, so ours silently replaced it. Every gallery on
  the site sat flush left with the rest of the column empty beside it: **612px of it at 1200px
  wide**. Both rules now say `margin: 0 auto 1.5em`.

  This was a regression introduced by the switchover, not a pre-existing look. The same gallery
  rendered under real Envira on a local copy of the site reports `margin-left: 306px` where
  Lichtbild reported `0px`, at the same 580px width — so the width was never the difference. It was
  invisible to all 220 checks for the reason recorded at 26.8.11: the markup never changed, and
  CSS has no output to assert on.

  On albums it also explains why the covers had looked half-fixed since 26.8.11.
  `justify-content: center` centres the covers *within* the box, and the box itself was the thing
  in the wrong place.

## [26.8.14] - 2026-08-08

### Added
- Two block-editor blocks, `lichtbild/gallery` and `lichtbild/album`. Each is a picker over the
  galleries or albums already on the site plus a live preview of the real front-end markup,
  laid out by the real front-end stylesheet. Both are dynamic: nothing is written into the post
  but the block comment and an id, so a gallery edited on its own screen stays current
  everywhere it is embedded. `[envira-gallery]` and `[lichtbild-gallery]` keep working unchanged.
- `Lichtbild_Block`, which renders none of it: both callbacks hand to `Lichtbild_Shortcode`, so the
  fifth publishing path inherits the visibility rule instead of carrying a fifth copy of it.
- `Lichtbild_Repository::gallery_choices()` and `::album_choices()`, the picker's list — routed
  through the reader, so Envira's defaults pseudo-gallery is excluded, and asking for every
  status, so a gallery prepared as a draft is offered. `Lichtbild_Album_Editor` now shares it
  rather than holding its own copy.
- `tests/blocks-js-test.js` runs the editor script against a mocked `wp` and asserts what it
  registered — 12 checks, in CI. It is the only JavaScript tested here, because it is the only
  file whose failure is total and silent: a throw anywhere in it means both blocks are missing
  from the inserter while the editor page still serves 200 with the script, the picker data and
  the block definitions all present.
- `tests/live-block.php`, 11 checks against a real WordPress: core accepts both `block.json`
  files, a serialised block renders byte-identically to the shortcode, the id survives
  serialisation as a number, and the editor stylesheet resolves its dependency in a real
  `WP_Styles`.
- German for all of it. The catalogue is now generated with `wp i18n make-pot`, so it covers
  the `block.json` metadata (`block title`, `block description`, `block keyword`) and the
  plugin header as well as the PHP — **207 strings, none left untranslated**.

### Fixed
- The picker's choices are built on `enqueue_block_editor_assets` rather than on `init`, so they
  are read by the one screen that uses them instead of on every request. Building them reads each
  gallery row through the reader — **111 queries and 11ms on a cold cache**, measured on a real
  WordPress — and on `init` that was paid by every front-end page view for data no visitor ever
  sees. Caught by measuring before deploying rather than by a check; there is a check now
  (`registering the blocks reads nothing`) and a mutation that reintroduces it.

- `tools/live-urls.py` adds the front page, so the verification surface is 160 rather than 159.
  On this site the front page is the blog index: it renders the latest posts' content in full, so
  it draws ten galleries and 132 figures — more gallery markup than any permalink — and no query
  in the enumerator could reach it. Nine deploys were verified against a surface excluding the
  site's most-visited page.

### Changed
- Front-end assets are registered on `init` rather than on `wp_enqueue_scripts`. Nothing is
  enqueued any earlier; the hook simply also fires in the admin, which is where the block's
  editor stylesheet needs the `lichtbild` handle to exist. Registered on `wp_enqueue_scripts` the
  dependency is dropped silently and the preview renders unstyled.
- `tests/i18n-test.php` also reads each `block.json` and the plugin header, and fails a block
  whose metadata declares no `textdomain` — without which WordPress translates none of it.

## [26.8.13] - 2026-08-08

### Added
- `uninstall.php` removes Lichtbild's three options and the migration screen's per-user transient
  when the plugin is deleted. It deliberately leaves the gallery and album records, Envira's
  originals, and Envira's own standalone option alone: those are content, not settings, and
  deleting them would turn "I removed the plugin" into "I destroyed 53 galleries" with no undo.
  Verified against a real WordPress on a migrated site — options gone, 52 galleries and 51
  records untouched — with the control that a deliberately destructive version reports 0.
- German. `languages/lichtbild-gallery.pot` and a complete `de_DE` catalogue — **184 strings, none left
  untranslated**. About 28 are visitor-facing and had been rendering in English on a `lang="de"`
  site since the switchover: the EXIF panel (*Kamera, Blende, Brennweite, Belichtungszeit,
  Aufnahmedatum*), the lightbox controls (*Schließen, Weiter, Zurück, Vergrößern, Teilen,
  Herunterladen, Link kopieren*) and the tag bar (*Nach Schlagwort filtern*). The rest is the
  admin, which was English throughout.
- `tests/i18n-test.php` asserts every translatable string in the source has a translation, that
  the catalogues hold no strings the source no longer has, and that a compiled `.mo` exists at
  all — a `.po` without one is a translation nobody ever sees. It extracts with PHP's tokenizer
  rather than a regex, because 32 of the strings span several lines and a line-oriented match
  drops every one of them silently. Wired into CI.

### Fixed
- `tools/deploy.sh` passes `--ftp-create-dirs`, so a new subdirectory in the upload set works.
  `languages/` did not exist on the server, and without it the first chunk fails with a bare
  550 that reads like a permissions problem.

## [26.8.12] - 2026-08-08

### Fixed
- Album covers rendered about 137px wide on a phone instead of filling the width. 26.8.11 moved
  the cover grid from grid to flex and left the `max-width: 700px` rule setting
  `grid-template-columns`, which is inert on a flex container — so the desktop basis of one third
  of the width stayed in force at every size. `flex: 1 1 190px` is the equivalent of the
  `repeat( auto-fit, minmax( 190px, 1fr ) )` it replaced. Measured in a real browser at 390px:
  216px per cover before 26.8.11, 137px in 26.8.11, 216px again now.

## [26.8.11] - 2026-08-08

Two things a person noticed on the live site, both real, and neither visible to any check that
existed.

### Fixed
- **The lightbox could not fill the viewport.** PhotoSwipe computes its `fit` zoom as
  `Math.min( 1, viewport / natural )` — capped at 1, so it never scales an image up — and the
  anchor declared the configured lightbox size. On this site `large` is 1024px and exists on
  1,563 of 2,243 attachments, so most photographs opened at 1024px however large the display.
  The anchor now declares the full-size dimensions and carries a `data-pswp-srcset`, so
  PhotoSwipe can fill the viewport while the browser still fetches the smallest candidate that
  covers what is on screen. Without the srcset this would be a bandwidth regression for
  everyone; with it, a phone downloads less than before.
- **Album covers no longer leave a hole, and their labels are centred again.** An album with
  fewer members than columns put them all at the left of a `1fr` grid — `American Football` has
  two members in a three-column layout. The cover grid is flex with `justify-content: center`
  now, so an incomplete row centres and a full row is unchanged. Separately, Envira's own
  stylesheet centred the album title, caption and image count unconditionally, and Lichtbild had
  been inheriting the theme's left alignment since the switchover — a faithfulness regression
  nothing recorded.

### Added
- `lightbox declares the full-size dimensions` and `lightbox srcset reaches the declared width`,
  both stated as properties rather than by recomputing what the renderer did, with mutations
  `L1` and `L2`. Taking the suite to 209 checks and 189 mutations.

## [26.8.10] - 2026-08-08

Test harness and CI. No gallery, album, migration or rendering logic changed; as in 26.8.9 the
one production file touched is `lichtbild-gallery.php`, for the version constant, which is the `?ver=` on
every front-end asset.

### Added
- `tests/make-fixture.php` builds a synthetic fixture — 10 galleries, 3 albums, 161 items —
  carrying the same shapes as the live site's, with no database and no credentials. The
  generator is committed and its output is not, because a generated artifact checked in beside
  its generator drifts silently. It is deterministic: byte-identical across repeated runs and
  across PHP 8.1, 8.2 and 8.5.
- `php tests/mutations.php --fixture=<path>` runs the mutation pass against another corpus. A
  path that cannot be read is a hard error rather than a fall-back to the default, which would
  make a run look like it had proved the other corpus while proving nothing about it.
- `.github/workflows/tests.yml` — the suite on PHP 8.1 to 8.5, and the full mutation pass on
  8.2. The workflow rebuilds the fixture twice and compares the two with `cmp`, and asserts
  with `git diff --exit-code` that the mutation harness restored everything it edited.

### Fixed
- The tag-filter block selected its gallery by post ID (`951`), a fact about one database
  written into the suite. Against any other fixture the ID resolved to nothing, the block was
  skipped, and eleven checks reported `[EMPTY]`. It now picks the gallery carrying the most
  distinct tags.
- `filtered page count follows the filter` divided by `per_page()`, which is 0 when pagination
  is off, so the suite died with `DivisionByZeroError` and reported nothing rather than failing
  one check. The slice below it already branched on `has_pagination()`.
- Four per-item checks asserted a srcset, an intrinsic size, lightbox dimensions and a
  non-original grid src for every item — true of all 2,264 live items and false by design for
  one whose attachment has been deleted, which the renderer deliberately keeps in the grid as a
  plain link and out of the lightbox. Deleting one photograph from the live site would have
  turned all four red on the next export. They are guarded on the item being measurable now,
  with populations on the real corpus unchanged at 529, and the rule they used to imply is
  asserted once per gallery as `every unmeasurable item was kept out of the lightbox`. Mutation
  `B96` pins it, and the synthetic corpus carries the only row that makes it non-vacuous.
- One PHP warning anywhere in the suite silently re-scored every check after it. PHP's CLI
  writes diagnostics to STDOUT, so a warning is body output, `headers_sent()` becomes true, and
  the three checks exercising an AJAX endpoint died on "Cannot modify header information"
  instead of on their own merits — turning red together under a dozen unrelated mutations and
  inflating the red sets the coverage inventory is built from. Diagnostics go to STDERR now.
  Measured: 648 reds across the real corpus before, 636 after, and not one check lost any
  pinning.
- `a cover outside its gallery is refused` passed without an album having been read at all.
  `Lichtbild_Album::cover_id()` answered 0 for a gallery the album does not contain, which is
  indistinguishable from a cover that was correctly refused, so the check was satisfied by the
  empty album the suite falls back to when the reader returns nothing. The checks read the
  member record now, where an absent member is null. Measured on `B95`: before, only the control
  went red; now both do.
- Three round-trip checks compared two renders that could both be empty, so a save was asserted
  to be a no-op without anything having been rendered. Measured before the fix: `B93` did not
  turn `a save round-trips the gallery byte for byte` red and `B95` did not turn the album one
  red. Both do now. The migration equivalence checks were never vulnerable — their two sides come
  from different readers, so one going empty makes them differ rather than agree.

### Removed
- `Lichtbild_Album::cover_id()`, `::caption()` and the private `item()` they shared. The renderer
  was moved off them in 26.8.5 because looking a member up by gallery ID returns the first match
  for every position, and nothing in the plugin has called them since — only the suite and one
  mutation, which is not a reason for production code to exist.

### Measured
- Both corpora report 207 checks, kill 187 of 187 mutations by the predicted check, and pin the
  same **204 distinct checks** — the sets are identical, with none pinned by only one of them.
  Coverage equivalence is a measurement here, not an assertion; the first two numbers alone are
  satisfied by a corpus that kills everything for weak reasons.

## [26.8.9] - Unreleased

Test harness and documentation. No gallery, album, migration or rendering logic changed.

The one production file touched is `lichtbild-gallery.php`, for the version constant — and that is not
nothing: `LICHTBILD_VERSION` is the `?ver=` on every front-end stylesheet and script, so deploying
this changes those URLs and browsers refetch otherwise identical assets. That is the intended
mechanism (the deploy records use `ver=` as the proof a deploy landed), but it is a change to
what a live request returns, and "nothing is affected" would be too absolute.

### Added
- `tests/mutations.php` reports `VANISHED` when the set of check names a mutated run reports
  differs from the baseline set, in either direction, naming what left and what appeared. A check
  behind an assert-and-continue short-circuit does not fail when the short-circuit fires — it
  disappears, and the report is quietly one line shorter. Found ten mutations with that defect
  where reading the code had found one.
- `php tests/mutations.php --names` prints the full set of checks a mutation turned red rather
  than `(+N more)`, which is what a coverage inventory needs, plus per-check population deltas.
  A check can keep its row while losing most of what it examined, and the name set cannot see
  that; the deltas are reported rather than treated as failures, because a mutation that
  legitimately changes what renders also changes populations.
- Thirteen checks are now declared with `Checks::expect()`, so they go `[EMPTY]` and red instead
  of vanishing. Nine are the per-item geometry and attribute checks.
- `empty gallery renders nothing` had never executed — every gallery on this site has items. It
  has a synthetic empty gallery now, and mutation `B91` pins it.
- The migration screen's three states are asserted: `render_mixed()`, `render_migrated()` and
  `render_pending()` all ran during the suite with nothing checked about any of them. The mixed
  case asserts the rollback is offered while the schema option still says unmigrated, which is
  the state an interrupted migration leaves and the one the button exists for.
- Mutations `B90`–`B95` and `S3`–`S5` for the above, taking the file to 186.

### Fixed
- `tests/render-test.php` handed the renderer whatever the repository returned, without a null
  guard, at 27 call sites (5 gallery renders, 12 album renders, 10 chained album reads) — so a
  mutation making a row unreachable fataled the suite
  before it reported anything, and "the reader can no longer find this row" could not be
  expressed at all. Guarded by `lichtbild_render_found()`, `lichtbild_render_album_found()` and
  `lichtbild_album_found()`; `B93`, `B94` and `B95` are the mutations that were impossible before.
- `image has src` removed. The renderer skips an item with no src, so a figure without one cannot
  exist and the assertion could not fail. Replaced by `every renderable item became a figure`,
  which compares figures against items with a usable src, plus a synthetic pair where the two
  differ.

## [26.8.8] - 2026-08-07

### Changed
- A failed rename now says why in the PHP error log, carrying the table, the column, the value
  and MySQL's own message. It was previously reported to one admin screen and a five-minute
  transient, so closing the tab left the row counts as the only evidence a migration had
  half-failed — recoverable, but not diagnosable.
- The migration screen renders several errors as a list rather than one run-together paragraph,
  which is the moment an operator most needs to read carefully.
- The success notice reports the album records converted and the Yoast settings carried. Both
  were already computed and shown to nobody, and both are the regression classes that made the
  26.8.5 and 26.8.6 work necessary.

## [26.8.7] - 2026-08-07

### Fixed
- Both public AJAX endpoints returned HTTP 500 on a request whose `tag` parameter was an array
  (`tag[]=x`). `sanitize_title()` is handed the value directly and its first act is a
  `preg_match()`, so a non-string was an uncaught `TypeError` that any logged-out visitor could
  repeat. A non-string now reads as no tag, the same as an absent one.
- The Galleries list table counted the `_lichtbild_gallery` record directly, so it showed 0 images
  for every gallery on an un-migrated or rolled-back site, and post-migration counted rows the
  front end does not render. It goes through the repository now, like the Albums column.
- Submitted form values that were not strings were cast rather than rejected, which stored the
  literal word `Array` as an image title, caption or tag. Non-string fields are now treated as
  unsubmitted.
- `data-lichtbild-tags` was emitted twice per item, on the anchor and on the figure. Only the
  figure copy is ever read.

### Changed
- Both editors now extend a shared `Lichtbild_Metabox_Editor`, which owns the save-guard chain, the
  ordered-row collection, the list-table column insert and the un-migrated notice. The two had
  been near-identical copies, and the list-column defect above existed because a fix had been
  applied to one of them.
- `Lichtbild_Editor::collect_items()` no longer writes taxonomy terms; the gallery's tags are
  written from `save()` after the record has been stored.
- `Lichtbild_Gallery::get()` removed — it had no callers.

## [26.8.6] - 2026-08-07

### Fixed
- The shortcode was the fourth path that could publish gallery content and the only one that did
  not consult `Lichtbild_Repository::is_viewable()`. A protected gallery embedded in a public post
  now refuses; the two non-public embeds on this site are unaffected.
- `tools/live-urls.py` enumerated the URL surface under Envira's pre-migration type names, so
  after the migration it returned 49 URLs instead of 159 — and every downstream verification step
  would still have reported success. An empty result from either query is now a hard error, and
  the URL path segment is no longer derived from the post type name.

### Added
- `Lichtbild_Migration::carry_seo_settings()` mirrors Yoast's 31 `envira*` title and indexing keys
  onto the new type names during a migration. Keys are added, never replaced or removed, and the
  suffix is matched exactly.

## [26.8.5] - 2026-08-07

### Fixed
- `migrate()` and `rollback()` discarded `update_option()`'s return, so a migration could rename
  every row and fail to switch authority — leaving the site looking for its galleries under names
  they no longer had. The schema option is now confirmed by reading it back.
- The album cover endpoint checked `edit_post` on the album and then returned any gallery it was
  handed, and never checked that the album ID was an album. Both objects are now authorised.
- Captions were unslashed twice, corroding a backslash on every write, in both editors and the
  migration.
- A gallery listed twice in one album rendered the first entry's cover and caption for both.
- The album list column counted only `_lichtbild_album`, showing 0 on an un-migrated site.
- The gallery picker offered Envira's stored-defaults record, which the migration renames like
  any other row.

## [26.8.4] - 2026-08-07

### Added
- `Lichtbild_Album_Config` gives albums a normalised record of their own, so the reader no longer
  contains Envira knowledge for albums either. `display_titles` and `display_image_count` are now
  honoured; both were stored and previously ignored.
- `Lichtbild_Album_Editor` edits album members, order, cover and settings. A cover must be one of
  the member gallery's own images, enforced on save rather than only offered by the chooser.

### Removed
- The album cover no longer falls back through `cover`, `thumb_id` and `id`. `id` in an album
  entry is the gallery's ID, never an attachment's, so that chain could only ever name the wrong
  image.
- Envira's frozen copies of the album title and its members' titles are dropped. WordPress holds
  the live value, and the frozen one stops being true the moment a post is renamed.

## [26.8.2] - 2026-08-07

### Fixed
- The early asset enqueue scanned for `[envira-gallery]` unconditionally, so with both plugins
  active Lichtbild loaded its stylesheet and scripts on pages Envira was rendering.

### Changed
- The visibility rule is one predicate, `Lichtbild_Repository::is_viewable()`, consulted by every
  publishing path rather than copied into each.

### Added
- `tools/deploy.sh` — chunked FTPS upload with per-file digest verification, an upload order
  asserted against the deployed bootstrap, and before/after capture of the URL surface.
- `tools/live-urls.py` enumerates that surface from the database.

## [26.8.1] - 2026-08-07

### Fixed
- An album published the cover, title and image count of a password-protected member gallery,
  while that gallery's own permalink and both AJAX endpoints correctly refused it.

## [26.8.0] - 2026-08-07

First release, live on `timo-stein.com`.

### Added
- Renders Envira's own gallery and album records in place, so switching is a plugin toggle in
  both directions. A takeover setting decides whether `[envira-gallery]` is handled while Envira
  is active.
- Registers `envira`, `envira_album` and `envira-tag`, which is what keeps the indexed
  `/envira/`, `/envira_album/` and `/envira-tag/` URLs answering once Envira is gone.
- An optional migration renames those rows onto `lichtbild_gallery`, `lichtbild_album` and
  `lichtbild_tag` and writes converted records alongside Envira's, which are left untouched. Post
  IDs never change and the URLs never move. Rollback restores the previous state.
- Justified grid laid out in CSS from each image's own aspect ratio, so nothing reflows once the
  images arrive; PhotoSwipe 5 imported on first click; grid images are WordPress derivatives with
  a `srcset` rather than the full-size original.
- Server-side tag filtering that spans the whole gallery rather than the rendered page, AJAX
  pagination, EXIF display honouring Envira's per-field toggles, and deep links naming the
  attachment rather than its position.
- `Lichtbild_Editor` edits a gallery's images, order, captions, per-image tags and settings once the
  migration has run.
