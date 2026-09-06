<?php
/**
 * Block editor registration.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `lichtbild/gallery` and `lichtbild/album` blocks.
 *
 * **This is the fifth path that can put gallery content in front of a visitor, and it renders
 * none of it itself.** Both callbacks hand straight to `Lichtbild_Shortcode`, which already
 * consults `Lichtbild_Repository::is_viewable()`. That is deliberate and it is the whole design:
 * the four earlier paths each grew their own copy of the visibility rule, one of them forgot
 * it, and a protected gallery's cover was published on a public album page for a week. A block
 * that assembled its own repository and renderer would be the fifth copy of a rule that has
 * already been forgotten once.
 *
 * So there is exactly one thing to verify about this class, and the checks say it in those
 * terms: what it renders is byte-identical to what the shortcode renders, including when the
 * shortcode renders nothing.
 *
 * WHY THE PICKER IS PRINTED INTO THE PAGE RATHER THAN FETCHED
 * ==========================================================
 *
 * `Lichtbild_Post_Types` registers all three types with `show_in_rest => false`, because the
 * editors are metaboxes on the classic post screen and nothing else needs them over REST.
 * A block editor therefore cannot query them: `useEntityRecords( 'postType', 'lichtbild_gallery' )`
 * answers a 404, not an empty list. The choices are printed as an inline script instead, which
 * is the same shape `Lichtbild_Assets` already uses for the lightbox's strings.
 *
 * That is a constraint rather than a preference, and it is worth knowing before someone
 * "modernises" this into a fetch: turning `show_in_rest` on would expose every gallery record
 * on a new public surface to answer a question the editor screen already knows the answer to.
 *
 * WHY CREATING A GALLERY IS AN ADMIN-AJAX ENDPOINT AND NOT A SECOND REPRESENTATION
 * ==============================================================================
 *
 * The block can create a gallery, and it creates the *entity* — a real draft post carrying a
 * real `_lichtbild_gallery` record — then stores nothing but that post's ID. The alternative,
 * keeping the chosen attachment IDs in the block's own attributes and rendering from those,
 * would put a second writable representation of one gallery into every post that embeds it,
 * which is precisely the state the write-path review had to eliminate once already. A gallery
 * edited on its own screen has to stay current everywhere it is embedded, and that only holds
 * while there is one copy of it.
 *
 * The same `show_in_rest => false` above is why creation goes over `admin-ajax.php` rather
 * than over `/wp/v2/lichtbild_gallery`: the post type is deliberately not a REST resource, and
 * making it one to answer a single POST would open a read surface on every gallery record.
 * `Lichtbild_Ajax` and `Lichtbild_Album_Editor::handle_covers()` already establish the shape.
 *
 * Unlike the two front-end endpoints, this one **does refuse on its nonce**. Those two are
 * public reads served from pages a full-page cache may have generated days ago; this one is an
 * admin write, reached only from a block editor screen that is never cached, so a nonce here
 * carries the meaning it is supposed to carry. There is no `nopriv` registration for the same
 * reason: a logged-out visitor has no business creating a post.
 *
 * WHAT THE EDITOR PREVIEW DELIBERATELY DOES NOT LOAD
 * ==================================================
 *
 * The block's `editorStyle` pulls in the front-end stylesheet, so the preview is laid out
 * exactly as a visitor sees it. `lichtbild.js` is **not** loaded, so the preview has no lightbox
 * and no AJAX pagination. Both would be actively wrong inside an editor — a click that opens a
 * full-screen viewer over the post you are writing, or a pagination request that replaces the
 * preview's markup behind the editor's back. `blocks.css` also makes the preview inert, so a
 * click on a photograph cannot navigate the editor away to an image file.
 */
class Lichtbild_Block {

	/**
	 * Handle shared by the editor script and the editor stylesheet.
	 */
	const HANDLE = 'lichtbild-blocks';

	/**
	 * `admin-ajax.php` action the block posts a new gallery to.
	 *
	 * The nonce action is deliberately the same string: one name for one operation, so a nonce
	 * minted for this endpoint cannot be replayed against another.
	 */
	const CREATE_ACTION = 'lichtbild_create_gallery';

	/**
	 * The shortcode handler both render callbacks delegate to.
	 *
	 * @var Lichtbild_Shortcode
	 */
	private $shortcode;

	/**
	 * Reads the galleries and albums the picker offers.
	 *
	 * @var Lichtbild_Repository
	 */
	private $repository;

	/**
	 * Plugin settings, consulted for the post type to create in and whether it is safe to.
	 *
	 * @var Lichtbild_Settings
	 */
	private $settings;

	/**
	 * Builds the block registrar.
	 *
	 * Settings are required rather than optional, and the reason is worth stating because the
	 * optional form looked harmless. A default of `null` with a lazy `new Lichtbild_Settings()`
	 * behind it makes this class a second place that decides what it is made of: `Lichtbild`
	 * would hand one object to every other collaborator while this one quietly built its own,
	 * and the two would answer the same question — has this site migrated — from two separate
	 * reads. A caller that forgets the argument should fail at construction, where the mistake
	 * is, rather than work by accident.
	 *
	 * @param Lichtbild_Shortcode  $shortcode  Handler both blocks render through.
	 * @param Lichtbild_Repository $repository Reader behind the picker.
	 * @param Lichtbild_Settings   $settings   Plugin settings.
	 */
	public function __construct( Lichtbild_Shortcode $shortcode, Lichtbild_Repository $repository, Lichtbild_Settings $settings ) {
		$this->shortcode  = $shortcode;
		$this->repository = $repository;
		$this->settings   = $settings;
	}

	/**
	 * Hooks block registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_data' ) );

		// No `wp_ajax_nopriv_` twin. This one writes a post, so there is no reading of it that
		// makes sense for a logged-out visitor.
		add_action( 'wp_ajax_' . self::CREATE_ACTION, array( $this, 'handle_create' ) );
	}

	/**
	 * Registers the editor assets and both block types.
	 *
	 * @return void
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			self::HANDLE,
			LICHTBILD_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
			LICHTBILD_VERSION,
			true
		);

		// Depends on `lichtbild`, so the preview is laid out by the same stylesheet the visitor
		// gets. That handle is registered on `init` for exactly this reason — `wp_enqueue_scripts`
		// never fires in the admin, so a dependency registered there is silently dropped here and
		// the preview renders unstyled.
		wp_register_style(
			self::HANDLE,
			LICHTBILD_URL . 'assets/css/blocks.css',
			array( 'lichtbild' ),
			LICHTBILD_VERSION
		);

		register_block_type(
			LICHTBILD_DIR . 'blocks/gallery',
			array( 'render_callback' => array( $this, 'render_gallery' ) )
		);

		register_block_type(
			LICHTBILD_DIR . 'blocks/album',
			array( 'render_callback' => array( $this, 'render_album' ) )
		);
	}

	/**
	 * Prints the picker's choices and the editor's strings.
	 *
	 * **Separate from `register_blocks()` on purpose, and the reason is a measurement.** Building
	 * the choices reads every gallery row through the reader, which on the live site's cold cache
	 * is **111 queries and 11ms** — for data only the block editor ever looks at. Called from
	 * `register_blocks()`, which runs on `init`, that was 111 queries on every front-end page
	 * view, and it does not show up in any rendered byte. `enqueue_block_editor_assets` fires
	 * only when the block editor loads, so it is paid once by the one screen that reads it, and
	 * not on the plugins screen either.
	 *
	 * Attaching the data after the script has already been enqueued is fine: `wp_add_inline_script`
	 * appends to the registered handle, and the handle is printed later, in the footer.
	 *
	 * `before`, so `window.LichtbildBlocks` exists by the time the script body runs. The strings
	 * travel with it rather than through `wp_set_script_translations()`: that route needs a
	 * compiled JSON catalogue per script handle, generated by a build step this plugin
	 * deliberately does not have, and `tests/i18n-test.php` could not see into it.
	 *
	 * @return void
	 */
	public function enqueue_editor_data() {
		// The create flow picks images through `wp.media`, which is core's own frame and is
		// already loaded on the post editing screen. It is NOT loaded on every screen that
		// fires this hook — the site editor and the widgets screen also do — so it is asked
		// for here rather than assumed. `wp_enqueue_media()` returns immediately if it has
		// already run, so asking twice costs nothing.
		if ( function_exists( 'wp_enqueue_media' ) && $this->can_create() ) {
			wp_enqueue_media();
		}

		wp_add_inline_script(
			self::HANDLE,
			'window.LichtbildBlocks = ' . wp_json_encode( $this->editor_data() ) . ';',
			'before'
		);
	}

	/**
	 * Creates a draft gallery from a set of chosen attachments.
	 *
	 * Every refusal answers with a message the person in the editor can act on, because the
	 * only thing the block can do with a bare 403 is say that something went wrong.
	 *
	 * @return void
	 */
	public function handle_create() {
		check_ajax_referer( self::CREATE_ACTION, 'nonce' );

		$blocked = $this->create_blocked_message();

		if ( '' !== $blocked ) {
			wp_send_json_error( array( 'message' => $blocked ), 403 );

			return;
		}

		$ids = $this->requested_attachments();

		if ( null === $ids ) {
			wp_send_json_error(
				array( 'message' => __( 'One or more of the chosen images is not an image on this site, or is not yours to use.', 'lichtbild-gallery' ) ),
				400
			);

			return;
		}

		if ( empty( $ids ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Choose at least one image.', 'lichtbild-gallery' ) ),
				400
			);

			return;
		}

		$title = $this->requested_title();

		// `wp_slash()` on the way in, for the reason `Lichtbild_Editor::save()` gives: core's
		// post and metadata layers unslash what they are handed, which is right for the raw
		// `$_POST` they normally get and wrong for a value already unslashed above. Without it
		// a gallery called `C:\Photos` loses a backslash per save and never says so.
		$post_id = wp_insert_post(
			array(
				'post_type'   => Lichtbild_Post_Types::gallery_type( $this->settings ),
				'post_status' => 'draft',
				'post_title'  => wp_slash( $title ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || (int) $post_id <= 0 ) {
			wp_send_json_error(
				array( 'message' => __( 'The gallery could not be saved. Please try again.', 'lichtbild-gallery' ) ),
				500
			);

			return;
		}

		$post_id = (int) $post_id;

		// The same record shape `Lichtbild_Editor::save()` writes, built through the same
		// sanitiser — so a new gallery is indistinguishable from one made on the gallery
		// screen, and there is one definition of what an item record holds. An item carrying
		// only an attachment ID is complete: title, alt, dimensions and every URL are resolved
		// from the attachment at render time, which is what keeps them current.
		$stored = update_post_meta(
			$post_id,
			Lichtbild_Repository::GALLERY_META_V2,
			wp_slash(
				array(
					'version'  => Lichtbild_Config::VERSION,
					'settings' => Lichtbild_Config::defaults(),
					'items'    => $this->items( $ids ),
				)
			)
		);

		// A post that exists with no record is the one state this endpoint must not leave
		// behind, and it is worse than the insert having failed outright. `Lichtbild_Repository`
		// finds no v2 record, falls through to an Envira record this gallery has never had, and
		// answers nothing — so the editor adopts an ID that previews as empty, and the site
		// gains a draft nobody asked for and nobody can explain. Delete it and say the gallery
		// was not saved, which is what actually happened.
		//
		// Force-deleted rather than trashed: it was created by this request seconds ago, no
		// visitor and no other screen has ever seen it, so a trash entry would be litter
		// carrying no information. `false` is the only failure `update_post_meta()` reports —
		// it answers an integer meta ID on insert and `true` on update, and `0` is neither.
		if ( false === $stored ) {
			wp_delete_post( $post_id, true );

			wp_send_json_error(
				array( 'message' => __( 'The gallery could not be saved. Please try again.', 'lichtbild-gallery' ) ),
				500
			);

			return;
		}

		wp_send_json_success(
			array(
				'id'      => $post_id,
				'title'   => $title,
				'images'  => count( $ids ),
				'editUrl' => (string) get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	/**
	 * Turns attachment IDs into stored item records.
	 *
	 * @param int[] $ids Attachment IDs, in the order they were chosen.
	 *
	 * @return array Item records, in the same order.
	 */
	private function items( array $ids ) {
		$items = array();

		foreach ( $ids as $id ) {
			$record = Lichtbild_Item::sanitize_record( array( 'id' => $id ) );

			if ( null !== $record ) {
				$items[] = $record;
			}
		}

		return $items;
	}

	/**
	 * Reads the submitted gallery name.
	 *
	 * @return string A non-empty title.
	 */
	private function requested_title() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in `handle_create()`, this method's only caller, and the value is deliberately kept in its original type until the array guard below before the string is passed through `sanitize_text_field()`; a static analyser cannot follow either across these statements.
		$raw = isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '';

		// A form field is a string or it is absent, but `title[]=x` submits an array and
		// nothing stops a request carrying one. Cast, that becomes a gallery literally named
		// "Array"; read as unsubmitted, it becomes the default below.
		$title = is_string( $raw ) ? sanitize_text_field( $raw ) : '';

		return '' !== $title ? $title : __( 'Untitled gallery', 'lichtbild-gallery' );
	}

	/**
	 * Reads and validates the chosen attachments.
	 *
	 * **Refuses the whole request rather than dropping what it cannot use**, because dropping
	 * is silent: a gallery would be created with fewer images than were chosen, and nothing
	 * would say which ones or why. The media frame only offers what the current user can see,
	 * so in ordinary use this cannot fire — it is here for a request that did not come from
	 * the frame.
	 *
	 * The capability asked is `read_post` on each attachment, and it is worth saying why it is
	 * not `edit_post`. Creating a gallery writes nothing to the attachment; it records that the
	 * image is shown here. `Lichtbild_Editor::save_tags()` asks `edit_post` because that write
	 * *does* change the attachment, and changes it in every other gallery holding it. The
	 * question this endpoint has to answer is the picker's — may this person see that this
	 * image exists — and asking a stricter one would stop an author placing an image somebody
	 * else uploaded, which is the ordinary case on a site with more than one author.
	 *
	 * @return int[]|null Attachment IDs in the chosen order, or null when the request is not
	 *                    one this endpoint will act on.
	 */
	private function requested_attachments() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in `handle_create()`, this method's only caller; every value is cast to int and checked against a real attachment below, which is the sanitisation, and it cannot happen at the read because a non-scalar has to reach the `is_scalar()` test intact rather than be coerced first.
		$raw = isset( $_POST['images'] ) ? wp_unslash( $_POST['images'] ) : array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_create(), this method's only caller, verifies the nonce before this completion-marker check.
		if ( ! is_array( $raw ) || ! isset( $_POST['images_complete'] ) ) {
			return null;
		}

		$ids = array();

		foreach ( $raw as $value ) {
			if ( ! is_scalar( $value ) ) {
				return null;
			}

			$id = (int) $value;

			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				return null;
			}

			// An attachment is not necessarily an image. A PDF, an MP3 and a video are all
			// attachments on an ordinary site, and every one of them would be stored as a
			// perfectly valid item record that resolves to no dimensions, no srcset and a
			// lightbox slide with nothing in it — the `0x0` case `Lichtbild_Item` already has to
			// keep out of PhotoSwipe's zoom arithmetic, arriving through the front door.
			//
			// `wp_attachment_is_image()` is the same question the media frame's
			// `library: { type: 'image' }` filter answers on the client, asked again here
			// because a filter in a frame is markup, and markup is a suggestion.
			if ( ! wp_attachment_is_image( $id ) ) {
				return null;
			}

			if ( ! current_user_can( 'read_post', $id ) ) {
				return null;
			}

			// The same image twice is a legitimate gallery — `build_from_own()` stores an
			// ordered list precisely so that it can be — but a media frame cannot select one
			// twice, so a repeat here is a malformed request rather than an intention.
			if ( ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Reports whether the current user may create a gallery from a block.
	 *
	 * @return bool True when the create flow is available.
	 */
	private function can_create() {
		return '' === $this->create_blocked_message();
	}

	/**
	 * Explains why the create flow is unavailable, or returns an empty string when it is not.
	 *
	 * One method rather than a predicate and a separate message, so the reason shown in the
	 * editor and the reason the endpoint refuses on cannot say different things.
	 *
	 * @return string A message for the person in the editor, or '' when creation is allowed.
	 */
	private function create_blocked_message() {
		// The same rule `Lichtbild_Editor` enforces, seen from the other side: this writes a
		// v2 record, and a v2 record is authoritative only on a migrated site. Written any
		// earlier it would save perfectly and change nothing a visitor sees.
		if ( ! $this->settings->has_migrated() ) {
			return __( 'Galleries can be created here once this site is on Lichtbild storage. Run the migration under Settings > Lichtbild.', 'lichtbild-gallery' );
		}

		$type = get_post_type_object( Lichtbild_Post_Types::gallery_type( $this->settings ) );

		if ( ! is_object( $type ) || ! isset( $type->cap->create_posts ) ) {
			return __( 'Galleries cannot be created right now.', 'lichtbild-gallery' );
		}

		// Two capabilities because the flow does two things: it creates a post, and it reads
		// the media library to fill it. Someone who may do only one of those cannot finish.
		if ( ! current_user_can( $type->cap->create_posts ) || ! current_user_can( 'upload_files' ) ) {
			return __( 'You do not have permission to create galleries.', 'lichtbild-gallery' );
		}

		return '';
	}

	/**
	 * Renders the gallery block.
	 *
	 * @param array $attributes Block attributes.
	 *
	 * @return string HTML markup, empty when the gallery cannot be shown.
	 */
	public function render_gallery( $attributes ) {
		return $this->shortcode->gallery( array( 'id' => $this->reference( $attributes ) ) );
	}

	/**
	 * Renders the album block.
	 *
	 * @param array $attributes Block attributes.
	 *
	 * @return string HTML markup, empty when the album cannot be shown.
	 */
	public function render_album( $attributes ) {
		return $this->shortcode->album( array( 'id' => $this->reference( $attributes ) ) );
	}

	/**
	 * Reads the chosen post ID out of the block attributes.
	 *
	 * Returned as the empty string when nothing is chosen, which is the value the shortcode
	 * already treats as "no reference". A literal `'0'` is not that value — it would be looked
	 * up, miss, and reach the same answer by a longer route.
	 *
	 * @param mixed $attributes Block attributes, which are whatever the editor stored.
	 *
	 * @return string Post ID as a string, or an empty string.
	 */
	private function reference( $attributes ) {
		$id = is_array( $attributes ) && isset( $attributes['id'] ) ? (int) $attributes['id'] : 0;

		return $id > 0 ? (string) $id : '';
	}

	/**
	 * Builds the data the editor script needs.
	 *
	 * A nonce is minted here rather than in `register_blocks()` for the same reason the choices
	 * are: it is only ever read by a block editor screen, and a nonce is bound to the user and
	 * the session, so printing one on every front-end request would be both wasted and wrong.
	 *
	 * @return array Picker choices, the create endpoint's coordinates and the UI strings.
	 */
	private function editor_data() {
		$blocked = $this->create_blocked_message();

		return array(
			'galleries'    => $this->options( $this->repository->gallery_choices() ),
			'albums'       => $this->options( $this->repository->album_choices() ),
			'canCreate'    => '' === $blocked,
			'createReason' => $blocked,
			'createAction' => self::CREATE_ACTION,
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			// Only minted for someone who may actually use it: a nonce handed to a user the
			// endpoint will refuse anyway is a token with no purpose.
			'createNonce'  => '' === $blocked ? wp_create_nonce( self::CREATE_ACTION ) : '',
			'i18n'         => array(
				'galleryTitle'              => __( 'Lichtbild Gallery', 'lichtbild-gallery' ),
				'albumTitle'                => __( 'Lichtbild Album', 'lichtbild-gallery' ),
				'chooseGallery'             => __( 'Choose a gallery', 'lichtbild-gallery' ),
				'chooseAlbum'               => __( 'Choose an album', 'lichtbild-gallery' ),
				'galleryInstructions'       => __( 'Pick one of the galleries on this site. Edit its images and settings on the gallery itself, not here.', 'lichtbild-gallery' ),
				'galleryCreateInstructions' => __( 'Make a new gallery from images in your media library, or place one that already exists.', 'lichtbild-gallery' ),
				'albumInstructions'         => __( 'Pick one of the albums on this site. Edit its galleries and settings on the album itself, not here.', 'lichtbild-gallery' ),
				'settings'                  => __( 'Gallery', 'lichtbild-gallery' ),
				'albumSettings'             => __( 'Album', 'lichtbild-gallery' ),
				'none'                      => __( '— Select —', 'lichtbild-gallery' ),
				'noGalleries'               => __( 'This site has no galleries yet.', 'lichtbild-gallery' ),
				'noAlbums'                  => __( 'This site has no albums yet.', 'lichtbild-gallery' ),
				'emptyGallery'              => __( 'This gallery has nothing to show. It may be empty, a draft, or password-protected.', 'lichtbild-gallery' ),
				'emptyAlbum'                => __( 'This album has nothing to show. It may be empty, a draft, or password-protected.', 'lichtbild-gallery' ),
				'galleryName'               => __( 'Gallery name', 'lichtbild-gallery' ),
				'createGallery'             => __( 'Create a gallery', 'lichtbild-gallery' ),
				'chooseExisting'            => __( 'Or choose an existing gallery', 'lichtbild-gallery' ),
				'chooseImages'              => __( 'Choose images for this gallery', 'lichtbild-gallery' ),
				'useImages'                 => __( 'Add to gallery', 'lichtbild-gallery' ),
				'creating'                  => __( 'Creating the gallery…', 'lichtbild-gallery' ),
				'createFailed'              => __( 'The gallery could not be created. Please try again.', 'lichtbild-gallery' ),
				'chooseAtLeastOne'          => __( 'Choose at least one image.', 'lichtbild-gallery' ),
				'mediaUnavailable'          => __( 'The media library is not available on this screen, so the gallery has to be made from the Lichtbild menu.', 'lichtbild-gallery' ),
				'draftNotice'               => __( 'This gallery is a draft, so visitors will not see it until you publish it.', 'lichtbild-gallery' ),
				'editGallery'               => __( 'Edit this gallery', 'lichtbild-gallery' ),
			),
		);
	}

	/**
	 * Turns titles-keyed-by-id into the shape `SelectControl` wants.
	 *
	 * @param array<int,string> $choices Titles keyed by post ID.
	 *
	 * @return array<int,array{value:int,label:string}> Select options.
	 */
	private function options( array $choices ) {
		$out = array();

		foreach ( $choices as $id => $title ) {
			$out[] = array(
				'value' => (int) $id,
				'label' => (string) $title,
			);
		}

		return $out;
	}
}
