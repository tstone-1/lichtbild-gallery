/**
 * The gallery editor: media picker, drag reordering, row removal.
 *
 * Deliberately built on what WordPress already ships — wp.media for the picker, jQuery UI
 * sortable for the dragging, wp.template for the row markup — so the plugin keeps its "no
 * build step, no npm dependency at runtime" property on the admin side as well.
 *
 * The order of the rows is written into a hidden field rather than left implicit in the DOM
 * order of the inputs. The browser would serialise them in DOM order and PHP would preserve
 * it, so it would usually work; "usually" is not a good enough standard for the one property
 * a drag-and-drop editor exists to set.
 */
( function ( $ ) {
	'use strict';

	var frame = null;
	var counter = 0;

	/**
	 * Rewrites the hidden order field from the current DOM order.
	 */
	function syncOrder() {
		var keys = [];

		$( '#lichtbild-editor-items .lichtbild-editor__item' ).each( function () {
			var key = $( this ).attr( 'data-key' );

			if ( key ) {
				keys.push( key );
			}
		} );

		$( '#lichtbild-editor-order' ).val( keys.join( ',' ) );
		$( '#lichtbild-editor-empty' ).toggle( 0 === keys.length );
	}

	/**
	 * Returns the thumbnail URL for a selected attachment.
	 *
	 * @param {Object} data Attachment JSON from the media frame.
	 * @return {string} A URL, falling back to the full-size file.
	 */
	function thumbnailFor( data ) {
		if ( data.sizes && data.sizes.thumbnail && data.sizes.thumbnail.url ) {
			return data.sizes.thumbnail.url;
		}

		if ( data.sizes && data.sizes.medium && data.sizes.medium.url ) {
			return data.sizes.medium.url;
		}

		return data.url || '';
	}

	/**
	 * Appends one attachment to the gallery.
	 *
	 * @param {Object} data Attachment JSON from the media frame.
	 */
	function addItem( data ) {
		var template = wp.template( 'lichtbild-editor-item' );

		counter += 1;

		$( '#lichtbild-editor-items' ).append(
			template( {
				key: 'n' + counter,
				id: data.id,
				thumb: thumbnailFor( data ),
				// Frozen so the item still renders if the attachment is deleted later; the
				// reader prefers WordPress whenever the attachment is still there.
				src: data.url || '',
				link: data.url || '',
				title: data.title || '',
				caption: data.caption || '',
				alt: data.alt || '',
				// Supplied by the server through wp_prepare_attachment_for_js. Without it a
				// newly added image would submit an empty tag field and clear the tags it
				// already had — everywhere it appears, not just here.
				tags: data.lichtbildTags || ''
			} )
		);

		syncOrder();
	}

	/**
	 * Opens the media library.
	 */
	function openPicker() {
		if ( frame ) {
			frame.open();

			return;
		}

		frame = wp.media( {
			title: LichtbildEditor.i18n.chooseImages,
			button: { text: LichtbildEditor.i18n.useImages },
			library: { type: 'image' },
			multiple: 'add'
		} );

		frame.on( 'select', function () {
			frame.state().get( 'selection' ).each( function ( attachment ) {
				addItem( attachment.toJSON() );
			} );
		} );

		frame.open();
	}

	$( function () {
		var list = $( '#lichtbild-editor-items' );

		if ( ! list.length ) {
			return;
		}

		list.sortable( {
			items: '> .lichtbild-editor__item',
			placeholder: 'lichtbild-editor__placeholder',
			forcePlaceholderSize: true,
			tolerance: 'pointer',
			update: syncOrder
		} );

		$( '#lichtbild-add-images' ).on( 'click', openPicker );

		// The note above the rows says whether what is typed into them will be shown, and that
		// answer is a checkbox in another metabox on the same form. Bound rather than left to
		// the next page load, because the gap between ticking the box and seeing the note is
		// exactly when somebody would otherwise type a caption that is never displayed.
		$( '#lichtbild-live_metadata' ).on( 'change', function () {
			$( '#lichtbild-editor-live-note' ).toggle( this.checked );
		} );

		list.on( 'click', '.lichtbild-editor__remove', function () {
			$( this ).closest( '.lichtbild-editor__item' ).remove();
			syncOrder();
		} );

		syncOrder();
	} );
}( jQuery ) );
