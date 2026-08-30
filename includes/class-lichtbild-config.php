<?php
/**
 * The normalised gallery settings schema, and the translation into it from Envira's.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Defines what a gallery's settings are, and converts Envira's version of them.
 *
 * Envira writes about 281 config keys per gallery, the overwhelming majority of which are
 * defaults its settings screen serialised rather than choices anyone made. Twenty-six of
 * them decide what a visitor sees. This class is the boundary between those two facts: it
 * owns the short list, and it is the only place that has to understand the long one.
 *
 * Putting the translation here rather than in `Lichtbild_Gallery` matters for two reasons.
 * Reading a v2 gallery then involves no Envira knowledge at all, which is what makes the
 * migration meaningful rather than cosmetic. And the messy parts of Envira's format — the
 * four spellings of true, the two generations of EXIF toggles, the CSS keyed on the old
 * plugin's element ids — are handled once, at the point of conversion, instead of being
 * re-derived on every page load forever.
 */
class Lichtbild_Config {

	/**
	 * Schema version written into converted records.
	 */
	const VERSION = 2;

	/**
	 * Returns the settings a gallery has when nothing says otherwise.
	 *
	 * Every default matches Envira's own, so a gallery saved by a version that predates a
	 * given key renders the way it does today rather than changing under the reader.
	 *
	 * There is deliberately no `custom_css` key, and it is not an oversight to be repaired.
	 * Through 26.8.21 a gallery carried a free-text CSS block that the renderer printed in an
	 * inline `<style>` element; the wordpress.org Plugin Directory does not permit a plugin to
	 * store and emit arbitrary CSS entered through its own UI, so the setting, the textarea,
	 * the conversion from Envira's key and the `<style>` output were all removed together.
	 * Removing only one of the four would leave a stored value nothing reads, which this
	 * codebase has already paid for once.
	 *
	 * Anything a gallery needs beyond the settings here belongs in the theme or in
	 * Appearance -> Customize -> Additional CSS, where WordPress's own editor validates it.
	 * The element ids are stable and documented for exactly that purpose: a gallery is
	 * `#lichtbild-<id>` and its wrapper `#lichtbild-<id>-wrap`.
	 *
	 * `live_metadata` defaults to false for the same reason every other default matches
	 * Envira's: a gallery that shows the words Envira froze into it has to keep showing them
	 * until its owner says otherwise. It is the one setting whose default decides whether a
	 * migration is faithful, so it is off and it is opt-in per gallery.
	 *
	 * @return array Normalised settings.
	 */
	public static function defaults() {
		return array(
			'layout'              => 'justified',
			'columns'             => 3,
			'row_height'          => 150,
			'gutter'              => 10,
			'image_size'          => 'medium_large',
			'lightbox_size'       => 'large',
			'title_display'       => 'none',
			'live_metadata'       => false,
			'lazy_loading'        => true,
			'pagination'          => false,
			'per_page'            => 0,
			'pagination_scroll'   => true,
			'lightbox_span_pages' => true,
			'lightbox_theme'      => 'dark',
			'keyboard'            => true,
			'protection'          => false,
			'exif'                => false,
			'exif_fields'         => array(),
			'exif_date_format'    => '',
			'social'              => false,
			'social_networks'     => array(),
			'download'            => false,
			'tags'                => false,
			'tags_position'       => 'below',
			'tags_all_enabled'    => true,
			'tags_all_label'      => '',
		);
	}

	/**
	 * Converts one Envira config array into normalised settings.
	 *
	 * @param array $envira Envira's config array.
	 * @param int   $id     Gallery post ID, passed on to the `lichtbild_config_from_envira` filter.
	 *
	 * @return array Normalised settings.
	 */
	public static function from_envira( array $envira, $id = 0 ) {
		$settings = self::defaults();

		// Envira spells automatic width as `columns = 0`; anything else is a fixed grid.
		$columns              = self::number( $envira, 'columns', 0 );
		$settings['layout']   = 0 === $columns ? 'justified' : 'columns';
		$settings['columns']  = $columns > 0 ? $columns : 3;
		$settings['gutter']   = max( 0, self::number( $envira, 'gutter', self::number( $envira, 'margin', 10 ) ) );

		$row_height             = self::number( $envira, 'justified_row_height', 150 );
		$settings['row_height'] = $row_height > 0 ? $row_height : 150;

		$image_size             = (string) self::get( $envira, 'image_size', 'default' );
		$settings['image_size'] = ( '' === $image_size || 'default' === $image_size || 'full' === $image_size )
			? 'medium_large'
			: $image_size;

		$lightbox_size             = (string) self::get( $envira, 'lightbox_image_size', 'default' );
		$settings['lightbox_size'] = ( '' === $lightbox_size || 'default' === $lightbox_size )
			? 'large'
			: $lightbox_size;

		$title_display             = (string) self::get( $envira, 'title_display', 'none' );
		$settings['title_display'] = in_array( $title_display, array( 'none', 'float', 'below' ), true )
			? $title_display
			: 'none';

		$settings['lazy_loading'] = self::flag( $envira, 'lazy_loading', true );
		$settings['pagination']   = self::flag( $envira, 'pagination', false );
		$settings['per_page']     = max( 0, self::number( $envira, 'pagination_images_per_page', 0 ) );

		if ( 0 === $settings['per_page'] ) {
			$settings['pagination'] = false;
		}

		$settings['pagination_scroll']   = self::flag( $envira, 'pagination_scroll', true );
		$settings['lightbox_span_pages'] = self::flag( $envira, 'pagination_lightbox_display_all_images', true );

		$theme                      = (string) self::get( $envira, 'lightbox_theme', 'base_dark' );
		$settings['lightbox_theme'] = false !== strpos( $theme, 'dark' ) ? 'dark' : 'light';

		$settings['keyboard']   = self::flag( $envira, 'keyboard', true );
		$settings['protection'] = self::flag( $envira, 'protection', false );

		// EXIF: the lightbox toggles win over the grid-caption ones, which are the older
		// generation and are what a gallery predating the lightbox set will carry.
		//
		// "Wins" has to mean *present*, not *true*. Written as `new || old`, a newer toggle
		// explicitly switched off could never override an older one left on — which is the
		// only case where the precedence matters at all, and the opposite of what the rule
		// says. No gallery in this site's fixture is in that state, so nothing here would have
		// noticed; `prefer()` makes the code do what the sentence above claims.
		$settings['exif'] = self::prefer( $envira, 'exif_lightbox', 'exif', false );

		foreach ( Lichtbild_Exif::SUPPORTED as $field ) {
			if ( self::prefer( $envira, 'exif_lightbox_' . $field, 'exif_' . $field, false ) ) {
				$settings['exif_fields'][] = $field;
			}
		}

		$format                       = (string) self::get( $envira, 'exif_lightbox_capture_time_format', '' );
		$settings['exif_date_format'] = '' !== $format
			? $format
			: (string) self::get( $envira, 'exif_capture_time_format', '' );

		// Same two generations, and this used to resolve them the other way round from EXIF —
		// ignoring the older top-level flag entirely while still falling back to the older
		// per-network ones. Both now use the same rule.
		$settings['social'] = self::prefer( $envira, 'social_lightbox', 'social', false );

		foreach ( array( 'facebook', 'twitter', 'pinterest', 'email' ) as $network ) {
			if ( self::prefer( $envira, 'social_lightbox_' . $network, 'social_' . $network, false ) ) {
				$settings['social_networks'][] = $network;
			}
		}

		$settings['download'] = self::flag( $envira, 'download_lightbox', false );

		$settings['tags']             = self::flag( $envira, 'tags', false ) || self::flag( $envira, 'tags_filter', false );
		$settings['tags_position']    = 'above' === (string) self::get( $envira, 'tags_position', 'below' ) ? 'above' : 'below';
		$settings['tags_all_enabled'] = self::flag( $envira, 'tags_all_enabled', true );
		$settings['tags_all_label']   = trim( (string) self::get( $envira, 'tags_all', '' ) );

		// `live_metadata` is deliberately NOT derived from anything Envira stores, and there is
		// nothing it could be derived from: Envira freezes an item's title, caption and alt into
		// its own record when the image is added and has no notion of reading them back from the
		// media library afterwards. So a converted gallery takes the default of false, which is
		// what keeps the migration faithful — the words on the page do not move because the
		// storage did. Turning it on is a choice made per gallery on the edit screen.

		// Envira's `custom_css` is deliberately NOT converted. See the note on `defaults()`:
		// the plugin no longer has a setting to convert it into, and a conversion whose result
		// nothing reads is worse than no conversion at all. Envira's own record still holds it,
		// so nothing is lost -- `tools/export-custom-css.py` is how it comes back out.

		/**
		 * Filters the settings converted from an Envira gallery.
		 *
		 * @param array $settings Normalised settings.
		 * @param array $envira   The Envira config they came from.
		 * @param int   $id       Gallery post ID.
		 */
		return (array) apply_filters( 'lichtbild_config_from_envira', $settings, $envira, $id );
	}

	/**
	 * Fills in anything missing from a stored settings array.
	 *
	 * A record written by an older version of this plugin will not have keys added since,
	 * and reading one must not depend on the writer having been current.
	 *
	 * @param array $settings Stored settings.
	 *
	 * @return array Settings with every key present.
	 */
	public static function fill( array $settings ) {
		$filled = array_merge( self::defaults(), $settings );

		// Types are asserted rather than trusted: these come out of the database, where an
		// older writer or a hand-edit may have left a string where a list belongs.
		foreach ( array( 'exif_fields', 'social_networks' ) as $list ) {
			$filled[ $list ] = is_array( $filled[ $list ] ) ? array_values( $filled[ $list ] ) : array();
		}

		return $filled;
	}

	/**
	 * Returns the image sizes a gallery may be set to use.
	 *
	 * @return string[] Registered size names, with `full` last.
	 */
	public static function image_sizes() {
		$sizes = function_exists( 'get_intermediate_image_sizes' ) ? get_intermediate_image_sizes() : array();
		$sizes = array_values( array_filter( array_map( 'strval', (array) $sizes ) ) );

		$sizes[] = 'full';

		return array_values( array_unique( $sizes ) );
	}

	/**
	 * Turns a submitted settings form into a valid settings array.
	 *
	 * This is deliberately not `fill()` with sanitising bolted on, because the two answer
	 * opposite questions about an absent key. A *stored* record is missing a key because the
	 * version that wrote it did not have that key yet, so the default is the right answer and
	 * that is what `fill()` gives. A *submitted form* is missing a key because an unchecked
	 * checkbox sends nothing — so a default of true would make such a box impossible to
	 * switch off. Every boolean here therefore reads absence as false, and every other field
	 * falls back to its default only when the value it did receive is unusable.
	 *
	 * @param array $input Raw `$_POST` settings, already unslashed.
	 *
	 * @return array Normalised settings, every key present and correctly typed.
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$out      = $defaults;

		$out['layout']     = self::choice( $input, 'layout', array( 'justified', 'columns' ), $defaults['layout'] );
		$out['columns']    = self::bounded( $input, 'columns', 1, 12, $defaults['columns'] );
		$out['row_height'] = self::bounded( $input, 'row_height', 40, 800, $defaults['row_height'] );
		$out['gutter']     = self::bounded( $input, 'gutter', 0, 100, $defaults['gutter'] );

		$sizes                   = self::image_sizes();
		$out['image_size']       = self::choice( $input, 'image_size', $sizes, $defaults['image_size'] );
		$out['lightbox_size']    = self::choice( $input, 'lightbox_size', $sizes, $defaults['lightbox_size'] );
		$out['title_display']    = self::choice( $input, 'title_display', array( 'none', 'float', 'below' ), $defaults['title_display'] );
		$out['lightbox_theme']   = self::choice( $input, 'lightbox_theme', array( 'dark', 'light' ), $defaults['lightbox_theme'] );
		$out['tags_position']    = self::choice( $input, 'tags_position', array( 'above', 'below' ), $defaults['tags_position'] );
		$out['tags_all_label']   = self::text( $input, 'tags_all_label' );
		$out['exif_date_format'] = self::text( $input, 'exif_date_format' );

		// Absent means unchecked. See the docblock: this is the one place where that is the
		// right reading, and it is the opposite of what `fill()` does.
		foreach ( array(
			'live_metadata',
			'lazy_loading',
			'pagination',
			'pagination_scroll',
			'lightbox_span_pages',
			'keyboard',
			'protection',
			'exif',
			'social',
			'download',
			'tags',
			'tags_all_enabled',
		) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] );
		}

		$out['per_page'] = self::bounded( $input, 'per_page', 0, 500, 0 );

		// Envira's own rule, and the reader relies on it: pagination with no page size is a
		// gallery that renders nothing.
		if ( 0 === $out['per_page'] ) {
			$out['pagination'] = false;
		}

		$out['exif_fields']     = self::subset( $input, 'exif_fields', Lichtbild_Exif::SUPPORTED );
		$out['social_networks'] = self::subset( $input, 'social_networks', array( 'facebook', 'twitter', 'pinterest', 'email' ) );

		// A submitted `custom_css` is dropped rather than sanitised. The form no longer offers
		// the field, so anything arriving under that name is either a stale bookmark or someone
		// posting by hand -- and an allowlisted record is the one shape that cannot be talked
		// into carrying a key the reader has no code for.

		/**
		 * Filters settings submitted through the gallery editor.
		 *
		 * The raw submission is deliberately NOT passed. It was, as context, and handing an
		 * unsanitised `$_POST` array to an unknown callback is a sanitising function offering a
		 * way around itself — the value a filter is least able to resist using is the one it is
		 * given. Everything a callback legitimately needs is in `$out`, already typed.
		 *
		 * @param array $out Sanitised settings.
		 */
		return (array) apply_filters( 'lichtbild_config_sanitize', $out );
	}

	/**
	 * Reads a submitted value that has to be one of a fixed set.
	 *
	 * @param array    $input   Submitted values.
	 * @param string   $key     Key to read.
	 * @param string[] $allowed Permitted values.
	 * @param string   $default Value when the submission is not permitted.
	 *
	 * @return string One of $allowed.
	 */
	private static function choice( array $input, $key, array $allowed, $default ) {
		$value = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Reads a submitted integer, clamped to a range.
	 *
	 * Clamped rather than rejected: a row height of 40000 is a typo, and the useful response
	 * to a typo is the nearest sane value rather than silently reverting to the default and
	 * leaving the editor showing something nobody typed.
	 *
	 * @param array  $input   Submitted values.
	 * @param string $key     Key to read.
	 * @param int    $min     Lowest permitted value.
	 * @param int    $max     Highest permitted value.
	 * @param int    $default Value when the submission is not a number.
	 *
	 * @return int Integer within [$min, $max].
	 */
	private static function bounded( array $input, $key, $min, $max, $default ) {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			return (int) $default;
		}

		return (int) min( $max, max( $min, (int) $input[ $key ] ) );
	}

	/**
	 * Reads a submitted list, keeping only permitted members and their canonical order.
	 *
	 * @param array    $input   Submitted values.
	 * @param string   $key     Key to read.
	 * @param string[] $allowed Permitted members, in the order they should be stored.
	 *
	 * @return string[] The permitted members that were submitted.
	 */
	private static function subset( array $input, $key, array $allowed ) {
		$given = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
		$given = array_map( 'strval', $given );

		// Iterating the allowlist rather than the submission fixes the stored order, so two
		// galleries with the same fields selected produce the same record.
		return array_values( array_filter( $allowed, function ( $field ) use ( $given ) {
			return in_array( $field, $given, true );
		} ) );
	}

	/**
	 * Reads a submitted single-line string.
	 *
	 * @param array  $input Submitted values.
	 * @param string $key   Key to read.
	 *
	 * @return string Sanitised text.
	 */
	private static function text( array $input, $key ) {
		$value = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';

		return sanitize_text_field( $value );
	}

	/**
	 * Reads a key from an Envira config array.
	 *
	 * @param array  $config  Envira config.
	 * @param string $key     Key to read.
	 * @param mixed  $default Value when absent.
	 *
	 * @return mixed Config value.
	 */
	private static function get( array $config, $key, $default = null ) {
		return array_key_exists( $key, $config ) ? $config[ $key ] : $default;
	}

	/**
	 * Reads an Envira config key as a boolean.
	 *
	 * Envira is inconsistent about how it serialises these — the same logical flag appears
	 * as `1`, `'1'`, `'True'` and `true` across galleries of different vintages, and
	 * `keyboard` is split between `'1'` and `'True'` on one single site. Anything not on the
	 * recognised list of truthy spellings is false.
	 *
	 * @param array  $config  Envira config.
	 * @param string $key     Key to read.
	 * @param bool   $default Value when absent.
	 *
	 * @return bool Interpreted flag.
	 */
	private static function flag( array $config, $key, $default = false ) {
		if ( ! array_key_exists( $key, $config ) ) {
			return $default;
		}

		$value = $config[ $key ];

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return $default;
	}

	/**
	 * Reads whichever of two generations of the same flag the gallery actually carries.
	 *
	 * Envira added a second, lightbox-specific set of toggles beside its original ones, and a
	 * gallery may carry either or both. The newer key wins **when it is present**, which is
	 * the distinction that matters: resolving this as `new || old` means an explicitly
	 * disabled newer toggle can never override an enabled older one, so the older setting
	 * quietly outlives the choice made to replace it.
	 *
	 * @param array  $config  Envira config.
	 * @param string $newer   Preferred key.
	 * @param string $older   Key to fall back to when the preferred one is absent.
	 * @param bool   $default Value when neither is present.
	 *
	 * @return bool Interpreted flag.
	 */
	private static function prefer( array $config, $newer, $older, $default = false ) {
		if ( array_key_exists( $newer, $config ) ) {
			return self::flag( $config, $newer, $default );
		}

		return self::flag( $config, $older, $default );
	}

	/**
	 * Reads an Envira config key as an integer.
	 *
	 * @param array  $config  Envira config.
	 * @param string $key     Key to read.
	 * @param int    $default Value when absent or unusable.
	 *
	 * @return int Interpreted number.
	 */
	private static function number( array $config, $key, $default = 0 ) {
		$value = self::get( $config, $key );

		return is_numeric( $value ) ? (int) $value : $default;
	}
}
