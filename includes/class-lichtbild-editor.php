<?php
/**
 * The gallery editor.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edits a gallery's images and settings on the post edit screen.
 *
 * This is the last thing that kept Envira installed. Everything else about a gallery —
 * reading it, rendering it, owning its URLs, owning its rows — had moved across, but the
 * only way to change one was still Envira's own screen.
 *
 * **The editor writes v2 records, so it requires the migration.** That is not a sequencing
 * accident, it is the same rule the repository enforces: a v2 record is authoritative only
 * on a migrated site, so an editor that wrote one before the migration would save happily
 * and change nothing a visitor sees. Rather than have two writable representations of one
 * gallery — the state the write-path review already had to eliminate once — the edit screen
 * on an un-migrated site says what to do instead of pretending to work.
 *
 * Three properties of the save path are worth knowing before changing it:
 *
 * - **An absent nonce field means this is not our form, and the only safe response is to do
 *   nothing.** `save_post` fires for quick edit, bulk edit, status changes and REST writes,
 *   none of which carry the images. Treating a missing `lichtbild_items` as "the user removed
 *   every image" would empty a gallery on a quick-edit of its title.
 * - **Order is submitted explicitly, not inferred.** The browser serialises fields in DOM
 *   order and PHP preserves it, so the order would usually be right — but "usually" is the
 *   wrong standard for the one property a drag-and-drop editor exists to set. `lichtbild_order`
 *   names the rows in the order they are to be stored, and rows it does not name are dropped.
 * - **Image tags are attachment terms, not gallery data.** Editing them here changes them
 *   everywhere that image appears, which is what makes the tag filter consistent across
 *   galleries, and is stated on the screen so it is not a surprise.
 *
 * The guard chain, the ordered collect, the list-table column insert and the un-migrated
 * notice live in `Lichtbild_Metabox_Editor`, shared with the album editor: they were identical in
 * both twins, and what is written twice is what stops being fixed twice.
 */
class Lichtbild_Editor extends Lichtbild_Metabox_Editor {

	/**
	 * Name of the nonce field, and the marker that identifies our own submission.
	 */
	const NONCE = 'lichtbild_editor_nonce';

	/**
	 * Nonce action, suffixed with the post ID.
	 */
	const NONCE_ACTION = 'lichtbild_editor_';

	/**
	 * Returns the post type galleries are stored under right now.
	 *
	 * @return string Post type name.
	 */
	protected function post_type() {
		return Lichtbild_Post_Types::gallery_type( $this->settings );
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

		$type = $this->post_type();

		add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'attachment_tags' ), 10, 2 );
	}

	/**
	 * Adds an image's tags to what the media library hands the browser.
	 *
	 * Without this the editor has no way to know the tags of an image being added, so the row
	 * it creates would carry an empty tag field — and saving would then clear the tags that
	 * image already had, in every gallery it appears in. A blank field that means "unchanged"
	 * and a blank field that means "remove them all" are indistinguishable at the server, so
	 * the fix has to be that the field is never blank by accident.
	 *
	 * Gated on the media frame having been opened from one of our galleries, because this
	 * costs a term lookup per attachment and the media library is used all over the admin.
	 *
	 * @param array          $response   Attachment data being sent to the browser.
	 * @param WP_Post|object $attachment The attachment.
	 *
	 * @return array Attachment data, with `lichtbildTags` when it applies.
	 */
	public function attachment_tags( $response, $attachment ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read on a media-library response, gated below on the frame having been opened from one of our own galleries; nothing is written and `(int)` is the sanitisation. The nonce for this request is WordPress's own, already verified by core before the filter runs.
		if ( ! $this->settings->has_migrated() || ! isset( $_REQUEST['post_id'] ) ) {
			return $response;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$parent = (int) $_REQUEST['post_id'];

		if ( $parent <= 0 || get_post_type( $parent ) !== $this->post_type() ) {
			return $response;
		}

		$response['lichtbildTags'] = implode( ', ', $this->tag_names( (int) $attachment->ID ) );

		return $response;
	}

	/**
	 * Registers the metaboxes on the gallery edit screen.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		$type = $this->post_type();

		add_meta_box(
			'lichtbild-images',
			__( 'Images', 'lichtbild-gallery' ),
			array( $this, 'render_images_box' ),
			$type,
			'normal',
			'high'
		);

		add_meta_box(
			'lichtbild-settings',
			__( 'Gallery Settings', 'lichtbild-gallery' ),
			array( $this, 'render_settings_box' ),
			$type,
			'normal',
			'default'
		);

		add_meta_box(
			'lichtbild-shortcode',
			__( 'Shortcode', 'lichtbild-gallery' ),
			array( $this, 'render_shortcode_box' ),
			$type,
			'side',
			'default'
		);
	}

	/**
	 * Loads the stored record for a gallery, filled out to the current schema.
	 *
	 * @param int $post_id Gallery post ID.
	 *
	 * @return array{settings:array,items:array} The gallery's stored record.
	 */
	private function record( $post_id ) {
		$stored = get_post_meta( $post_id, Lichtbild_Repository::GALLERY_META_V2, true );

		$settings = is_array( $stored ) && isset( $stored['settings'] ) && is_array( $stored['settings'] )
			? $stored['settings']
			: array();

		$items = is_array( $stored ) && isset( $stored['items'] ) && is_array( $stored['items'] )
			? $stored['items']
			: array();

		return array(
			'settings' => Lichtbild_Config::fill( $settings ),
			'items'    => array_values( array_filter( $items, 'is_array' ) ),
		);
	}

	/**
	 * Renders the images metabox.
	 *
	 * @param WP_Post|object $post Gallery being edited.
	 *
	 * @return void
	 */
	public function render_images_box( $post ) {
		if ( ! $this->settings->has_migrated() ) {
			$this->render_unavailable(
				__( 'This gallery is still stored in Envira Gallery\'s format.', 'lichtbild-gallery' ),
				__( 'Lichtbild reads that format but never writes to it, so that switching between the two plugins stays lossless. Migrate the galleries to Lichtbild\'s own storage to edit them here.', 'lichtbild-gallery' )
			);

			return;
		}

		$post_id = (int) $post->ID;
		$record  = $this->record( $post_id );

		wp_nonce_field( self::NONCE_ACTION . $post_id, self::NONCE );

		// Every row below reads the attachment's row, its meta and its terms -- three queries per
		// image unprimed, which on a 504-image gallery is the same fifteen hundred the lightbox
		// endpoint used to cost. This screen walks every item of the gallery in one request, and
		// it was the last reader left without the priming the album editor's twin already does.
		$this->prime_items( $record['items'] );

		$order = array();

		echo '<div class="lichtbild-editor" id="lichtbild-editor">';
		echo '<p class="lichtbild-editor__actions">';
		echo '<button type="button" class="button button-primary" id="lichtbild-add-images">' .
			esc_html__( 'Add Images', 'lichtbild-gallery' ) . '</button> ';
		echo '<span class="description">' .
			esc_html__( 'Drag to reorder. Titles, captions and alt text are stored on the gallery; tags are stored on the image itself and are shared by every gallery it appears in.', 'lichtbild-gallery' ) .
			'</span>';

		// Printed always and hidden when it does not apply, rather than printed conditionally,
		// so the editor's script can show it the moment the box is ticked instead of after a
		// save. The state it reflects is the one on screen, which is what the person typing
		// into these fields needs to know.
		//
		// It says "only where the Media Library is empty" rather than "not shown", because that
		// is what the reader does: a blank title, caption or alt text there falls back to the
		// row below. A note claiming the rows are dead would be wrong for exactly the images
		// whose row is still doing the work.
		echo ' <span class="description" id="lichtbild-editor-live-note"' .
			( empty( $record['settings']['live_metadata'] ) ? ' style="display:none"' : '' ) . '>' .
			esc_html__( 'This gallery takes titles, captions and alt text from the Media Library. What you type below is saved either way, and shown only where the Media Library field is empty.', 'lichtbild-gallery' ) .
			'</span>';
		echo '</p>';

		echo '<ul class="lichtbild-editor__items" id="lichtbild-editor-items">';

		foreach ( $record['items'] as $index => $item ) {
			$key     = 'i' . (int) $index;
			$order[] = $key;

			$this->render_row( $key, $item );
		}

		echo '</ul>';

		echo '<p class="lichtbild-editor__empty" id="lichtbild-editor-empty"' .
			( empty( $record['items'] ) ? '' : ' style="display:none"' ) . '>' .
			esc_html__( 'This gallery has no images yet.', 'lichtbild-gallery' ) . '</p>';

		echo '<input type="hidden" name="lichtbild_order" id="lichtbild-editor-order" value="' .
			esc_attr( implode( ',', $order ) ) . '" />';

		echo '</div>';

		$this->complete_section( 'items' );
		$this->render_row_template();
	}

	/**
	 * Primes the post, meta and term caches for a set of stored item records.
	 *
	 * The editor holds raw records rather than `Lichtbild_Item` objects, so it cannot hand them
	 * to `Lichtbild_Gallery::prime()`; the call and the guard are the same as there.
	 *
	 * @param array $items Stored item records.
	 *
	 * @return int Number of attachments primed.
	 */
	private function prime_items( array $items ) {
		$ids = array();

		foreach ( $items as $item ) {
			$id = isset( $item['id'] ) ? (int) $item['id'] : 0;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids = array_values( $ids );

		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, true, true );
		}

		return count( $ids );
	}

	/**
	 * Renders one image row.
	 *
	 * @param string $key  Row key, used to group the row's fields in the submission.
	 * @param array  $item Stored item record.
	 *
	 * @return void
	 */
	private function render_row( $key, array $item ) {
		$id     = isset( $item['id'] ) ? (int) $item['id'] : 0;
		$name   = 'lichtbild_items[' . $key . ']';
		$thumb  = $id > 0 ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		$thumb  = is_string( $thumb ) && '' !== $thumb ? $thumb : (string) ( isset( $item['src'] ) ? $item['src'] : '' );
		$tags   = $this->tag_names( $id );
		$fields = array(
			'title'   => __( 'Title', 'lichtbild-gallery' ),
			'caption' => __( 'Caption', 'lichtbild-gallery' ),
			'alt'     => __( 'Alt text', 'lichtbild-gallery' ),
		);

		echo '<li class="lichtbild-editor__item" data-key="' . esc_attr( $key ) . '">';

		echo '<div class="lichtbild-editor__thumb">';

		if ( '' !== $thumb ) {
			echo '<img src="' . esc_url( $thumb ) . '" alt="" />';
		} else {
			echo '<span class="lichtbild-editor__missing">' . esc_html__( 'Image missing', 'lichtbild-gallery' ) . '</span>';
		}

		echo '</div>';

		echo '<div class="lichtbild-editor__fields">';

		foreach ( $fields as $field => $label ) {
			$value = isset( $item[ $field ] ) ? (string) $item[ $field ] : '';

			echo '<label><span>' . esc_html( $label ) . '</span>';
			echo '<input type="text" name="' . esc_attr( $name . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '" />';
			echo '</label>';
		}

		echo '<label><span>' . esc_html__( 'Tags', 'lichtbild-gallery' ) . '</span>';
		echo '<input type="text" class="lichtbild-editor__tags" name="' . esc_attr( $name . '[tags]' ) . '" value="' . esc_attr( implode( ', ', $tags ) ) . '" />';
		echo '</label>';

		echo '</div>';

		echo '<div class="lichtbild-editor__controls">';
		echo '<button type="button" class="button-link lichtbild-editor__remove">' . esc_html__( 'Remove', 'lichtbild-gallery' ) . '</button>';
		echo '</div>';

		echo '<input type="hidden" name="' . esc_attr( $name . '[id]' ) . '" value="' . esc_attr( (string) $id ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( $name . '[src]' ) . '" value="' . esc_attr( isset( $item['src'] ) ? (string) $item['src'] : '' ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( $name . '[link]' ) . '" value="' . esc_attr( isset( $item['link'] ) ? (string) $item['link'] : '' ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( $name . '[status]' ) . '" value="' . esc_attr( isset( $item['status'] ) ? (string) $item['status'] : 'active' ) . '" />';

		echo '</li>';
	}

	/**
	 * Prints the client-side template rows added from the media library are built from.
	 *
	 * It mirrors `render_row()` deliberately rather than sharing markup with it: the server
	 * template needs escaped PHP values, the client one needs `{{ }}` placeholders, and the
	 * two have different escaping rules. The suite checks that both carry a field for every
	 * key an item record has, which is the property that would otherwise drift.
	 *
	 * @return void
	 */
	private function render_row_template() {
		?>
		<script type="text/html" id="tmpl-lichtbild-editor-item">
			<li class="lichtbild-editor__item" data-key="{{ data.key }}">
				<div class="lichtbild-editor__thumb"><img src="{{ data.thumb }}" alt="" /></div>
				<div class="lichtbild-editor__fields">
					<label><span><?php esc_html_e( 'Title', 'lichtbild-gallery' ); ?></span>
						<input type="text" name="lichtbild_items[{{ data.key }}][title]" value="{{ data.title }}" /></label>
					<label><span><?php esc_html_e( 'Caption', 'lichtbild-gallery' ); ?></span>
						<input type="text" name="lichtbild_items[{{ data.key }}][caption]" value="{{ data.caption }}" /></label>
					<label><span><?php esc_html_e( 'Alt text', 'lichtbild-gallery' ); ?></span>
						<input type="text" name="lichtbild_items[{{ data.key }}][alt]" value="{{ data.alt }}" /></label>
					<label><span><?php esc_html_e( 'Tags', 'lichtbild-gallery' ); ?></span>
						<input type="text" class="lichtbild-editor__tags" name="lichtbild_items[{{ data.key }}][tags]" value="{{ data.tags }}" /></label>
				</div>
				<div class="lichtbild-editor__controls">
					<button type="button" class="button-link lichtbild-editor__remove"><?php esc_html_e( 'Remove', 'lichtbild-gallery' ); ?></button>
				</div>
				<input type="hidden" name="lichtbild_items[{{ data.key }}][id]" value="{{ data.id }}" />
				<input type="hidden" name="lichtbild_items[{{ data.key }}][src]" value="{{ data.src }}" />
				<input type="hidden" name="lichtbild_items[{{ data.key }}][link]" value="{{ data.link }}" />
				<input type="hidden" name="lichtbild_items[{{ data.key }}][status]" value="active" />
			</li>
		</script>
		<?php
	}

	/**
	 * Returns the tag names assigned to an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return string[] Tag names.
	 */
	private function tag_names( $attachment_id ) {
		if ( $attachment_id <= 0 ) {
			return array();
		}

		$terms = get_the_terms( $attachment_id, Lichtbild_Post_Types::tag_taxonomy( $this->settings ) );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$names = array();

		foreach ( $terms as $term ) {
			$names[] = $term->name;
		}

		return $names;
	}

	/**
	 * Renders the settings metabox.
	 *
	 * @param WP_Post|object $post Gallery being edited.
	 *
	 * @return void
	 */
	public function render_settings_box( $post ) {
		if ( ! $this->settings->has_migrated() ) {
			return;
		}

		$record   = $this->record( (int) $post->ID );
		$settings = $record['settings'];
		$sizes    = Lichtbild_Config::image_sizes();

		echo '<table class="form-table lichtbild-editor-settings" role="presentation">';

		$this->row_choice(
			$settings,
			'layout',
			__( 'Layout', 'lichtbild-gallery' ),
			array(
				'justified' => __( 'Justified rows — images keep their aspect ratio', 'lichtbild-gallery' ),
				'columns'   => __( 'Fixed columns', 'lichtbild-gallery' ),
			)
		);

		$this->row_number( $settings, 'columns', __( 'Columns', 'lichtbild-gallery' ), 1, 12, __( 'Used by the fixed-column layout.', 'lichtbild-gallery' ) );
		$this->row_number( $settings, 'row_height', __( 'Row height', 'lichtbild-gallery' ), 40, 800, __( 'Target height in pixels for justified rows.', 'lichtbild-gallery' ) );
		$this->row_number( $settings, 'gutter', __( 'Gutter', 'lichtbild-gallery' ), 0, 100, __( 'Space between images, in pixels.', 'lichtbild-gallery' ) );

		$this->row_choice( $settings, 'image_size', __( 'Grid image size', 'lichtbild-gallery' ), $this->size_labels( $sizes ) );
		$this->row_choice( $settings, 'lightbox_size', __( 'Lightbox image size', 'lichtbild-gallery' ), $this->size_labels( $sizes ) );

		$this->row_choice(
			$settings,
			'title_display',
			__( 'Show titles', 'lichtbild-gallery' ),
			array(
				'none'  => __( 'Not at all', 'lichtbild-gallery' ),
				'float' => __( 'On hover, over the image', 'lichtbild-gallery' ),
				'below' => __( 'Below the image', 'lichtbild-gallery' ),
			)
		);

		// Stated as a consequence rather than as a feature, because the consequence is what an
		// owner has to weigh: one place to fix a typo, against a caption that changes on a page
		// they were not editing. The last sentence answers the question the wording otherwise
		// invites — nothing about this writes to the media library, in either position.
		$this->row_flags(
			$settings,
			__( 'Image metadata', 'lichtbild-gallery' ),
			array(
				'live_metadata' => __( 'Take titles, captions and alt text from the Media Library', 'lichtbild-gallery' ),
			),
			__( 'Off, this gallery shows the title, caption and alt text saved on its own rows below. On, it shows what the Media Library holds for each image today — so editing an image there changes it in every gallery at once. A field the Media Library leaves empty falls back to the row below, which is why alt text never disappears when you switch this on. Lichtbild never writes to the Media Library either way.', 'lichtbild-gallery' )
		);

		$this->row_flags(
			$settings,
			__( 'Behaviour', 'lichtbild-gallery' ),
			array(
				'lazy_loading' => __( 'Load images lazily', 'lichtbild-gallery' ),
				'protection'   => __( 'Discourage right-click saving', 'lichtbild-gallery' ),
				'keyboard'     => __( 'Keyboard navigation in the lightbox', 'lichtbild-gallery' ),
				'download'     => __( 'Offer a download button in the lightbox', 'lichtbild-gallery' ),
			)
		);

		$this->row_choice(
			$settings,
			'lightbox_theme',
			__( 'Lightbox theme', 'lichtbild-gallery' ),
			array(
				'dark'  => __( 'Dark', 'lichtbild-gallery' ),
				'light' => __( 'Light', 'lichtbild-gallery' ),
			)
		);

		$this->row_flags(
			$settings,
			__( 'Pagination', 'lichtbild-gallery' ),
			array(
				'pagination'          => __( 'Split the gallery into pages', 'lichtbild-gallery' ),
				'pagination_scroll'   => __( 'Scroll back to the top when the page changes', 'lichtbild-gallery' ),
				'lightbox_span_pages' => __( 'Let the lightbox run through every page, not just the visible one', 'lichtbild-gallery' ),
			)
		);

		$this->row_number( $settings, 'per_page', __( 'Images per page', 'lichtbild-gallery' ), 0, 500, __( 'Zero switches pagination off.', 'lichtbild-gallery' ) );

		$this->row_flags(
			$settings,
			__( 'Tag filter', 'lichtbild-gallery' ),
			array(
				'tags'             => __( 'Show a tag filter', 'lichtbild-gallery' ),
				'tags_all_enabled' => __( 'Include a button that clears the filter', 'lichtbild-gallery' ),
			)
		);

		$this->row_choice(
			$settings,
			'tags_position',
			__( 'Filter position', 'lichtbild-gallery' ),
			array(
				'above' => __( 'Above the images', 'lichtbild-gallery' ),
				'below' => __( 'Below the images', 'lichtbild-gallery' ),
			)
		);

		$this->row_text( $settings, 'tags_all_label', __( 'Label for the clear button', 'lichtbild-gallery' ), __( 'Left empty, this reads "All".', 'lichtbild-gallery' ) );

		$this->row_flags( $settings, __( 'EXIF', 'lichtbild-gallery' ), array( 'exif' => __( 'Show camera data in the lightbox', 'lichtbild-gallery' ) ) );
		$this->row_list( $settings, 'exif_fields', __( 'EXIF fields', 'lichtbild-gallery' ), $this->exif_labels() );
		$this->row_text( $settings, 'exif_date_format', __( 'Capture date format', 'lichtbild-gallery' ), __( 'A PHP date format. Left empty, the site\'s own is used.', 'lichtbild-gallery' ) );

		$this->row_flags( $settings, __( 'Sharing', 'lichtbild-gallery' ), array( 'social' => __( 'Show sharing buttons in the lightbox', 'lichtbild-gallery' ) ) );
		$this->row_list(
			$settings,
			'social_networks',
			__( 'Networks', 'lichtbild-gallery' ),
			array(
				'facebook'  => __( 'Facebook', 'lichtbild-gallery' ),
				'twitter'   => __( 'X / Twitter', 'lichtbild-gallery' ),
				'pinterest' => __( 'Pinterest', 'lichtbild-gallery' ),
				'email'     => __( 'Email', 'lichtbild-gallery' ),
			)
		);

		echo '</table>';
		$this->complete_section( 'settings' );
	}

	/**
	 * Returns select labels for the registered image sizes.
	 *
	 * @param string[] $sizes Registered size names.
	 *
	 * @return array<string,string> Labels keyed by size name.
	 */
	private function size_labels( array $sizes ) {
		$labels = array();

		foreach ( $sizes as $size ) {
			$labels[ $size ] = $size;
		}

		return $labels;
	}

	/**
	 * Returns checkbox labels for the supported EXIF fields.
	 *
	 * @return array<string,string> Labels keyed by field name.
	 */
	private function exif_labels() {
		$labels = array(
			'make'          => __( 'Camera make', 'lichtbild-gallery' ),
			'model'         => __( 'Camera model', 'lichtbild-gallery' ),
			'focal_length'  => __( 'Focal length', 'lichtbild-gallery' ),
			'aperture'      => __( 'Aperture', 'lichtbild-gallery' ),
			'shutter_speed' => __( 'Shutter speed', 'lichtbild-gallery' ),
			'iso'           => __( 'ISO', 'lichtbild-gallery' ),
			'capture_time'  => __( 'Capture date', 'lichtbild-gallery' ),
		);

		$out = array();

		// Driven by the supported list rather than by this map, so a field added to
		// `Lichtbild_Exif` cannot be silently unreachable from the editor.
		foreach ( Lichtbild_Exif::SUPPORTED as $field ) {
			$out[ $field ] = isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
		}

		return $out;
	}

	/**
	 * Renders a select row.
	 *
	 * @param array               $settings Current settings.
	 * @param string              $key      Setting name.
	 * @param string              $label    Row label.
	 * @param array<string,string> $choices Options keyed by stored value.
	 *
	 * @return void
	 */
	private function row_choice( array $settings, $key, $label, array $choices ) {
		$current = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';

		echo '<tr><th scope="row"><label for="lichtbild-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select name="lichtbild_settings[' . esc_attr( $key ) . ']" id="lichtbild-' . esc_attr( $key ) . '">';

		foreach ( $choices as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, (string) $value, false ) . '>' .
				esc_html( $text ) . '</option>';
		}

		echo '</select></td></tr>';
	}

	/**
	 * Renders a number row.
	 *
	 * @param array  $settings    Current settings.
	 * @param string $key         Setting name.
	 * @param string $label       Row label.
	 * @param int    $min         Lowest permitted value.
	 * @param int    $max         Highest permitted value.
	 * @param string $description Help text.
	 *
	 * @return void
	 */
	private function row_number( array $settings, $key, $label, $min, $max, $description = '' ) {
		$value = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : 0;

		echo '<tr><th scope="row"><label for="lichtbild-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="lichtbild-' . esc_attr( $key ) . '"' .
			' name="lichtbild_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '"' .
			' min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" step="1" />';

		if ( '' !== $description ) {
			echo ' <span class="description">' . esc_html( $description ) . '</span>';
		}

		echo '</td></tr>';
	}

	/**
	 * Renders a text row.
	 *
	 * @param array  $settings    Current settings.
	 * @param string $key         Setting name.
	 * @param string $label       Row label.
	 * @param string $description Help text.
	 *
	 * @return void
	 */
	private function row_text( array $settings, $key, $label, $description = '' ) {
		$value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';

		echo '<tr><th scope="row"><label for="lichtbild-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="lichtbild-' . esc_attr( $key ) . '"' .
			' name="lichtbild_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" />';

		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Renders a group of checkboxes, each its own boolean setting.
	 *
	 * The id is emitted for the same reason the single-value rows emit one: it is the only
	 * handle the editor's script has on a box whose state changes what the rest of the screen
	 * means. The `<label>` wraps its input, so nothing here depends on it for labelling.
	 *
	 * @param array                $settings    Current settings.
	 * @param string               $label       Row label.
	 * @param array<string,string> $flags       Labels keyed by setting name.
	 * @param string               $description Help text printed under the group.
	 *
	 * @return void
	 */
	private function row_flags( array $settings, $label, array $flags, $description = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><fieldset>';

		foreach ( $flags as $key => $text ) {
			echo '<label><input type="checkbox" id="lichtbild-' . esc_attr( $key ) . '"' .
				' name="lichtbild_settings[' . esc_attr( $key ) . ']" value="1"' .
				checked( ! empty( $settings[ $key ] ), true, false ) . ' /> ' . esc_html( $text ) . '</label><br />';
		}

		echo '</fieldset>';

		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Renders a group of checkboxes that together make up one list setting.
	 *
	 * @param array                $settings Current settings.
	 * @param string               $key      Setting name.
	 * @param string               $label    Row label.
	 * @param array<string,string> $members  Labels keyed by member value.
	 *
	 * @return void
	 */
	private function row_list( array $settings, $key, $label, array $members ) {
		$current = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : array();

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><fieldset>';

		foreach ( $members as $value => $text ) {
			echo '<label><input type="checkbox" name="lichtbild_settings[' . esc_attr( $key ) . '][]"' .
				' value="' . esc_attr( $value ) . '"' .
				checked( in_array( (string) $value, array_map( 'strval', $current ), true ), true, false ) .
				' /> ' . esc_html( $text ) . '</label><br />';
		}

		echo '</fieldset></td></tr>';
	}

	/**
	 * Renders the shortcode metabox.
	 *
	 * @param WP_Post|object $post Gallery being edited.
	 *
	 * @return void
	 */
	public function render_shortcode_box( $post ) {
		$shortcode = '[lichtbild-gallery id="' . (int) $post->ID . '"]';

		echo '<p>' . esc_html__( 'Paste this into a post or page:', 'lichtbild-gallery' ) . '</p>';
		echo '<input type="text" class="large-text code" readonly value="' . esc_attr( $shortcode ) . '"' .
			' onfocus="this.select()" />';
		echo '<p class="description">' .
			esc_html__( 'The Envira shortcode with this ID keeps working too, so nothing already published needs changing.', 'lichtbild-gallery' ) .
			'</p>';
	}

	/**
	 * Saves a submitted gallery.
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
		 * The nonce IS verified for this request -- in `authorised_save()`, one statement above,
		 * which returns early on an absent or wrong nonce, on an autosave, on the wrong post
		 * type and without the capability. A static analyser cannot follow verification into a
		 * helper, so it reports every `$_POST` read below as unverified; suppressing that is
		 * therefore a statement about the analyser, not about the request.
		 *
		 * The two arrays are unslashed here and sanitized per field afterwards, which is the
		 * only order that works: `collect_items()` decides field by field what each value may
		 * contain, and `Lichtbild_Config::sanitize()` does the same for the settings. Sanitising
		 * the array wholesale at the read would have to assume one type for every field it
		 * holds, which is how a caption loses its punctuation and a boolean becomes a string.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted = isset( $_POST['lichtbild_items'] ) && is_array( $_POST['lichtbild_items'] )
			? wp_unslash( $_POST['lichtbild_items'] )
			: array();

		$order = isset( $_POST['lichtbild_order'] ) ? sanitize_text_field( wp_unslash( $_POST['lichtbild_order'] ) ) : '';

		$settings = isset( $_POST['lichtbild_settings'] ) && is_array( $_POST['lichtbild_settings'] )
			? wp_unslash( $_POST['lichtbild_settings'] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$collected = $this->collect_items( $submitted, $order, $post_id );

		// `wp_slash()` because core's metadata layer unslashes the value it is handed — correct
		// for the raw `$_POST` it normally receives, wrong for one already unslashed above. A
		// caption reading `C:\Photos` would otherwise be stored as `C:Photos`, losing a
		// backslash per save and never reporting it.
		update_post_meta(
			$post_id,
			Lichtbild_Repository::GALLERY_META_V2,
			wp_slash(
				array(
					'version'  => Lichtbild_Config::VERSION,
					'settings' => Lichtbild_Config::sanitize( $settings ),
					'items'    => $collected['items'],
				)
			)
		);

		// After the gallery, never during the collect. Tags are terms on the attachment, so
		// writing them is a second write to a second table: doing it while assembling the
		// record means an early return added between the two leaves the tags changed and the
		// gallery as it was, which is a disagreement no screen shows.
		//
		// Not conditional on what `update_post_meta()` returned, and that is deliberate — it
		// answers `false` both on failure and when the value is already identical, and a save
		// that changes only tags is exactly the second case.
		foreach ( $collected['tags'] as $attachment_id => $value ) {
			$this->save_tags( (int) $attachment_id, $value );
		}
	}

	/**
	 * Turns the submitted rows into stored items, in the submitted order.
	 *
	 * Collection only: the tag writes it finds are returned rather than performed, so that the
	 * one method that writes to the database is the one whose name says so.
	 *
	 * @param array  $submitted Rows keyed by row key.
	 * @param string $order     Comma-separated row keys, in display order.
	 * @param int    $post_id   Gallery post ID.
	 *
	 * @return array{items:array,tags:array} Item records, and the submitted tag lists keyed by
	 *                                       attachment ID. A row that submitted no `tags` key
	 *                                       is absent from that map, which is what "leave this
	 *                                       image's tags alone" looks like.
	 */
	private function collect_items( array $submitted, $order, $post_id ) {
		$items = array();
		$tags  = array();

		foreach ( $this->ordered_rows( $submitted, $order ) as $row ) {
			$record = Lichtbild_Item::sanitize_record( $row );

			if ( null === $record ) {
				continue;
			}

			// Existing posts must be readable attachments. Missing attachments retain their frozen
			// URLs, which keeps migrated galleries usable after a media-library deletion.
			$type = $record['id'] > 0 ? get_post_type( $record['id'] ) : false;
			if ( $type && ( 'attachment' !== $type || ! current_user_can( 'read_post', $record['id'] ) ) ) {
				continue;
			}

			$items[] = $record;

			if ( $record['id'] > 0 && array_key_exists( 'tags', $row ) ) {
				$tags[ $record['id'] ] = $row['tags'];
			}
		}

		/**
		 * Filters the items about to be stored for a gallery.
		 *
		 * @param array $items   Item records, in display order.
		 * @param int   $post_id Gallery post ID.
		 */
		return array(
			'items' => (array) apply_filters( 'lichtbild_editor_items', $items, $post_id ),
			'tags'  => $tags,
		);
	}

	/**
	 * Writes an image's tags.
	 *
	 * These are terms on the attachment, so this changes the image everywhere it appears —
	 * which is the point: the same photograph carrying different tags in two galleries would
	 * make the filter mean something different on each page.
	 *
	 * Authorised against the **attachment**, not against the gallery. The save path has already
	 * established that the user may edit this gallery, and that is a different question: the
	 * terms are written to the image, and because they are shared, writing them changes the
	 * filter on every other gallery that image appears in — including galleries this user
	 * cannot open. `wp_set_object_terms()` performs no capability check of its own and creates
	 * any term it is given a name for, so without this, edit rights on one gallery confer the
	 * right to retag the whole media library and invent taxonomy terms at will.
	 *
	 * A refusal is silent, matching the rest of this save path: the gallery still saves, and
	 * only the tags of images the user may not edit are left alone.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param mixed $value         Whatever the row submitted as its tag list.
	 *
	 * @return void
	 */
	private function save_tags( $attachment_id, $value ) {
		// Whatever a request carries, not necessarily a string: `lichtbild_items[i0][tags][]`
		// submits an array, and casting one writes a term literally named "Array" onto the
		// image — shared, so onto every gallery holding it — with a PHP warning to match. A row
		// that submitted something that is not a tag list has said nothing about its tags.
		if ( ! is_string( $value ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return;
		}

		$names = array();

		foreach ( explode( ',', $value ) as $name ) {
			$name = sanitize_text_field( trim( $name ) );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		wp_set_object_terms(
			$attachment_id,
			array_values( array_unique( $names ) ),
			Lichtbild_Post_Types::tag_taxonomy( $this->settings )
		);
	}

	/**
	 * Enqueues the editor's assets on the gallery edit screen.
	 *
	 * @param string $hook Current admin page.
	 *
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! $this->editing_our_type( $hook ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'lichtbild-editor',
			LICHTBILD_URL . 'assets/css/editor.css',
			array(),
			LICHTBILD_VERSION
		);

		wp_enqueue_script(
			'lichtbild-editor',
			LICHTBILD_URL . 'assets/js/editor.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-util' ),
			LICHTBILD_VERSION,
			true
		);

		wp_localize_script(
			'lichtbild-editor',
			'LichtbildEditor',
			array(
				'i18n' => array(
					'chooseImages' => __( 'Add images to this gallery', 'lichtbild-gallery' ),
					'useImages'    => __( 'Add to gallery', 'lichtbild-gallery' ),
				),
			)
		);
	}

	/**
	 * Adds the image count and shortcode columns to the gallery list.
	 *
	 * @param array $columns Existing columns.
	 *
	 * @return array Columns with ours inserted before the date.
	 */
	public function columns( $columns ) {
		return $this->insert_columns(
			$columns,
			array(
				'lichtbild_images'    => __( 'Images', 'lichtbild-gallery' ),
				'lichtbild_shortcode' => __( 'Shortcode', 'lichtbild-gallery' ),
			)
		);
	}

	/**
	 * Prints one of our list columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Gallery post ID.
	 *
	 * @return void
	 */
	public function column( $column, $post_id ) {
		if ( 'lichtbild_shortcode' === $column ) {
			echo '<code>[lichtbild-gallery id=&quot;' . (int) $post_id . '&quot;]</code>';

			return;
		}

		if ( 'lichtbild_images' !== $column ) {
			return;
		}

		// Through the repository rather than off the v2 meta, because which record is
		// authoritative is exactly the thing that changes at migration. Counting
		// `_lichtbild_gallery` directly reports 0 for every gallery on an un-migrated site -- where
		// the real record is still Envira's -- and post-migration it counts the pending rows and
		// the malformed ones the reader drops, so the number here and the number of photographs
		// on the page disagree.
		$gallery = $this->repository->gallery( (int) $post_id );

		echo null === $gallery ? 0 : (int) $gallery->count();
	}
}
