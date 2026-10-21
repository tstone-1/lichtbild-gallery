<?php
/**
 * Reads galleries and albums out of the database.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the stored gallery and album records and turns them into Lichtbild objects.
 *
 * **What this class decides is which stored record is authoritative**, and it is the only one
 * that reads both shapes at query time. A migrated site's own `_lichtbild_gallery` record wins;
 * an un-migrated one — or a rolled-back one — falls back to converting `_eg_gallery_data` on
 * the fly. Which of those applies is the `$owns_data` question, and getting it wrong is not a
 * detail: see `build_gallery()` for the two sources of truth that answer differs between.
 *
 * It once claimed to be the only class that knew Envira's storage layout, and that stopped
 * being true the day the schema move it predicted actually happened. `Lichtbild_Config`,
 * `Lichtbild_Album_Config` and `Lichtbild_Migration::build_record()` all know that layout now, and
 * deliberately: conversion is where the four spellings of true and the frozen titles are dealt
 * with once, rather than re-derived on every page load.
 */
class Lichtbild_Repository {

	/**
	 * Post type holding galleries.
	 */
	const GALLERY_POST_TYPE = 'envira';

	/**
	 * Post type holding albums.
	 */
	const ALBUM_POST_TYPE = 'envira_album';

	/**
	 * Taxonomy holding per-image tags.
	 */
	const TAG_TAXONOMY = 'envira-tag';

	/**
	 * Post meta key holding a gallery record Lichtbild wrote.
	 */
	const GALLERY_META_V2 = '_lichtbild_gallery';

	/**
	 * Post meta key holding a gallery record Envira wrote.
	 */
	const GALLERY_META = '_eg_gallery_data';

	/**
	 * Post meta key holding an album record Envira wrote.
	 */
	const ALBUM_META = '_eg_album_data';

	/**
	 * Post meta key holding an album record Lichtbild wrote.
	 */
	const ALBUM_META_V2 = '_lichtbild_album';

	/**
	 * Galleries already loaded this request, keyed by post ID.
	 *
	 * @var array<int,Lichtbild_Gallery|null>
	 */
	private $galleries = array();

	/**
	 * Albums already loaded this request, keyed by post ID.
	 *
	 * @var array<int,Lichtbild_Album|null>
	 */
	private $albums = array();

	/**
	 * Post type galleries are stored under.
	 *
	 * @var string
	 */
	private $gallery_type;

	/**
	 * Post type albums are stored under.
	 *
	 * @var string
	 */
	private $album_type;

	/**
	 * Taxonomy per-image tags are stored under.
	 *
	 * @var string
	 */
	private $tag_taxonomy;

	/**
	 * Whether Lichtbild's own records are authoritative.
	 *
	 * @var bool
	 */
	private $owns_data;

	/**
	 * Builds the repository.
	 *
	 * The names are injected rather than read from a constant because they change when the
	 * data is migrated off Envira's post types, and every read has to follow that move. They
	 * default to Envira's so an un-migrated site — and the render tests — need no argument.
	 *
	 * @param string $gallery_type Post type galleries are stored under.
	 * @param string $album_type   Post type albums are stored under.
	 * @param string $tag_taxonomy Taxonomy per-image tags are stored under.
	 * @param bool   $owns_data    Whether Lichtbild's own gallery records are authoritative.
	 */
	public function __construct(
		$gallery_type = self::GALLERY_POST_TYPE,
		$album_type = self::ALBUM_POST_TYPE,
		$tag_taxonomy = self::TAG_TAXONOMY,
		$owns_data = false
	) {
		$this->gallery_type = (string) $gallery_type;
		$this->album_type   = (string) $album_type;
		$this->tag_taxonomy = (string) $tag_taxonomy;
		$this->owns_data    = (bool) $owns_data;
	}

	/**
	 * Loads a gallery.
	 *
	 * @param int|string $id Gallery post ID, or a gallery slug.
	 *
	 * @return Lichtbild_Gallery|null The gallery, or null when it does not exist or is empty.
	 */
	public function gallery( $id ) {
		$post_id = $this->resolve_id( $id, $this->gallery_type );

		if ( 0 === $post_id ) {
			return null;
		}

		if ( array_key_exists( $post_id, $this->galleries ) ) {
			return $this->galleries[ $post_id ];
		}

		$this->galleries[ $post_id ] = $this->build_gallery( $post_id );

		return $this->galleries[ $post_id ];
	}

	/**
	 * Loads an album.
	 *
	 * @param int|string $id Album post ID, or an album slug.
	 *
	 * @return Lichtbild_Album|null The album, or null when it does not exist.
	 */
	public function album( $id ) {
		$post_id = $this->resolve_id( $id, $this->album_type );

		if ( 0 === $post_id ) {
			return null;
		}

		if ( array_key_exists( $post_id, $this->albums ) ) {
			return $this->albums[ $post_id ];
		}

		$this->albums[ $post_id ] = $this->build_album( $post_id );

		return $this->albums[ $post_id ];
	}

	/**
	 * Reports whether a post may be shown to whoever is asking.
	 *
	 * Every path that can put gallery content in front of a visitor consults this, and it
	 * lives here because the repository is what the publishing paths already hold: the
	 * renderer is handed one to resolve album members, and the AJAX and standalone handlers
	 * are constructed with one.
	 *
	 * **Two independent gates, and neither stands in for the other.** A password-protected
	 * post is `publish` status, so the status check alone lets it through; a draft gallery
	 * carries no password, so the password check alone lets *it* through. Both legs have a
	 * mutation apiece for that reason.
	 *
	 * Neither gate is enforced anywhere upstream for the content Lichtbild publishes. WordPress
	 * applies a post password by replacing `post_content`, and a gallery keeps its images in
	 * post meta; `Lichtbild_Repository::resolve_id()` matches a numeric ID on post *type* alone,
	 * so a draft or private gallery named by an album or a shortcode loads perfectly happily.
	 *
	 * **All five publishing paths consult this** — the standalone filter, both AJAX endpoints,
	 * the album cover grid, the shortcode, and the two blocks. The shortcode was the last to
	 * adopt it, and the argument for leaving it out was not a bad one; see the class docblock on
	 * `Lichtbild_Shortcode` for what settled it. The blocks arrived already consulting it, because
	 * they render nothing themselves and hand to the shortcode instead — which is the cheapest
	 * way to add a publishing path that cannot forget. Nothing here is optional, and a path that
	 * stops asking is the defect this predicate exists to make impossible to reintroduce quietly.
	 *
	 * @param int $post_id Post ID of a gallery or album.
	 *
	 * @return bool True when the post may be rendered for the current visitor.
	 */
	public function is_viewable( $post_id ) {
		$post_id = (int) $post_id;
		$status  = get_post_status( $post_id );

		if ( 'publish' !== $status && ! current_user_can( 'read_post', $post_id ) ) {
			return false;
		}

		// Returns false only for a visitor who has not entered the password, so this blocks
		// the people the password is for and nobody else.
		return ! post_password_required( $post_id );
	}

	/**
	 * Lists the galleries a picker may offer, titled.
	 *
	 * **The reader decides what is offerable, not the query**, and that is the point of routing
	 * this through `gallery()` rather than returning what `get_posts()` found. Envira keeps its
	 * site-wide defaults in a gallery of its own, the migration renames that row along with
	 * every other, and afterwards it is an ordinary `lichtbild_gallery` post as far as any query is
	 * concerned. A picker built from the raw rows offers "Envira Default Settings" as a choice
	 * and hands back a member the renderer then declines to draw.
	 *
	 * Not filtered by `is_viewable()`, deliberately: that predicate answers a question about a
	 * *visitor*, and a draft gallery is a legitimate thing for an author to place in a post they
	 * are also drafting. What stops it reaching the public is the publishing path, which asks.
	 *
	 * It IS filtered by capability, which is a different question and was missing entirely: the
	 * status list above deliberately includes `private`, `draft` and `pending`, so an unfiltered
	 * listing handed every author the titles of every other author's unpublished galleries.
	 * `read_post` is the default because that is the picker's real question -- may this person
	 * see that this exists -- and it maps to `read` for a published post, so placing a
	 * colleague's published gallery in a post keeps working. The album editor passes
	 * `edit_post` instead, because album membership republishes a gallery's cover and title
	 * under the album, which is the same boundary `handle_covers()` already enforces.
	 *
	 * @param string $capability Meta capability to require for each listed object.
	 *
	 * @return array<int,string> Titles keyed by gallery post ID, ordered by title.
	 */
	public function gallery_choices( $capability = 'read_post' ) {
		$out = array();

		foreach ( $this->rows( $this->gallery_type ) as $post_id ) {
			if ( ! current_user_can( $capability, $post_id ) ) {
				continue;
			}

			if ( null === $this->gallery( $post_id ) ) {
				continue;
			}

			$title = (string) get_the_title( $post_id );

			/* translators: %d: gallery post ID. */
			$out[ $post_id ] = '' !== $title ? $title : sprintf( __( 'Gallery %d', 'lichtbild-gallery' ), $post_id );
		}

		return $out;
	}

	/**
	 * Lists the albums a picker may offer, titled.
	 *
	 * Capability-filtered for the reason given on `gallery_choices()`: the status list includes
	 * unpublished objects, so an unfiltered listing leaks other authors' titles.
	 *
	 * @param string $capability Meta capability to require for each listed object.
	 *
	 * @return array<int,string> Titles keyed by album post ID, ordered by title.
	 */
	public function album_choices( $capability = 'read_post' ) {
		$out = array();

		foreach ( $this->rows( $this->album_type ) as $post_id ) {
			if ( ! current_user_can( $capability, $post_id ) ) {
				continue;
			}

			if ( null === $this->album( $post_id ) ) {
				continue;
			}

			$title = (string) get_the_title( $post_id );

			/* translators: %d: album post ID. */
			$out[ $post_id ] = '' !== $title ? $title : sprintf( __( 'Album %d', 'lichtbild-gallery' ), $post_id );
		}

		return $out;
	}

	/**
	 * Builds the record Lichtbild stores for one gallery.
	 *
	 * The one definition of the envelope. The gallery editor, block creation and the migration
	 * each assembled it by hand until 26.10.0, and the reader below refuses a record without
	 * settings, so a writer that got the shape wrong produced a gallery that silently rendered
	 * from Envira's record, or not at all.
	 *
	 * @param array $settings Normalised settings.
	 * @param array $items    Item records, in display order.
	 *
	 * @return array{version:int,settings:array,items:array} The record.
	 */
	public static function gallery_record( array $settings, array $items ) {
		return array(
			'version'  => Lichtbild_Config::VERSION,
			'settings' => $settings,
			'items'    => array_values( $items ),
		);
	}

	/**
	 * Builds the record Lichtbild stores for one album.
	 *
	 * @param array $settings Normalised album settings.
	 * @param array $items    Member records, in display order.
	 *
	 * @return array{version:int,settings:array,items:array} The record.
	 */
	public static function album_record( array $settings, array $items ) {
		return array(
			'version'  => Lichtbild_Album_Config::VERSION,
			'settings' => $settings,
			'items'    => array_values( $items ),
		);
	}

	/**
	 * Stores a gallery record.
	 *
	 * `wp_slash()` because core's metadata layer unslashes the value it is handed, which is right
	 * for the raw `$_POST` it normally receives and wrong for a value already unslashed. A caption
	 * reading `C:\Photos` would otherwise be stored as `C:Photos`, losing a backslash per save and
	 * never reporting it. Every writer had to remember that until this existed.
	 *
	 * @param int   $post_id  Gallery post ID.
	 * @param array $settings Normalised settings.
	 * @param array $items    Item records, in display order.
	 *
	 * @return int|bool What `update_post_meta()` returned.
	 */
	public static function store_gallery( $post_id, array $settings, array $items ) {
		return update_post_meta( (int) $post_id, self::GALLERY_META_V2, wp_slash( self::gallery_record( $settings, $items ) ) );
	}

	/**
	 * Stores an album record, with the slashing `store_gallery()` explains.
	 *
	 * @param int   $post_id  Album post ID.
	 * @param array $settings Normalised album settings.
	 * @param array $items    Member records, in display order.
	 *
	 * @return int|bool What `update_post_meta()` returned.
	 */
	public static function store_album( $post_id, array $settings, array $items ) {
		return update_post_meta( (int) $post_id, self::ALBUM_META_V2, wp_slash( self::album_record( $settings, $items ) ) );
	}

	/**
	 * Returns every post ID of one of Lichtbild's types, whatever its status.
	 *
	 * The status list is explicit because `get_posts()` defaults to `publish` alone, and a
	 * picker that silently omitted every draft would read as "this site has no galleries" to
	 * whoever had just made one.
	 *
	 * @param string $post_type Post type to list.
	 *
	 * @return int[] Post IDs, ordered by title.
	 */
	private function rows( $post_type ) {
		$posts = get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
				'fields'      => 'ids',
			)
		);

		return array_map( 'intval', (array) $posts );
	}

	/**
	 * Builds a gallery object from its stored record.
	 *
	 * @param int $post_id Gallery post ID.
	 *
	 * @return Lichtbild_Gallery|null The gallery, or null when the record is unusable.
	 */
	private function build_gallery( $post_id ) {
		// A record Lichtbild wrote wins over the one it was converted from — but only while the
		// site is migrated, and that condition is the whole point rather than a detail.
		//
		// The migration leaves both records in place, so after a rollback the converted one is
		// still sitting there. Preferring it unconditionally would mean a rollback restored
		// Envira's post types without restoring Envira's *authority*: edit a gallery in Envira
		// afterwards, switch back to Lichtbild, and Lichtbild would render the pre-rollback snapshot
		// instead. Two sources of truth, silently disagreeing, in the state that exists
		// precisely because someone wanted to undo the migration.
		//
		// This is also what makes an interrupted migration safe. Records are converted before
		// any row is renamed, so a request that dies in the middle leaves converted records on
		// an unmigrated site — inert, because of this condition.
		//
		// The exception is a gallery that has no Envira record at all: one created in Lichtbild
		// after the migration, on a site that was later rolled back. There is only one source of
		// truth then, so there is nothing to disagree with, and refusing it made every such
		// gallery render nothing until the site was migrated again.
		$own  = get_post_meta( $post_id, self::GALLERY_META_V2, true );
		$data = get_post_meta( $post_id, self::GALLERY_META, true );

		if ( ( $this->owns_data || ! is_array( $data ) ) && is_array( $own ) && ! empty( $own['settings'] ) ) {
			return $this->build_from_own( $post_id, $own );
		}

		if ( ! is_array( $data ) ) {
			return null;
		}

		$config = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array();

		// Envira stores its site-wide defaults in a gallery of its own. Rendering that as a
		// gallery would output an empty grid on any page that referenced it by mistake.
		if ( 'defaults' === ( isset( $config['type'] ) ? $config['type'] : '' ) ) {
			return null;
		}

		$records = isset( $data['gallery'] ) && is_array( $data['gallery'] ) ? $data['gallery'] : array();
		$items   = array();

		// Envira keys its item map by attachment ID, so the key is the identity.
		foreach ( $records as $key => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$item = new Lichtbild_Item( is_numeric( $key ) ? (int) $key : 0, $record, $this->tag_taxonomy );

			if ( $item->is_active() ) {
				$items[] = $item;
			}
		}

		return new Lichtbild_Gallery(
			$post_id,
			Lichtbild_Config::from_envira( $config, $post_id ),
			$this->filter_items( $items, $post_id )
		);
	}

	/**
	 * Builds a gallery from a record Lichtbild wrote.
	 *
	 * @param int   $post_id Gallery post ID.
	 * @param array $record  Stored record.
	 *
	 * @return Lichtbild_Gallery The gallery.
	 */
	private function build_from_own( $post_id, array $record ) {
		$settings = is_array( $record['settings'] ) ? $record['settings'] : array();
		$entries  = isset( $record['items'] ) && is_array( $record['items'] ) ? $record['items'] : array();
		$items    = array();

		// Lichtbild stores an ordered list rather than a map keyed by attachment, so that the
		// same image can appear twice and so that order is the record's own property rather
		// than an accident of array key order.
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$id   = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
			$item = new Lichtbild_Item( $id, $entry, $this->tag_taxonomy );

			if ( $item->is_active() ) {
				$items[] = $item;
			}
		}

		return new Lichtbild_Gallery( $post_id, $settings, $this->filter_items( $items, $post_id ) );
	}

	/**
	 * Applies the item filter and reindexes.
	 *
	 * @param Lichtbild_Item[] $items   Items to filter.
	 * @param int           $post_id Gallery post ID.
	 *
	 * @return Lichtbild_Item[] Filtered items.
	 */
	private function filter_items( array $items, $post_id ) {
		/**
		 * Filters the items of a gallery before it is rendered.
		 *
		 * @param Lichtbild_Item[] $items   Active items in display order.
		 * @param int           $post_id Gallery post ID.
		 */
		$items = (array) apply_filters( 'lichtbild_gallery_items', $items, $post_id );

		return array_values( $items );
	}

	/**
	 * Builds an album object from its stored record.
	 *
	 * @param int $post_id Album post ID.
	 *
	 * @return Lichtbild_Album|null The album, or null when the record is unusable.
	 */
	private function build_album( $post_id ) {
		// The same rule as galleries, and for the same reason: Lichtbild's own record wins, but
		// only while the site is migrated. The migration leaves Envira's record in place, so
		// preferring the converted one unconditionally would mean a rollback restored Envira's
		// post types without restoring Envira's authority.
		//
		// Albums went a release without this. `build_album()` read `_eg_album_data` whatever the
		// schema said, so a migrated site's albums were still in Envira's format — which is why
		// an album editor could not be written before this existed: it would have had to write
		// Envira's format back, the one thing the gallery side is built to avoid.
		//
		// And the same exception: an album with no Envira record has one source of truth, so a
		// rolled-back site still reads it.
		$own  = get_post_meta( $post_id, self::ALBUM_META_V2, true );
		$data = get_post_meta( $post_id, self::ALBUM_META, true );

		if ( ( $this->owns_data || ! is_array( $data ) ) && is_array( $own ) && isset( $own['settings'] ) && is_array( $own['settings'] ) ) {
			$items = isset( $own['items'] ) && is_array( $own['items'] ) ? $own['items'] : array();

			return new Lichtbild_Album( $post_id, $own['settings'], self::clean_album_items( $items ) );
		}

		if ( ! is_array( $data ) ) {
			return null;
		}

		$config = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array();

		// Envira keeps its site-wide album defaults in an album of its own, exactly as it does
		// for galleries. Rendering it would produce an empty cover grid.
		if ( 'defaults' === ( isset( $config['type'] ) ? (string) $config['type'] : '' ) ) {
			return null;
		}

		$items = array();

		foreach ( self::envira_album_entries( $data ) as $gallery_id => $entry ) {
			$items[] = Lichtbild_Album_Config::item_from_envira( $gallery_id, is_array( $entry ) ? $entry : array() );
		}

		return new Lichtbild_Album( $post_id, Lichtbild_Album_Config::from_envira( $config ), $items );
	}

	/**
	 * Returns the per-gallery entries of an Envira album record, keyed by gallery ID.
	 *
	 * Envira has used both spellings across versions, so both are accepted.
	 *
	 * @param array $data The `_eg_album_data` value.
	 *
	 * @return array<int,mixed> Entries keyed by gallery ID.
	 */
	public static function envira_album_entries( array $data ) {
		$entries = array();

		foreach ( array( 'galleries', 'gallery' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$entries = $data[ $key ];

				break;
			}
		}

		$clean = array();

		foreach ( $entries as $gallery_id => $entry ) {
			if ( is_numeric( $gallery_id ) ) {
				$clean[ (int) $gallery_id ] = $entry;
			}
		}

		return $clean;
	}

	/**
	 * Drops anything from a stored item list that is not a usable item.
	 *
	 * @param array $items Stored items.
	 *
	 * @return array<int,array{id:int,cover_id:int,caption:string}> Clean items, reindexed.
	 */
	private static function clean_album_items( array $items ) {
		$clean = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			$clean[] = array(
				'id'       => (int) $item['id'],
				'cover_id' => isset( $item['cover_id'] ) ? (int) $item['cover_id'] : 0,
				'caption'  => isset( $item['caption'] ) ? (string) $item['caption'] : '',
			);
		}

		return $clean;
	}

	/**
	 * Resolves a shortcode's `id` or `slug` argument to a post ID.
	 *
	 * @param int|string $id        A post ID or a slug.
	 * @param string     $post_type Post type to look in.
	 *
	 * @return int Post ID, or 0 when nothing matched.
	 */
	private function resolve_id( $id, $post_type ) {
		if ( is_numeric( $id ) ) {
			$post_id = (int) $id;

			return get_post_type( $post_id ) === $post_type ? $post_id : 0;
		}

		$slug = sanitize_title( (string) $id );

		if ( '' === $slug ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				'name'             => $slug,
				'post_status'      => array( 'publish', 'private', 'draft' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}
}
