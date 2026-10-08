import golden from '../../../tests/fixtures/editor/golden.json';
import { runChecks } from '../analysis/checks';
import { scoreOutput } from '../analysis/score';

describe( 'JS instant tier matches the PHP engine', () => {
	golden.cases.forEach( ( c ) => {
		it( `output for ${ c.name }`, () => {
			expect( runChecks( c.input ) ).toEqual( c.output );
		} );
		it( `score for ${ c.name }`, () => {
			expect( scoreOutput( c.output, golden.registry ) ).toEqual( c.score );
		} );
	} );
} );

describe( 'instant tier behaviour', () => {
	it( 'passes keyword placement for the good post', () => {
		const good = golden.cases.find( ( c ) => c.name === 'good-post' );
		const r = runChecks( good.input ).keywords[ 0 ].results;
		expect( r.kw_in_title.status ).toBe( 'pass' );
		expect( r.kw_in_slug.status ).toBe( 'pass' );
	} );

	it( 'analyses a 5,000 word post quickly', () => {
		const input = {
			title: 'Big post',
			slug: 'big-post',
			content_html: '<p>' + 'Cold brew coffee is a good drink. '.repeat( 1000 ) + '</p>',
			keywords: [ { keyword: 'cold brew coffee' } ],
		};
		const start = Date.now();
		runChecks( input );
		expect( Date.now() - start ).toBeLessThan( 500 );
	} );
} );
