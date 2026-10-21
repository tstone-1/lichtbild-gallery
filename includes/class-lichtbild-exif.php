<?php
/**
 * Formats camera metadata for display in the lightbox.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads EXIF out of WordPress's own attachment metadata.
 *
 * Envira's EXIF addon re-reads the original file on every request to produce this. That is
 * unnecessary: WordPress already parses EXIF at upload time and stores the result in the
 * `image_meta` key of the attachment metadata, so the same values are available with no
 * file access at all.
 */
class Lichtbild_Exif {

	/**
	 * Fields WordPress can report, in the order they are displayed.
	 *
	 * `lens` is deliberately absent: Envira re-reads the original file to obtain it, and
	 * WordPress does not keep it in `image_meta`, so supporting it would mean a file read
	 * per image. It is switched off on every gallery this was built against, and asking for
	 * it simply yields nothing rather than an error.
	 */
	const SUPPORTED = array( 'make', 'model', 'focal_length', 'aperture', 'shutter_speed', 'iso', 'capture_time' );

	/**
	 * Returns the formatted EXIF fields for an attachment.
	 *
	 * Envira exposes a per-field toggle for each of these, and every gallery on the site
	 * this was built for turns camera make, model and capture time off while leaving the
	 * four exposure values on. Honouring the toggles is therefore not a refinement — a
	 * renderer that shows everything it can find prints the camera body on galleries whose
	 * settings say not to.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string[] $enabled       Field keys to include; see self::SUPPORTED.
	 * @param string   $date_format   Date format for the capture time.
	 *
	 * @return array<int,array{label:string,value:string}> Ordered label/value pairs.
	 */
	public static function fields( $attachment_id, array $enabled, $date_format = '' ) {
		$attachment_id = (int) $attachment_id;

		// The same rule `Lichtbild_Item` applies to every other metadata read since 26.9.0: an item's
		// ID is only an attachment if the post says so, and a gallery record can name any post.
		if ( $attachment_id <= 0 || empty( $enabled ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array();
		}

		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['image_meta'] ) || ! is_array( $meta['image_meta'] ) ) {
			return array();
		}

		$exif   = $meta['image_meta'];
		$fields = array();

		// WordPress keeps one combined `camera` string rather than a separate make and
		// model, so either toggle asks for the same value and it is emitted once.
		if ( in_array( 'make', $enabled, true ) || in_array( 'model', $enabled, true ) ) {
			$camera = isset( $exif['camera'] ) ? trim( (string) $exif['camera'] ) : '';

			if ( '' !== $camera ) {
				$fields[] = array(
					'label' => __( 'Camera', 'lichtbild-gallery' ),
					'value' => $camera,
				);
			}
		}

		if ( in_array( 'focal_length', $enabled, true ) ) {
			$focal = isset( $exif['focal_length'] ) ? (float) $exif['focal_length'] : 0.0;

			if ( $focal > 0 ) {
				$fields[] = array(
					'label' => __( 'Focal length', 'lichtbild-gallery' ),
					/* translators: %s: focal length in millimetres. */
					'value' => sprintf( __( '%s mm', 'lichtbild-gallery' ), self::trim_zeros( $focal ) ),
				);
			}
		}

		if ( in_array( 'aperture', $enabled, true ) ) {
			$aperture = isset( $exif['aperture'] ) ? (float) $exif['aperture'] : 0.0;

			if ( $aperture > 0 ) {
				$fields[] = array(
					'label' => __( 'Aperture', 'lichtbild-gallery' ),
					'value' => 'f/' . self::trim_zeros( $aperture ),
				);
			}
		}

		if ( in_array( 'shutter_speed', $enabled, true ) ) {
			$shutter = isset( $exif['shutter_speed'] ) ? (float) $exif['shutter_speed'] : 0.0;

			if ( $shutter > 0 ) {
				$fields[] = array(
					'label' => __( 'Shutter speed', 'lichtbild-gallery' ),
					'value' => self::format_shutter( $shutter ),
				);
			}
		}

		if ( in_array( 'iso', $enabled, true ) ) {
			$iso = isset( $exif['iso'] ) ? (int) $exif['iso'] : 0;

			if ( $iso > 0 ) {
				$fields[] = array(
					'label' => __( 'ISO', 'lichtbild-gallery' ),
					'value' => (string) $iso,
				);
			}
		}

		if ( in_array( 'capture_time', $enabled, true ) ) {
			$taken = isset( $exif['created_timestamp'] ) ? (int) $exif['created_timestamp'] : 0;

			if ( $taken > 0 ) {
				$format = '' !== $date_format ? $date_format : (string) get_option( 'date_format' );

				$fields[] = array(
					'label' => __( 'Taken', 'lichtbild-gallery' ),
					'value' => date_i18n( $format, $taken ),
				);
			}
		}

		/**
		 * Filters the EXIF fields shown for an image.
		 *
		 * @param array $fields        Ordered label/value pairs.
		 * @param int   $attachment_id Attachment ID.
		 * @param array $exif          Raw `image_meta` array from WordPress.
		 */
		return (array) apply_filters( 'lichtbild_exif_fields', $fields, $attachment_id, $exif );
	}

	/**
	 * Renders a shutter speed as a photographer would write it.
	 *
	 * WordPress stores the value in seconds as a float, so 0.004 has to come back out as
	 * `1/250s` rather than as a decimal nobody reads.
	 *
	 * @param float $seconds Exposure time in seconds.
	 *
	 * @return string Formatted shutter speed.
	 */
	private static function format_shutter( $seconds ) {
		if ( $seconds >= 1.0 ) {
			/* translators: %s: exposure time in seconds. */
			return sprintf( __( '%s s', 'lichtbild-gallery' ), self::trim_zeros( $seconds ) );
		}

		$denominator = (int) round( 1 / $seconds );

		return '1/' . $denominator . ' s';
	}

	/**
	 * Formats a float without trailing zeroes.
	 *
	 * @param float $value Number to format.
	 *
	 * @return string Formatted number.
	 */
	private static function trim_zeros( $value ) {
		$formatted = rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );

		return '' !== $formatted ? $formatted : '0';
	}
}
