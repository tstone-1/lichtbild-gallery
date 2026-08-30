<?php
/**
 * Plugin Name: Lichtbild Gallery
 * Plugin URI:  https://github.com/tstone-1/lichtbild-gallery
 * Description: Responsive galleries for WordPress. Reads existing Envira Gallery data in place, so galleries keep working without migration or a licence.
 * Version:     26.8.27
 * Author:      tstone-1
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lichtbild-gallery
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * Lichtbild replaces Envira Gallery Pro, and which storage it reads depends on where the site
 * is. Before the migration it owns nothing: galleries live in the `envira` post type under the
 * `_eg_gallery_data` post meta exactly as Envira wrote them, and Lichtbild renders from that,
 * so deactivating Lichtbild and reactivating Envira is lossless in both directions and an A/B
 * comparison on a live site is safe. After the migration the galleries are `lichtbild_gallery`
 * posts carrying `_lichtbild_gallery` records that Lichtbild alone reads and writes, and a site
 * that never had Envira starts there. Envira's own records are never destroyed either way,
 * which is what keeps the rollback real rather than a reconstruction.
 *
 * NOT AFFILIATED WITH, ENDORSED BY, OR CONNECTED TO ENVIRA GALLERY OR AWESOME MOTIVE.
 * "Envira Gallery" is their product and their trademark. Lichtbild is independent, contains
 * no Envira code, and names Envira only to say what it reads and what it replaces. The
 * `envira` post type, the `_eg_gallery_data` meta key and the `/envira/` URL paths appear
 * here because they are the data and the addresses of a site that already exists, and
 * moving either would break it -- interoperability, not association.
 */

defined( 'ABSPATH' ) || exit;

/*
 * There is no `load_plugin_textdomain()` call, and its absence is the finished state of a
 * condition the call itself carried: "remove it once the bundled catalogue is dropped in favour
 * of the directory's, not before." The distributed plugin no longer ships a `.po`/`.mo`, so that
 * is now the case.
 *
 * Translations come from translate.wordpress.org, which delivers them into
 * `WP_LANG_DIR/plugins/` -- the one place every WordPress 6.x and 7.x just-in-time loader reads
 * without being told to. That is why the call was unnecessary from 4.6 onward for anything the
 * directory serves, and it is what the directory's own guidelines ask for.
 *
 * `Domain Path` stays in the header. It is not vestigial: a copy of this plugin installed from
 * somewhere other than wordpress.org can still carry a catalogue in `languages/`, and on WP 7
 * the textdomain registry finds it from that header alone -- verified on WP 7.0.3, which is what
 * the site this was built for runs, and the reason its German kept working once this call went.
 * Confirmed on that site in production after the 26.8.22 deploy, not only locally: the rendered
 * page ships `Schließen`, `Weiter`, `Vergrößern` with no `load_plugin_textdomain()` anywhere.
 * Worth having checked, because the failure mode is silent -- 28 strings revert to English and
 * nothing is logged.
 *
 * **That is verified for WP 7 and for nothing older.** The header still says
 * `Requires at least: 6.0`, and which 6.x release began discovering a plugin's own `languages/`
 * directory unaided has not been established here -- an independent review put it at 6.8 and
 * cited a source that does not exist, so treat it as unknown rather than as 6.8. It costs
 * nothing either way for a plugin the directory serves, because those translations arrive in
 * `WP_LANG_DIR/plugins/`, which every version reads. It matters only for a copy installed from
 * source onto an older WordPress, where the bundled catalogue may silently not load. Settle it
 * on the local WordPress before relying on it.
 */

define( 'LICHTBILD_VERSION', '26.8.27' );
define( 'LICHTBILD_FILE', __FILE__ );
define( 'LICHTBILD_DIR', plugin_dir_path( __FILE__ ) );
define( 'LICHTBILD_URL', plugin_dir_url( __FILE__ ) );

require_once LICHTBILD_DIR . 'includes/class-lichtbild-config.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-album-config.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-item.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-gallery.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-album.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-post-types.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-repository.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-exif.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-renderer.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-assets.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-shortcode.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-block.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-standalone.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-ajax.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-settings.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-migration.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-migration-screen.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-metabox-editor.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-editor.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild-album-editor.php';
require_once LICHTBILD_DIR . 'includes/class-lichtbild.php';

/**
 * Returns the shared plugin instance, booting it on first call.
 *
 * @return Lichtbild The plugin container.
 */
function lichtbild() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Lichtbild();
		$instance->boot();
	}

	return $instance;
}

add_action( 'plugins_loaded', 'lichtbild', 20 );

/**
 * Invalidates the rewrite rules on activation, so gallery permalinks resolve immediately.
 *
 * Rewrite rules are generated from the post types registered at flush time and then stored, so
 * a site whose rules were built before this plugin existed carries no `/gallery/`, `/album/` or
 * `/gallery-tag/` rule. Nothing regenerates them on its own: every gallery permalink answers
 * 404 until someone happens to re-save Settings -> Permalinks, which reads as the plugin being
 * broken rather than as a missing flush. Measured on a fresh install of the published 26.8.25 —
 * 94 rules, none of them ours, and 404 on a gallery that renders perfectly through its
 * shortcode.
 *
 * `delete_option()` rather than `flush_rewrite_rules()`, and the difference is the whole point:
 * activation runs after `init` has already fired for this request WITHOUT this plugin loaded,
 * so its post types are not registered yet and flushing here would persist a fresh set of rules
 * that still lack them — the same wrong answer, written more confidently. Deleting the option
 * defers the rebuild to the next request, by which time `init` has registered the types. It is
 * the same idiom `Lichtbild_Migration::finish()` uses after a rename, for the same reason.
 *
 * @return void
 */
function lichtbild_activate() {
	delete_option( 'rewrite_rules' );
}

register_activation_hook( __FILE__, 'lichtbild_activate' );
