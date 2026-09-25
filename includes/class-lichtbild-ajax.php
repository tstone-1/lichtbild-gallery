<?php
/**
 * AJAX endpoints for paginated galleries.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves gallery pages and whole-gallery lightbox data.
 *
 * Both endpoints are read-only and available to logged-out visitors, because galleries are
 * public content. That makes the capability check on non-public galleries load-bearing
 * rather than decorative: a gallery in draft or private status is visible on the front end
 * only to someone who could already read it, and the endpoints have to enforce the same
 * rule or they become a way around it.
 */
class Lichtbild_Ajax {

	/**
	 * Gallery repository.
	 *
	 * @var Lichtbild_Repository
	 */
	private $repository;

	/**
	 * Renderer.
	 *
	 * @var Lichtbild_Renderer
	 */
	private $renderer;

	/**
	 * Builds the handler.
	 *
	 * @param Lichtbild_Repository $repository Gallery repository.
	 * @param Lichtbild_Renderer   $renderer   Renderer.
	 */
	public function __construct( Lichtbild_Repository $repository, Lichtbild_Renderer $renderer ) {
		$this->repository = $repository;
		$this->renderer   = $renderer;
	}

	/**
	 * Hooks the endpoints.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( array( 'lichtbild_page', 'lichtbild_items' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, str_replace( 'lichtbild_', 'handle_', $action ) ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, str_replace( 'lichtbild_', 'handle_', $action ) ) );
		}
	}

	/**
	 * Returns the markup for one page of a gallery.
	 *
	 * @return void
	 */
	public function handle_page() {
		$gallery = $this->authorised_gallery();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see authorised_gallery(): the nonce is verified and deliberately not refused on, because a full-page cache serves pages older than a nonce's twelve-hour life. This endpoint reads and changes nothing; authorization is Lichtbild_Repository::is_viewable(). `(int)` is the sanitisation.
		$page = isset( $_REQUEST['page'] ) ? max( 1, (int) $_REQUEST['page'] ) : 1;
		$tag  = $this->requested_tag();

		// A tag makes this walk the whole gallery to work out which items match and how many
		// pages that leaves; without one, only the page being returned is ever read.
		$gallery->prime( '' === $tag ? $gallery->page_items( $page, $tag ) : $gallery->items() );

		$total = $gallery->page_count( $tag );
		$page  = min( $page, $total );

		wp_send_json_success(
			array(
				'html'  => $this->renderer->items( $gallery, $gallery->page_items( $page, $tag ) ),
				'nav'   => $total > 1 ? $this->renderer->pagination( $gallery, $page, $tag ) : '',
				'page'  => $page,
				'pages' => $total,
			)
		);
	}

	/**
	 * Returns lightbox data for every image in a gallery.
	 *
	 * A paginated gallery whose lightbox spans pages needs the images that are not in the
	 * DOM. Sending only what the lightbox needs — rather than the grid markup for every
	 * page — keeps that payload small even for the 504-image gallery on this site.
	 *
	 * The tag is honoured here too: with a filter active the lightbox must page through the
	 * matching images across the whole gallery, which is precisely the set the grid cannot
	 * supply because it is showing one page of it.
	 *
	 * @return void
	 */
	public function handle_items() {
		$gallery       = $this->authorised_gallery();
		$tag           = $this->requested_tag();
		$lightbox_size = $gallery->lightbox_size();
		$exif_fields   = $gallery->has_exif() ? $gallery->exif_fields() : array();
		$exif_format   = $gallery->exif_date_format();
		$items         = array();

		// Everything, and before the filter rather than after it: deciding which items carry
		// the tag reads the terms of every item, so filtering first would pay the per-image
		// cost this exists to avoid and then prime what was left.
		$gallery->prime( $gallery->items() );

		foreach ( $gallery->filtered_items( $tag ) as $item ) {
			$source = $item->lightbox_source( $lightbox_size );

			// An item with no known dimensions cannot be sized by the lightbox, and passing
			// zeroes makes its zoom arithmetic divide by nothing. Those items stay in the
			// grid as ordinary links instead.
			if ( '' === $source['url'] || $source['width'] <= 0 || $source['height'] <= 0 ) {
				continue;
			}

			$entry = array(
				'src'    => $source['url'],
				'srcset' => $source['srcset'],
				'width'  => $source['width'],
				'height' => $source['height'],
				'id'     => $item->id(),
				'key'    => $gallery->item_key( $item ),
				'title'  => $item->title(),
				'alt'    => $item->alt(),
			);

			$caption = $item->caption();

			if ( '' !== $caption ) {
				$entry['caption'] = $caption;
			}

			if ( ! empty( $exif_fields ) ) {
				$exif = Lichtbild_Exif::fields( $item->id(), $exif_fields, $exif_format );

				if ( ! empty( $exif ) ) {
					$entry['exif'] = $exif;
				}
			}

			if ( $gallery->has_download() ) {
				$download = $item->url( 'full' );

				if ( '' !== $download ) {
					$entry['download'] = $download;
				}
			}

			$tags = $item->tags();

			if ( ! empty( $tags ) ) {
				$entry['tags'] = wp_list_pluck( $tags, 'slug' );
			}

			$items[] = $entry;
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Reads the tag filter out of the request.
	 *
	 * **A request parameter has whatever shape the caller gave it, and these two endpoints are
	 * the ones anyone at all can reach.** `tag[]=x` passes the nonce and the authorization
	 * above and then hands an array to `sanitize_title()`, whose first act is a `preg_match()`
	 * on the value — a `TypeError` on PHP 8, so an uncaught fatal and an HTTP 500 that any
	 * logged-out visitor can repeat at will.
	 *
	 * The reading that keeps it a filter rather than a weapon is the one an absent parameter
	 * already gets: something that is not a string names no tag, and the gallery renders
	 * unfiltered.
	 *
	 * @return string Tag slug, or an empty string when the request named none.
	 */
	private function requested_tag() {
		// Unslashed at the read rather than at the use. The two are equivalent here --
		// `wp_unslash()` recurses and leaves a non-string alone, and the `is_string()` test below
		// is what actually keeps an array away from `sanitize_title()` -- but only this order
		// lets a static analyser see that the value is unslashed before it is sanitized, and a
		// reviewer reads the analyser's output before reading the code.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a public read endpoint on cacheable pages, see authorised_gallery(); sanitized by `sanitize_title()` on return, which cannot happen at the read because a non-string has to reach the `is_string()` test intact rather than be coerced by a sanitiser first.
		$raw = isset( $_REQUEST['tag'] ) ? wp_unslash( $_REQUEST['tag'] ) : '';

		return is_string( $raw ) ? sanitize_title( $raw ) : '';
	}

	/**
	 * Validates the request and returns the gallery it refers to.
	 *
	 * Sends a JSON error and exits when the request is not valid, so callers can treat the
	 * return value as always usable.
	 *
	 * @return Lichtbild_Gallery The requested gallery.
	 */
	private function authorised_gallery() {
		// **The nonce is verified and never refused on**, which is deliberate and is the third
		// argument below.
		//
		// A nonce is a fact about *when a page was generated*, and it stops being valid twelve
		// hours later. A full-page cache — the normal configuration for a public WordPress site,
		// and the one where a 504-image gallery most needs its pagination — serves pages that
		// were generated days ago, so the nonce a logged-out visitor holds is routinely expired
		// before they ever click anything. Refusing on it would break pagination, tag filtering
		// and the lightbox on those sites, at an hour nobody can predict, with no error a site
		// owner could act on: the page renders, and then a button does nothing.
		//
		// Nothing is given up by not refusing, because there is nothing here for CSRF to steal.
		// Both endpoints are reads that change no state, they return JSON a cross-origin script
		// cannot read, and their authorization is the `is_viewable()` call below — which has to
		// hold on its own regardless, since anyone who knows a gallery ID can reach them whether
		// or not its page was ever rendered. A nonce that cannot refuse is still worth sending
		// and checking: it fires `check_ajax_referer` for anything monitoring it, and it keeps
		// the ordinary same-origin request distinguishable from one that forged its way here.
		check_ajax_referer( 'lichtbild', 'nonce', false );

		$id      = isset( $_REQUEST['gallery'] ) ? (int) $_REQUEST['gallery'] : 0;
		$gallery = $id > 0 ? $this->repository->gallery( $id ) : null;

		if ( null === $gallery ) {
			wp_send_json_error( array( 'message' => __( 'Gallery not found.', 'lichtbild-gallery' ) ), 404 );
		}

		// The nonce above is CSRF hygiene and nothing more — every logged-out visitor shares one
		// public nonce, liftable from any rendered page. This is the authorization, and these
		// endpoints are reachable by anyone who knows a gallery ID whether or not its page was
		// ever rendered, so it has to hold on its own.
		if ( ! $this->repository->is_viewable( $gallery->id() ) ) {
			wp_send_json_error( array( 'message' => __( 'Gallery not found.', 'lichtbild-gallery' ) ), 404 );
		}

		return $gallery;
	}
}
