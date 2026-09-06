/**
 * Lichtbild front end.
 *
 * PhotoSwipe is loaded with a dynamic import the first time a visitor actually opens an
 * image, so a page full of galleries costs nothing until someone clicks. The grid itself
 * needs no JavaScript at all — it is laid out in CSS — which is why this file only deals
 * with the lightbox, pagination, tag filtering and image protection.
 */
( function () {
	'use strict';

	var settings = window.LichtbildSettings || {};
	var i18n = settings.i18n || {};
	var photoSwipePromise = null;

	/**
	 * Matches a deep-link fragment, naming the gallery and the image.
	 *
	 * The second alternative is the prefix this plugin wrote before it was renamed. A deep
	 * link is a thing people paste into messages and bookmark, so links carrying it are out
	 * in the world and there is no moment at which the last one stops being followed —
	 * whereas the cost of continuing to resolve them is one alternation, paid once per page
	 * load, on a regular expression that is only ever run against `location.hash`.
	 *
	 * Only reading is bilingual. Everything this writes uses the current prefix, so a legacy
	 * link is upgraded in the address bar as soon as the lightbox it opened writes its own.
	 */
	var DEEP_LINK = /^#(?:lichtbild|tivira)-(\d+)-i(\d+)$/;

	/**
	 * Loads the PhotoSwipe module once and reuses it thereafter.
	 *
	 * @return {Promise<Function>} Resolves with the PhotoSwipe constructor.
	 */
	function loadPhotoSwipe() {
		if ( ! photoSwipePromise ) {
			photoSwipePromise = import( settings.photoswipe ).then( function ( module ) {
				return module.default;
			} ).catch( function ( error ) {
				// Clear the cache so the NEXT click tries again. A rejected promise is a
				// settled promise: caching it turned one transient module-load failure -- a
				// dropped connection, a proxy hiccup -- into a permanently dead lightbox,
				// because every later click reused the same rejection. And the click handler
				// has already called `preventDefault()`, so the failure presented as an image
				// that simply does nothing, forever, with no way back short of a reload.
				photoSwipePromise = null;

				throw error;
			} );
		}

		return photoSwipePromise;
	}

	/**
	 * Reads a JSON data attribute, tolerating absence and malformed values.
	 *
	 * @param {Element} element   Element carrying the attribute.
	 * @param {string}  attribute Attribute name.
	 * @param {*}       fallback  Value to return when parsing fails.
	 *
	 * @return {*} Parsed value or the fallback.
	 */
	function readJson( element, attribute, fallback ) {
		var raw = element.getAttribute( attribute );

		if ( ! raw ) {
			return fallback;
		}

		try {
			return JSON.parse( raw );
		} catch ( error ) {
			return fallback;
		}
	}

	/**
	 * Builds a lightbox slide description from one grid anchor.
	 *
	 * @param {HTMLAnchorElement} link Grid anchor.
	 *
	 * @return {Object} A PhotoSwipe data source entry.
	 */
	function slideFromLink( link ) {
		return {
			src: link.getAttribute( 'href' ),
			// PhotoSwipe writes `sizes` from the displayed width on every resize, so the browser
			// fetches the smallest candidate covering what is actually on screen. Absent for an
			// item whose attachment is gone, and '' is the right value then -- PhotoSwipe skips
			// the attribute rather than emitting an empty srcset.
			srcset: link.getAttribute( 'data-pswp-srcset' ) || '',
			width: parseInt( link.getAttribute( 'data-pswp-width' ), 10 ) || 0,
			height: parseInt( link.getAttribute( 'data-pswp-height' ), 10 ) || 0,
			alt: link.querySelector( 'img' ) ? link.querySelector( 'img' ).alt : '',
			id: parseInt( link.getAttribute( 'data-lichtbild-item' ), 10 ) || 0,
			title: link.getAttribute( 'data-lichtbild-title' ) || '',
			caption: link.getAttribute( 'data-lichtbild-caption' ) || '',
			exif: readJson( link, 'data-lichtbild-exif', null ),
			download: link.getAttribute( 'data-lichtbild-download' ) || '',
			element: link
		};
	}

	/**
	 * Normalises a slide record that arrived from the server.
	 *
	 * @param {Object} entry Raw entry from the items endpoint.
	 *
	 * @return {Object} A PhotoSwipe data source entry.
	 */
	function slideFromRecord( entry ) {
		return {
			src: entry.src,
			srcset: entry.srcset || '',
			width: entry.width || 0,
			height: entry.height || 0,
			alt: entry.alt || '',
			id: entry.id || 0,
			title: entry.title || '',
			caption: entry.caption || '',
			exif: entry.exif || null,
			download: entry.download || ''
		};
	}

	/**
	 * One gallery on the page.
	 *
	 * @param {HTMLElement} root Element carrying the `lichtbild` class.
	 *
	 * @class
	 */
	function Gallery( root ) {
		this.root = root;
		this.wrap = root.closest( '.lichtbild-wrap' ) || root.parentNode;
		this.config = readJson( root, 'data-lichtbild-config', {} );
		this.id = this.config.id || parseInt( root.getAttribute( 'data-lichtbild-id' ), 10 ) || 0;
		this.page = this.config.page || 1;
		this.slideCache = Object.create( null );
		this.pending = false;
		this.sequence = 0;
		this.activeTag = '';

		this.bindGrid();
		this.bindPagination();
		this.bindTags();
		this.bindProtection();
		this.restoreFromHash();
	}

	/**
	 * Delegates clicks on the grid to the lightbox.
	 *
	 * @return {void}
	 */
	Gallery.prototype.bindGrid = function () {
		var self = this;

		this.root.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( 'a.lichtbild-link' );

			if ( ! link || ! self.root.contains( link ) ) {
				return;
			}

			// Leave modified clicks to the browser so "open in new tab" still works.
			if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || 1 === event.button ) {
				return;
			}

			// An item whose attachment is gone has no known dimensions, so the lightbox
			// cannot size it. Let the browser follow the link to the file instead of
			// opening a slide with nothing to scale.
			if ( ! parseInt( link.getAttribute( 'data-pswp-width' ), 10 ) ||
				! parseInt( link.getAttribute( 'data-pswp-height' ), 10 ) ) {
				return;
			}

			event.preventDefault();
			self.open( link );
		} );
	};

	/**
	 * Returns visible grid anchors that can be sized by the lightbox.
	 *
	 * @return {HTMLAnchorElement[]} Visible grid anchors.
	 */
	Gallery.prototype.visibleLinks = function () {
		return Array.prototype.filter.call(
			this.root.querySelectorAll( 'a.lichtbild-link' ),
			function ( link ) {
				var figure = link.closest( '.lichtbild-item' );

				return ( ! figure || ! figure.classList.contains( 'is-filtered' ) ) &&
					parseInt( link.getAttribute( 'data-pswp-width' ), 10 ) > 0 &&
					parseInt( link.getAttribute( 'data-pswp-height' ), 10 ) > 0;
			}
		);
	};

	/**
	 * Resolves the slide list to open, fetching the full gallery when needed.
	 *
	 * A paginated gallery whose lightbox spans pages has to show images that are not in the
	 * DOM, so those are fetched once and cached per tag. The applied filter spans pages too:
	 * a visitor who filtered to one species expects to page through that species.
	 *
	 * @return {Promise<Object[]>} Resolves with the slide list.
	 */
	Gallery.prototype.slides = function () {
		var self = this;
		var tag = this.activeTag;
		var spans = this.config.pagination && this.config.spanPages && this.config.pages > 1;

		if ( ! spans ) {
			return Promise.resolve( this.visibleLinks().map( slideFromLink ) );
		}

		// Cached per filter: the set the lightbox should page through is the matching set
		// for the active tag, which is not what the grid is showing when it is paginated.
		if ( this.slideCache[ tag ] ) {
			return Promise.resolve( this.slideCache[ tag ] );
		}

		return this.request( 'lichtbild_items', { tag: tag } ).then( function ( data ) {
			self.slideCache[ tag ] = ( data.items || [] ).map( slideFromRecord );

			return self.slideCache[ tag ];
		} ).catch( function () {
			// A failed fetch must not break the click; fall back to what is on screen.
			return self.visibleLinks().map( slideFromLink );
		} );
	};

	/**
	 * Opens the lightbox at the image belonging to a grid anchor.
	 *
	 * @param {HTMLAnchorElement} link Anchor that was activated.
	 *
	 * @return {void}
	 */
	Gallery.prototype.open = function ( link ) {
		var self = this;
		var wantedId = parseInt( link.getAttribute( 'data-lichtbild-item' ), 10 ) || 0;

		Promise.all( [ loadPhotoSwipe(), this.slides() ] ).then( function ( results ) {
			var PhotoSwipe = results[ 0 ];
			var slides = results[ 1 ];
			var index = -1;
			var i;

			for ( i = 0; i < slides.length; i++ ) {
				if ( slides[ i ].id && slides[ i ].id === wantedId ) {
					index = i;
					break;
				}
			}

			// A gallery or filter may have changed since this thumbnail was rendered.
			// Keep the click on its photograph instead of silently opening the first result.
			if ( index < 0 ) {
				slides = [ slideFromLink( link ) ];
				index = 0;
			}

			self.launch( PhotoSwipe, slides, index );
		} ).catch( function () {
			// The click was cancelled on the assumption that a lightbox would open. It did not,
			// so give the browser back what it would have done: follow the link to the image.
			// Silent failure is the one outcome a visitor cannot work around, and this is the
			// only path where the plugin has already taken the default action away.
			window.location.href = link.href;
		} );
	};

	/**
	 * Constructs and opens a PhotoSwipe instance.
	 *
	 * @param {Function} PhotoSwipe PhotoSwipe constructor.
	 * @param {Object[]} slides     Slide list.
	 * @param {number}   index      Zero-based index to open at.
	 *
	 * @return {void}
	 */
	Gallery.prototype.launch = function ( PhotoSwipe, slides, index ) {
		var self = this;
		var pswp = new PhotoSwipe( {
			dataSource: slides,
			index: index,
			bgOpacity: 0.94,
			showHideAnimationType: 'zoom',
			arrowKeys: false !== this.config.keyboard,
			returnFocus: true,
			closeTitle: i18n.close || 'Close',
			zoomTitle: i18n.zoom || 'Zoom',
			arrowPrevTitle: i18n.previous || 'Previous',
			arrowNextTitle: i18n.next || 'Next',
			// Match the thumbnail so the open animation zooms from the right place.
			thumbSelector: 'a.lichtbild-link'
		} );

		pswp.on( 'uiRegister', function () {
			self.registerInfo( pswp );

			if ( self.config.social && self.config.networks && self.config.networks.length ) {
				self.registerShare( pswp );
			}

			if ( self.config.download ) {
				self.registerDownload( pswp );
			}
		} );

		pswp.on( 'change', function () {
			self.writeHash( slides, pswp.currIndex );
		} );

		pswp.on( 'destroy', function () {
			self.clearHash();
		} );

		if ( 'dark' !== this.lightboxTheme() ) {
			pswp.on( 'firstUpdate', function () {
				pswp.element.classList.add( 'pswp--lichtbild-light' );
			} );
		}

		pswp.init();
		this.pswp = pswp;
	};

	/**
	 * Returns the lightbox colour scheme for this gallery.
	 *
	 * @return {string} Either `dark` or `light`.
	 */
	Gallery.prototype.lightboxTheme = function () {
		return this.root.classList.contains( 'lichtbild-theme-light' ) ? 'light' : 'dark';
	};

	/**
	 * Registers the title, caption and EXIF panel.
	 *
	 * @param {Object} pswp PhotoSwipe instance.
	 *
	 * @return {void}
	 */
	Gallery.prototype.registerInfo = function ( pswp ) {
		var self = this;

		pswp.ui.registerElement( {
			name: 'lichtbild-info',
			order: 9,
			isButton: false,
			appendTo: 'wrapper',
			onInit: function ( element ) {
				element.className = 'pswp__lichtbild-info';

				var render = function () {
					var slide = pswp.currSlide && pswp.currSlide.data ? pswp.currSlide.data : null;

					element.textContent = '';

					if ( ! slide ) {
						return;
					}

					if ( slide.title ) {
						var title = document.createElement( 'span' );
						title.className = 'pswp__lichtbild-title';
						title.textContent = slide.title;
						element.appendChild( title );
					}

					if ( slide.caption ) {
						var caption = document.createElement( 'span' );
						caption.className = 'pswp__lichtbild-caption';
						// Captions are authored in wp-admin and may carry inline markup,
						// which is how they render in the post content too.
						caption.innerHTML = slide.caption;
						element.appendChild( caption );
					}

					if ( self.config.exif && slide.exif && slide.exif.length ) {
						var list = document.createElement( 'ul' );
						list.className = 'pswp__lichtbild-exif';

						slide.exif.forEach( function ( field ) {
							var entry = document.createElement( 'li' );
							var label = document.createElement( 'b' );
							label.textContent = field.label;
							entry.appendChild( label );
							entry.appendChild( document.createTextNode( field.value ) );
							list.appendChild( entry );
						} );

						element.appendChild( list );
					}
				};

				pswp.on( 'change', render );
				render();
			}
		} );
	};

	/**
	 * Registers the share button and its menu.
	 *
	 * @param {Object} pswp PhotoSwipe instance.
	 *
	 * @return {void}
	 */
	Gallery.prototype.registerShare = function ( pswp ) {
		var self = this;

		pswp.ui.registerElement( {
			name: 'lichtbild-share',
			order: 8,
			isButton: true,
			html: '<svg aria-hidden="true" class="pswp__icn" viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M18 16.1c-.8 0-1.5.3-2 .8l-7.1-4.2c.1-.2.1-.5.1-.7s0-.5-.1-.7L16 7.1c.5.5 1.2.8 2 .8 1.7 0 3-1.3 3-3s-1.3-3-3-3-3 1.3-3 3c0 .2 0 .5.1.7L8 9.8c-.5-.5-1.2-.8-2-.8-1.7 0-3 1.3-3 3s1.3 3 3 3c.8 0 1.5-.3 2-.8l7.1 4.2c0 .2-.1.4-.1.7 0 1.7 1.3 3 3 3s3-1.3 3-3-1.3-3-3-3z"/></svg>',
			title: i18n.share || 'Share',
			appendTo: 'bar',
			onInit: function ( element ) {
				element.classList.add( 'pswp__lichtbild-share' );

				var menu = document.createElement( 'ul' );
				menu.className = 'pswp__lichtbild-share-menu';
				menu.hidden = true;
				element.appendChild( menu );

				var build = function () {
					var slide = pswp.currSlide && pswp.currSlide.data ? pswp.currSlide.data : null;

					menu.textContent = '';

					if ( ! slide ) {
						return;
					}

					var shareUrl = self.permalinkFor( pswp.options.dataSource, pswp.currIndex );

					self.config.networks.forEach( function ( network ) {
						var entry = document.createElement( 'li' );
						var anchor = document.createElement( 'a' );

						anchor.href = self.shareHref( network, shareUrl, slide );
						anchor.target = 'email' === network ? '_self' : '_blank';
						anchor.rel = 'noopener noreferrer';
						anchor.textContent = ( i18n.shareOn || 'Share on %s' ).replace(
							'%s',
							network.charAt( 0 ).toUpperCase() + network.slice( 1 )
						);

						entry.appendChild( anchor );
						menu.appendChild( entry );
					} );

					var copyEntry = document.createElement( 'li' );
					var copyButton = document.createElement( 'button' );
					copyButton.type = 'button';
					copyButton.textContent = i18n.copyLink || 'Copy link';
					copyButton.addEventListener( 'click', function ( event ) {
						event.stopPropagation();

						if ( navigator.clipboard ) {
							navigator.clipboard.writeText( shareUrl ).then( function () {
								copyButton.textContent = i18n.copied || 'Link copied';
							} );
						}
					} );

					copyEntry.appendChild( copyButton );
					menu.appendChild( copyEntry );
				};

				element.addEventListener( 'click', function ( event ) {
					if ( event.target.closest( '.pswp__lichtbild-share-menu' ) ) {
						return;
					}

					event.stopPropagation();
					build();
					menu.hidden = ! menu.hidden;
				} );

				pswp.on( 'change', function () {
					menu.hidden = true;
				} );
			}
		} );
	};

	/**
	 * Registers the download button.
	 *
	 * @param {Object} pswp PhotoSwipe instance.
	 *
	 * @return {void}
	 */
	Gallery.prototype.registerDownload = function ( pswp ) {
		pswp.ui.registerElement( {
			name: 'lichtbild-download',
			order: 7,
			isButton: true,
			tagName: 'a',
			html: '<svg aria-hidden="true" class="pswp__icn" viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M12 3v10.6l3.3-3.3 1.4 1.4-5.7 5.7-5.7-5.7 1.4-1.4 3.3 3.3V3h2zM5 19h14v2H5v-2z"/></svg>',
			title: i18n.download || 'Download',
			appendTo: 'bar',
			onInit: function ( element ) {
				var sync = function () {
					var slide = pswp.currSlide && pswp.currSlide.data ? pswp.currSlide.data : null;
					var href = slide && slide.download ? slide.download : ( slide ? slide.src : '' );

					element.href = href;
					element.download = '';
					element.hidden = ! href;
				};

				pswp.on( 'change', sync );
				sync();
			}
		} );
	};

	/**
	 * Builds the share URL for one network.
	 *
	 * @param {string} network Network slug.
	 * @param {string} url     Deep link to the image.
	 * @param {Object} slide   Slide record.
	 *
	 * @return {string} Share URL.
	 */
	Gallery.prototype.shareHref = function ( network, url, slide ) {
		var text = slide.title || document.title;

		switch ( network ) {
			case 'facebook':
				return 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent( url );
			case 'twitter':
				return 'https://twitter.com/intent/tweet?url=' + encodeURIComponent( url ) +
					'&text=' + encodeURIComponent( text );
			case 'pinterest':
				return 'https://pinterest.com/pin/create/button/?url=' + encodeURIComponent( url ) +
					'&media=' + encodeURIComponent( slide.src ) + '&description=' + encodeURIComponent( text );
			case 'email':
				return 'mailto:?subject=' + encodeURIComponent( text ) + '&body=' + encodeURIComponent( url );
			default:
				return url;
		}
	};

	/**
	 * Returns the shareable deep link for a slide.
	 *
	 * @param {Object[]} slides Slide list the index refers to.
	 * @param {number}   index  Zero-based slide index.
	 *
	 * @return {string} Absolute URL including the deep-link fragment.
	 */
	Gallery.prototype.permalinkFor = function ( slides, index ) {
		return window.location.origin + window.location.pathname + window.location.search +
			'#' + this.hashFor( slides, index );
	};

	/**
	 * Returns the fragment identifying one slide.
	 *
	 * The fragment names the image, not its position. A position is only meaningful
	 * alongside the filter and page it was taken under, so a shared link built from one
	 * would open a different photograph for anyone whose filter differed — including the
	 * same visitor after reloading, since the filter is not part of the URL either.
	 *
	 * @param {Object[]} slides Slide list the index refers to.
	 * @param {number}   index  Zero-based slide index.
	 *
	 * @return {string} Fragment without the leading hash.
	 */
	Gallery.prototype.hashFor = function ( slides, index ) {
		var slide = slides && slides[ index ] ? slides[ index ] : null;

		return 'lichtbild-' + this.id + '-i' + ( slide && slide.id ? slide.id : 0 );
	};

	/**
	 * Writes the deep link for the current slide into the address bar.
	 *
	 * `replaceState` rather than a hash assignment, so paging through fifty images does not
	 * leave fifty entries in the back history.
	 *
	 * @param {Object[]} slides Slide list currently open.
	 * @param {number}   index  Zero-based slide index.
	 *
	 * @return {void}
	 */
	Gallery.prototype.writeHash = function ( slides, index ) {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}

		window.history.replaceState( null, '', '#' + this.hashFor( slides, index ) );
	};

	/**
	 * Removes a deep link left in the address bar once the lightbox closes.
	 *
	 * @return {void}
	 */
	Gallery.prototype.clearHash = function () {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}

		var match = DEEP_LINK.exec( window.location.hash );

		if ( match && parseInt( match[ 1 ], 10 ) === this.id ) {
			window.history.replaceState(
				null,
				'',
				window.location.pathname + window.location.search
			);
		}
	};

	/**
	 * Returns every slide in the gallery, ignoring the active filter.
	 *
	 * A deep link arrives before any filter has been applied and may name an image on a page
	 * the grid has not rendered, so resolving it needs the unfiltered set rather than what
	 * happens to be on screen.
	 *
	 * @return {Promise<Object[]>} Resolves with the full slide list.
	 */
	Gallery.prototype.allSlides = function () {
		var self = this;

		if ( this.slideCache[ '' ] ) {
			return Promise.resolve( this.slideCache[ '' ] );
		}

		if ( ! this.config.pagination ) {
			return Promise.resolve( this.visibleLinks().map( slideFromLink ) );
		}

		return this.request( 'lichtbild_items', { tag: '' } ).then( function ( data ) {
			self.slideCache[ '' ] = ( data.items || [] ).map( slideFromRecord );

			return self.slideCache[ '' ];
		} ).catch( function () {
			return self.visibleLinks().map( slideFromLink );
		} );
	};

	/**
	 * Reads one localized string, falling back to nothing rather than to `undefined`.
	 *
	 * @param {string} key Key in the localized strings object.
	 *
	 * @return {string} The string, or an empty string.
	 */
	Gallery.prototype.strings = function ( key ) {
		// `i18n`, not `strings`: that is the key `wp_localize_script()` is given in
		// Lichtbild_Assets. Reading the wrong one is not an error in either language -- the
		// property is simply undefined, every lookup falls through to '', and the pagination
		// failure message was empty for as long as it existed. tests/frontend-js-test.js
		// asserts the two names against each other for that reason.
		var bag = window.LichtbildSettings && window.LichtbildSettings.i18n;

		return bag && bag[ key ] ? bag[ key ] : '';
	};

	/**
	 * Shows a message below the grid, and tells assistive technology about it.
	 *
	 * The pagination failure had a class for it and no message: `is-error` was added to the
	 * grid, matched no rule in either stylesheet, and the `loadFailed` string was handed to the
	 * browser and never read by anything. A visitor whose network blipped mid-pagination got no
	 * feedback at all -- the grid simply kept the page it already had.
	 *
	 * A live region rather than an alert, because the grid still shows correct content: this is
	 * information, not an interruption. `textContent`, because a translated string from the
	 * server has no business being parsed as markup.
	 *
	 * @param {string} message Message to show; an empty string removes it.
	 *
	 * @return {void}
	 */
	Gallery.prototype.announce = function ( message ) {
		var node = this.wrap.querySelector( '.lichtbild-message' );

		if ( ! message ) {
			if ( node && node.parentNode ) {
				node.parentNode.removeChild( node );
			}

			return;
		}

		if ( ! node ) {
			node = document.createElement( 'p' );
			node.className = 'lichtbild-message';
			node.setAttribute( 'role', 'status' );
			node.setAttribute( 'aria-live', 'polite' );
			this.wrap.appendChild( node );
		}

		node.textContent = message;
	};

	/**
	 * Opens the lightbox when the page was loaded with a deep link.
	 *
	 * @return {void}
	 */
	Gallery.prototype.restoreFromHash = function () {
		var match = DEEP_LINK.exec( window.location.hash );

		if ( ! match || parseInt( match[ 1 ], 10 ) !== this.id ) {
			return;
		}

		var self = this;
		var wantedId = parseInt( match[ 2 ], 10 );

		Promise.all( [ loadPhotoSwipe(), this.allSlides() ] ).then( function ( results ) {
			var slides = results[ 1 ];
			var i;

			for ( i = 0; i < slides.length; i++ ) {
				if ( slides[ i ].id === wantedId ) {
					self.launch( results[ 0 ], slides, i );

					return;
				}
			}
		} ).catch( function () {
			// Nothing to fall back to here -- no click was intercepted, the page is simply
			// loaded at a deep link. The grid is already rendered and the linked image is in
			// it, so leaving the page as it stands is the correct outcome; what matters is that
			// the rejection is consumed, and that `loadPhotoSwipe()` has cleared its cache so a
			// click afterwards can still succeed.
		} );
	};

	/**
	 * Wires the pagination controls.
	 *
	 * @return {void}
	 */
	Gallery.prototype.bindPagination = function () {
		var self = this;
		var slot = this.wrap.querySelector( '.lichtbild-pagination-slot' );

		if ( ! slot ) {
			return;
		}

		this.slot = slot;

		// The slot survives a page change while the nav inside it is replaced, so the
		// listener goes on the slot and keeps working without being rebound.
		slot.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button[data-lichtbild-page]' );

			if ( ! button || button.disabled ) {
				return;
			}

			var page = parseInt( button.getAttribute( 'data-lichtbild-page' ), 10 );

			if ( page >= 1 && page <= ( self.config.pages || 1 ) && page !== self.page ) {
				self.goToPage( page );
			}
		} );
	};

	/**
	 * Loads and displays one page of the gallery.
	 *
	 * @param {number} page One-based page number.
	 *
	 * @return {void}
	 */
	Gallery.prototype.goToPage = function ( page, options ) {
		var self = this;
		var settle = options || {};

		// Requests are not queued and are not cancelled; they are numbered. A second tag
		// click while the first is in flight must win, and dropping it — as an early return
		// on a pending flag does — leaves the bar showing one tag and the grid another.
		var ticket = ++this.sequence;
		var tag = undefined === settle.tag ? this.activeTag : settle.tag;

		this.pending = true;
		this.root.classList.add( 'is-loading' );

		this.request( 'lichtbild_page', { page: page, tag: tag } ).then( function ( data ) {
			if ( ticket !== self.sequence ) {
				return;
			}

			self.root.innerHTML = data.html;
			self.page = data.page;
			self.config.pages = data.pages;
			self.selectTag( tag );
			self.root.classList.remove( 'is-error' );

			// The server re-renders the nav because it is the side that knows how many
			// pages the current filter leaves; the client only swaps it in.
			if ( self.slot ) {
				self.slot.innerHTML = data.nav || '';
			}

			if ( false !== self.config.scroll && ! settle.silent ) {
				var top = self.wrap.getBoundingClientRect().top + window.pageYOffset - 40;

				window.scrollTo( {
					top: top,
					behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth'
				} );
			}
		} ).catch( function () {
			if ( ticket === self.sequence ) {
				self.root.classList.add( 'is-error' );
				self.announce( self.strings( 'loadFailed' ) );
			}
		} ).then( function () {
			if ( ticket !== self.sequence ) {
				return;
			}

			self.pending = false;
			self.root.classList.remove( 'is-loading' );

			if ( ! self.root.classList.contains( 'is-error' ) ) {
				self.announce( '' );
			}
		} );
	};

	/**
	 * Wires the tag filter bar.
	 *
	 * @return {void}
	 */
	Gallery.prototype.bindTags = function () {
		var self = this;
		var bar = this.wrap.querySelector( '.lichtbild-tags' );

		if ( ! bar ) {
			return;
		}

		bar.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button[data-lichtbild-tag]' );

			if ( ! button ) {
				return;
			}

			var slug = button.getAttribute( 'data-lichtbild-tag' ) || '';

			if ( slug === self.activeTag && ! self.pending ) {
				return;
			}

			if ( self.config.pagination ) {
				// A filter spans the whole gallery, so the matching set and its page count
				// both come from the server; filtering in the DOM could only ever see the
				// images already on this page.
				self.goToPage( 1, { silent: true, tag: slug } );
			} else {
				self.selectTag( slug );
				self.applyTagFilter();
			}
		} );
	};

	/**
	 * Commits the tag represented by the displayed grid and its toggle buttons.
	 *
	 * A pending or failed request still displays the previous grid, so it must keep that
	 * grid's tag for lightbox requests too. Only the latest successful response commits.
	 *
	 * @param {string} slug Tag slug, or an empty string for all images.
	 *
	 * @return {void}
	 */
	Gallery.prototype.selectTag = function ( slug ) {
		this.activeTag = slug;

		Array.prototype.forEach.call( this.wrap.querySelectorAll( '.lichtbild-tag' ), function ( button ) {
			var selected = ( button.getAttribute( 'data-lichtbild-tag' ) || '' ) === slug;

			button.classList.toggle( 'is-current', selected );
			button.setAttribute( 'aria-pressed', selected ? 'true' : 'false' );
		} );
	};

	/**
	 * Shows or hides grid items according to the active tag.
	 *
	 * @return {void}
	 */
	Gallery.prototype.applyTagFilter = function () {
		var active = this.activeTag;

		Array.prototype.forEach.call( this.root.querySelectorAll( '.lichtbild-item' ), function ( item ) {
			if ( '' === active ) {
				item.classList.remove( 'is-filtered' );
				return;
			}

			var tags = ( item.getAttribute( 'data-lichtbild-tags' ) || '' ).split( ' ' );

			item.classList.toggle( 'is-filtered', -1 === tags.indexOf( active ) );
		} );
	};

	/**
	 * Suppresses the context menu and drag saving on protected galleries.
	 *
	 * This is a deterrent, not a control — anything on screen can be captured — and it is
	 * applied only because the gallery asked for it.
	 *
	 * @return {void}
	 */
	Gallery.prototype.bindProtection = function () {
		if ( ! this.config.protection ) {
			return;
		}

		this.root.addEventListener( 'contextmenu', function ( event ) {
			if ( event.target.closest( '.lichtbild-item' ) ) {
				event.preventDefault();
			}
		} );

		this.root.addEventListener( 'dragstart', function ( event ) {
			if ( 'IMG' === event.target.tagName ) {
				event.preventDefault();
			}
		} );
	};

	/**
	 * Calls one of the plugin's AJAX endpoints.
	 *
	 * @param {string} action Action name.
	 * @param {Object} params Extra query parameters.
	 *
	 * @return {Promise<Object>} Resolves with the response payload.
	 */
	Gallery.prototype.request = function ( action, params ) {
		var query = new URLSearchParams();

		query.set( 'action', action );
		query.set( 'nonce', settings.nonce );
		query.set( 'gallery', this.id );

		Object.keys( params || {} ).forEach( function ( key ) {
			query.set( key, params[ key ] );
		} );

		return window.fetch( settings.ajaxUrl + '?' + query.toString(), {
			credentials: 'same-origin'
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}

			return response.json();
		} ).then( function ( payload ) {
			if ( ! payload || ! payload.success ) {
				throw new Error( 'Request failed' );
			}

			return payload.data;
		} );
	};

	/**
	 * Initialises every gallery on the page.
	 *
	 * @return {void}
	 */
	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.lichtbild' ), function ( root ) {
			if ( ! root.lichtbildGallery ) {
				root.lichtbildGallery = new Gallery( root );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.Lichtbild = { init: init };
}() );
