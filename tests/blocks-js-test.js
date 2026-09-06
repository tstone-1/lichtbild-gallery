/**
 * Runs the block editor script against a mocked `wp`, and asserts what it registered.
 *
 *     node tests/blocks-js-test.js
 *
 * WHY THIS EXISTS WHEN NOTHING ELSE HERE TESTS JAVASCRIPT
 * ======================================================
 *
 * Every other check in this repository asserts something about markup or about PHP, and both
 * are blind to the way this file fails. `blocks.js` runs once, at the top of the block editor,
 * and if it throws — a typo, a renamed `wp` package, an option that no longer exists — the
 * exception is caught by nothing, `registerBlockType` is never reached, and **both blocks are
 * simply absent from the inserter**. No PHP notice, no failing request, no missing asset: the
 * editor page still serves 200 with the script tag, the picker data and the server-side block
 * definitions all present, which is exactly what the live checks in `tests/live-block.php`
 * assert. Every one of them passes over a file that crashed on its first line.
 *
 * So this is deliberately not a rendering test. It does not care what the editor looks like.
 * It cares that the script runs to completion and that the two `registerBlockType` calls
 * happened with the names the metadata declares.
 *
 * The mock is the smallest thing the script will accept, and that is a property worth keeping:
 * every `wp.*` member it stubs is one the script genuinely uses, so a member added to the mock
 * without the script needing it is dead weight, and one the script starts using without being
 * added here fails loudly rather than quietly.
 *
 * WHY `useState` IS MODELLED RATHER THAN FAKED
 * ============================================
 *
 * The create flow is five pieces of component state and the transitions between them, so a
 * `useState` returning a fixed pair would model a component that cannot change — every check
 * below would render the same first frame and pass whatever the setters did. The stub here keeps
 * one slot per call in call order, persists it across renders of the same instance, and accepts
 * the updater-function form, because `blocks.js` uses it to append to the picker's choices. That
 * is what makes "the chooser is disabled while a request is in flight" a question this file can
 * answer at all.
 *
 * It is a *model*, not React: nothing re-renders by itself. A check sets state through the
 * script's own handlers and then re-renders explicitly, which is enough to ask what the next
 * frame contains and is honest about being a frame rather than a browser.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

let failures = 0;
let total = 0;

/**
 * Reports one check.
 *
 * The running total is counted rather than written down at the bottom, because a hand-kept count
 * drifts the moment a check is added and then reports fewer checks than ran — which is the one
 * number a reader uses to decide whether the file did what it claims.
 *
 * @param {string}  label  What is being asserted.
 * @param {boolean} ok     Whether it holds.
 * @param {string}  detail Context, printed either way.
 */
function check( label, ok, detail ) {
	total++;

	console.log( `${ ok ? '[OK]  ' : '[FAIL]' } ${ label.padEnd( 52 ) } ${ detail || '' }` );

	if ( ! ok ) {
		failures++;
	}
}

/**
 * Prints the summary and ends the run.
 */
function done() {
	console.log( `\nchecks: ${ total }, failing: ${ failures }` );

	process.exit( failures > 0 ? 1 : 0 );
}

/**
 * A stand-in for a React component or element type.
 *
 * @param {string} name Readable name, so a failure says which one.
 *
 * @return {Function} The sentinel.
 */
function component( name ) {
	const fn = function () {
		return null;
	};

	fn.displayName = name;

	return fn;
}

const registered = {};

/**
 * The elements `createElement` produced, flattened, so a check can look for one by type.
 *
 * @param {Object} node Element returned by the mocked `createElement`.
 *
 * @return {Object[]} Every element in the tree, including the root.
 */
function flatten( node ) {
	if ( ! node || 'object' !== typeof node || ! node.__el ) {
		return [];
	}

	return node.children.reduce( ( all, child ) => all.concat( flatten( child ) ), [ node ] );
}

// One slot per `useState` call, in call order, held across renders of one component instance.
// `mount()` starts a new instance; `frame()` starts a new render of the current one.
const hooks = { slots: [], cursor: 0 };

/**
 * Starts a fresh component instance, discarding whatever state the last one held.
 */
function mount() {
	hooks.slots = [];
	hooks.cursor = 0;
}

/**
 * One `useState` slot: the value, and a setter that accepts a value or an updater function.
 *
 * @param {*} initial Initial value, or a function producing one.
 *
 * @return {Array} The `[ value, setValue ]` pair.
 */
function useState( initial ) {
	const slot = hooks.cursor++;

	if ( ! Object.prototype.hasOwnProperty.call( hooks.slots, slot ) ) {
		hooks.slots[ slot ] = 'function' === typeof initial ? initial() : initial;
	}

	return [
		hooks.slots[ slot ],
		( next ) => {
			hooks.slots[ slot ] = 'function' === typeof next ? next( hooks.slots[ slot ] ) : next;
		}
	];
}

// Every request the script made, each with the handles to settle it, so a check decides when the
// server answers and what it answers with.
const fetches = [];

/**
 * Records a request and hands back a promise the check settles itself.
 *
 * @param {string} url     Endpoint.
 * @param {Object} options Request options.
 *
 * @return {Promise} A promise held open until a check resolves or rejects it.
 */
function fetchStub( url, options ) {
	let settle = null;
	const promise = new Promise( ( resolve, reject ) => {
		settle = { resolve, reject };
	} );

	fetches.push( { url, options, settle } );

	return promise;
}

/**
 * The parts of `FormData` the script uses, keeping order, because order is what carries the
 * gallery's own item order to the server.
 */
class FormDataStub {

	/**
	 * Starts an empty body.
	 */
	constructor() {
		this.entries = [];
	}

	/**
	 * Appends one field.
	 *
	 * @param {string} key   Field name.
	 * @param {string} value Field value.
	 */
	append( key, value ) {
		this.entries.push( [ key, value ] );
	}
}

// What the media frame will hand back when a check opens it.
let selection = [];

/**
 * Core's media frame, reduced to what the script asks of it.
 *
 * @param {Object} args Frame arguments.
 *
 * @return {Object} The frame.
 */
function mediaStub( args ) {
	mediaStub.args = args;
	mediaStub.frame = {
		opened: 0,
		handlers: {},
		on( event, fn ) {
			this.handlers[ event ] = fn;
		},
		open() {
			this.opened++;
		},
		state: () => ( {
			get: () => ( {
				each: ( fn ) => selection.forEach( ( id ) => fn( { toJSON: () => ( { id } ) } ) )
			} )
		} )
	};

	return mediaStub.frame;
}

mediaStub.args = null;
mediaStub.frame = null;

const wp = {
	element: {
		createElement: ( type, props, ...children ) => ( {
			__el: true,
			type,
			props: props || {},
			children: children.flat( Infinity ).filter( ( c ) => null !== c && undefined !== c )
		} ),
		useState
	},
	blocks: {
		registerBlockType: ( name, settings ) => {
			registered[ name ] = settings;
		}
	},
	blockEditor: {
		useBlockProps: () => ( { className: 'wp-block-lichtbild' } ),
		InspectorControls: component( 'InspectorControls' )
	},
	components: {
		Placeholder: component( 'Placeholder' ),
		PanelBody: component( 'PanelBody' ),
		SelectControl: component( 'SelectControl' ),
		TextControl: component( 'TextControl' ),
		Button: component( 'Button' ),
		Notice: component( 'Notice' ),
		ExternalLink: component( 'ExternalLink' )
	},
	media: mediaStub,
	serverSideRender: component( 'ServerSideRender' )
};

// Shaped like what `Lichtbild_Block::editor_data()` prints, with two galleries and one album so a
// picker that offered a constant would be visible.
const data = {
	galleries: [
		{ value: 11, label: 'Zoo' },
		{ value: 22, label: 'Alps' }
	],
	albums: [ { value: 33, label: 'Travel' } ],
	canCreate: true,
	createReason: '',
	createAction: 'lichtbild_create_gallery',
	createNonce: 'nonce-abc',
	ajaxUrl: 'https://example.com/wp-admin/admin-ajax.php',
	i18n: {
		galleryTitle: 'Lichtbild-Galerie',
		albumTitle: 'Lichtbild-Album',
		chooseGallery: 'Galerie auswählen',
		chooseAlbum: 'Album auswählen',
		galleryInstructions: 'gallery instructions',
		albumInstructions: 'album instructions',
		settings: 'Galerie',
		albumSettings: 'Album',
		none: '— Auswählen —',
		noGalleries: 'no galleries',
		noAlbums: 'no albums',
		emptyGallery: 'empty gallery',
		emptyAlbum: 'empty album',
		galleryName: 'Galeriename',
		createGallery: 'Galerie anlegen',
		chooseExisting: 'oder eine vorhandene',
		chooseImages: 'Bilder wählen',
		useImages: 'Zur Galerie hinzufügen',
		creating: 'wird angelegt…',
		createFailed: 'generic failure',
		chooseAtLeastOne: 'choose at least one',
		mediaUnavailable: 'no media library here',
		draftNotice: 'this is a draft',
		editGallery: 'edit this gallery'
	}
};

const source = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'blocks.js' ), 'utf8' );

/**
 * Runs `blocks.js` in a fresh context and returns the gallery block's `edit`.
 *
 * @param {Object} blockData What `Lichtbild_Block::editor_data()` printed.
 *
 * @return {Function} The edit component the script registered.
 */
function load( blockData ) {
	const box = {
		window: { wp, LichtbildBlocks: blockData, fetch: fetchStub, FormData: FormDataStub },
		console
	};

	box.global = box;

	vm.runInNewContext( source, box, { filename: 'assets/js/blocks.js' } );

	return registered[ 'lichtbild/gallery' ].edit;
}

const sandbox = { window: { wp, LichtbildBlocks: data, fetch: fetchStub, FormData: FormDataStub }, console };
sandbox.global = sandbox;

// A throw here is the whole point, so it is reported as a failing check rather than left to
// crash the process with a stack trace and no summary line.
try {
	vm.runInNewContext( source, sandbox, { filename: 'assets/js/blocks.js' } );
	check( 'the script runs to completion', true, '' );
} catch ( error ) {
	check( 'the script runs to completion', false, String( error ) );
	done();
}

const names = Object.keys( registered ).sort();

check(
	'both blocks registered themselves',
	2 === names.length && 'lichtbild/album' === names[ 0 ] && 'lichtbild/gallery' === names[ 1 ],
	`registered: ${ names.length ? names.join( ', ' ) : '(nothing)' }`
);

if ( 2 !== names.length ) {
	done();
}

// Dynamic blocks save nothing into the post content but the block comment. A `save` returning
// markup would freeze a snapshot of the gallery into every post that embeds it, which is the
// one thing this plugin's whole design is against.
check(
	'both blocks are dynamic',
	names.every( ( name ) => null === registered[ name ].save() ),
	'save() returned markup for: ' +
		( names.filter( ( name ) => null !== registered[ name ].save() ).join( ', ' ) || '(none)' )
);

// Only Lichtbild's own shortcode. `[envira-gallery]` still renders under a rollback because Envira
// registers it again; a one-click conversion with no confirmation is the wrong place to spend
// that, so a transform naming it is a regression rather than a feature.
const tags = names.flatMap( ( name ) =>
	( registered[ name ].transforms.from || [] ).flatMap( ( t ) => [].concat( t.tag ) )
).sort();

check(
	'only our own shortcodes transform',
	2 === tags.length && 'lichtbild-album' === tags[ 0 ] && 'lichtbild-gallery' === tags[ 1 ],
	`transforms from: ${ tags.join( ', ' ) || '(nothing)' }`
);

const shortcodeAttr = registered[ 'lichtbild/gallery' ].transforms.from[ 0 ].attributes.id.shortcode;

check(
	'the shortcode transform reads a number',
	11 === shortcodeAttr( { named: { id: '11' } } ) && 0 === shortcodeAttr( { named: {} } ),
	`id="11" -> ${ JSON.stringify( shortcodeAttr( { named: { id: '11' } } ) ) }, absent -> ` +
		JSON.stringify( shortcodeAttr( { named: {} } ) )
);

// --- the three states of the edit component ------------------------------------------------
//
// Each is rendered and inspected for what it contains. Every render below is a fresh instance:
// `mount()` throws away the previous one's state, so a check cannot pass on a value some earlier
// check happened to leave in a hook slot.
const edit = registered[ 'lichtbild/gallery' ].edit;

/**
 * Renders `edit` as a new component instance and returns every element it produced.
 *
 * @param {number} id       Chosen gallery.
 * @param {Object} override Extra props merged into the mocked block props.
 *
 * @return {Object[]} The flattened element tree.
 */
function render( id, override ) {
	mount();

	return flatten( edit( Object.assign( { attributes: { id }, setAttributes: () => {} }, override ) ) );
}

/**
 * Renders the *same* instance again, so a check can read the frame after a setter ran.
 *
 * @param {Function} component The edit component.
 * @param {Object}   props     Block props.
 *
 * @return {Object[]} The flattened element tree.
 */
function reRender( component, props ) {
	hooks.cursor = 0;

	return flatten( component( props ) );
}

const unchosen = render( 0 );
const chosen = render( 11 );

check(
	'an unchosen block offers the picker',
	unchosen.some( ( el ) => 'Placeholder' === el.type.displayName ) &&
		unchosen.some( ( el ) => 'SelectControl' === el.type.displayName ) &&
		! unchosen.some( ( el ) => 'ServerSideRender' === el.type.displayName ),
	'elements: ' + unchosen.map( ( el ) => el.type.displayName || el.type ).join( ', ' )
);

check(
	'a chosen block previews from the server',
	chosen.some( ( el ) => 'ServerSideRender' === el.type.displayName ) &&
		chosen.some( ( el ) => 'InspectorControls' === el.type.displayName ) &&
		! chosen.some( ( el ) => 'Placeholder' === el.type.displayName ),
	'elements: ' + chosen.map( ( el ) => el.type.displayName || el.type ).join( ', ' )
);

// The preview must ask the server for the block it belongs to, and hand it the chosen id.
// Getting either wrong previews a different gallery, or somebody else's block entirely.
const preview = chosen.find( ( el ) => 'ServerSideRender' === el.type.displayName );

check(
	'the preview names its own block and id',
	'lichtbild/gallery' === preview.props.block && 11 === preview.props.attributes.id,
	`block ${ preview.props.block }, id ${ JSON.stringify( preview.props.attributes.id ) }`
);

// The dropdown must carry every gallery plus the empty choice, or a site's galleries are
// missing from the picker while everything else looks correct.
const select = unchosen.find( ( el ) => 'SelectControl' === el.type.displayName );

check(
	'the picker lists every gallery it was given',
	3 === select.props.options.length &&
		0 === select.props.options[ 0 ].value &&
		11 === select.props.options[ 1 ].value &&
		22 === select.props.options[ 2 ].value,
	`${ select.props.options.length } options for 2 galleries plus the empty choice`
);

// A `<select>` hands back a string. Stored unconverted, the block's id would be `"11"` where
// block.json promises a number, and WordPress would re-serialise the post on every save.
let written = null;

render( 0, { setAttributes: ( attrs ) => { written = attrs; } } )
	.find( ( el ) => 'SelectControl' === el.type.displayName )
	.props.onChange( '22' );

check(
	'a chosen id is stored as a number',
	null !== written && 22 === written.id && 'number' === typeof written.id,
	`stored: ${ JSON.stringify( written ) }`
);

// A site with nothing to pick gets a statement, not an empty dropdown that reads as a bug.
const emptyEdit = load( Object.assign( {}, data, { galleries: [] } ) );

mount();

const nothing = flatten( emptyEdit( { attributes: { id: 0 }, setAttributes: () => {} } ) );

check(
	'a site with no galleries says so',
	nothing.some( ( el ) => 'Placeholder' === el.type.displayName ) &&
		! nothing.some( ( el ) => 'SelectControl' === el.type.displayName ),
	'elements: ' + nothing.map( ( el ) => el.type.displayName || el.type ).join( ', ' )
);

// The guard at the top of the script. A `wp` without the packages it needs must return quietly
// rather than throw, because throwing inside the editor's own bundle takes the editor with it.
const guarded = { window: {}, console };
guarded.global = guarded;

let threw = false;

try {
	vm.runInNewContext( source, guarded, { filename: 'assets/js/blocks.js' } );
} catch ( error ) {
	threw = true;
}

check( 'a missing wp is survived, not thrown on', ! threw, threw ? 'it threw' : 'returned quietly' );

// --- the create flow -------------------------------------------------------------------------
//
// The checks above ask what one frame contains. These ask what the script *does*: open the media
// frame, post what was chosen, and read the answer. None of it is reachable from markup — the
// PHP suite sees the request arrive and never sees what sent it, and `tests/live-block.php` sees
// the script tag and never sees it run — so a create flow that posted the wrong nonce, dropped
// the chosen order, or left the form stuck on "Creating…" would pass every other check here.

/**
 * Finds one element by the component it renders.
 *
 * @param {Object[]} tree Flattened element tree.
 * @param {string}   name Component display name.
 *
 * @return {Object|undefined} The element, if it is there.
 */
function byName( tree, name ) {
	return tree.find( ( el ) => name === ( el.type && el.type.displayName ) );
}

/**
 * Lets every pending promise callback run.
 *
 * `setImmediate` is a macrotask, so the whole microtask queue — `response.json()` and the
 * `.then()` after it — has drained by the time it fires.
 *
 * @return {Promise} Resolved after the microtask queue is empty.
 */
function flush() {
	return new Promise( ( resolve ) => setImmediate( resolve ) );
}

/**
 * A block whose attributes actually change when the script writes them, so a check can render
 * the frame that follows an adoption rather than assert on the write alone.
 *
 * @return {Object} Props, plus `written`, the last attributes the script stored.
 */
function block() {
	const props = { attributes: { id: 0 }, written: null };

	props.setAttributes = ( attrs ) => {
		props.written = attrs;
		Object.assign( props.attributes, attrs );
	};

	return props;
}

const createEdit = load( data );

( async () => {
	// A throw in here would otherwise surface as an unhandled rejection: a stack trace, no
	// summary line, and an exit code that says failure without saying how many checks ran. Same
	// reason the script's own run is wrapped above.
	try {
		await createFlow();
	} catch ( error ) {
		check( 'the create flow runs to completion', false, String( error ) );
	}

	done();
} )();

/**
 * Drives the create flow, from an empty block to an adopted gallery and back to a refusal.
 */
async function createFlow() {
	// One instance for the whole happy path, because the flow *is* the state carried across
	// renders: the name typed in the first frame has to reach the request made from the third.
	mount();

	const props = block();
	let tree = reRender( createEdit, props );

	byName( tree, 'TextControl' ).props.onChange( 'Alpen' );

	tree = reRender( createEdit, props );
	selection = [ 501, 502 ];

	byName( tree, 'Button' ).props.onClick();

	check(
		'the create button opens the media frame, images only',
		!! mediaStub.frame &&
			1 === mediaStub.frame.opened &&
			'image' === mediaStub.args.library.type &&
			'add' === mediaStub.args.multiple &&
			'function' === typeof mediaStub.frame.handlers.select,
		`opened ${ mediaStub.frame ? mediaStub.frame.opened : 0 } time(s), library ` +
			JSON.stringify( mediaStub.args && mediaStub.args.library )
	);

	mediaStub.frame.handlers.select();

	// The order of `images[]` is the gallery's own item order, and it is the one thing in this
	// request the server cannot reconstruct. The nonce and the action are asserted with it
	// because a request carrying the wrong one of either is refused with a message about
	// permissions, which reads as a broken site rather than a broken request.
	const sent = fetches[ 0 ];

	check(
		'the chosen images are posted, in order, with the nonce',
		1 === fetches.length &&
			data.ajaxUrl === sent.url &&
			'POST' === sent.options.method &&
			'same-origin' === sent.options.credentials &&
			JSON.stringify( sent.options.body.entries ) ===
				JSON.stringify( [
					[ 'action', 'lichtbild_create_gallery' ],
					[ 'nonce', 'nonce-abc' ],
					[ 'title', 'Alpen' ],
					[ 'images[]', '501' ],
					[ 'images[]', '502' ],
					[ 'images_complete', '1' ]
				] ),
		`${ fetches.length } request(s): ` + JSON.stringify( sent && sent.options.body.entries )
	);

	tree = reRender( createEdit, props );

	// The window in which two writers can disagree about what this block points at. The response
	// ends by writing its own id into the block, so a chooser left live during the request lets
	// someone select a different gallery and have it silently overwritten a second later.
	check(
		'the chooser is disabled while the request is in flight',
		true === byName( tree, 'SelectControl' ).props.disabled,
		`disabled: ${ JSON.stringify( byName( tree, 'SelectControl' ).props.disabled ) }`
	);

	sent.settle.resolve( {
		json: () =>
			Promise.resolve( {
				success: true,
				data: { id: 77, title: 'Alpen', editUrl: 'https://example.com/wp-admin/post.php?post=77' }
			} )
	} );

	await flush();

	tree = reRender( createEdit, props );

	const options = byName( tree, 'SelectControl' ).props.options;

	check(
		'the new gallery is adopted as a number, listed, and the form freed',
		null !== props.written &&
			77 === props.written.id &&
			'number' === typeof props.written.id &&
			4 === options.length &&
			77 === options[ 3 ].value &&
			false === byName( tree, 'SelectControl' ).props.disabled,
		`wrote ${ JSON.stringify( props.written ) }, ${ options.length } options`
	);

	// A draft is invisible to visitors and renders perfectly for the author looking at it, so the
	// only thing that says so is this notice.
	const draft = byName( tree, 'Notice' );

	check(
		'a freshly made draft says it is one, and links to itself',
		!! draft &&
			'warning' === draft.props.status &&
			draft.children.includes( 'this is a draft' ) &&
			!! byName( tree, 'ExternalLink' ),
		`notice: ${ draft ? draft.props.status : '(none)' }`
	);

	// --- the same flow, refused ---------------------------------------------------------------
	//
	// A fresh instance, because this asks what happens to a form that has never succeeded.
	mount();
	fetches.length = 0;
	selection = [ 501 ];

	const refusedProps = block();
	let refused = reRender( createEdit, refusedProps );

	byName( refused, 'Button' ).props.onClick();
	mediaStub.frame.handlers.select();

	fetches[ 0 ].settle.resolve( {
		json: () => Promise.resolve( { success: false, data: { message: 'Run the migration first.' } } )
	} );

	await flush();

	refused = reRender( createEdit, refusedProps );

	const complaint = byName( refused, 'Notice' );

	// The server's own message, not this file's generic one: a refusal here says what to do —
	// run the migration, ask for permission — and "The gallery could not be created" says only
	// that something went wrong. And the form has to come back, or the editor is stuck on
	// "Creating…" with no way to try again.
	check(
		'a refusal shows the server reason and frees the form',
		null === refusedProps.written &&
			!! complaint &&
			'error' === complaint.props.status &&
			complaint.children.includes( 'Run the migration first.' ) &&
			false === byName( refused, 'SelectControl' ).props.disabled,
		`notice: ${ complaint ? JSON.stringify( complaint.children ) : '(none)' }`
	);
}
