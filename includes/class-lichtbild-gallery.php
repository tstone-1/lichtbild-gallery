<?php
/**
 * A gallery: normalised settings plus its items.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * One gallery, expressed entirely in Lichtbild's own terms.
 *
 * Deliberately contains no knowledge of Envira. Whatever a gallery was stored as, the
 * repository hands this class the twenty-six normalised settings defined by
 * `Lichtbild_Config`, so the reader is the same for a record Envira wrote and one Lichtbild
 * wrote. That is the difference between a migration that means something and a rename.
 */
class Lichtbild_Gallery {

	/**
	 * Post ID of the gallery.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Normalised settings, every key present.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * The gallery's items.
	 *
	 * @var Lichtbild_Item[]
	 */
	private $items;

	/**
	 * Builds a gallery.
	 *
	 * @param int           $id       Gallery post ID.
	 * @param array         $settings Normalised settings; missing keys are filled.
	 * @param Lichtbild_Item[] $items    Active items in display order.
	 */
	public function __construct( $id, array $settings, array $items ) {
		$this->id       = (int) $id;
		$this->settings = Lichtbild_Config::fill( $settings );
		$this->items    = $items;

		// `live_metadata` is a gallery setting whose effect is read per item, and this class is
		// the only object that holds both halves — the repository builds the items before it has
		// the settings, and the renderer sees the items one at a time. Applied here, every path
		// that reaches an item goes through a gallery first, so the AJAX endpoints and the
		// standalone page get the same answer as the shortcode without asking again.
		$live = ! empty( $this->settings['live_metadata'] );

		foreach ( $this->items as $item ) {
			$item->use_live_metadata( $live );
		}
	}

	/**
	 * Returns the gallery post ID.
	 *
	 * @return int Post ID.
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Returns the gallery title.
	 *
	 * @return string Title text.
	 */
	public function title() {
		return (string) get_the_title( $this->id );
	}

	/**
	 * Returns all active items.
	 *
	 * @return Lichtbild_Item[] Items in display order.
	 */
	public function items() {
		return $this->items;
	}

	/**
	 * Returns the number of active items.
	 *
	 * @return int Item count.
	 */
	public function count() {
		return count( $this->items );
	}

	/**
	 * Returns the whole settings array.
	 *
	 * @return array Normalised settings.
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Reports whether the gallery uses the justified row layout.
	 *
	 * @return bool True for justified rows, false for a fixed column grid.
	 */
	public function is_justified() {
		$justified = 'justified' === $this->settings['layout'];

		/**
		 * Filters whether a gallery uses the justified row layout.
		 *
		 * @param bool           $justified Whether rows are justified.
		 * @param Lichtbild_Gallery $gallery   The gallery being rendered.
		 */
		return (bool) apply_filters( 'lichtbild_is_justified', $justified, $this );
	}

	/**
	 * Returns the fixed column count, for galleries not using justified rows.
	 *
	 * @return int Column count, at least one.
	 */
	public function columns() {
		return max( 1, (int) $this->settings['columns'] );
	}

	/**
	 * Returns the target row height for the justified layout, in pixels.
	 *
	 * @return int Row height.
	 */
	public function row_height() {
		return max( 1, (int) $this->settings['row_height'] );
	}

	/**
	 * Returns the gap between images, in pixels.
	 *
	 * @return int Gap size.
	 */
	public function gutter() {
		return max( 0, (int) $this->settings['gutter'] );
	}

	/**
	 * Returns the registered image size used for grid thumbnails.
	 *
	 * @return string Registered image size name.
	 */
	public function grid_size() {
		/**
		 * Filters the registered image size used for grid thumbnails.
		 *
		 * @param string         $size    Registered image size name.
		 * @param Lichtbild_Gallery $gallery The gallery being rendered.
		 */
		return (string) apply_filters( 'lichtbild_grid_image_size', $this->settings['image_size'], $this );
	}

	/**
	 * Returns the registered image size opened in the lightbox.
	 *
	 * @return string Registered image size name.
	 */
	public function lightbox_size() {
		/**
		 * Filters the registered image size opened in the lightbox.
		 *
		 * @param string         $size    Registered image size name.
		 * @param Lichtbild_Gallery $gallery The gallery being rendered.
		 */
		return (string) apply_filters( 'lichtbild_lightbox_image_size', $this->settings['lightbox_size'], $this );
	}

	/**
	 * Reports whether pagination is enabled.
	 *
	 * @return bool True when the gallery paginates.
	 */
	public function has_pagination() {
		return (bool) $this->settings['pagination'] && $this->per_page() > 0;
	}

	/**
	 * Returns the number of images per page.
	 *
	 * @return int Page size.
	 */
	public function per_page() {
		return max( 0, (int) $this->settings['per_page'] );
	}

	/**
	 * Primes the WordPress caches for a set of this gallery's items.
	 *
	 * Reading an item touches three things WordPress caches per post: the attachment row (for
	 * the title and excerpt an item falls back to), its meta (for the dimensions and the
	 * registered sizes) and its terms (for the tag filter). Unprimed, that is three queries per
	 * image — so the lightbox endpoint on the 504-image gallery cost around fifteen hundred
	 * queries in a single request, while a visitor waited for the lightbox to open.
	 *
	 * Called with the set about to be walked rather than always with everything: a paginated
	 * gallery renders ten items, and priming five hundred to read ten would be the same mistake
	 * pointing the other way.
	 *
	 * @param Lichtbild_Item[] $items Items about to be read.
	 *
	 * @return int Number of attachments primed.
	 */
	public function prime( array $items ) {
		$ids = array();

		foreach ( $items as $item ) {
			$id = $item->id();

			// Keyed by ID: an item may legitimately appear twice in a v2 gallery, and priming
			// is per attachment rather than per item.
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids = array_values( $ids );

		// Core's own helper for this, and the only one that primes all three caches together.
		// The leading underscore marks it as internal to core rather than deprecated — it has
		// been stable since 3.4 — but the guard means a future core that drops it degrades to
		// unprimed reads rather than a fatal error on a public endpoint.
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, true, true );
		}

		return count( $ids );
	}

	/**
	 * Returns the items carrying a given tag.
	 *
	 * @param string $tag Tag slug; an empty slug means every item.
	 *
	 * @return Lichtbild_Item[] Matching items in display order.
	 */
	public function filtered_items( $tag = '' ) {
		if ( '' === $tag ) {
			return $this->items;
		}

		$matching = array();

		foreach ( $this->items as $item ) {
			foreach ( $item->tags() as $item_tag ) {
				if ( $item_tag['slug'] === $tag ) {
					$matching[] = $item;
					break;
				}
			}
		}

		return $matching;
	}

	/**
	 * Returns the number of pages.
	 *
	 * The tag is part of the arithmetic because filtering happens across the whole gallery
	 * rather than within the current page — a visitor filtering to one species wants every
	 * photograph of it, not the handful that happened to land on the page they were on.
	 *
	 * @param string $tag Tag slug currently applied.
	 *
	 * @return int Page count, at least one.
	 */
	public function page_count( $tag = '' ) {
		if ( ! $this->has_pagination() ) {
			return 1;
		}

		return max( 1, (int) ceil( count( $this->filtered_items( $tag ) ) / $this->per_page() ) );
	}

	/**
	 * Returns the items belonging to one page.
	 *
	 * @param int    $page One-based page number.
	 * @param string $tag  Tag slug currently applied.
	 *
	 * @return Lichtbild_Item[] Items on that page.
	 */
	public function page_items( $page, $tag = '' ) {
		$items = $this->filtered_items( $tag );

		if ( ! $this->has_pagination() ) {
			return $items;
		}

		$page = max( 1, min( (int) $page, $this->page_count( $tag ) ) );

		return array_slice( $items, ( $page - 1 ) * $this->per_page(), $this->per_page() );
	}

	/**
	 * Reports whether the lightbox should cycle the whole gallery rather than one page.
	 *
	 * @return bool True when the lightbox spans every page.
	 */
	public function lightbox_spans_pages() {
		return (bool) $this->settings['lightbox_span_pages'];
	}

	/**
	 * Reports whether the pagination controls scroll the gallery back into view.
	 *
	 * @return bool True when paging scrolls.
	 */
	public function pagination_scrolls() {
		return (bool) $this->settings['pagination_scroll'];
	}

	/**
	 * Reports whether right-click and drag protection is enabled.
	 *
	 * @return bool True when protection is on.
	 */
	public function has_protection() {
		return (bool) $this->settings['protection'];
	}

	/**
	 * Reports whether EXIF data is shown in the lightbox.
	 *
	 * @return bool True when EXIF is displayed.
	 */
	public function has_exif() {
		return (bool) $this->settings['exif'];
	}

	/**
	 * Returns the EXIF fields this gallery displays.
	 *
	 * @return string[] Enabled field keys.
	 */
	public function exif_fields() {
		/**
		 * Filters the EXIF fields a gallery displays.
		 *
		 * @param string[]       $fields  Enabled field keys.
		 * @param Lichtbild_Gallery $gallery The gallery being rendered.
		 */
		return (array) apply_filters( 'lichtbild_exif_enabled_fields', $this->settings['exif_fields'], $this );
	}

	/**
	 * Returns the date format used for the EXIF capture time.
	 *
	 * @return string A date format string, empty to use the site setting.
	 */
	public function exif_date_format() {
		return (string) $this->settings['exif_date_format'];
	}

	/**
	 * Reports whether sharing buttons are shown in the lightbox.
	 *
	 * @return bool True when sharing is enabled.
	 */
	public function has_social() {
		return (bool) $this->settings['social'];
	}

	/**
	 * Returns the enabled sharing networks.
	 *
	 * @return string[] Network slugs.
	 */
	public function social_networks() {
		/**
		 * Filters the sharing networks offered in the lightbox.
		 *
		 * @param string[]       $networks Network slugs.
		 * @param Lichtbild_Gallery $gallery  The gallery being rendered.
		 */
		return (array) apply_filters( 'lichtbild_social_networks', $this->settings['social_networks'], $this );
	}

	/**
	 * Reports whether a download button is offered in the lightbox.
	 *
	 * @return bool True when downloads are enabled.
	 */
	public function has_download() {
		return (bool) $this->settings['download'];
	}

	/**
	 * Reports whether keyboard navigation is enabled.
	 *
	 * @return bool True when arrow keys move between images.
	 */
	public function has_keyboard() {
		return (bool) $this->settings['keyboard'];
	}

	/**
	 * Reports whether images are lazily loaded.
	 *
	 * @return bool True when lazy loading is on.
	 */
	public function has_lazy_loading() {
		return (bool) $this->settings['lazy_loading'];
	}

	/**
	 * Reports whether the tag filter bar is shown.
	 *
	 * @return bool True when the filter bar is rendered.
	 */
	public function has_tags() {
		return (bool) $this->settings['tags'];
	}

	/**
	 * Returns where the tag filter bar is placed.
	 *
	 * @return string Either `above` or `below`.
	 */
	public function tags_position() {
		return 'above' === $this->settings['tags_position'] ? 'above' : 'below';
	}

	/**
	 * Reports whether the tag filter bar offers an "everything" button.
	 *
	 * @return bool True when the button is shown.
	 */
	public function tags_all_enabled() {
		return (bool) $this->settings['tags_all_enabled'];
	}

	/**
	 * Returns the label of the "everything" button on the tag filter bar.
	 *
	 * Stored per gallery and translated by the site owner rather than by a language pack —
	 * it is `Alle` on every gallery this was built against — so the stored string wins over
	 * Lichtbild's own translation.
	 *
	 * @return string Button label.
	 */
	public function tags_all_label() {
		$label = trim( (string) $this->settings['tags_all_label'] );

		return '' !== $label ? $label : __( 'All', 'lichtbild-gallery' );
	}

	/**
	 * Returns how the image title is displayed over the grid.
	 *
	 * @return string One of `none`, `float` or `below`.
	 */
	public function title_display() {
		return (string) $this->settings['title_display'];
	}

	/**
	 * Returns the lightbox colour scheme.
	 *
	 * @return string Either `dark` or `light`.
	 */
	public function lightbox_theme() {
		return 'light' === $this->settings['lightbox_theme'] ? 'light' : 'dark';
	}

}
