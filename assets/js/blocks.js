/**
 * The block editor's gallery and album blocks.
 *
 * Written against `wp.element.createElement` rather than JSX, for the same reason the rest of
 * this plugin's JavaScript is: there is no build step, and adding one to ship two pickers would
 * be the largest change in the repository for the smallest feature in it.
 *
 * NOTHING ABOUT THE BLOCKS IS DECLARED HERE EXCEPT THE EDITING EXPERIENCE.
 * =======================================================================
 *
 * Title, icon, category, keywords and attributes all live in each block's own `block.json` and
 * reach this file through WordPress's own server-side bootstrap — `register_block_type()` prints
 * the registry into the editor, and `registerBlockType()` merges it under the client settings. So
 * there is one declaration of what a Lichtbild block *is*, and the two halves cannot drift.
 *
 * The one thing that is deliberately not shared is `id`'s type. `block.json` says `number`; the
 * `SelectControl` below hands back a string, because a `<select>` value always is one. Every
 * write therefore goes through `parseInt`, and a block whose stored `id` is `"12"` rather than
 * `12` would be a block WordPress re-serialises differently on every save.
 *
 * WHAT THE CREATE FLOW WRITES INTO THE BLOCK, AND WHAT IT DOES NOT
 * ===============================================================
 *
 * "Create a gallery" makes a real draft gallery post on the server and then stores exactly one
 * thing in the block: its ID. The chosen attachments, the gallery's name and its settings all
 * live on the gallery, which is the only copy of them. Everything else the create flow knows —
 * the name typed into the field, whether a request is in flight, the confirmation shown after a
 * gallery is made — is component state and is deliberately not persisted: it describes this
 * editing session, not the gallery, and a block that remembered it would be a second, staler
 * account of a gallery that can be edited elsewhere.
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! data ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var strings = data.i18n || {};

	/**
	 * Builds the `edit` component for one of the two blocks.
	 *
	 * @param {Object} config Block name, picker choices and the strings for its states.
	 *
	 * @return {Function} The edit component.
	 */
	function makeEdit( config ) {
		return function ( props ) {
			// Every hook is called here, unconditionally and in one order, before any of the
			// early returns below. React identifies a hook by its call order, so a hook behind
			// a condition changes identity the moment the condition does.
			var blockProps = wp.blockEditor.useBlockProps();
			var nameState = useState( '' );
			var errorState = useState( '' );
			var busyState = useState( false );
			var createdState = useState( null );
			var choicesState = useState( config.choices );

			var id = parseInt( props.attributes.id, 10 ) || 0;
			var name = nameState[ 0 ];
			var error = errorState[ 0 ];
			var busy = busyState[ 0 ];
			var created = createdState[ 0 ];
			var choices = choicesState[ 0 ];

			/**
			 * The chooser. Built per call site rather than once: it appears either in the
			 * placeholder or in the sidebar, never in both at the same time, and a shared
			 * element object read as if it did would be a puzzle for whoever came next.
			 *
			 * @return {Object} A SelectControl element.
			 */
			function picker() {
				return el( wp.components.SelectControl, {
					label: config.chooseLabel,
					value: id,

					// A create request in flight ends by writing its own gallery's id into the
					// block. Left live, this chooser lets someone pick a different gallery
					// while it is in the air, and the late response then overwrites that choice
					// with nothing on screen saying it did — the block simply shows a gallery
					// nobody selected. Disabled for the seconds the request takes, which is the
					// only window in which the two writers can disagree.
					disabled: busy,
					options: [ { value: 0, label: strings.none } ].concat( choices ),
					onChange: function ( value ) {
						props.setAttributes( { id: parseInt( value, 10 ) || 0 } );
					},
					__nextHasNoMarginBottom: true
				} );
			}

			/**
			 * A message the person in the editor can act on.
			 *
			 * `Notice` rather than a paragraph, because it carries the live-region politeness
			 * that makes a message appearing after a click reach a screen reader at all.
			 *
			 * @param {string} status  Notice status, which decides that politeness.
			 * @param {string} text    The message.
			 * @param {Object} [extra] An element rendered after the message, or null.
			 *
			 * @return {Object} A Notice element.
			 */
			function notice( status, text, extra ) {
				return el(
					wp.components.Notice,
					{
						status: status,
						isDismissible: false,
						className: 'lichtbild-block-notice'
					},
					text,
					extra || null
				);
			}

			/**
			 * Posts the chosen attachments and adopts the gallery the server made.
			 *
			 * @param {number[]} ids Attachment IDs, in the order they were chosen.
			 */
			function submit( ids ) {
				if ( ! ids.length ) {
					errorState[ 1 ]( strings.chooseAtLeastOne );

					return;
				}

				var body = new window.FormData();

				body.append( 'action', data.createAction );
				body.append( 'nonce', data.createNonce );
				body.append( 'title', name );

				ids.forEach( function ( chosen ) {
					body.append( 'images[]', String( chosen ) );
				} );
				body.append( 'images_complete', '1' );

				busyState[ 1 ]( true );
				errorState[ 1 ]( '' );

				// `credentials: 'same-origin'` is what carries the login cookie, without which
				// the endpoint sees a logged-out request and refuses on the nonce.
				window.fetch( data.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} ).then( function ( response ) {
					return response.json();
				} ).then( function ( payload ) {
					adopt( payload );
				} ).catch( function () {
					busyState[ 1 ]( false );
					errorState[ 1 ]( strings.createFailed );
				} );
			}

			/**
			 * Reads the endpoint's answer.
			 *
			 * A refusal carries a message saying what to do about it — the migration is not
			 * run, the image is not usable — so that message is preferred over this file's
			 * generic one, which only means "something went wrong".
			 *
			 * @param {Object} payload The decoded JSON response.
			 */
			function adopt( payload ) {
				busyState[ 1 ]( false );

				var answer = payload && payload.data ? payload.data : null;
				var made = payload && payload.success && answer ? parseInt( answer.id, 10 ) || 0 : 0;

				if ( ! made ) {
					errorState[ 1 ]( ( answer && answer.message ) || strings.createFailed );

					return;
				}

				// The picker's choices were printed when the editor loaded, so they cannot
				// know about a gallery made since. Appending keeps the sidebar dropdown
				// honest for someone who creates one and then changes their mind.
				choicesState[ 1 ]( function ( current ) {
					return current.concat( [ {
						value: made,
						label: answer.title || String( made )
					} ] );
				} );

				createdState[ 1 ]( { id: made, editUrl: answer.editUrl || '' } );
				props.setAttributes( { id: made } );
			}

			/**
			 * Opens core's media frame and submits what was chosen.
			 */
			function openPicker() {
				errorState[ 1 ]( '' );

				// `wp.media` is enqueued for this screen by `Lichtbild_Block`, but a screen
				// that fires the same hook without a media library is a real state — saying so
				// is better than a button that does nothing when clicked.
				if ( ! wp.media ) {
					errorState[ 1 ]( strings.mediaUnavailable );

					return;
				}

				var frame = wp.media( {
					title: strings.chooseImages,
					button: { text: strings.useImages },
					library: { type: 'image' },
					multiple: 'add'
				} );

				frame.on( 'select', function () {
					var ids = [];

					frame.state().get( 'selection' ).each( function ( attachment ) {
						var chosen = parseInt( attachment.toJSON().id, 10 ) || 0;

						if ( chosen > 0 ) {
							ids.push( chosen );
						}
					} );

					submit( ids );
				} );

				frame.open();
			}

			/**
			 * The name field and the button that opens the media frame.
			 *
			 * @return {Object} The create controls.
			 */
			function creator() {
				return el(
					'div',
					{ className: 'lichtbild-block-create' },
					el( wp.components.TextControl, {
						label: strings.galleryName,
						value: name,
						disabled: busy,
						onChange: function ( value ) {
							nameState[ 1 ]( value );
						},
						__nextHasNoMarginBottom: true
					} ),
					el(
						wp.components.Button,
						{
							variant: 'primary',
							isBusy: busy,
							// `aria-disabled` rather than `disabled`, so the button keeps
							// keyboard focus while a request is in flight instead of dropping
							// it to the top of the document.
							'aria-disabled': busy,
							onClick: function () {
								if ( ! busy ) {
									openPicker();
								}
							}
						},
						busy ? strings.creating : strings.createGallery
					)
				);
			}

			if ( ! id ) {
				return el(
					'div',
					blockProps,
					el(
						wp.components.Placeholder,
						{
							label: config.title,
							instructions: config.canCreate ? config.createInstructions : config.instructions
						},
						error ? notice( 'error', error ) : null,
						config.canCreate ? creator() : null,

						// Why the button is absent, when there is a reason worth giving. On a
						// site that has not migrated this is the only thing on the screen that
						// says what to do next.
						! config.canCreate && config.createReason
							? el( 'p', { className: 'lichtbild-block-reason' }, config.createReason )
							: null,

						choices.length
							? el(
								'div',
								{ className: 'lichtbild-block-choose' },
								config.canCreate
									? el( 'p', { className: 'lichtbild-block-or' }, strings.chooseExisting )
									: null,
								picker()
							)
							: el( 'p', { className: 'lichtbild-block-none' }, config.noneMessage )
					)
				);
			}

			return el(
				'div',
				blockProps,
				el(
					wp.blockEditor.InspectorControls,
					null,
					el( wp.components.PanelBody, { title: config.panelTitle }, picker() )
				),

				// Only for a gallery this session just made. A draft is invisible to visitors,
				// and a preview that renders perfectly is exactly what that looks like from
				// inside the editor, where the author can read their own drafts.
				created && created.id === id
					? notice(
						'warning',
						strings.draftNotice,
						created.editUrl
							? el(
								wp.components.ExternalLink,
								{ href: created.editUrl, className: 'lichtbild-block-edit-link' },
								strings.editGallery
							)
							: null
					)
					: null,
				el(
					'div',
					{ className: 'lichtbild-block-preview' },
					el( wp.serverSideRender, {
						block: config.name,
						attributes: { id: id },

						// The server answers with an empty body for a gallery that exists but
						// may not be shown -- a draft, or one behind a password. Without this
						// the editor prints its own "Block rendered as empty", which is true
						// and says nothing about why.
						EmptyResponsePlaceholder: function () {
							return el( wp.components.Placeholder, {
								label: config.title,
								instructions: config.emptyMessage
							} );
						}
					} )
				)
			);
		};
	}

	/**
	 * Registers one block.
	 *
	 * @param {Object} config Block name, picker choices and strings.
	 * @param {string} tag    Shortcode this block can be converted from.
	 */
	function register( config, tag ) {
		wp.blocks.registerBlockType( config.name, {
			edit: makeEdit( config ),

			// Dynamic: the markup is produced by the render callback on every request, so
			// nothing is written into the post content but the block comment and its id.
			// That is what keeps a gallery edited on its own screen current in every post
			// that embeds it.
			save: function () {
				return null;
			},

			// Only Lichtbild's own shortcode transforms. `[envira-gallery]` deliberately does
			// not: it still renders under a rollback, because Envira registers it again, and
			// a one-click conversion with no confirmation is the wrong place to quietly spend
			// that. Someone who wants the block can pick it.
			transforms: {
				from: [
					{
						type: 'shortcode',
						tag: tag,
						attributes: {
							id: {
								type: 'number',
								shortcode: function ( attrs ) {
									return parseInt( attrs.named.id, 10 ) || 0;
								}
							}
						}
					}
				]
			}
		} );
	}

	register(
		{
			name: 'lichtbild/gallery',
			choices: data.galleries || [],
			title: strings.galleryTitle,
			chooseLabel: strings.chooseGallery,
			instructions: strings.galleryInstructions,
			createInstructions: strings.galleryCreateInstructions,
			panelTitle: strings.settings,
			noneMessage: strings.noGalleries,
			emptyMessage: strings.emptyGallery,
			canCreate: true === data.canCreate,
			createReason: data.createReason || ''
		},
		'lichtbild-gallery'
	);

	register(
		{
			name: 'lichtbild/album',
			choices: data.albums || [],
			title: strings.albumTitle,
			chooseLabel: strings.chooseAlbum,
			instructions: strings.albumInstructions,
			createInstructions: strings.albumInstructions,
			panelTitle: strings.albumSettings,
			noneMessage: strings.noAlbums,
			emptyMessage: strings.emptyAlbum,

			// An album is a list of galleries, so there is nothing to create it *from* until
			// galleries exist, and the album screen is where its covers and order are chosen
			// anyway. Offering "create" here would produce an empty album and a second screen
			// to finish it on.
			canCreate: false,
			createReason: ''
		},
		'lichtbild-album'
	);
} )( window.wp, window.LichtbildBlocks );
