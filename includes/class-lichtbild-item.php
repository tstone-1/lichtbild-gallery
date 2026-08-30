<?php
/**
 * A single image inside a gallery.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * One gallery image, combining Envira's stored item record with live WordPress data.
 *
 * Envira stores a frozen copy of the URL, title and caption at the time the image was
 * added to the gallery. Where the attachment still exists we prefer WordPress as the
 * source of truth for dimensions and derivative sizes, because Envira's record has no
 * dimensions at all and its `src` always points at the original full-size file — which
 * is what made Envira galleries heavy. Where the attachment is gone (deleted from the
 * media library but left in the gallery), the frozen record is all there is, so it is used
 * after a scheme check and the item degrades to a plain unsized image rather than
 * disappearing. Such an item has no known dimensions, which is why it is kept out of the
 * lightbox rather than handed to it as a zero-sized slide.
 */
class Lichtbild_Item {

	/**
	 * Attachment ID, or 0 when the item is not backed by an attachment.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Envira's stored item record.
	 *
	 * @var array
	 */
	private $record;

	/**
	 * Cached attachment metadata, or null until first looked up.
	 *
	 * @var array|null
	 */
	private $meta;

	/**
	 * Whether the attachment metadata has been looked up yet.
	 *
	 * @var bool
	 */
	private $meta_loaded = false;

	/**
	 * Taxonomy per-image tags are read from.
	 *
	 * @var string
	 */
	private $tag_taxonomy;

	/**
	 * Whether the media library's current values win over the gallery's frozen ones.
	 *
	 * @var bool
	 */
	private $live_metadata = false;

	/**
	 * Wraps one Envira gallery item.
	 *
	 * @param int    $id           Attachment ID, or 0 when unknown.
	 * @param array  $record       Envira's stored item record.
	 * @param string $tag_taxonomy Taxonomy per-image tags are read from.
	 */
	public function __construct( $id, array $record, $tag_taxonomy = 'envira-tag' ) {
		$this->id           = (int) $id;
		$this->record       = $record;
		$this->tag_taxonomy = (string) $tag_taxonomy;
	}

	/**
	 * Chooses which copy of the title, caption and alt text this item reads.
	 *
	 * Off — the default, and what every gallery gets until somebody says otherwise — the stored
	 * record wins, so a gallery shows the words Envira froze into it when each image was added.
	 * On, the media library's current values win and the stored ones become the fallback for
	 * whatever the library cannot answer: an attachment that has been deleted, or a field left
	 * blank on one that still exists.
	 *
	 * **Nothing here writes.** Turning this on changes which of two existing values is read; the
	 * stored record is left exactly as it was, which is what makes switching it off again
	 * lossless rather than a restore.
	 *
	 * Set per gallery rather than per item because it is a gallery setting. `Lichtbild_Gallery`
	 * is the only object holding both the settings and the items, so that is where the two meet.
	 *
	 * @param bool $enabled Whether to read the media library first.
	 *
	 * @return void
	 */
	public function use_live_metadata( $enabled ) {
		$this->live_metadata = (bool) $enabled;
	}

	/**
	 * Turns one submitted item row into a stored item record.
	 *
	 * This lives beside the code that reads those keys on purpose. The record shape is
	 * asserted in exactly two places — here and in the migration's converter — and a field
	 * added to one without the other is a field that silently stops surviving a save, which
	 * is why the suite compares the two rather than trusting them to stay in step.
	 *
	 * @param array $input Raw submitted row, already unslashed.
	 *
	 * @return array|null Item record, or null when the row names nothing that can be shown.
	 */
	public static function sanitize_record( array $input ) {
		$id  = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$src = self::clean_url( self::text( $input, 'src' ) );

		// An item is worth keeping if it can produce an image: either an attachment to ask
		// WordPress about, or a frozen URL from before that attachment was deleted. A row with
		// neither renders as an empty box, so dropping it is the only sane reading.
		if ( $id <= 0 && '' === $src ) {
			return null;
		}

		$status = self::text( $input, 'status' );

		return array(
			'id'      => $id,
			'status'  => 'pending' === $status ? 'pending' : 'active',
			'src'     => $src,
			'link'    => self::clean_url( self::text( $input, 'link' ) ),
			'title'   => sanitize_text_field( self::text( $input, 'title' ) ),
			'caption' => wp_kses_post( self::text( $input, 'caption' ) ),
			'alt'     => sanitize_text_field( self::text( $input, 'alt' ) ),
		);
	}

	/**
	 * Reads one submitted field as text, treating anything else as unsubmitted.
	 *
	 * A form field is a string or it is absent — except that `lichtbild_items[i0][title][]` is an
	 * array, and nothing stops a request carrying one. Cast, that becomes the literal word
	 * "Array" stored as the image's title, with a PHP warning on the way; the array-aware
	 * sanitisers downstream never get the chance to help, because the cast happens first.
	 *
	 * @param array  $input Raw submitted row.
	 * @param string $key   Field name.
	 *
	 * @return string The submitted text, or an empty string.
	 */
	private static function text( array $input, $key ) {
		return isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? $input[ $key ] : '';
	}

	/**
	 * Returns the keys a stored item record carries.
	 *
	 * @return string[] Record keys.
	 */
	public static function record_keys() {
		return array( 'id', 'status', 'src', 'link', 'title', 'caption', 'alt' );
	}

	/**
	 * Restricts a submitted URL to a scheme safe to hand a visitor.
	 *
	 * @param mixed $url Candidate URL.
	 *
	 * @return string The URL, or an empty string.
	 */
	private static function clean_url( $url ) {
		$url = trim( (string) $url );

		return '' === $url ? '' : (string) esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Returns the attachment ID backing this item.
	 *
	 * @return int Attachment ID, or 0 when the item has no attachment.
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Reports whether Envira marked this item as active.
	 *
	 * Envira uses a `pending` status for items queued by its own import routines; those
	 * are not shown on the front end and Lichtbild matches that.
	 *
	 * @return bool True when the item should be displayed.
	 */
	public function is_active() {
		$status = isset( $this->record['status'] ) ? $this->record['status'] : 'active';

		return 'pending' !== $status;
	}

	/**
	 * Loads and caches the attachment metadata.
	 *
	 * @return array Attachment metadata, empty when unavailable.
	 */
	private function meta() {
		if ( ! $this->meta_loaded ) {
			$this->meta_loaded = true;
			$this->meta        = array();

			if ( $this->id > 0 ) {
				$meta = wp_get_attachment_metadata( $this->id );

				if ( is_array( $meta ) ) {
					$this->meta = $meta;
				}
			}
		}

		return $this->meta;
	}

	/**
	 * Returns the intrinsic width and height of the original image.
	 *
	 * @return array{0:int,1:int} Width and height in pixels; zeroes when unknown.
	 */
	public function dimensions() {
		$meta = $this->meta();

		if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			return array( (int) $meta['width'], (int) $meta['height'] );
		}

		return array( 0, 0 );
	}

	/**
	 * Returns the aspect ratio used to lay the item out.
	 *
	 * Falls back to 3:2 for items whose dimensions are unknown, which keeps a deleted
	 * attachment from collapsing the justified row it sits in.
	 *
	 * @return float Width divided by height.
	 */
	public function aspect() {
		list( $width, $height ) = $this->dimensions();

		if ( $width > 0 && $height > 0 ) {
			return $width / $height;
		}

		return 1.5;
	}

	/**
	 * Returns the URL for a registered image size, falling back to Envira's stored URL.
	 *
	 * @param string $size Registered image size name.
	 *
	 * @return string Image URL, empty when nothing is available.
	 */
	public function url( $size ) {
		if ( $this->id > 0 ) {
			$src = wp_get_attachment_image_src( $this->id, $size );

			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				return $src[0];
			}
		}

		return isset( $this->record['src'] ) ? $this->safe_url( $this->record['src'] ) : '';
	}

	/**
	 * Returns the URL and pixel size of the image shown in the lightbox.
	 *
	 * PhotoSwipe needs the exact dimensions of the file it is about to display, not the
	 * dimensions of the original, or it opens with the wrong zoom and the wrong aspect.
	 *
	 * @param string $size Registered image size name.
	 *
	 * @return array{url:string,width:int,height:int} Lightbox source description.
	 */
	public function lightbox_source( $size ) {
		if ( $this->id > 0 ) {
			$src = wp_get_attachment_image_src( $this->id, $size );

			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				// The dimensions declared are the FULL-size ones, not this size's, and that is
				// the whole point rather than a detail.
				//
				// PhotoSwipe computes its `fit` zoom level as `Math.min( 1, viewport / natural )`
				// — capped at 1, so it never scales an image up. Declaring the configured size
				// therefore caps the lightbox at that size: on this site `large` is 1024px wide
				// and exists on 1,563 of 2,243 attachments, so most photographs opened at 1024px
				// on any display, however large. That is what "the lightbox does not fill the
				// viewport" is, and it was true under Envira too.
				//
				// Declaring the full size lets PhotoSwipe fill the viewport, and the srcset is
				// what stops that costing a phone the original file: PhotoSwipe writes `sizes`
				// from the displayed width on every resize, so the browser fetches the smallest
				// candidate that covers what is actually on screen. Without the srcset this would
				// be a straight bandwidth regression for everyone.
				list( $full_width, $full_height ) = $this->dimensions();

				return array(
					'url'    => $src[0],
					'width'  => $full_width > 0 ? $full_width : (int) $src[1],
					'height' => $full_height > 0 ? $full_height : (int) $src[2],
					'srcset' => $this->srcset( $size ),
				);
			}
		}

		$link                   = isset( $this->record['link'] ) ? $this->safe_url( $this->record['link'] ) : '';
		list( $width, $height ) = $this->dimensions();

		return array(
			'url'    => '' !== $link ? $link : $this->url( 'full' ),
			'width'  => $width,
			'height' => $height,
			// An attachment that no longer exists has no generated sizes to offer.
			'srcset' => '',
		);
	}

	/**
	 * Returns the responsive srcset for the grid thumbnail.
	 *
	 * @param string $size Registered image size name.
	 *
	 * @return string A srcset attribute value, empty when unavailable.
	 */
	public function srcset( $size ) {
		if ( $this->id <= 0 ) {
			return '';
		}

		$srcset = wp_get_attachment_image_srcset( $this->id, $size );

		return is_string( $srcset ) ? $srcset : '';
	}

	/**
	 * Returns the item title, preferring Envira's per-gallery override.
	 *
	 * With `use_live_metadata()` on, the attachment's current title wins instead — but only
	 * when it says something. An attachment that has been deleted, or one saved without a
	 * title, leaves the frozen chain below to answer, so switching the setting on can add a
	 * label and can change one, and cannot take one away.
	 *
	 * @return string Title text.
	 */
	public function title() {
		if ( $this->live_metadata ) {
			$live = $this->id > 0 ? trim( (string) get_the_title( $this->id ) ) : '';

			if ( '' !== $live ) {
				return $live;
			}
		}

		$title = isset( $this->record['title'] ) ? trim( (string) $this->record['title'] ) : '';

		if ( '' !== $title ) {
			return $title;
		}

		return $this->id > 0 ? (string) get_the_title( $this->id ) : '';
	}

	/**
	 * Returns the item caption, filtered to the markup post content may carry.
	 *
	 * Captions legitimately contain inline markup, and the lightbox inserts them as HTML.
	 * Transporting the string through an escaped HTML attribute does not make that safe —
	 * `getAttribute()` hands back the original text, and `innerHTML` then parses whatever it
	 * was. So the allowlist has to be applied here, on the way out of the database, rather
	 * than relied upon from the escaping that happens later for a different reason.
	 *
	 * With `use_live_metadata()` on, the attachment's own excerpt — which is what the media
	 * library calls the caption — wins when it says something. The allowlist is applied to
	 * whichever value is chosen, because both come out of the database and both end up in the
	 * lightbox's `innerHTML`.
	 *
	 * @return string Caption text, restricted to post-content markup.
	 */
	public function caption() {
		if ( $this->live_metadata && $this->id > 0 ) {
			$live = get_post_field( 'post_excerpt', $this->id );
			$live = is_string( $live ) ? trim( $live ) : '';

			if ( '' !== $live ) {
				return wp_kses_post( $live );
			}
		}

		$caption = isset( $this->record['caption'] ) ? trim( (string) $this->record['caption'] ) : '';

		if ( '' === $caption && $this->id > 0 ) {
			$excerpt = get_post_field( 'post_excerpt', $this->id );
			$caption = is_string( $excerpt ) ? $excerpt : '';
		}

		return '' !== $caption ? wp_kses_post( $caption ) : '';
	}

	/**
	 * Returns a URL only if it uses a scheme safe to put in front of a visitor.
	 *
	 * Envira froze the URL of every item at the time it was added, and Lichtbild falls back to
	 * that frozen value whenever the attachment is gone. That value is whatever was in the
	 * database, so it is validated rather than trusted — otherwise a stored `javascript:`
	 * URL reaches an anchor's href, and the JSON endpoint hands it out unescaped besides.
	 *
	 * @param string $url Candidate URL.
	 *
	 * @return string The URL, or an empty string when its scheme is not allowed.
	 */
	private function safe_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		return (string) esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Returns the alt text, falling back to the title so the image is never unlabelled.
	 *
	 * With `use_live_metadata()` on, the media library's alt text wins — but only when it says
	 * something. A value that is empty, or only whitespace, is read as "the library has nothing
	 * for this image" and the frozen chain below answers instead. So this setting can add an
	 * accessible name and can change one, and never takes one away.
	 *
	 * That reading is a decision, and the renderer is what decides it. The `<img>` is the only
	 * content of the anchor around it, so an image with an empty alt leaves a link with no
	 * accessible name at all — a screen reader falls back to announcing the URL. An empty alt is
	 * how HTML marks a decorative image, and WordPress does record a deliberately-cleared one
	 * distinguishably (the meta row exists holding `''`), so honouring it here is implementable.
	 * It is not done because the two ways of being wrong are not the same size: reading a blank
	 * as decorative silently strips the name off every image on a site whose owner never filled
	 * the field in, which is the ordinary state of a photograph uploaded years ago, while
	 * reading it as missing costs an owner who genuinely meant it a label they can clear on the
	 * gallery's own row instead. A gallery of unnamed links is not a state this setting may
	 * produce by accident.
	 *
	 * @return string Alt text.
	 */
	public function alt() {
		if ( $this->live_metadata && $this->id > 0 ) {
			$live = get_post_meta( $this->id, '_wp_attachment_image_alt', true );
			$live = is_string( $live ) ? trim( $live ) : '';

			if ( '' !== $live ) {
				return $live;
			}
		}

		$alt = isset( $this->record['alt'] ) ? trim( (string) $this->record['alt'] ) : '';

		// A literal two-character `""` is read as no alt text as well. It has NOT been observed
		// on the site this was built from -- all 2,264 items there carry `''` -- and no fixture
		// carries it, so this branch is untested by the suite. It stays because the alternative
		// is worse for the one record that does have it: two quote characters would become the
		// image's accessible name, and the fallback to the title below would never be reached.
		if ( '' !== $alt && '""' !== $alt ) {
			return $alt;
		}

		if ( $this->id > 0 ) {
			$stored = get_post_meta( $this->id, '_wp_attachment_image_alt', true );

			if ( is_string( $stored ) && '' !== trim( $stored ) ) {
				return $stored;
			}
		}

		return $this->title();
	}

	/**
	 * Returns the tag slugs assigned to this image.
	 *
	 * Envira's tag addon migrated per-item tags into the `envira-tag` taxonomy on the
	 * attachment (the `_processed_tag_upgrade` meta records that having happened), so the
	 * taxonomy is authoritative and the item record's `tags` key is left empty.
	 *
	 * @return array<int,array{slug:string,name:string}> Tags, in taxonomy order.
	 */
	public function tags() {
		if ( $this->id <= 0 ) {
			return array();
		}

		$terms = get_the_terms( $this->id, $this->tag_taxonomy );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$tags = array();

		foreach ( $terms as $term ) {
			$tags[] = array(
				'slug' => $term->slug,
				'name' => $term->name,
			);
		}

		return $tags;
	}
}
