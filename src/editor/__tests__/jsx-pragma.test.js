import path from 'path';
import { transformSync } from '@babel/core';

// The plugin supports WordPress 6.0, and the react-jsx-runtime script only
// exists from 6.6, so JSX must compile to wp.element.createElement.
const compile = ( code ) =>
	transformSync( code, {
		filename: path.join( __dirname, '../components/Example.js' ),
		configFile: path.resolve( __dirname, '../../../babel.config.js' ),
		babelrc: false,
		envName: 'production',
	} ).code;

describe( 'JSX compilation', () => {
	it( 'uses createElement from @wordpress/element, not the JSX runtime', () => {
		const out = compile( 'export const A = () => <><p className="x">hi</p></>;' );

		expect( out ).not.toMatch( /jsx-runtime/ );
		expect( out ).toMatch( /import \{ createElement, Fragment \} from "@wordpress\/element"/ );
		expect( out ).toMatch( /createElement\(Fragment, null, createElement\("p"/ );
	} );

	it( 'does not import twice when the file already imports createElement', () => {
		const out = compile(
			"import { createElement, useState } from '@wordpress/element';\nexport const A = () => <p />;"
		);

		expect( out.match( /createElement/g ).length ).toBe( 2 );
		expect( out ).not.toMatch( /Fragment/ );
	} );

	it( 'leaves files without JSX alone', () => {
		const out = compile( 'export const x = 1;' );

		expect( out ).not.toMatch( /@wordpress\/element/ );
	} );
} );
