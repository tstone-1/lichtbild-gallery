// Run the complete editor with a small jQuery/media-frame stand-in: node tests/editor-js-test.js
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
process.on( 'uncaughtException', error => {
	console.error( '[FAIL] ' + error.message );
	process.exitCode = 1;
} );
const events = {};
let selection;
function row( id, tags, key ) {
	const result = { id: { value: String( id ) }, tags: { value: tags }, key };
	result.tags.row = result;
	return result;
}
const rows = [ row( 7, 'old', 'a' ), row( 7, 'old', 'b' ), row( 8, 'other', 'c' ) ];
const list = {};
function wrap( nodes ) {
	return {
		length: nodes.length,
		each( fn ) { nodes.forEach( node => fn.call( node ) ); return this; },
		find( selector ) {
			if ( nodes[0] === list ) return wrap( rows );
			return wrap( nodes.map( node => selector === '.lichtbild-editor__tags' ? node.tags : node.id ) );
		},
		closest() { return wrap( [ nodes[0].row ] ); },
		val( value ) { if ( value === undefined ) return nodes[0]?.value; nodes.forEach( node => { node.value = value; } ); return this; },
		attr() { return nodes[0].key; },
		on( type, selector, fn ) { events[ selector || type ] = fn || selector; return this; },
		sortable() { return this; },
		toggle() { return this; },
		append( node ) { rows.push( node ); return this; }
	};
}
function $( value ) {
	if ( typeof value === 'function' ) { value(); return; }
	if ( typeof value !== 'string' ) return wrap( [ value ] );
	if ( value === '#lichtbild-editor-items' ) return wrap( [ list ] );
	if ( value.includes( '.lichtbild-editor__item' ) ) return wrap( rows );
	return wrap( [ {} ] );
}
const wp = {
	template: () => data => row( data.id, data.tags, data.key ),
	media: () => ( {
		open() {}, on( name, fn ) { selection = fn; },
		state: () => ( { get: () => ( { each: fn => fn( { toJSON: () => ( { id: 7, lichtbildTags: 'stale library tags' } ) } ) } ) } )
	} )
};
vm.runInNewContext( fs.readFileSync( path.join( __dirname, '../assets/js/editor.js' ), 'utf8' ), { jQuery: $, wp, LichtbildEditor: { i18n: {} } } );
rows[0].tags.value = 'edited';
events[ '.lichtbild-editor__tags' ].call( rows[0].tags );
assert.equal( rows[1].tags.value, 'edited', 'tag edits update another occurrence' );
assert.equal( rows[2].tags.value, 'other' );
rows.reverse();
rows[1].tags.value = 'after reorder';
events[ '.lichtbild-editor__tags' ].call( rows[1].tags );
assert.equal( rows[2].tags.value, 'after reorder' );
// The add button's direct click handler opens the actual picker path.
const click = Object.values( events ).find( fn => typeof fn === 'function' && fn.name === 'openPicker' );
assert.ok( click );
click();
selection();
assert.equal( rows.at( -1 ).tags.value, 'after reorder', 'new occurrence inherits unsaved tags' );
console.log( '[OK] tag edits synchronize duplicates, survive reorder and reach newly added occurrences' );
