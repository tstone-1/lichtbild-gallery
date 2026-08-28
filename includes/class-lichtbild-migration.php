<?php
/**
 * Moves galleries off Envira's post types and onto Lichtbild's.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plans, performs and reverses the migration onto Lichtbild's own storage.
 *
 * This is the first thing in the plugin that writes to the database, so its shape is
 * governed by what happens when it goes wrong rather than by what happens when it works:
 *
 * - **Post IDs never change.** The migration is an `UPDATE ... SET post_type`, not a copy.
 *   Every `[envira-gallery id="N"]` in a post still names the same row, permalinks keep
 *   their slugs, attachments keep their parents, and nothing has to be rewritten in the
 *   content of 49 posts. A create-and-import design would have to maintain an ID map
 *   forever, and would break any link anyone ever saved.
 * - **Nothing is destroyed.** `_eg_gallery_data` is left exactly as it was; the converted
 *   record is written alongside it under a new key. Rollback is therefore a matter of
 *   putting the post types back and forgetting the new key — no data is reconstructed,
 *   because none was lost.
 * - **The plan is separable from the act.** `plan()` answers what would change without
 *   touching anything, so the confirmation screen shows counts derived from the same code
 *   that does the work rather than from a description of it.
 *
 * The one thing that genuinely cannot be undone by this class is a rewrite flush, which is
 * why it is done last and unconditionally in both directions.
 */
class Lichtbild_Migration {

	/**
	 * Plugin settings.
	 *
	 * @var Lichtbild_Settings
	 */
	private $settings;

	/**
	 * Builds the migrator.
	 *
	 * @param Lichtbild_Settings $settings Plugin settings.
	 */
	public function __construct( Lichtbild_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Converts one stored Envira gallery record into Lichtbild's own.
	 *
	 * Pure: it reads no database and writes nothing, so the migration's correctness can be
	 * asserted by rendering both records and comparing the markup.
	 *
	 * @param array $data    The `_eg_gallery_data` value.
	 * @param int   $post_id Gallery post ID.
	 *
	 * @return array|null A `_lichtbild_gallery` record, or null for Envira's defaults row.
	 */
	public static function build_record( array $data, $post_id ) {
		$config = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array();

		// Envira's own defaults record is not a gallery and must not become one. This moved here
		// from the migration loop when albums started sharing that loop: both types have such a
		// row, so deciding it is the record builder's job rather than the walker's.
		if ( 'defaults' === ( isset( $config['type'] ) ? (string) $config['type'] : '' ) ) {
			return null;
		}

		$records = isset( $data['gallery'] ) && is_array( $data['gallery'] ) ? $data['gallery'] : array();
		$items   = array();

		foreach ( $records as $key => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			// Envira's map is keyed by attachment ID; Lichtbild's list carries the ID as a
			// field, so order belongs to the record and an image may appear twice.
			$items[] = array(
				'id'      => is_numeric( $key ) ? (int) $key : 0,
				'status'  => isset( $record['status'] ) ? (string) $record['status'] : 'active',
				'src'     => isset( $record['src'] ) ? (string) $record['src'] : '',
				'link'    => isset( $record['link'] ) ? (string) $record['link'] : '',
				'title'   => isset( $record['title'] ) ? (string) $record['title'] : '',
				'caption' => isset( $record['caption'] ) ? (string) $record['caption'] : '',
				'alt'     => isset( $record['alt'] ) ? (string) $record['alt'] : '',
			);
		}

		return array(
			'version'  => Lichtbild_Config::VERSION,
			'settings' => Lichtbild_Config::from_envira( $config, $post_id ),
			'items'    => $items,
		);
	}

	/**
	 * Converts every record of one post type into Lichtbild's own format.
	 *
	 * Shared by galleries and albums so that the read-back verification below exists once. It was
	 * written for galleries and, when albums were finally given a record of their own, copying it
	 * would have been the third instance of this project's most expensive habit — a rule enforced
	 * in several places, staying identical only for as long as someone remembers.
	 *
	 * @param string   $post_type  Post type to walk.
	 * @param string   $source_key Meta key holding Envira's record.
	 * @param string   $target_key Meta key to write Lichtbild's record to.
	 * @param string   $failure    Error message template taking the post ID.
	 * @param callable $builder    Builds a record from `(array $data, int $id)`, or null to skip.
	 * @param string[] $errors     Errors, appended to.
	 *
	 * @return int|null Records written, or null when one could not be and nothing should move.
	 */
	private function convert( $post_type, $source_key, $target_key, $failure, callable $builder, array &$errors ) {
		global $wpdb;

		$written = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- enumerating every row of a post type is what WP_Query cannot do without instantiating each post; this runs once, inside a migration the user triggered, and caching a list that the next statement renames would cache a lie.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
				$post_type
			)
		);

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$data = get_post_meta( $id, $source_key, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$record = call_user_func( $builder, $data, $id );

			// Envira keeps its own defaults in a row of the same type, for galleries and albums
			// alike. It is not one, and must not become one.
			if ( null === $record ) {
				continue;
			}

			// `wp_slash()` for the same reason both editors need it: core's metadata layer
			// unslashes what it stores, so a value read out of one meta key and written to
			// another loses a level of backslashes on the way. Here the read-back below would
			// catch it and stop the migration -- safe, but it would mean one backslash anywhere
			// in a gallery's titles or captions blocked the whole thing for no good reason.
			update_post_meta( $id, $target_key, wp_slash( $record ) );

			// Verified by reading it back, not by trusting the return value. `update_post_meta()`
			// returns false both when the write failed and when the stored value was already
			// identical, so the return cannot distinguish the two — and counting the write as
			// done regardless would report a record as converted while none exists for it. The
			// row would still be renamed, leaving something that only renders because the Envira
			// record it was supposed to stop depending on is still there.
			//
			// And the read-back is compared against what was meant to be written, not merely
			// checked for being a well-formed record. A site that migrated and rolled back still
			// carries its converted records, so "a valid record is present" is satisfied by the
			// *old* one — which is precisely the stale value a failed write would leave.
			$stored = get_post_meta( $id, $target_key, true );

			if ( wp_json_encode( $stored ) !== wp_json_encode( $record ) ) {
				$errors[] = sprintf( $failure, $id );

				return null;
			}

			$written++;
		}

		return $written;
	}

	/**
	 * Builds the record Lichtbild stores for one album.
	 *
	 * @param array $data    The `_eg_album_data` value.
	 * @param int   $post_id Album post ID.
	 *
	 * @return array|null A `_lichtbild_album` record, or null for Envira's defaults row.
	 */
	public static function build_album_record( array $data, $post_id ) {
		unset( $post_id );

		$config = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array();

		if ( 'defaults' === ( isset( $config['type'] ) ? (string) $config['type'] : '' ) ) {
			return null;
		}

		$items = array();

		foreach ( Lichtbild_Repository::envira_album_entries( $data ) as $gallery_id => $entry ) {
			$items[] = Lichtbild_Album_Config::item_from_envira( $gallery_id, is_array( $entry ) ? $entry : array() );
		}

		return array(
			'version'  => Lichtbild_Album_Config::VERSION,
			'settings' => Lichtbild_Album_Config::from_envira( $config ),
			'items'    => $items,
		);
	}

	/**
	 * Reports what the pending action would change, without changing anything.
	 *
	 * "Pending" is directional: before migration it describes the migration, afterwards it
	 * describes the rollback, so the counts always name rows that exist right now. A screen
	 * built on a plan that only ever counted the Envira types would show three zeroes after a
	 * successful migration, which reads as "nothing to undo" rather than as "51 galleries to
	 * put back".
	 *
	 * The breakdown of galleries into convertible, defaults and unreadable is here rather
	 * than in the screen because it is the answer to the question the confirmation raises:
	 * why the number of records written is smaller than the number of rows moved.
	 *
	 * @return array{migrated:bool,galleries:int,albums:int,terms:int,mixed:bool,stranded:int,convertible:int,defaults:int,unreadable:int,converted:int}
	 */
	public function plan() {
		$migrated = $this->settings->has_migrated();

		$gallery_type = $migrated ? Lichtbild_Post_Types::GALLERY : Lichtbild_Repository::GALLERY_POST_TYPE;
		$album_type   = $migrated ? Lichtbild_Post_Types::ALBUM : Lichtbild_Repository::ALBUM_POST_TYPE;
		$tag_taxonomy = $migrated ? Lichtbild_Post_Types::TAG : Lichtbild_Repository::TAG_TAXONOMY;

		$counts = $this->rows_under( $gallery_type, $album_type, $tag_taxonomy );

		// A mixed state is the one the screen most needs to describe and the one a directional
		// plan structurally cannot: a request that died between the first rename and the
		// schema option leaves rows under Lichtbild's types while the option still says 1, so the
		// plan would report "0 galleries to migrate" over 52 stranded rows and offer no way
		// back. Counted separately, and never inferred from the flag.
		$stranded = $migrated ? 0 : $this->lichtbild_rows();
		$left     = $migrated ? $this->envira_rows() : 0;

		return array_merge(
			array(
				'migrated'  => $migrated,
				'galleries' => $counts['galleries'],
				'albums'    => $counts['albums'],
				'terms'     => $counts['terms'],
				'mixed'     => $stranded > 0 || $left > 0,
				'stranded'  => $stranded + $left,
			),
			$this->survey( $gallery_type )
		);
	}

	/**
	 * Counts the posts and taxonomy rows stored under one set of names.
	 *
	 * The one place the counting rules live. `plan()` reports the three numbers separately and
	 * `lichtbild_rows()` wants only their total, so the split is between what the counts *are* and
	 * what each caller does with them — rather than two copies of the same three queries, which
	 * is what this was and is exactly how a plan comes to describe something the migration does
	 * not do.
	 *
	 * @param string $gallery_type Gallery post type.
	 * @param string $album_type   Album post type.
	 * @param string $tag_taxonomy Image tag taxonomy.
	 *
	 * @return array{galleries:int,albums:int,terms:int} Rows stored under those names.
	 */
	private function rows_under( $gallery_type, $album_type, $tag_taxonomy ) {
		global $wpdb;

		return array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a count for the confirmation screen, which must reflect the database at the moment it is read; a cached count is precisely the wrong answer to "what am I about to migrate".
			'galleries' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
					$gallery_type
				)
			),
			'albums'    => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
					$album_type
				)
			),
			'terms'     => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
					$tag_taxonomy
				)
			),
		);
	}

	/**
	 * Counts the rows still stored under Envira's types and taxonomy.
	 *
	 * The mirror of `lichtbild_rows()`: after a successful migration this must be zero, and a
	 * non-zero answer means a rename did not finish.
	 *
	 * @return int Number of posts and taxonomy rows under Envira's names.
	 */
	private function envira_rows() {
		return array_sum(
			$this->rows_under(
				Lichtbild_Repository::GALLERY_POST_TYPE,
				Lichtbild_Repository::ALBUM_POST_TYPE,
				Lichtbild_Repository::TAG_TAXONOMY
			)
		);
	}

	/**
	 * Classifies the gallery rows of one post type by what conversion would do to them.
	 *
	 * Reads the same meta by the same key as `migrate()` and applies the same `defaults`
	 * test, so the confirmation screen's counts come from the code that does the work rather
	 * than from a description of it. Drifting the two apart is exactly how a plan comes to
	 * promise something the migration does not do.
	 *
	 * @param string $post_type Post type to survey.
	 *
	 * @return array{convertible:int,defaults:int,unreadable:int,converted:int}
	 */
	private function survey( $post_type ) {
		global $wpdb;

		$survey = array(
			'convertible' => 0,
			'defaults'    => 0,
			'unreadable'  => 0,
			'converted'   => 0,
		);

		$ids = array_map(
			'intval',
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above: the set being migrated, read once at the moment of migrating it.
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
					$post_type
				)
			)
		);

		if ( empty( $ids ) ) {
			return $survey;
		}

		// One query for every row's meta, so the loop below costs nothing further.
		update_meta_cache( 'post', $ids );

		foreach ( $ids as $id ) {
			if ( is_array( get_post_meta( $id, Lichtbild_Repository::GALLERY_META_V2, true ) ) ) {
				$survey['converted']++;
			}

			$data = get_post_meta( $id, Lichtbild_Repository::GALLERY_META, true );

			if ( ! is_array( $data ) ) {
				$survey['unreadable']++;
				continue;
			}

			if ( $this->is_defaults( $data ) ) {
				$survey['defaults']++;
				continue;
			}

			$survey['convertible']++;
		}

		return $survey;
	}

	/**
	 * Reports whether a stored record is Envira's site-wide defaults rather than a gallery.
	 *
	 * Envira keeps the settings its editor pre-fills new galleries with in a gallery-shaped
	 * row of its own. It has no items, and converting it would produce an empty gallery that
	 * renders as a blank grid.
	 *
	 * @param array $data The `_eg_gallery_data` value.
	 *
	 * @return bool True when the record is the defaults record.
	 */
	private function is_defaults( array $data ) {
		$config = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array();

		return 'defaults' === ( isset( $config['type'] ) ? $config['type'] : '' );
	}

	/**
	 * Performs the migration.
	 *
	 * **Two simultaneous requests are accepted rather than locked out, and that is a decision
	 * rather than an oversight.** Both would pass `has_migrated()`, and the loser would then
	 * find every statement already done: `convert()` writes records the read-back finds
	 * identical, `move()` matches no rows and reports `0` — which is a legitimate result and
	 * not the `false` a failure gives — `carry_seo_settings()` adds only keys that are not
	 * already there, and `set_schema()` confirms the value the winner wrote. Nothing is lost,
	 * nothing is written twice, and the whole damage is one screen reporting
	 * "Migrated. 0 galleries" over a site that migrated correctly. Cosmetic, on an action a
	 * site owner performs once.
	 *
	 * A lock would trade that for something worse. There is no transaction here, so a request
	 * can end at any statement — and a lock surviving such a request would block `migrate()`
	 * *and* the rollback, in precisely the mixed state where those two buttons are the only way
	 * back. Stale-takeover by timestamp only moves the race to the takeover, and buys a second
	 * option that every path out of this class then has to remember to clear. A stranding lock
	 * is a worse failure than the race it prevents.
	 *
	 * @return array{galleries:int,albums:int,terms:int,converted:int,albums_converted:int,seo_keys:int,errors:string[],warnings:string[]}
	 */
	public function migrate() {
		global $wpdb;

		$result = array(
			'galleries'        => 0,
			'albums'           => 0,
			'terms'            => 0,
			'converted'        => 0,
			'albums_converted' => 0,
			'seo_keys'         => 0,
			'errors'           => array(),
			// Separate from `errors` deliberately. An error means the migration failed and the
			// screen shows it instead of the result; a warning means the rows moved correctly
			// and something beside them did not, which the site owner still has to be told.
			'warnings'         => array(),
		);

		if ( $this->settings->has_migrated() ) {
			$result['errors'][] = __( 'The galleries have already been migrated.', 'lichtbild-gallery' );

			return $result;
		}

		// Refused rather than warned about. Migrating renames the rows out from under Envira,
		// which would leave its own screens empty and its shortcode rendering nothing — and
		// the person who would notice is a visitor, not whoever clicked the button.
		if ( $this->settings->envira_is_active() ) {
			$result['errors'][] = __( 'Deactivate Envira Gallery before migrating.', 'lichtbild-gallery' );

			return $result;
		}

		// Convert the records first. Doing this before the post types move means a failure
		// here leaves a site that is entirely unchanged rather than half-renamed.
		$converted = $this->convert(
			Lichtbild_Repository::GALLERY_POST_TYPE,
			Lichtbild_Repository::GALLERY_META,
			Lichtbild_Repository::GALLERY_META_V2,
			/* translators: %d: gallery post ID. */
			__( 'Gallery %d could not be converted, so the migration was stopped before anything was renamed.', 'lichtbild-gallery' ),
			array( self::class, 'build_record' ),
			$result['errors']
		);

		if ( null === $converted ) {
			return $result;
		}

		$result['converted'] = $converted;

		// Albums went a whole release without this, and the omission was invisible from both
		// ends: their rows were renamed, so the site looked migrated, while the reader went on
		// reading `_eg_album_data` — so the albums of a "migrated" site were still in Envira's
		// format. It surfaced only when an album editor was wanted, because writing one would
		// have meant writing Envira's format back.
		$converted = $this->convert(
			Lichtbild_Repository::ALBUM_POST_TYPE,
			Lichtbild_Repository::ALBUM_META,
			Lichtbild_Repository::ALBUM_META_V2,
			/* translators: %d: album post ID. */
			__( 'Album %d could not be converted, so the migration was stopped before anything was renamed.', 'lichtbild-gallery' ),
			array( self::class, 'build_album_record' ),
			$result['errors']
		);

		if ( null === $converted ) {
			return $result;
		}

		$result['albums_converted'] = $converted;

		$result['galleries'] = $this->move(
			$wpdb->posts,
			'post_type',
			Lichtbild_Repository::GALLERY_POST_TYPE,
			Lichtbild_Post_Types::GALLERY,
			$result['errors']
		);

		$result['albums'] = $this->move(
			$wpdb->posts,
			'post_type',
			Lichtbild_Repository::ALBUM_POST_TYPE,
			Lichtbild_Post_Types::ALBUM,
			$result['errors']
		);

		$result['terms'] = $this->move(
			$wpdb->term_taxonomy,
			'taxonomy',
			Lichtbild_Repository::TAG_TAXONOMY,
			Lichtbild_Post_Types::TAG,
			$result['errors']
		);

		// The option is what every read consults, so it must never claim a state the rows are
		// not in. If a rename failed, the rows are mixed — say so and leave the flag alone;
		// `rollback()` looks at the rows rather than the flag and can still put them back.
		if ( empty( $result['errors'] ) ) {
			// Carried across before the flag flips, because after it Envira's copy is the only
			// record of a choice the site owner made, and uninstalling Envira would take it —
			// blanking every gallery permalink on a site that had them switched on.
			//
			// Read back, for the reason `set_schema()` is read back: `update_option()` answers
			// false both for "the write failed" and for "the value was already that", so its
			// return value cannot carry this. Comparing the stored value against the intended
			// one can.
			$standalone = (int) (bool) get_option( Lichtbild_Settings::OPTION_STANDALONE_ENVIRA, false );

			update_option( Lichtbild_Settings::OPTION_STANDALONE, $standalone );

			if ( (int) get_option( Lichtbild_Settings::OPTION_STANDALONE, -1 ) !== $standalone ) {
				$result['warnings'][] = __( 'The setting that decides whether a gallery renders on its own permalink could not be copied across. Check Settings before uninstalling Envira Gallery, or those pages may come out blank.', 'lichtbild-gallery' );
			}

			$result['seo_keys'] = $this->carry_seo_settings( $result['warnings'] );

			// A WARNING, never an error, and the distinction is the whole design of this block.
			// An error suppresses the success notice and means "the migration failed"; the rows
			// have already moved by this point, so refusing to advance the schema would leave a
			// site whose posts are named `lichtbild_gallery` while the plugin registers `envira`
			// — measured on a full copy of the live site as 52 galleries present and 0 findable.
			// Losing a Yoast title is a bad day; hiding every gallery is a broken site. So the
			// schema advances regardless and the auxiliary failure is reported beside it, which
			// is the only part that was actually missing: it used to be silent.
			$this->set_schema( Lichtbild_Settings::SCHEMA_MIGRATED, $result['errors'] );
		}

		$this->finish();

		return $result;
	}

	/**
	 * Copies Yoast SEO's per-type settings onto the names this migration has just created.
	 *
	 * **A post type is a public identifier, and other plugins write it down.** Yoast keys every
	 * per-type setting on the registered name — `title-tax-envira-tag`, `noindex-envira`, and
	 * fifty more — so renaming the types silently orphans all of them. On the site this plugin
	 * exists for that removed Yoast from 58 indexed tag archives at a stroke: the document title
	 * fell back to WordPress's own default and the canonical link stopped being emitted
	 * altogether. Nothing about the pages looked broken, which is why it took a before-and-after
	 * capture to see.
	 *
	 * This is the same reasoning as the standalone option carried across above, and it was
	 * missing for the same reason: it is easy to think of a migration as being about rows.
	 *
	 * Deliberately Yoast-only. A general "rewrite every option mentioning the old name" pass
	 * would be guesswork about plugins whose key formats are unknown, and guesswork that writes.
	 *
	 * Two properties worth keeping:
	 *
	 * - **Keys are added, never replaced or removed.** Envira's originals stay, which is what
	 *   makes a rollback need no inverse of this: the old keys are still there for the restored
	 *   names, and the new ones become inert.
	 * - **The suffix is matched exactly, never as a substring.** Matching loosely turns
	 *   `title-tax-envira-category` into a key for a taxonomy this plugin never registered,
	 *   describing an archive that no longer exists. That mistake was made once, by hand, and
	 *   caught only by printing the list instead of applying it.
	 *
	 * @return int Number of keys added.
	 */
	private function carry_seo_settings( array &$warnings ) {
		$titles = get_option( 'wpseo_titles', null );

		if ( ! is_array( $titles ) || empty( $titles ) ) {
			return 0;
		}

		$types = array(
			Lichtbild_Repository::GALLERY_POST_TYPE => Lichtbild_Post_Types::GALLERY,
			Lichtbild_Repository::ALBUM_POST_TYPE   => Lichtbild_Post_Types::ALBUM,
		);

		$taxes = array( Lichtbild_Repository::TAG_TAXONOMY => Lichtbild_Post_Types::TAG );
		$added = array();

		foreach ( $titles as $key => $value ) {
			$new = null;

			if ( false !== strpos( $key, '-tax-' ) ) {
				list( $head, $tax ) = explode( '-tax-', $key, 2 );

				if ( isset( $taxes[ $tax ] ) ) {
					$new = $head . '-tax-' . $taxes[ $tax ];
				}
			} elseif ( 0 === strpos( $key, 'taxonomy-' ) && str_ends_with( $key, '-ptparent' ) ) {
				$tax = substr( $key, strlen( 'taxonomy-' ), -strlen( '-ptparent' ) );

				if ( isset( $taxes[ $tax ] ) ) {
					$new = 'taxonomy-' . $taxes[ $tax ] . '-ptparent';
				}
			} elseif ( 0 === strpos( $key, 'post_types-' ) && str_ends_with( $key, '-maintax' ) ) {
				$type = substr( $key, strlen( 'post_types-' ), -strlen( '-maintax' ) );

				if ( isset( $types[ $type ] ) ) {
					$new = 'post_types-' . $types[ $type ] . '-maintax';
				}
			} else {
				foreach ( $types as $old => $replacement ) {
					if ( str_ends_with( $key, '-' . $old ) ) {
						$new = substr( $key, 0, -strlen( $old ) ) . $replacement;

						break;
					}
				}
			}

			if ( null !== $new && ! array_key_exists( $new, $titles ) ) {
				$added[ $new ] = $value;
			}
		}

		if ( empty( $added ) ) {
			return 0;
		}

		update_option( 'wpseo_titles', array_merge( $titles, $added ) );

		// Read back before claiming a count. `count( $added )` is what this INTENDED to copy,
		// and reporting it after a write that did not land tells the site owner their canonical
		// URLs moved when they did not — the one number on the success notice nobody can check
		// by looking at the site.
		$stored = get_option( 'wpseo_titles', array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		foreach ( $added as $key => $value ) {
			if ( ! array_key_exists( $key, $stored ) || $stored[ $key ] !== $value ) {
				$warnings[] = __( 'The Yoast SEO titles and canonical settings for the old post types could not be carried onto the new ones. Check Yoast\'s Content Types settings; until then those pages fall back to Yoast\'s defaults.', 'lichtbild-gallery' );

				return 0;
			}
		}

		return count( $added );
	}

	/**
	 * Writes the schema option, and reports rather than assumes that it landed.
	 *
	 * The last statement of a migration is the one that decides which post types the *next*
	 * request registers and queries, so it is the one statement whose failure is invisible and
	 * total: every row has been renamed, the screen says it worked, and the site then goes
	 * looking for its galleries under the names they no longer have. Every gallery and album
	 * unreachable, from a write nobody checked.
	 *
	 * `update_option()` returns false both on failure and when the stored value is already
	 * identical — the same three-valued ambiguity `move()` exists for. Here the second case
	 * cannot arise, because this is only ever called to change the value: `migrate()` writes
	 * `SCHEMA_MIGRATED` having refused to run when `has_migrated()` was already true, and
	 * `rollback()` writes `1` having established the opposite. A `false` in this position is
	 * therefore a failure and nothing else, which is what makes it worth reading. Confirmed
	 * against the current value rather than trusted, so the claim does not rest on that
	 * reasoning staying true.
	 *
	 * @param int   $value  Schema version to store.
	 * @param array $errors Error list, appended to when the write did not land.
	 *
	 * @return bool Whether the option now holds the value.
	 */
	private function set_schema( $value, array &$errors ) {
		update_option( Lichtbild_Settings::OPTION_SCHEMA, $value );

		if ( (int) get_option( Lichtbild_Settings::OPTION_SCHEMA, 0 ) === (int) $value ) {
			return true;
		}

		$errors[] = sprintf(
			/* translators: %d: schema version that could not be stored. */
			__( 'The rows were renamed but the schema version could not be set to %d. The site is still reading the old post types; do not deactivate anything until this is resolved.', 'lichtbild-gallery' ),
			(int) $value
		);

		return false;
	}

	/**
	 * Renames one column value to another across a table.
	 *
	 * Exists to give `$wpdb->update()`'s three outcomes three different meanings. It returns
	 * the number of rows changed, `0` when there were none to change — a legitimate result —
	 * and `false` on a database error. Casting the return to `int` collapses the last two,
	 * so a failed statement reports as "nothing needed doing" and the migration carries on to
	 * the next one and then writes the schema option as though it had all worked.
	 *
	 * @param string   $table    Table name.
	 * @param string   $column   Column to rename.
	 * @param string   $from     Current value.
	 * @param string   $to       New value.
	 * @param string[] $errors   Collected errors, appended to by reference.
	 *
	 * @return int Rows changed; zero when the statement failed.
	 */
	private function move( $table, $column, $from, $to, array &$errors ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- renaming a post type in place is the whole point: it keeps every post ID, so no permalink moves and no shortcode breaks. There is no core API for it, and a write is not a cache candidate.
		$changed = $wpdb->update( $table, array( $column => $to ), array( $column => $from ), array( '%s' ), array( '%s' ) );

		if ( false === $changed ) {
			// The only thing this plugin logs, and this branch is why. The message below reaches
			// one admin screen and a transient that lives five minutes, so closing the tab leaves
			// a half-failed migration with nothing behind it but row counts that do not add up —
			// and the database's own reason for refusing, which is the only thing that says what
			// to do next, is discarded at the end of the request. Written to the log so it is
			// still there in the hosting panel tomorrow. Nothing else here logs, deliberately: a
			// line per ordinary operation is a log nobody reads.
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Lichtbild: could not rename %1$s "%2$s" to "%3$s" in %4$s: %5$s',
					$column,
					$from,
					$to,
					$table,
					$wpdb->last_error
				)
			);

			$errors[] = sprintf(
				/* translators: 1: value being renamed, 2: table name. */
				__( 'Renaming %1$s in %2$s failed. Roll back from this screen and try again.', 'lichtbild-gallery' ),
				$from,
				$table
			);

			return 0;
		}

		return (int) $changed;
	}

	/**
	 * Reverses the migration.
	 *
	 * The converted records are deliberately left in place. They are inert once the schema
	 * option is back to 1 — the repository only consults them when it finds them, and after
	 * a rollback the post type no longer matches — and keeping them means migrating again
	 * costs nothing and cannot lose an edit made in between.
	 *
	 * @return array{galleries:int,albums:int,terms:int,errors:string[]}
	 */
	public function rollback() {
		global $wpdb;

		// Deliberately not `has_migrated()`. There is no transaction here, so a request that
		// dies between the first rename and the schema option leaves rows under Lichtbild's
		// types while the option still says 1 — and a rollback gated on the option would
		// refuse exactly then, in the one state where it is the only way back. The question
		// that matters is whether any row is under Lichtbild's types, so that is the one asked.
		$stranded = $this->lichtbild_rows();

		// A site that never had Envira has nothing to roll back TO. Since such a site now starts
		// life already on Lichtbild's storage, `has_migrated()` is true there and the row count is
		// whatever the owner has built -- so without this the screen would happily offer to move
		// a fresh install's galleries onto post types named after a plugin it has never had.
		// Refused in the handler rather than merely hidden in the markup, for the reason the
		// album cover picker was: markup is a suggestion.
		if ( ! $this->settings->continues_envira() ) {
			return array(
				'galleries' => 0,
				'albums'    => 0,
				'terms'     => 0,
				'errors'    => array( __( 'This site has no Envira records, so there is nothing to roll back to.', 'lichtbild-gallery' ) ),
			);
		}

		if ( ! $this->settings->has_migrated() && 0 === $stranded ) {
			return array(
				'galleries' => 0,
				'albums'    => 0,
				'terms'     => 0,
				'errors'    => array( __( 'The galleries are not migrated, so there is nothing to roll back.', 'lichtbild-gallery' ) ),
			);
		}

		$result = array( 'errors' => array() );

		$result['galleries'] = $this->move(
			$wpdb->posts,
			'post_type',
			Lichtbild_Post_Types::GALLERY,
			Lichtbild_Repository::GALLERY_POST_TYPE,
			$result['errors']
		);

		$result['albums'] = $this->move(
			$wpdb->posts,
			'post_type',
			Lichtbild_Post_Types::ALBUM,
			Lichtbild_Repository::ALBUM_POST_TYPE,
			$result['errors']
		);

		$result['terms'] = $this->move(
			$wpdb->term_taxonomy,
			'taxonomy',
			Lichtbild_Post_Types::TAG,
			Lichtbild_Repository::TAG_TAXONOMY,
			$result['errors']
		);

		// Same rule in this direction, and it matters more: writing the flag back to 1 after a
		// failed rename would send the screen to its pre-migration state while rows are still
		// stranded under Lichtbild's types, hiding the rollback button that is the way out.
		if ( empty( $result['errors'] ) ) {
			$this->set_schema( 1, $result['errors'] );
		}

		$this->finish();

		return $result;
	}

	/**
	 * Counts the rows currently stored under Lichtbild's own types.
	 *
	 * Used to decide whether there is anything to roll back, independently of what the schema
	 * option claims — the two disagree precisely when a migration was interrupted.
	 *
	 * @return int Number of posts and taxonomy rows under Lichtbild's names.
	 */
	private function lichtbild_rows() {
		return array_sum(
			$this->rows_under( Lichtbild_Post_Types::GALLERY, Lichtbild_Post_Types::ALBUM, Lichtbild_Post_Types::TAG )
		);
	}

	/**
	 * Clears the caches and rewrite rules a type rename invalidates.
	 *
	 * Both are load-bearing and neither is optional. `post_type` is part of every cached
	 * post object, so a stale cache serves rows claiming a type that no longer exists. And
	 * rewrite rules are generated from the registered types at flush time, so without this
	 * every gallery URL 404s until someone happens to re-save the permalink settings — which
	 * looks exactly like the migration having broken the site.
	 *
	 * @return void
	 */
	private function finish() {
		wp_cache_flush();

		// The rules have to be rebuilt from the types as they are registered *now*, and this
		// request registered them as they were before the rename.
		delete_option( 'rewrite_rules' );
	}
}
