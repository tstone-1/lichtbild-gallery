# Lichtbild — deploy records

One entry per release that reached `timo-stein.com`, newest first, verbatim and in the
order they were written. `AGENTS.md` holds the procedure and one line per entry; this is
what those lines point at. Read the last few before running `tools/deploy.sh`, and read
26.8.7 and 26.8.10 before trusting `plan`.

They sat under a lessons entry in `AGENTS.md` by accident of chronological appending;
being a genre with one obvious retrieval trigger — you are about to deploy — is what
earns them a file. Everything else that moved out is in [`lessons.md`](lessons.md).

## The records at a glance

One line per record, newest first. This list lived in `AGENTS.md` until it reached a seventh of
that file, which is the same argument that moved the records themselves: knowing a record
*exists* is most of its value, and the paragraphs matter only once you are already here.

`tests/docs-index-test.php` checks every line below against the headings in this file, in both
directions — a hook naming a record that is gone fails, and so does a record with no hook. An
index that is not checked is a list of claims.

- *The 26.8.26 deploy, where a changed-nothing result is the correct one* — two files, 160 of
  160 URLs byte-identical before and after, because the fix is an activation hook and activation
  does not run on a site that is already active. Holds the first publish where `channels` was
  green in both directions, the `--from <zip>` run that proved the release archive itself, and
  why `audit` naming only two files was the whole ordering answer.
- *The 26.8.25 switchover, verified against the database rather than against itself* — `compare`
  reported **160 of 160 changed**, which after a type rename is the expected artifact and worth
  nothing; the check that answered the question compared each gallery's rendered images against
  Envira's untouched record, an absolute source rather than a before/after guess. 50 of 50
  correct. Holds why byte-modulo-substitution failed, and the one gallery that rendered nothing.
- *The 26.8.24 deploy, which is a first install and is only half done* — the rename put every
  file under a new name in a new directory, so `UPLOAD_ORDER` is the whole deployed set and no
  audit of the old directory could have produced it. `plan` worked that out from the server by
  itself. The upload is verified and inert; the swap needs wp-admin and had not been run when
  this was written.
- *The 26.8.23 deploy, where "unchanged" was the whole point and was checked in the database
  first* — the new predicate reads the site's recorded slug scheme, so `generic` would have
  unrendered `[envira-gallery]` in 49 posts; the live option said `envira`, which made the
  release a no-op. `0 changed` proves nothing here — the digest re-verification and `?ver=`
  do. The order points the usual way and `plan` cannot see it either way.
  - *`deploy.sh audit`, written after the fifth stale UPLOAD_ORDER rather than the sixth* — the
    "ask the server" step every record since 26.8.21 recommends, kept this time. Its universe is
    derived from `.distignore` the way the zip is, plus the one deployed file the archive
    excludes; it fails closed in three places; and its test proves the verdicts apart offline.
- *The 26.8.22 deploy, the first that was SUPPOSED to change rendered bytes* — every release
  since 26.8.15 predicted `0 changed`; here it would have meant the upload never landed. The
  discriminating check is an **anonymous** `<style>`, since core and the theme emit six with
  ids. Settles the WP 7 textdomain question on production, and names two things it could not see.
  - *The CSS this release removed had never applied to anything* — every declaration in all
    twenty blocks was commented out, so the browser parsed an empty rule and the removal changed
    no pixel. A count of non-empty strings had stood in for a measurement of effect for the life
    of the project, and one `grep` would have settled it at any point.
  - *The 26.8.22 ordering constraint, which pointed backwards* — the first release to DELETE a
    method, so the CALLER uploads first; `plan` said "satisfied" over the order that would have
    been a fatal on the front page and fifty permalinks. `UPLOAD_ORDER` stale for the fourth
    time, re-derived by digest-auditing the server rather than diffing a tag — which found a
    commit that edited five shipped files after the last deploy and never shipped.
  - *The check that now enforces it, and the six cases that prove it can fail* — three outcomes
    rather than two; `order-check` is a subcommand so it runs offline in CI; C and F are the
    controls that make the other four mean anything, and one branch is honestly uncovered.
- *The 26.8.21 deploy, whose release was found on an install that had never been done* — a
  deliberate no-op on the only site it reached, which is the recommended shape; `UPLOAD_ORDER`
  held the previous release's list **again**, after that was written down, because the note
  says re-derive and the script says nothing; and a control that grepped a `.php` URL measured
  the host's willingness to execute PHP, not the file.
- *The 26.8.20 deploy, where "0 changed" was RIGHT and my reason for expecting otherwise was
  wrong* — `capture` strips `?ver=` before hashing, so it is structurally blind to the only
  page-level evidence a CSS-plus-version release produces; a prediction derived from an
  assumption about your own instrument is the instrument agreeing with itself. What
  established the release was a live contrast measurement, and the control that says the
  probe can still see the defect.
- *The 26.8.19 deploy, where "0 changed" was again the prediction and again proves nothing* —
  neither change touches a rendered byte, so `capture` was going to say 160/160 whether the
  upload landed or never happened. Four before-and-after behavioural controls instead, and the
  `-1` in their "before" column is the bug reproduced from the command line.
  - *The ordering constraint that is not about PHP, now a line of code rather than a note* — an
    asset must precede `lichtbild-gallery.php`, which carries the `?ver=` it is cached under; no grep can
    see that edge, and it bound twice in five releases. `plan` now refuses it.
- *The 26.8.18 deploy, which fixed the install nobody here has ever done* — schema 1 means "still
  on Envira's storage", not "new", so a fresh install registered post types NAMED `envira` and
  refused to edit; 26.8.17 had fixed the paths and left the names.
  - *Two answers derived separately were wrong; one observation fixed it* — the fix's own first
    draft gave a brand-new install Envira's paths, because "migrated" had come to mean two things.
  - *A fix that hands the user a new footgun* — a fresh site is now migrated, so it must be
    refused the rollback it would otherwise be offered.
- *The 26.8.17 deploy, whose riskiest line ran on the server and decided something* — the slug
  scheme writes an option on first use and every gallery URL depends on the answer; it derived
  `envira` unaided, and the control is that `/gallery/<slug>/` does **not** serve.
  - *One ordering constraint, invisible to `plan` for the third release running* — a method
    arriving on an existing class, called on every front-end request. Three releases in a row now,
    which makes it the normal case rather than the exception.
- *The 26.8.16 deploy, which was a first install, a data rename, and a plugin swap* — ordering is
  moot when the directory is inactive, and `plan` says so instead of claiming satisfaction;
  `uninstall.php` had shipped three releases earlier and never reached the server.
  - *The sequence, and why it needed no new migration code* — a SQL rename would have corrupted
    16 galleries, because the stored CSS selectors sit inside PHP-serialised data where a string
    replace changes the length without the length prefix.
  - *Every number that came back "changed" was an instrument, and one of them was mid-sequence* —
    a tile counter keyed to a hardcoded class name, Yoast losing 58 canonicals to the rollback,
    and a protected-gallery check that had picked the wrong gallery entirely.
  - *The setting that does not travel with a rename* — a rename orphans every option keyed on the
    old name, and a fallback chain hides it until the thing it falls back to is deleted.
- *The 26.8.15 deploy, where "0 changed" was the prediction and proves nothing on its own* — a
  deploy that never landed produces the same number, because `capture` throws away the one thing
  this release changes. Four controls that answer what the comparison cannot.
  - *The bug was found by a person looking at the site, for the third time* — the Envira control
    that made it a regression rather than a preference, and the first time the local copy's
    ability to run both plugins has been cashed in.
  - *The control I nearly used instead would have said the opposite* — a Wayback snapshot whose
    theme stylesheet had not truly applied; ask whether a stylesheet's **effects** are present,
    not whether the stylesheet is.
  - *One ordering constraint, and it is not about PHP* — the stylesheet before `lichtbild-gallery.php`, or
    browsers cache the old file under the new `?ver=` and nothing corrects it until the next bump.
- *The 26.8.14 deploy, and the URL that had never once been checked* — the front page renders
  ten galleries and was in no deploy's verification surface; three checks failed and all three
  were my own instrument.
  - *The front page renders ten galleries and was in no deploy's verification surface* —
    enumerate the URL *spaces* a plugin can appear in, not a sample of the ones that came to
    mind.
  - *Three checks that failed and were all my own instrument* — a glob counted as an image, a
    "no-gallery" page with ten galleries, and a refusal that refused in German.
- *The 26.8.11 deploy, the first with an ordering constraint `plan` cannot express* — the
  constraint is on a returned array key, which no grep can see. Identical was the wrong
  expectation, and the semantic instrument was not taken — decide which one the release needs
  at `plan` time, not after the upload.
- *The 26.8.10 deploy, where the stale thing was the deploy script itself* — `UPLOAD_ORDER`
  still held the previous release's file list and `plan` cheerfully validated it. The version
  of record is what the server serves, not what the last release commit says.
- *The 26.8.8 deploy, which is what a boring one looks like when it is predicted* — no ordering
  constraint, derived and not hoped; identical required in advance, `ver=` the one thing
  required to differ.
- *The 26.8.7 deploy, where `plan` passed an order that would have taken the site down* —
  `abstract class` broke the name extraction and removed both spellings the consumer search
  relied on; `continue` on "I could not determine that" is a silent pass.
- *The 26.8.6 deploy, where the VERIFICATION TOOL was the thing that broke* — `live-urls.py`
  returned 49 URLs instead of 159 after the migration, and every downstream step would still
  have reported success.
- *The 26.8.5 deploy, which had no ordering constraint at all — and how that was established* —
  "no constraint" is a derived fact, and whether a require is new is a fact about the server
  rather than about this checkout.
- *The 26.8.4 deploy, and the constraint that inverted* — `lichtbild-gallery.php` went third rather than
  last. Re-derive `UPLOAD_ORDER` every release; do not edit the last one.
- *The 26.8.2 deploy, which was the first one that was boring* — identical was predicted from
  two queries against production, which is what makes 159/159 a pass rather than an ambiguity.
- *The 26.8.1 deploy, which took the site down for a few minutes* — whole-file upload failed 6
  of 6 and left the file at 0 bytes, which is a fatal on every page. Chunk by default; it is
  not the fallback.

## The records, newest first

### The 26.8.26 deploy, where a changed-nothing result is the correct one

The release that fixed the fresh-install 404, deployed and published on 2026-08-28. Two files —
`readme.txt` and `lichtbild-gallery.php` — and the interesting part is that **`compare` reported
160 of 160 URLs byte-identical, and that is the right answer rather than a failed deploy.** The
change is a `register_activation_hook`; activation does not run on a site that is already
active, and the site's rewrite rules have carried the gallery types since the migration. A
deploy whose visible effect is nothing is exactly what this one should be, which is only
reassuring because `capture`/`compare` can tell "nothing changed" apart from "nothing was
uploaded" — the digest check on the two files did that separately.

**`audit` answered the upload set in one call, and it was the whole ordering argument.** It
reported 38 files identical, 2 changed, 2 deliberately absent, and `plan` then derived: no new
requires, no versioned assets, no methods dropped — *"none, so nothing to sequence"* three times
over. An `UPLOAD_ORDER` of two files with no constraint between them is the easy case, and it is
still worth running `audit` first, because the question it answers is what the SERVER has, not
what this release touched.

**The release archive was tested before it was published, which is new.** `tools/devenv.sh fresh
--from build/lichtbild-gallery-26.8.26.zip` installed the actual archive into an empty WordPress
7.1 and ran all 37 fresh-install checks green. Until this release the fresh-install path could
only be tested against what the directory already served, which is a build without the fix in
it — see *The fresh install nobody had done* in [`lessons.md`](lessons.md).

**First publish where `channels` is green in both directions.** Site and directory both at
26.8.26, 0 differing of 42 shipped files, 1 by design. The order was deliberate: FTPS first, SVN
second, so the site was never behind the directory even briefly — a site behind the directory is
offered an update in wp-admin, and applying it is what deletes the German `.mo`.

**SVN, r3670765.** `rsync -a --delete --exclude .svn` from the unpacked archive into `trunk/`,
`svn copy trunk tags/26.8.26`, one commit for both. Verified by `svn export`ing the committed tag
back from the server and digest-comparing all 41 files against the local build — not by reading
the commit output. The password came from `pbpaste` over `--password-from-stdin`; it is not in
the login keychain, and `~/.subversion/auth/svn.simple/` is empty, so the next release needs it
from the same place again: `profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password`.

### The 26.8.25 switchover, verified against the database rather than against itself

The day `atelier` became `lichtbild-gallery` on the live site. The upload had been done and
proven inert a few hours earlier (26.8.24), so this record is about the three wp-admin steps and,
mostly, about how they were checked.

**`compare` reported 160 of 160 changed, 0 status differences, and that number is worthless.**
The post type appears in WordPress's own body classes, so any rename reports 100% changed —
recorded here since 26.8.07 and true again. What is new is that the command now *exits 1* on
that, which is correct behaviour meeting a case where the difference is expected: the gate did
its job and the human has to decide. Do not read a red `compare` after a rename as a regression,
and do not weaken the gate to make it green.

**The attempt to rescue the byte comparison failed, and is worth recording as a dead end.** The
idea was byte-equality modulo a *named, enumerated* substitution — undo the rename, undo
`aria-pressed`, undo the plugin directory move, and require the pre-switchover hash to come back.
It got to **59 of 160**: the tag archives, which enqueue none of the plugin's assets. The 101
gallery pages never reproduced, and three successive guesses at the residue (the plugin path, the
JSON-escaped form of that path, the new attribute) each changed nothing. The lesson is not which
substitution was missing. It is that **reconstructing bytes you did not capture is the wrong
instrument**: `capture` stores hashes, so there was nothing to diff against, and every step was a
guess with no way to see the answer. If a byte comparison across a rename is ever wanted, take a
`fingerprint` *before* the change — that is what it is for.

**What actually answered the question was the database, and it is a stronger check than the hash
would have been.** Envira's `_eg_gallery_data` is untouched by the migration by design, so it is
an absolute statement of what each gallery contains — no before/after capture involved. For every
published gallery: fetch the permalink, extract the rendered `data-lichtbild-item` ids, compare
against the active items in Envira's record, in order.

**49 of 50 matched exactly.** The 50th rendered nothing, and that is the site's only
password-protected gallery serving WordPress's password form instead of photographs — the
visibility rule working, not a defect. Checked rather than assumed: `post_password <> ''` on that
row, and exactly one such row on the site. So 50 of 50.

Around that: both albums render their covers, a tag archive answers 200 while a deliberately
bogus gallery slug answers **404** (so the URL probe discriminates), both AJAX endpoints answer
200 with real payloads while refusing the protected gallery and a nonexistent id with 404, and no
live page contains the string `atelier` anywhere while the same pages contain `lichtbild` 92
times — a grep with its own control.

**Two of the same day's fixes were confirmed live by accident, which is the nicest kind.** The
localized bag arrives as `i18n` carrying `Schließen` and `Vergrößern`, so the W1 key fix and the
German catalogue are both working; and a deliberately malformed AJAX request came back
*"Galerie nicht gefunden."* rather than in English.

**Three probe bugs on the way, all mine, all of the same family — an instrument that answers
without measuring.** A one-off hash used `printf '%s' "$body"` where `capture` pipes through
`sed '$d'`, so the trailing newline differed and **0 of 160** pages matched; proving the pipeline
against a recorded value first would have caught it immediately, and that check is now the first
thing the script does. The AJAX probe passed `id=` where the endpoint reads `gallery=`, so every
call 404'd — *including the control*, which is the tell: when the thing that must fail and the
thing that must pass agree, the probe is broken, not the site. And `GID=$(...)` aborted a whole
block, because `GID` is read-only in zsh; the error names a group id and reads like a permissions
problem.

**Cleanup, done the same day.** The old `wp-content/plugins/atelier/` directory and the three
`atelier_*` options are gone. Both were verified by a control rather than by the absence of a
complaint: the old `readme.txt` went **200 -> 404** while the live plugin's own readme stayed at
**200**, so the probe discriminates rather than failing on everything; and 160 of 160 URLs
compared `changed 0` across each deletion, which now means something because the same command
correctly exits 1 — as it did over the rename a few hours earlier.

Deleting the directory over FTP is one `lftp` session rather than one login per file. That is not
tidiness: this host IP-bans an account that opens ~100 failed logins, and this project has paid
for that once already.

**What stays, deliberately.** The `_atelier_gallery` (51) and `_atelier_album` (2) meta rows: a
complete second copy of the converted records, costing nothing, with Envira's own records beside
them — which is what makes a rollback a restoration rather than a reconstruction. The deleted
directory was Atelier 26.8.23 and is recoverable in full from git at `8a1647a`, which is what
made deleting it uninteresting rather than risky.

**Both deletions were refused by the agent harness's own guard and run by hand.** Worth recording
because it will recur: a recursive remote delete and a SQL `DELETE` are exactly the shapes that
get blocked, however clearly the work was authorised. The answer is not to find a way around it —
it is to write the operation as a script that states its own reversibility, prints before and
after, and can be read before it is run. Both are in the session's drop folder.

### The 26.8.24 deploy, which is a first install and is only half done

**Written before it finished, deliberately.** Three of its steps happen in wp-admin and cannot be
run from here, so the alternative to an incomplete record is no record of a half-changed
production site — which is the worse artifact.

**This is the one release where `UPLOAD_ORDER` is not the answer to "what changed".** The plugin
was renamed, so all 40 deployed files have new names inside a directory the server did not have.
`audit` could not produce the list, because not one of those paths exists in the old directory;
the list is instead the complete deployed set, derived the way `build-zip.sh` derives the
archive — shipped files, minus `SERVER_ABSENT`, plus the one file in `SERVER_EXTRA`.

**`plan` reached that verdict on its own, from the server rather than from the list**, and this is
the first release where its answer was *"constraints: none applicable"* rather than an ordering:

```
FIRST INSTALL: no lichtbild-gallery.php on the server, so this is a new plugin directory.
Ordering is moot: the directory is not in active_plugins, so PHP opens none of it
while it uploads. Activate only after every file is digest-verified.
```

That is the exact inverse of 26.8.7's hazard. There, a half-uploaded set was a half-executed
plugin on a live site; here the directory is inert until somebody activates it, so the upload
carries no risk at all and the risk moves entirely into the activation. The advice it prints is
therefore the whole safety property, and it was followed: **40 files, 0 mismatching**, each
downloaded back and digest-compared.

**The no-op was verified before the change, which is what the inert upload is for.** 160 URLs
captured before and after: `joined 160 of 160 rows, malformed 0, changed 0, status differences 0`.
A plugin directory can sit on the server proving it transferred intact while changing nothing a
visitor sees, and that separates "did the transport work" from "did the swap work" into two
questions asked at two different times.

**Two instruments had to be read from git rather than from the working tree**, and both would
otherwise have answered zero:

- `tools/live-urls.py` decides the post-type names from `lichtbild_schema_version`, which
  production does not have — it is still on `atelier_schema_version`. The renamed script therefore
  described a site that does not exist. **It refused rather than returning an empty list**:
  *"enumerated 0 permalinks and 0 tag archives ... this means the type names are wrong for the
  schema state, not that the site is empty."* That guard is the only reason this was a
  two-minute detour. The 160 URLs came from `git show <pre-rename>:tools/live-urls.py`, which
  still understands the names production actually uses — and it has to be run from **inside** the
  tree, because it derives the credentials path from its own location.
- `tools/deploy.env` is gitignored, so the rename never touched it: it still named
  `ATELIER_DEPLOY_HOST` while the script had moved to `LICHTBILD_DEPLOY_HOST`. Every gitignored
  file that carries a renamed identifier is invisible to a whole-tree substitution, and this is
  the one that stops the deploy dead.

**A near-miss worth more than the deploy.** Copying `deploy.env` to `deploy.env.bak-before-rename`
produced an untracked file that `.gitignore` did **not** cover — the rule was the exact path, not a
glob — in a public repository whose entire *Site access* section exists to keep the deployment host
and account out of it. `git add -A` would have committed it. The rule is now `tools/deploy.env*`,
with a control proving `tools/deploy.sh` is still not ignored, because a glob that swallows the
script would be the other kind of mistake.

**What is left.** Three steps need wp-admin:

1. **Roll the migration back** from Settings → the old plugin, while it is still active.
2. **Deactivate the old plugin, activate Lichtbild Gallery**, back to back.
3. **Run the migration** from Settings → Lichtbild.

**Step 1 must come before step 2, and the reason is not the one you would guess.** The worry was
that activating first would make the new plugin record the wrong URL scheme permanently — a site
recorded `generic` keeps that answer forever, and its galleries would move off `/envira/`. That
does not happen: `has_envira_history()` counts `_eg_gallery_data` and `_eg_album_data` postmeta,
which the migration never destroys, so it answers `envira` either way. The **real** hazard is
duller and worse. Activated while the rows are still named `atelier_gallery`, the new plugin finds
no `lichtbild_schema_version`, concludes the site is unmigrated, and registers Envira's type
*names* — so nothing matches the rows and every gallery URL 404s until the migration runs.

**The flush has to be a separate request from the migration, and the local rehearsal proved it by
failing.** Running `migrate()` and `flush_rewrite_rules()` in one `wp eval-file` left every gallery
permalink serving the **homepage**: the request had registered the types under their old names
before the rename happened, so the rules regenerated from those. The tell was not the status code —
all three URLs answered **200** — it was that a deliberately bogus slug answered 200 as well. A
control is what turned "the migration broke the permalinks" into "the flush ran in the wrong
request"; `wp rewrite flush` in a fresh process fixed it, and the bogus slug went back to 404. The
migration screen's post/redirect/get does exactly this, which is why the production run through
wp-admin is safe and the shortcut was not.

The rest of the rehearsal, on a full copy of production: activation recorded
`lichtbild_slug_scheme = envira`; the migration moved 52 galleries, 2 albums and 58 terms with 0
errors; `/envira/<slug>/` and `/envira-tag/<slug>/` both resolved afterwards while a bogus slug
404'd; Yoast's keys arrived on `title-lichtbild_gallery` and `title-tax-lichtbild_tag`; and the
three live suites passed 37 checks between them, each migrating, verifying and rolling back.


### The 26.8.23 deploy, where "unchanged" was the whole point and was checked in the database first

Deployed 2026-08-15 from the MacBook. **6 files, 11 chunks, every chunk first time, 0
mismatching**, `160/160` URLs semantically identical and `0` non-200, `ver=26.8.22` before and
`26.8.23` after. Eighteenth deploy with no failed chunk.

**This release decides, per site, whether Lichtbild answers to `[envira-gallery]` at all — so the
question that had to be settled before the push was what THIS site's recorded slug scheme says.**
`claims_envira_shortcodes()` is `should_take_over() && 'envira' === slug_scheme()`, and if the
second half had been `generic` here, the shortcode would have stopped resolving in 49 published
posts: not an error, not a 500, just the gallery quietly gone from every one of them. The answer
came from the live `wp_options` rather than from reading the code — `lichtbild_slug_scheme =
'envira'`, `lichtbild_schema_version = 2` — so the new predicate returns exactly what
`should_take_over()` returned before it, and the release is a deliberate no-op on the only site it
reaches. That is the shape this project keeps arriving at as the safest one, but it is worth
noticing that here it was a *finding* rather than a design: the same code on a site recorded
`generic` is a behavioural change, and nothing in the deploy would have said so.

**`0 changed` therefore proves nothing on its own, again, and two other things do.** `capture`
strips `?ver=` before hashing, so a release that moves no rendered byte is invisible to it whether
it landed or never left the laptop. What establishes the upload is `push` re-downloading all six
files and digest-comparing them **after the whole set had landed** — not only after each — and the
front page serving `lichtbild.css?ver=26.8.23` where it served `26.8.22` an hour earlier.

**The positive control is an embedding post, and it is the one that would have gone red.** A post
carrying `[envira-gallery id="N"]` renders its tiles and contains no raw `[envira-gallery` text.
The 160-URL comparison would also have caught the predicate answering `generic` — as 49 changed
pages — but only as a hash difference; this names the mechanism, and it is the check to run first
on any site where the scheme is not known to be `envira`.

**The ordering constraint points the USUAL way, one release after the only one that inverted.**
`Lichtbild_Settings::claims_envira_shortcodes()` is new, and both `class-lichtbild-shortcode.php` and
`class-lichtbild-assets.php` stop calling `should_take_over()` and call it instead — so the
definition lands first. Getting it backwards is not a gallery-page fatal but a **site-wide** one:
`register_shortcodes()` runs on `init` and `maybe_enqueue_early()` on `wp_enqueue_scripts`, so
every request on the site would hit "Call to undefined method" until the second upload finished.
`plan` printed `constraints: satisfied`, correctly by its own lights and equally over the fatal
order — `Lichtbild_Settings` was already required, already constructed, already there, and a method
added to an existing class is in none of the three questions it asks. `check_removed_methods()`
reported `none`, which is its emptiness case and is the right answer here rather than a silence.

**`UPLOAD_ORDER` was the previous release's list for the fifth time — and this is the release
where the audit that fixes it stopped being a throwaway script.** All 40 deployed files were
downloaded back and digest-compared: 34 identical, 6 changed, 0 absent, 0 unreadable, putting the
server exactly at the 26.8.22 deploy. Unlike last time no unshipped commit turned up — the six
changed files are exactly what 26.8.23 touched — but that is a fact the audit established, not one
a diff could have. The entry below is the subcommand it became.

### `deploy.sh audit`, written after the fifth stale UPLOAD_ORDER rather than the sixth

Every deploy record since 26.8.21 ends by saying the fix is to ask the server rather than to diff
a tag, and every one of those audits ran as a script outside the repository and was then thrown
away. The durable artifact was a comment that was stale again by the next release. `audit` is that
audit, kept: it downloads every deployed file, digest-compares it, and prints one verdict per file.

**Its universe is derived, not listed.** The set comes from `git ls-files` minus `.distignore` —
the same derivation `tools/build-zip.sh` uses — because an audit with its own hand-written list of
files would drift from the thing it audits, which is the defect it exists to end. Two named lists
sit on top, in opposite directions, and the second is the one that would have been missed:
`SERVER_ABSENT` (`LICENSE`, `languages/lichtbild-gallery.pot` — in the archive, never deployed) and
`SERVER_EXTRA` (`languages/lichtbild-gallery-de_DE.mo` — deployed since 26.8.13, and deliberately *not* in
the archive, because a directory-hosted plugin gets its translations from translate.wordpress.org).
Derive the universe from `.distignore` alone and the audit never asks about the one file whose
absence is silent: 28 strings quietly revert to English.

**It fails closed in three places, because each of them otherwise reports a clean bill of health
over nothing.** No `git ls-files` output, no `.distignore`, or a file that exists on the server and
cannot be read — all refuse rather than summarise. The first is not hypothetical: a missing `git`
once had a deploy script report 40 differing files and 201 phantom leftovers on a healthy site.

**The UPLOAD_ORDER cross-check runs in both directions**, since one alone is the dangerous half
missing: a file that differs and is not listed silently does not deploy, while a listed file the
server already matches costs one needless transfer. A third state is named rather than flagged —
nothing differs at all, which is what a just-deployed site looks like.

`tests/deploy-audit-test.sh`, in CI, is 13 checks over seven cases, and two of them are what make
the other five mean anything: **F** hands it a tree with no tracked files and requires a refusal
rather than a clean report, and **G** neuters the digest comparison and requires case B to stop
finding its change — if B still fired, B would be firing for some other reason. `audit --against
<dir> [file ...]` is the seam that makes all of it offline: a directory stands in for the server,
and the file list replaces `UPLOAD_ORDER`, so the cases assert about the check rather than about
whichever release is in flight. Same shape as `order-check`, for the same reason.

**One thing is deliberately not automated: `plan` does not run the audit.** It is 40 downloads,
and making every `plan` wait on the network to answer a question `plan` does not ask would be
paid on every invocation for a mistake that is made once per release. The audit is a step in the
recipe, ahead of `plan`.

### The 26.8.22 deploy, the first that was SUPPOSED to change rendered bytes

Deployed 2026-08-14 from the MacBook. `26.8.22` live on both assets, and the three URL spaces
verified independently from the Windows desktop over HTTP after the push: **49/49 gallery
permalinks, 2/2 albums and 5 of 40 sampled tag archives answer 200**, every gallery renders
photographs, and **not one page carries an anonymous `<style>` element**.

**"Identical" would have been the wrong answer here, and that is what makes this deploy readable
at a glance.** Every release since 26.8.15 has predicted `0 changed` and then had to argue about
what the comparison could actually see — `capture` strips `?ver=` before hashing, so a release
touching only an asset or a nonce is invisible to it by construction. This one removes the inline
`<style>` block from every page carrying one of the **twenty** galleries that had custom CSS, so
it moves rendered bytes on exactly those URLs and nowhere else. A `0 changed` here would have
meant the upload never landed.

**Twenty, not the sixteen this record first said.** Sixteen is the count in the survey line in
`AGENTS.md`, and that line counts galleries whose *Envira* record carries hand-written
`custom_css`; what this release removes is driven by the *v2* records, of which twenty carry it.
Measured three ways that agree: `tools/export-custom-css.py` reports `52 galleries examined
(migrated site), 20 carry custom CSS`; the live site carried **50** blocks across **41** pages
before the push; and 41 is 1 front page + 20 permalinks + 20 embedding posts. An inherited number
is the easiest kind to carry into a record — it was right about a different question.

**The check that matters is the absence of an ANONYMOUS style element, not of style elements.**
The front page carries six — `classic-theme-styles-inline-css`, `global-styles-inline-css`,
`twentytwenty-style-inline-css`, `wp-block-library-inline-css`, `wp-emoji-styles-inline-css`,
`wp-img-auto-sizes-contain-inline-css` — every one of them WordPress's or the theme's, and every
one carrying an `id=`. Lichtbild's removed element had none. Counting `<style>` tags outright
reports six on every page whether the release worked or not; the discriminating property is the
missing `id=`, and the sweep tests for that.

**The open question this release was carrying is now answered, on production.** Removing
`load_plugin_textdomain()` rested on WP 7's textdomain registry discovering the catalogue in
`languages/` from the `Domain Path` header alone — previously verified locally, and the single
most likely thing to have been mis-verified, because its failure mode is silent: 28 visitor-facing
strings revert to English with nothing logged. The live page ships `"close":"Schließen"`,
`"next":"Weiter"`, `"zoom":"Vergrößern"`, `"download":"Herunterladen"`, `"share":"Teilen"`,
`"copied":"Link kopiert"`. It works. **What is still unknown is which WordPress before 7 does
this**; an independent review put it at 6.8 and cited a make.wordpress.org post that returns 404,
so the header's `Requires at least: 6.0` still carries an unverified span. It costs nothing for a
directory-served copy, whose translations arrive in `WP_LANG_DIR/plugins/`.

**Two things this verification could NOT see, and both are worth naming rather than leaving as an
impression of completeness.** The sweep enumerated URLs from Yoast's sitemaps, which is a
different set from `tools/live-urls.py`'s 159: the sitemap excludes the password-protected
gallery, so the control that it still serves its form with zero upload filenames did not run
here — nothing in this release touches that path, but the check that would have proved it was
absent, not passing. And the Customizer had **no `wp-custom-css` block at the time of the sweep**,
which means the exported CSS had not yet been pasted in: WordPress omits that block entirely when
Additional CSS is empty, so its absence is evidence, not silence.

### The CSS this release removed had never applied to anything

**That last observation is sound and the conclusion first drawn from it was not: there is nothing
to paste, and no gallery is waiting to be styled.** Every declaration in all twenty exported
blocks is commented out — `/* margin-bottom: 20px; */`, `/* font-size: 18px; */`,
`/* font-family: "roboto"; */` and nothing else — so what the old plugin emitted was
`#lichtbild-2423 { }`, an empty rule. Grepping the export for a line that is not a comment, a
selector or a brace returns **zero**. The galleries are not "unstyled until it is pasted"; they
were never styled, the CSS has been inert for as long as it has existed, and pasting the export
would add 145 lines of comments that do nothing.

Two details make the point harder to doubt. Sixteen of the twenty blocks target `#lichtbild-2423`
regardless of which gallery stores them — written once on Harburg and copied nineteen times — so
even uncommented, most would style a gallery that is not theirs. And the offline render
comparison below shows the whole markup delta is the `<style>` element itself, 510 bytes across
four galleries, with nothing inside it that a browser acts on.

The export is still worth keeping, for a different reason than restoration: it is the record that
Envira's copy and Lichtbild's v2 copy agreed before any gallery gets saved under 26.8.22, which is
the point at which the v2 copy is dropped.

**`AGENTS.md` had asserted "16 galleries carry hand-written `custom_css`" for the life of the
project, and it was true at the byte level and false in every way that mattered.** That
measurement counted non-empty strings. It never asked whether any of them produced a
declaration, and nothing downstream asked either: `rewrite_css()` existed to keep this CSS
working, the `#lichtbild-wrap-N` versus `#lichtbild-N-wrap` defect was diagnosed and fixed *inside*
it, this release's notes worried about losing the data, and `tools/export-custom-css.py` was
written and tested to recover it. All of that was care spent on rules that had never applied.

**A stored value is not an applied value, and the cheap check is to ask what the browser would
do with it, not whether the string is non-empty.** The corpus already recorded that every
*wrapper* rule had been dead since the switchover, found by comparing the converter against the
renderer, which is the expensive way round. Worth carrying to any "N records carry X" claim: the
sentence that sounds like a measurement is usually a count.

### The 26.8.22 ordering constraint, which pointed backwards

The record above is the deploy; this is the thing that nearly went wrong in it. **12 files, 31
chunks, every chunk first time, 0 non-200,** `ver=26.8.21` before and `26.8.22` after.
Seventeenth deploy with no failed chunk.

**This is the first release that DELETES a method, and the familiar rule gives exactly the wrong
answer for one.** `Lichtbild_Gallery::custom_css()` is gone and `Lichtbild_Renderer` line 84 was the
file that stops calling it. Every ordering rule in five previous releases is about a definition
*arriving* — land it before its caller — so the reflex is to upload the class that owns the
method first. Do that and the **deployed** renderer, still on the server, is left naming a method
the new file no longer defines: `Call to undefined method` on the front page and fifty
permalinks, for as long as the second upload takes. The caller goes first. Renderer, then
gallery, then config — config third because the deployed gallery reads
`$this->settings['custom_css']` and config drops that key, a warning rather than a fatal but free
to avoid.

**`plan` printed `constraints: satisfied` over either order, and was right by its own lights.**
It asks which `require`s are new and whether an asset precedes the bootstrap; a deleted method is
in neither question, and its class-level grep cannot see it either — the class is still required,
still constructed, still there. One method went away. That gap is now a check with its own test;
the entry below is about it.

The other four functions this release removes are safe by construction and were checked rather
than assumed: `lichtbild_load_textdomain()` is defined and hooked in the same file, and
`Lichtbild_Config::css()`, `Lichtbild_Config::rewrite_css()` and `Lichtbild_Editor::row_css()` are all
private, so no other file can have been calling them.

**`UPLOAD_ORDER` was stale for the fourth time — four releases stale, under a comment still
describing 26.8.18's constraint.** What mattered was not re-deriving it but *where from*. Instead
of diffing against the release commit, every shipped file was downloaded back and digest-compared
against the local copy. That put the server at exactly the 26.8.21 deploy (`03d9edc`) and
surfaced something a `git diff` against a tag structurally cannot see: commit `9e3ab3c` edited
five more shipped files *after* that deploy and never shipped. `ajax`, `album-editor`,
`migration`, `settings` and `uninstall.php` are in this upload for that reason, not because
26.8.22 touched them. **Ask the server what it has.** The audit is 43 downloads and answers the
question the note has now failed to answer four times.

**The changed set was compared in both directions, and the count alone would have been nearly
worthless.** The pages carrying an inline `<style>` were measured on the live site *before* the
push: 41 pages, 50 blocks. `compare` then reported 41 changed — a satisfying number that a
coincidence could produce. Neither `comm` produced a row: nothing predicted stayed put, nothing
unpredicted moved.

**Even the matching set does not prove only the `<style>` moved, and that needed a different
instrument.** Four affected galleries were rendered offline at `03d9edc` and at `HEAD` through
the stub harness; strip the `<style>` elements from the old output and it is **byte-identical**
to the new — 510 bytes, no other difference. The control is what makes it mean anything:
unstripped the two differ, so the strip is doing the work rather than the comparison being
vacuous. Same move the migration record describes for the tile count, and cheaper than it looks —
the harness renders galleries already, and a worktree at the deployed SHA is one command.

**Three files were deliberately left absent from the server:** `LICENSE`,
`languages/lichtbild-gallery-de_DE.po` and `languages/lichtbild-gallery.pot`. None is read at runtime, none has ever
been deployed, and each is one more transfer that can fail for no behavioural gain. `LICENSE`
ships in the wordpress.org ZIP, a different artifact built by a different script.

### The check that now enforces it, and the six cases that prove it can fail

`check_removed_methods()` in `tools/deploy.sh`, run by `plan` and therefore by `push`. It compares
the **deployed** tree against the local one, so a method present there and absent here is exactly
a method this release deletes.

**Three outcomes, not two, because "I could not tell" must never be delivered as a pass:**

- A call that **survives** the release — some local file still calls a method nothing defines any
  more — is refused outright. No upload order fixes it. This also covers every caller *outside*
  the upload for free: a file not in the upload is byte-identical to the deployed one, so if its
  deployed copy calls the method, its local copy does too.
- The deployed caller **is** in the upload and is ordered at or after the file dropping the
  definition. Refused, naming both files.
- Another class still defines a method of that **name**, so `->name(` cannot say which object it
  belongs to. Reported as `AMBIGUOUS` for a human rather than guessed either way — resolving it by
  grep would be claiming a type checker the script does not have.

**`order-check <deployed-tree> [file ...]` is a subcommand rather than inline code, and that is
the load-bearing design decision.** It compares two directories on disk, takes its order from the
caller, and never opens a connection — so the check can be exercised without breaking a live
deploy, and it runs in CI on a runner with no credentials and no route to the host. A check
nothing exercises is a check nobody knows the state of.

`tests/deploy-order-test.sh` builds both trees from the **working** copy — not `HEAD`, because the
check under test is usually uncommitted when this runs — and adds methods to the reference, which
is the same thing as deleting them from the release without having to pick a real method whose
disappearance would break the fixture's own PHP. Six cases, each with its outcome declared in
advance:

| | case | expected |
|---|---|---|
| A | caller before definition | pass, and it names the pair |
| B | definition before caller | **fail**, naming the method |
| C | nothing removed at all | pass, and it says "none" |
| D | a call the release does not remove | **fail**, no order fixes it |
| E | the name is defined on another class | pass, reported for a human |
| F | case B with the caller-grep neutered | **pass** |

**C and F are the two that make the other four mean anything.** C is the emptiness control: a
check that walks an empty list passes exactly like one that walked a full list and found nothing
wrong, and this repository has shipped that defect before. F mutates the harness rather than the
code — if B still failed with the caller-grep returning false, B would be failing for some other
reason and would be saying nothing about the check. The mutation asserts its own landing by
digest, because a mutation that patched nothing reports the tests as fine.

**Validated against the real case, not only the synthetic one.** With a worktree at `03d9edc` as
the deployed tree, the order actually used passes and names the pair; the order `plan` would have
accepted fails with the fatal spelled out. It finds three dropped methods and exactly one ordering
pair, which is the right answer — the other two are private.

**What is not covered, stated because a gap nobody names is a gap nobody fixes:**
`fetch_deployed()`'s refusal branch — a file that exists on the server but cannot be read — needs
a network to exercise and no test reaches it. It is written to refuse rather than skip, on the
reasoning that an unreadable file and a file with nothing removed produce the same silence and the
silent one reads as a pass. That reasoning has not been demonstrated.

### The 26.8.21 deploy, whose release was found on an install that had never been done

**3 files, 4 chunks, every chunk first time, 160/160 URLs identical, 0 non-200,** `ver=26.8.20`
before and `26.8.21` after. Sixteenth deploy with no failed chunk.

Identical was predicted and is the correct answer here for a reason worth stating: the fix only
fires where `continues_envira()` is false, and this site's slug scheme is `envira`, so the
release is a deliberate no-op on the only site it was deployed to. That is the shape the
switchover record recommends --- deploy in a state where the change should be nothing, and check
that it is nothing --- and it is the first time it has been done on purpose rather than by
accident of what a release contained.

**`UPLOAD_ORDER` held the previous release's file list again.** `plan` validated it happily:
three files, constraints satisfied, and every one of them a file this release does not touch.
That is the 26.8.10 trap exactly, and the thing to notice is that it recurred *after* being
written down, because the note says "re-derive" and the script says nothing. Re-derived from
`git diff --name-only <deployed-sha> HEAD` restricted to shipped paths, which is the mechanical
form of the same instruction and should probably be what `plan` does itself.

`plan` did adapt correctly once corrected: with no asset in the set it reported **`versioned
assets in this upload: none, so no cache-buster to sequence`** rather than asserting a
constraint it could not have satisfied.

**One control was invalid and said so loudly, which is the only reason it was not believed.**
Fetching `includes/class-lichtbild-settings.php` over HTTP and grepping it for the new branch
returned zero --- because the host executes PHP, so the file exits at its `ABSPATH` guard and the
response is 200 with a **zero-byte body**. The instrument was measuring the web server's
willingness to run PHP, not the content of the file. The evidence that stands is the push's own
two-stage digest verification, which pulls each file back over FTPS and compares SHA-256: once
per file, and again for the whole set after everything had landed.

The controls that did hold: `ver=26.8.21` in the served HTML, which can only come from
`LICHTBILD_VERSION` in the `lichtbild-gallery.php` now executing; a gallery permalink still rendering ten
tiles; and 0 non-200 across all 160 URLs, which is what a fatal in a freshly uploaded class
would have shown up as immediately.

### The 26.8.20 deploy, where "0 changed" was RIGHT and my reason for expecting otherwise was wrong

**3 files, 4 chunks, every chunk first time, 160/160 URLs identical, 0 non-200,** `ver=26.8.19`
before and `26.8.20` after. Fifteenth deploy with no failed chunk.

The two records before this one say a version bump produces "0 changed" because the release
touches no rendered byte. That reasoning does not apply here and I predicted the opposite:
`?ver=` appears **in the HTML** of every page that enqueues the stylesheet, so bumping it should
have changed the hash of all fifty gallery permalinks. The comparison said 160/160 identical
anyway, which for about a minute looked like an upload that had not landed.

It had landed. `capture` strips the version before hashing --- `sed 's/?ver=[0-9.]*//g'` --- and
has since it was written. So the number was correct and the prediction was wrong, and the
difference matters: I had reasoned about what `capture` hashes instead of reading the four lines
that say. **A prediction derived from an assumption about your own instrument is not a
prediction, it is the instrument agreeing with itself.** The normalisation is deliberate and
right, because a `?ver=` change is exactly the noise a deploy comparison should ignore --- but
its consequence is that `compare` is structurally blind to the only page-level evidence a
CSS-plus-version release produces, and no amount of reading the number would have revealed that.

What actually established the release, none of which is a page hash:

- The served stylesheet's SHA-256 equals the local file's, which `push` verified per chunk and
  then again for the whole set after everything had landed.
- The served CSS contains **one** `.lichtbild-tag.is-current` block where it had two, and zero
  occurrences of either old rule.
- A headless browser loaded a **live** gallery permalink, built the button the stylesheet is
  meant to style, and let the engine resolve it: white on `#1a1a1a`, **17.4:1**.
- The control for that last one, which is what makes it evidence: the same probe, on the same
  live page, with the *previous* two rules injected, still measures **1:1**. Without it,
  "17.4:1" is equally consistent with a probe that cannot see the defect at all.

One thing worked that has failed three releases running: `plan` printed **`versioned assets
before the bootstrap: 1 of 1`** and derived it. The constraint --- an asset must precede
`lichtbild-gallery.php`, which carries the `LICHTBILD_VERSION` that is the `?ver=` the asset is cached under
--- was a prose note that bound twice in five releases before 26.8.19 turned it into code. This
is the first release where it had a file to order, and it ordered it without being told.

### The 26.8.19 deploy, where "0 changed" was again the prediction and again proves nothing

**4 files, 8 chunks, every chunk first time, 160/160 URLs identical, 0 non-200,** `ver=26.8.18`
before and `26.8.19` after. Fourteenth deploy with no failed chunk.

Neither change in this release touches a rendered byte. One is a query-argument handler, the
other is a regular expression inside a script — so `capture`, which hashes the page, was going
to say 160/160 identical whether the upload landed, half-landed, or never happened at all. The
26.8.15 record made this point and it is worth restating because it keeps recurring: a
comparison instrument that cannot see the change is not evidence about the change, and the
number it returns is the same number a failed deploy returns.

So the release is established by four controls that ask about the behaviour directly, run
before the upload as well as after, because a control with no "before" cannot distinguish a fix
from a thing that was always true:

| control | before | after |
|---|---|---|
| `lichtbild_items` with a wrong nonce, public gallery | `-1` | items, HTTP 200 |
| `lichtbild_page` with a wrong nonce, public gallery | `-1` | HTTP 200 |
| `lichtbild_items` with a wrong nonce, **protected** gallery | `-1` | `Galerie nicht gefunden.`, HTTP 404 |
| the served script's deep-link pattern | current prefix only | both prefixes |

The third row is the one that matters most and is the reason the first two are safe. A nonce
that can no longer refuse anything is only defensible while something else is doing the
refusing, so the same unusable nonce has to keep getting nowhere near a gallery it may not see —
and it does, in German, which incidentally says the translation catalogue is still applying.

**The `-1` in the "before" column is the bug, sitting on the live site, reproduced from the
command line.** WordPress answers a failed `check_ajax_referer()` with a bare `-1`, and that is
what every visitor got whose page had been served from a cache for longer than the nonce lived:
pagination and tag filtering silently stopped working, the lightbox could not reach images on
other pages, and nothing anywhere reported an error. `timo-stein.com` has no page cache, which
is exactly why it was never seen here — this is a defect that only exists on somebody else's
site, which is a category this project now has to care about.

**One control was a bad probe, and it read as a failed deploy.** The `?ver=` check came back
empty, which for a moment looked like the bootstrap not having landed; the URL it fetched
(`/envira/moose/`) simply does not exist on the site. Re-run against a URL taken from
`urls.txt` it returns `lichtbild.css?ver=26.8.19` and `lichtbild.js?ver=26.8.19`. **Take probe URLs
from the enumerator, never from memory** — this is the fourth time an empty answer here has
turned out to be the instrument rather than the finding.

#### The ordering constraint that is not about PHP, now a line of code rather than a note

`assets/js/lichtbild.js` had to land **before** `lichtbild-gallery.php`, for the reason recorded at 26.8.15
for the stylesheet: assets are cache-busted by `LICHTBILD_VERSION`, that constant lives in the
bootstrap, and landing the bootstrap first means a browser asking for `?ver=26.8.19` is served
the **old** file and caches it under the new name — where nothing corrects it until the next
version bump, because the URL never changes again.

`plan` could not see it. Its require walk derives edges from greps for `Foo::`, `new Foo(` and
`extends Foo`, and there is no such edge here: nothing in the PHP names the script, the
dependency runs entirely through a query argument. So it was carried by a paragraph in a deploy
record, and it bound twice in five releases.

It is now derived: any file under `assets/` in `UPLOAD_ORDER` must be positioned before
`lichtbild-gallery.php`, and `plan` refuses otherwise. Verified by swapping the two entries — `[ERROR]
assets/js/lichtbild.js is ordered at or after lichtbild-gallery.php`, exit 1 — and by restoring the file
byte-for-byte afterwards.

The first version of that check had the defect this script has already fixed once elsewhere: it
counted only the assets that *passed*, so with `lichtbild-gallery.php` ordered first it printed `versioned
assets in this upload: none, so no cache-buster to sequence` directly beneath the error saying
otherwise. **A tally of the good cases cannot report the bad one**; it now prints `0 of 1`.

### The 26.8.18 deploy, which fixed the install nobody here has ever done

**5 files, 5 chunks, every chunk first time, 160/160 URLs identical, 0 non-200,** `ver=26.8.17`
before and `26.8.18` after. Thirteenth deploy with no failed chunk.

The change is entirely about a site this one has never been: one that never had Envira. Schema 1
does not mean "new" — it means *still on Envira's storage* — so a fresh install registered post
types literally named `envira`, `envira_album` and `envira-tag`, and every editor screen refused
to work, telling its owner their gallery was in a format the site had never had. 26.8.17 fixed
the URL *paths* and left the *type names*, which is half a fix and the more visible half at that:
the names show up in admin URLs, body classes and sitemap filenames.

The control that matters here is not the 160 URLs — nothing could have moved them — but that the
live site's two options read exactly as before, `lichtbild_schema_version 2` and
`lichtbild_slug_scheme envira`. The whole change is gated on those being unset, so a site that has
already decided is unreachable by it, and that is checked rather than reasoned about.

#### Two answers derived separately were wrong; one observation fixed it

Worth recording because the bug appeared *while fixing the bug*, and the suite caught it
immediately. Schema initialisation ran first and marked a fresh site migrated; the slug scheme
then asked `has_migrated()`, saw `true`, and concluded the site had migrated **from Envira** — so
a brand-new install got Envira's URL paths, the precise opposite of the intent. The signal was
ambiguous because "migrated" had quietly come to mean two things.

Both answers now come from a single observation, written together, and `initialise()` reads the
raw schema option rather than going through `has_migrated()` so it cannot observe its own writes.
An earlier draft also had `detect_slug_scheme()` and `has_migrated()` calling each other — mutual
recursion that would have been a stack overflow on every request of a fresh install, which is
exactly the install being fixed.

#### A fix that hands the user a new footgun

Making a fresh site "migrated" gives it a rollback it must never be offered: there is no Envira
installation behind it, so rolling back would move the owner's galleries onto post types named
after a plugin they have never installed. Refused in `rollback()` rather than merely hidden on
the screen, for the reason the album cover picker was — markup is a suggestion. **A fix that
creates a hazard elsewhere is normal; noticing it in the same change is the part that has to be
deliberate.**

#### Verified against real WordPress, where the stubs cannot reach

Post type registration and permalink generation happen in WordPress, not in the harness. On a
real install stripped of every Envira record and option: `has_migrated` true, scheme `generic`,
**`lichtbild_gallery` registered at `/gallery/` and `envira` not registered at all**, and rollback
refused. The refusal came back in German, which incidentally proves 26.8.17's
`load_plugin_textdomain()` fix works — a control nobody set out to run.

### The 26.8.17 deploy, whose riskiest line ran on the server and decided something

**11 files, 17 chunks, every chunk first time, 160/160 URLs identical, 0 non-200,**
`ver=26.8.16` before and `26.8.17` after. Twelfth deploy with no failed chunk.

Identical was the prediction and it is worth stating why, because the release changes a great
deal: the slug scheme, the CSS converter, the textdomain load, the JavaScript failure path and a
stylesheet. **None of it can move a rendered byte on *this* site.** The scheme derives to the
paths already in use, the converter only runs at conversion time and this site's records are
already converted, the JS and CSS changes are inside asset files, and the version bump reaches
the page only through a `?ver=` that `capture` normalises away. So the prediction was not "we
hope nothing changed" but a claim about which of the changes could reach a page.

Four controls, because 0 changed is also what a deploy that never landed produces: `ver=26.8.17`
on both assets, `Gallery.prototype.announce` present in the served JavaScript, the new
`.lichtbild-message` rule present in the served stylesheet, and `readme.txt` answering 200 where it
had never existed.

#### The line that decides something, running for the first time on real data

This release's genuinely risky change is that `Lichtbild_Settings::slug_scheme()` **writes an
option the first time anything asks it**, and every gallery URL on the site depends on the answer.
A wrong answer would have moved 57 indexed URLs on the first page view after the upload.

It derived `envira` and pinned it, which is right, and it did so without any manual database
write — the site's own `lichtbild_schema_version` of 2 settles it, because a migrated site was
serving Envira's paths by definition. That property is what made the deploy safe to run in the
normal way rather than needing a prepared row, and it was established by reading the derivation
before uploading rather than by watching what happened afterwards.

Verified after the fact from both ends: the option reads `envira`, all four URL spaces answer
200, and `/gallery/<slug>/` — the path a fresh install would use — does **not** serve, which is
the control that stops "the URLs still work" from being satisfied by a site that registered both.

#### One ordering constraint, invisible to `plan` for the third release running

`class-lichtbild-settings.php` gains `slug_scheme_paths()` and `class-lichtbild-post-types.php` calls
it on **every front-end request**. Landing the caller first is a fatal "Call to undefined method"
on every page of the site, not an admin-only inconvenience — the worst version of this hazard so
far, since the previous two were confined to editor screens.

`plan` reports "no new requires, nothing to sequence", and is right: both files are already
required and both classes already exist. It reasons about requires and class names, and a method
arriving on an existing class is neither. Written into `UPLOAD_ORDER`'s comment, which is now the
third release to carry a constraint of exactly this shape — 26.8.7's constructor arity, 26.8.14's
album editor, and this. **That is no longer an exception worth noting each time; it is the normal
case, and the checker covers the other one.**

### The 26.8.16 deploy, which was a first install, a data rename, and a plugin swap

**39 files, 43 chunks, every chunk first time, 160/160 URLs semantically identical, 0 non-200.**
Eleventh deploy with no failed chunk. The first install of a new plugin directory, and the first
deploy whose data step was performed by hand in `wp-admin` rather than from here.

`plan` reported **FIRST INSTALL** and declined to check ordering, which is new and is the honest
answer: every previous release uploaded into a directory WordPress was actively executing, so a
half-uploaded set was a half-executed plugin. This one uploaded into `/plugins/lichtbild-gallery/`, which
nothing had been told about — not in `active_plugins`, nothing requiring it, PHP never opening a
file in it. **An inactive plugin directory executes nothing, so the intermediate states that
ordering exists to protect against are not reachable.** Saying "constraints: satisfied" there
would have been true and would have meant something different from what a reader would take it
to mean.

That distinction needed care in the code, because "the server's `lichtbild-gallery.php` came back empty"
has two causes meaning opposite things. It is resolved by size: `-1` from `remote_size` is
genuinely absent and means first install; any other value means the file is there and could not
be read, which is a transport failure and still refuses. An empty answer read as a fact is the
mistake this script's whole history is made of.

`uninstall.php` was in this upload and in **no previous one**. It shipped in 26.8.13 and never
reached the server, so deleting the plugin would have left its options behind — exactly what that
file exists to prevent. Nothing detected it; it fell out of enumerating what the server actually
holds instead of assuming the repository and the server agree.

#### The sequence, and why it needed no new migration code

Renaming the data could have been `UPDATE ... SET post_type` in SQL, and that would have
corrupted 16 galleries. The stored records carry rewritten `#tivira-<id>` CSS selectors **inside
PHP-serialised data**, where a string replace changes the length without the length prefix. So
the rename was done with code that was already proven on this exact data: **roll back to Envira's
types with the existing rollback, swap the plugin, re-run the existing migration against the new
names.** The selectors are regenerated from Envira's originals and are right by construction —
verified afterwards as 0 stale `#tivira-` against 20 correct `#lichtbild-`, and 51 of 51 records
unserialising cleanly.

The precondition that makes rollback lossless — that no gallery has been edited since the
migration, because an edit lives only in the v2 record — was **checked against the live database
rather than assumed**. The most recent edit is 2024-08-06, two years before the migration.

Rehearsed twice against a real WordPress with both plugin generations installed side by side, the
old one materialised from its own deployed commit so the rollback was performed by the code
actually running in production. 52/2/58 moved, 111 of 111 URLs identical.

#### Every number that came back "changed" was an instrument, and one of them was mid-sequence

- **In rehearsal: 52 of 111 changed, and it was the fingerprint's own tile counter.** It counted a
  hardcoded class name, so every pre-rename page recorded `tiles:0` — and the tell was the shape
  rather than the size: everything with a gallery changed, every tag archive did not. Proved
  instead of argued by recomputing a changed page's hash with the count forced to 0, which
  reproduced the before-hash exactly. **Without that fix the live comparison would have had no
  signal at all**, since it would have flagged the same pages for the same non-reason.
- **Live, mid-sequence: 58 changed, all tag archives.** Not the swap — the *rollback*. The
  baseline was captured while the site was migrated, so the taxonomy was one name then and
  another at the time of comparison, and Yoast keys its per-name settings on the registered name.
  The title fell back to core's (en-dash instead of Yoast's separator) and the canonical vanished
  from 58 indexed URLs. This is the 26.8.6 regression exactly, already solved in code:
  `carry_seo_settings()` mirrors the keys onto the new names during the migration, adding and
  never replacing, which is why the rollback needed no inverse pass. Confirmed restored after the
  migration: canonical back, and the title reads `Archive - ` again.
- **And one that was purely my own guess.** A closing check said the protected gallery was leaking
  40 images. It was the wrong gallery: `lichtbild-protected` is *right-click* protection, which 47
  of 52 galleries carry, and has nothing to do with a post password. The real one is id 1646,
  which serves its form with 0 upload references, 0 tiles, and `-1` from the AJAX endpoint.
  **Ask the database which row has the property, rather than picking the page whose name sounds
  like it.**

#### The setting that does not travel with a rename

`lichtbild_standalone` is unset the moment the name changes, and `standalone()` then falls back to
`envira_gallery_standalone_enabled` — an Envira leftover that `TODO.md` already lists for
deletion. So gallery permalinks were one unrelated tidy-up away from rendering nothing, with no
error anywhere. Found by reading the fallback rather than by any check, and the general form is
worth keeping: **a rename orphans every option keyed on the old name, and a fallback chain hides
that until the thing it falls back to is removed.**

#### Cleanup

53 orphaned meta rows (`_tivira_gallery` 51, `_tivira_album` 2) and 2 stale options deleted in one
transaction, gated on controls read before and after — `_eg_gallery_data` 52, `_eg_album_data` 3,
`_lichtbild_gallery` 51, `_lichtbild_album` 2 — with the delete rolled back if any of them moved. The
rollback deliberately does not delete its own record, which is right for a rollback and wrong to
leave once the name no longer exists in any code.

The old `/plugins/tivira/` directory was then removed, enumerated rather than assumed: 39 files
and 10 directories. Envira's own records stay exactly where they are.

**Final state: 160/160 identical, 0 non-200**, against a baseline captured before the upload — so
across the whole operation, not merely across its last step. The control that stops that being
vacuous: `envira` went to **0** rows while `lichtbild_gallery` went to **53**.

### The 26.8.15 deploy, where "0 changed" was the prediction and proves nothing on its own

**2 files, 3 chunks, every chunk first time, 160/160 URLs identical, 0 non-200,** `ver=26.8.14`
before and `26.8.15` after. Tenth deploy with no failed chunk. The first deploy verified against
the **160**-URL surface, the front page included — 26.8.14 could only promise it from the next
release, and this is that release.

Identical was required in advance, and here it is nearly trivial to predict: the release changes
one stylesheet and no PHP behaviour, and `capture` normalises `?ver=` precisely so that a version
bump does not swamp the comparison. Nothing in any page body could move.

**Which is exactly why the headline number is the weakest evidence in this record.** A deploy that
never landed produces `changed 0` too — same number, same summary line, and `capture` structurally
cannot tell them apart, because the one thing this release *does* change on the page is the
`?ver=` string it deliberately throws away. The 26.8.2 record says identical is a pass rather than
an ambiguity only when it is predicted; this one adds the other half: **predicting it is what makes
the number worthless as a positive.** So four controls were run, and each answers a question the
comparison cannot:

1. **The stylesheet, over HTTP, by digest** — `98dbb45f…` from `https://timo-stein.com/…/lichtbild.css`
   against the same digest locally. `push` verifies over FTP, which proves the bytes reached the
   filesystem; this proves the web server serves them.
2. **`ver=26.8.15` in the rendered page**, which is the only thing that proves `lichtbild-gallery.php` landed
   *and* is executing — a stale opcache would leave the constant at 26.8.14 with the file already
   correct on disk.
3. **`grep -c 'margin: 0 auto 1.5em'` on the live stylesheet → 2**, both rules, not one.
4. **A browser, on the live URL.** `getBoundingClientRect()` on `.lichtbild-wrap` reports
   `gapL=306 gapR=306` where it reported `gapL=0 gapR=612` before, on all three page types:
   gallery permalink, album permalink, and the front page's ten galleries.

Only the fourth is the thing anyone actually asked for, and it is the only one that could not have
been inferred from the other three.

#### The bug was found by a person looking at the site, for the third time

26.8.11 is the entry recording that two live defects were invisible to 207 checks because the
markup was right and the rendering was not. This is the same shape again, and it had been live
since the switchover: `.lichtbild-wrap` and `.lichtbild-album` set `margin: 0 0 1.5em`, which collides
with the theme rule that centres content blocks — Twenty Twenty's `margin-left/right: auto` on
`.entry-content > *` — at *equal specificity*, and a plugin stylesheet is always the later one. So
the declaration did not leave the theme's centring alone; it silently replaced it.

**The measurement that made it a regression rather than a preference was the Envira control, and
getting it required the local WordPress.** AGENTS.md's switchover entry says to keep the local copy
able to run both plugins; this is the first time that has been cashed in. Same gallery, same theme,
same viewport:

| | left gap | right gap |
|---|---|---|
| Envira (local, real plugin) | 306px | 306px |
| Lichtbild, before | **0px** | **612px** |
| Lichtbild, after | 306px | 306px |

The width — 580px — was identical in all three. It was never the difference, which matters because
"the galleries look too narrow" is the obvious wrong diagnosis and would have led to overriding the
theme's `max-width` instead.

#### The control I nearly used instead would have said the opposite

The first attempt at an Envira baseline was a Wayback snapshot from January 2026, and it reported
Envira's wrap at **1176px, full width** — which would have argued for widening the galleries rather
than centring them. It was wrong: the archived theme stylesheet had not truly applied. The tell was
a control that cost one line — the page title rendered at **32px** where Twenty Twenty gives
**64px**, and `maxW` read `none` where the theme constrains every content child.

`themeSheetPresent=true` was in that same probe's output and was **not** enough: the sheet was
linked and the archive had captured *something* at that URL. **Ask whether a stylesheet's effects
are present, not whether the stylesheet is** — a third-party archive is a rendering environment
nobody controls, and it fails by degrading quietly rather than by erroring.

#### One ordering constraint, and it is not about PHP

`plan` correctly reports "no new requires, nothing to sequence" — and this release still has a
constraint, the same class as 26.8.14's third and 26.8.11's: **`lichtbild.css` before `lichtbild-gallery.php`.**
`LICHTBILD_VERSION` is the `?ver=` on the stylesheet, so `lichtbild-gallery.php` is what announces the asset's new
identity. Land it first and the site advertises `?ver=26.8.15` while the server still holds the old
file — visitors then fetch the **old** stylesheet and cache it under the **new** version string,
which is persistent, because the entire point of a version query is that it is trusted. The reverse
window is "new file, still advertised as 26.8.14": returning visitors do not refetch, and the bump
lands seconds later. It self-corrects.

`plan` prints "constraints: satisfied" over this order and would print it over the reverse too. It
is not wrong — it is correct about what it checks, and this is not that. Written into
`UPLOAD_ORDER`'s comment for the same reason 26.8.7's arity was.

### The 26.8.14 deploy, and the URL that had never once been checked

**11 files, 19 chunks, every chunk first time, 159/159 URLs identical, 0 non-200,** `ver=26.8.13`
before and `26.8.14` after. Ninth deploy with no failed chunk. All eleven re-verified by digest
after the whole set had landed, not only as they went up.

Identical was required in advance rather than observed after, and it rested on one thing worth
checking rather than assuming: **that no existing translation changed.** The catalogue gained 25
entries this release, and a changed one would move German text on every page. Diffing the old and
new `.po` by msgid: **181 shared entries, 181 byte-identical, 0 removed** — the only difference is
the header block. So the prediction held, and `ver=` moving is what separates it from a deploy
that never landed.

`plan` derived the new require by itself and was **proved capable of refusing** first: ordering
`class-lichtbild.php` before `lichtbild-gallery.php` produces
`[ERROR] includes/class-lichtbild.php names Lichtbild_Block but is ordered before the file that requires it`
and a non-zero exit, with `deploy.sh` restored byte-identically. The third constraint is the one
it structurally cannot see — the album editor now calls a method arriving on an existing class —
and that is written into `UPLOAD_ORDER`'s comment for the same reason 26.8.7's arity was.

#### The front page renders ten galleries and was in no deploy's verification surface

Found by running a check *after* the deploy — does a page with no gallery load no Lichtbild assets?
— picking `https://timo-stein.com/` as the obvious example, and getting the opposite answer. It
is `class="home blog"`, the blog index, rendering the latest posts' content in full: **10
galleries and 132 figures, more gallery markup than any single permalink on the site, on its
most-visited URL.**

`tools/live-urls.py` could not see it by construction. It enumerates gallery and album
permalinks, tag archives, and posts whose own `post_content` holds a shortcode — and the front
page is none of those. The shortcodes live in the posts it aggregates, **every one of which is
enumerated**, which is exactly why the gap survived nine deploys: the content was covered, so
nothing ever looked wrong. Same lesson as 26.8.2, where ten probe URLs happened to contain no
album and the album regression reached production. *Enumerate the URL spaces a plugin can appear
in, not a sample of the ones that came to mind.*

Fixed, so the surface is **160**. The front page cannot be compared across *this* deploy — there
was no before-capture of it — and that is stated rather than papered over; it enters the baseline
from the next release. What was established directly: HTTP 200, zero PHP diagnostics, 10
galleries, `ver=26.8.14`, and a hash stable across two fetches two seconds apart, which is what
makes it usable as a baseline at all.

#### Three checks that failed and were all my own instrument

Worth recording together, because the pattern is the point: **not one of them was the site.**

- **"the protected gallery leaks 1 upload reference."** The match was the literal string
  `/wp-content/uploads/*` — a glob in a header, not an image. Counting `wp-content/uploads/<year>/`
  gives 0, alongside 0 figures and 1 password form.
- **"a page with no gallery loads Lichtbild's assets."** The page had ten galleries. The property is
  real and holds: `/category/temporaer/`, found from the site's own sitemap rather than guessed,
  loads **0 css, 0 js, 0 editor assets**. That is the property this release most risked, since
  registration moved to `init`.
- **"ajax does not refuse the protected gallery."** It refuses in **German** — `Galerie nicht
  gefunden.` — because 26.8.13 translated it. A check grepping for English prose is a check with a
  language dependency nobody declared; `"success":false` against a control returning
  `"success":true` has none.

And a fourth, in the tooling rather than the checks: **`for s in $CANDS` iterated once** under
zsh with the whole sitemap list as a single token, so the sweep found "1 non-gallery URL" instead
of 59. That trap is written down in the shared notes and it still cost a round trip. `while IFS=
read -r` over a here-string, always.

### The 26.8.11 deploy, the first with an ordering constraint `plan` cannot express

**6 files, 12 chunks, every chunk first time, 0 non-200,** `ver=26.8.10` before and `ver=26.8.11`
after. Eighth deploy with no failed chunk.

**The constraint is on a returned array key, and no grep can see it.**
`Lichtbild_Item::lightbox_source()` gained a `srcset` key that `class-lichtbild-renderer.php` and
`class-lichtbild-ajax.php` both read. A consumer landing first reads a key that is not there — in
PHP 8 a warning and a null rather than a fatal, so it would not take the site down; it would log
an *Undefined array key* per image per page view, which is the kind of thing nobody notices for
a month. `plan` reasons about `require_once` and class names, exactly as it could not see
26.8.7's constructor arity. Stated in `UPLOAD_ORDER`'s comment because the checker cannot state
it, and the live check afterwards was **zero PHP diagnostics on a rendered gallery page**.

**`lichtbild-gallery.php` last is not convention here either.** `LICHTBILD_VERSION` is the `?ver=` on the
stylesheet and the script, so bumping it is what makes browsers refetch them. Land it before the
assets and a visitor in the gap fetches the *old* css/js under the *new* query string and caches
it for as long as the far-future expiry says.

**This is the first deploy since the switchover where identical was the wrong expectation, and
it had to be predicted.** The anchor gains an attribute and its declared dimensions change, so
the whole-page hash *must* move on anything rendering a gallery. It moved on exactly **97 of
159**, and the 62 that did not are the 58 tag archives plus four pages with no gallery on them —
checked, not assumed. Using `capture` alone here reads as a mass regression; the semantic
`fingerprint` is the right instrument for a change like this.

> **And that instrument was not taken.** `fingerprint` has to be run *before* the upload to be
> worth anything, and only `capture` was — so the strongest available statement about those 97
> pages is "they changed, and the changes I inspected were the ones I predicted", rather than
> "no photograph, tile count or title moved". The delta was verified directly instead: every one
> of a gallery's ten items carries `data-pswp-srcset`, the declared width is the full size rather
> than `large`, the srcset offers a candidate at the full width, and the protected gallery still
> serves its password form with **zero** gallery links and zero srcset against a control showing
> seven. Good enough, and weaker than one command run five minutes earlier would have been.
> **Decide which instrument the release needs at `plan` time, not after the upload.**

Two of the four verification checks were wrong on the first attempt, both in the direction that
invents a problem: `grep -c` counts *lines* and the markup is one line, so both albums reported
"1 cover" when they render 3 and 2; and the protected gallery was fetched at `/envira/1646/`,
typed from memory, which 404s — its slug is `kunterbunte-pferde-29-01-2018`. The 26.8.2 entry
records that exact trap. **A URL used as verification must come from the enumerated list**, and
`grep -o | wc -l` is the only counting form worth using on generated markup.

### The 26.8.10 deploy, where the stale thing was the deploy script itself

**3 files, 4 chunks, every chunk first time, 159/159 URLs identical, 0 non-200,** `ver=26.8.8`
before and `ver=26.8.10` after. Seventh deploy with no failed chunk. The site skips 26.8.9
entirely: it was committed and never deployed.

Two things were wrong before a byte moved, and both were the *tooling* rather than the plugin.

- **`UPLOAD_ORDER` still held 26.8.8's file list.** `plan` printed it, derived its constraints
  against it, and reported `constraints: satisfied` — correctly, for a set of files that were
  not the ones this release changed. The block's own comment says to rewrite it every release
  rather than edit it, and it had simply not been rewritten. It is a **hardcoded list that looks
  derived**, sitting directly beneath a paragraph explaining why it must not be. The count even
  matched — three files then, three now — so nothing about the output looked off.
- **The version it claimed to be upgrading from was wrong.** The comment said 26.8.9 → 26.8.10;
  the server was serving 26.8.8. Read off a live gallery page in one `curl`. **The version of
  record is what the server serves, not what the last release commit says**, and the two differ
  whenever a release is committed without being deployed — which is exactly the state this repo
  had been in for four commits.

A third defect was in the report rather than the logic: `plan` printed *"new classes required by
lichtbild-gallery.php"* above a list of **all nineteen**, which reads precisely like the server fetch having
failed and every require having defaulted to new. It cost a detour through the fetch code to
establish nothing was wrong. It prints a count under an accurate label now, and states
`new requires this release: none, so nothing to sequence` when the derived set is empty — the
useful line, and the one that was missing.

**The ordering checker was proved capable of failing even though this release has no ordering
constraint**, which is the case where that proof matters most: with no new requires, the
constraint loop body never executes, so a green result says nothing whatever about it — and the
loop had just been edited. Forcing every require to look new gives **19 `NEW require` lines, 20
errors, exit 1, and no `constraints: satisfied`**; the script restored byte-identically, verified
by digest.

The release itself was predicted to be invisible and was. The only substantive production change
is a **removal** — `Lichtbild_Album::cover_id()`, `::caption()` and the private `item()` — whose
callers were counted at zero before deletion; the second file is a docblock rewrite asserted
comment-only by diffing its non-comment lines to zero; the third is the version constant. So
`159/159 identical` is a pass rather than an ambiguity, and `ver=` moving is what separates it
from a deploy that never landed.

### The 26.8.8 deploy, which is what a boring one looks like when it is predicted

**3 files, 7 chunks, every chunk first time, 159/159 URLs identical, 0 non-200,** `ver=26.8.7`
before and `ver=26.8.8` after. Sixth deploy with no failed chunk.

No ordering constraint, derived and not hoped: `lichtbild-gallery.php` gains no `require_once`, the only
addition is an `error_log()` call inside `move()`, no signature changed, and the two keys the
screen newly reads are both taken through `isset()` — so the screen landing before the migration
is a zero rather than a warning.

**The prediction is the point, and it was written into `UPLOAD_ORDER` before the upload.** The
whole change is admin-facing, so *identical on all 159* was required in advance rather than
observed afterwards — which is what makes it a pass instead of an ambiguity. An identical capture
is also what a deploy that never landed produces; `ver=` is the one thing required to differ, and
it is what tells the two apart.

Nothing else to record. That is the entry.

### The 26.8.7 deploy, where `plan` passed an order that would have taken the site down

**13 files, 30 chunks, every chunk first time, 0 non-200,** `ver=26.8.6` before and `ver=26.8.7`
after. Fifth deploy running with no failed chunk. But the number worth keeping is a different
one: **the ordering checker reported `constraints: satisfied` over an arrangement that was a
fatal on every admin page**, and it was caught only because the mutation was run.

The release has two constraints pointing opposite ways, and **the second is one `plan` cannot
model at all**:

- **Inheritance.** Both editors become `extends Lichtbild_Metabox_Editor`, so the base file lands
  first (inert — nothing requires it yet), then `lichtbild-gallery.php` makes it loadable, then the two
  subclasses.
- **Arity, and the constraint is on the CALLER.** `Lichtbild_Editor` gained a required second
  constructor argument, so the *old* wiring's one-argument call is an `ArgumentCountError`:
  `class-lichtbild.php` must land **before** the editors. The reverse direction is safe only
  because PHP passes extra arguments to a userland constructor without complaint, and that
  asymmetry is the entire reason an order exists that has no fatal window. `plan` reasons about
  requires, not signatures — it cannot see this, and a comment now says so.

**Two independent blind spots, and the first hid the second.** `plan` derives the class name with
`grep -o '^class [A-Za-z_]*'`. The new base is `abstract class`, so the match was **empty**, and
the empty answer fell straight through a `[ -z "$class_name" ] && continue` — the constraint
check was skipped in its entirety while the run printed `constraints: satisfied`. Behind it sat a
second gap: the consumer grep looks for `Foo::` and `new Foo(`, neither of which can ever appear
for an abstract class, so **the only edge that exists for an abstract parent is `extends`, and
that was the one spelling it did not look for**. Fixing only the grep — which was the obvious
first move, and was made first — changed nothing, because the name extraction failed before it.

Three things generalise, and the last is the one that cost the least and found the most:

- **A checker that has only ever passed is indistinguishable from one that cannot fail.**
  Mutating `UPLOAD_ORDER` into the fatal arrangement is one command. It is now the standing step
  before any deploy with an ordering constraint: make it red on purpose, then put it back and
  verify the restore by digest.
- **`continue` on "I could not determine that" is a silent pass.** The skip now stops the deploy
  instead: a new require whose class name cannot be read means the check covered nothing, and
  that is the one answer it must never give quietly. Same family as every `[EMPTY]` check in the
  suite.
- **The special case is where the detector is weakest, because the special case is what nobody
  wrote the detector for.** `abstract` broke the name extraction *and* removed both spellings the
  consumer search relied on. A rule derived from the common shape will meet the uncommon one
  exactly when it matters.

Verification, and this deploy is the first where **byte-identical was the wrong expectation** and
that had to be predicted rather than discovered: `data-lichtbild-tags` was deliberately dropped from
the anchor copy, so the whole-page hash *must* move on any page rendering a tagged item. It moved
on exactly **2 of 159** URLs — one gallery, in its permalink and its embedding post — and the
delta was then checked rather than assumed: 0 occurrences on `<a>`, 10 on `<figure>`, one per
tagged item. The **semantic fingerprint was 159/159 identical**, which is what says no photograph,
tile count or title moved. Using the wrong instrument here would have failed in whichever
direction was least convenient: `capture` alone reads as a regression, `fingerprint` alone cannot
see the attribute at all.

Targeted checks afterwards, because a whole-site hash says "unchanged" without saying "correct":
the array-tag request that used to be an uncaught `TypeError` now answers **200 with a body
byte-identical to the no-tag control** (the control is the point — "did not crash" is also what a
refused request looks like); the protected gallery serves its password form with **zero** upload
filenames and **zero** gallery items; both AJAX endpoints answer `Gallery not found.` for its ID
**with a valid nonce**; and both albums render every cover.

Two zsh traps already in the shared notes bit again during the verification, both producing
confident wrong output first: `GAL=$(...)` had been written `GID=`, which is **read-only in zsh**
and aborts the block with *"failed to change group ID"* — a privilege error, in a script talking
to a remote server; and `"$c:lichtbild-gallery.php"` in a history loop had `:t` parsed as a **history
modifier through the double quotes**, so every version read back empty. Write `${c}:path`.

**And the first attempt at the AJAX check proved nothing.** The gallery ID was scraped with the
wrong attribute name, came back empty, and both the test request and its control answered
`Gallery not found.` — which reads exactly like a working refusal. The control is what exposed
it: a check whose control also "passes" has not run. The real attribute is `data-lichtbild-id`.

### The 26.8.6 deploy, where the VERIFICATION TOOL was the thing that broke

**3 files, 6 chunks, every chunk first time, 159/159 URLs identical, 0 non-200,** `ver=26.8.5`
before and `ver=26.8.6` after. No ordering constraint again — no new requires, both new methods
private and called from their own file, and `is_viewable()` on the server since 26.8.4.

The deploy was uneventful. What was not is that **`tools/live-urls.py` had been silently broken
by the migration**, and it broke in the direction that looks like success.

It enumerated the surface with `post_type IN ('envira', 'envira_album')` and
`taxonomy = 'envira-tag'` — the pre-migration names. After the migration those match nothing, so
it returned **49 URLs instead of 159**: only the posts embedding a shortcode, and not one gallery
permalink, album page or tag archive. Every downstream step would still have worked perfectly.
The capture would have reported 49 URLs and 0 non-200; the comparison would have reported 49/49
identical; and the deploy would have been declared verified against a surface containing none of
the URLs this plugin exists to protect.

It was caught only because the printed count was read — 49 where 159 was expected — which is
luck dressed as diligence. The fix is a guard: **an empty result from either query is now a hard
error**, on the grounds that a live site has both galleries and tag archives, so zero means the
query is asking for the wrong names rather than that the site is empty.

**A second bug sat in the same function and would have survived the first fix.** The URL was
built as `f"{site}/{post_type}/{slug}/"` — using the post type as the path segment. That is
correct only before the migration. Lichtbild pins `rewrite['slug']` to Envira's names in *both*
directions precisely so the indexed URLs never move, so the type name and the path segment are
two different things that happen to coincide on an un-migrated site. After the migration it would
have produced `/lichtbild_gallery/<slug>/`, which 404s. The two are now separate constants written
next to each other.

The general shape, and it is worth more than the bug: **a verification tool is code, it has the
same failure modes as the code it verifies, and nothing verifies it.** This one was written
during the 26.8.1 deploy, exercised by four deploys, and was wrong the moment the migration ran —
the event it was written to make safe.

**And the first proof of the new guard was itself invalid.** The mutated copy was written to
`/tmp`, where its relative path to `tests/.db.json` no longer resolved, so it exited 1 on a
missing-config error. Exit 1 was the expected result, and it was produced by a completely
different failure. Re-run inside the repo it failed correctly, naming the mixed state it found.
**A check that fires for the wrong reason is indistinguishable from one that works** — read the
error, never just the exit code.

### The 26.8.5 deploy, which had no ordering constraint at all — and how that was established

**"No constraint" is a derived fact, not an absence of evidence.** `lichtbild-gallery.php` gains no
`require_once` this release, and the only new method — `Lichtbild_Migration::set_schema()` — is
private and called from its own file. Both checked by `git diff` against the deployed commit
rather than by reading the diff and forming an impression.

So `plan`'s check was rewritten from *this release's instance of the rule* to *the rule*: a file
that names a class must not land before the file that requires it — **and that only binds when
the require is new, which is a fact about the server rather than about this checkout.** `plan`
now fetches the deployed `lichtbild-gallery.php` and reads it. That is what made 26.8.4's new class files
legitimately precede the gate, and what makes 26.8.5 unconstrained; the same code answers both
without being edited between them.

**Proved capable of failing, because a checker that has only ever passed is indistinguishable
from one that cannot fail.** Making it treat every require as new produces **28 errors** and
exit 1, so `push` refuses; the file restored byte-identical afterwards.

Result: **5 files, 14 chunks, every chunk first time, 159/159 URLs identical**, 0 non-200,
`ver=26.8.4` before and `ver=26.8.5` after. Fourth deploy with no failed chunk.

The renderer changed this release, so the album pages were checked directly rather than by hash
alone: both render every cover with its title and count. The protected gallery still serves its
form with zero gallery markup and zero upload filenames; the AJAX endpoint still answers
`Gallery not found.` for its ID **with a valid nonce**, against a control returning 7 items for
a public gallery; and `lichtbild_album_covers` is unreachable logged-out, since it is registered
on `wp_ajax_` only.

### The 26.8.4 deploy, and the constraint that inverted

**Re-derive `UPLOAD_ORDER` every release; do not edit the last one.** 26.8.2's central constraint
was that `lichtbild-gallery.php` goes **last**. 26.8.4's is that it goes **third**, because it is the file
that `require_once`s the two new classes — so it is the moment `Lichtbild_Album_Config` and
`Lichtbild_Album_Editor` become loadable, and every consumer below it would be a fatal "class not
found" if it arrived first. Keeping the old order would have taken the site down on four files.

`plan` catches that, and it was checked rather than assumed: moving `lichtbild-gallery.php` back to the end
turns it red with four errors and `push` refuses. The constraints are `grep`-derived — who names
`Lichtbild_Album_Config::`, who constructs `Lichtbild_Album_Editor`, who calls `has_titles()`.

**One exemption has to be derived from the server, not listed.** The new class files *do* name
`Lichtbild_Album_Config` and *do* precede the gate, legitimately: a file that does not yet exist on
the server is inert whatever it names, because nothing requires it, so nothing loads it. `plan`
therefore asks the server which files are absent (`remote_size` returning `-1`) and exempts
those. A hardcoded exemption list would be a claim about the server made without looking at it —
and it is the same list that changes every release.

**One window is unavoidable and was accepted rather than engineered away.** Between
`class-lichtbild-album.php` and `class-lichtbild-repository.php` the two disagree about the item
shape, so an album cover falls back to its gallery's first image. Two pages, a few seconds, no
error — and uploading them the other way round produces the same window pointing the other way.
Files that change together cannot both be first; the thing to check is that the window is
cosmetic rather than fatal, which is a question about arity and method names.

The result: **10 files, 17 chunks, every chunk first time, 159/159 URLs identical**, 0 non-200,
`ver=26.8.2` before and `ver=26.8.4` after. Third deploy running with no failed chunk.

**The pre-flight predicted that, which is what makes "identical" a pass.** Two queries against
production established the site is still unmigrated (no schema option, zero `_lichtbild_gallery` and
zero `_lichtbild_album` records), so **both editors refuse to run and the album editor is dead code
on this site until the migration runs**. And every rendering input was checked rather than
assumed: both published albums carry `display_titles='below'` and `display_image_count=1`, so
honouring those settings reproduces the old unconditional output exactly; every album member sets
`cover_image_id` and none sets `cover` or `thumb_id`, so the fallback chain that was deleted had
never been reached; no member has a caption; and the defaults album is a draft with no members.
Nothing left that could differ.

Targeted checks afterwards, because a whole-site hash says "unchanged" without saying "correct":
both albums render every cover with titles and counts (3 and 2 members); the protected gallery
serves its password form with **zero** gallery markup and **zero** upload filenames; neither
album mentions it; and the AJAX endpoint answers `Gallery not found.` for its ID **with a valid
nonce**, against a control where a public gallery returns its 7 items. That pairing matters —
without a nonce the endpoint answers `-1`, which proves the CSRF gate works and says nothing at
all about authorization, and it is the easy check to mistake for the real one.

### The 26.8.2 deploy, which was the first one that was boring

The point of recording it is that nothing happened, and that this was established rather than
hoped for. **Every chunk of every file landed on the first attempt** — 8 files, 10 chunks — which
is the second deploy in a row where chunking has not failed once, against a whole-file path that
failed 6 of 6 on a single file. Treat that as settled.

The pre-flight is what turned "probably fine" into a prediction. Two queries against production
established that the site is unmigrated, that Envira's addons are all deactivated, and that
**none of the four guarded galleries is listed in an album** — so the visibility extraction could
not change any rendered byte, and with Envira off the asset fix takes the same branch it always
did. The deploy was therefore *predicted* to be invisible, which is what makes "159/159
identical" a pass rather than an ambiguity: an identical capture is also what a deploy that
never landed produces.

That ambiguity is closed separately, and it has to be: `ver=26.8.1` before, `ver=26.8.2` after,
read off a real gallery page. The version string is the only reason the normalisation is safe —
it is the one thing required to differ, so everything else can be required to match.

The surface came to **159** URLs against the previous deploy's 116, because `tools/live-urls.py`
adds the 49 posts that embed a shortcode. Those exercise the shortcode path, which no permalink
capture reaches — and the shortcode is the one publishing path with no visibility check, so it
is exactly the path worth watching.

Targeted checks afterwards, because a whole-site hash says "unchanged" without saying "correct":
the protected gallery serves its password form with **zero** gallery markup and **zero** upload
filenames in the response; both albums render their covers with no protected member among them;
and the AJAX endpoint answers `Gallery not found.` for the protected gallery's ID.

**And a small instance of this project's oldest lesson, in miniature.** Two URLs typed from
memory during the checks — `/envira/testgalerie/` and `/envira_album/2017/` — came back 301 and
404, because the real slugs are `alzenauer-testgalerie` and `989`/`naturfotografie`. Both were
harmless because the enumerated list was the thing being trusted. Had either been used *as* the
verification, it would have reported a broken site that was fine, or a fine site that was
broken.

### The 26.8.1 deploy, which took the site down for a few minutes

Worth recording in full, because the procedure in `AGENTS.md` was followed and was still not sufficient.

- **Whole-file upload failed 6 of 6 attempts on a 19 KB file, leaving it at 0 bytes — and the
  live site returned HTTP 500 site-wide.** The previous entry called a 0-byte file "invisible
  until someone activates the plugin"; it is worse than that. `class-lichtbild-config.php` is
  `require_once`d by `lichtbild-gallery.php`, so a truncated copy is an instant fatal on every page,
  including the homepage. One of the failed attempts landed at exactly **16,384** bytes again.
- **Chunked upload then landed every chunk of every remaining file on the first attempt** —
  4 chunks of a 32 KB file, 3 of the 19 KB one, 2 and 1 for the rest. Nothing failed once.
  So **chunk by default; do not treat it as the fallback after the whole-file attempt fails.**
  The whole-file attempt is what breaks the site, and it buys nothing when it succeeds.
  `split -b 8192`, first piece with `-T` (overwrites), the rest with `--append -T`, checking
  the cumulative remote length after each piece and re-truncating to the last known-good
  length before retrying — a partial append otherwise corrupts every later offset.
- **Upload order is load-bearing when one file gains a method another calls.** 26.8.1 added
  `Lichtbild_Gallery::prime()` and called it from `Lichtbild_Ajax` and `Lichtbild_Renderer`. Uploading
  a caller first leaves a window in which any visitor gets `Call to undefined method`. Derive
  the order mechanically (`grep -l 'function prime('` against `grep -l -- '->prime('`), do not
  eyeball it.
- **Prove the deploy is a no-op when it is supposed to be one.** Every URL the plugin owns was
  fetched before and after — 116 of them, enumerated from the database rather than from memory
  (50 galleries, both albums, all 58 tag archives, the protected gallery as a control, and the
  posts that embed a shortcode). `LICHTBILD_VERSION` reaches the asset query strings, so `ver=`
  is normalised out and **everything else must hash identically**; the bump proves the new
  code is live and the identical remainder proves nothing regressed. 116/116 identical.
- **Read the live database in the pre-flight, and it can tell you the fix changes nothing.**
  One query established that the site has exactly one password-protected gallery and three
  non-public ones, and that **none of them is in an album** — so the album leak the release
  fixes was latent rather than active, and the deploy was expected to be invisible. Knowing
  that in advance is what made "116/116 identical" a pass rather than an ambiguity.

Two traps in the *verification*, both of which produced confident wrong output before being
caught, and both of the same family as everything under **Proving a test suite**:

- **`for f in $FILES` iterates once under zsh**, with the whole list as a single token. The
  per-file precondition (does the remote size match `HEAD`?) is what turned that into an abort
  instead of an upload to a nonsense path. Use a `while IFS= read -r` loop over a file.
- **A `join`-based before/after diff compared the hash column against the status column** and
  reported all 116 URLs as changed. It failed in the safe direction, but only by luck. Assert
  the join's own sanity — that the two URL columns agree on every row — rather than trusting
  the field numbering.

**And do not pipe the upload loop through `tail`.** Done here, with the rule already written
down two sections away: `tail` buffers until EOF, so a twelve-minute loop wrote a **zero-byte**
log and was indistinguishable from one that had hung on its first file. Redirect, and read the
file as it grows.
