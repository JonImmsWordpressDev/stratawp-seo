import { ruleFix } from '../analysis/fixes';
import { charLength } from '../analysis/text';

describe( 'ruleFix', () => {
	it( 'suggests the keyword slug for a draft', () => {
		expect(
			ruleFix( 'kw_in_slug', { keyword: 'Cold Brew Coffee', slug: 'untitled', status: 'draft' } )
		).toEqual( { kind: 'slug', value: 'cold-brew-coffee', original: 'untitled' } );
	} );

	it( 'never rewrites the slug of a published or scheduled post', () => {
		expect( ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'x', status: 'publish' } ) ).toBeNull();
		expect( ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'x', status: 'future' } ) ).toBeNull();
	} );

	it( 'never rewrites the slug of a private post', () => {
		expect( ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'x', status: 'private' } ) ).toBeNull();
	} );

	it( 'treats a post as live when the saved status is live even if the edited status is draft', () => {
		expect(
			ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'x', status: 'draft', savedStatus: 'publish' } )
		).toBeNull();
		expect(
			ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'x', status: 'draft', savedStatus: 'future' } )
		).toBeNull();
		expect(
			ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'x', status: 'draft', savedStatus: 'private' } )
		).toBeNull();
	} );

	it( 'allows the slug fix for pending and draft saved posts', () => {
		expect(
			ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'x', status: 'pending', savedStatus: 'draft' } )
		).toEqual( { kind: 'slug', value: 'cold-brew', original: 'x' } );
	} );

	it( 'has no slug fix when the slug already matches', () => {
		expect( ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'cold-brew', status: 'draft' } ) ).toBeNull();
	} );

	it( 'trims a long title at a word boundary', () => {
		const title = 'How to make the best cold brew coffee at home in under ten minutes with simple tools';
		const fix = ruleFix( 'title_length', { title, metaTitle: '', preset: { title_max: 60 } } );
		expect( charLength( fix.value ) ).toBeLessThanOrEqual( 60 );
		expect( title.startsWith( fix.value ) ).toBe( true );
		expect( fix.value.endsWith( ' ' ) ).toBe( false );
		expect( fix.kind ).toBe( 'meta_title' );
	} );

	it( 'has no title fix when the title already fits', () => {
		expect( ruleFix( 'title_length', { title: 'Short title', preset: { title_max: 60 } } ) ).toBeNull();
	} );

	it( 'cuts a long description at a sentence end when one is close enough', () => {
		const metaDescription =
			'Cold brew is smooth. It is low in acid and easy to make at home. Follow this guide to get it right every single time you brew.';
		const fix = ruleFix( 'description_length', { metaDescription, preset: { desc_max: 70 } } );
		expect( fix.value ).toBe( 'Cold brew is smooth. It is low in acid and easy to make at home.' );
	} );

	it( 'returns null for checks without a rule fix', () => {
		expect( ruleFix( 'kw_density', {} ) ).toBeNull();
	} );
} );
