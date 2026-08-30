<?php
/**
 * Plugin container: constructs the collaborators and wires them to WordPress.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns object construction and hook registration for the whole plugin.
 *
 * Nothing else in Lichtbild calls `add_action`/`add_filter` at construction time, so this
 * class is the single place to read to learn what the plugin does to a site.
 */
class Lichtbild {

	/**
	 * Reads galleries and albums out of whichever storage this site is on -- Envira's records
	 * before the migration, Lichtbild's own afterwards.
	 *
	 * @var Lichtbild_Repository
	 */
	private $repository;

	/**
	 * Turns a gallery into front-end markup.
	 *
	 * @var Lichtbild_Renderer
	 */
	private $renderer;

	/**
	 * Registers and enqueues styles and scripts.
	 *
	 * @var Lichtbild_Assets
	 */
	private $assets;

	/**
	 * Handles the shortcodes.
	 *
	 * @var Lichtbild_Shortcode
	 */
	private $shortcode;

	/**
	 * Registers the two block-editor blocks.
	 *
	 * Handed the shortcode rather than a repository and a renderer, because that is what it
	 * renders through — see `Lichtbild_Block` for why a fifth publishing path was given no way to
	 * publish anything on its own.
	 *
	 * @var Lichtbild_Block
	 */
	private $block;

	/**
	 * Serves paginated pages over AJAX.
	 *
	 * @var Lichtbild_Ajax
	 */
	private $ajax;

	/**
	 * Settings screen and option access.
	 *
	 * @var Lichtbild_Settings
	 */
	private $settings;

	/**
	 * Registers the post types and taxonomy galleries live in.
	 *
	 * @var Lichtbild_Post_Types
	 */
	private $post_types;

	/**
	 * Renders a gallery on its own permalink.
	 *
	 * @var Lichtbild_Standalone
	 */
	private $standalone;

	/**
	 * The migration onto Lichtbild's own storage.
	 *
	 * @var Lichtbild_Migration
	 */
	private $migration;

	/**
	 * The migration's admin screen.
	 *
	 * @var Lichtbild_Migration_Screen
	 */
	private $migration_screen;

	/**
	 * Edits a gallery's images and settings.
	 *
	 * Handed the repository as well as the settings, because the list column has to report
	 * whichever record is authoritative rather than the one key that only a migrated site has.
	 *
	 * @var Lichtbild_Editor
	 */
	private $editor;

	/**
	 * Edits an album's member galleries and settings.
	 *
	 * @var Lichtbild_Album_Editor
	 */
	private $album_editor;

	/**
	 * Builds the object graph.
	 */
	public function __construct() {
		$this->settings         = new Lichtbild_Settings();
		$this->post_types       = new Lichtbild_Post_Types( $this->settings );
		$this->repository       = new Lichtbild_Repository(
			Lichtbild_Post_Types::gallery_type( $this->settings ),
			Lichtbild_Post_Types::album_type( $this->settings ),
			Lichtbild_Post_Types::tag_taxonomy( $this->settings ),
			$this->settings->has_migrated()
		);
		$this->assets           = new Lichtbild_Assets( $this->settings );
		$this->renderer         = new Lichtbild_Renderer( $this->assets );
		$this->shortcode        = new Lichtbild_Shortcode( $this->repository, $this->renderer, $this->settings );
		$this->block            = new Lichtbild_Block( $this->shortcode, $this->repository, $this->settings );
		$this->ajax             = new Lichtbild_Ajax( $this->repository, $this->renderer );
		$this->standalone       = new Lichtbild_Standalone( $this->repository, $this->renderer, $this->settings );
		$this->migration        = new Lichtbild_Migration( $this->settings );
		$this->migration_screen = new Lichtbild_Migration_Screen( $this->migration, $this->settings );
		$this->editor           = new Lichtbild_Editor( $this->settings, $this->repository );
		$this->album_editor     = new Lichtbild_Album_Editor( $this->settings, $this->repository );

		$this->settings->set_migration_screen( $this->migration_screen );
	}

	/**
	 * Registers every hook the plugin uses.
	 *
	 * @return void
	 */
	public function boot() {
		$this->settings->register();
		$this->post_types->register();
		$this->assets->register();
		$this->shortcode->register();
		$this->block->register();
		$this->ajax->register();
		$this->standalone->register();
		$this->migration_screen->register();
		$this->editor->register();
		$this->album_editor->register();
	}

	/**
	 * Exposes the repository so themes and companion code can read gallery data.
	 *
	 * @return Lichtbild_Repository The gallery repository.
	 */
	public function repository() {
		return $this->repository;
	}

	/**
	 * Exposes the renderer for themes that want to output a gallery directly.
	 *
	 * @return Lichtbild_Renderer The renderer.
	 */
	public function renderer() {
		return $this->renderer;
	}
}
