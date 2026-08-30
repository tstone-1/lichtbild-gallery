'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const root = path.join( __dirname, '..' );
const relative = '.wordpress-org/blueprints/blueprint.json';
const blueprint = JSON.parse( fs.readFileSync( path.join( root, relative ), 'utf8' ) );
const plugin = fs.readFileSync( path.join( root, 'lichtbild-gallery.php' ), 'utf8' );
const postTypes = fs.readFileSync( path.join( root, 'includes/class-lichtbild-post-types.php' ), 'utf8' );
const readme = fs.readFileSync( path.join( root, 'readme.txt' ), 'utf8' );
const install = blueprint.steps.find( ( step ) => 'installPlugin' === step.step );
const galleryType = ( postTypes.match( /const GALLERY\s*=\s*'([^']+)'/ ) || [] )[ 1 ];
const pluginName = ( plugin.match( /^\s*\*\s*Plugin Name:\s*(.+?)\s*$/m ) || [] )[ 1 ];
const requiredPhp = ( readme.match( /^Requires PHP:\s*(\S+)\s*$/m ) || [] )[ 1 ];
let failed = 0;

function check( label, condition ) {
	console.log( `${ condition ? '[OK]  ' : '[FAIL]' } ${ label }` );
	failed += condition ? 0 : 1;
}

function version( value ) {
	return String( value ).split( '.' ).map( Number );
}

const previewPhp = version( blueprint.preferredVersions.php );
const floorPhp = version( requiredPhp );

check( 'the directory plugin is installed', install && 'wordpress.org/plugins' === install.pluginData.resource );
check( 'the plugin slug is current', install && 'lichtbild-gallery' === install.pluginData.slug );
check( 'the gallery post type is current', blueprint.landingPage.includes( `post_type=${ galleryType }` ) );
check( 'the preview opens an admin screen', blueprint.landingPage.startsWith( '/wp-admin/' ) );
check( 'the preview title matches the plugin header', pluginName === blueprint.meta.title );
check( 'the preview PHP meets the plugin floor', previewPhp[ 0 ] > floorPhp[ 0 ] || ( previewPhp[ 0 ] === floorPhp[ 0 ] && previewPhp[ 1 ] >= floorPhp[ 1 ] ) );
check( 'the readme points to the blueprint', readme.includes( relative ) );

console.log( `checks: 7, failing: ${ failed }` );
process.exit( failed ? 1 : 0 );
