<?php
/**
 * Plugin options and the settings screen.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and presents the one decision Lichtbild needs from the site owner.
 *
 * That decision is when to take the `[envira-gallery]` shortcode over. Both plugins can be
 * installed at once — they read the same rows and neither writes to the other's — so the
 * useful default is to defer to Envira while it is active and step in the moment it is
 * switched off. That makes the comparison a single toggle in the plugins screen, and makes
 * backing out of Lichtbild exactly as cheap.
 */
class Lichtbild_Settings {

	/**
	 * Option name holding the takeover mode.
	 */
	const OPTION_TAKEOVER = 'lichtbild_takeover';

	/**
	 * Option recording which storage schema the site's galleries are on.
	 */
	const OPTION_SCHEMA = 'lichtbild_schema_version';

	/**
	 * Schema version written by the migration to Lichtbild's own post types.
	 */
	const SCHEMA_MIGRATED = 2;

	/**
	 * Option recording whether a gallery renders on its own permalink.
	 */
	const OPTION_STANDALONE = 'lichtbild_standalone';

	/**
	 * Envira's equivalent option, read until the migration copies it across.
	 */
	const OPTION_STANDALONE_ENVIRA = 'envira_gallery_standalone_enabled';

	/**
	 * Option recording which set of URL paths this site serves its galleries from.
	 *
	 * Note this is an OPTION and `lichtbild_url_slugs` is a FILTER; they are deliberately not
	 * named the same thing. The option records what the site decided, the filter overrides it.
	 */
	const OPTION_SLUG_SCHEME = 'lichtbild_slug_scheme';

	/**
	 * The migration section rendered below the settings form.
	 *
	 * @var Lichtbild_Migration_Screen|null
	 */
	private $migration_screen = null;

	/**
	 * Whether `initialise()` has run on this instance.
	 *
	 * Per request, not per site. The option it writes is the durable answer, but an un-migrated
	 * Envira site has no schema option -- nothing writes `1`, and the migration is what writes
	 * `2` -- so without this every predicate call re-asked `has_owned_content()`: measured at 17
	 * uncached `COUNT(*)` queries on `wp_posts` for one minimal request, in the configuration
	 * the plugin documents as "install beside Envira and change nothing".
	 *
	 * @var bool
	 */
	private $initialised = false;

	/**
	 * Returns the URL paths this site serves galleries, albums and tag archives from.
	 *
	 * **Decided once and written down, never re-derived.** The derivation asks whether the site
	 * has an Envira history, and a site's answer to that changes over time — Envira's records
	 * can be deleted long after the migration — while its published URLs must not. Deriving on
	 * every request would therefore move 57 indexed URLs the day somebody tidied up an old post
	 * meta key, with no action that looks anything like a permalink change. So the first request
	 * that asks records the answer, and every later one reads it.
	 *
	 * The default for a site with no such history is generic. A fresh install has no reason to
	 * publish its galleries under a path named after another company's product, and doing so
	 * would be the single most likely thing for a plugin reviewer to object to.
	 *
	 * @return array Paths keyed by `gallery`, `album` and `tag`.
	 */
	public function slug_scheme_paths() {
		return 'envira' === $this->slug_scheme()
			? Lichtbild_Post_Types::SLUGS_ENVIRA
			: Lichtbild_Post_Types::SLUGS_GENERIC;
	}

	/**
	 * Reports whether this site is continuing an Envira installation.
	 *
	 * The question "is there anything to roll back TO", asked once and named, because two places
	 * need it and a chooser that merely declines to offer an action is markup rather than a rule.
	 *
	 * @return bool True when the site has an Envira history.
	 */
	public function continues_envira() {
		return 'envira' === $this->slug_scheme();
	}

	/**
	 * Returns the recorded slug scheme, deciding and storing it on first use.
	 *
	 * @return string Either `envira` or `generic`.
	 */
	public function slug_scheme() {
		$this->initialise();

		return 'generic' === get_option( self::OPTION_SLUG_SCHEME, '' ) ? 'generic' : 'envira';
	}

	/**
	 * Reports whether Envira ever stored anything on this site.
	 *
	 * Deliberately a bare query calling nothing else: it is the single observation both stored
	 * answers are derived from, and anything it called would be something that reads one of them.
	 *
	 * @return bool True when Envira gallery or album records exist.
	 */
	private function has_envira_history() {
		global $wpdb;

		// COUNT(*) rather than `SELECT meta_id ... LIMIT 1`, and the reason is worth keeping:
		// `get_var()` answers null for no rows and the column value otherwise, so a null check
		// reads correctly against real WordPress and yet cannot be modelled by a stub that
		// answers a count -- which is exactly what this project's stub does, correctly, for
		// every other query it serves. A count is unambiguous to both.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- asked once per site, and its answer is then stored in an option and never re-derived; that option IS the cache. A meta_key existence test has no WP_Query form that does not load every matching post.
		$found = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ( '_eg_gallery_data', '_eg_album_data' )"
		);

		return (int) $found > 0;
	}

	/**
	 * Decides, once, what kind of site this is — and records both consequences together.
	 *
	 * **The two answers have to be written from the same observation, and an earlier draft that
	 * derived them separately was wrong in a way worth recording.** Schema initialisation ran
	 * first and set a fresh site to `SCHEMA_MIGRATED`; the slug scheme then asked
	 * `has_migrated()`, saw `true`, and concluded the site had migrated *from Envira* — so a
	 * brand-new install got Envira's URL paths, which is the precise opposite of the intent. The
	 * signal was ambiguous because "migrated" had come to mean two different things.
	 *
	 * What matters about schema 1 is that it does not mean "new". It means *still on Envira's
	 * storage*: sensible for a site mid-migration, nonsensical for one that has never had Envira.
	 * Left at 1, a fresh install registered post types literally named `envira`, `envira_album`
	 * and `envira-tag` — visible in admin URLs, body classes and sitemap filenames — while every
	 * editor screen refused to work, telling the owner their gallery was "still stored in Envira
	 * Gallery's format" on a site where no such record has ever existed. The only route to a
	 * first gallery was to run a migration of zero rows.
	 *
	 * Guards, all load-bearing:
	 *
	 * - It does nothing once the slug scheme is recorded, which is what makes it idempotent and
	 *   what stops it re-deciding when Envira's records are deleted years later.
	 * - A schema already at or past `SCHEMA_MIGRATED` counts as an Envira history on its own,
	 *   because a site only reaches that by migrating from Envira. Read from the raw option
	 *   rather than through `has_migrated()`, so this cannot observe its own writes.
	 * - It advances the schema ONLY when that option has never been set, so it can never
	 *   overrule a site sitting deliberately at 1 while its owner prepares to migrate.
	 *
	 * @return void
	 */
	private function initialise() {
		// Once per instance, and an instance lives one request. Everything below is either a
		// cached option read or a write that must not repeat; the one uncached query,
		// `has_owned_content()`, is the reason this guard exists.
		if ( $this->initialised ) {
			return;
		}

		$this->initialised = true;

		$schema = get_option( self::OPTION_SCHEMA, null );

		/*
		 * Content this plugin owns outranks every inference below it, and this runs BEFORE the
		 * early return rather than after, which is not a style choice.
		 *
		 * Earlier uninstall versions deleted the schema and slug scheme while keeping the
		 * migrated posts and their meta -- settings are a plugin's to remove, photographs are
		 * not. Reinstalling then landed here with no schema, found Envira's retained
		 * `_eg_*` meta, concluded "a site with an Envira history that has not migrated", and
		 * left the schema absent. `has_migrated()` answered false, `register_types()` registered
		 * `envira`/`envira_album`/`envira-tag`, and every retained row -- still named
		 * `lichtbild_gallery` -- became invisible. Measured on a full copy of the live site:
		 * 52 galleries in the table, 0 under the registered type, and the gallery permalink
		 * returning the same byte count as a deliberately bogus slug. That is the exact opposite
		 * of the promise `uninstall.php` makes, which is that reinstalling brings them back.
		 *
		 * After the first call had recorded a scheme, the early return made it permanent: the
		 * schema could never be restored on any later request. So the repair has to happen
		 * before that return, not inside the branch it guards.
		 *
		 * Only when the schema has never been set, which is the same guard the write below
		 * uses: a site sitting deliberately at 1 while its owner prepares to migrate must not be
		 * overruled, and a half-finished migration has the option set and is handled by the
		 * mixed-state machinery instead.
		 */
		if ( null === $schema && $this->has_owned_content() ) {
			update_option( self::OPTION_SCHEMA, self::SCHEMA_MIGRATED );

			$schema = (string) self::SCHEMA_MIGRATED;
		}

		$scheme = get_option( self::OPTION_SLUG_SCHEME, '' );

		if ( 'envira' === $scheme || 'generic' === $scheme ) {
			return;
		}

		$history = ( null !== $schema && (int) $schema >= self::SCHEMA_MIGRATED )
			|| $this->has_envira_history();

		update_option( self::OPTION_SLUG_SCHEME, $history ? 'envira' : 'generic' );

		if ( ! $history && null === $schema ) {
			update_option( self::OPTION_SCHEMA, self::SCHEMA_MIGRATED );
		}
	}

	/**
	 * Reports whether any post this plugin owns exists, regardless of what the options say.
	 *
	 * The type names are written literally for the same reason `has_envira_history()` writes
	 * Envira's meta keys literally, and for one more: `Lichtbild_Post_Types::gallery_type()`
	 * asks `has_migrated()`, which is what calls this, so reading the names through that class
	 * would be circular. `tests/render-test.php` asserts these two strings against
	 * `Lichtbild_Post_Types::GALLERY` and `::ALBUM`, because a literal that drifts from the
	 * constant it copies would make this answer false on a site full of galleries -- which is
	 * the failure it exists to prevent, arriving by another route.
	 *
	 * @return bool True when the site holds galleries or albums under Lichtbild's own types.
	 */
	private function has_owned_content() {
		global $wpdb;

		// COUNT(*) rather than a LIMIT 1 probe, for the reason spelled out in
		// `has_envira_history()`: a count is unambiguous to real WordPress and to the stub.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- asked only while the schema option is absent, and at most once per request: `initialise()` memoises on the instance, because an un-migrated Envira site keeps that option absent until it migrates and this used to run on every predicate call.
		$found = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( 'lichtbild_gallery', 'lichtbild_album' )"
		);

		return (int) $found > 0;
	}

	/**
	 * Reports whether a gallery renders on its own permalink.
	 *
	 * Lichtbild's own option wins; Envira's is the fallback, because before the migration this
	 * has to behave exactly as the site already does — the switch is meant to be invisible,
	 * and `/envira/<slug>/` is indexed. Once migrated the value has been copied across, so
	 * uninstalling Envira cannot take the setting with it and blank every gallery page.
	 *
	 * @return bool True when a gallery renders on its own permalink.
	 */
	public function standalone() {
		$own = get_option( self::OPTION_STANDALONE, null );

		if ( null !== $own && '' !== $own ) {
			return (bool) $own;
		}

		/*
		 * Both sentences above are about a site that HAS an Envira history, and a site without
		 * one inherited their conclusion by accident: there is no Envira option to read, so the
		 * fallback returned Envira's own default of off, and a brand-new gallery's permalink
		 * answered 200 with the title and no photographs on it. Nothing had copied a value
		 * across, because there had been no migration to copy one.
		 *
		 * So the fallback is asked only where it means something. A site on Lichtbild's own
		 * storage from the start renders a gallery on its own permalink, which is the only
		 * defensible answer for a public post type that WordPress offers a "View" link for.
		 */
		if ( ! $this->continues_envira() ) {
			return true;
		}

		return (bool) get_option( self::OPTION_STANDALONE_ENVIRA, false );
	}

	/**
	 * Gives the settings screen the migration section to render.
	 *
	 * Injected rather than constructed here because the migration screen needs the migrator,
	 * which needs these settings — building it inside would be a cycle. It is optional, so
	 * this class stays usable on its own.
	 *
	 * @param Lichtbild_Migration_Screen $screen The migration section.
	 *
	 * @return void
	 */
	public function set_migration_screen( Lichtbild_Migration_Screen $screen ) {
		$this->migration_screen = $screen;
	}

	/**
	 * Reports whether the data has been migrated onto Lichtbild's own post types.
	 *
	 * Everything that names a post type has to agree with this, so it is a single stored
	 * answer rather than something inferred by looking for rows — a site mid-migration, or
	 * one where the migration failed partway, would give two different answers to a probe.
	 *
	 * The one exception is the option being absent altogether, which `initialise()` resolves
	 * from the rows once and then stores: that is the uninstall-and-reinstall case, where the
	 * settings are gone and the photographs are not. A stored answer is still what this reads.
	 *
	 * @return bool True once the migration has completed.
	 */
	public function has_migrated() {
		$this->initialise();

		return (int) get_option( self::OPTION_SCHEMA, 1 ) >= self::SCHEMA_MIGRATED;
	}

	/**
	 * Hooks the settings screen.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LICHTBILD_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Returns the configured takeover mode.
	 *
	 * @return string One of `auto`, `always` or `never`.
	 */
	public function takeover() {
		$value = get_option( self::OPTION_TAKEOVER, 'auto' );

		return in_array( $value, array( 'auto', 'always', 'never' ), true ) ? $value : 'auto';
	}

	/**
	 * Reports whether Lichtbild should handle the Envira shortcodes on this request.
	 *
	 * The setting stops applying once the data has moved, and that is not a convenience —
	 * after migration the rows say `lichtbild_gallery`, so Envira cannot find them whatever the
	 * setting says. Deferring to it would render nothing at all. The choice of who handles
	 * the shortcode is only a real choice while both plugins can still read the same rows.
	 *
	 * @return bool True when Lichtbild owns `[envira-gallery]` and `[envira-album]`.
	 */
	public function should_take_over() {
		if ( $this->has_migrated() ) {
			return true;
		}

		$mode = $this->takeover();

		if ( 'always' === $mode ) {
			return true;
		}

		if ( 'never' === $mode ) {
			return false;
		}

		return ! $this->envira_is_active();
	}

	/**
	 * Reports whether Lichtbild should answer to Envira's shortcode names.
	 *
	 * Taking over `[envira-gallery]` is the whole point on a site continuing an Envira
	 * installation, and it is meaningless on a site that never had one — there is no such
	 * shortcode in any post, and registering the name anyway claims another plugin's tag on a
	 * site with no reason to carry it. So the takeover mode decides *whether to stand aside for
	 * a running Envira*, and the slug scheme decides *whether Envira is part of this site's
	 * history at all*; both have to say yes.
	 *
	 * The scheme is the right second half rather than a fresh query, because it is the same
	 * observation the URL paths are already pinned to: recorded once, and deliberately not
	 * re-derived when Envira's records are deleted years later. A gallery's shortcode and its
	 * permalink must not answer that question differently.
	 *
	 * The one case this gives up: Envira active but having never stored a gallery reads as
	 * `generic`, so `[envira-gallery]` is left alone. It has no gallery to render either way.
	 *
	 * @return bool True when Envira's shortcode names should resolve to Lichtbild.
	 */
	public function claims_envira_shortcodes() {
		return $this->should_take_over() && 'envira' === $this->slug_scheme();
	}

	/**
	 * Reports whether Envira Gallery is active.
	 *
	 * @return bool True when the Envira plugin is running.
	 */
	public function envira_is_active() {
		return class_exists( 'Envira_Gallery' ) || defined( 'ENVIRA_VERSION' );
	}

	/**
	 * Registers the option with the settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'lichtbild',
			self::OPTION_TAKEOVER,
			array(
				'type'              => 'string',
				'default'           => 'auto',
				'sanitize_callback' => array( $this, 'sanitize_takeover' ),
			)
		);
	}

	/**
	 * Sanitises the takeover option.
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return string A valid takeover mode.
	 */
	public function sanitize_takeover( $value ) {
		return in_array( $value, array( 'auto', 'always', 'never' ), true ) ? $value : 'auto';
	}

	/**
	 * Adds the settings page under the Settings menu.
	 *
	 * @return void
	 */
	public function register_page() {
		add_options_page(
			__( 'Lichtbild', 'lichtbild-gallery' ),
			__( 'Lichtbild', 'lichtbild-gallery' ),
			'manage_options',
			'lichtbild',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a settings link to the plugin row.
	 *
	 * @param string[] $links Existing action links.
	 *
	 * @return string[] Action links with the settings link prepended.
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=lichtbild' );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'lichtbild-gallery' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Renders the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$mode     = $this->takeover();
		$active   = $this->envira_is_active();
		$migrated = $this->has_migrated();
		$counts   = wp_count_posts( Lichtbild_Post_Types::gallery_type( $this ) );
		$total    = 0;

		if ( is_object( $counts ) ) {
			foreach ( array( 'publish', 'private', 'draft' ) as $status ) {
				$total += isset( $counts->$status ) ? (int) $counts->$status : 0;
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Lichtbild', 'lichtbild-gallery' ); ?></h1>

			<p>
				<?php
				printf(
					/* translators: %d: number of galleries found. */
					esc_html( _n( 'Reading %d gallery.', 'Reading %d galleries.', $total, 'lichtbild-gallery' ) ),
					(int) $total
				);
				?>
				<?php if ( $active ) : ?>
					<strong><?php esc_html_e( 'Envira Gallery is currently active.', 'lichtbild-gallery' ); ?></strong>
				<?php else : ?>
					<strong><?php esc_html_e( 'Envira Gallery is not active.', 'lichtbild-gallery' ); ?></strong>
				<?php endif; ?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( 'lichtbild' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Handle Envira shortcodes', 'lichtbild-gallery' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION_TAKEOVER ); ?>"
										value="auto" <?php checked( $mode, 'auto' ); ?> />
									<?php esc_html_e( 'Automatically — only when Envira Gallery is deactivated', 'lichtbild-gallery' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION_TAKEOVER ); ?>"
										value="always" <?php checked( $mode, 'always' ); ?> />
									<?php esc_html_e( 'Always — render every gallery with Lichtbild, even if Envira is active', 'lichtbild-gallery' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION_TAKEOVER ); ?>"
										value="never" <?php checked( $mode, 'never' ); ?> />
									<?php esc_html_e( 'Never — only the [lichtbild-gallery] shortcode uses Lichtbild', 'lichtbild-gallery' ); ?>
								</label>
							</fieldset>
							<p class="description">
								<?php if ( $migrated ) : ?>
									<?php esc_html_e( 'This setting no longer applies. The galleries have been migrated to Lichtbild\'s own storage, so Envira cannot read them whatever it is set to, and Lichtbild handles both shortcodes.', 'lichtbild-gallery' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Lichtbild reads the same gallery records Envira wrote and never modifies them, so switching between the two is lossless in both directions.', 'lichtbild-gallery' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php
			if ( null !== $this->migration_screen ) {
				$this->migration_screen->render();
			}
			?>

			<h2><?php esc_html_e( 'Shortcodes', 'lichtbild-gallery' ); ?></h2>
			<p>
				<code>[lichtbild-gallery id="123"]</code>
				<?php esc_html_e( 'renders a gallery, and always uses Lichtbild regardless of the setting above.', 'lichtbild-gallery' ); ?>
			</p>
			<p>
				<code>[lichtbild-album id="123"]</code>
				<?php esc_html_e( 'renders an album as a grid of gallery covers.', 'lichtbild-gallery' ); ?>
			</p>
		</div>
		<?php
	}
}
