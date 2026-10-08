import golden from '../../../tests/fixtures/editor/golden.json';
import { groupResults } from '../analysis/group';

const byName = ( name ) => golden.cases.find( ( c ) => c.name === name );

describe( 'groupResults', () => {
	it( 'puts passing checks under good and failing under problems', () => {
		const c = byName( 'long-passive-no-headings' );
		const g = groupResults( c.output, golden.registry, 'readability' );
		expect( g.problems.map( ( r ) => r.def.id ) ).toContain( 'sentence_length' );
		expect( g.improvements.map( ( r ) => r.def.id ) ).toContain( 'paragraph_length' );
	} );

	it( 'keeps every keyword check under notAnalyzed when no keyword is set', () => {
		const c = byName( 'empty-keyword' );
		const g = groupResults( c.output, golden.registry, 'seo' );
		const keywordIds = golden.registry.checks
			.filter( ( d ) => d.scope === 'keyword' && d.group === 'seo' )
			.map( ( d ) => d.id );
		expect( g.notAnalyzed.map( ( r ) => r.def.id ) ).toEqual(
			expect.arrayContaining( keywordIds )
		);
		expect( g.problems.some( ( r ) => r.def.scope === 'keyword' ) ).toBe( false );
	} );

	it( 'reads results for the requested keyword index', () => {
		const c = byName( 'related-keywords' );
		const first = groupResults( c.output, golden.registry, 'seo', 0 );
		const third = groupResults( c.output, golden.registry, 'seo', 2 );
		expect( first.good.length ).not.toBe( third.good.length );
	} );
} );
