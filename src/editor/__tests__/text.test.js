import { trim } from '../analysis/text';
import { runChecks, normalize } from '../analysis/checks';

describe( 'trim (PHP trim() semantics)', () => {
	it( 'strips the PHP default character set', () => {
		expect( trim( ' \t\n\r\0\x0Bab \t\n\r\0\x0B' ) ).toBe( 'ab' );
	} );

	it( 'keeps NBSP and other unicode spaces that JS trim() would strip', () => {
		expect( trim( ' ab ' ) ).toBe( ' ab ' );
		expect( trim( '　ab ' ) ).toBe( '　ab ' );
		expect( trim( '﻿ab' ) ).toBe( '﻿ab' );
	} );
} );

describe( 'normalize and runChecks input tolerance', () => {
	const base = { title: 'T', slug: 's', content_html: '<p>Hi there.</p>' };

	it( 'treats null or non-array keywords as no keyword', () => {
		[ null, undefined, 'x', 5, {} ].forEach( ( keywords ) => {
			const out = runChecks( { ...base, keywords } );
			expect( out.keywords ).toHaveLength( 1 );
			expect( out.keywords[ 0 ].keyword ).toBe( '' );
		} );
	} );

	it( 'treats a null keyword row as an empty row', () => {
		const out = runChecks( { ...base, keywords: [ null ] } );
		expect( out.keywords[ 0 ].keyword ).toBe( '' );
		expect( normalize( { ...base, keywords: [ null ] } ).keywords[ 0 ] ).toEqual( {
			keyword: '',
			used_elsewhere: null,
		} );
	} );
} );
