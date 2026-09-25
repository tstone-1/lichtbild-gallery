<?php
/**
 * Turns a gallery into front-end markup.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders galleries and albums.
 *
 * The justified layout is done in CSS rather than JavaScript. Every item carries its own
 * aspect ratio, and within a flex row each item both grows and is sized in proportion to
 * that ratio — so every item in a row settles at the same height without anything being
 * measured at runtime. Envira needed isotope for this, which meant the grid could not be
 * laid out until the images had loaded and their sizes were known; here the correct
 * geometry is in the first paint, so there is no reflow when the images arrive.
 */
class Lichtbild_Renderer {

	/**
	 * Asset manager, told which features a rendered gallery needs.
	 *
	 * @var Lichtbild_Assets
	 */
	private $assets;

	/**
	 * Builds the renderer.
	 *
	 * @param Lichtbild_Assets $assets Asset manager.
	 */
	public function __construct( Lichtbild_Assets $assets ) {
		$this->assets = $assets;
	}

	/**
	 * Renders a gallery.
	 *
	 * @param Lichtbild_Gallery $gallery Gallery to render.
	 * @param int            $page    One-based page number to show first.
	 *
	 * @return string HTML markup.
	 */
	public function gallery( Lichtbild_Gallery $gallery, $page = 1 ) {
		if ( 0 === $gallery->count() ) {
			return '';
		}

		$this->assets->need_gallery();

		$dom_id = 'lichtbild-' . $gallery->id();
		$items  = $gallery->page_items( $page );

		// The tag bar lists every tag in the gallery, not just the rendered page, so a gallery
		// showing it reads all of them; one showing ten images reads ten.
		$gallery->prime( $gallery->has_tags() ? $gallery->items() : $items );

		$classes = array( 'lichtbild', 'lichtbild-theme-' . $gallery->lightbox_theme() );

		if ( $gallery->is_justified() ) {
			$classes[] = 'lichtbild-justified';
		} else {
			$classes[] = 'lichtbild-columns';
		}

		if ( $gallery->has_protection() ) {
			$classes[] = 'lichtbild-protected';
		}

		if ( 'none' !== $gallery->title_display() ) {
			$classes[] = 'lichtbild-titles-' . $gallery->title_display();
		}

		$style = sprintf(
			'--lichtbild-gap:%dpx;--lichtbild-row:%d;--lichtbild-columns:%d;',
			$gallery->gutter(),
			$gallery->row_height(),
			$gallery->columns()
		);

		// No `<style>` element is emitted here, and nothing else in this class emits one either.
		// A gallery used to carry a free-text CSS block that was printed inline at this point;
		// the wordpress.org guidelines do not permit a plugin to store and print arbitrary CSS
		// entered through its own UI, and an inline `<style>` is the wrong way to ship CSS in
		// any case -- `Lichtbild_Assets` enqueues every stylesheet this plugin has.
		$out = sprintf(
			'<div id="%s-wrap" class="lichtbild-wrap"%s>',
			esc_attr( $dom_id ),
			$gallery->has_protection() ? ' oncontextmenu="return false"' : ''
		);

		$tag_bar = $gallery->has_tags() ? $this->tag_bar( $gallery ) : '';

		if ( '' !== $tag_bar && 'above' === $gallery->tags_position() ) {
			$out .= $tag_bar;
		}

		$out .= sprintf(
			'<div id="%s" class="%s" style="%s" data-lichtbild-id="%d" data-lichtbild-config="%s">',
			esc_attr( $dom_id ),
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $style ),
			(int) $gallery->id(),
			esc_attr( wp_json_encode( $this->client_config( $gallery, $page ) ) )
		);

		$out .= $this->items( $gallery, $items );
		$out .= '</div>';

		$out .= '<div class="lichtbild-pagination-slot">';

		if ( $gallery->has_pagination() && $gallery->page_count() > 1 ) {
			$out .= $this->pagination( $gallery, $page );
		}

		$out .= '</div>';

		if ( '' !== $tag_bar && 'below' === $gallery->tags_position() ) {
			$out .= $tag_bar;
		}

		$out .= '</div>';

		/**
		 * Filters the finished markup of a gallery.
		 *
		 * @param string         $out     Rendered HTML.
		 * @param Lichtbild_Gallery $gallery The gallery.
		 * @param int            $page    Page number rendered.
		 */
		return (string) apply_filters( 'lichtbild_gallery_html', $out, $gallery, $page );
	}

	/**
	 * Renders just the items of a gallery, without the surrounding grid element.
	 *
	 * Used both for the initial render and for AJAX page loads, so that a page fetched
	 * later is byte-for-byte the markup it would have had on a first render.
	 *
	 * @param Lichtbild_Gallery $gallery Gallery being rendered.
	 * @param Lichtbild_Item[]  $items   Items to render.
	 *
	 * @return string HTML markup.
	 */
	public function items( Lichtbild_Gallery $gallery, array $items ) {
		$grid_size     = $gallery->grid_size();
		$lightbox_size = $gallery->lightbox_size();
		$lazy          = $gallery->has_lazy_loading();
		$exif_fields   = $gallery->has_exif() ? $gallery->exif_fields() : array();
		$exif_format   = $gallery->exif_date_format();
		$out           = '';

		foreach ( $items as $item ) {
			$aspect   = $item->aspect();
			$src      = $item->url( $grid_size );
			$lightbox = $item->lightbox_source( $lightbox_size );

			if ( '' === $src ) {
				continue;
			}

			$attributes = array(
				'class'            => 'lichtbild-link',
				'href'             => $lightbox['url'],
				'data-pswp-width'  => $lightbox['width'] > 0 ? (string) $lightbox['width'] : '',
				'data-pswp-height' => $lightbox['height'] > 0 ? (string) $lightbox['height'] : '',
				// Paired with the full-size dimensions above: those let PhotoSwipe fill the
				// viewport, this stops that costing a small screen the original file. Emitted
				// only when there is one, so an item whose attachment is gone carries no empty
				// attribute for the JS to have to distinguish from a missing one.
				'data-pswp-srcset' => $lightbox['srcset'],
				'data-lichtbild-item' => (string) $item->id(),
				'data-lichtbild-key' => $gallery->item_key( $item ),
			);

			if ( '' === $attributes['data-pswp-srcset'] ) {
				unset( $attributes['data-pswp-srcset'] );
			}

			$title = $item->title();

			if ( '' !== $title ) {
				$attributes['data-lichtbild-title'] = $title;
			}

			$caption = $item->caption();

			if ( '' !== $caption ) {
				$attributes['data-lichtbild-caption'] = $caption;
			}

			if ( ! empty( $exif_fields ) ) {
				$exif = Lichtbild_Exif::fields( $item->id(), $exif_fields, $exif_format );

				if ( ! empty( $exif ) ) {
					$attributes['data-lichtbild-exif'] = wp_json_encode( $exif );
				}
			}

			if ( $gallery->has_download() ) {
				$attributes['data-lichtbild-download'] = $item->url( 'full' );
			}

			$tags = $item->tags();

			// The tag list goes on the `<figure>` below and nowhere else. It was emitted on the
			// anchor as well, and the filter reads only the figure's copy — a second copy of the
			// same data on every tagged image, which is bytes on every page and one more place
			// for the two to disagree the day either is changed.
			$item_class = 'lichtbild-item';

			if ( ! empty( $tags ) ) {
				$item_class .= ' lichtbild-has-tags';
			}

			$out .= sprintf(
				'<figure class="%s" style="--lichtbild-aspect:%s"%s>',
				esc_attr( $item_class ),
				esc_attr( number_format( $aspect, 4, '.', '' ) ),
				! empty( $tags ) ? ' data-lichtbild-tags="' . esc_attr( implode( ' ', wp_list_pluck( $tags, 'slug' ) ) ) . '"' : ''
			);

			$out .= '<a' . $this->attributes( $attributes ) . '>';

			$img = array(
				'src'      => $src,
				'alt'      => $item->alt(),
				'loading'  => $lazy ? 'lazy' : 'eager',
				'decoding' => 'async',
			);

			$srcset = $item->srcset( $grid_size );

			if ( '' !== $srcset ) {
				$img['srcset'] = $srcset;
				$img['sizes']  = sprintf(
					'(max-width: 600px) 100vw, %dpx',
					max( 200, (int) round( $gallery->row_height() * $aspect ) )
				);
			}

			list( $width, $height ) = $item->dimensions();

			if ( $width > 0 && $height > 0 ) {
				$img['width']  = (string) $width;
				$img['height'] = (string) $height;
			}

			$out .= '<img' . $this->attributes( $img ) . ' />';
			$out .= '</a>';

			if ( 'below' === $gallery->title_display() && '' !== $title ) {
				$out .= '<figcaption class="lichtbild-caption">' . esc_html( $title ) . '</figcaption>';
			} elseif ( 'float' === $gallery->title_display() && '' !== $title ) {
				$out .= '<figcaption class="lichtbild-caption lichtbild-caption-float">' . esc_html( $title ) . '</figcaption>';
			}

			$out .= '</figure>';
		}

		return $out;
	}

	/**
	 * Renders the pagination controls.
	 *
	 * @param Lichtbild_Gallery $gallery Gallery being rendered.
	 * @param int            $page    Current one-based page number.
	 * @param string         $tag     Tag slug currently applied.
	 *
	 * @return string HTML markup.
	 */
	public function pagination( Lichtbild_Gallery $gallery, $page, $tag = '' ) {
		$total = $gallery->page_count( $tag );
		$page  = max( 1, min( (int) $page, $total ) );

		$out = '<nav class="lichtbild-pagination" data-lichtbild-total="' . (int) $total . '" aria-label="' .
			esc_attr__( 'Gallery pages', 'lichtbild-gallery' ) . '">';

		$out .= sprintf(
			'<button type="button" class="lichtbild-page-prev" data-lichtbild-page="%d"%s>%s</button>',
			$page - 1,
			1 === $page ? ' disabled' : '',
			esc_html__( 'Previous', 'lichtbild-gallery' )
		);

		$out .= '<span class="lichtbild-page-list">';

		for ( $number = 1; $number <= $total; $number++ ) {
			$out .= sprintf(
				'<button type="button" class="lichtbild-page%s" data-lichtbild-page="%d"%s>%d</button>',
				$number === $page ? ' is-current' : '',
				$number,
				$number === $page ? ' aria-current="page"' : '',
				$number
			);
		}

		$out .= '</span>';

		$out .= sprintf(
			'<button type="button" class="lichtbild-page-next" data-lichtbild-page="%d"%s>%s</button>',
			$page + 1,
			$page === $total ? ' disabled' : '',
			esc_html__( 'Next', 'lichtbild-gallery' )
		);

		return $out . '</nav>';
	}

	/**
	 * Renders the tag filter bar.
	 *
	 * @param Lichtbild_Gallery $gallery Gallery being rendered.
	 *
	 * @return string HTML markup, empty when the gallery has no tagged images.
	 */
	private function tag_bar( Lichtbild_Gallery $gallery ) {
		$tags = array();

		foreach ( $gallery->items() as $item ) {
			foreach ( $item->tags() as $tag ) {
				$tags[ $tag['slug'] ] = $tag['name'];
			}
		}

		if ( empty( $tags ) ) {
			return '';
		}

		asort( $tags, SORT_NATURAL | SORT_FLAG_CASE );

		$out = '<div class="lichtbild-tags" role="group" aria-label="' . esc_attr__( 'Filter by tag', 'lichtbild-gallery' ) . '">';

		// `aria-pressed` on every button, not a class alone. The class is what CSS draws with
		// and carries no meaning to a screen reader, so which filter is currently applied was
		// simply not announced -- while the pagination three lines down had exposed its active
		// page with `aria-current` all along. Emitted on ALL of them, including the ones that
		// are false: a toggle group where only the active button carries the attribute reads as
		// a set of buttons with unknown state rather than one selected out of several.
		if ( $gallery->tags_all_enabled() ) {
			$out .= '<button type="button" class="lichtbild-tag is-current" aria-pressed="true" data-lichtbild-tag="">' .
				esc_html( $gallery->tags_all_label() ) . '</button>';
		}

		foreach ( $tags as $slug => $name ) {
			$out .= sprintf(
				'<button type="button" class="lichtbild-tag" aria-pressed="false" data-lichtbild-tag="%s">%s</button>',
				esc_attr( $slug ),
				esc_html( $name )
			);
		}

		return $out . '</div>';
	}

	/**
	 * Renders an album as a grid of gallery covers.
	 *
	 * @param Lichtbild_Album      $album      Album to render.
	 * @param Lichtbild_Repository $repository Repository used to load each gallery.
	 *
	 * @return string HTML markup.
	 */
	public function album( Lichtbild_Album $album, Lichtbild_Repository $repository ) {
		if ( 0 === $album->count() ) {
			return '';
		}

		$this->assets->need_gallery();

		$out = sprintf(
			'<div class="lichtbild-album" style="--lichtbild-album-columns:%d">',
			$album->columns()
		);

		// Walked by position rather than by gallery ID, because the album's item list may name
		// the same gallery twice -- the storage allows it and the editor can create it. Looking
		// the cover and caption up by ID returns the first match for every position, so both
		// rows would render the first one's photograph, which is the opposite of why someone
		// would list a gallery twice. `Lichtbild_Album`'s own docblock says per-position callers
		// must walk `items()`; this is the caller it was talking about.
		foreach ( $album->items() as $item ) {
			$gallery_id = (int) $item['id'];
			$gallery    = $repository->gallery( $gallery_id );

			if ( null === $gallery || 0 === $gallery->count() ) {
				continue;
			}

			// The third place that can publish a gallery, and the last one to learn this rule.
			// A post password is enforced by WordPress replacing `post_content`, and an album
			// reaches none of that: the cover, the title and the count all come from post meta
			// and from the gallery row. So a protected gallery's cover photograph would be
			// published on a public album page, with its title beside it, while the gallery's
			// own permalink and the AJAX endpoints both correctly refuse.
			if ( ! $repository->is_viewable( $gallery_id ) ) {
				continue;
			}

			$cover_id = (int) $item['cover_id'];
			$cover    = '';

			if ( $cover_id > 0 ) {
				$src = wp_get_attachment_image_src( $cover_id, 'medium_large' );

				if ( is_array( $src ) ) {
					$cover = $src[0];
				}
			}

			if ( '' === $cover ) {
				$items = $gallery->items();
				$cover = ! empty( $items ) ? $items[0]->url( 'medium_large' ) : '';
			}

			if ( '' === $cover ) {
				continue;
			}

			$permalink = get_permalink( $gallery_id );
			$caption   = (string) $item['caption'];

			$out .= '<figure class="lichtbild-album-item">';
			$out .= '<a class="lichtbild-album-link" href="' . esc_url( $permalink ? $permalink : '#' ) . '">';
			$out .= '<img src="' . esc_url( $cover ) . '" alt="' . esc_attr( $gallery->title() ) .
				'" loading="lazy" decoding="async" />';
			$out .= '<figcaption class="lichtbild-album-caption">';

			// Both were stored by Envira, varied between this site's albums, and were rendered
			// unconditionally anyway — so the record carried a choice the renderer ignored.
			// Honouring them is a no-op here (both albums have both on) and is what makes the
			// settings mean something once an editor can change them.
			if ( $album->has_titles() ) {
				$out .= '<span class="lichtbild-album-title">' . esc_html( $gallery->title() ) . '</span>';
			}

			if ( $album->has_counts() ) {
				$out .= '<span class="lichtbild-album-count">' . esc_html(
					sprintf(
						/* translators: %d: number of images in a gallery. */
						_n( '%d image', '%d images', $gallery->count(), 'lichtbild-gallery' ),
						$gallery->count()
					)
				) . '</span>';
			}

			if ( '' !== $caption ) {
				$out .= '<span class="lichtbild-album-excerpt">' . esc_html( wp_strip_all_tags( $caption ) ) . '</span>';
			}

			$out .= '</figcaption></a></figure>';
		}

		return $out . '</div>';
	}

	/**
	 * Builds the configuration handed to the front-end script.
	 *
	 * @param Lichtbild_Gallery $gallery Gallery being rendered.
	 * @param int            $page    Page number being rendered first.
	 *
	 * @return array Client configuration.
	 */
	private function client_config( Lichtbild_Gallery $gallery, $page = 1 ) {
		return array(
			'id'         => $gallery->id(),
			'page'       => (int) $page,
			'keyboard'   => $gallery->has_keyboard(),
			'exif'       => $gallery->has_exif(),
			'social'     => $gallery->has_social(),
			'networks'   => $gallery->social_networks(),
			'download'   => $gallery->has_download(),
			'protection' => $gallery->has_protection(),
			'pagination' => $gallery->has_pagination(),
			'pages'      => $gallery->page_count(),
			'spanPages'  => $gallery->lightbox_spans_pages(),
			'scroll'     => $gallery->pagination_scrolls(),
		);
	}

	/**
	 * Builds an escaped attribute string.
	 *
	 * @param array<string,string> $attributes Attribute names mapped to raw values.
	 *
	 * @return string Attribute string with a leading space, empty when nothing to emit.
	 */
	private function attributes( array $attributes ) {
		$out = '';

		foreach ( $attributes as $name => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			// URL-bearing attributes only. `srcset` is deliberately not among them: it is a
			// comma-separated list with width descriptors, which `esc_url()` would mangle, and
			// `esc_attr()` is the correct escaping for it. It used to carry a branch of its own
			// here that recomputed exactly what this ternary already produces.
			$escaped = in_array( $name, array( 'href', 'src', 'data-lichtbild-download' ), true )
				? esc_url( $value )
				: esc_attr( $value );

			$out .= ' ' . $name . '="' . $escaped . '"';
		}

		return $out;
	}
}
