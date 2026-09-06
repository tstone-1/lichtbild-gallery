<?php
/**
 * The album editor.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edits an album's member galleries and settings on the post edit screen.
 *
 * The last thing Envira could still do that Lichtbild could not. It arrives after
 * `Lichtbild_Album_Config`, and could not have arrived before it: until albums had a record of
 * their own, an album editor would have had to write `_eg_album_data` back — the one thing the
 * gallery side is built never to do.
 *
 * It is the gallery editor's twin and deliberately so, down to the nonce-presence guard and the
 * explicit order field. Two things differ, and both follow from what an album *is*:
 *
 * - **The picker is a list of galleries, not the media library.** Members are posts, so
 *   `wp.media` is the wrong instrument; a select of every gallery is the right one at this
 *   site's scale and costs no JavaScript beyond moving a row into a list.
 * - **A cover must be one of the member gallery's own images, and that is enforced here rather
 *   than only offered.** The chooser lists that gallery's images, but a chooser is markup and
 *   markup is a suggestion; `save()` reads the member gallery and drops a cover the gallery does
 *   not contain. Without that, an album could promise a photograph that clicking through never
 *   shows — and the renderer's fallback to the first image would quietly hide it, which is the
 *   failure mode that makes it worth enforcing rather than trusting.
 *
 * A member that does not resolve to a gallery is dropped for the same reason: an album is an
 * ordered set of galleries, and a row naming something else is not a member with a problem, it
 * is not a member.
 *
 * Everything the two editors do identically -- the guard chain, the ordered collect, the
 * column insert, the un-migrated notice -- is in `Lichtbild_Metabox_Editor` rather than written
 * out here a second time.
 */
class Lichtbild_Album_Editor extends Lichtbild_Metabox_Editor {

	/**
	 * Name of the nonce field, and the marker that identifies our own submission.
	 */
	const NONCE = 'lichtbild_album_editor_nonce';

	/**
	 * Nonce action, suffixed with the post ID.
	 */
	const NONCE_ACTION = 'lichtbild_album_editor_';

	/**
	 * Nonce action for the cover-list endpoint.
	 */
	const COVERS_NONCE_ACTION = 'lichtbild_album_covers';

	/**
	 * Returns the post type albums are stored under right now.
	 *
	 * @return string Post type name.
	 */
	protected function post_type() {
		return Lichtbild_Post_Types::album_type( $this->settings );
	}

	/**
	 * Hooks the edit screen.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save' ) );
		add_action( 'admin_notices', array( $this, 'render_save_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_lichtbild_album_covers', array( $this, 'handle_covers' ) );

		$type = $this->post_type();

		add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
	}

	/**
	 * Registers the metaboxes on the album edit screen.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		$type = $this->post_type();

		add_meta_box(
			'lichtbild-album-galleries',
			__( 'Galleries', 'lichtbild-gallery' ),
			array( $this, 'render_galleries_box' ),
			$type,
			'normal',
			'high'
		);

		add_meta_box(
			'lichtbild-album-settings',
			__( 'Album Settings', 'lichtbild-gallery' ),
			array( $this, 'render_settings_box' ),
			$type,
			'normal',
			'default'
		);

		add_meta_box(
			'lichtbild-album-shortcode',
			__( 'Shortcode', 'lichtbild-gallery' ),
			array( $this, 'render_shortcode_box' ),
			$type,
			'side',
			'default'
		);
	}

	/**
	 * Loads the stored record for an album, filled out to the current schema.
	 *
	 * @param int $post_id Album post ID.
	 *
	 * @return array{settings:array,items:array} The album's stored record.
	 */
	private function record( $post_id ) {
		$stored = get_post_meta( $post_id, Lichtbild_Repository::ALBUM_META_V2, true );

		$settings = is_array( $stored ) && isset( $stored['settings'] ) && is_array( $stored['settings'] )
			? $stored['settings']
			: array();

		$items = is_array( $stored ) && isset( $stored['items'] ) && is_array( $stored['items'] )
			? $stored['items']
			: array();

		return array(
			'settings' => Lichtbild_Album_Config::fill( $settings ),
			'items'    => array_values( array_filter( $items, 'is_array' ) ),
		);
	}

	/**
	 * Renders the member galleries metabox.
	 *
	 * @param WP_Post|object $post Album being edited.
	 *
	 * @return void
	 */
	public function render_galleries_box( $post ) {
		if ( ! $this->settings->has_migrated() ) {
			$this->render_unavailable(
				__( 'This album is still stored in Envira Gallery\'s format.', 'lichtbild-gallery' ),
				__( 'Lichtbild reads that format but never writes to it, so that switching between the two plugins stays lossless. Migrate to Lichtbild\'s own storage to edit albums here.', 'lichtbild-gallery' )
			);

			return;
		}

		$post_id = (int) $post->ID;
		$record  = $this->record( $post_id );

		wp_nonce_field( self::NONCE_ACTION . $post_id, self::NONCE );

		$order = array();

		echo '<div class="lichtbild-editor" id="lichtbild-album-editor">';
		echo '<p class="lichtbild-editor__actions">';
		echo '<label class="screen-reader-text" for="lichtbild-album-add">' .
			esc_html__( 'Gallery to add', 'lichtbild-gallery' ) . '</label>';

		$this->render_add_control();

		echo ' <button type="button" class="button button-primary" id="lichtbild-album-add-button">' .
			esc_html__( 'Add Gallery', 'lichtbild-gallery' ) . '</button>';
		echo '<span class="description">' .
			esc_html__( 'Drag to reorder. A cover must be one of the gallery\'s own images; left unset, its first image is used.', 'lichtbild-gallery' ) .
			'</span>';
		echo '</p>';

		echo '<ul class="lichtbild-editor__items" id="lichtbild-album-editor-items">';

		foreach ( $record['items'] as $index => $item ) {
			$key     = 'i' . (int) $index;
			$order[] = $key;

			$this->render_row( $key, $item );
		}

		echo '</ul>';

		echo '<p class="lichtbild-editor__empty" id="lichtbild-album-editor-empty"' .
			( empty( $record['items'] ) ? '' : ' style="display:none"' ) . '>' .
			esc_html__( 'This album has no galleries yet.', 'lichtbild-gallery' ) . '</p>';

		echo '<input type="hidden" name="lichtbild_album_order" id="lichtbild-album-editor-order" value="' .
			esc_attr( implode( ',', $order ) ) . '" />';

		echo '</div>';

		$this->complete_section( 'items' );
		$this->render_row_template();
	}

	/**
	 * Prints the select of galleries that can be added.
	 *
	 * The list comes from the repository, which is also where the block editor's picker gets it.
	 * That matters for one property in particular: Envira's defaults pseudo-gallery is renamed
	 * by the migration into an ordinary-looking row, and only the reader can tell it apart. The
	 * two pickers held their own copies of that rule for one release, which is one release longer
	 * than this project's own rule about a guard living in more than one place.
	 *
	 * Drafts and private galleries are offered as well as published ones: an album is edited over
	 * time and a gallery is often prepared before it is published. The renderer refuses to publish
	 * a member the visitor may not see, so listing one here cannot leak it.
	 *
	 * @return void
	 */
	private function render_add_control() {
		// `edit_post`, not the picker default: putting a gallery in an album republishes its
		// title and cover under that album, which is the same boundary `handle_covers()`
		// enforces on the AJAX side. `collect_items()` re-checks it, because a crafted POST
		// never sees this list.
		$galleries = $this->repository->gallery_choices( 'edit_post' );

		if ( empty( $galleries ) ) {
			echo '<span class="description">' . esc_html__( 'There are no galleries to add yet.', 'lichtbild-gallery' ) . '</span>';

			return;
		}

		echo '<select id="lichtbild-album-add">';

		foreach ( $galleries as $gallery_id => $title ) {
			echo '<option value="' . esc_attr( (string) $gallery_id ) . '">' . esc_html( $title ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Renders one member row.
	 *
	 * @param string $key  Row key, used to group the row's fields in the submission.
	 * @param array  $item Stored item record.
	 *
	 * @return void
	 */
	private function render_row( $key, array $item ) {
		$gallery_id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		$cover_id   = isset( $item['cover_id'] ) ? (int) $item['cover_id'] : 0;
		$caption    = isset( $item['caption'] ) ? (string) $item['caption'] : '';
		$name       = 'lichtbild_album_items[' . $key . ']';
		// A member stored before 26.8.25 can still name a gallery this person cannot edit, and
		// the save path dropping it is no help until the album is next saved. Resolving nothing
		// renders the row as an empty placeholder, which is what an unresolvable member already
		// looks like -- so the leak closes without inventing a new state for the screen.
		$gallery    = $gallery_id > 0 && current_user_can( 'edit_post', $gallery_id )
			? $this->repository->gallery( $gallery_id )
			: null;
		$title      = null === $gallery ? '' : $gallery->title();
		$thumb      = $this->cover_thumb( $gallery, $cover_id );
		$edit       = get_edit_post_link( $gallery_id );

		echo '<li class="lichtbild-editor__item" data-key="' . esc_attr( $key ) . '">';

		echo '<div class="lichtbild-editor__thumb">';

		if ( '' !== $thumb ) {
			echo '<img src="' . esc_url( $thumb ) . '" alt="" />';
		} else {
			echo '<span class="lichtbild-editor__missing">' . esc_html__( 'No cover', 'lichtbild-gallery' ) . '</span>';
		}

		echo '</div>';

		echo '<div class="lichtbild-editor__fields">';

		echo '<p class="lichtbild-editor__name">';

		if ( null === $gallery ) {
			// Not an error state to recover from silently: the row names something that is not a
			// gallery, so saving will drop it, and the screen says so rather than letting it
			// disappear without explanation.
			echo '<strong>' . esc_html(
				sprintf(
					/* translators: %d: post ID that is no longer a gallery. */
					__( 'Gallery %d is missing and will be removed when you save.', 'lichtbild-gallery' ),
					$gallery_id
				)
			) . '</strong>';
		} elseif ( $edit ) {
			echo '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo '<strong>' . esc_html( $title ) . '</strong>';
		}

		echo '</p>';

		echo '<label><span>' . esc_html__( 'Cover', 'lichtbild-gallery' ) . '</span>';
		echo '<select class="lichtbild-editor__cover" name="' . esc_attr( $name . '[cover_id]' ) . '">';
		$this->render_cover_options( $gallery, $cover_id );
		echo '</select></label>';

		echo '<label><span>' . esc_html__( 'Caption', 'lichtbild-gallery' ) . '</span>';
		echo '<input type="text" name="' . esc_attr( $name . '[caption]' ) . '" value="' . esc_attr( $caption ) . '" />';
		echo '</label>';

		echo '</div>';

		echo '<div class="lichtbild-editor__controls">';
		echo '<button type="button" class="button-link lichtbild-editor__remove">' . esc_html__( 'Remove', 'lichtbild-gallery' ) . '</button>';
		echo '</div>';

		echo '<input type="hidden" name="' . esc_attr( $name . '[id]' ) . '" value="' . esc_attr( (string) $gallery_id ) . '" />';

		echo '</li>';
	}

	/**
	 * Prints the options of one cover chooser.
	 *
	 * @param Lichtbild_Gallery|null $gallery  Member gallery, or null when it is missing.
	 * @param int                 $selected Currently chosen attachment ID.
	 *
	 * @return void
	 */
	private function render_cover_options( $gallery, $selected ) {
		echo '<option value="0"' . selected( 0, (int) $selected, false ) . '>' .
			esc_html__( 'First image in the gallery', 'lichtbild-gallery' ) . '</option>';

		foreach ( $this->cover_choices( $gallery ) as $choice ) {
			echo '<option value="' . esc_attr( (string) $choice['id'] ) . '"' .
				selected( (int) $choice['id'], (int) $selected, false ) . ' data-thumb="' . esc_attr( $choice['thumb'] ) . '">' .
				esc_html( $choice['label'] ) . '</option>';
		}
	}

	/**
	 * Returns the images of one gallery, as cover choices.
	 *
	 * @param Lichtbild_Gallery|null $gallery Member gallery, or null when it is missing.
	 *
	 * @return array<int,array{id:int,label:string,thumb:string}> Choices in gallery order.
	 */
	private function cover_choices( $gallery ) {
		if ( ! $gallery instanceof Lichtbild_Gallery ) {
			return array();
		}

		$items = $gallery->items();

		$gallery->prime( $items );

		$choices = array();

		foreach ( $items as $item ) {
			$title = $item->title();

			$choices[] = array(
				'id'    => $item->id(),
				'label' => '' !== $title
					? $title
					/* translators: %d: attachment ID. */
					: sprintf( __( 'Image %d', 'lichtbild-gallery' ), $item->id() ),
				'thumb' => $item->url( 'thumbnail' ),
			);
		}

		return $choices;
	}

	/**
	 * Returns the thumbnail URL shown beside a member row.
	 *
	 * @param Lichtbild_Gallery|null $gallery  Member gallery, or null when it is missing.
	 * @param int                 $cover_id Chosen attachment ID, or 0 for the gallery's first.
	 *
	 * @return string A URL, empty when there is nothing to show.
	 */
	private function cover_thumb( $gallery, $cover_id ) {
		if ( ! $gallery instanceof Lichtbild_Gallery ) {
			return '';
		}

		$items = $gallery->items();

		foreach ( $items as $item ) {
			if ( 0 === (int) $cover_id || $item->id() === (int) $cover_id ) {
				return $item->url( 'thumbnail' );
			}
		}

		return '';
	}

	/**
	 * Prints the client-side template rows added from the picker are built from.
	 *
	 * @return void
	 */
	private function render_row_template() {
		?>
		<script type="text/html" id="tmpl-lichtbild-album-editor-item">
			<li class="lichtbild-editor__item" data-key="{{ data.key }}">
				<div class="lichtbild-editor__thumb"><img src="{{ data.thumb }}" alt="" /></div>
				<div class="lichtbild-editor__fields">
					<p class="lichtbild-editor__name"><strong>{{ data.title }}</strong></p>
					<label><span><?php esc_html_e( 'Cover', 'lichtbild-gallery' ); ?></span>
						<select class="lichtbild-editor__cover" name="lichtbild_album_items[{{ data.key }}][cover_id]">
							<option value="0"><?php esc_html_e( 'First image in the gallery', 'lichtbild-gallery' ); ?></option>
						</select></label>
					<label><span><?php esc_html_e( 'Caption', 'lichtbild-gallery' ); ?></span>
						<input type="text" name="lichtbild_album_items[{{ data.key }}][caption]" value="" /></label>
				</div>
				<div class="lichtbild-editor__controls">
					<button type="button" class="button-link lichtbild-editor__remove"><?php esc_html_e( 'Remove', 'lichtbild-gallery' ); ?></button>
				</div>
				<input type="hidden" name="lichtbild_album_items[{{ data.key }}][id]" value="{{ data.id }}" />
			</li>
		</script>
		<?php
	}

	/**
	 * Renders the settings metabox.
	 *
	 * There is deliberately no title field. An album's title is the post's, which the editor
	 * above this box already edits; see `Lichtbild_Album_Config` for why the stored copy went.
	 *
	 * @param WP_Post|object $post Album being edited.
	 *
	 * @return void
	 */
	public function render_settings_box( $post ) {
		if ( ! $this->settings->has_migrated() ) {
			return;
		}

		$record   = $this->record( (int) $post->ID );
		$settings = $record['settings'];

		echo '<table class="form-table lichtbild-editor-settings" role="presentation">';

		echo '<tr><th scope="row"><label for="lichtbild-album-columns">' . esc_html__( 'Columns', 'lichtbild-gallery' ) .
			'</label></th><td>';
		echo '<input type="number" class="small-text" id="lichtbild-album-columns"' .
			' name="lichtbild_album_settings[columns]" value="' . esc_attr( (string) (int) $settings['columns'] ) . '"' .
			' min="1" max="12" step="1" />';
		echo ' <span class="description">' . esc_html__( 'How many covers sit side by side.', 'lichtbild-gallery' ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Under each cover', 'lichtbild-gallery' ) . '</th><td><fieldset>';
		echo '<label><input type="checkbox" name="lichtbild_album_settings[show_titles]" value="1"' .
			checked( ! empty( $settings['show_titles'] ), true, false ) . ' /> ' .
			esc_html__( 'Show the gallery title', 'lichtbild-gallery' ) . '</label><br />';
		echo '<label><input type="checkbox" name="lichtbild_album_settings[show_counts]" value="1"' .
			checked( ! empty( $settings['show_counts'] ), true, false ) . ' /> ' .
			esc_html__( 'Show how many images it holds', 'lichtbild-gallery' ) . '</label><br />';
		echo '</fieldset></td></tr>';

		echo '</table>';
		$this->complete_section( 'settings' );
	}

	/**
	 * Renders the shortcode metabox.
	 *
	 * @param WP_Post|object $post Album being edited.
	 *
	 * @return void
	 */
	public function render_shortcode_box( $post ) {
		$shortcode = '[lichtbild-album id="' . (int) $post->ID . '"]';

		echo '<p>' . esc_html__( 'Paste this into a post or page:', 'lichtbild-gallery' ) . '</p>';
		echo '<input type="text" class="large-text code" readonly value="' . esc_attr( $shortcode ) . '"' .
			' onfocus="this.select()" />';
		echo '<p class="description">' .
			esc_html__( 'The album also has its own page, which needs no shortcode at all.', 'lichtbild-gallery' ) .
			'</p>';
	}

	/**
	 * Saves a submitted album.
	 *
	 * @param int $post_id Post being saved.
	 *
	 * @return void
	 */
	public function save( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $this->authorised_save( $post_id ) ) {
			return;
		}

		/*
		 * Verified in `authorised_save()` one statement above, exactly as in
		 * `Lichtbild_Editor::save()`; the suppression below is about what a static analyser can
		 * see across a method boundary, not about the request. The arrays are unslashed here and
		 * sanitized per field afterwards, by `collect_entries()` and
		 * `Lichtbild_Album_Config::sanitize()`.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted = isset( $_POST['lichtbild_album_items'] ) && is_array( $_POST['lichtbild_album_items'] )
			? wp_unslash( $_POST['lichtbild_album_items'] )
			: array();

		$order = isset( $_POST['lichtbild_album_order'] )
			? sanitize_text_field( wp_unslash( $_POST['lichtbild_album_order'] ) )
			: '';

		$settings = isset( $_POST['lichtbild_album_settings'] ) && is_array( $_POST['lichtbild_album_settings'] )
			? wp_unslash( $_POST['lichtbild_album_settings'] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// `wp_slash()` because `update_post_meta()` unslashes what it is given — core's metadata
		// layer calls `wp_unslash()` on the value, which is right for the raw `$_POST` WordPress
		// normally hands it and wrong for the value we have already unslashed above. Without
		// this, a caption reading `C:\Photos` is stored as `C:Photos`: every backslash silently
		// eaten, once per save.
		update_post_meta(
			$post_id,
			Lichtbild_Repository::ALBUM_META_V2,
			wp_slash(
				array(
					'version'  => Lichtbild_Album_Config::VERSION,
					'settings' => Lichtbild_Album_Config::sanitize( $settings ),
					'items'    => $this->collect_items( $submitted, $order, $post_id ),
				)
			)
		);
	}

	/**
	 * Turns the submitted rows into stored items, in the submitted order.
	 *
	 * @param array  $submitted Rows keyed by row key.
	 * @param string $order     Comma-separated row keys, in display order.
	 * @param int    $post_id   Album post ID.
	 *
	 * @return array Item records.
	 */
	private function collect_items( array $submitted, $order, $post_id ) {
		$items = array();

		foreach ( $this->ordered_rows( $submitted, $order ) as $row ) {
			$gallery_id = isset( $row['id'] ) ? (int) $row['id'] : 0;

			// The save guard authorised the ALBUM. It says nothing about the galleries the
			// request names, and until 26.8.25 nothing else asked either: any id the repository
			// could resolve was stored, so somebody who could edit one album could POST another
			// author's private or draft gallery and have the editor render its title and cover
			// thumbnails back to them. `handle_covers()` had the rule right all along -- it
			// checks the album AND the gallery -- and this path simply did not share it.
			//
			// Before resolving the row, because `gallery()` reads the record of a post this
			// person may have no business knowing exists.
			if ( $gallery_id > 0 && ! current_user_can( 'edit_post', $gallery_id ) ) {
				continue;
			}

			$gallery = $gallery_id > 0 ? $this->repository->gallery( $gallery_id ) : null;

			// An album is an ordered set of galleries. A row naming a post that is not one is not
			// a member with a problem; it is not a member.
			if ( ! $gallery instanceof Lichtbild_Gallery ) {
				continue;
			}

			$items[] = array(
				'id'       => $gallery_id,
				'cover_id' => $this->clean_cover( $gallery, isset( $row['cover_id'] ) ? $row['cover_id'] : 0 ),
				'caption'  => sanitize_text_field( isset( $row['caption'] ) ? (string) $row['caption'] : '' ),
			);
		}

		/**
		 * Filters the members about to be stored for an album.
		 *
		 * @param array $items   Item records, in album order.
		 * @param int   $post_id Album post ID.
		 */
		return (array) apply_filters( 'lichtbild_album_editor_items', $items, $post_id );
	}

	/**
	 * Restricts a cover to an image the member gallery actually contains.
	 *
	 * The chooser only offers those, so this can only ever fire on a submission the editor did
	 * not produce — or on one produced before the gallery changed. Enforced rather than trusted
	 * because the failure is invisible: the renderer falls back to the gallery's first image
	 * when a cover cannot be resolved, so a cover pointing outside the gallery would look
	 * exactly like a cover nobody had chosen.
	 *
	 * @param Lichtbild_Gallery $gallery  Member gallery.
	 * @param mixed          $cover_id Submitted attachment ID.
	 *
	 * @return int The cover, or 0 when it is not one of the gallery's images.
	 */
	private function clean_cover( Lichtbild_Gallery $gallery, $cover_id ) {
		$cover_id = is_numeric( $cover_id ) ? (int) $cover_id : 0;

		if ( $cover_id <= 0 ) {
			return 0;
		}

		foreach ( $gallery->items() as $item ) {
			if ( $item->id() === $cover_id ) {
				return $cover_id;
			}
		}

		return 0;
	}

	/**
	 * Answers the cover chooser for a gallery added without reloading the screen.
	 *
	 * Admin-only, and authorised against **both** posts it touches. Checking only the album was
	 * the reviewable mistake: `current_user_can( 'edit_post', $id )` answers a question about one
	 * specific post, so permission to edit an album says nothing whatever about the gallery whose
	 * every image title and thumbnail this then returns. An author with one album of their own
	 * could name any gallery on the site — draft, private, or password-protected — and read its
	 * contents back.
	 *
	 * `is_viewable()` is deliberately *not* the predicate here, though it is the right one on
	 * every public path. This is an editing screen, and a draft gallery is a legitimate album
	 * member; refusing it would make the editor unable to do the thing it exists for. The
	 * question on an admin path is "may you edit this", asked once per post.
	 *
	 * The album ID is also checked to be an album. Without that, `edit_post` on any post at all —
	 * a page the user authored — would serve as the key.
	 *
	 * @return void
	 */
	public function handle_covers() {
		check_ajax_referer( self::COVERS_NONCE_ACTION, 'nonce' );

		$album_id   = isset( $_REQUEST['album'] ) ? (int) $_REQUEST['album'] : 0;
		$gallery_id = isset( $_REQUEST['gallery'] ) ? (int) $_REQUEST['gallery'] : 0;

		$allowed = $album_id > 0
			&& get_post_type( $album_id ) === Lichtbild_Post_Types::album_type( $this->settings )
			&& current_user_can( 'edit_post', $album_id )
			&& $gallery_id > 0
			&& current_user_can( 'edit_post', $gallery_id );

		if ( ! $allowed ) {
			wp_send_json_error( __( 'Not allowed.', 'lichtbild-gallery' ), 403 );

			return;
		}

		$gallery = $this->repository->gallery( $gallery_id );

		if ( ! $gallery instanceof Lichtbild_Gallery ) {
			wp_send_json_error( __( 'Gallery not found.', 'lichtbild-gallery' ), 404 );

			return;
		}

		wp_send_json_success(
			array(
				'title'  => $gallery->title(),
				'covers' => $this->cover_choices( $gallery ),
			)
		);
	}

	/**
	 * Enqueues the editor's assets on the album edit screen.
	 *
	 * @param string $hook Current admin page.
	 *
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! $this->editing_our_type( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'lichtbild-editor',
			LICHTBILD_URL . 'assets/css/editor.css',
			array(),
			LICHTBILD_VERSION
		);

		wp_enqueue_script(
			'lichtbild-album-editor',
			LICHTBILD_URL . 'assets/js/album-editor.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-util' ),
			LICHTBILD_VERSION,
			true
		);

		wp_localize_script(
			'lichtbild-album-editor',
			'LichtbildAlbumEditor',
			array(
				'nonce' => wp_create_nonce( self::COVERS_NONCE_ACTION ),
				'i18n'  => array(
					'firstImage' => __( 'First image in the gallery', 'lichtbild-gallery' ),
				),
			)
		);
	}

	/**
	 * Adds the gallery count and shortcode columns to the album list.
	 *
	 * @param array $columns Existing columns.
	 *
	 * @return array Columns with ours inserted before the date.
	 */
	public function columns( $columns ) {
		return $this->insert_columns(
			$columns,
			array(
				'lichtbild_galleries' => __( 'Galleries', 'lichtbild-gallery' ),
				'lichtbild_shortcode' => __( 'Shortcode', 'lichtbild-gallery' ),
			)
		);
	}

	/**
	 * Prints one of our list columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Album post ID.
	 *
	 * @return void
	 */
	public function column( $column, $post_id ) {
		if ( 'lichtbild_shortcode' === $column ) {
			echo '<code>[lichtbild-album id=&quot;' . (int) $post_id . '&quot;]</code>';

			return;
		}

		if ( 'lichtbild_galleries' !== $column ) {
			return;
		}

		// Through the repository rather than off the v2 meta, because which record is
		// authoritative is exactly the thing that changes at migration. Counting `_lichtbild_album`
		// directly reports 0 for every album on an un-migrated site -- where the real record is
		// still Envira's, and where the plugin has spent its whole life so far.
		$album = $this->repository->album( (int) $post_id );

		echo null === $album ? 0 : (int) $album->count();
	}
}
