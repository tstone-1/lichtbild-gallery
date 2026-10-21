<?php
/**
 * Removes installation settings while keeping gallery content and its storage identity.
 *
 * Schema, slug scheme and standalone behavior survive while owned posts or tags remain,
 * so reinstalling restores the same URLs. With no owned content, all plugin options are removed.
 *
 * What this deliberately never deletes, because each would be destructive:
 *
 * - **The gallery and album records** (`_lichtbild_gallery`, `_lichtbild_album` and their posts).
 *   They are the photographs, content rather than settings. Deleting the plugin unregisters the
 *   post types, so they stop being visible; reinstalling makes every one reappear untouched.
 * - **Envira's `_eg_gallery_data` and `_eg_album_data`.** They are what a rollback restores
 *   authority to, and they are not Lichtbild's to remove under any circumstances.
 * - **`envira_gallery_standalone_enabled`.** Lichtbild reads it before the migration and copies its
 *   value into its own; reading a setting never makes it yours to delete.
 *
 * @package Lichtbild
 */

// Set by WordPress itself when it runs this file. Its absence means the file was requested
// directly, which is the one way this could be called by someone who did not delete anything.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Storage identity and permalink behavior belong to retained content, not the install.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall must preserve the identity of content it retains.
$lichtbild_post_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( 'lichtbild_gallery', 'lichtbild_album' )" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- retained tags also need their original URL scheme.
$lichtbild_tag_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'lichtbild_tag'" );
// A failed observation is not proof that the content is gone.
$lichtbild_has_content = null === $lichtbild_post_count || null === $lichtbild_tag_count
	|| (int) $lichtbild_post_count > 0 || (int) $lichtbild_tag_count > 0;

/*
 * Options Lichtbild writes. Named literally rather than read from `Lichtbild_Settings::OPTION_*`,
 * because none of the plugin's classes are loaded during uninstall: WordPress includes this file
 * alone, with no `lichtbild-gallery.php` before it, so a `Lichtbild_Settings::OPTION_SCHEMA` here
 * is a fatal, not a constant. `tests/render-test.php` sets every `lichtbild_` constant and checks
 * that this list removes it, which is what keeps the two from drifting.
 */
$lichtbild_options = array(
	'lichtbild_schema_version',
	'lichtbild_takeover',
	'lichtbild_standalone',
	'lichtbild_slug_scheme',
);

foreach ( $lichtbild_options as $lichtbild_option ) {
	if ( $lichtbild_has_content && in_array( $lichtbild_option, array( 'lichtbild_schema_version', 'lichtbild_slug_scheme', 'lichtbild_standalone' ), true ) ) {
		continue;
	}
	delete_option( $lichtbild_option );
}

// Per-user transients: the migration screen hands its result to the next request in one, and
// both editors leave one behind when they refuse a truncated form, so the next screen can say
// why. Each expires within five minutes, but one is left for anyone who deleted the plugin
// inside that window.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall runs once, with no request after it to serve from a cache; transients are keyed by a name pattern that no core API enumerates.
$lichtbild_transients = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE '\\_transient\\_lichtbild\\_migration\\_result\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_lichtbild\\_migration\\_result\\_%'
	    OR option_name LIKE '\\_transient\\_lichtbild\\_editor\\_nonce\\_incomplete\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_lichtbild\\_editor\\_nonce\\_incomplete\\_%'
	    OR option_name LIKE '\\_transient\\_lichtbild\\_album\\_editor\\_nonce\\_incomplete\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_lichtbild\\_album\\_editor\\_nonce\\_incomplete\\_%'"
);

foreach ( (array) $lichtbild_transients as $lichtbild_transient ) {
	delete_option( $lichtbild_transient );
}
