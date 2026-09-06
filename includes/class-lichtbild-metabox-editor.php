<?php
/**
 * What the two metabox editors do identically.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * The mechanics shared by the gallery editor and the album editor.
 *
 * The two are twins on purpose — the same seven-step guard chain, the same explicit order
 * field, the same refusal to run before the migration — and for two releases that likeness
 * was six copies rather than one class. It cost what a copied rule always costs: the fix that
 * put the album's list column through the repository landed on one twin and not the other, so
 * one screen counted the authoritative record and the other counted a meta key that is empty
 * on every un-migrated site. A guard copied is a guard that will be forgotten once.
 *
 * What lives here is mechanics: which requests a save may act on, how a submitted order list
 * becomes rows, where our columns go in a list table, what an un-migrated screen says instead
 * of a form. What deliberately does not is **markup** — the row templates and the settings
 * fields are written per editor, because a gallery row and an album row have nothing in common
 * past the `<li>` around them, and the suite already checks each pair of templates against the
 * record shape it has to carry.
 *
 * Subclasses declare `NONCE` and `NONCE_ACTION`; the chain reads them through `static::`, so a
 * subclass that forgot one is a fatal error rather than a save authorising against the wrong
 * action.
 */
abstract class Lichtbild_Metabox_Editor {

	/**
	 * Plugin settings, consulted for whether Lichtbild owns the data.
	 *
	 * @var Lichtbild_Settings
	 */
	protected $settings;

	/**
	 * Repository, which is what knows which record is authoritative.
	 *
	 * @var Lichtbild_Repository
	 */
	protected $repository;

	/**
	 * Builds the editor.
	 *
	 * @param Lichtbild_Settings   $settings   Plugin settings.
	 * @param Lichtbild_Repository $repository Gallery and album repository.
	 */
	public function __construct( Lichtbild_Settings $settings, Lichtbild_Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/**
	 * Returns the post type this editor edits, under the name it has right now.
	 *
	 * The name changes at migration, so it is asked for rather than written down: a screen,
	 * a metabox or a guard nailed to one name attaches to nothing on the other side of it.
	 *
	 * @return string Post type name.
	 */
	abstract protected function post_type();

	/**
	 * Reports whether a `save_post` request may write this post's record.
	 *
	 * @param int $post_id Post being saved.
	 *
	 * @return bool True when the request is ours, authorised, and worth writing.
	 */
	protected function authorised_save( $post_id ) {
		// `save_post` fires for far more than this form — autosaves, revisions, quick edit,
		// bulk edit, status transitions. Every one of those posts a request with no items in
		// it, so anything that got past this point would store an empty record: a quick edit
		// of the title would empty the gallery.
		//
		// Removing this line does not by itself let such a request through, because the nonce
		// verification below refuses an absent nonce as readily as a wrong one. What only this
		// line prevents is a PHP warning on every one of those saves, and `save_post` fires
		// often enough that such a log is one nobody can read.
		if ( ! isset( $_POST[ static::NONCE ] ) ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return false;
		}

		// Galleries and albums are saved by the same hook and live in the same table, so a
		// handler that did not check the type would write its record onto the other's row.
		if ( get_post_type( $post_id ) !== $this->post_type() ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ static::NONCE ] ) );

		if ( ! wp_verify_nonce( $nonce, static::NONCE_ACTION . $post_id ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		// The record an editor writes is authoritative only on a migrated site, so writing one
		// anywhere else produces a record nothing reads and an edit that appears not to save.
		if ( ! $this->settings->has_migrated() ) {
			return false;
		}

		// PHP truncates POST at max_input_vars. Each metabox ends with a marker so either
		// ordering of the boxes is safe, including truncation inside the settings fields.
		if ( ! isset( $_POST[ static::NONCE . '_items_complete' ], $_POST[ static::NONCE . '_settings_complete' ] ) ) {
			set_transient( static::NONCE . '_incomplete_' . get_current_user_id(), $post_id, 300 );
			return false;
		}

		return true;
	}

	/**
	 * Prints the completion marker after a metabox's last submitted field.
	 *
	 * @param string $section Metabox section name.
	 * @return void
	 */
	protected function complete_section( $section ) {
		echo '<input type="hidden" name="' . esc_attr( static::NONCE . '_' . $section . '_complete' ) . '" value="1" />';
	}

	/**
	 * Reports an incomplete submission on the affected editor's next page load.
	 *
	 * @return void
	 */
	public function render_save_notice() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $this->post_type() ) {
			return;
		}
		$key = static::NONCE . '_incomplete_' . get_current_user_id();
		$post_id = (int) get_transient( $key );
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-error"><p>' . esc_html__( 'The gallery or album data was not saved because the server received an incomplete form. Your images and settings were kept. Ask your host to increase PHP max_input_vars, then try again.', 'lichtbild-gallery' ) . '</p></div>';
	}

	/**
	 * Returns the submitted rows in the order the form named them.
	 *
	 * Order is submitted explicitly, in a field of its own, rather than inferred from the order
	 * of the fields. The browser serialises in DOM order and PHP preserves it, so inferring it
	 * would usually be right — and "usually" is the wrong standard for the one property a
	 * drag-and-drop editor exists to set.
	 *
	 * Two consequences worth stating, because both are load-bearing rather than incidental. A
	 * row the order does not name is a row the browser left behind, so it is dropped — which is
	 * also how removal works. And a key named twice is a malformed submission rather than an
	 * instruction to store the same row twice.
	 *
	 * @param array  $submitted Rows keyed by row key.
	 * @param string $order     Comma-separated row keys, in display order.
	 *
	 * @return array<int,array> The named rows, deduplicated, in the submitted order.
	 */
	protected function ordered_rows( array $submitted, $order ) {
		$keys = array_filter( array_map( array( $this, 'clean_key' ), explode( ',', (string) $order ) ) );
		$rows = array();
		$seen = array();

		foreach ( $keys as $key ) {
			if ( ! isset( $submitted[ $key ] ) || ! is_array( $submitted[ $key ] ) || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$rows[]       = $submitted[ $key ];
		}

		return $rows;
	}

	/**
	 * Restricts a row key to the characters the editors generate.
	 *
	 * @param string $key Submitted row key.
	 *
	 * @return string Cleaned key, empty when nothing survived.
	 */
	private function clean_key( $key ) {
		return (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) $key );
	}

	/**
	 * Prints the notice shown in place of the editor on an un-migrated site.
	 *
	 * Both sentences come from the caller so that each editor says what it is about in its own
	 * words, and so that the strings stay literal where a translator's tooling can find them.
	 *
	 * @param string $summary     What the screen cannot do, in one sentence.
	 * @param string $explanation Why, and what to do instead.
	 *
	 * @return void
	 */
	protected function render_unavailable( $summary, $explanation ) {
		echo '<p><strong>' . esc_html( $summary ) . '</strong></p>';
		echo '<p>' . esc_html( $explanation ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'options-general.php?page=lichtbild' ) ) . '">' .
			esc_html__( 'Go to Settings > Lichtbild', 'lichtbild-gallery' ) . '</a></p>';
	}

	/**
	 * Inserts our own columns into a list table, before the date.
	 *
	 * @param array                $columns Existing columns.
	 * @param array<string,string> $ours    Our columns, labels keyed by column name.
	 *
	 * @return array Columns with ours inserted.
	 */
	protected function insert_columns( array $columns, array $ours ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out = array_merge( $out, $ours );
			}

			$out[ $key ] = $label;
		}

		// A list table with no date column would otherwise silently lose ours. Any plugin may
		// remove that column, so the impossibility is enforced in someone else's code rather
		// than in this one, which is why the fallback stays.
		foreach ( $ours as $key => $label ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $label;
			}
		}

		return $out;
	}

	/**
	 * Reports whether an admin request is the edit screen of a post this editor owns.
	 *
	 * @param string $hook Current admin page.
	 *
	 * @return bool True when our assets belong on this screen.
	 */
	protected function editing_our_type( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen || $screen->post_type !== $this->post_type() ) {
			return false;
		}

		// An editor that cannot save has no form to wire up, so its assets would be bytes on a
		// page carrying a notice.
		return $this->settings->has_migrated();
	}
}
