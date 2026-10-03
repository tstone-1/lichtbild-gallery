/**
 * Asserts the column cap a fixed-column gallery resolves to at three viewport widths.
 *
 *     node tests/responsive-columns-test.js
 *
 * WHAT THIS CAN AND CANNOT SEE, STATED FIRST BECAUSE IT DECIDES WHAT THE RESULT MEANS
 * ===================================================================================
 *
 * This does not render anything, and no amount of reading a stylesheet proves what a browser
 * paints — the 26.8.12 defect (`grid-template-columns` left in force on a container that had
 * become a flex box) was invisible to every source-level instrument in this repo and needed a
 * rendering engine to find. So the claim here is narrower and is worth stating exactly:
 *
 * - It **cascades**: the declarations for `.lichtbild-columns` and `.lichtbild-columns
 *   .lichtbild-item` are collected from the base rules plus every `@media` block whose
 *   `max-width` admits the viewport, in source order, last one winning. A rule that stops
 *   applying at a width stops being counted at that width.
 * - It **evaluates**: `min(var(--lichtbild-columns), 3)` is computed for a configured count
 *   rather than matched as text, and the `flex` basis is worked out arithmetically and checked
 *   to tile the row exactly — N items and N-1 gaps filling the container, N+1 overflowing it.
 * - It **assumes** the browser implements the cascade, `calc()`, `min()` and flex wrapping as
 *   specified. That assumption is what separates this from a rendering measurement, and it is
 *   the reason a green run here is a contract check and not proof of layout.
 *
 * The one class of defect it does catch on its own is the 26.8.12 shape: a declaration left
 * behind for a layout model the container is no longer using. The container's `display` is read
 * at each width, and the properties that belong to the other model are asserted to be inert.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

let failures = 0;

/**
 * Reports one check.
 *
 * @param {string}  label  What is being asserted.
 * @param {boolean} ok     Whether it holds.
 * @param {string}  detail Context, printed either way.
 */
function check( label, ok, detail ) {
	console.log( `${ ok ? '[OK]  ' : '[FAIL]' } ${ label.padEnd( 60 ) } ${ detail || '' }` );

	if ( ! ok ) {
		failures++;
	}
}

/**
 * Splits a stylesheet into contexts: the base rules, and one per `@media` block.
 *
 * Brace-matched rather than line-oriented, because a media block's rules are nested and a
 * regular expression that ignores nesting silently attributes them to the base context — which
 * would report every capped width as uncapped and look like a stylesheet bug.
 *
 * @param {string} css Stylesheet source, comments already removed.
 *
 * @return {Array<{condition: string, rules: Array<{selectors: string[], body: string}>}>} Contexts.
 */
function contexts( css ) {
	const out = [ { condition: '', rules: [] } ];
	let i = 0;

	while ( i < css.length ) {
		const at = css.indexOf( '@media', i );
		const brace = css.indexOf( '{', i );

		if ( -1 === brace ) {
			break;
		}

		if ( -1 !== at && at < brace ) {
			const open = css.indexOf( '{', at );
			const end = matchBrace( css, open );

			out.push( {
				condition: css.slice( at + 6, open ).trim(),
				rules: rules( css.slice( open + 1, end ) ),
			} );

			i = end + 1;

			continue;
		}

		const end = matchBrace( css, brace );

		out[ 0 ].rules.push( {
			selectors: css.slice( i, brace ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ),
			body: css.slice( brace + 1, end ),
		} );

		i = end + 1;
	}

	return out;
}

/**
 * Returns the index of the `}` closing the `{` at the given index.
 *
 * @param {string} css   Stylesheet source.
 * @param {number} open  Index of the opening brace.
 *
 * @return {number} Index of the matching closing brace.
 */
function matchBrace( css, open ) {
	let depth = 0;

	for ( let i = open; i < css.length; i++ ) {
		if ( '{' === css[ i ] ) {
			depth++;
		}

		if ( '}' === css[ i ] ) {
			depth--;

			if ( 0 === depth ) {
				return i;
			}
		}
	}

	throw new Error( 'unbalanced braces in the stylesheet' );
}

/**
 * Splits one block of rule sets.
 *
 * @param {string} block Stylesheet fragment holding only rule sets.
 *
 * @return {Array<{selectors: string[], body: string}>} The rule sets.
 */
function rules( block ) {
	const out = [];
	let i = 0;

	while ( i < block.length ) {
		const brace = block.indexOf( '{', i );

		if ( -1 === brace ) {
			break;
		}

		const end = matchBrace( block, brace );

		out.push( {
			selectors: block.slice( i, brace ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ),
			body: block.slice( brace + 1, end ),
		} );

		i = end + 1;
	}

	return out;
}

/**
 * Parses one rule body into declarations.
 *
 * @param {string} body Declarations, semicolon separated.
 *
 * @return {Object<string,string>} Property to value.
 */
function declarations( body ) {
	const out = {};

	for ( const part of body.split( ';' ) ) {
		const colon = part.indexOf( ':' );

		if ( -1 === colon ) {
			continue;
		}

		out[ part.slice( 0, colon ).trim() ] = part.slice( colon + 1 ).trim().replace( /\s+/g, ' ' );
	}

	return out;
}

/**
 * Reports whether a media condition admits a viewport width.
 *
 * Only `max-width` is understood. Anything else — `prefers-reduced-motion`, a `min-width`, a
 * comma-joined list — is reported as unknown rather than guessed at, and a block this cannot
 * read that also touches the selectors under test is a failure below rather than a silent
 * omission. An unread rule and an absent rule are indistinguishable in the result otherwise.
 *
 * @param {string} condition The media condition.
 * @param {number} width     Viewport width in pixels.
 *
 * @return {boolean|null} Whether it applies, or null when the condition is not understood.
 */
function applies( condition, width ) {
	const match = /^\(\s*max-width\s*:\s*(\d+)px\s*\)$/.exec( condition.trim() );

	if ( ! match ) {
		return null;
	}

	return width <= Number( match[ 1 ] );
}

/**
 * Collects the winning declarations for one selector at one viewport width.
 *
 * @param {Array} sheet    Parsed contexts.
 * @param {string} selector Selector, matched exactly.
 * @param {number} width   Viewport width in pixels.
 * @param {string[]} skipped Collects conditions that could not be read but name this selector.
 *
 * @return {Object<string,string>} The declarations in force.
 */
function computed( sheet, selector, width, skipped ) {
	const out = {};

	for ( const context of sheet ) {
		const admits = '' === context.condition ? true : applies( context.condition, width );
		const names = context.rules.some( ( rule ) => rule.selectors.includes( selector ) );

		if ( null === admits ) {
			if ( names ) {
				skipped.push( context.condition );
			}

			continue;
		}

		if ( ! admits ) {
			continue;
		}

		for ( const rule of context.rules ) {
			if ( ! rule.selectors.includes( selector ) ) {
				continue;
			}

			Object.assign( out, declarations( rule.body ) );
		}
	}

	return out;
}

/**
 * Evaluates a numeric CSS expression after substituting custom properties.
 *
 * `100%` resolves against a stated container width, so the result is in pixels and can be
 * compared with the gap. Only digits, operators, brackets and whitespace may survive the
 * substitution — anything else means a variable went unresolved, and evaluating that would turn
 * a missing declaration into an arithmetic result nobody could tell apart from a real one.
 *
 * @param {string} expression The value, with or without a `calc()` wrapper.
 * @param {Object<string,number>} vars      Custom property values, keyed without the `var()`.
 * @param {number}                container Container width in pixels, for `100%`.
 *
 * @return {number} The value in pixels.
 */
function evaluate( expression, vars, container ) {
	let text = expression;

	for ( const [ name, value ] of Object.entries( vars ) ) {
		text = text.split( `var(${ name })` ).join( String( value ) );
	}

	text = text
		.replace( /calc\(/g, '(' )
		.replace( /100%/g, String( container ) )
		.replace( /(\d)px/g, '$1' )
		.trim();

	if ( ! /^[\d\s.+*/()-]+$/.test( text ) ) {
		throw new Error( `unresolved expression: ${ expression } -> ${ text }` );
	}

	// eslint-disable-next-line no-new-func
	return Number( new Function( `return ${ text };` )() );
}

/**
 * Resolves the number of columns a gallery renders in, at one width.
 *
 * @param {Array}  sheet      Parsed contexts.
 * @param {number} configured The gallery's `columns` setting.
 * @param {number} width      Viewport width in pixels.
 * @param {string[]} skipped  Collects unreadable media conditions.
 *
 * @return {{columns: number, display: string, inert: string[]}} The resolution.
 */
function resolve( sheet, configured, width, skipped ) {
	const container = computed( sheet, '.lichtbild-columns', width, skipped );
	const item = computed( sheet, '.lichtbild-columns .lichtbild-item', width, skipped );
	const display = container.display || '';
	const inert = [];

	if ( 'grid' === display ) {
		// The desktop rule takes the configured count straight from the custom property, so the
		// track list has to be read rather than assumed: a hardcoded `repeat(3, ...)` would cap
		// every gallery at a number nobody chose, which is the mistake the `min()` avoids.
		const tracks = container[ 'grid-template-columns' ] || '';

		if ( ! /^repeat\(\s*var\(--lichtbild-columns\)\s*,/.test( tracks ) ) {
			throw new Error( `grid tracks do not follow the configured count: ${ tracks }` );
		}

		if ( item.flex ) {
			inert.push( `flex: ${ item.flex }` );
		}

		return { columns: configured, display, inert };
	}

	if ( 'flex' !== display ) {
		throw new Error( `unexpected container display at ${ width }px: ${ display || '(none)' }` );
	}

	// A track list surviving onto a flex container is the 26.8.12 defect exactly, and it is
	// silent in a browser. `none` is the only value that is not one.
	const tracks = container[ 'grid-template-columns' ];

	if ( tracks && 'none' !== tracks ) {
		inert.push( `grid-template-columns: ${ tracks }` );
	}

	const now = item[ '--lichtbild-columns-now' ] || '';
	const cap = /^min\(\s*var\(--lichtbild-columns\)\s*,\s*(\d+)\s*\)$/.exec( now );

	if ( ! cap ) {
		throw new Error( `no readable column cap at ${ width }px: ${ now || '(none)' }` );
	}

	return {
		columns: Math.min( configured, Number( cap[ 1 ] ) ),
		display,
		inert,
	};
}

const file = path.join( __dirname, '..', 'assets', 'css', 'lichtbild.css' );
const source = fs.readFileSync( file, 'utf8' ).replace( /\/\*[\s\S]*?\*\//g, '' );
const sheet = contexts( source );
const skipped = [];

// The three widths are a desktop, the tablet breakpoint's own boundary and a phone in portrait.
// 700 and 480 are the boundaries themselves, which is where an off-by-one between `max-width`
// and a `<` comparison would show up.
const WIDTHS = [ 1200, 700, 480 ];

// What each configured count must resolve to. 1 and 2 are the counts a cap must leave alone —
// a rule written as `repeat(3, ...)` rather than as a `min()` would promote both, which is the
// same defect pointing the other way and is invisible in a screenshot of a six-column gallery.
const EXPECTED = {
	1: { 1200: 1, 700: 1, 480: 1 },
	2: { 1200: 2, 700: 2, 480: 2 },
	6: { 1200: 6, 700: 3, 480: 2 },
};

for ( const configured of Object.keys( EXPECTED ).map( Number ) ) {
	for ( const width of WIDTHS ) {
		const wanted = EXPECTED[ configured ][ width ];
		let got;

		try {
			got = resolve( sheet, configured, width, skipped );
		} catch ( error ) {
			check( `columns=${ configured } at ${ width }px`, false, error.message );

			continue;
		}

		check(
			`columns=${ configured } at ${ width }px caps at ${ wanted }`,
			got.columns === wanted,
			`${ got.display }, resolved ${ got.columns }`
		);

		if ( got.inert.length ) {
			check(
				`columns=${ configured } at ${ width }px leaves no declaration for the other layout model`,
				false,
				got.inert.join( '; ' )
			);
		}
	}
}

// The arithmetic, which is the half that says the cap produces a row rather than merely a
// number. Each item's basis is the container less the gaps between N of them, divided by N; so
// N items and N-1 gaps must fill the row exactly, and N+1 must not fit.
for ( const [ configured, width, container ] of [ [ 6, 700, 690 ], [ 6, 480, 470 ], [ 2, 480, 470 ], [ 1, 700, 690 ] ] ) {
	const item = computed( sheet, '.lichtbild-columns .lichtbild-item', width, skipped );
	const columns = resolve( sheet, configured, width, skipped ).columns;
	const gap = Number( /(\d+)px/.exec( computed( sheet, '.lichtbild-wrap', width, skipped )[ '--lichtbild-gap' ] || '' )[ 1 ] );
	const basis = evaluate(
		( item.flex || '' ).replace( /^0 1 /, '' ),
		{ '--lichtbild-columns-now': columns, '--lichtbild-gap': gap },
		container
	);

	const fills = Math.abs( columns * basis + ( columns - 1 ) * gap - container ) < 0.000001;
	const overflows = ( columns + 1 ) * basis + columns * gap > container;

	check(
		`columns=${ configured } at ${ width }px tiles ${ columns } across a ${ container }px row`,
		fills && overflows,
		`basis ${ basis.toFixed( 2 ) }px, gap ${ gap }px, fills=${ fills }, next wraps=${ overflows }`
	);
}

// The control, and without it every check above is satisfied by a resolver that answers from
// `EXPECTED`. A synthetic stylesheet capping at four has to be reported as four.
const synthetic = contexts(
	`.lichtbild-wrap { --lichtbild-gap: 10px; }
	.lichtbild-columns { display: grid; grid-template-columns: repeat(var(--lichtbild-columns), minmax(0, 1fr)); }
	@media (max-width: 700px) {
		.lichtbild-columns { display: flex; grid-template-columns: none; }
		.lichtbild-columns .lichtbild-item { --lichtbild-columns-now: min(var(--lichtbild-columns), 4); }
	}`
);

check(
	'the resolver reads the stylesheet it is given rather than the expectation',
	4 === resolve( synthetic, 6, 700, [] ).columns && 6 === resolve( synthetic, 6, 1200, [] ).columns,
	`synthetic sheet resolved ${ resolve( synthetic, 6, 700, [] ).columns } at 700px`
);

// And its mirror: a stylesheet with the defect this file exists to catch must be reported, or
// the inert-declaration check above is decoration.
const defective = contexts(
	`.lichtbild-columns { display: grid; grid-template-columns: repeat(var(--lichtbild-columns), minmax(0, 1fr)); }
	@media (max-width: 700px) {
		.lichtbild-columns { display: flex; }
		.lichtbild-columns .lichtbild-item { --lichtbild-columns-now: min(var(--lichtbild-columns), 3); }
	}`
);

check(
	'a track list left in force on a flex container is reported',
	1 === resolve( defective, 6, 700, [] ).inert.length,
	JSON.stringify( resolve( defective, 6, 700, [] ).inert )
);

// A media block this parser cannot read is not evidence of anything, so it may not pass
// silently — but only when it names one of the selectors under test.
check(
	'every media block naming these selectors was understood',
	0 === skipped.length,
	skipped.join( '; ' )
);

console.log( `\n${ failures } failing` );

process.exit( failures > 0 ? 1 : 0 );
