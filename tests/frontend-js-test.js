/**
 * Asserts what the front-end script's deep-link pattern accepts.
 *
 *     node tests/frontend-js-test.js
 *
 * WHAT THIS CAN AND CANNOT SEE, STATED FIRST BECAUSE IT DECIDES WHAT THE RESULT MEANS
 * ===================================================================================
 *
 * `lichtbild.js` is a closed IIFE. State tests reach it through the public init() entry
 * point and the gallery object it attaches to each grid, without production test exports.
 *
 * 1. The deep-link pattern is extracted and executed; related wiring retains source
 *    assertions, which establish presence rather than runtime behavior.
 * 2. The whole script runs through init() against a small DOM with controlled requests.
 *    These checks cover pagination/filter state, stale responses, slide eligibility and
 *    cache keys. The dynamic import alone is replaced with a module stub; no browser layout
 *    or PhotoSwipe internals are tested.
 *
 * WHY THE PATTERN IS WORTH TESTING AT ALL
 * =======================================
 *
 * A deep link is the one URL this plugin produces that leaves the site: people paste them into
 * messages and bookmark them. The plugin was renamed, so links carrying the former prefix are
 * out in the world and cannot be recalled, and a fragment that no longer resolves fails in the
 * quietest way available — the page loads, the photograph the link was about is not shown, and
 * nothing anywhere reports an error. No rendering check, no PHP check and no live URL check can
 * see it, because every one of them is satisfied by the page that loads.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

let failures = 0;
let checksRun = 0;

/**
 * Reports one check.
 *
 * @param {string}  label  What is being asserted.
 * @param {boolean} ok     Whether it holds.
 * @param {string}  detail Context, printed either way.
 */
function check( label, ok, detail ) {
	checksRun++;
	console.log( `${ ok ? '[OK]  ' : '[FAIL]' } ${ label.padEnd( 54 ) } ${ detail || '' }` );

	if ( ! ok ) {
		failures++;
	}
}

const file = path.join( __dirname, '..', 'assets', 'js', 'lichtbild.js' );
const source = fs.readFileSync( file, 'utf8' );

// Extracting by pattern means a reformatted declaration stops being found, and that has to be a
// failure rather than a skip: "the regex could not be located" and "the regex is fine" must not
// produce the same exit code. This is the same rule the PHP mutation harness enforces on itself
// — a target that is absent, or present twice, is BROKEN and never a pass.
const declarations = source.match( /^\tvar DEEP_LINK = (\/.+\/);$/m );

if ( ! declarations ) {
	check(
		'the deep-link pattern can be found in the source',
		false,
		'no `var DEEP_LINK = /.../;` line in assets/js/lichtbild.js'
	);

	process.exit( 1 );
}

/** @type {RegExp} The real literal from the shipped file, not a copy of it. */
const pattern = vm.runInNewContext( declarations[ 1 ] );

/**
 * Runs the pattern and returns what it captured.
 *
 * @param {string} hash Fragment including the leading hash.
 *
 * @return {?{gallery:number,image:number}} The ids, or null when it does not match.
 */
function resolve( hash ) {
	const match = pattern.exec( hash );

	return match
		? { gallery: parseInt( match[ 1 ], 10 ), image: parseInt( match[ 2 ], 10 ) }
		: null;
}

const current = resolve( '#lichtbild-1234-i5678' );

check(
	'a current deep link resolves to its gallery and image',
	!! current && 1234 === current.gallery && 5678 === current.image,
	current ? `gallery ${ current.gallery }, image ${ current.image }` : 'did not match'
);

// The reason this file exists. Links written before the plugin was renamed carry the former
// prefix, and they are in other people's messages and bookmarks.
const legacy = resolve( '#tivira-1234-i5678' );

check(
	'a deep link from before the rename resolves identically',
	!! legacy && !! current
		&& legacy.gallery === current.gallery && legacy.image === current.image,
	legacy ? `gallery ${ legacy.gallery }, image ${ legacy.image }` : 'did not match'
);

// The control, and the half that says the check above is a rule rather than a wildcard. A
// pattern loose enough to accept anything would pass every assertion so far.
check(
	'a fragment belonging to something else is not claimed',
	null === resolve( '#gallery-1234-i5678' )
		&& null === resolve( '#envira-1234-i5678' )
		&& null === resolve( '#lichtbild' )
		&& null === resolve( '#comment-1234' ),
	'four foreign fragments, none matched'
);

// The fragment names the image, never its position -- a position means nothing without the
// filter and page it was taken under, neither of which is in the URL. An index-shaped fragment
// must therefore not resolve, or a link built by hand would open a different photograph.
check(
	'a position-shaped fragment is not accepted as an image',
	null === resolve( '#lichtbild-1234-5678' ) && null === resolve( '#lichtbild-1234' ),
	'neither `-N` nor a bare gallery id matched'
);

// Anchored at both ends, so a fragment that merely starts or ends with one is not one.
check(
	'the pattern is anchored at both ends',
	null === resolve( '#lichtbild-12-i34-extra' ) && null === resolve( 'x#lichtbild-12-i34' ),
	'a suffix and a prefix, neither matched'
);

// --- the call sites, which is the source-reading half -------------------------------------
//
// Two methods read `location.hash`: one opens the lightbox from a link that was followed, the
// other clears the hash when it closes. Both have to use the same pattern, and the second is
// the one that quietly gets left behind, because it was a `indexOf( '#lichtbild-' )` string test
// where the first was already a regular expression -- two spellings of one rule is how half a
// rename survives review.
const usesPattern = ( source.match( /DEEP_LINK\.exec\(/g ) || [] ).length;

check(
	'both hash readers use the extracted pattern',
	2 === usesPattern,
	`${ usesPattern } call site(s) of DEEP_LINK.exec()`
);

// And nothing still matches a hash by string prefix, which is what the pattern replaced. This
// is the assertion that would have caught the half-done version of this change.
const literalPrefix = /hash[^\n]*(indexOf|startsWith|===)[^\n]*['"]#/.test( source );

check(
	'no hash is matched by a bare string prefix',
	! literalPrefix,
	literalPrefix ? 'a string comparison against a hash literal survives' : 'none'
);

// Writing is deliberately not bilingual: everything this file emits uses the current prefix, so
// a legacy link is upgraded in the address bar as soon as the lightbox it opened writes its
// own. A second writer emitting the old prefix would keep minting links that need the shim.
const writesLegacy = /return\s+'tivira-/.test( source ) || /'#tivira-/.test( source );

check(
	'nothing writes a fragment using the former prefix',
	! writesLegacy,
	writesLegacy ? 'a legacy prefix is being produced, not just accepted' : 'read-only shim'
);

// The localized bag is named in TWO languages, and neither one errors when they disagree: PHP
// hands the browser an object, JS reads a property that is simply `undefined`, and every lookup
// falls through to ''. That is how `loadFailed` was localized, announced to a live region, and
// empty, for as long as the message existed. So assert the two names against each other rather
// than either one alone.
//
// The PHP side is located from `loadFailed` outwards -- find the string, then the nearest
// enclosing `'<key>' => array(` -- so renaming the key on either side is what goes red, and a
// reformatted PHP array does not quietly stop being found.
const assets = fs.readFileSync(
	path.join( __dirname, '..', 'includes', 'class-lichtbild-assets.php' ),
	'utf8'
);
const loadFailedAt = assets.indexOf( "'loadFailed'" );
const enclosing = loadFailedAt === -1
	? null
	: [ ...assets.slice( 0, loadFailedAt ).matchAll( /'([A-Za-z0-9_]+)'\s*=> array\(/g ) ].pop();
const phpBag = enclosing ? enclosing[ 1 ] : null;
const jsBag = ( source.match( /window\.LichtbildSettings\.([A-Za-z0-9_]+)/ ) || [] )[ 1 ] || null;

check(
	'the localized bag has the same name in PHP and JS',
	phpBag !== null && jsBag !== null && phpBag === jsBag,
	phpBag === jsBag ? `both '${ phpBag }'` : `PHP localizes '${ phpBag }', JS reads '${ jsBag }'`
);

// A rejected promise is a SETTLED promise, so caching one caches the failure. `loadPhotoSwipe()`
// stores the import promise and every click reuses it, and the click handler has already called
// `preventDefault()` by then -- so one transient module-load failure made every later click on
// every image do nothing at all, permanently, with no way back short of a page reload. Nothing
// is logged and nothing is drawn; it is indistinguishable from a dead page.
//
// These three are source assertions, which is the weaker half this file is explicit about: they
// prove the module-load recovery is written, never that a browser retries the import. The
// state harness below stubs the import and therefore cannot establish loader behavior.
const loader = ( source.match( /function loadPhotoSwipe\(\)[\s\S]*?\n\t}/ ) || [ '' ] )[ 0 ];

check(
	'a failed module load clears its own cache',
	/\.catch\(/.test( loader ) && /photoSwipePromise = null/.test( loader ),
	loader ? 'catch and reset both present in loadPhotoSwipe()' : 'loadPhotoSwipe() could not be located'
);

const openBody = ( source.match( /Gallery\.prototype\.open = function[\s\S]*?\n\t};/ ) || [ '' ] )[ 0 ];

check(
	'a click that cannot open the lightbox follows the link',
	/\.catch\(/.test( openBody ) && /window\.location\.href/.test( openBody ),
	openBody ? 'open() falls back to the href' : 'open() could not be located'
);

const restoreBody = ( source.match( /Gallery\.prototype\.restoreFromHash = function[\s\S]*?\n\t};/ ) || [ '' ] )[ 0 ];

check(
	'the deep-link path consumes its rejection',
	/\.catch\(/.test( restoreBody ),
	restoreBody ? 'restoreFromHash() handles a rejected import' : 'restoreFromHash() could not be located'
);

// Execute the complete script against a small DOM and controllable transport. Only the
// import is substituted: the module loader is not the subject of these state-transition
// checks. Gallery construction and event handlers run through the public init() path.
function classList( initial = [] ) {
	const values = new Set( initial );
	return {
		add: value => values.add( value ),
		remove: value => values.delete( value ),
		contains: value => values.has( value ),
		toggle( value, enabled ) {
			if ( enabled ) { values.add( value ); } else { values.delete( value ); }
		}
	};
}

function gridLink( id, width = 800, height = 600 ) {
	const attributes = {
		href: 'https://example.invalid/image-' + id + '.jpg',
		'data-lichtbild-item': String( id ),
		'data-pswp-width': String( width ),
		'data-pswp-height': String( height )
	};
	return {
		href: attributes.href,
		getAttribute: key => attributes[ key ] || '',
		querySelector: () => ( { alt: 'Synthetic image' } ),
		closest: () => ( { classList: classList() } )
	};
}

function frontend( overrides = {} ) {
	const requests = [];
	const opened = [];
	const buttons = [ '', 'birds', 'flowers', 'constructor' ].map( slug => ( {
		slug: slug,
		pressed: slug === '' ? 'true' : 'false',
		classList: classList( slug === '' ? [ 'is-current' ] : [] ),
		getAttribute: () => slug,
		setAttribute( key, value ) { this.pressed = value; }
	} ) );
	const bar = { addEventListener( type, handler ) { this.click = handler; } };
	const slot = { innerHTML: 'old nav', addEventListener() {} };
	const wrap = {
		message: null,
		querySelector( selector ) {
			return { '.lichtbild-tags': bar, '.lichtbild-pagination-slot': slot, '.lichtbild-message': this.message }[ selector ] || null;
		},
		querySelectorAll: () => buttons,
		appendChild( node ) { this.message = node; node.parentNode = this; },
		removeChild() { this.message = null; }
	};
	const config = Object.assign( { id: 1, pagination: true, spanPages: true, pages: 3, scroll: false }, overrides );
	const root = {
		innerHTML: 'old grid',
		links: [ gridLink( 10 ), gridLink( 20, 0, 0 ) ],
		classList: classList(),
		closest: () => wrap,
		getAttribute: key => key === 'data-lichtbild-config' ? JSON.stringify( config ) : '1',
		querySelectorAll() { return this.links; },
		addEventListener( type, handler ) { this[ type ] = handler; },
		contains: () => true
	};
	const document = {
		readyState: 'complete',
		querySelectorAll: () => [ root ],
		createElement: () => ( { setAttribute() {} } )
	};
	const window = {
		LichtbildSettings: { ajaxUrl: 'https://example.invalid/ajax', i18n: { loadFailed: 'Load failed' } },
		location: { hash: '' },
		fetch( url ) {
			return new Promise( ( resolve, reject ) => requests.push( {
				query: new URL( url ).searchParams,
				succeed: data => resolve( { ok: true, json: () => Promise.resolve( { success: true, data: data } ) } ),
				fail: () => reject( new Error( 'Synthetic network failure' ) )
			} ) );
		},
		loadModule: () => Promise.resolve( { default: function ( options ) {
			opened.push( options );
			this.on = function () {};
			this.init = function () {};
		} } )
	};
	const importCall = 'import( settings.photoswipe )';
	if ( source.split( importCall ).length !== 2 ) {
		throw new Error( 'Expected exactly one dynamic import seam' );
	}
	vm.runInNewContext( source.replace( importCall, 'window.loadModule()' ), { window, document, URLSearchParams } );
	return {
		gallery: root.lichtbildGallery, root, wrap, buttons, requests, opened,
		clickTag( slug ) { bar.click( { target: { closest: () => buttons.find( button => button.slug === slug ) } } ); }
	};
}

const settled = () => new Promise( resolve => setImmediate( resolve ) );
const pageResult = ( html = 'new grid' ) => ( { html: html, nav: 'new nav', page: 2, pages: 3 } );
const itemResult = id => ( { items: [ { id: id, src: 'https://example.invalid/image.jpg', width: 800, height: 600 } ] } );

async function checkFrontendState() {
	let f = frontend();
	f.gallery.goToPage( 2 );
	f.requests[ 0 ].fail();
	await settled();
	check( 'a failed page load visibly reports its failure', f.root.classList.contains( 'is-error' ) && f.wrap.message.textContent === 'Load failed' );
	f.gallery.goToPage( 2 );
	f.requests[ 1 ].succeed( pageResult() );
	await settled();
	check( 'successful retry clears the error and stale message', f.root.innerHTML === 'new grid' && ! f.root.classList.contains( 'is-error' ) && f.wrap.message === null );

	f = frontend();
	f.clickTag( 'birds' );
	check( 'filter request carries the chosen tag', f.requests[ 0 ].query.get( 'tag' ) === 'birds' );
	check( 'pending filter preserves the displayed tag state', f.gallery.activeTag === '' && f.buttons[ 0 ].pressed === 'true' && f.buttons[ 1 ].pressed === 'false' );
	f.requests[ 0 ].fail();
	await settled();
	check( 'failed filter keeps the grid and selected tag together', f.gallery.activeTag === '' && f.root.innerHTML === 'old grid' && f.buttons[ 0 ].pressed === 'true' );
	f.gallery.open( f.root.links[ 0 ] );
	check( 'lightbox after failed filter requests the displayed tag', f.requests[ 1 ].query.get( 'tag' ) === '' );
	f.requests[ 1 ].succeed( itemResult( 10 ) );
	await settled();
	check( 'lightbox after failed filter opens the clicked image', f.opened.length === 1 && f.opened[ 0 ].dataSource[ f.opened[ 0 ].index ].id === 10 );
	f.clickTag( 'birds' );
	check( 'a failed filter can be retried with the same button', f.requests.length === 3 );
	f.requests[ 2 ].succeed( pageResult() );
	await settled();
	check( 'successful filter commits grid and accessible tag state', f.gallery.activeTag === 'birds' && f.root.innerHTML === 'new grid' && f.buttons[ 1 ].pressed === 'true' && f.buttons[ 0 ].pressed === 'false' );

	f = frontend();
	f.clickTag( 'birds' );
	f.clickTag( 'flowers' );
	f.requests[ 1 ].succeed( pageResult( 'flowers' ) );
	await settled();
	f.requests[ 0 ].succeed( pageResult( 'birds' ) );
	await settled();
	check( 'late response cannot replace the latest filter choice', f.root.innerHTML === 'flowers' && f.gallery.activeTag === 'flowers' && f.buttons[ 2 ].pressed === 'true' );

	f = frontend();
	f.clickTag( 'birds' );
	f.clickTag( '' );
	check( 'selecting the displayed tag supersedes a pending filter', f.requests.length === 2 );
	f.requests[ 1 ].succeed( pageResult( 'all' ) );
	await settled();
	f.requests[ 0 ].fail();
	await settled();
	check( 'obsolete failure cannot dim the latest successful grid', f.root.innerHTML === 'all' && f.gallery.activeTag === '' && ! f.root.classList.contains( 'is-error' ) );

	f = frontend();
	f.gallery.open( f.root.links[ 0 ] );
	f.requests[ 0 ].succeed( itemResult( 99 ) );
	await settled();
	check( 'changed server list still opens the clicked photograph', f.opened.length === 1 && f.opened[ 0 ].dataSource[ f.opened[ 0 ].index ].id === 10 );

	f = frontend( { pagination: false } );
	let slides = await f.gallery.slides();
	check( 'DOM lightbox list excludes unsized attachment fallbacks', slides.length === 1 && slides[ 0 ].id === 10 );
	slides = await f.gallery.allSlides();
	check( 'DOM deep-link list excludes unsized attachment fallbacks', slides.length === 1 && slides[ 0 ].id === 10 );
	let prevented = false;
	f.root.click( { target: { closest: () => f.root.links[ 1 ] }, preventDefault() { prevented = true; } } );
	check( 'unsized attachment remains an ordinary navigable link', ! prevented && f.opened.length === 0 );

	f = frontend();
	const fallback = f.gallery.slides();
	f.requests[ 0 ].fail();
	slides = await fallback;
	check( 'failed items request also excludes unsized DOM slides', slides.length === 1 && slides[ 0 ].id === 10 );

	f = frontend();
	f.gallery.selectTag( 'constructor' );
	const pending = f.gallery.slides();
	check( 'constructor tag is fetched instead of an inherited value', f.requests.length === 1 && f.requests[ 0 ].query.get( 'tag' ) === 'constructor' );
	f.requests[ 0 ].succeed( itemResult( 10 ) );
	await pending;
	slides = await f.gallery.slides();
	check( 'constructor tag results are cached normally', f.requests.length === 1 && slides.length === 1 && slides[ 0 ].id === 10 );
}

checkFrontendState().catch( error => {
	check( 'front-end state harness completes', false, error.stack );
} ).then( () => {
	console.log( `\nchecks: ${ checksRun }, failing: ${ failures }` );
	process.exitCode = failures ? 1 : 0;
} );
