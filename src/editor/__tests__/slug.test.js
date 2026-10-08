import { deriveSlug } from '../analysis/slug';

describe( 'deriveSlug', () => {
	it( 'prefers the edited slug', () => {
		expect( deriveSlug( 'my-slug', 'Cold brew coffee guide', 'other' ) ).toBe( 'my-slug' );
	} );

	it( 'falls back to the editor derived slug', () => {
		expect( deriveSlug( '', 'Cold brew coffee guide', 'from-editor' ) ).toBe( 'from-editor' );
	} );

	it( 'falls back to the title when nothing else is known', () => {
		expect( deriveSlug( '', 'Cold brew coffee guide' ) ).toBe( 'cold-brew-coffee-guide' );
		expect( deriveSlug( '', 'Cold brew: the guide', '' ) ).toBe( 'cold-brew-the-guide' );
	} );

	it( 'is empty with no slug and no title', () => {
		expect( deriveSlug( '', '' ) ).toBe( '' );
	} );
} );
