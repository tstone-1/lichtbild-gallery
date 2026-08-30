=== Lichtbild Gallery ===
Contributors: tstone1
Tags: gallery, photo gallery, image gallery, lightbox, photography
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 26.8.27
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Photo galleries that look composed and load quickly. Free, with no account, no tracking and nothing held back for a paid tier.

== Description ==

Lichtbild lays your photographs out in even rows that fill the width of the page. Nothing is
cropped to a square and no row ends ragged, so a set of mixed portrait and landscape shots reads
as one composition rather than a grid fighting its contents. Click any photograph and it opens
full screen, with the next and previous one a swipe or an arrow key away.

Making a gallery takes one screen. Add images from your media library, drag them into the order
you want, and edit each one's title, caption and alt text in the same place. Then put the gallery
into a post with the block, or paste a shortcode.

It is quick on the visitor's side because of what it does not send: photographs are served at the
size the page actually shows rather than at full resolution, and the full-screen viewer is
downloaded only when somebody opens an image, so a page of galleries that nobody clicks costs no
JavaScript at all.

Everything is included. There is no pro version, no upgrade prompt, no licence key and no account
to create. The plugin makes no requests to any server other than your own, so nothing about your
site, your visitors or your photographs is sent anywhere.

If you are moving from Envira Gallery, Lichtbild reads your existing galleries where they are, so
you can compare the two by switching a plugin on and off. The migration that follows keeps every
URL and shortcode working and can be undone from the same screen.

= What you get =

* **Even rows that fill the page.** Every photograph keeps its own proportions and the row settles
  at a shared height. Fixed columns are available if you prefer them.
* **Images sized for the page.** The grid uses WordPress's own smaller versions with a `srcset`,
  not the full-size original, so a page of thumbnails is a fraction of the weight.
* **A full-screen viewer** with swipe, arrow keys and zoom, loaded on the first click rather than
  on every page.
* **Filter by tag.** Tags belong to the photograph, so one applied in a gallery follows that image
  everywhere it appears. The filter searches the whole gallery, not only the page on screen.
* **Pagination**, with the full-screen viewer still able to run through every page.
* **Camera settings under the photograph** — camera, aperture, shutter speed, focal length, ISO
  and capture time — taken from what WordPress already read when the image was uploaded. You
  choose which of them to show, gallery by gallery.
* **Links to a single photograph.** A shared link opens the image it names, whatever page or
  filter the person following it lands on.
* **Albums**, which collect galleries behind a cover image and get their own page.
* **Blocks for galleries and albums**, plus shortcodes for classic editors and page builders.
* **Per-gallery options** for share buttons, a download link, right-click protection, row height
  and spacing, which image size to use, and whether titles sit under each photograph, over it, or
  nowhere.
* **No jumping as the page loads.** Every image carries its dimensions, so the layout is settled
  before the photographs arrive.

= Migrating from Envira Gallery =

Lichtbild can read Envira Gallery's records where they lie, without copying or converting
anything, so you can compare the two by toggling a plugin. When you are ready, a migration on
the settings screen moves the galleries onto Lichtbild's own storage in place: post IDs never
change, so existing shortcodes keep working, and existing permalinks keep resolving. Envira's
original records are left untouched, and the migration can be rolled back from the same screen.

Lichtbild is **not affiliated with, endorsed by, or connected to Envira Gallery or Awesome
Motive**. "Envira Gallery" is their product and their trademark. Lichtbild contains no Envira
code; it names Envira only to describe what it can read and what it replaces.

On a site with no Envira history, Lichtbild serves its galleries from `/gallery/`, `/album/` and
`/gallery-tag/`. A site migrating from Envira keeps Envira's existing paths, so no indexed URL
moves. Both are overridable through the `lichtbild_url_slugs` filter.

== Installation ==

1. In **Plugins → Add New**, search for *Lichtbild Gallery*, install it and activate it. Or
   upload the plugin folder to `wp-content/plugins/lichtbild-gallery` and activate it there.
2. Galleries appear under **Lichtbild** in the admin menu. Create one, add images from your
   media library, and drag them into order.
3. Put it in a post with the **Lichtbild Gallery** block, or with `[lichtbild-gallery id="123"]`
   in the classic editor.

If Envira Gallery is installed, Lichtbild stays out of its way: the takeover setting under
**Settings → Lichtbild** defaults to handling `[envira-gallery]` only while Envira is inactive.

== Frequently Asked Questions ==

= Is any of it paid, and does it need an account? =

No. Every feature described here is in this plugin. There is no pro version, no licence key, no
sign-up and no upgrade prompt.

= Does it send anything anywhere? =

No. The plugin talks only to your own site. It contacts no external service, loads no fonts or
scripts from anywhere else, and collects nothing about your visitors.

= Can I try it without installing it? =

Yes. [Open the live demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/tstone-1/lichtbild-gallery/main/.wordpress-org/blueprints/blueprint.json)
to run a throwaway WordPress in your browser with the plugin already installed. Nothing touches
your own site. The media library starts empty, so upload a few photographs of your own.

= Does it need a page builder or a build step? =

No. There is no bundler and no npm dependency at runtime. PhotoSwipe is bundled.

= Is the source of the bundled JavaScript included? =

Yes. Nothing here is generated: every file the plugin ships is the source. PhotoSwipe 5.4.4 is
vendored under `assets/vendor/photoswipe/` with its MIT licence, and the unminified `.esm.js`
sources sit beside the `.esm.min.js` files they were minified from.

= Will migrating from Envira change my URLs? =

No. The migration renames post types in place and pins the URL paths to the ones already
published, so permalinks and shortcodes keep resolving to the same galleries.

= Can I go back? =

Yes. The migration leaves Envira's own records untouched and can be rolled back from the
settings screen, which restores the original post types rather than reconstructing them.

= Where are per-image tags stored? =

On the attachment, as a taxonomy, so tagging an image affects it everywhere it appears rather
than only in one gallery.

== Screenshots ==

1. Portrait and landscape photographs in even rows. Nothing is cropped to a square and no row
   ends ragged.
2. Filtering by tag. The buttons cover every tag in the gallery, including ones whose
   photographs are on a later page, and this gallery has a lot of them.
3. The full-screen viewer, with the camera settings WordPress read when the image was uploaded.

== Changelog ==

= 26.8.27 =
* Galleries can be created directly from the Gallery block by choosing images from the Media
  Library. The block stores the new gallery's ID, so it remains reusable and editable elsewhere.
* A per-gallery option can take current titles, captions and alt text from the Media Library,
  while keeping the gallery's own values as fallbacks.
* Fixed-column galleries use fewer columns on narrow screens, and the plugin listing now offers
  a no-install live demo.

= 26.8.26 =
* Fixed: on a new installation, gallery, album and tag permalinks returned 404 until the
  permalink settings were saved again. The rules are now rebuilt when the plugin is activated.

= 26.8.25 =
* Fixed: deleting the plugin and installing it again could leave migrated galleries in the
  database but invisible. They are found again automatically.
* Fixed: an album could be made to store a gallery its editor had no permission to open, and the
  gallery and album pickers listed other authors' unpublished items.
* Fixed: the message shown when a gallery page fails to load was empty, and a failed lightbox
  load stopped every later image from opening until the page was reloaded.
* Fixed: tag filter buttons now report which one is selected to screen readers.
* The migration now warns when a setting it copies alongside the galleries cannot be written.

= 26.8.24 =
* Renamed to Lichtbild Gallery. The former name was too close to plugins already in this
  directory. Nothing a visitor sees changed: gallery, album and tag URLs stay where they are and
  the `[envira-gallery]` shortcode keeps working.
* Galleries, albums and image tags now live under Lichtbild's own post types and meta keys.

= 26.8.23 =
* Envira Gallery's `[envira-gallery]` and `[envira-album]` shortcodes are now taken over only on
  a site that has Envira records. A site that never used Envira registers only Lichtbild's own two
  shortcodes; a site continuing an Envira installation is unaffected.
* The `lichtbild_config_sanitize` filter no longer receives the raw form submission as a second
  argument. Callbacks get the sanitised settings only.

= 26.8.22 =
* Per-gallery Custom CSS has been removed, in line with the Plugin Directory guideline against
  storing and printing arbitrary CSS entered through a plugin's own interface. Style your
  galleries in Appearance > Customize > Additional CSS instead: a gallery is `#lichtbild-<id>` and
  its wrapper `#lichtbild-<id>-wrap`, so existing rules keep working once moved.
* The upgrade itself deletes no CSS, and `tools/export-custom-css.py` in the source repository
  prints what is still stored, ready to paste. Move it before you next save an affected gallery:
  saving rewrites that gallery's settings record, which is where the most recent version of the
  CSS lives on a site migrated from Envira Gallery.
* Translations now come from translate.wordpress.org rather than a catalogue bundled in the
  plugin.

= 26.8.21 =
* A gallery created on a site that never had Envira Gallery now renders on its own permalink.
  It answered with the page title and none of its photographs, because the setting that governs
  this fell back to a value only a site migrating from Envira has.

= 26.8.20 =
* The currently selected button in the tag filter is legible again. It had been painted in the
  same colour as its own background, so the applied tag could not be read. Themes can now set
  the pair through the `--lichtbild-tag-fill` and `--lichtbild-tag-label` custom properties.

= 26.8.19 =
* Pagination, tag filtering and the lightbox no longer stop working on sites that use full-page
  caching, where the nonce a logged-out visitor holds is routinely older than the page.
* Deep links to a single image that were shared before the plugin was renamed resolve again.

= 26.8.18 =
* Generic URL paths by default for new installs; sites with an Envira history keep the paths
  they already publish, recorded once rather than re-derived.
* Bundled translations are loaded explicitly, so they apply on WordPress 6.x as well as 7.
* Fixed converted custom CSS targeting a gallery's wrapper, which produced a selector that
  matched no element.
* A failed pagination request now says so instead of silently keeping the previous page.

= 26.8.15 =
* Galleries and albums are centred in the content column again.

== Upgrade Notice ==

= 26.8.22 =
Read this if you set Custom CSS on a gallery: the field is gone, so those rules no longer
apply. Nothing is deleted. Move the CSS to Appearance > Customize > Additional CSS, unchanged
- the element ids are the same. Do it before you next save that gallery, which drops the newer copy.

= 26.8.21 =
Important for new installs: a gallery's own permalink rendered an empty page. Sites migrated
from Envira Gallery were never affected.

= 26.8.20 =
Worth taking if you use the tag filter: the selected tag's label was invisible against its own
background. Sites with the filter switched off are unaffected.

= 26.8.19 =
Recommended for any site using a page cache: gallery pagination and filtering kept working only
for as long as the cached page's nonce was valid.
