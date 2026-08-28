<?php
/**
 * A small WordPress stand-in, backed by a fixture exported from a real site.
 *
 * This exists so the renderer can be exercised against every gallery on a live site
 * without a WordPress install. It implements only the functions Lichtbild calls, and it
 * implements them the way WordPress does — in particular `wp_get_attachment_image_src()`
 * falling back to the full size when a registered size was never generated, which is the
 * case that decides whether the lightbox gets correct dimensions.
 *
 * @package Lichtbild\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Holds the fixture and answers queries against it.
 */
class Lichtbild_Test_Site {

	/**
	 * The shared instance.
	 *
	 * @var Lichtbild_Test_Site|null
	 */
	public static $instance = null;

	/**
	 * Site URL from the fixture.
	 *
	 * @var string
	 */
	public $siteurl = '';

	/**
	 * Gallery records keyed by post ID.
	 *
	 * @var array<int,array>
	 */
	public $galleries = array();

	/**
	 * Album records keyed by post ID.
	 *
	 * @var array<int,array>
	 */
	public $albums = array();

	/**
	 * Attachment records keyed by post ID.
	 *
	 * @var array<int,array>
	 */
	public $attachments = array();

	/**
	 * Loads a fixture file.
	 *
	 * @param string $path Path to the JSON fixture.
	 *
	 * @return Lichtbild_Test_Site The loaded site.
	 */
	public static function load( $path ) {
		$site = new self();
		$data = json_decode( (string) file_get_contents( $path ), true );

		$site->siteurl = isset( $data['siteurl'] ) ? $data['siteurl'] : 'https://example.com';

		foreach ( $data['galleries'] as $record ) {
			$record['data']                        = unserialize( base64_decode( $record['meta'] ) );
			$site->galleries[ (int) $record['id'] ] = $record;

			$site->note_password_data( $record );
		}

		foreach ( $data['albums'] as $record ) {
			$record['data']                     = unserialize( base64_decode( $record['meta'] ) );
			$site->albums[ (int) $record['id'] ] = $record;

			$site->note_password_data( $record );
		}

		foreach ( $data['attachments'] as $id => $record ) {
			if ( isset( $record['meta'] ) ) {
				$record['data'] = unserialize( base64_decode( $record['meta'] ) );
			}

			$site->attachments[ (int) $id ] = $record;
		}

		$site->build_tables();

		self::$instance = $site;

		return $site;
	}

	/**
	 * Post rows, keyed by post ID, holding only the columns the migration touches.
	 *
	 * @var array<int,array>
	 */
	public $posts = array();

	/**
	 * Taxonomy rows, keyed by term taxonomy ID.
	 *
	 * @var array<int,array>
	 */
	public $taxonomies = array();

	/**
	 * Stored options.
	 *
	 * @var array<string,mixed>
	 */
	public $options = array();

	/**
	 * Whether post meta writes should silently fail.
	 *
	 * @var bool
	 */
	public $fail_meta_writes = false;

	/**
	 * Metaboxes registered this run, keyed by box ID.
	 *
	 * @var array<string,array>
	 */
	public $meta_boxes = array();

	/** Projection of post meta into table shape, built on demand by the wpdb stub. */
	public $postmeta_projection = array();

	/**
	 * Post being rendered on its own permalink, or 0 when this is not such a request.
	 *
	 * @var int
	 */
	public $current_post = 0;

	/**
	 * The admin screen a request is on, or null when it is not on one.
	 *
	 * Null is the honest default here: nothing in this suite is an admin page load, and that is
	 * what core answers outside the admin. It is a property rather than a constant so that a
	 * check about the editors' asset enqueue has something to set.
	 *
	 * @var object|null
	 */
	public $current_screen = null;

	/**
	 * Post IDs whose password the visitor has not entered.
	 *
	 * @var int[]
	 */
	public $protected = array();

	/**
	 * Post content keyed by post ID, for the checks that scan it for a shortcode.
	 *
	 * The fixture does not export `post_content` — a gallery keeps its images in post meta,
	 * which is the whole premise — so this is a knob rather than fixture data.
	 *
	 * @var array<int,string>
	 */
	public $post_content = array();

	/**
	 * Post IDs the *fixture* records as password-protected on the live site.
	 *
	 * Kept separate from `$protected`, which is the knob individual checks set and reset. This
	 * one is provenance: it says what the real site looks like, so a check can be driven by the
	 * production distribution rather than only by a state someone thought to construct.
	 *
	 * @var int[]
	 */
	public $fixture_protected = array();

	/**
	 * Whether the fixture carries password data at all.
	 *
	 * A fixture exported before `post_password` was added has no opinion about protection, and
	 * that is not the same as a site with nothing protected. Without this the difference is
	 * invisible, and "no protected galleries found" would read as coverage.
	 *
	 * @var bool
	 */
	public $fixture_has_password_data = false;

	/**
	 * Capability answers that override the blanket one, keyed by `cap` or `cap:object_id`.
	 *
	 * `current_user_can()` otherwise answers from a single site-wide boolean, which cannot
	 * express "may edit this gallery but not that attachment" — the exact distinction the
	 * editor's tag guard turns on.
	 *
	 * @var array<string,bool>
	 */
	public $capability_overrides = array();

	/**
	 * Option names `update_option()` refuses to write.
	 *
	 * @var string[]
	 */
	public $fail_options_on = array();

	/**
	 * Cache-priming calls made this run, each `array{ids:int[]}`.
	 *
	 * @var array<int,array>
	 */
	public $primed = array();

	/**
	 * Records whether one fixture record carries password data, and whether it is protected.
	 *
	 * @param array $record A gallery or album record from the fixture.
	 *
	 * @return void
	 */
	public function note_password_data( array $record ) {
		if ( ! array_key_exists( 'protected', $record ) ) {
			return;
		}

		$this->fixture_has_password_data = true;

		if ( $record['protected'] ) {
			$this->fixture_protected[] = (int) $record['id'];
		}
	}

	/**
	 * Menu parents those registrations asked for, keyed by type name.
	 *
	 * @var array<string,mixed>
	 */
	public $menu_parents = array();

	/**
	 * What `current_user_can()` should answer.
	 *
	 * @var bool
	 */
	public $capabilities = false;

	/**
	 * Names passed to `register_post_type()` and `register_taxonomy()`.
	 *
	 * @var string[]
	 */
	public $registered = array();

	/**
	 * Rewrite slugs those registrations asked for, keyed by type name.
	 *
	 * @var array<string,string>
	 */
	public $rewrite_slugs = array();

	/**
	 * Returns the uploads base URL.
	 *
	 * @return string Base URL without a trailing slash.
	 */
	public function uploads_url() {
		return $this->siteurl . '/wp-content/uploads';
	}

	/**
	 * Builds the post and taxonomy rows the migration operates on.
	 *
	 * The fixture stores galleries, albums and attachment tags as objects; the migration
	 * works on table rows. This derives the second from the first so both views of the same
	 * site stay in step, rather than having the fixture carry the same facts twice.
	 *
	 * @return void
	 */
	public function build_tables() {
		$this->posts      = array();
		$this->taxonomies = array();

		foreach ( array_keys( $this->galleries ) as $id ) {
			$this->posts[ (int) $id ] = array( 'post_type' => 'envira' );
		}

		foreach ( array_keys( $this->albums ) as $id ) {
			$this->posts[ (int) $id ] = array( 'post_type' => 'envira_album' );
		}

		$slugs = array();

		foreach ( $this->attachments as $attachment ) {
			if ( empty( $attachment['tags'] ) ) {
				continue;
			}

			foreach ( $attachment['tags'] as $tag ) {
				$slugs[ $tag['slug'] ] = true;
			}
		}

		$next = 1;

		foreach ( array_keys( $slugs ) as $slug ) {
			$this->taxonomies[ $next++ ] = array(
				'taxonomy' => 'envira-tag',
				'slug'     => $slug,
			);
		}
	}

	/**
	 * Returns the taxonomy name image tags are currently stored under.
	 *
	 * Read from the rows rather than hardcoded, so a migration that renamed the post types
	 * and forgot the taxonomy would take the tags off every image instead of passing.
	 *
	 * @return string Taxonomy name.
	 */
	public function tag_taxonomy() {
		foreach ( $this->taxonomies as $row ) {
			return $row['taxonomy'];
		}

		return 'envira-tag';
	}
}

/**
 * Returns post meta for a gallery, album or attachment.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @param bool   $single  Whether to return a single value.
 *
 * @return mixed Meta value.
 */
function get_post_meta( $post_id, $key = '', $single = false ) {
	$site = Lichtbild_Test_Site::$instance;

	if ( '_lichtbild_gallery' === $key ) {
		return isset( $site->galleries[ $post_id ]['lichtbild'] )
			? $site->galleries[ $post_id ]['lichtbild']
			: '';
	}

	if ( '_lichtbild_album' === $key ) {
		return isset( $site->albums[ $post_id ]['lichtbild'] )
			? $site->albums[ $post_id ]['lichtbild']
			: '';
	}

	if ( '_eg_gallery_data' === $key && isset( $site->galleries[ $post_id ]['data'] ) ) {
		return $site->galleries[ $post_id ]['data'];
	}

	if ( '_eg_album_data' === $key && isset( $site->albums[ $post_id ]['data'] ) ) {
		return $site->albums[ $post_id ]['data'];
	}

	if ( '_wp_attachment_image_alt' === $key && isset( $site->attachments[ $post_id ]['alt'] ) ) {
		return $site->attachments[ $post_id ]['alt'];
	}

	return $single ? '' : array();
}

/**
 * Returns attachment metadata.
 *
 * @param int $attachment_id Attachment ID.
 *
 * @return array|false Metadata, or false when unknown.
 */
function wp_get_attachment_metadata( $attachment_id ) {
	$site = Lichtbild_Test_Site::$instance;

	return isset( $site->attachments[ $attachment_id ]['data'] )
		? $site->attachments[ $attachment_id ]['data']
		: false;
}

/**
 * Resolves a registered image size to a URL and pixel dimensions.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size          Registered size name.
 *
 * @return array|false Array of URL, width, height and intermediate flag.
 */
function wp_get_attachment_image_src( $attachment_id, $size = 'thumbnail' ) {
	$site = Lichtbild_Test_Site::$instance;
	$meta = wp_get_attachment_metadata( $attachment_id );

	if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
		return false;
	}

	$dir  = ltrim( dirname( $meta['file'] ), '.' );
	$base = $site->uploads_url() . ( '' !== $dir ? '/' . $dir : '' );

	if ( 'full' !== $size && isset( $meta['sizes'][ $size ] ) ) {
		$entry = $meta['sizes'][ $size ];

		return array(
			$base . '/' . $entry['file'],
			(int) $entry['width'],
			(int) $entry['height'],
			true,
		);
	}

	return array(
		$site->uploads_url() . '/' . $meta['file'],
		(int) $meta['width'],
		(int) $meta['height'],
		false,
	);
}

/**
 * Builds a srcset from every generated size sharing the original aspect ratio.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size          Registered size name.
 *
 * @return string|false Srcset string, or false when there is nothing to offer.
 */
function wp_get_attachment_image_srcset( $attachment_id, $size = 'medium' ) {
	$site = Lichtbild_Test_Site::$instance;
	$meta = wp_get_attachment_metadata( $attachment_id );

	if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || empty( $meta['width'] ) ) {
		return false;
	}

	$dir    = ltrim( dirname( $meta['file'] ), '.' );
	$base   = $site->uploads_url() . ( '' !== $dir ? '/' . $dir : '' );
	$ratio  = $meta['width'] / max( 1, $meta['height'] );
	$parts  = array();

	foreach ( $meta['sizes'] as $entry ) {
		if ( empty( $entry['width'] ) || empty( $entry['height'] ) ) {
			continue;
		}

		// WordPress only offers sizes whose aspect matches the original, so cropped
		// thumbnails never end up in a srcset alongside uncropped ones.
		if ( abs( ( $entry['width'] / $entry['height'] ) - $ratio ) > 0.02 ) {
			continue;
		}

		$parts[ (int) $entry['width'] ] = $base . '/' . $entry['file'] . ' ' . (int) $entry['width'] . 'w';
	}

	$parts[ (int) $meta['width'] ] = $site->uploads_url() . '/' . $meta['file'] . ' ' . (int) $meta['width'] . 'w';

	if ( count( $parts ) < 2 ) {
		return false;
	}

	ksort( $parts );

	return implode( ', ', $parts );
}

/**
 * Returns a post title.
 *
 * @param int $post_id Post ID.
 *
 * @return string Title.
 */
function get_the_title( $post_id = 0 ) {
	$site = Lichtbild_Test_Site::$instance;

	foreach ( array( 'galleries', 'albums', 'attachments' ) as $bucket ) {
		if ( isset( $site->$bucket[ $post_id ]['title'] ) ) {
			return (string) $site->$bucket[ $post_id ]['title'];
		}
	}

	return '';
}

/**
 * Returns one field of a post.
 *
 * @param string $field   Field name.
 * @param int    $post_id Post ID.
 *
 * @return string Field value.
 */
function get_post_field( $field, $post_id = 0 ) {
	$site = Lichtbild_Test_Site::$instance;

	if ( 'post_excerpt' === $field && isset( $site->attachments[ $post_id ]['excerpt'] ) ) {
		return (string) $site->attachments[ $post_id ]['excerpt'];
	}

	return '';
}

/**
 * Returns the post type of a post.
 *
 * @param int $post_id Post ID.
 *
 * @return string|false Post type, or false when unknown.
 */
function get_post_type( $post_id = 0 ) {
	$site = Lichtbild_Test_Site::$instance;

	// Answered from the rows rather than from which fixture bucket the ID is in, so that a
	// migration which renames the post type is visible here. A stub that always said `envira`
	// would let the repository keep finding galleries after a rename that never happened.
	if ( isset( $site->posts[ $post_id ]['post_type'] ) ) {
		return $site->posts[ $post_id ]['post_type'];
	}

	if ( isset( $site->attachments[ $post_id ] ) ) {
		return 'attachment';
	}

	return false;
}

/**
 * Returns the post status of a post.
 *
 * Answers from the row rather than from which bucket the ID landed in, so a rename that never
 * happened cannot be papered over — and it answers for **albums** as well as galleries, which
 * it did not until the visibility predicate started asking about them. An album page is a post
 * like any other; a stub that knew the status of only one of the two post types reported
 * `false` for every album, which reads exactly like a draft.
 *
 * Returning `false` for an unknown ID is deliberate: a stub that fell back to `publish` would
 * make the status leg of `Lichtbild_Repository::is_viewable()` impossible to fail.
 *
 * @param int $post_id Post ID.
 *
 * @return string|false Status, or false when unknown.
 */
function get_post_status( $post_id = 0 ) {
	$site = Lichtbild_Test_Site::$instance;

	foreach ( array( $site->galleries, $site->albums ) as $bucket ) {
		if ( isset( $bucket[ $post_id ]['status'] ) ) {
			return $bucket[ $post_id ]['status'];
		}
	}

	return false;
}

/**
 * Returns the terms of a taxonomy assigned to a post.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy name.
 *
 * @return array|false Term objects, or false when none.
 */
function get_the_terms( $post_id, $taxonomy ) {
	$site = Lichtbild_Test_Site::$instance;

	if ( $site->tag_taxonomy() !== $taxonomy || empty( $site->attachments[ $post_id ]['tags'] ) ) {
		return false;
	}

	$terms = array();

	foreach ( $site->attachments[ $post_id ]['tags'] as $tag ) {
		$terms[] = (object) array(
			'slug' => $tag['slug'],
			'name' => $tag['name'],
		);
	}

	return $terms;
}

/**
 * Returns a permalink.
 *
 * @param int $post_id Post ID.
 *
 * @return string Permalink.
 */
function get_permalink( $post_id = 0 ) {
	$site = Lichtbild_Test_Site::$instance;
	$name = isset( $site->galleries[ $post_id ]['name'] ) ? $site->galleries[ $post_id ]['name'] : (string) $post_id;

	return $site->siteurl . '/' . $name . '/';
}

/**
 * Looks posts up by slug, or lists every post of a type.
 *
 * Both shapes match on the row's *current* post type rather than a fixed name, so a query
 * asking for a type nothing is stored under finds nothing — which is what would happen on a
 * real site whose galleries had been renamed, and is what stops the album editor's gallery
 * picker looking populated on a site where the migration never ran.
 *
 * **`post_status` is honoured, and defaults to `publish` when absent exactly as the real
 * function does.** This ignored status entirely until 26.8.14, which made one whole class of
 * defect unconstructible: a picker that asks for published galleries only, and so silently
 * omits every draft on a site where a gallery is routinely prepared before it is published.
 * Mutation `BK6` does precisely that and SURVIVED, because both sides of the comparison were
 * reading a stub with no opinion about status. A stub that ignores an argument models code
 * that cannot get that argument wrong.
 *
 * @param array $args Query arguments.
 *
 * @return int[] Matching post IDs.
 */
function get_posts( $args = array() ) {
	$site = Lichtbild_Test_Site::$instance;
	$type = isset( $args['post_type'] ) ? $args['post_type'] : '';
	$name = isset( $args['name'] ) ? $args['name'] : '';

	// Counted so a check can assert that a code path reads *nothing*. On the live site's cold
	// cache one call here plus the reads behind it costs 111 queries, and the whole point of
	// where it is called from is that pages which never use the answer do not pay for it. That
	// is invisible in every rendered byte, so it can only be asserted as a call count.
	$GLOBALS['lichtbild_test_queries'] = ( $GLOBALS['lichtbild_test_queries'] ?? 0 ) + 1;

	// `any` is the real function's escape hatch; everything else is a list to match against.
	$statuses = isset( $args['post_status'] ) ? (array) $args['post_status'] : array( 'publish' );
	$any      = in_array( 'any', $statuses, true );

	$found = array();

	foreach ( array( $site->galleries, $site->albums ) as $bucket ) {
		foreach ( $bucket as $id => $record ) {
			$stored = isset( $site->posts[ $id ]['post_type'] ) ? $site->posts[ $id ]['post_type'] : '';

			if ( $stored !== $type ) {
				continue;
			}

			$status = isset( $record['status'] ) ? (string) $record['status'] : 'publish';

			if ( ! $any && ! in_array( $status, $statuses, true ) ) {
				continue;
			}

			if ( '' !== $name ) {
				if ( isset( $record['name'] ) && $record['name'] === $name ) {
					return array( (int) $id );
				}

				continue;
			}

			$found[] = (int) $id;
		}
	}

	return $found;
}

/**
 * Returns the admin URL that edits a post.
 *
 * Empty for an ID that is not a post here, because the editor uses the falsiness of the real
 * function's return to decide whether to link a member row at all.
 *
 * @param int $post_id Post ID.
 *
 * @return string Edit URL, or an empty string.
 */
function get_edit_post_link( $post_id = 0 ) {
	$site    = Lichtbild_Test_Site::$instance;
	$post_id = (int) $post_id;

	if ( ! isset( $site->posts[ $post_id ] ) ) {
		return '';
	}

	return 'https://example.test/wp-admin/post.php?post=' . $post_id . '&action=edit';
}

/**
 * Returns an option value.
 *
 * @param string $name    Option name.
 * @param mixed  $default Default value.
 *
 * @return mixed Option value.
 */
function get_option( $name, $default = false ) {
	$site = Lichtbild_Test_Site::$instance;

	if ( 'date_format' === $name ) {
		return 'F j, Y';
	}

	return array_key_exists( $name, $site->options ) ? $site->options[ $name ] : $default;
}

/**
 * Stores an option.
 *
 * @param string $name  Option name.
 * @param mixed  $value Option value.
 *
 * @return bool Always true.
 */
function update_option( $name, $value ) {
	$site = Lichtbild_Test_Site::$instance;

	// A knob, because the branch it reaches is otherwise unreachable and that branch is the
	// whole point of `Lichtbild_Migration::set_schema()`. A stub that always succeeds makes "the
	// rows were renamed but the flag was not written" impossible to construct — which is
	// exactly the state that leaves every gallery on the site unfindable, and exactly the state
	// this stub asserted could not happen for as long as it returned true unconditionally.
	if ( in_array( $name, $site->fail_options_on, true ) ) {
		return false;
	}

	$site->options[ $name ] = $value;

	return true;
}

/**
 * Removes an option.
 *
 * @param string $name Option name.
 *
 * @return bool Always true.
 */
function delete_option( $name ) {
	unset( Lichtbild_Test_Site::$instance->options[ $name ] );

	return true;
}

/**
 * Stores post meta.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @param mixed  $value   Meta value.
 *
 * @return bool Always true.
 */
function update_post_meta( $post_id, $key, $value ) {
	$site = Lichtbild_Test_Site::$instance;

	// A write that silently does not land is the case the migration's read-back exists for,
	// and it is unreachable unless the stub can produce it.
	if ( $site->fail_meta_writes ) {
		return false;
	}

	// Core unslashes the value on the way in -- `update_metadata()` calls `wp_unslash()` on it,
	// because WordPress normally hands this function raw slashed `$_POST` data. Modelling that
	// is the difference between a round-trip check that means something and one that cannot
	// fail: a stub storing the value verbatim makes the two ends agree no matter what the code
	// between them does with backslashes, which is how a double-unslash shipped in three places.
	$value = wp_unslash( $value );

	if ( '_lichtbild_gallery' === $key ) {
		$site->galleries[ (int) $post_id ]['lichtbild'] = $value;
	}

	if ( '_lichtbild_album' === $key ) {
		$site->albums[ (int) $post_id ]['lichtbild'] = $value;
	}

	return true;
}

/**
 * Primes the meta cache; a no-op here, where nothing is cached.
 *
 * @return void
 */
function update_meta_cache() {}

/**
 * Records a cache-priming call.
 *
 * Nothing is cached here, so this cannot make anything faster — and a priming call that is
 * merely absent changes no output at all, which is precisely why it needs recording. Without
 * it, deleting the prime from a hot endpoint would leave every check green, and the fix would
 * be one refactor away from silently reverting.
 *
 * @param int[] $ids               Post IDs being primed.
 * @param bool  $update_term_cache Whether terms are primed too.
 * @param bool  $update_meta_cache Whether meta is primed too.
 *
 * @return void
 */
function _prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true ) {
	Lichtbild_Test_Site::$instance->primed[] = array(
		'ids'   => array_values( array_map( 'intval', (array) $ids ) ),
		'terms' => (bool) $update_term_cache,
		'meta'  => (bool) $update_meta_cache,
	);
}

/**
 * Flushes the object cache; a no-op here, where nothing is cached.
 *
 * @return void
 */
function wp_cache_flush() {}

/**
 * A tiny stand-in for `$wpdb`, backed by the fixture's derived tables.
 *
 * It reads the column and value out of the SQL rather than recognising whole queries, so a
 * migration that named the wrong post type, or filtered on the wrong column, returns the
 * wrong rows here instead of quietly matching a hardcoded expectation. That is the
 * difference between a stub that can disagree with the code and one that cannot.
 */
class Lichtbild_Test_wpdb {

	/**
	 * Text of the error a simulated failure reports.
	 */
	const SIMULATED_ERROR = 'Simulated database error';

	/**
	 * Posts table name.
	 *
	 * @var string
	 */
	public $posts = 'wp_posts';

	/**
	 * Term taxonomy table name.
	 *
	 * @var string
	 */
	public $term_taxonomy = 'wp_term_taxonomy';

	/**
	 * Post meta table name.
	 *
	 * Absent until the slug scheme needed it, and its absence did NOT surface as a missing
	 * property — PHP resolved `{$wpdb->postmeta}` to an empty string, so the query reached the
	 * stub as `FROM  WHERE`, i.e. a syntactically valid statement naming no table. Worth
	 * knowing: an undeclared table name on this object fails as a *query* problem somewhere
	 * else, not as a property problem here.
	 *
	 * @var string
	 */
	public $postmeta = 'wp_postmeta';

	/**
	 * Table whose updates should fail, mimicking a database error.
	 *
	 * `$wpdb->update()` returns `false` on error and `0` when nothing matched, and telling
	 * those apart is the whole point of the migration's `move()` helper — so the stub has to
	 * be able to produce the first, or that branch is never executed by any test.
	 *
	 * @var string
	 */
	public $fail_updates_on = '';

	/**
	 * The database's own account of the last statement, empty when it did not fail.
	 *
	 * Real `$wpdb` sets this beside a `false` return, and it is the only thing that says *why*
	 * a rename was refused. A stub that always left it empty would let a log line claiming to
	 * carry the reason pass while carrying nothing — the check would be reading the format
	 * string rather than the diagnosis.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Substitutes values into a query, quoting strings.
	 *
	 * @param string $sql  Query with placeholders.
	 * @param mixed  ...$args Values.
	 *
	 * @return string Query with values substituted.
	 */
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $value ) {
			$replacement = is_int( $value ) ? (string) $value : "'" . str_replace( "'", "\\'", (string) $value ) . "'";
			$sql         = preg_replace( '/%[sd]/', $replacement, $sql, 1 );
		}

		return $sql;
	}

	/**
	 * Runs a `SELECT COUNT(*)` query.
	 *
	 * @param string $sql Query.
	 *
	 * @return int Row count.
	 */
	public function get_var( $sql ) {
		$this->get_var_calls++;

		return count( $this->matching( $sql ) );
	}

	/**
	 * Number of `get_var()` calls so far.
	 *
	 * Real `$wpdb->get_var()` has no cache, so every call here is a query on the live site. The
	 * suite reads this to assert that a question asked once per request is asked once, which no
	 * output-based check can see: priming and memoising change no rendered byte.
	 *
	 * @var int
	 */
	public $get_var_calls = 0;

	/**
	 * Runs a single-column `SELECT`.
	 *
	 * @param string $sql Query.
	 *
	 * @return array Column values.
	 */
	public function get_col( $sql ) {
		return array_keys( $this->matching( $sql ) );
	}

	/**
	 * Updates rows, returning how many changed.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Columns to set.
	 * @param array  $where  Columns to match.
	 *
	 * @return int|false Rows affected, or false on a simulated error.
	 */
	public function update( $table, $data, $where ) {
		if ( '' !== $this->fail_updates_on && $this->fail_updates_on === $table ) {
			$this->last_error = self::SIMULATED_ERROR . ' on ' . $table;

			return false;
		}

		// Cleared on the way past, the way a real statement clears it, so a check cannot read
		// the previous failure's message and conclude that this one failed too.
		$this->last_error = '';

		$rows    = &$this->table( $table );
		$changed = 0;

		foreach ( $rows as $id => $row ) {
			foreach ( $where as $column => $value ) {
				if ( ! isset( $row[ $column ] ) || $row[ $column ] !== $value ) {
					continue 2;
				}
			}

			foreach ( $data as $column => $value ) {
				$rows[ $id ][ $column ] = $value;
			}

			$changed++;
		}

		return $changed;
	}

	/**
	 * Returns the rows a query's `WHERE` clause selects.
	 *
	 * @param string $sql Query.
	 *
	 * @return array Matching rows keyed by ID.
	 */
	private function matching( $sql ) {
		// `WHERE col IN ( 'a', 'b' )`, which the equality pattern below cannot read. Modelled
		// rather than special-cased away, because the caller uses it to ask a real question --
		// does this site have ANY Envira record -- and a stub that answered `null` regardless
		// would make a fresh install and a migrated one indistinguishable, which is exactly the
		// distinction the slug scheme turns on.
		if ( preg_match( "/FROM\s+(\S+)\s+WHERE\s+(\w+)\s+IN\s*\(([^)]*)\)/i", $sql, $in ) ) {
			$wanted = array_map(
				static function ( $value ) {
					return trim( trim( $value ), "'" );
				},
				explode( ',', $in[3] )
			);

			$matched = array();

			foreach ( $this->table( $in[1] ) as $id => $row ) {
				if ( isset( $row[ $in[2] ] ) && in_array( $row[ $in[2] ], $wanted, true ) ) {
					$matched[ $id ] = $row;
				}
			}

			return $matched;
		}

		if ( ! preg_match( "/FROM\s+(\S+)\s+WHERE\s+(\w+)\s*=\s*'([^']*)'/i", $sql, $found ) ) {
			throw new RuntimeException( 'Unsupported query: ' . $sql );
		}

		$rows    = $this->table( $found[1] );
		$matched = array();

		foreach ( $rows as $id => $row ) {
			if ( isset( $row[ $found[2] ] ) && $row[ $found[2] ] === $found[3] ) {
				$matched[ $id ] = $row;
			}
		}

		return $matched;
	}

	/**
	 * Returns a reference to one of the fixture's tables.
	 *
	 * @param string $table Table name.
	 *
	 * @return array Reference to the rows.
	 */
	private function &table( $table ) {
		$site = Lichtbild_Test_Site::$instance;

		if ( $this->posts === $table ) {
			return $site->posts;
		}

		if ( $this->term_taxonomy === $table ) {
			return $site->taxonomies;
		}

		// Post meta is not a table in this fixture -- it hangs off the gallery and album rows --
		// so it is projected into one on demand. Only `meta_key` is modelled, because that is
		// the only column any query here selects on, and inventing the rest would be modelling
		// WordPress rather than modelling what the code asks for.
		//
		// The projection is rebuilt per call and kept on the site object because this method
		// returns a REFERENCE: handing back a local would be a notice and, worse, a reference to
		// a variable that dies with the call.
		if ( $this->postmeta === $table ) {
			$site->postmeta_projection = array();
			$row_id                    = 0;

			foreach ( array( '_eg_gallery_data' => $site->galleries, '_eg_album_data' => $site->albums ) as $key => $rows ) {
				foreach ( $rows as $id => $row ) {
					if ( ! isset( $row['data'] ) || '' === $row['data'] ) {
						continue;
					}

					$row_id++;
					$site->postmeta_projection[ $row_id ] = array(
						'meta_id'  => $row_id,
						'post_id'  => $id,
						'meta_key' => $key,
					);
				}
			}

			return $site->postmeta_projection;
		}

		throw new RuntimeException( 'Unknown table: ' . $table );
	}
}

$GLOBALS['wpdb'] = new Lichtbild_Test_wpdb();

/**
 * Formats a timestamp.
 *
 * @param string $format Date format.
 * @param int    $stamp  Unix timestamp.
 *
 * @return string Formatted date.
 */
function date_i18n( $format, $stamp = 0 ) {
	return gmdate( $format, (int) $stamp );
}

/**
 * Returns the value unchanged, and records what each filter was handed.
 *
 * No callback ever runs here, so the value is not the interesting part. The ARGUMENT COUNT is:
 * a filter's extra arguments are its context, and what a plugin puts in that context is a
 * public API decision no other instrument in this suite can see. `lichtbild_config_sanitize`
 * passed the raw `$_POST` array as context until the wordpress.org review objected, and nothing
 * would have noticed it coming back — the return value is identical either way.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value Value being filtered.
 *
 * @return mixed The value.
 */
function apply_filters( $hook, $value ) {
	$GLOBALS['lichtbild_test_filters'][ (string) $hook ] = func_num_args();

	return $value;
}

/**
 * Records a hook registration.
 *
 * These were no-ops until the harness-completeness guard showed that `Lichtbild` -- the container
 * that constructs the whole object graph and registers every hook -- had never been loaded by
 * this suite at all. Recording makes `boot()` checkable, which is worth having: the container is
 * where a constructor signature change lands, and nothing else would notice one.
 *
 * @param string $hook     Hook name.
 * @param mixed  $callback Callback.
 *
 * @return void
 */
function add_action( $hook = '', $callback = null ) {
	$GLOBALS['lichtbild_test_hooks'][] = array( 'kind' => 'action', 'hook' => (string) $hook, 'callback' => $callback );
}

/**
 * Records a filter registration.
 *
 * @param string $hook     Hook name.
 * @param mixed  $callback Callback.
 *
 * @return void
 */
function add_filter( $hook = '', $callback = null ) {
	$GLOBALS['lichtbild_test_hooks'][] = array( 'kind' => 'filter', 'hook' => (string) $hook, 'callback' => $callback );
}

/**
 * Registers a shortcode.
 *
 * @param string $tag      Shortcode tag.
 * @param mixed  $callback Callback.
 *
 * @return void
 */
function add_shortcode( $tag, $callback ) {
	$GLOBALS['lichtbild_test_shortcodes'][ (string) $tag ] = $callback;
}

/**
 * Removes a shortcode.
 *
 * @param string $tag Shortcode tag.
 *
 * @return void
 */
function remove_shortcode( $tag ) {
	unset( $GLOBALS['lichtbild_test_shortcodes'][ (string) $tag ] );
}

/**
 * Reports whether a shortcode is registered.
 *
 * @param string $tag Shortcode tag.
 *
 * @return bool True when registered.
 */
function shortcode_exists( $tag ) {
	return isset( $GLOBALS['lichtbild_test_shortcodes'][ (string) $tag ] );
}

/**
 * Merges shortcode attributes against their defaults, keeping only known keys.
 *
 * @param array  $pairs     Known attributes and their defaults.
 * @param array  $atts      Supplied attributes.
 * @param string $shortcode Shortcode name.
 *
 * @return array Merged attributes.
 */
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	unset( $shortcode );

	$atts = (array) $atts;
	$out  = array();

	foreach ( (array) $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}

	return $out;
}

/**
 * Escapes an attribute value.
 *
 * @param string $text Raw text.
 *
 * @return string Escaped text.
 */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Escapes HTML text.
 *
 * @param string $text Raw text.
 *
 * @return string Escaped text.
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Escapes a URL.
 *
 * @param string $url Raw URL.
 *
 * @return string Escaped URL.
 */
function esc_url( $url ) {
	$url = str_replace( array( ' ', '"', "'", '<', '>' ), array( '%20', '', '', '', '' ), (string) $url );

	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

/**
 * Escapes translated attribute text.
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 *
 * @return string Escaped text.
 */
function esc_attr__( $text, $domain = '' ) {
	return esc_attr( $text );
}

/**
 * Escapes translated HTML text.
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 *
 * @return string Escaped text.
 */
function esc_html__( $text, $domain = '' ) {
	return esc_html( $text );
}

/**
 * Returns translated text unchanged.
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 *
 * @return string The text.
 */
function __( $text, $domain = '' ) {
	return $text;
}

/**
 * Returns the singular or plural form.
 *
 * @param string $single Singular form.
 * @param string $plural Plural form.
 * @param int    $number Count.
 * @param string $domain Text domain.
 *
 * @return string Chosen form.
 */
function _n( $single, $plural, $number, $domain = '' ) {
	return 1 === (int) $number ? $single : $plural;
}

/**
 * Encodes a value as JSON.
 *
 * @param mixed $value Value to encode.
 *
 * @return string JSON text.
 */
function wp_json_encode( $value ) {
	return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * Strips all HTML tags.
 *
 * @param string $text Raw text.
 *
 * @return string Plain text.
 */
function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

/**
 * Plucks one key out of every element of a list.
 *
 * Handles objects as well as arrays, as WordPress does — term lists are objects, and a
 * pluck that quietly returned nulls for them would make a check about tag names pass or fail
 * for a reason that had nothing to do with tags.
 *
 * @param array  $list  List of arrays or objects.
 * @param string $field Key or property to pluck.
 *
 * @return array Plucked values.
 */
function wp_list_pluck( $list, $field ) {
	return array_map(
		static function ( $entry ) use ( $field ) {
			if ( is_object( $entry ) ) {
				return isset( $entry->$field ) ? $entry->$field : null;
			}

			return is_array( $entry ) && isset( $entry[ $field ] ) ? $entry[ $field ] : null;
		},
		$list
	);
}

/**
 * Records enqueued handles so tests can assert assets were requested exactly once.
 *
 * @var array<string,int>
 */
$GLOBALS['lichtbild_test_enqueued'] = array();

/**
 * Registered styles and scripts, keyed by handle.
 *
 * These three were no-ops until 26.8.14, with docblocks claiming they recorded something.
 * What made recording worth the lines is the block editor's stylesheet: it is registered with
 * `lichtbild` as a **dependency**, and `WP_Styles` drops a dependency that was never registered
 * without a word — so the one arrangement that breaks the editor preview is also the one that
 * produces no error anywhere. That is a fact about handles and their dependencies, which is
 * precisely what a no-op cannot hold.
 *
 * @var array<string,array{deps:array,src:string}>
 */
$GLOBALS['lichtbild_test_registered'] = array(
	'style'  => array(),
	'script' => array(),
);

/**
 * Inline scripts attached to a handle, in the order they were added.
 *
 * @var array<int,array{handle:string,data:string,position:string}>
 */
$GLOBALS['lichtbild_test_inline'] = array();

/**
 * Registered block definitions, keyed by block name.
 *
 * @var array<string,array>
 */
$GLOBALS['lichtbild_test_blocks'] = array();

/**
 * Records a registered style.
 *
 * @param string $handle Style handle.
 * @param string $src    Source URL.
 * @param array  $deps   Handles this one depends on.
 *
 * @return void
 */
function wp_register_style( $handle = '', $src = '', $deps = array() ) {
	$GLOBALS['lichtbild_test_registered']['style'][ (string) $handle ] = array(
		'src'  => (string) $src,
		'deps' => (array) $deps,
	);
}

/**
 * Records a registered script.
 *
 * @param string $handle Script handle.
 * @param string $src    Source URL.
 * @param array  $deps   Handles this one depends on.
 *
 * @return void
 */
function wp_register_script( $handle = '', $src = '', $deps = array() ) {
	$GLOBALS['lichtbild_test_registered']['script'][ (string) $handle ] = array(
		'src'  => (string) $src,
		'deps' => (array) $deps,
	);
}

/**
 * Records localized script data.
 *
 * @return void
 */
function wp_localize_script() {}

/**
 * Records an inline script attached to a handle.
 *
 * @param string $handle   Script handle.
 * @param string $data     JavaScript to print.
 * @param string $position `before` or `after`.
 *
 * @return void
 */
function wp_add_inline_script( $handle = '', $data = '', $position = 'after' ) {
	$GLOBALS['lichtbild_test_inline'][] = array(
		'handle'   => (string) $handle,
		'data'     => (string) $data,
		'position' => (string) $position,
	);
}

/**
 * Registers a block type, from a directory holding a `block.json` or from a name.
 *
 * The real function reads the metadata file and merges `$args` over it, and reading it here is
 * the point rather than a convenience: it is what makes `block.json` a file the suite can be
 * wrong about. A typo in `attributes` is otherwise invisible until an editor loads.
 *
 * @param string $name_or_path Block name, or a path to the directory holding `block.json`.
 * @param array  $args         Settings merged over the metadata.
 *
 * @return array The registered definition.
 */
function register_block_type( $name_or_path = '', $args = array() ) {
	$metadata = array();
	$path     = rtrim( (string) $name_or_path, '/' ) . '/block.json';

	if ( is_readable( $path ) ) {
		$decoded = json_decode( (string) file_get_contents( $path ), true );

		// A malformed block.json is a hard failure rather than an empty definition: an empty
		// one would register a nameless block and read as "the block is not there".
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'block.json is not valid JSON: ' . $path );
		}

		$metadata = $decoded;
	} else {
		$metadata['name'] = (string) $name_or_path;
	}

	$definition = array_merge( $metadata, (array) $args );

	$GLOBALS['lichtbild_test_blocks'][ (string) $definition['name'] ] = $definition;

	return $definition;
}

/**
 * Records an enqueued style.
 *
 * @param string $handle Style handle.
 *
 * @return void
 */
function wp_enqueue_style( $handle ) {
	$GLOBALS['lichtbild_test_enqueued'][ 'style:' . $handle ] =
		isset( $GLOBALS['lichtbild_test_enqueued'][ 'style:' . $handle ] )
			? $GLOBALS['lichtbild_test_enqueued'][ 'style:' . $handle ] + 1
			: 1;
}

/**
 * Records an enqueued script.
 *
 * @param string $handle Script handle.
 *
 * @return void
 */
function wp_enqueue_script( $handle ) {
	$GLOBALS['lichtbild_test_enqueued'][ 'script:' . $handle ] =
		isset( $GLOBALS['lichtbild_test_enqueued'][ 'script:' . $handle ] )
			? $GLOBALS['lichtbild_test_enqueued'][ 'script:' . $handle ] + 1
			: 1;
}

/**
 * Returns an admin URL.
 *
 * @param string $path Path below wp-admin.
 *
 * @return string Absolute URL.
 */
function admin_url( $path = '' ) {
	return Lichtbild_Test_Site::$instance->siteurl . '/wp-admin/' . ltrim( (string) $path, '/' );
}

/**
 * Returns a nonce derived from the action.
 *
 * Derived rather than fixed so that `wp_verify_nonce()` below can tell a nonce made for one
 * action from a nonce made for another — which is the whole property the editor's nonce is
 * carrying, since it is bound to the post ID being edited.
 *
 * @param string $action Nonce action.
 *
 * @return string Nonce value.
 */
function wp_create_nonce( $action = 'testnonce' ) {
	return 'nonce:' . $action;
}

/**
 * Verifies a nonce against an action.
 *
 * @param string $nonce  Submitted nonce.
 * @param string $action Expected action.
 *
 * @return bool True when the nonce was made for that action.
 */
function wp_verify_nonce( $nonce, $action = 'testnonce' ) {
	return (string) $nonce === 'nonce:' . $action;
}

/**
 * Prints a nonce field.
 *
 * @param string $action Nonce action.
 * @param string $name   Field name.
 *
 * @return void
 */
function wp_nonce_field( $action = '', $name = '_wpnonce' ) {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
}

/**
 * Records a metabox registration.
 *
 * @param string $id        Box ID.
 * @param string $title     Box title.
 * @param mixed  $callback  Render callback.
 * @param string $screen    Post type.
 * @param string $context   Placement.
 * @param string $priority  Ordering.
 *
 * @return void
 */
function add_meta_box( $id, $title, $callback, $screen = '', $context = 'advanced', $priority = 'default' ) {
	Lichtbild_Test_Site::$instance->meta_boxes[ $id ] = array(
		'title'    => $title,
		'screen'   => $screen,
		'context'  => $context,
		'priority' => $priority,
	);
}

/**
 * Reports whether a post is a revision.
 *
 * @param int $post_id Post ID.
 *
 * @return bool Always false; no revision is ever saved here.
 */
function wp_is_post_revision( $post_id ) {
	return false;
}

/**
 * Returns the URL of an attachment at a given size.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size          Registered size.
 *
 * @return string|false URL, or false when unavailable.
 */
function wp_get_attachment_image_url( $attachment_id, $size = 'thumbnail' ) {
	$src = wp_get_attachment_image_src( $attachment_id, $size );

	return is_array( $src ) && ! empty( $src[0] ) ? $src[0] : false;
}

/**
 * Replaces an attachment's terms in one taxonomy.
 *
 * Writes where `get_the_terms()` reads, so a tag written by the editor is a tag the reader
 * finds — and a tag the editor fails to write is one it does not.
 *
 * @param int      $object_id Attachment ID.
 * @param string[] $terms     Term names.
 * @param string   $taxonomy  Taxonomy name.
 *
 * @return array The stored term names.
 */
function wp_set_object_terms( $object_id, $terms, $taxonomy ) {
	$site = Lichtbild_Test_Site::$instance;

	if ( $site->tag_taxonomy() !== $taxonomy ) {
		return array();
	}

	$stored = array();

	foreach ( (array) $terms as $name ) {
		$stored[] = array(
			'slug' => sanitize_title( $name ),
			'name' => (string) $name,
		);
	}

	$site->attachments[ (int) $object_id ]['tags'] = $stored;

	return (array) $terms;
}

/**
 * Returns the registered intermediate image sizes.
 *
 * @return string[] Size names.
 */
function get_intermediate_image_sizes() {
	return array( 'thumbnail', 'medium', 'medium_large', 'large' );
}

/**
 * Returns the admin screen this request is on.
 *
 * Core declares this in `wp-admin/includes/screen.php`, so it exists on every admin request
 * and on no other -- which is exactly when the editors ask, and why they call it bare rather
 * than behind a `function_exists()` guard claiming some request gets there without the admin
 * loaded. Answers from the site object rather than a hardcoded null so that a check about the
 * editors' asset enqueue has something to set.
 *
 * @return object|null The screen, or null when this is not an admin page load.
 */
function get_current_screen() {
	return Lichtbild_Test_Site::$instance->current_screen;
}

/**
 * Enqueues the media library; a no-op here.
 *
 * @return void
 */
function wp_enqueue_media() {}

/**
 * Escapes text for a textarea.
 *
 * @param string $text Raw text.
 *
 * @return string Escaped text.
 */
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Returns a selected attribute when two values match.
 *
 * @param mixed $selected Current value.
 * @param mixed $current  Value being rendered.
 * @param bool  $echo     Whether to print.
 *
 * @return string The attribute, or an empty string.
 */
function selected( $selected, $current = true, $echo = true ) {
	$out = (string) $selected === (string) $current ? ' selected="selected"' : '';

	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $out;
}

/**
 * Returns a checked attribute when two values match.
 *
 * @param mixed $checked Current value.
 * @param mixed $current Value being rendered.
 * @param bool  $echo    Whether to print.
 *
 * @return string The attribute, or an empty string.
 */
function checked( $checked, $current = true, $echo = true ) {
	$out = $checked === $current ? ' checked="checked"' : '';

	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $out;
}

/**
 * Returns a disabled attribute when a value is truthy.
 *
 * @param mixed $disabled Current value.
 * @param mixed $current  Value being rendered.
 * @param bool  $echo     Whether to print.
 *
 * @return string The attribute, or an empty string.
 */
function disabled( $disabled, $current = true, $echo = true ) {
	$out = $disabled === $current ? ' disabled="disabled"' : '';

	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $out;
}

/**
 * Prints a submit button.
 *
 * @param string $text       Button label.
 * @param string $type       Button class.
 * @param string $name       Field name.
 * @param bool   $wrap       Whether to wrap it in a paragraph.
 * @param array  $attributes Extra attributes.
 *
 * @return void
 */
function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true, $attributes = array() ) {
	$rendered = '';

	foreach ( (array) $attributes as $attribute => $value ) {
		$rendered .= ' ' . $attribute . '="' . esc_attr( $value ) . '"';
	}

	$button = '<input type="submit" name="' . esc_attr( $name ) . '" class="button button-' . esc_attr( $type )
		. '" value="' . esc_attr( $text ) . '"' . $rendered . ' />';

	echo $wrap ? '<p class="submit">' . $button . '</p>' : $button; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Prints translated, escaped text.
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 *
 * @return void
 */
function esc_html_e( $text, $domain = '' ) {
	echo esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Sanitises a string into a slug.
 *
 * Core reaches `remove_accents()` before it does anything else, and that opens with a
 * `preg_match()` on the value — so on PHP 8 an array argument is an uncaught `TypeError`
 * rather than a slug. Modelled here rather than tolerated, for the usual reason: a stub that
 * quietly casts makes the two public AJAX endpoints look safe against `tag[]=x`, which is the
 * one input a logged-out visitor fully controls, and a check written against such a stub
 * cannot fail. Scalars and `__toString()` objects coerce in core and coerce here.
 *
 * @param string $title Raw title.
 *
 * @throws TypeError When the value is one core's own `preg_match()` would refuse.
 *
 * @return string Slug.
 */
function sanitize_title( $title ) {
	if ( is_array( $title ) || ( is_object( $title ) && ! method_exists( $title, '__toString' ) ) ) {
		throw new TypeError(
			'preg_match(): Argument #2 ($subject) must be of type string, ' . gettype( $title ) . ' given'
		);
	}

	$title = strtolower( trim( (string) $title ) );

	return preg_replace( '/[^a-z0-9_-]+/', '-', $title );
}

/**
 * Removes slashes WordPress would have added.
 *
 * @param string $value Raw value.
 *
 * @return string Unslashed value.
 */
function wp_unslash( $value ) {
	// Recursive, because core's is: `wp_unslash()` is `stripslashes_deep()`, and the values
	// this plugin hands the metadata layer are nested arrays. A version that only touched
	// strings would leave every caption in an item list untouched and quietly model the
	// opposite of what WordPress does.
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * Adds slashes the way core does, so a value survives the metadata layer's unslash.
 *
 * @param mixed $value Value to slash.
 *
 * @return mixed Slashed value.
 */
function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}

	return is_string( $value ) ? addslashes( $value ) : $value;
}

/**
 * Verifies an AJAX nonce, and halts on failure unless the caller asked it not to.
 *
 * **This used to return `true` unconditionally, and a stub that always says yes models code
 * that cannot get the answer wrong.** Every check that reached an endpoint through it passed
 * without ever setting a nonce, so the suite could not tell a handler that refuses a bad nonce
 * from one that never looks — which is the exact distinction the front-end endpoints and the
 * album cover endpoint now sit on opposite sides of. The same shape as the other stub defects
 * this suite has paid for: the harness answering for WordPress more agreeably than WordPress
 * does.
 *
 * `$stop` is modelled because it is the whole point of the argument. Real WordPress calls
 * `wp_die( -1, 403 )` here, so a refusal halts with `die:403` — deliberately not the
 * `error 403` an authorization refusal produces, so an assertion can say which gate answered.
 *
 * @param string|int $action    Nonce action.
 * @param string     $query_arg Request key carrying the nonce.
 * @param bool       $stop      Whether to halt when it does not verify.
 *
 * @return bool Whether the nonce verified.
 */
function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	$key      = $query_arg ? $query_arg : '_ajax_nonce';
	$nonce    = isset( $_REQUEST[ $key ] ) ? $_REQUEST[ $key ] : '';
	$verified = wp_verify_nonce( $nonce, $action );

	if ( ! $verified && $stop ) {
		wp_die( -1, '', array( 'response' => 403 ) );
	}

	return $verified;
}

/**
 * Answers a capability question, per object where a check has said so.
 *
 * Defaults to the site-wide `capabilities` flag: false, matching the logged-out visitor the
 * AJAX authorization checks model. The admin-screen checks raise it deliberately and put it
 * back.
 *
 * `capability_overrides` exists because a single boolean cannot express a user who may edit a
 * gallery but not one of the attachments in it, and a guard for exactly that case would then
 * be untestable — it would pass whether or not it existed, which is the failure mode this
 * suite is built to refuse.
 *
 * @param string $capability Capability being tested.
 * @param mixed  ...$args    Object the capability is tested against, where there is one.
 *
 * @return bool Whether the current user has the capability.
 */
function current_user_can( $capability = '', ...$args ) {
	$site      = Lichtbild_Test_Site::$instance;
	$overrides = $site->capability_overrides;

	// Most specific first: `edit_post:123` beats `edit_post`, which beats the blanket flag.
	if ( ! empty( $args ) && is_numeric( $args[0] ) ) {
		$specific = $capability . ':' . (int) $args[0];

		if ( array_key_exists( $specific, $overrides ) ) {
			return (bool) $overrides[ $specific ];
		}
	}

	if ( array_key_exists( (string) $capability, $overrides ) ) {
		return (bool) $overrides[ (string) $capability ];
	}

	return (bool) $site->capabilities;
}

/**
 * Sends a success payload and stops.
 *
 * @param mixed $data Payload.
 *
 * @return void
 */
function wp_send_json_success( $data = null ) {
	header( 'Content-Type: application/json' );
	echo wp_json_encode(
		array(
			'success' => true,
			'data'    => $data,
		)
	);

	// Thrown rather than `exit`, so a check can call an endpoint and inspect what it sent.
	// With `exit` the first AJAX assertion would end the whole run, and a suite that stops
	// early looks a lot like one that passed.
	throw new Lichtbild_Test_Halt( 'success' );
}

/**
 * Sends an error payload and stops.
 *
 * @param mixed $data   Payload.
 * @param int   $status HTTP status code.
 *
 * @return void
 */
function wp_send_json_error( $data = null, $status = 400 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo wp_json_encode(
		array(
			'success' => false,
			'data'    => $data,
		)
	);

	throw new Lichtbild_Test_Halt( 'error ' . (int) $status );
}

/**
 * Applies the post-content markup allowlist.
 *
 * A stand-in for `wp_kses_post()`, and the attribute pass is the part that matters:
 * `strip_tags()` alone keeps `<a onclick="...">` intact, because it filters tags and has
 * never filtered attributes. A stub built on `strip_tags()` alone is therefore *weaker*
 * than the function it stands in for, and would report a caption test failing here that
 * real WordPress passes — which is how a stub turns into a source of false findings.
 *
 * Still an approximation: real kses is far more thorough (protocol checks, entity
 * normalisation, nesting repair). It errs strict, so anything surviving here would survive
 * there too.
 *
 * @param string $text Raw text.
 *
 * @return string Filtered text.
 */
function wp_kses_post( $text ) {
	$text = strip_tags( (string) $text, '<em><strong><b><i><a><br><span><p><code>' );

	// Drop every attribute that is not on the allowlist, which is what separates this from
	// strip_tags() and what the caption tests are actually about.
	return preg_replace_callback(
		'#<([a-z][a-z0-9]*)\b([^>]*)>#i',
		static function ( $match ) {
			$tag  = strtolower( $match[1] );
			$kept = '';

			if ( 'a' === $tag && preg_match( '#\bhref\s*=\s*"([^"]*)"#i', $match[2], $href ) ) {
				$url = esc_url_raw( $href[1] );

				if ( '' !== $url ) {
					$kept = ' href="' . $url . '"';
				}
			}

			return '<' . $tag . $kept . '>';
		},
		$text
	);
}

/**
 * Validates a URL for storage, rejecting schemes not on the allowlist.
 *
 * @param string   $url      Raw URL.
 * @param string[] $protocols Allowed schemes.
 *
 * @return string The URL, or an empty string when its scheme is not allowed.
 */
function esc_url_raw( $url, $protocols = null ) {
	$url       = trim( (string) $url );
	$protocols = is_array( $protocols ) ? $protocols : array( 'http', 'https' );

	if ( '' === $url ) {
		return '';
	}

	// A scheme-relative or root-relative URL carries no scheme to object to.
	if ( 0 === strpos( $url, '//' ) || 0 === strpos( $url, '/' ) ) {
		return $url;
	}

	$scheme = strtolower( (string) wp_parse_url_scheme( $url ) );

	if ( '' === $scheme || ! in_array( $scheme, $protocols, true ) ) {
		return '';
	}

	return $url;
}

/**
 * Returns the scheme of a URL, tolerating the obfuscations a hostile value may use.
 *
 * @param string $url Raw URL.
 *
 * @return string Scheme without the colon, empty when there is none.
 */
function wp_parse_url_scheme( $url ) {
	// Strip the control characters and whitespace a browser would ignore before parsing,
	// so "java\nscript:alert(1)" is not read as a scheme-less URL.
	$clean = preg_replace( '/[\x00-\x20\x7f]/', '', (string) $url );

	return preg_match( '#^([a-z][a-z0-9+.-]*):#i', $clean, $match ) ? $match[1] : '';
}

/**
 * Stops the request, as `wp_die()` does, in a form a test can catch.
 *
 * @param string $message Message.
 * @param string $title   Title.
 * @param array  $args    Arguments, including the HTTP response code.
 *
 * @throws Lichtbild_Test_Halt Always, carrying the response code.
 *
 * @return void
 */
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Lichtbild_Test_Halt( 'die:' . ( isset( $args['response'] ) ? $args['response'] : 0 ) );
}

/**
 * Stands in for a redirect, which is followed by `exit` in the code under test.
 *
 * @param string $location Target URL.
 *
 * @throws Lichtbild_Test_Halt Always.
 *
 * @return void
 */
function wp_safe_redirect( $location ) {
	throw new Lichtbild_Test_Halt( 'redirect' );
}

/**
 * Raised in place of the request ending, so a test can tell which way it ended.
 */
class Lichtbild_Test_Halt extends RuntimeException {}

/**
 * Verifies an admin nonce; accepts whatever the fixture is set to expect.
 *
 * @return bool Always true.
 */
function check_admin_referer() {
	return true;
}

/**
 * Returns the current user ID.
 *
 * @return int Always 1.
 */
function get_current_user_id() {
	return 1;
}

/**
 * Stores a transient.
 *
 * @param string $key   Transient name.
 * @param mixed  $value Value.
 *
 * @return bool Always true.
 */
function set_transient( $key, $value ) {
	Lichtbild_Test_Site::$instance->options[ 'transient:' . $key ] = $value;

	return true;
}

/**
 * Reads a transient.
 *
 * @param string $key Transient name.
 *
 * @return mixed Value, or false when absent.
 */
function get_transient( $key ) {
	$site = Lichtbild_Test_Site::$instance;

	return array_key_exists( 'transient:' . $key, $site->options ) ? $site->options[ 'transient:' . $key ] : false;
}

/**
 * Removes a transient.
 *
 * @param string $key Transient name.
 *
 * @return bool Always true.
 */
function delete_transient( $key ) {
	unset( Lichtbild_Test_Site::$instance->options[ 'transient:' . $key ] );

	return true;
}

/**
 * Sanitises a scalar field.
 *
 * @param string $value Raw value.
 *
 * @return string Trimmed value with tags removed.
 */
function sanitize_text_field( $value ) {
	return trim( wp_strip_all_tags( (string) $value ) );
}

/**
 * Records a post type registration.
 *
 * @param string $name Post type name.
 * @param array  $args Registration arguments.
 *
 * @return void
 */
function register_post_type( $name, $args = array() ) {
	$site                         = Lichtbild_Test_Site::$instance;
	$site->registered[]           = $name;
	$site->rewrite_slugs[ $name ] = isset( $args['rewrite']['slug'] ) ? $args['rewrite']['slug'] : '';
	$site->menu_parents[ $name ]  = isset( $args['show_in_menu'] ) ? $args['show_in_menu'] : '';
}

/**
 * Records a taxonomy registration.
 *
 * @param string $name       Taxonomy name.
 * @param mixed  $object_type Object type it applies to.
 * @param array  $args       Registration arguments.
 *
 * @return void
 */
function register_taxonomy( $name, $object_type = '', $args = array() ) {
	$site                 = Lichtbild_Test_Site::$instance;
	$site->registered[]   = $name;
	$site->rewrite_slugs[ $name ] = isset( $args['rewrite']['slug'] ) ? $args['rewrite']['slug'] : '';
}

/**
 * Reports whether a singular post is being viewed; always false in these tests.
 *
 * @return bool Always false.
 */
function is_singular( $post_type = '' ) {
	$site = Lichtbild_Test_Site::$instance;

	if ( $site->current_post <= 0 ) {
		return false;
	}

	return '' === $post_type || get_post_type( $site->current_post ) === $post_type;
}

/**
 * Reports whether the main loop is running; true whenever a post is being rendered.
 *
 * @return bool True during a simulated singular request.
 */
function in_the_loop() {
	return Lichtbild_Test_Site::$instance->current_post > 0;
}

/**
 * Reports whether this is the main query.
 *
 * @return bool True during a simulated singular request.
 */
function is_main_query() {
	return Lichtbild_Test_Site::$instance->current_post > 0;
}

/**
 * Returns the ID of the post being rendered.
 *
 * @return int Post ID, or 0.
 */
function get_the_ID() {
	return Lichtbild_Test_Site::$instance->current_post;
}

/**
 * Reports whether a post still needs its password entered.
 *
 * Driven by a list rather than hardcoded, so the guard that reads it can be shown to fail:
 * a stub that always said false would make the check pass whether the guard exists or not.
 *
 * @param mixed $post Post object, ID, or null for the current one.
 *
 * @return bool True when the password has not been entered.
 */
function post_password_required( $post = null ) {
	$site = Lichtbild_Test_Site::$instance;

	if ( is_object( $post ) && isset( $post->ID ) ) {
		$id = (int) $post->ID;
	} elseif ( is_numeric( $post ) ) {
		$id = (int) $post;
	} else {
		$id = $site->current_post;
	}

	return in_array( $id, $site->protected, true );
}

/**
 * A post object.
 *
 * Real enough for the one production `instanceof WP_Post` check, which is what stops
 * `Lichtbild_Assets::maybe_enqueue_early()` reading `post_content` off a null.
 */
class WP_Post {

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	public $ID = 0;

	/**
	 * Post content.
	 *
	 * @var string
	 */
	public $post_content = '';
}

/**
 * Returns a post object for the given ID, or the one being viewed.
 *
 * @param mixed $post Post ID, or null for the current one.
 *
 * @return WP_Post|null The post, or null when no post is being viewed.
 */
function get_post( $post = null ) {
	$site = Lichtbild_Test_Site::$instance;
	$id   = is_numeric( $post ) ? (int) $post : $site->current_post;

	if ( $id <= 0 ) {
		return null;
	}

	$out               = new WP_Post();
	$out->ID           = $id;
	$out->post_content = isset( $site->post_content[ $id ] ) ? (string) $site->post_content[ $id ] : '';

	return $out;
}

/**
 * Reports whether content contains a shortcode.
 *
 * @param string $content   Post content.
 * @param string $shortcode Shortcode tag.
 *
 * @return bool True when present.
 */
function has_shortcode( $content, $shortcode ) {
	return false !== strpos( (string) $content, '[' . $shortcode );
}

/**
 * Returns a plugin's path relative to the plugins directory.
 *
 * @param string $file Absolute plugin file path.
 *
 * @return string Relative path.
 */
function plugin_basename( $file ) {
	return 'lichtbild/' . basename( (string) $file );
}
