# Lichtbild — the corpus

The full text of the entries `AGENTS.md` lists one line each. Nothing here is a summary:
every entry is the original, verbatim, in the order it was written, so later entries
correct earlier ones. `AGENTS.md` is the index and is read on every task; this file is
read when you are about to work in the area an entry names.

**A title is a claim, not the lesson.** Several entries conclude the opposite of what
their heading suggests — usually that is why they were written down.

The per-release deploy records live in [`deploys.md`](deploys.md).

## Two generations, and both are live

### The screen that runs it

`Lichtbild_Migration_Screen` is a section of Settings -> Lichtbild, not part of the settings form:
the form edits an option through the Settings API, this runs a one-way action that writes to
`wp_posts`, and an irreversible-looking button does not belong inside a radio group.

**The post/redirect/get is load-bearing, not manners.** The request that performs the
migration registered the post types as they were *before* the rename, so the screen reporting
the result has to be a fresh request or every count on it is read through the wrong
registration — the same reason `finish()` deletes `rewrite_rules` rather than flushing them
here. The outcome travels in a per-user transient so the counts shown are the ones the
migration returned, not numbers reconstructed by looking again.

**Every guard is in the handler, not the markup.** A button the screen declines to draw is
still reachable by anyone who kept the URL, so the capability check, the nonce and the
Envira-is-active precondition are all enforced where the work happens. The disabled checkbox
is a courtesy; `migrate()` refusing is the control.

### Recovering from a migration that dies half-way

There is no transaction. The request can end at any statement, and three things follow that
are not obvious from reading `migrate()` top to bottom.

- **Rollback is gated on the rows, not on the flag.** A request that dies between the first
  rename and `update_option()` leaves rows under Lichtbild's types while the option still says
  `1` — and a rollback gated on `has_migrated()` refuses in exactly that state, which is the
  one where it is the only way back. `lichtbild_rows()` asks the question that matters.
- **`$wpdb->update()` has three outcomes and `(int)` collapses two of them.** It returns rows
  changed, `0` when none matched — legitimate — and `false` on error. Casting turns a failed
  statement into "nothing needed doing", so the migration would carry on and write the schema
  option as though it had worked. `Lichtbild_Migration::move()` exists for that one distinction.
- **Records are converted before any rename**, so a failure during conversion leaves a site
  that is entirely unchanged rather than half-renamed.

### Registration must not defer to Envira once the data has moved

`Lichtbild_Post_Types::register_types()` stands aside while Envira is active, because registering
the same name twice means the last call silently wins. That is right up to the migration and
wrong after it: nothing else registers `lichtbild_gallery`, so deferring would leave the type
unregistered and take `/envira/`, `/envira_album/` and `/envira-tag/` off the site — a
reactivated Envira 404ing every gallery it cannot see. The guard is `! $migrated && ...`.

The same reasoning applies to `Lichtbild_Settings::should_take_over()`, which returns true
unconditionally once migrated: the takeover setting is only a real choice while both plugins
can read the same rows, and after the rename Envira cannot render anything whatever it says.

## The editor, and why it requires the migration

### Albums own their data too, since 26.8.3

Albums spent a release in a state that looked migrated and was not. `Lichtbild_Migration` renamed
album **rows**, while `Lichtbild_Repository::build_album()` went on reading `_eg_album_data`
whatever the schema said — so on a "migrated" site the albums were still in Envira's format, and
nothing anywhere said so. The gap was invisible from both ends: the rows had moved, the pages
rendered, and the only symptom was that an album editor could not be written without writing
Envira's format back.

`Lichtbild_Album_Config` is the gallery schema's twin. Envira stores 170 keys on each of this site's
two real albums; **twelve vary at all and three decide what a visitor sees** — columns, whether
member titles are shown, whether the image count is. `Lichtbild_Album` now reads that normalised
shape and knows nothing about Envira, exactly as `Lichtbild_Gallery` does.

Two conversions are deliberately **not** faithful, because Envira's own record is wrong:

- **The cover is `cover_image_id` and nothing else.** The old lookup fell back through `cover`,
  `thumb_id` and finally `id` — and `id` in an album entry is the **gallery's** ID, never an
  attachment's. That fallback could only ever name the wrong image. It was masked twice over:
  every real entry sets `cover_image_id`, and a gallery ID handed to
  `wp_get_attachment_image_src()` returns false, which drops through to the first image anyway.
  Right answer, wrong reasoning, and it would have been frozen into the record.
- **Both frozen titles are dropped — the members' and the album's own.** Envira freezes a copy of
  each member's title inside the album, and keeps the album's own title in its config as well.
  WordPress already holds the live value for both, and the frozen one stops being true the moment
  a post is renamed.

  The album's own was the one nearly kept, because unlike the members' it had made it into the v2
  schema — and keeping it would have been worse than harmless. Nothing read it, so it could not be
  seen to be wrong; but it was written as an **override**, so the day anything called
  `Lichtbild_Album::title()` a rename of the album post would silently have had no effect. **A stored
  value nothing reads is not neutral when the code that would read it prefers it to the truth.**
  The album editor is what forced the question — a title field there would have been a second
  place to edit one title — which is the general shape: an unused accessor is a decision nobody
  has had to make yet.

**`display_titles` and `display_image_count` are now honoured.** Both were stored, both varied
between this site's albums, and the renderer showed titles and counts unconditionally anyway — a
record carrying a choice nothing read. Honouring them is a no-op here and is what makes the
settings mean anything once they are editable.

The conversion loop is now shared by galleries and albums (`Lichtbild_Migration::convert()`), so the
read-back verification exists once rather than twice. Copying it would have been the third
instance of this project's most expensive habit.

### And the album editor, since 26.8.4

`Lichtbild_Album_Editor` is the gallery editor's twin, and could not have been written before the
section above: until albums had a record of their own it would have had to write `_eg_album_data`
back, which is the one thing the gallery side is built never to do. It carries the same
nonce-presence guard, the same explicit order field, and the same refusal to run before the
migration, for the same reasons.

Two things differ, and both follow from what an album is:

- **The picker is a list of galleries, not the media library.** Members are posts, so `wp.media`
  is the wrong instrument. A select of every gallery is right at this site's scale and costs no
  JavaScript beyond moving a row into a list. It is built with `get_posts()` against
  `gallery_type()`, so a picker naming the pre-migration type comes back **empty** — which reads
  as "this site has no galleries" rather than as a bug, and is why there is a check on it.
- **A cover must be one of the member gallery's own images, and that is enforced rather than
  offered.** The chooser lists that gallery's images; a chooser is markup, and markup is a
  suggestion. `save()` reads the member gallery and drops a cover it does not contain, and drops a
  row whose ID is not a gallery at all.

**Both of those guards protect something no front-end check can see**, which is the argument for
having them at all. The renderer falls back to the gallery's first image whenever a cover will not
resolve, so a cover pointing at *another gallery's* photograph renders exactly like a cover nobody
chose. The page looks right either way; only the record is wrong, and only until someone edits it.

The cover chooser for a gallery added without reloading needs a round trip, so there is one
admin-only endpoint, `lichtbild_album_covers`. It is authorised against **the album being edited**,
not merely against being logged in: it reports the contents of a gallery.

One deliberate omission: **there is no title field.** An album's title is the post's, which the
editor above the metabox already edits.

## What an independent review found, before the migration (26.8.5)

Reviewed independently again once the album work existed and **deliberately before running the
production migration**, because a finding is cheap now and lands on live data afterwards. Seven
findings, all verified against the code and against production, all fixed. Four of them would
have bitten only *after* the migration, which is the argument for the timing.

- **A migration could rename every row and fail to switch authority.** `migrate()` and
  `rollback()` discarded `update_option()`'s return. The schema option is what every read
  consults, so it decides which post types the *next* request registers and queries: a failed
  write there means the screen reports success while the site looks for its galleries under
  names they no longer have. Every gallery and album unreachable, from the one statement nobody
  checked. `set_schema()` now confirms the value by reading it back — not by trusting the
  return, which is `false` both on failure and on no-op, the same three-valued ambiguity
  `move()` exists for.
- **The cover endpoint authorised the wrong object.** It checked `edit_post` on the *album* and
  then returned the title and every image thumbnail of any gallery ID it was handed. `edit_post`
  is per-post; permission on one says nothing about another. It also never checked that the
  album ID was an album, so `edit_post` on any post the user authored was the key. Both gates
  are now explicit, and `is_viewable()` is deliberately still not the predicate here — a draft
  gallery is a legitimate album member, and this is an editing screen.
- **Captions were unslashed twice.** `update_post_meta()` unslashes what it stores (core's
  `update_metadata()`, `wp-includes/meta.php:222`), which is right for the raw `$_POST`
  WordPress normally hands it and wrong for a value already unslashed. `C:\Photos` stored as
  `C:Photos`, one level per write, in **both** editors and the migration. `wp_slash()` at all
  three writes.
- **A repeated album member rendered the first one's cover and caption.** The storage allows a
  gallery twice and the editor can create it; `Lichtbild_Album::item()` returns the first match and
  the renderer looked members up by ID. The class docblock already said per-position callers
  must walk `items()` — the renderer was the caller it meant.
- **The picker would have offered Envira's defaults pseudo-gallery**, which the migration
  renames like any other row. Live check: `#350 'Envira Default Settings'` exists as a draft.
- **The list column counted `_lichtbild_album` only**, so it showed 0 for every album on an
  un-migrated site — which is where this plugin had spent its entire life. It goes through the
  repository now, so it counts whichever record is authoritative.
- **The suite printed 80 KB of JSON above its own report**, because a check called the lightbox
  endpoint without an output buffer. 86,693 bytes down to 13,793.

### Three of those hid behind the test harness, and that is the more useful half

Each was reachable only after the *stubs* stopped modelling WordPress wrongly:

- **`update_option()` returned `true` unconditionally**, so "the rows moved but the flag did
  not" was not merely untested, it was unconstructible. A knob (`fail_options_on`) was needed
  before a check could exist.
- **`update_post_meta()` stored its value verbatim.** Core unslashes; the stub did not. A
  round-trip check between two ends that both leave backslashes alone cannot fail on a
  backslash, which is exactly how the same defect shipped in three places. The stub now
  unslashes, and `wp_unslash()` had to become recursive to match core's `stripslashes_deep()` —
  a string-only version would have left every caption in an item list untouched and modelled
  the opposite of WordPress.
- **The test payload builders submitted unslashed values.** WordPress delivers `$_POST`
  *slashed* — it is why the save path unslashes at all — so a raw payload models a request that
  cannot happen and makes correct production code look broken. The first piece of test data
  containing a backslash turned four unrelated checks red, none of them pointing at the harness.
  **A payload builder is part of the code under test.**

**And one check was written vacuous twice in the same session, the second time knowingly.** The
picker check asserted the screen offers *every gallery row*, which enshrined the defaults-row
bug rather than catching it. Its replacement counts what the reader will actually return — and
needed `$pickable < count( rows )` beside it, because on the local WordPress the defaults row is
absent (Envira deletes its own stored defaults when its addons are bulk-deactivated), so the new
count would have passed there with the guard removed. The album-column check had the identical
shape: a migrate-then-rollback leaves the converted record behind deliberately, so counting it
gave the right answer by accident until the check started removing it first. Mutation `AE12`
survived until then and was right to.

**The backslash injection found a harness bug nothing else would have.** Putting one real
backslash into one fixture gallery turned three equivalence checks red while the value they
disagreed about was demonstrably correct on both sides. The cause: the pre-migration snapshot
was taken through the reader built at the top of the file, which has been memoising galleries
since line 233 — so the snapshot predated the injection. A snapshot taken through a stale cache
is not a snapshot, and nothing before this had ever edited the fixture mid-run.

Two findings were checked and confirmed *not* to be problems, which is worth as much: dropping
both frozen titles is right — the review independently noticed that one fixture member's frozen
title already differs from its live post title — and `convert()`'s read-back comparison is
genuinely capable of failing and already handles `update_post_meta()`'s ambiguous return.

**None of it affected the live front end**, and one probe established that before any fix was
written: zero backslashes across all 55 gallery and album records on production, so finding 3
was latent rather than blocking. That is the difference between "must fix now" and "must fix
before someone types a Windows path into a caption".

## Security posture

### What the second review changed, on the write path

Reviewed independently again once the migration existed and before anything touched
production. No irreversible data loss was found in any examined state, but four things were
wrong, and the first was wrong in a way worth remembering: **the code carried a comment
asserting a safety property that nothing enforced.**

- **Rollback restored Envira's post types without restoring Envira's authority.**
  `Lichtbild_Repository` preferred `_lichtbild_gallery` whenever it existed, and the migration
  deliberately leaves that record behind — so after a rollback, an edit made in Envira had no
  effect and Lichtbild kept rendering the pre-rollback snapshot. Two sources of truth, silently
  disagreeing, in the state that exists *because* someone wanted to undo the migration. The
  comment beside it read "a rollback is a matter of ignoring this one again", and no code
  ignored anything. Now gated on an explicit `$owns_data`, which also makes an interrupted
  migration safe: records are written before any rename, so a half-finished run leaves them
  inert rather than authoritative.
- **The converted count was claimed, not verified.** `update_post_meta()`'s return was
  discarded, and it returns `false` both on failure and when the value is already identical —
  so it cannot be trusted either way. The record is read back and **compared against what was
  meant to be written**; checking only that *a* well-formed record is present is satisfied by
  a stale one from an earlier migrate-and-rollback, which is exactly what a failed write
  leaves behind.
- **The schema option could claim a state the rows were not in.** It is what every read
  consults, so writing it after a failed rename would send the reader looking for rows that
  are not there — every gallery on the site unreachable. Neither direction writes it now
  unless the renames succeeded.
- **The backup confirmation and the POST requirement lived only in the markup.** A `required`
  attribute is a hint to a browser; `admin_post_<action>` fires for GET as well. Both are
  enforced in the handler, which is the principle the class docblock already claimed.

Two findings were accepted and answered with a warning rather than a fix, because Lichtbild
cannot control the other side: with Envira **reactivated after** a migration, both plugins
register types whose rewrite slug is `envira`, one rule key cannot route to two query
variables, and registration order decides which wins — if Envira's does, its rule queries a
post type holding no rows and the URL 404s. `Lichtbild_Post_Types::render_conflict_notice()`
says so in the admin. The honest answer is that Envira should be uninstalled by then.

The AJAX nonce is CSRF hygiene, not authorization — every logged-out visitor shares a public
nonce lifted from any rendered page. Authorization is the post-status plus `read_post` check
in `Lichtbild_Ajax::authorised_gallery()`, and that is the load-bearing part.

### There are three places that publish a gallery, and the rule was copied into two of them

Found by a third review (2026-08-07), after the live switchover. The section above records the
password hole being closed in `Lichtbild_Standalone` and `Lichtbild_Ajax`. It was closed in two of
the **three** paths that can put gallery content in front of a visitor. The third is
`Lichtbild_Renderer::album()`, which renders a cover, a title and an image count per member
gallery — all of it from post meta and the gallery row, so a post password reaches none of it.

A protected gallery listed in an album therefore had its cover photograph published on a
public album page while its own permalink and both AJAX endpoints correctly refused. Verified
by rendering album 986 with member 951 protected: the markup was **byte-identical** to the
unprotected control. Both published albums render through that path.

The same guard covers a second case the password check cannot:
`Lichtbild_Repository::resolve_id()` matches a numeric ID on post **type** alone, so a draft or
private gallery named in an album rendered its cover too.

Three things worth keeping from this:

- **Count the publish paths, not the fixes.** Two of three looks like a completed job from
  inside either one. The question that would have caught it is "what else can put an image on
  a page?", asked of the codebase rather than of the diff.
- **A guard copied is a guard that will be forgotten once.** The rule lived in three places and
  had to stay identical in all of them, which is exactly what it had failed to do. It is now
  one predicate — see the section below.
- **The album check that existed asserted a length, not content.** `strlen($appended) >
  strlen('CONTENT')` passes just as happily on markup that leaks, which is why the album path
  had a check and the leak still shipped. The replacement asserts a member's *title* is absent
  when protected, with a control asserting it is present when it is not.

### The rule is `Lichtbild_Repository::is_viewable()`, and extracting it found two more gaps

Done immediately after the leak was closed, deliberately as its own change rather than inside
the security fix — that one had to be small enough to deploy in minutes, and this one moves
guards that mutations pin by exact text.

It lives on the repository because that is what the publishing paths already hold: the renderer
is handed one to resolve album members, and the AJAX and standalone handlers are constructed
with one. No new wiring, no new class, and the paths that could publish next — an album editor,
a REST route, a block — get it by having the object they would need anyway.

**The mutations split in two, and that is the part worth copying.** One predicate would
otherwise mean one mutation, and a path that stopped consulting it would survive every mutation
of the predicate. So there are three that delete a **call site** (`P1` standalone, `P2` AJAX,
`P5` album) and two that break a **leg** (`V1` password, `V2` status). Neither kind implies the
other, and the leg mutations each go red in two places at once — one per path that asks — which
is the coverage the old arrangement claimed and did not have.

Two things the extraction surfaced that no amount of reading would have:

- **The standalone path had never had its status leg checked, because it never had one.** It
  tested the password alone. Adopting the whole predicate there is belt to WordPress's braces —
  core would not serve a draft page in the first place — but that impossibility lives in core's
  query rather than in this file, and a guard whose only defence is another module's behaviour
  is the kind nobody notices losing. `V2` now goes red in that path too.
- **The test stub could not answer `get_post_status()` for an album.** It looked only in the
  gallery bucket, so every album read back `false`, which is indistinguishable from a draft. The
  moment the predicate started asking about albums — a standalone album page is a post like any
  other — a correct guard refused every one of them, and the album check went red on the first
  run. The fixture had carried album status all along; the stub simply never looked. **A stub
  that answers for one of two post types is a stub that will invent a status for the other.**

## Testing

### Declaring conditional checks by hand does not scale, and the count is the instrument

The rule above was applied to the areas someone thought of, and the areas nobody thought of are
exactly where it was needed. Thirteen checks could still leave the report rather than fail —
nine of them behind the per-item `if ( ! $checks->assert( ... ) ) { continue; }` short-circuits,
and four more elsewhere. Renaming the grid link class (`B25`) turned **one** check red and took
**nine** off the report; the total fell from 197 to 188 and the run still read as a single
tidy failure.

**The generic instrument is the set of check names the report lists.** `tests/mutations.php`
compares that set against the baseline's, both directions, and any difference is its own verdict,
`VANISHED`, with the missing and added names printed. That found ten mutations with the defect
where reading the code had found one, and the thirteen checks in one pass rather than one at a
time.

It was a count first, which is the weaker instrument and worth knowing why: a mutation that
removed one check and added another nets to zero, and the count would call that unchanged. Sets
cost four lines. That said, **nothing here has yet proved the set comparison capable of catching
that case, because nothing can** — check names are string literals in `render-test.php` and
mutations only edit `includes/`, so no mutation can rename a check. The one-directional case
*is* proved: deleting an `expect()` entry turns `B25` red with the name printed.

Three things worth carrying past this suite:

- **The diagnosis has to be a fact about the report, not about the checks you remembered to
  look at.** A hand-maintained list of "conditional" checks is itself conditional on someone's
  attention, and it silently stops being complete the first time a check is added behind a
  short-circuit.
- **It is complete for names and blind to populations, and that limit is real.** A check keeps
  its row while losing most of what it examined: `every renderable item became a figure` runs 51
  times over real galleries and twice synthetically, so a mutation that stops the real galleries
  reaching it leaves the name in place backed by **2 assertions instead of 53**. `[EMPTY]` catches
  a population that falls to zero; a partial collapse has nothing watching it. `--names` prints
  population deltas for this reason, and they are deliberately *not* a verdict — `B52` shifts
  three populations by legitimately making one more gallery render, and failing a correct mutation
  is how a signal stops being read.
- **It fires in the direction nobody watches, too.** `B52` reported one check more than the
  baseline — a check *appearing*. `empty gallery renders nothing` lives behind `0 ===
  $gallery->count()`, and every gallery on this site has items, so **the check had never run
  once**. It was not failing and it was not passing; it did not exist, and no report had ever
  been one line shorter than expected because nobody knew what to expect. It has a synthetic
  empty gallery now, and `B91` pins it.

**`image has src` is gone, which the earlier note said it should be.** It asserted that a
rendered `<img>` carries a non-empty `src` while the renderer `continue`s on an empty one — so
no such figure could exist, and on this fixture, where all 2,264 items carry a src, no mutation
could construct one either. The replacement states the invariant the renderer actually
implements: **the number of figures equals the number of items with a usable src**
(`every renderable item became a figure`). On the real galleries that agrees with `item count
matches page` and neither can tell the two apart; the pair that separates them is synthetic —
one item usable, one not, one figure — with a control asserting two usable items give two.
`B90` removes the `continue` and kills it.

**The suite is mutation-proved, and the mutations are now code rather than a memory.**

```sh
php tests/mutations.php          # all of them
php tests/mutations.php E1 C5    # one or two, while iterating
```

Each entry names the single edit it makes and, **in advance**, the check it expects to kill.
Getting that prediction wrong is a result, not a nuisance: either the check does not cover
what it claims, or the mutation changes nothing, and both are worth knowing. A green suite is
worth nothing until the checks have been shown capable of going red, so re-run this after
changing anything here.

The harness is held to the standard it enforces, because a mutation harness that lies is
worse than none — `SURVIVED` reads as a gap in the *tests* rather than in the harness:

- Files are restored **by bytes** and the restore is verified by digest. Text mode rewrites
  line endings on some platforms, and the comparison that would catch it reads back through
  the same translation.
- A mutation whose target text is missing **or occurs twice** is `BROKEN`, never `SURVIVED`.
  Refactoring moves the lines these point at.
- A run with no `checks:` summary line is `BROKEN`. A fatal error produces no failures either.
- The parsed failure names are **cross-checked against the printed total**. The report pads
  the check name, so a parser that assumed the padding would stop matching the day a check
  got a longer name — silently, and in the direction that looks like good news.
- Failure names are taken by splitting on `[FAIL] ` / `[EMPTY] ` and stripping the trailing
  counts, never by reading a fixed column.
- **Ids are unique, and an id asked for must exist.** Two entries sharing an id makes
  `php tests/mutations.php E5` silently run *both*, printing that id twice with different
  verdicts beside it — which reads as a flake — and reporting more mutations than were asked
  for. A typo'd id ran nothing at all and reported a clean sweep. Both now abort. Added after
  colliding with an existing id while writing a new mutation, i.e. the failure came first.

- **A mutation that makes checks go AWAY rather than RED is `VANISHED`, never `KILLED`.** See
  the section above; the count is compared against baseline and the missing names are printed.

```sh
php tests/mutations.php --names B25   # the full red set, not `(+N more)`
```

`--names` prints every check a mutation turned red rather than the predicted one and a tally.
That is what makes a coverage inventory possible: the measurement below means set-differencing
the full red set of every mutation against the checks the suite reports, and it was first done
by patching the harness by hand each time.

**The 189 in the file cover every area, and that is a measurement rather than an impression.**
An earlier 48 were run by hand and their definitions are gone, which left the v1 areas — the
Envira conversion, the pagination and filter arithmetic, the markup a visitor receives, the
registrations the URLs rest on — proved by a pass nobody could repeat. Backfilling them closed
the gap from **90 checks pinned by no mutation to 4**.

**Coverage was counted by running every mutation and recording the full set of checks it turns
red, then set-differencing that against the checks the suite reports.** Matching mutation
`expect` names against check names is the obvious method and it undercounts badly, because most
mutations turn several checks red — the collateral is real coverage and the names never say so.
Anything claiming to measure this has to run the mutations.

The four still pinned by nothing are listed here because *why* is the whole content, and three of
them are properties of the checks rather than gaps to fill: `orphan item is still active` is the
control for `pending items are excluded`, and the only edit that kills it empties every gallery;
`a shortcode naming nothing renders nothing` asserts emptiness, which is every other check's
failure mode, so only a shortcode that *invented* a gallery would kill it — and `a block naming
nothing renders nothing` is its twin, added in 26.8.14, unpinnable for the identical reason. The
fourth, `markup parses`, resisted every single-edit mutation under DOMDocument's tolerant HTML
parser — reported rather than contrived, which is the right answer when a mutation cannot be
built honestly. (`image has src` was another, and it was deleted rather than pinned; see above.)

**A fatal pre-empts the check that should have failed, and that hid a whole class of mutation.**
`render-test.php` called `$renderer->gallery( ...->gallery( $id ), 1 )` and indexed
`build_record( ... )['items'][0]` with no null guard, a hundred lines *after* the checks that
handle those cases correctly. A mutation making a row unreachable therefore killed the suite
before anything was reported — so **"the reader can no longer find this row", which is the single
most important thing the migration's reader does, could not be expressed here at all.** The two
that hit it reported `BROKEN`, never `SURVIVED`, which is the only reason it read as a defect in
the harness rather than a gap in the tests.

Closed by `lichtbild_render_found()`, `lichtbild_render_album_found()` and `lichtbild_album_found()`,
which assert the row was found and then return markup, or an empty album for the chained reads.
`B93` (a migrated site's own gallery record returns nothing), `B94` (the converter emits no
items) and `B95` (the album twin) are the mutations that were impossible before.

Two things about how the remaining sites were found, and the second is the useful one:

- **The sweep was much wider than the diagnosis.** The note above says "two". Counted from the
  guards that now exist: **5** gallery renderer call sites, **12** album renderer ones and **10**
  chained `->album( $id )->gallery_ids()` reads — **27** in all, because `Lichtbild_Renderer::album()`
  is typed exactly like `gallery()` and nobody had asked. A defect described once and left
  standing in twenty-seven places is this project's most expensive habit, recorded again.
  (The first draft of this paragraph said 1, 12 and 8; an independent review counted the
  replacements and got 27. A number written from memory of what you edited is not a count.)
- **`B95` found five of them, one at a time, by fataling on each in turn.** Each fix moved the
  fatal to the next line number. That is a slow way to enumerate and a completely reliable one,
  and it is worth preferring to reading: the grep that "found all the call sites" missed the
  multi-line ones, twice.

**The screen's three states were exercised and unasserted.** `Lichtbild_Migration_Screen::render()`
dispatches to `render_mixed()`, `render_migrated()` or `render_pending()`, and all three ran
during the suite with nothing asserted about any of them — the notice checks above them are
`render_result()`, which runs before the dispatch and answers a different question.
`render_mixed()` is the one that matters: it is the screen left by a migration that died between
the first rename and the schema option, and the only one offering the way back. Its rule is that
the rollback is offered on the strength of the **rows** and never the flag, so the check asserts
`has_migrated()` is still false while the rollback is on the page — gated on the flag, the button
would hide in exactly the state where it is the only way out. `S3`, `S4` and `S5` pin the three,
and each kills its own check and nothing else.

The mixed state is built by renaming **one row by hand** rather than by calling `migrate()`. That
is the point rather than a shortcut: the rows and the option have to be able to disagree, or the
state under test cannot be constructed at all.

Two of them survived on the first run and both were findings rather than noise, which is the
argument for predicting in advance:

- **A guard can be enforced twice, and mutating the first one then proves nothing.** Removing
  the editor's nonce-*presence* check does not let an unrelated `save_post` through, because
  the nonce *verification* below refuses an absent nonce too. What only the first prevents is
  a PHP warning on every quick edit, so the check had to assert that as well.
- **A check can be built on a state that cannot occur.** "An unmigrated site refuses to save"
  cleared the schema flag but left the rows renamed — a combination no real site is in — so
  the *post type* guard refused first and the check passed without the migration guard
  existing at all.

**The migration is proved by equivalence, not by inspection.** For all 51 renderable
galleries the suite converts the record and asserts the markup is **byte-identical** to the
Envira-path render. That covers the reader as well as the converter, which comparing settings
would not.

**And then it runs the migration for real.** `migrate()` renames the rows in the stub's own
tables, and every gallery is rendered again through a repository built the way the plugin
builds one afterwards — which is what conversion checks cannot cover: that the renamed rows
are still reachable, that the taxonomy rename carried the tags with it, and that the reader
consults the right names. Rollback is then asserted to restore the markup byte for byte.

> **"The way the plugin builds one" was a comment, not a fact, for a while.** That reader was
> constructed with three arguments where the plugin passes four, so `owns_data` was false and
> it fell back to `_eg_gallery_data` — which the migration deliberately leaves in place. All 51
> galleries therefore rendered identically for the reason that, as far as the reader was
> concerned, nothing had moved. Gutting `build_from_own()` entirely left the check green.
>
> The conversion checks above do cover that function directly, so the reader path was proved;
> what nothing covered was that the *post-migration* reader is wired to prefer its own record —
> the one configuration that actually ships. Found by mutation while adding the editor's
> checks, a month of green runs after it was written. **A comment claiming a check builds
> something "the way production does" is a claim to verify, not a note to read.**

Five properties of the stubs make that capable of failing, and each replaced a version that
could not:

- **`get_post_type()` answers from a row, not from which fixture bucket the ID is in.** A stub
  that always said `envira` would let the repository keep finding galleries after a rename
  that never happened.
- **`get_the_terms()` reads the current taxonomy name**, so forgetting the taxonomy rename
  takes the tags off every image instead of passing.
- **`$wpdb` parses the column and value out of the SQL** rather than recognising whole
  queries, so naming the wrong post type returns the wrong rows here too. It also has a
  `fail_updates_on` knob, because `$wpdb->update()`'s `false` branch is otherwise unreachable
  and that branch is the whole reason `move()` exists.
- **`get_post_status()` answers for albums as well as galleries, and returns `false` for an
  unknown ID rather than falling back to `publish`.** Both halves are load-bearing and both were
  found the same way. It looked in the gallery bucket alone until the shared visibility
  predicate started asking about album pages, at which point every album read back `false` —
  indistinguishable from a draft — and a correct guard refused all of them. And a stub that
  guessed `publish` for an unknown ID would make the predicate's status leg impossible to fail.
- **`get_post()` returns a real `WP_Post` carrying `post_content`**, because the early asset
  scan tests `instanceof WP_Post` and then reads that property. A `stdClass` would have made
  the scan return before doing anything, so its checks would have passed on a code path that
  never ran. The content itself is a knob, not fixture data — the fixture exports no
  `post_content`, since a gallery keeping its images in post meta is the whole premise.

**The harness has its own require list, and it was silently short by two classes.** `lichtbild-gallery.php`
cannot be included by the suite — it defines constants and registers hooks — so `render-test.php`
duplicates its list, and a new class has to be added in three places. A completeness check now
compares `glob( includes/class-*.php )` against `get_included_files()` and exits before any check
runs, because a harness that is not loading the code under test cannot report anything
trustworthy about it.

It found `Lichtbild_Shortcode` and `Lichtbild` had **never** been loaded by this suite. That is worth
sitting with: the shortcode is a publishing path — the one that deliberately skips the visibility
predicate — and `Lichtbild` is where every constructor in the plugin is actually called, so it is
where a signature change lands. Both now have checks; requiring the files without exercising them
would have satisfied the new guard and covered nothing, which is the same empty-filter-reads-as-a-pass
trap this file is full of. Two `add_action`/`add_filter` stubs stopped being no-ops so that
`boot()` could be asserted at all.

**A mutation survived, and deleting the guard would have been the wrong response.** Removing the
`config.type === 'defaults'` skip from `build_album()` changed nothing, because on this site that
row is a **draft** and every loop skips albums with no members — so the guard was unreachable by
accident of the fixture rather than by anything in the code. A published defaults row on any other
Envira site renders an empty cover grid. The answer was a check, not a deletion; the project's own
rule about where an impossibility is enforced is what decided it.

**PHP hoists an unconditional class declaration to compile time**, so the check that the
migration refuses while Envira is active has to declare `Envira_Gallery` through `eval()`.
Written out literally, the class exists from the first line of the file — the migration above
would have refused, every migration check would have failed, and the two guard checks would
have passed for a reason that had nothing to do with ordering. It is the one failure mode a
guard test cannot afford, since a refusal is what it asserts. Caught on the first run, from
`Deactivate Envira Gallery before migrating.` appearing where 52 renamed rows were expected.

Two checks were vacuous when first written and are worth not re-introducing: **the takeover
check** passes on `auto` with Envira absent whatever the migrated case does, so the option is
forced to `never` first; and **the post-migration registration check** is meaningless unless
Envira is active, so it runs after the `eval()` rather than before it.

**Not one of the 2,264 real items has a caption or alt text, and no attachment has an excerpt
to fall back to.** So the equivalence check, run over the fixture alone, cannot notice a
conversion that drops those fields — which is exactly what `M23` demonstrated by surviving. A
synthetic gallery supplies them, and it carries its own control asserting the caption actually
reaches the markup, because otherwise the comparison would be just as vacuous as before. The
general point: **a mutation that survives is a question about the data before it is a verdict
on the test.**

The fixture is **not committed**: it contains the site's own content. Regenerate it.

**It carries `post_password` as a boolean, and it did not until 2026-08-07.** The export
selected `post_status` and not `post_password`, so the corpus could not tell a protected
gallery from a public one — which meant every claim built on it ("renders every gallery on the
site", "byte-identical before and after migration") silently treated the site's one protected
gallery as public, and every password check had to be hand-built from a state someone thought
to construct. That is how the album cover grid published a protected gallery with the whole
suite green. Exported as `bool(password)` and never as the value: what the suite needs is the
distinction, and the password itself is a secret with no business in a file on a laptop.

Because the fixture is regenerated rather than committed, an old one still loads and simply has
no opinion about protection. That is **not** the same as a site with nothing protected, so
`render-test.php` prints which it got — `fixture carries password data: yes (N protected)` or a
`NO - exported before post_password was added` notice. Reported the way the PHP matrix reports a
missing interpreter: a gap that is stated out loud, never a silent pass.

### And since 26.8.10 there is a second corpus, built by `tests/make-fixture.php`

Every claim in this repo rested on a file that existed on one laptop. There was no CI and a
fresh clone could not run a single check, which is a strange position for a project whose own
notes argue this hard about verification. `php tests/make-fixture.php` writes
`tests/fixture-synthetic.json` — 10 galleries, 3 albums, 161 items, no database, no
credentials — and the suite takes it as an ordinary argument.

**The generator is committed and its output is not.** A generated artifact checked in beside
the thing that generates it drifts, and the drift is silent because both look plausible. It is
deterministic — verified byte-identical across repeated runs *and* across PHP 8.1, 8.2 and 8.5,
which matters because a corpus that differs between runs turns every equivalence check in the
suite into a coin toss and the failure would read as a defect in the plugin.

**It is not a replacement for the real fixture and is not meant to become one.** The live
corpus is 52 galleries of photographs taken over years, and its value distribution is what
produced most of the traps recorded above — four spellings of boolean true, a per-gallery
translated all-label, per-field EXIF toggles that contradict each other. Nobody would have
invented those. What the synthetic one carries is the same *shapes*, each chosen from what the
real corpus was **measured** to contain, one gallery per shape, with the file's own table
saying which is which.

#### The measurement that says it is worth anything

A corpus that passes while pinning nothing is precisely the empty-filter-reads-as-a-pass trap
this file is full of, and "220 checks, 0 failing" is exactly what it would print. So
`tests/mutations.php` gained `--fixture=<path>`, and the two corpora were compared by running
all 197 mutations against each and set-differencing the **full red set** of every one:

| | real | synthetic |
|---|---|---|
| checks reported | 220 | 220 |
| mutations killed by the predicted check | 197 / 197 | 197 / 197 |
| **distinct checks pinned by some mutation** | **216** | **216** |
| checks pinned on one corpus only | \- | **0** |

*(Re-measured at 26.8.14. It was 209 / 189 / 206 when the synthetic corpus was built, and the
row that matters has held at every re-measurement since: the pinned-check sets are equal, and
so, at 18, is the count of mutations whose collateral differs.)*

The third row is the one that means something. The first two are satisfied by a corpus that
kills everything for weak reasons; the identical pinned-check *sets* are what say the coverage
inventory is the same. Eighteen individual mutations still differ in collateral, and every check
one of them loses is pinned by a different mutation on that corpus, which is why the totals
agree.

**That last sentence is a measurement and was written the other way round first.** `B96` hands
PhotoSwipe a 0x0 slide for an unmeasurable item, and it turns `every unmeasurable item was kept
out of the lightbox` red only on the synthetic corpus — nothing on the live site is orphaned, so
both sides of that equality are 0 there. The obvious inference is that the check is unpinned on
the real fixture, and it was written down as such. It is wrong: **`B6` pins it**, by reversing
`fill()` so the default lightbox size is forced onto the one real attachment that has no such
size. Different mutation, different mechanism, same check. A claim about what a corpus fails to
cover needs the full red set of *every* mutation, which is what `--names` exists for.

**A bad `--fixture=` path is a hard error rather than a fall-back to the default.** Falling
back would make a CI run look like it had proved the synthetic corpus while proving nothing
about it, which is the same failure as `tools/live-urls.py` verifying a deploy against 49 URLs
instead of 159.

#### Five shapes the corpus was missing, and how each was found

None of these came from reading the suite. Each is a check going red, or a mutation losing a
red, against a corpus that looked complete:

- **Every attachment needs a `title`.** WordPress sets one from the file name at upload and all
  2,243 real ones have it; the renderer falls back to it for `alt`. A corpus of untitled
  attachments turns `image has alt attribute` red on **every item**, which reads as a renderer
  bug.
- **Galleries that are album members must hold disjoint attachments.** The album editor checks
  pick a "foreign" cover from a sibling member, so two members sharing one photograph makes a
  **correct** refusal look like a bug.
- **One tag has to match more images than fit on a page, and not a multiple of the page size.**
  With every tag matching two images, `page_count()` rounding *down* is invisible: `max( 1,
  floor( 2 / 10 ) )` and `ceil( 2 / 10 )` are both 1. Mutation `B9` still killed its predicted
  check and quietly lost the filtered-arithmetic red.
- **One attachment must be missing a registered size.** Exactly one on the live site has no
  `medium_large` — an upload predating the size, which WordPress never backfills. `B6` reverses
  `fill()` so defaults beat stored settings, and only an image that *cannot* serve the default
  size drops back to the full-size original. Every synthetic attachment carried every size, so
  nothing could regress.
- **The most-tagged gallery has to be paginated**, or the suite *fatals* rather than fails —
  see the unguarded division below.

#### Three defects in the suite that only a second corpus could expose

- **`$repository->gallery( 951 )`** — the tag-filter block named its gallery by ID, which is a
  fact about one database written into the test. Against any other corpus the ID resolves to
  nothing, the block is skipped, and **eleven checks report `[EMPTY]`**. It now selects the
  gallery carrying the most distinct tags, which is both corpus-agnostic and a better statement
  of what the block needs.
- **`filtered page count follows the filter` divided by `per_page()` unguarded**, and
  `per_page()` is 0 when pagination is off — so the suite died with `DivisionByZeroError` and
  reported nothing at all. The slice fifteen lines below already branched on
  `has_pagination()`, which is what says this was an oversight rather than an assumption worth
  keeping. **A fatal is worse than a failure**: it pre-empts the report, exactly as the
  unguarded null renderer calls did in 26.8.9.
- **Four per-item checks asserted properties an orphan item deliberately does not have.**
  `lightbox dimensions are known`, `image has srcset`, `image has intrinsic size` and `grid
  image is not the original` held for all 2,264 live items and are false by design for one
  whose attachment has been deleted: it keeps Envira's frozen full-size URL, stays in the grid
  as a plain link, and is kept out of the lightbox rather than handed to PhotoSwipe as a `0x0`
  slide for its zoom arithmetic to divide by. So they encoded *"every item has a live
  attachment"* without saying so, and deleting one photograph from the live site would have
  turned four of them red on the next export — reported as a regression in a renderer doing
  exactly what it was built to do.

  They are guarded on the item being measurable now, and the rule they used to imply is stated
  once per gallery instead: `every unmeasurable item was kept out of the lightbox`, comparing
  items whose attachment does not resolve against links carrying no lightbox size. **The
  populations on the real corpus did not move — 529 each, checked before and after** — because
  nothing there is orphaned, which is also why that equality is `0 === 0` on all 51 real
  galleries and why the synthetic corpus carries the one row that makes it mean anything.

  It is stated per gallery rather than per item for a specific reason: a per-item form would
  have **no population at all** on the real fixture, so it would report `[EMPTY]` and fail
  there — the precise hazard 26.8.9 was spent removing. `B96` is the mutation.

- **One PHP warning silently re-scored every check after it, and it inflated a dozen red sets.**
  PHP's CLI writes diagnostics to STDOUT, so a warning anywhere in `render-test.php` is body
  output, `headers_sent()` becomes true, and every later check exercising an AJAX endpoint dies
  on *"Cannot modify header information"* instead of on its own merits. Three checks were
  affected — both endpoint ones and the array-shaped tag — and they went red **together** under
  a dozen unrelated mutations. `ini_set( 'display_errors', 'stderr' )` fixes it, which is what
  `tools/devenv.sh` already does to wp-cli for the same reason.

  Measured rather than asserted: **648 reds across the real corpus before, 636 after — 12 were
  false — and not one check lost any pinning** (204 distinct, both corpora, unchanged). Under
  `SEO3`, which touches Yoast settings and nothing else, the red set went from three checks to
  one.

  Three things generalise, and the middle one is why it survived so long:

  - **The mechanism was not what the symptom suggested.** The obvious reading — and the one
    written into `TODO.md` first — is that the suite prints verdicts as it goes, so a `[FAIL]`
    emits output. It does not; the report is printed once at the end. The output came from an
    `assert()` whose **own expression** indexed an array key the mutation had removed, and a
    warning from an argument is invisible in a line that reads like a pure assertion.
  - **It fails in the direction nobody audits.** A cascade makes *more* checks red, and every
    instinct treats extra reds as extra coverage. Nothing anywhere asks whether a mutation
    killed too much, which is exactly why an artifact can sit inside the instrument the
    coverage inventory is built from.
  - **And it explained none of the difference between the two corpora, though `TODO.md` claimed
    it explained three reds of it.** The artifact hit both corpora identically, so it cancelled
    out of every comparison: the only-real / only-synthetic split is 3 / 18 before the fix and
    3 / 18 after. **An artifact common to both sides of a difference cannot be the cause of that
    difference** — obvious once stated, and it was written down backwards.

- **Two public methods survived having no caller because a test and a mutation used them.**
  `Lichtbild_Album::cover_id( $id )` and `::caption( $id )` looked a member up by gallery ID. The
  renderer was moved off them in 26.8.5 — an album may legitimately list a gallery twice, and
  first-match-wins renders one photograph's cover and caption for both positions — after which
  nothing in `includes/` called either one. What kept them alive was `render-test.php` and
  mutation `AE10`, whose *replacement text* called them to reintroduce the bug. Deleting them
  therefore "broke a test", which is the shape that makes dead code look load-bearing.

  Both are gone, along with the private `item()` they shared. The mutation inlines the
  first-match lookup, which is where a defect being reproduced belongs; the checks go through a
  `lichtbild_album_member()` helper in the suite.

  **That move also closed an unearned pass, and the two turn out to be the same defect seen from
  either end.** `cover_id()` answers `0` for a gallery the album has never heard of, which is
  indistinguishable from a cover that was correctly refused — exactly what `a cover outside its
  gallery is refused` asserts. So when `lichtbild_album_found()` fell back to an empty album, that
  check passed without an album having been read at all. Measured on `B95`, which makes the
  reader return null: **before, only the control `a cover inside its gallery is kept` went red;
  now both do.** Returning the member *record* rather than a field of it is the whole fix, because
  it makes "no such row" and "the row stores 0" different answers.

- **Three round-trip checks compared two renders that could both be empty.** Same defect, other
  value: a save is asserted to be a no-op by rendering the row before and after and comparing
  byte for byte, and `$editor_render()` answers `''` for a row the reader cannot find — so two
  *absent* renders compare equal and the check passes without anything having been rendered.
  Measured before the fix: `B93`, which makes the gallery reader return null for every migrated
  record, **did not** turn `a save round-trips the gallery byte for byte` red, and `B95` did not
  turn the album one red. Both do now. The fix is one clause — require the before-render to be
  non-empty — because if it is, an equal after-render is too.

  Worth knowing why the *migration equivalence* checks were never vulnerable to this, since they
  also compare two renders: their two sides come from **different readers**, the Envira path and
  the v2 path, so one going empty makes them differ rather than agree. `converted record renders
  identically` is red under both mutations and always was. **Two values compared for equality are
  only as good as their ability to disagree**, and reading them from the same source is what
  removes it.

- **The shape all three share, which is the part worth carrying:** a check whose passing value is
  also what its helper returns on failure. `0` for a missing cover, `''` for a missing render.
  Each was invisible in a green run, each needed a mutation to find, and each was fixed by making
  "nothing was found" a *distinguishable* answer rather than a plausible one. The general audit —
  reading every check against what its helper answers when it finds nothing — is in `TODO.md`.

#### CI, which is the whole point

`.github/workflows/tests.yml` runs the suite on PHP 8.1 through 8.5 and the full mutation pass
on 8.2, the production version. Three of its steps are there for reasons this file has argued
before: the generator is run **twice** and the outputs compared with `cmp`, because determinism
is load-bearing and nothing else would notice it lapsing; the mutation job runs `git diff
--exit-code` afterwards, because a harness that lies about restoring the files it edited is
worse than none; and the mutation job exists at all because **a green suite proves nothing
until the checks have been shown capable of going red**, which is the one thing a passing badge
hides.

### PHP versions: test the one the site runs, not the one the Mac has

```sh
bash tests/php-matrix.sh                                # 8.1 8.2 8.3 8.4 8.5, all 220 checks each
bash tests/php-matrix.sh tests/fixture-synthetic.json   # the same, with no database
```

**The live site runs PHP 8.2.30; this Mac ships 8.5.** Every result before that script existed
was measured on an interpreter the site does not have — not wrong, but answering a question
nobody asked, the same family of mistake as quoting a debug-build benchmark. The suite is a
plain PHP program with no WordPress and no database, so running it across versions costs
wall clock and nothing else.

`Requires PHP` in the header is **8.1** because 8.1 is the oldest version actually exercised.
It said `7.4` first, which was a claim nothing backed; 7.4 and 8.0 are EOL and absent from
Homebrew core, so testing them would mean adding a third-party tap for versions no host still
offers. Raise the floor rather than claim an untested one — and if it ever needs lowering,
test first.

Two properties the script has because a matrix is as capable of lying as any other harness:

- **A version that is not installed is `[SKIP]`, never a pass**, and the production version
  being skipped is a hard error however green the rest of the table is.
- **A run with no summary line is `[BROKEN]`, not a pass.** A fatal error produces no
  `checks:` line, and treating its absence as success is how a matrix covers nothing while
  printing a full table.

Proved by mutation, because a matrix that has never gone red is indistinguishable from one
that cannot: inserting a call to `array_find()` (PHP 8.4+) turned 8.1, 8.2 and 8.3 `[BROKEN]`
and left 8.4 and 8.5 green, and the file restored byte-identical afterwards.

## The local WordPress

### The editors, against real infrastructure

```sh
bash tools/devenv.sh reset
bash tools/devenv.sh wp plugin deactivate --all --exclude=lichtbild-gallery
bash tools/devenv.sh wp eval-file "$PWD/tests/live-editor.php"        # 13 checks, galleries
bash tools/devenv.sh reset
bash tools/devenv.sh wp plugin deactivate --all --exclude=lichtbild-gallery
bash tools/devenv.sh wp eval-file "$PWD/tests/live-album-editor.php"  # 13 checks, albums
```

Thirteen checks for galleries: migrate, register the post-migration types, register the
metaboxes on the real screen, draw a row per item and a field per setting, save through the
real `save_post`, reorder, write tags to the real taxonomy, confirm the term was created,
restore, roll back.

Thirteen for albums, the same shape, plus the two things only a real database can answer: that
the picker's `get_posts()` finds the **renamed** galleries — 52 offered for 52 present, where
the pre-migration type name finds none — and that a cover belonging to a different gallery is
refused while one from the member gallery is kept. That pair has to be run together: a
`clean_cover()` returning 0 for everything satisfies the refusal and breaks the editor.

**The precondition is checked before anything is changed, and that is not politeness.**
`devenv.sh reset` restores the imported `active_plugins`, which has Envira running — so the
migration correctly refuses, and the first version of this script reported "0 galleries" and
then failed twelve checks for a reason that had nothing to do with the code.

**Post types are registered at `init`, so a migration performed later in the same request
leaves them naming the rows they used to name.** That is why the migration screen does a
post/redirect/get, and here it surfaced as `map_meta_cap was called incorrectly: the post type
lichtbild_gallery is not registered` on every capability check, plus a `WP_Error` from
`wp_get_object_terms()`. The script re-registers after migrating; production gets the same
effect from the redirect.

### The one thing even that does not prove: the browser round trip

Done by hand once, and worth repeating before the production run rather than committing as a
pipeline (it needs a login, and the imported database carries the *live site's* users, so it
needs a throwaway admin created locally):

- `wp-admin/post.php?post=<id>&action=edit` returns **200 / 153,838 bytes** with 7 item rows,
  all 26 settings fields, `editor.css`, `editor.js`, `jquery-ui-sortable`, `media-views` and
  the row template present, and **zero** PHP notices or warnings.
- Parsing that form and POSTing it back unchanged — 135 fields — leaves the gallery
  **byte-identical**.
- **With a control, because an identical render is also what a save that never ran produces.**
  Reversing `lichtbild_order` in the same body changes the rendered hash, and putting it back
  restores it exactly. Without that leg the round trip proves nothing.

The album editor was put through the same three steps in 26.8.4 — `post.php?post=989&action=edit`
returns **200 / 104,249 bytes** carrying the picker, both member rows, all three settings fields,
`editor.css`, `album-editor.js`, `jquery-ui-sortable` and the row template, with **zero** PHP
diagnostics; 53 fields posted back leave the album byte-identical; reversing the order moves the
hash and restoring it brings it back. The real `lichtbild_album_covers` endpoint answers **133**
covers for a member gallery to an authorised admin.

**And the control earned its keep immediately, twice over.** The first attempt reported the
round trip identical *and* the control identical — because the form encoder had thrown and
posted an **empty body**. The second reported HTTP 403 on every POST for a subtler reason worth
knowing: **WordPress prints some of its own hidden inputs with single quotes**, so a parser
matching only `name="..."` silently drops `post_ID` — and a form missing it is rejected by
`post.php` before any of this code runs. Both times the "unchanged" leg said [OK]. Only the
control said anything true.
**The first thing it proved was the project's central claim.** With no plugin registering the
type, `/envira/zoo-leipzig/` **301s away** to `/zoo-leipzig/`; activate Lichtbild and the same
URL returns **200**. That is the whole argument for why independence rests on the
registrations rather than on an editor, and nothing without real rewrite rules could show it.

**The second thing it found was a missing feature — a 200 over an empty page.** Registering
the post type stops `/envira/<slug>/` 404ing; it does not put the gallery on it. A gallery
post keeps its images in post meta, not in `post_content`, so the permalink resolved, the
theme rendered, and the content area was blank — behind a perfectly healthy status code. The
live site showed seven items on the same URL and Lichtbild showed none. `Lichtbild_Standalone`
closes it by filtering `the_content`, the way Envira does. Two deliberate differences: the
loop is checked (`in_the_loop()` and `is_main_query()`, so a widget or related-posts block
running `the_content` on the same post cannot produce a second copy — Envira tests only
`is_singular()` and the queried type), and the setting is **owned** rather than borrowed,
because reading `envira_gallery_standalone_enabled` forever would mean uninstalling Envira
silently blanks every gallery page. The migration copies the value into `lichtbild_standalone`.

### The full round trip, on real infrastructure

Run against the imported production database with Envira deactivated:

| step | result |
|---|---|
| `plan()` | 53 galleries, 3 albums, 58 tags, 51 convertible, 1 defaults record |
| `migrate()` | 53 / 3 / 58 moved, 51 converted, 0 errors — **row-conserving** |
| six gallery URLs | HTTP 200, gallery markup **byte-identical** to before |
| `[envira-gallery id="N"]` in a published post | still renders, so post IDs survived |
| `rollback()` | 53 / 3 restored, schema back to 1 |
| the same six URLs again | HTTP 200, **byte-identical to pre-migration** |

The control that stops "identical" being vacuous: `envira` went to **0** rows and
`lichtbild_gallery` to **53**, so the types genuinely changed underneath.

**The Envira-is-active guard fired on its own**, unprompted — the imported database carries
production's `active_plugins`, so Envira really was running and `migrate()` refused. That
precondition had only ever been exercised against a stub before.

**One count discrepancy was Envira's doing, not Lichtbild's, and is worth knowing before the
real run.** A first attempt reported 52 galleries and 2 albums where the plan had said 53 and
3. Nothing was lost by the migration: deactivating the Envira addons *in bulk* removes its own
stored defaults records, which are the rows Lichtbild skips anyway. Isolating it took three
controls — a no-op WordPress bootstrap (no change, so not cron), one-at-a-time deactivation
(no change), and finally counting before and after inside a single process. **Count in one
process or not at all**; on a live WordPress something else is always running between two
commands.

### Five traps, four of them the harness lying rather than the code

- **`localhost:3307` silently ignores the port.** MySQL reads `localhost` as "use the unix
  socket", so the port is discarded and the connection goes to a socket that does not exist.
  It surfaces as *"Error establishing a database connection"*, which reads like a credentials
  problem and is not one. Use `127.0.0.1`.
- **`mariadb-install-db` creates only `root@localhost`, which is a socket identity.** A TCP
  connection from WordPress is a different account to the server and is refused however right
  the credentials are, so the grant for `root@127.0.0.1` is explicit.
- **PHP CLI writes errors to stdout, not stderr.** wp-cli 2.12's vendored code is not
  deprecation-clean on 8.2+, so `$(wp core version)` came back as a notice, a newline and then
  the version — every captured value quietly wrong, and `2>/dev/null` no help at all. The
  wrapper sets `display_errors=stderr`.
- **PHP's built-in server has no rewrite engine**, so every pretty permalink 404s before
  WordPress loads. `router.php` stands in for the `.htaccess` rules. Without it the
  environment would agree that Lichtbild had broken the URLs it exists to protect.
- **opcache serves the previous version of a file you just swapped, and the stale answer looks
  like a finding.** `opcache.enable=1` with `revalidate_freq=2` means an A/B that overwrites
  `includes/` and immediately curls a page can measure the *old* build — silently, because
  nothing distinguishes a cached class from a freshly compiled one. Hit while proving the asset
  fix on 2026-08-07: the first A/B reported that the bug did not reproduce at HEAD, which reads
  as *"the fix was unnecessary"*, and a four-second settle turned the same page from 0 to 1
  `lichtbild.css`. Note the direction — the stale read pointed at doing nothing, which is the half
  nobody re-checks. Settle before every capture, and give the swap a **control that reads the
  file on disk** (`grep -c should_take_over includes/class-lichtbild-assets.php`) so "which build
  is live" is asserted rather than assumed.

### Two failures the setup script itself had, and both are this project's recurring shape

**It printed `ready` over a wall of failures.** Every `wp` call had died on the `localhost`
trap above; nothing checked their exit codes, so the summary line was cheerfully wrong. The
fix is not care but structure: each step is `|| die`, and `verify()` asserts positive
evidence — WordPress reports 7.0.3, it can see at least fifty galleries **through its own
query layer**, and the table prefix matches the one configured for the deployment target.

**And an HTTP 200 was read as a working page when the body was zero bytes.** `core download
--skip-content` had left no theme installed, so WordPress rendered nothing at all — behind a
perfectly healthy status code. Same family as every `[EMPTY]` check in the suite: *check the
population, not the absence of an error.*

### What has to come from the server, and why

`tools/fetch-live-assets.py` pulls the active theme (`twentytwenty`) and all twenty
`envira-*` plugin directories over read-only FTPS — 1,207 files, 13.9 MB. The theme, because
without one the page is blank; Envira itself, because coexistence, the takeover setting and
the duplicate-slug conflict are claims about behaviour *next to* Envira, and modelling Envira
is precisely what the stub suite already does.

**Uploads are deliberately not fetched.** Attachment metadata is in the database, so
dimensions, `srcset` and the justified geometry are all exact without a single image file
present; a must-use plugin points the URLs at the live domain. Copying 2,243 files would
prove nothing further. That same mu-plugin also blocks outbound mail, because a copy of a
live site must not be able to write to that site's correspondents.

## The live switchover, and the three bugs it found

Done 2026-08-07: Lichtbild uploaded, activated, and Envira's 18 addons deactivated.
`timo-stein.com` now renders every gallery through Lichtbild. **At the time this was written the
migration had not been run** — the site was still on v1, reading Envira's own rows in place. It
was migrated later the same day, and Envira was uninstalled after that; see the two sections
below.

The result is worth the trouble: gallery permalinks went from **395 KB to 60 KB**, and every
one of the 57 gallery, album and tag URLs answers 200 with the same photographs as before.

**Every one of the three bugs below was found by a control, not by a test.** Each had passed
the whole suite, the mutation pass and the real-WordPress checks, because each needed a state
the local environment had never been in — Lichtbild and Envira *both active*, and a page nobody
had thought to capture.

- **Both plugins active rendered every gallery permalink twice.** `Lichtbild_Standalone` appended
  its gallery without consulting `should_take_over()`, so it ran alongside Envira's own
  standalone filter. This is the state a fresh install is in, and the one the takeover setting
  exists for. It was live for about a minute; the fix is in `applies()`.
- **Album permalinks rendered an empty page.** `Lichtbild_Standalone` only ever handled galleries,
  and an album keeps its galleries in post meta for the same reason a gallery keeps its images
  there — so `/envira_album/<slug>/` answered **200** with nothing on it. Two published URLs,
  and a regression rather than a missing extra, because Envira's albums addon does render them.
- **A password-protected gallery would have been published in full.** WordPress enforces a post
  password by replacing `post_content`, which reaches no part of a gallery, so
  `Lichtbild_Standalone` would have appended every image directly beneath the password form — and
  `Lichtbild_Ajax` would have served them to anyone with the gallery ID, because a protected post
  is `publish` status and `read_post` does not consider the password either. Found by reading
  the live database during the pre-flight: exactly one gallery on this site is protected, and
  the post embedding it is protected too.

Four things generalise past this deployment:

- **Deploy in a state where the change should be nothing, and check that it is nothing.**
  Activating Lichtbild with Envira still running and `takeover=auto` is meant to be a complete
  no-op. It was not, and a byte comparison against the pre-deploy capture said so in seconds.
  A rollout with no such step would have shipped the duplication.
- **Capture the before-state of every URL *space*, not a sample of one.** Ten probe URLs were
  captured and none of them was an album, which is exactly why the album regression reached
  production. The list should come from the database — every post type and taxonomy the plugin
  registers — rather than from what came to mind.
- **A comparison needs the un-switched site to compare against, and after the switch it is
  gone.** The album regression could only be measured by putting Envira back *locally* and
  fetching the same URL. Keep the local copy able to run both plugins.
- **The fingerprint has to be semantic, not byte-for-byte.** Lichtbild's markup is deliberately
  different, so the comparison is the set of upload filenames with size suffixes stripped: the
  same photographs, however they are wrapped.

### Fixed since: the early enqueue now matches only the shortcodes we claim

`Lichtbild_Assets::maybe_enqueue_early()` used to scan for `[envira-gallery]` unconditionally, so
with **both** plugins active and the takeover on `auto` — the state a fresh install is in, and
the one the setting exists for — Lichtbild put its stylesheet and two scripts on a page Envira was
rendering. Measured on the live site at about 940 bytes of markup and three extra requests, no
visual change. Small, and it made "install Lichtbild and change nothing" false in the one
configuration that claim exists for.

The list is now the one `Lichtbild_Shortcode` actually claims: ours always, Envira's only while
`should_take_over()`. `Lichtbild_Assets` takes the settings in its constructor, which is why three
call sites moved.

**Both directions are asserted, and the second is the one that matters.** "It did not enqueue"
is also what a scan matching nothing produces — precisely what this fix could have broken — so
`A2` forces the envira names on unconditionally and `A3` empties the base list, with a check
apiece.

### Closed in 26.8.6: the shortcode was the fourth publishing path

`Lichtbild_Shortcode::gallery()` and `::album()` now consult `Lichtbild_Repository::is_viewable()`,
so all four paths that can put gallery content in front of a visitor ask the same question.

The argument for leaving it open was that a shortcode is an author naming a specific gallery in a
specific post, which reads as intent to publish it *there* — the way WordPress does not cascade a
post's password onto the attachments it embeds. What made that thin is that the same sentence
nearly describes an album, and an album always did check.

**What actually kept it open was cost, and the cost turned out to be zero — measured, not
argued.** WordPress holds one cookie carrying one password, so a protected gallery embedded in a
protected post with a *different* password would stop rendering for a visitor who had legitimately
unlocked the page. On this site there are exactly two embeds that are not plainly public, and both
survive:

| case | outcome |
|---|---|
| protected gallery `#1646` in post `#1508`, itself protected **with the same password** | the cookie unlocks both; the embed keeps working |
| private gallery `#373` in post `#432`, itself private | only users who can read the private post can see the page, and they can read the gallery too |

49 published posts embed a gallery shortcode; those two are the entire exposure. The question was
answerable in one database query, and it sat open for weeks because nobody ran it. **A product
decision blocked on an unknown cost is usually blocked on an unrun query.**

The remaining behaviour change is the one worth having: a protected gallery embedded in a *public*
post now refuses, which is precisely the case the check exists for.

### And the fifth publishing path, added 26.8.14, which arrived already asking

`Lichtbild_Block` registers `lichtbild/gallery` and `lichtbild/album`, and **renders none of it**. Both
callbacks hand to `Lichtbild_Shortcode`, which asks `is_viewable()`. That is the whole design, and
it is what the four sections above were paid for: each earlier path grew its own copy of the
rule, one of them forgot, and a protected gallery's cover went out on a public album page. A
block that assembled its own repository and renderer would have been the fifth copy of a rule
already forgotten once.

The property is therefore stated as an **equality** rather than as a list of behaviours: what a
block renders is byte-for-byte what the shortcode renders. The password gate, the status gate
and the empty answer for a row that is not there all follow from that and cannot drift from it,
because there is no second implementation to drift.

**And the coverage does not follow from the design, which is the part that nearly went wrong.**
Nothing in `Lichtbild_Block` can be mutated to break either visibility gate — there is no copy to
break — so the two checks are pinned by `V1` and `V2` instead. The status check was very nearly
left out on exactly that reasoning, and measurement said no: with only the password leg written,
`V2` went red in three places and **none of them was the block**, so nothing anywhere asserted
that a block consults the status gate at all. Written, `V1` goes red in six places and `V2` in
four. Same shape as the standalone path when the predicate was first extracted — belt to
somebody else's braces is exactly the guard nobody notices losing.

Three decisions worth not re-litigating:

- **Metadata lives in `block.json` and nowhere else.** Title, icon, category, keywords and
  attributes reach the editor through core's own server-side bootstrap, and `blocks.js` declares
  only the editing experience. One declaration, so the two halves cannot drift — which is the
  same argument `Lichtbild_Config` makes against two writable representations of one gallery.
- **The picker is printed into the page, and that is a constraint rather than a preference.**
  All three post types are `show_in_rest => false`, so the editor cannot query them:
  `useEntityRecords( 'postType', 'lichtbild_gallery' )` answers 404, not an empty list. Turning
  REST on would expose every gallery record on a new public surface to answer a question the
  screen already knows. Worth knowing before someone modernises it into a fetch.
- **Only `[lichtbild-gallery]` transforms into a block, not `[envira-gallery]`.** Envira's tag
  still renders under a rollback because Envira registers it again; converting it to a block is
  a one-click, unconfirmed step that spends that. Ours is unambiguous.

**Asset registration moved from `wp_enqueue_scripts` to `init` for one reason**: the block's
editor stylesheet depends on `lichtbild`, `wp_enqueue_scripts` never fires in the admin, and
`WP_Styles` drops an unregistered dependency **without a word**. So the one arrangement that
breaks the preview is also the one that produces no error anywhere. Nothing is enqueued earlier;
`need_gallery()` is still the only thing that enqueues at all.

#### The one real defect in this release, and no check would ever have found it

`register_blocks()` runs on `init`, and it built the picker's choices there — which reads every
gallery row through the reader: **111 queries and 11ms on a cold cache**, on every request,
including every front-end page view, for data only the block editor ever looks at. It is now on
`enqueue_block_editor_assets`, so the one screen that reads the answer pays for it, and
`register_blocks()` measures **0 queries**.

Three things worth carrying, and the first is the whole reason this is written down:

- **It changes no rendered byte, so every instrument in this repository was blind to it.** 220
  checks, 197 mutations, five PHP versions, eleven live checks and a browser round trip all
  passed over it. A cost is not a behaviour, and the suite only asserts behaviours. It was found
  by asking, before a deploy, what the new code does on a request that does not use it.
- **The first measurement said 0 queries and was an artifact.** The plugin's own `init` had
  already run `editor_data()` during the wp-cli bootstrap, so the caches were primed and the
  measurement timed the *second* call. The warm control — 1 query against 111 cold — is what
  distinguishes a cheap function from a measurement taken after something else paid. Same family
  as the opcache A/B already recorded above, and the stale read pointed the same way: at doing
  nothing.
- **The check that exists now can only be a call count.** `registering the blocks reads nothing`
  asserts `get_posts()` was not reached, with the control that `enqueue_editor_data()` does reach
  it — because "read nothing" is also true of a picker that offers nothing — plus the hook name,
  since `admin_enqueue_scripts` would pay it on every admin page. `BK8` reintroduces the defect.

#### Three things the harness could not see until it was told to

- **`wp_register_style()`/`wp_register_script()` were no-ops with docblocks claiming they
  recorded something.** Recording is what makes the dependency above assertable at all; a no-op
  cannot hold a fact about handles.
- **`get_posts()` ignored `post_status` entirely.** Mutation `BK6` — a picker asking for
  published galleries only, so every draft silently disappears from it — **SURVIVED**, because
  both sides of the comparison read a stub with no opinion about status. It defaults to
  `publish` when absent now, exactly as the real function does. *A stub that ignores an argument
  models code that cannot get that argument wrong.*
- **A `block.json` is a source file for translation, and the tokenizer cannot see it.** Core
  runs `title`, `description` and `keywords` through `_x()` with its own contexts, so all eleven
  sat in the catalogue and were reported as orphans "no longer in the source" — false, and the
  kind of permanent warning that teaches people to stop reading warnings. The same was true of
  the four plugin-header fields, which live in a **comment**. `tests/i18n-test.php` reads both
  now, and fails a `block.json` declaring no `textdomain`, without which core translates none of
  it. The catalogue is generated with `wp i18n make-pot` for the same reason: it already knew
  all three sources, and a check that disagreed with its generator fails on every regeneration.

#### `tests/blocks-js-test.js`, the only JavaScript this repo tests

`blocks.js` runs once at the top of the block editor. If it throws — a typo, a renamed `wp`
package — nothing catches it, `registerBlockType` is never reached, and **both blocks are absent
from the inserter** while the editor page still serves 200 with the script tag, the picker data
and the block definitions all present. Every live check in `tests/live-block.php` passes over a
file that crashed on its first line, and so does the browser fetch below. That gap is the entire
justification; it is not a rendering test and should not grow into one.

It runs the real file in a `vm` against a mocked `wp` where every stubbed member is one the
script genuinely uses, and asserts what got registered — 12 checks, in CI, proved capable of
failing by four mutations (a throw, an `envira-gallery` transform, a `<select>` string stored
unconverted, a preview naming the wrong block), each killing exactly its own check, with the
file restored byte-identically.

**Restore by copy, not by `git checkout`.** The first proof run of the `block.json` mutations
lost both restores to `error: pathspec ... did not match any file(s) known to git` — the files
were new and untracked, so the two edits stacked silently. The mutation harness has verified its
restores by digest since 26.8.9; an ad-hoc proof deserves the same, and for new files `git` is
not the instrument.

#### What real WordPress said, and the artifact it produced first

`tests/live-block.php` — 11 checks: core accepts both metadata files, a serialised block renders
**14,298 bytes identical** to the shortcode, `parse_blocks()` returns the id as an `int` rather
than a string, the editor stylesheet resolves `lichtbild` in a real `WP_Styles`, the picker offers
51 of 52 rows while the pre-migration type name finds 0, and a protected gallery renders nothing
to a logged-out visitor against a control that renders 14,298.

**Two of those failed first, with one cause, and it was the test rather than the code.** The
plugin's own `init` had already registered both blocks, using a `Lichtbild_Block` built at
`plugins_loaded` — before the script renamed anything — so its render callback held a repository
still reading `envira`. Core keeps the **first** registration and warns about the second, so
`do_blocks()` used the stale one and every render came back empty. It is the same trap this file
already records for post types, one layer further in: *a migration performed later in the same
request leaves every object built earlier naming the rows they used to name.* Production is
immune for the same reason it always was — the migration screen's post/redirect/get.

And the browser round trip, done once by hand with a throwaway admin: `post-new.php` returns
**200 / 437,542 bytes** carrying `blocks.js`, `window.LichtbildBlocks` with 51 galleries and 2
albums, `blocks.css`, `lichtbild.css` (the dependency, resolved), both block definitions in core's
bootstrap, and **zero PHP diagnostics**. The payload's `chooseGallery` came back as
`Galerie auswählen`, which is the catalogue reaching the block editor rather than an assumption
that it would.

## The migration, run on the live site 2026-08-07

`timo-stein.com` now runs on Lichtbild's own storage. 53 galleries, 3 albums and 58 image tags
moved; 51 gallery records and 2 album records were converted. Envira's records are untouched —
52 `_eg_gallery_data` and 3 `_eg_album_data` still there — which is what keeps rollback real.

**159 of 159 URLs are semantically identical to the pre-migration capture, and all 159 answer
200.** Both albums render every cover from their own v2 record; the protected gallery still
serves its form with zero markup and zero upload filenames; the AJAX endpoint still refuses it
with a valid nonce while a public gallery returns its items; all 104 tag-to-attachment
relationships survived the taxonomy rename.

**Verify a migration semantically, never byte for byte.** The post type is in WordPress's own
body and post classes, so `single-envira` becomes `single-lichtbild_gallery` on every page and a
byte hash reports 100% changed while telling you nothing. `tools/deploy.sh` now carries both:
`capture` (whole-page hash, right for a deploy, where nothing should move) and `fingerprint`
(the upload filenames with size suffixes stripped, the tile count, and the document title —
right for a migration). Using the wrong one fails in whichever direction is least convenient.

**The document title is in that fingerprint because the image set alone is blind on 60 of the
159 URLs** — 58 tag archives render no thumbnails, plus the protected gallery. Without a title
the strongest thing "unchanged" could mean for those is "still empty", which a page that *broke*
into an empty one satisfies just as well. Adding it took the fingerprint from 52 distinct values
to 114, largest group 2. It also turned out to be the only reason the one real regression was
visible at all.

### The regression was Yoast's, and the pre-flight should have found it

58 URLs changed: every tag archive, and only tag archives. **Yoast keys its per-type settings on
the registered name** — `title-tax-envira-tag`, `noindex-tax-envira-tag`, and so on — so
renaming the taxonomy to `lichtbild_tag` left it managing none of those pages. The title fell back
to WordPress core's default (`Wespe – …` in place of `Wespe Archive - …`, core's en-dash instead
of Yoast's separator) and **the canonical link disappeared** from 58 indexed URLs.

Galleries and albums escaped by luck rather than by design: Yoast's default post-type title
template happens to match the configured one, so their titles and canonicals never moved. Worth
knowing rather than relying on.

Fixed by mirroring the 31 `envira*` keys in `wpseo_titles` onto the new names — nothing replaced,
nothing removed — after which all 159 match the pre-migration baseline exactly.

Four things generalise:

- **Ask what ELSE keys off the names you are renaming.** The pre-flight checked what *Lichtbild*
  reads and never asked that question. One query for `envira` against `wp_options` would have
  shown it. A migration that renames a post type or taxonomy is renaming a public identifier
  that other plugins have written down.
- **Print the plan; do not apply it.** The first generated key list had **56** entries because a
  substring match turned `title-tax-envira-category` into `title-tax-lichtbild_gallery-category` —
  Envira taxonomies Lichtbild never registered, whose archives no longer exist at all. Those 25
  keys would have described pages that are gone. Reading the list is what caught it.
- **Prove a serialisation round trip before writing back.** `wpseo_titles` holds 156 keys of
  mixed type and one option feeds Yoast site-wide. Serialising the *unmodified* parse and
  comparing it byte for byte against the original (7,053 bytes, identical) is what made adding
  keys safe; without that check a library quirk would have been silent and total.
- **`$PIPESTATUS` is empty in zsh** — it is `$pipestatus`, lowercase. The database backup's exit
  code read as blank, so the dump's success was unknown at the moment it looked confirmed. It
  was verified instead by restoring it into a scratch database and matching every row count
  against production, which is the check that should have been run regardless: a dump you have
  not restored is a hope. The `-- Dump completed` trailer is its truncation tell.

**Closed in 26.8.6.** `Lichtbild_Migration::carry_seo_settings()` now does in code what was done by
hand here, and arrives at the same answer against the same data: **31 keys, 156 to 187, zero
invented**. Two properties are load-bearing — keys are *added*, never replaced or removed, which
is why a rollback needs no inverse pass (Envira's originals are still there for the restored
names, and the new ones go inert); and the suffix is matched exactly, never as a substring, which
is the mistake that produced 25 keys describing archives that no longer exist. It is deliberately
Yoast-only: a general "rewrite every option mentioning the old name" pass would be guesswork about
key formats, and guesswork that writes.

## Envira is gone (2026-08-07)

All twenty `envira-*` plugin directories were deleted from the server. The plugins directory now
holds eleven plugins and Lichtbild; `active_plugins` names no Envira anything.

**This is the moment the project's central claim was actually tested.** Nothing but Lichtbild
registers `/envira/`, `/envira_album/` and `/envira-tag/` now, and all **159 URLs still answer
200 with the same photographs as before the migration** — compared against a capture taken while
Envira was still installed and rendering. The registrations were the whole argument; here is the
evidence.

**Rollback still works, and that was checked rather than assumed** — it is now the only recovery
path, and the obvious worry is that it restores rows to post types nothing registers.
`register_types()` stands aside only when `! $migrated && envira_is_active()`, so with Envira
absent it registers whichever names the schema says: roll back and it registers `envira`,
`envira_album` and `envira-tag` itself, which are exactly the names the restored rows are under.
Confirmed against a real WordPress rather than read off the source.

Uninstalling it leaves harmless debris behind — orphaned `wp_options` rows, a scheduled event
with no handler, a taxonomy nothing registers. None of it is read by anything and none of it is
worth a production write on its own. **The inventory of what one particular site still carries is
in the gitignored `AGENTS.local.md`**, along with the one entry in it that is load-bearing.

Envira's own gallery and album records are deliberately still there. They are what rollback
restores authority to, and they cost nothing.

## German, and why the catalogue needs a test of its own (26.8.13)

The site is `lang="de"` and every one of Lichtbild's 184 strings rendered in English. About 28 are
visitor-facing — the EXIF panel, the lightbox controls, the tag bar — and had been that way since
the switchover, because Envira shipped German and nothing replaced it. `languages/lichtbild-gallery.pot` and
a complete `de_DE` catalogue close it.

> **Two of the four lessons below were superseded at 26.8.14, and the second one matters.** The
> hand-rolled extraction described here was replaced by `wp i18n make-pot`, which already knew
> about the two sources this section's tokenizer could not see — `block.json` metadata and the
> plugin header, eleven and four strings respectively. The catalogue is 207 strings now. The
> tokenizer lesson still stands for `tests/i18n-test.php`, which is a *check* rather than a
> generator and must keep agreeing with what the generator extracts.

**The measured gap that made this the top priority was not an Envira feature.** Of 276 stored
Envira settings, 56 vary across the 51 galleries and 28 of those are unread by Lichtbild — but sorted
by real use they are single-gallery or cosmetic (`zoom`, `slideshow`, `print_lightbox`,
`watermarking`: one gallery or none each). There is no capability gap worth building. There was a
language gap on every page.

Four things worth keeping:

- **A line-oriented regex silently drops multi-line strings, and looks complete doing it.** The
  first extraction pass reported 153 of 180 and nothing said otherwise; 32 of the strings span
  several source lines, including every paragraph on the migration screen. `tests/i18n-test.php`
  uses PHP's own tokenizer for that reason. **The count was only suspicious because the `.pot`
  said 181** — two tools disagreeing is what exposed it.
- **A catalogue rots on the next string added, and the symptom is invisible**: one English line
  among German ones, with every other check green. So the catalogue has a test — every source
  string translated, no orphans, no fuzzy entries, and a compiled `.mo` present, because a `.po`
  without one is a translation nobody ever sees. In CI.
- **The generator's own escaping was wrong and the test caught it immediately.** Two strings
  containing quotes came out double-escaped, because the `.po` parser concatenated quoted lines
  without unescaping them. Written by hand and eyeballed, both would have shipped as
  untranslated; the check named them on its first run.
- **WordPress needs no `load_plugin_textdomain()` call** — `Domain Path` in the header plus the
  textdomain registry is enough on WP 7. Verified against the real local WordPress rather than
  assumed, **and the probe was proved able to fail**: with the `.mo` moved aside it reports
  `textdomain loaded: NO` and 0 of 5.

**The deploy changed 158 of 159 URLs, and the one that did not is the tell.** It is the protected
gallery, which renders no Lichtbild output at all. Everything else carries the taxonomy's singular
label — `Image Tag` became `Bild-Schlagwort` — including all 58 tag archives. A first check for
the *plural* found zero on both sides and briefly made the change look unexplained; the control
that settled it was hashing one page three times with no deploy in between, proving the hash was
stable and the difference therefore real. **Ask whether the measurement is stable before asking
what broke.**

## Two defects a person found by looking at the site (26.8.11)

Both had been live since the switchover, both are cosmetic-to-moderate, and **neither could have
been caught by anything in this repo** — 207 checks, 187 mutations, five PHP versions and a real
WordPress all agreed the site was correct. What they have in common is that the *markup was
right*: the failure was in what the markup means to a browser, and nothing here renders one.

- **The lightbox never filled the viewport.** PhotoSwipe computes its `fit` zoom level as
  `Math.min( 1, viewport / natural )` — read out of the vendored source rather than assumed, and
  the `Math.min( 1, … )` is the whole story: it never scales an image *up*. The anchor declared
  the configured lightbox size, so that size was a hard ceiling on how large a photograph could
  ever be shown. On this site `large` is 1024px wide and exists on **1,563 of 2,243**
  attachments, so most photographs opened at 1024px on a 4K display.

  The fix is two halves and neither works alone: declare the **full-size** dimensions, so
  PhotoSwipe is allowed to fill the viewport, and emit `data-pswp-srcset`, so the browser still
  fetches the smallest candidate covering what is actually on screen. PhotoSwipe rewrites
  `sizes` from the displayed width on every resize, so this is strictly better than before on
  small screens as well as large. Declaring the full size *without* the srcset would have been a
  straight bandwidth regression for every visitor — which is why `L1` and `L2` exist as separate
  mutations rather than one.

- **Album covers left a hole, and their labels had silently moved.** Two findings behind one
  report. `grid-template-columns: repeat( var( --lichtbild-album-columns ), 1fr )` always fills the
  row, so an album with fewer members than columns puts them all at the left — `American
  Football` has two members in a three-column layout. Flex with `justify-content: center`
  centres an incomplete row and lays a full one out identically.

  The second was the more interesting one and it was **not** a design choice: Envira's own
  `envira-albums/assets/css/albums.css` sets `text-align: center` on the album title, caption and
  image count **unconditionally** — not behind the `album_alignment` setting, which is `0` on
  both live albums. So those labels moved on the day Envira was uninstalled, and nothing in the
  markup, the settings or the record recorded the difference.

Three things worth carrying:

- **The answer to "did Envira do X?" is on disk.** `tools/fetch-live-assets.py` pulled all twenty
  `envira-*` plugin directories into the local environment precisely so coexistence could be
  tested, and that makes Envira's stylesheet a **primary source**. One `grep` for `text-align`
  settled a question that would otherwise have been a taste argument. Reach for it before
  reasoning about what the old plugin used to do.
- **A byte-identical deploy verifies faithfulness to the previous *Lichtbild*, not to Envira.** Every
  deploy since the switchover has compared against a capture of Lichtbild's own output, so a
  difference introduced at the switchover is baked into every baseline since and can never show
  up. The only instrument that catches it is a person looking at the page.
- **A green suite is evidence about the markup, not about the rendering.** Both defects are
  invisible to DOMDocument: one is a zoom-level computation inside a JavaScript library, the
  other is which CSS rule wins. Adding checks was still right — the two lightbox properties are
  now asserted and mutation-pinned — but the honest reading is that this class of defect needs
  eyes, and the useful response to a report like this is to fix it and to ask what else is in the
  same class, not to pretend a check would have caught it.

### Changing `display` orphans every rule that targets the old layout model (26.8.12)

26.8.11 moved the album cover grid from `grid` to `flex` and left the `max-width: 700px` rule
setting `grid-template-columns`. That property is **inert on a flex container** — it is not an
error, it does not warn, and it does not fall back. The rule simply stopped doing anything, so
the desktop basis of one third of the width stayed in force at every size and album covers
rendered about **137px wide on a 390px phone** instead of filling it.

Nothing in this repo could have seen it. The markup is identical, all 209 checks stayed green,
and the deploy's own before/after capture compares *markup*, which did not change. **This is a
CSS defect and CSS has no output to assert on** — the only instrument that works is a rendering
engine.

**So use one: `--headless --dump-dom` plus a `getBoundingClientRect()` probe is a complete
measurement tool and takes a minute to set up.** Injecting a script that writes the numbers into
`document.title`, then reading the title out of the dumped DOM, turned a taste argument into a
table:

| | desktop 1200px | phone 390px |
|---|---|---|
| pre-26.8.11 | `left=0 right=201` — the hole being fixed | `itemW=216`, fills |
| 26.8.11 | `left=101 right=101` — centred | `itemW=137` — the regression |
| 26.8.12 | `left=101 right=101` — centred | `itemW=216`, fills |

Three things worth keeping:

- **Measure all three, not two.** Comparing only "before the fix" and "after the fix" at one
  width would have shown the centring working and said nothing about the phone. The old
  stylesheet is the control, and it is what shows the regression is a regression rather than a
  pre-existing quirk.
- **A `display` change is a migration, and the rules that break are the ones somewhere else.**
  Grep the stylesheet for every property that belongs to the layout model you are leaving —
  `grid-template-*`, `grid-area`, `justify-items` — and check each one against the container it
  targets. They fail silently and individually.
- **The measurement immediately answered a question that had nothing to do with the bug**:
  `bodyScrollW=492` in a 390px window, identical across all three stylesheets, so the theme
  overflows horizontally on a phone regardless of anything here. Recorded, not fixed — it is not
  this plugin's.

### A public read endpoint cannot be gated on a nonce, and the harness said it could (26.8.19)

Both front-end AJAX endpoints — pagination, tag filtering, and the lightbox on a gallery whose
images span pages — refused any request whose nonce did not verify. That was wrong, and it was
wrong in a way this site could never have shown: `timo-stein.com` has no page cache.

**A nonce is a fact about when a page was generated.** It stops verifying twelve hours later. A
full-page cache — the normal configuration for a public WordPress site, and the one where a
504-image gallery most needs its pagination to work — serves pages generated days ago. So on a
cached site the nonce a logged-out visitor is holding is *routinely* expired before they click
anything, and WordPress answers a failed `check_ajax_referer()` with a bare `-1`. The gallery
renders, a button does nothing, and there is no error anywhere for the site owner to act on.

Nothing is given up by not refusing, and that is worth being precise about rather than asserting:
both endpoints are reads that change no state, they return JSON that a cross-origin script cannot
read, and their authorization is `Lichtbild_Repository::is_viewable()` — which has to hold on its
own regardless, because anyone who knows a gallery ID can reach them whether or not its page was
ever rendered. The nonce is still sent and still verified; it simply cannot refuse.

**The admin cover endpoint keeps refusing, and the contrast is the point.** An admin screen is
never served from a page cache, so a nonce there is reliable and refusing on it is free. One rule
does not travel to the other endpoint, and the two mutations that cover this are each other's
control: each turns one endpoint's rule into the other's.

#### The stub that always said yes

`check_ajax_referer()` in `tests/wp-stubs.php` returned `true` unconditionally, with a docblock
explaining that the preview server is local and read-only. **A stub that always says yes models
code that cannot get the answer wrong.** Every check that reached an endpoint through it had been
passing without ever setting a nonce, so the suite could not distinguish a handler that refuses a
bad nonce from one that never looks — which is precisely the distinction the two endpoints now
sit on opposite sides of.

Correcting it turned four existing checks red immediately, all in the album cover endpoint, all
of which had been asserting a *later* gate over a nonce nobody supplied. That is the third time
in this project a defect has been hiding behind the harness rather than in the code, and the
shape is identical each time: the stub answering for WordPress more agreeably than WordPress
does.

It also produced the failure mode the `Checks::expect()` mechanism exists for. The new
`the cover endpoint refuses a missing or wrong nonce` check sits inside a conditional area, so
under four unrelated mutations it **vanished from the report** instead of failing — and the
harness said so, in those words, rather than counting a green run. Declared, and all four go
back to KILLED.

#### And the deep-link shim, which no existing instrument could see

The lightbox writes a fragment naming the gallery and the photograph, and people paste those into
messages and bookmark them. Links made before the rename carry the former prefix and cannot be
recalled; after the rename they resolved to nothing. The failure is the quietest available — the
page loads, the photograph the link was about is simply not shown — so no rendering check, PHP
check or live-URL check can see it. Every one of them is satisfied by the page that loads.

`tests/frontend-js-test.js` is the answer, and what it can see is worth stating because it decides
what the result means. `lichtbild.js` is a closed IIFE, so driving `restoreFromHash()` for real
would mean standing up a DOM, a history object and a PhotoSwipe import for one regular
expression. Instead the pattern is **lifted out of the source and executed** — the literal that
runs in the browser, not a copy of it — which makes every assertion about what it accepts a real
assertion. The call sites are then checked by reading the source, which is the weaker half and is
labelled as such: a grep proves a name appears, never that the path taken at run time is the one
that appears. It is there because a correct pattern that nothing consults would pass every
behavioural check above it.

Two properties fell out of writing it that were not in the original change:

- **Reading is bilingual; writing is not.** Everything the script emits uses the current prefix,
  so a legacy link is upgraded in the address bar as soon as the lightbox it opened writes its
  own. A check asserts nothing *produces* the old prefix — a second writer minting them would
  keep the shim permanently load-bearing instead of decaying.
- **The second reader is the one that gets left behind.** `clearHash()` matched the hash by
  string prefix where `restoreFromHash()` already used a regular expression, and two spellings of
  one rule is how half a rename survives review. A check asserts no hash is matched by a bare
  string literal anywhere in the file, and hand-mutating `clearHash()` back turns two checks red.

## The wordpress.org review, and the feature it cost

The submission was pended on 2026-08-14 by an automated pre-review — `AUTOPREREVIEW
atelier/tstone1/14Aug26/T1`, sent before any human read the plugin. Four findings. Three were
mechanical; one removed a feature, and the interesting part is that all four are a *policy*
disagreement rather than a defect. Nothing they flagged was broken.

**The one with teeth: a plugin may not store and print arbitrary CSS entered through its own
UI.** Gallery settings carried a Custom CSS textarea, saved into the gallery's record, printed
by the renderer inside an inline `<style>`. Their reasoning is that WordPress already ships a
validating CSS editor in the Customizer, and that a plugin duplicating it is surface area with
no upside. The finding is marked ✨, meaning it was written by their AI rather than a person —
which changes nothing about whether to comply, and is worth saying because the instinct is to
argue with a machine.

**Removing it meant removing all four halves, and that was the only real decision.** The
setting in `defaults()`, the conversion of Envira's own `custom_css`, the sanitiser, the
textarea, and the `<style>` output are five places; leaving any of them would have left a
stored value nothing reads, which this codebase has already paid for once and has an entry
about. So `Lichtbild_Config::css()` and `rewrite_css()` are gone, `Lichtbild_Gallery::custom_css()`
is gone, and `Lichtbild_Config::defaults()` is twenty-five settings rather than twenty-six.

**Nothing was destroyed, and that is what made it an easy call.** Envira's `_eg_gallery_data`
still holds the CSS on the sixteen galleries that had it — untouched since before the migration,
for exactly this class of reason — so the removal costs rendering, not data.
`tools/export-custom-css.py` prints it for the Customizer. **It is lossless because the element
ids do not move**: a gallery is `#lichtbild-<id>` whether the rule arrives inline from a plugin or
from Appearance -> Customize -> Additional CSS. Only the delivery changes. That script is the
deleted `rewrite_css()`'s only surviving copy, which is why the ordering trap is restated in its
docstring rather than left in the commit that removed it.

**The first version of that script was wrong, in the one direction that reads as success — and
an independent review caught it, not the tests.** It read Envira's record only. But on a
*migrated* site the record Lichtbild renders from is `_lichtbild_gallery`, and Lichtbild's own editor
wrote CSS into that one, so any gallery whose CSS was edited after 2026-08-07 has its current
value there and a pre-migration value in Envira's. The exporter would have returned the older
text and reported success, because **older CSS is perfectly valid CSS** — there is no
malformedness to notice, no error to raise, and the only way to know is to compare the two.

Two things make that worse than an ordinary stale read. The window closes by itself: saving a
gallery under 26.8.22 rewrites the v2 record through the new allowlist, which has no
`custom_css`, so the newer copy is dropped at that moment while Envira's survives — the export
therefore has to happen before the next save, not merely before some future cleanup. And the
release notes said *"nothing was deleted"* and *"still stored in the original gallery record"*,
which was true of Envira's copy and false of the one that mattered. **A claim about data
retention has to name the record**, because "the gallery record" is two records here and the
project's whole design is that they can disagree.

The fix reads both, prefers what `Lichtbild_Repository` prefers, prints both and exits non-zero
when they differ rather than choosing for the reader. Its decision function is now importable so
it can be tested at all — `tests/export-custom-css-test.py`, 15 checks, outside CI because it
covers a one-shot tool rather than the plugin — and the check that matters was shown to go red
by inverting the migrated preference, which is exactly the first version's bug.

**A removal needs guards, and the useful ones are not keyed to the thing removed.** Four new
checks: the conversion drops the key, `sanitize()` drops it, the settings form offers no
textarea, and — the one that matters — *no gallery emits a style element*, asserted over the
whole rendered population rather than over the gallery someone remembered. That last is worth
nothing on its own: a corpus in which no gallery ever *had* custom CSS satisfies it by having
nothing to print. So it is paired with a control, `envira css in the corpus is not zero`, that
reads the Envira records through `get_post_meta()` and requires at least one to carry CSS.
**The control failed on its first run** — for the right reason in the wrong place: it read
`$record['config']` where the fixture exposes `$record['data']['config']`, so it was asserting
against a shape the reader never sees. A control that passes immediately is the one to distrust.

Deliberately not keyed to the removed setting: mutation `CSS1` re-introduces a `<style>` built
from the gallery *title*, nothing to do with `custom_css`. The guideline is about the element,
not about the field that used to feed one, and a check written against the old field would go
green the day somebody prints CSS from somewhere else.

**The three mechanical findings, one of which is not what it looks like.** The `<style>` output
was cited separately as "use wp_enqueue commands" — it disappears with the feature, so it needed
no separate fix. The bundled German `.po`/`.mo` had to leave the package, because the directory
serves translations from translate.wordpress.org and a bundled copy at best duplicates them. And
`load_plugin_textdomain()` had to go with it: its own comment had carried the condition
*"remove it once the bundled catalogue is dropped in favour of the directory's, not before"*,
and that condition had now been met by the finding above it. The catalogue is excluded from the
zip by `.distignore` rather than deleted, because **the site this was built for does not install
from wordpress.org** — it runs WP 7, where the textdomain registry finds a catalogue in
`languages/` from the `Domain Path` header alone, so German survives the call going away.

The fourth finding, on nonces, cited no file and is boilerplate. The one place it could point at
is `Lichtbild_Ajax`, which verifies and deliberately does not refuse so that pagination survives a
full-page cache; both endpoints are reads gated on `is_viewable()`. That reasoning goes in the
reply as context rather than as a change, which is the one thing their instructions actually ask
for: findings and clarifications, not a list of edits.

### The CRLF checkout, which had been quietly disabling the mutation harness

Not part of the review, found while running it. This repository had **no `.gitattributes`**, and
Git for Windows ships `core.autocrlf=true` in its system config, so the Windows desktop's working
tree was CRLF throughout while every blob in the repository is LF.

`tests/mutations.php` matches its target text exactly, and its targets are written with `\n`. On
that checkout **every multi-line mutation matched nothing** — including pre-existing ones like
`E1`, untouched for weeks. The harness was honest about it, reporting `BROKEN: target text occurs
0 times` rather than `SURVIVED`, which is the entire reason that rule exists and is the only
reason this was visible at all. But the effect was that most of the suite's proof of
falsifiability had silently stopped running on this machine, while single-line mutations kept
killing their checks and the run looked normal.

The same defect is the one `AGENTS.md` already warns about for the deploy: `tools/deploy.sh`
uploads working-tree bytes and verifies each file against *that same local file*, so a whole-tree
CRLF conversion would be reported as 40 files "uploaded and verified". The rule had been written
down and never enforced. `.gitattributes` now pins `* text=auto eol=lf` with explicit `binary`
rules, and the tree was normalised: 69 files converted, 2 already LF, 5 skipped as binary. The
measurement that says it was worth doing is that `E1` went from `BROKEN` to `KILLED` with no
change to `E1` — and the full pass then ran **206 mutations, 206 killed by the predicted check**,
the first clean mutation run this box has ever produced.

## Round 2 found two design decisions and one real one

`R atelier/tstone1/14Aug26/T2`, 2026-08-15, one day after 26.8.22 was uploaded. Three findings.
The distribution is the point: **one was a hole, and two were correct readings of code that is
deliberately what it is.** A review that names a bug tells you what to change; a review that
names a decision tells you to defend it — and getting that classification wrong in either
direction is expensive. Defending a hole loses a round; changing a decision loses the feature.

**The real one: `Lichtbild_Config::sanitize()` passed the raw submission to its filter.**

```php
return (array) apply_filters( 'lichtbild_config_sanitize', $out, $input );
```

`$out` is the allowlisted, typed record. `$input` was there as context, and it is an unsanitised
`$_POST` array handed to every callback on a public hook — **a sanitising function offering a way
around itself.** Nothing in this plugin used it and nothing outside it could have yet, which is
exactly why it was cheap to remove and exactly why it would never have been noticed: the return
value is byte-identical either way.

That last property decides the shape of the check. No behavioural assertion can see this, so the
stub `apply_filters()` now records `func_num_args()` per hook and one check reads it — hook name
plus value is two, and a third argument is the raw input by any other name. Mutation `B98`
re-adds `$input` and is killed by that check alone. **A public API decision that changes no
output needs an instrument aimed at the API, not at the output.**

**The two decisions: the Envira shortcode names, and the Yoast option write.**

Flagged under "all plugins must have unique function names ... options must be prefixed":

```php
add_shortcode( 'envira-gallery', array( $this, 'gallery' ) );
update_option( 'wpseo_titles', array_merge( $titles, $added ) );
```

Both are correct as findings and wrong as diagnoses. The rule is about *claiming a generic name
for your own things*; these deliberately name **another plugin's** things, which is the entire
job — keeping `[envira-gallery]` rendering on a site whose 49 published posts contain it, and
keeping Yoast's canonical on 58 indexed URLs whose post type the migration renamed out from under
it. Neither has a prefixed alternative that does anything.

**But one of them still became a code change, and that is the lesson worth keeping.** The
takeover guard was `should_take_over()`, which in `auto` means *Envira is not currently active* —
and on a site that has never had Envira that is true, so Lichtbild registered `[envira-gallery]` on
a site where no post contains it and no Envira has ever run. **The rule being enforced was
correct for a site migrating from Envira and was being applied to every site**, which is the same
defect 26.8.18 fixed for the post types and the URL slugs, one release earlier, in code the fix
for this one now reuses:

```php
public function claims_envira_shortcodes() {
    return $this->should_take_over() && 'envira' === $this->slug_scheme();
}
```

The two halves answer different questions on purpose — the mode answers *is Envira running*, the
scheme answers *was Envira ever here* — and reading the scheme rather than re-querying is what
stops a gallery's shortcode and its permalink disagreeing about the site's history years later,
after Envira's rows are deleted.

Three details of the checking, none of which follow from the design:

- **The new check runs under `always`, the mode most likely to claim the tags.** A generic site
  refusing under `never` cannot be distinguished from a generic site that was never asked.
- **Its control is the same call with `'envira'`.** "Claimed no Envira tags" is equally true of a
  registry that claimed nothing, which is what a gutted `register_shortcodes()` produces — the
  failure this suite has met more than once.
- **Reading the scheme changes it.** `slug_scheme()` records its answer on first read, so the
  test closure has to restore the option to *unset* when it was unset, or a later check reads the
  closure's work instead of the fixture's.

And the predicate exists as a method rather than as the same condition written twice because
`Lichtbild_Assets::maybe_enqueue_early()` scans post content for the identical tag list. Two copies
of "which shortcodes are ours" drift, and the direction they drift in is silent: `has_shortcode()`
returns false for an unregistered tag, so the scan would simply stop matching and the assets would
arrive late, with nothing red.

## Round 3 rejected the name, and nothing else

`R ❗TRM atelier/tstone1/14Aug26/T4`, 2026-08-22, seven days after 26.8.23 was uploaded. It is
the third review message and the second human-facing round; the counter skipped T3 because T3 is
our own reply to round 2, which this message quotes. The opening sentence is the whole content:
*"We have pended your submission without looking at the code in order to help you correct this
issue."* Nothing in the plugin was read.

**The finding was right, and checking that took one HTTP request.** The reflex on a review that
did not read the code is to argue with it. The directory's own search API settles it instead:

```
api.wordpress.org/plugins/info/1.2/?action=query_plugins&request[search]=atelier
```

Three published plugins lead with the word — `atelier-product-sorting-for-woocommerce`,
`atelier-scroll-top`, `atelier-create-cv`. Against Guideline 17, a bare common noun already
carried by three listings is exactly what "must be distinguishable" excludes. There was no round
to win, so the name changed: **Lichtbild Gallery**, slug `lichtbild-gallery`, which returns 0
results from the same query and 404 from the per-slug endpoint. Measure the name before choosing
it; the same call that convicts the old one clears the new one.

**A rename is three case-sensitive substitutions and a control**, which is how 26.8.16 did it and
why it was trustworthy: the count of `envira` occurrences was **1270 before and 1270 after**.
That control is not ceremony. `atelier` and `envira` share four letters and `lichtbild` shares
none, so this run was safer than the last one — and it is still the only evidence that the
compatibility layer, which is the whole reason this plugin exists, was not caught up in the pass.
`lichtbild` finished at 3692 against 3700 for `atelier`, the difference being exactly the eight
deliberately protected strings: two wordpress.org review-thread IDs, the separate private
`tstone-1/atelier-history` repository, and the old slug named in prose.

Four things this rename cost that the last one did not, all of them found by an instrument rather
than by reading:

**The text domain is not the identifier prefix, and 62 of 299 literals proved it.** A blanket
`'atelier'` → `'lichtbild-gallery'` is wrong: 237 of those literals are the text-domain argument
of an i18n call, and the other 62 are the asset handle, the nonce action, the settings group, the
`plugins_loaded` callback name and the migrated-record array key, all of which must become
`'lichtbild'`. They are separable by shape — a text domain is the last argument, so it matches
`, 'atelier' )` and nothing else does. Classify and count first; the classifier printed 237/62
and flagged one apparent ambiguity that turned out to be the `add_options_page()` menu slug.

**`class-atelier.php` ends in `atelier.php`, and that collapsed two files onto one name.** The
rule rewriting the main file to `lichtbild-gallery.php` fired inside every reference to the
container class, so `class-atelier.php` and `class-atelier-gallery.php` both became
`class-lichtbild-gallery.php`. PHP caught it immediately — `Cannot redeclare class
Lichtbild_Gallery`, from a file required twice — but the point is what could not be done next:
**the collapse is not invertible by inspection**, because after it the two names are the same
string. The recovery is `git reset --hard`, fix the rule order, re-run; there is no repair pass.
Order longest-first is not enough when one filename is a suffix of another, so the rule now names
`class-atelier.php` explicitly, above the one it would otherwise be eaten by.

The instrument that would have caught it without a fatal is cheap and is now part of the pass:
after substituting, resolve every `'includes/...php'`, `'assets/...css'` and `'blocks/...json'`
literal in the tree against the filesystem. 114 paths, all present, one second.

**The `.mo` is a binary the substitution cannot touch, and a stale one fails silently.** Every
`msgid` in `languages/*.mo` still named the old identity while the PHP had moved, so gettext
would have missed on all of them and rendered the German admin in English with nothing logged —
the same failure mode recorded at 26.8.13, arriving by a different route. `msgfmt` from the
substituted `.po` fixes it, and the control is the count: **55 occurrences before, 55 after**,
moved rather than lost. The `.pot` is regenerated with `wp i18n make-pot`, which derives
`Report-Msgid-Bugs-To` from the containing directory name and therefore needs its three custom
headers restored afterwards.

**The sort-order literal went red again, in the opposite direction.** 26.8.16 recorded one check
of 220 failing because `the shortcode registry follows the takeover setting` compares against a
literal array in `sort()` order, and `atelier` sorts *before* `envira` where `tivira` sorted
after. `lichtbild` sorts *after* `envira`, so the same two literals had to move back. A literal
encoding a derived property is precisely what a rename cannot update — and this is now the second
consecutive rename where that check was the one to fail, which is worth more than the fix: it is
the same check earning its place twice, and both times the report told us exactly which property
had no test forcing the distinction.

Everything else held: 238 checks green on all five PHP versions with none skipped, the check-name
**set** identical to baseline once the same substitution is applied to it — the instrument that
distinguishes a check going red from a check quietly ceasing to exist — 209 of 209 mutations
killed by the predicted check, and the harness restoring every file it edited by digest.

## The third review, and the finding whose recommended fix was worse than the defect

An independent read-only review of the whole codebase at `511df7e`, 2026-08-22. Two blockers,
seven warnings, three nitpicks, and every one of them real — which is unusual enough to say, and
is not the interesting part. The interesting part is what happened when they were checked.

**Two findings were traced by the reviewer and decided by execution here.** The review said so
itself about the first: *"verified by tracing the complete uninstall -> settings initialization ->
registration/repository state transition; not executed against a live WordPress database."* That
is an honest confidence statement and it is exactly the invitation to run it. On a full copy of
the live site: migrate, apply the four `delete_option()` calls `uninstall.php` makes, reload.
`has_migrated()` answered no, the registered types came back `envira, envira_album`, the 52 rows
sat under `lichtbild_gallery`, and the gallery permalink returned **36857 bytes titled "Page not
found" — the same byte count as a deliberately bogus slug.** Before the deletions the same URL
served 61915 bytes of gallery. A traced finding is an argument; that is a measurement, and it is
what made the fix obviously worth its risk rather than plausibly worth it.

The same instrument settled `compare` in one command: it printed `changed 1`, listed the URL, and
exited **0**.

**And then the recommended fix for W3 would have caused the blocker.** The finding is right —
`migrate()` advanced the schema without reading back the standalone-option copy or the Yoast
write, so a targeted failure was silent and the success notice reported copied keys that never
landed. The suggested repair was *"add an error before `set_schema()`"*. Errors in this codebase
gate the flag: `set_schema()` runs only while `$result['errors']` is empty, and the screen shows
errors **instead of** the result. So taking that advice literally means a failed Yoast write
leaves the rows named `lichtbild_gallery` while the schema says unmigrated — which is precisely
the state measured above, where every gallery is present and unreachable. **Losing a Yoast title
is a bad day; hiding every gallery is a broken site.**

The fix is a third channel: `$result['warnings']`, verified by readback, rendered beside the
success notice rather than instead of it. The schema still advances, because by then the rows have
already moved and the schema is what lets anything find them. What was actually missing was never
the refusal — it was that the failure arrived as nothing at all.

The general rule, and it is the one this repository keeps relearning from outside reports: **verify
the fix against the codebase, not only the finding.** A reviewer reads the function; the fix lands
in a system. Both halves of this review's own advice were sound in isolation.

### Four ways the new tests were nearly worthless, all caught by the harness

None of these was caught by reading. Each is the mutation harness or the check-name count
refusing to accept a test at face value, which is the whole reason both exist.

**A test whose subject comes from the code under test disappears when that code is mutated.** The
first draft picked the unauthorised gallery by calling `gallery_choices()` — the very method whose
capability filter was being tested. Six unrelated mutations then reported `240 checks, baseline
243`: three checks had **left the report** rather than failed, which reads as "not applicable" and
is indistinguishable from coverage lapsing. Taking the subject straight from the fixture fixed it.

**`expect()` one level too deep is the same as no `expect()` at all.** Declaring the three
conditional checks inside `if ( $album_edit_id > 0 )` still let them vanish, because a mutation
that empties that condition skips the declaration with it. Every other `expect()` in the file sits
at column 0, and that is not a formatting habit.

**A behaviour change can silently retire an existing mutation.** `E5` had killed *an unmigrated
site refuses to save* for as long as it existed; after the uninstall fix it **SURVIVED**. Nothing
was wrong with the mutation. The check expressed "unmigrated" as a *deleted* schema option, and a
deleted schema option now means "uninstalled and reinstalled" and is repaired back to migrated —
so the post-type guard did all the refusing and the guard `E5` removes was never exercised. The
comment directly above that check already warned about this exact collapse, from the other
direction. Two setups had to say `schema = 1` explicitly. **A fix that changes what a state means
invalidates every test that expressed that state by proxy**, and the only thing that reported it
was a mutation surviving.

**Two more mutations went `BROKEN` because their `find` text had moved** — `SEO1` and `B86`, both
anchored on lines this change edited. That is the harness's honesty rule paying off: a target that
matches nothing is `BROKEN`, never a pass, so re-anchoring is forced rather than optional.

### Two instruments that lie in the same direction

**Plugin Check derives the expected text domain from the DIRECTORY NAME.** Checking the shipped
archive extracted to `zzcheck-lichtbild-gallery/` reported **24 `TextDomainMismatch` errors** that
say nothing whatever about the plugin. The working tree is symlinked into the local WordPress
under the real slug, so checking the *shipped bytes* means parking that symlink and extracting the
zip in its place. Otherwise the thing measured is the tree, not the release.

**And the claim it was supposed to support had gone stale in the most instructive way.**
`AGENTS.md` said Plugin Check reported 0 errors and 0 warnings. It reported one, and had since
26.8.22: the `upgrade_notice_limit` rule caps a `readme.txt` upgrade notice at 300 characters, the
26.8.22 notice was 379, and **that notice was added by 26.8.22** — the claim was written true and
falsified by the very release it was written for. Measure all four notices, not the newest: only
one was over, and a spot check of the latest would have found nothing.

### The near-miss, which cost nothing and was the largest risk of the day

Backing up a gitignored file before editing it produced `tools/deploy.env.bak-before-rename`,
which `.gitignore` did **not** cover — the rule was the exact path, `tools/deploy.env`, not a
glob. That file holds the deployment host and account, in a public repository whose entire *Site
access* section exists to keep exactly those two strings out of it, and `git add -A` would have
committed it. The rule is now `tools/deploy.env*`, with a control asserting `tools/deploy.sh` is
still not ignored, because a glob that swallows the deploy script is the other kind of mistake.

**A gitignore protects a path, and a backup is a new path.** The habit that closes it is the one
already written down for values rather than filenames: after any operation that copies a
credentials-adjacent file, run `git status --porcelain` and read it, rather than trusting that the
rule which covered the original covers its copy.

## Publishing gave the live site a second update channel, and the two disagree by design

2026-08-27, an hour after the first SVN commit. WordPress matches an installed plugin to the
directory **by folder slug**, and this site's plugin folder is `lichtbild-gallery` — the slug that
had just been registered. So a site that had exactly one way to receive this plugin, the FTPS
deploy that digest-verifies every file and captures 116 URLs either side, silently acquired a
second: `api.wordpress.org`, arriving as an ordinary update, with none of that.

Both channels were on `26.8.25`, so nothing was offered and nothing was at risk that day. The
hazard is latent, and it has three shapes:

| state | what happens |
|---|---|
| site ahead | no update offered — but the site runs code the directory cannot reproduce |
| directory ahead | an update is offered, possibly applied automatically, and the site changes without one check this deploy performs |
| **same version, different bytes** | invisible. A file deployed without a version bump is flattened by the next update |

The third is the same blindness `audit` already exists for, one layer up: **comparing version
numbers is comparing a label**, exactly as comparing byte counts was before the digest check
replaced it, and this repository has already paid once for believing a label.

`tools/deploy.sh channels` compares the local shipped tree against the archive the directory
actually serves for the same version, and `push` runs it before touching the server. It needs no
deployment target — it never opens an FTP connection — so `tests/deploy-channels-test.sh` proves
all nine of its verdicts in CI with no credentials and no network, via `--against <zip>`.

**Its first run found something no one had thought about, and it is the useful half of this
entry.** `languages/lichtbild-gallery-de_DE.mo` is deployed to the site and deliberately excluded
from the wordpress.org build, because a hosted plugin gets its translations from
translate.wordpress.org. Correct in both places, and it means **a wordpress.org update deletes the
German catalogue from a site whose pages are `lang="de"`** — the 28 visitor-facing strings that
26.8.13 exists to have translated, silently back to English, with the plugin still reporting the
same version. That is the concrete argument for keeping plugin auto-updates off for this install,
and it was invisible until something compared the two channels file by file.

A `SERVER_EXTRA` file absent from the directory build is therefore reported `[BY DESIGN]` and not
as a difference — a finding that fires every run stops being read, which `shipped_files()` already
says about `SERVER_EXTRA` names that no longer exist — but it is still counted and named as a
`[HAZARD]`, because "expected" and "harmless" are not the same word.

One check in it is there because of a mistake made ninety minutes earlier. A comparison against
the directory's still-generating zip reported all 41 files as differences: the download was
truncated, the extraction produced nothing, and **an empty operand renders as a total mismatch**.
`channels` therefore refuses an archive `unzip -t` cannot read, exits 2, and prints no difference
lines at all — asserted directly, because "every file changed" is the most alarming thing this
tool can say and it must not be able to say it for that reason.

**Closing this properly is a language pack, not a toggle**, and both halves are worth writing
down. The toggle first: the live site manages updates through **Companion Auto Update**, which
*replaces* WordPress's native per-plugin auto-update column — so `plugins.php` showing no
auto-update control is **not** evidence that updates are off, which is exactly how it read on
2026-08-27. The setting lives in that plugin's own screen, and Lichtbild is excluded there now.

The permanent fix removes the disagreement instead of managing it: submit the German catalogue to
translate.wordpress.org, and the directory delivers it as a language pack, after which the `.mo`
need not be deployed at all and the two channels ship identical bytes. The catalogue was already
ready — 206 entries, 100%, matching the `.pot` in both directions, `msgfmt --check` clean — and
`de/default` is the right project because the translation is **informal**; a grep for formal
address finds only *"Sie ist leer"*, which is the gallery, not the reader. Importing a `.po`
needs Project Translation Editor rights, which the plugin author requests on
`make.wordpress.org/polyglots`. Three formatting details are load-bearing and none is guessable:
the locale tag is the **WP Locale** from the teams page (`#de_DE`, not `#de`) in plain text, the
line must begin with a lowercase `o` and a space so it renders as the reviewers' checkbox, and
the plugin URL takes a leading minus so it does not expand into a preview. Variants like
`de_DE_formal` are granted with the main locale and must not be requested separately.

**A submitted post is not a published post, and an unpublished one notifies nobody.**
make.wordpress.org gives a first-time contributor the Contributor role, so Publish becomes Pending
Review and the `#de_DE` tag — the entire notification mechanism — never fires. It looks identical
to a request quietly waiting in a queue. Verify by fetching `?p=<id>` logged out: a published post
answers **301** to its permalink, an unpublished one **404**, and the control is any other post id
from the same page. Two more instruments agree cheaply and share no failure mode with that one:
the RSS feed lists published posts, and the front page carries a dozen other PTE requests. Do not
read the first 404 as the answer on its own — the site is a JavaScript app, so a `--dump-dom`
scrape of the post reported "plugin URL present: False" purely because it had matched the
template rather than the content, which is a false negative that would have sent us editing a
post that was fine.
