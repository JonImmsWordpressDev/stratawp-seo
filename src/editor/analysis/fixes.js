import { charLength, slugify } from './text';

const cutChars = ( s, n ) => Array.from( s ).slice( 0, n ).join( '' );

export function trimToWords( text, max ) {
	if ( charLength( text ) <= max ) {
		return text;
	}
	const cut = cutChars( text, max );
	const space = cut.lastIndexOf( ' ' );
	const base = space > max * 0.6 ? cut.slice( 0, space ) : cut;
	return base.replace( /[\s,;:-]+$/, '' );
}

export function trimToSentence( text, max ) {
	if ( charLength( text ) <= max ) {
		return text;
	}
	const probe = cutChars( text, max ) + ' ';
	const end = Math.max(
		probe.lastIndexOf( '. ' ),
		probe.lastIndexOf( '! ' ),
		probe.lastIndexOf( '? ' )
	);
	if ( end > max * 0.5 ) {
		return probe.slice( 0, end + 1 );
	}
	return trimToWords( text, max );
}

/**
 * Deterministic fixes that need no AI. Returns null when the fix does not
 * apply, so the sidebar shows no button.
 */
export function ruleFix( checkId, ctx ) {
	const {
		keyword = '',
		slug = '',
		title = '',
		metaTitle = '',
		metaDescription = '',
		status = 'draft',
		preset = {},
	} = ctx;

	if ( checkId === 'kw_in_slug' ) {
		const next = slugify( keyword );
		// Changing the URL of a live post breaks inbound links.
		if ( status === 'publish' || status === 'future' || ! next || next === slug ) {
			return null;
		}
		return { kind: 'slug', value: next, original: slug };
	}

	if ( checkId === 'title_length' ) {
		const original = metaTitle.trim() !== '' ? metaTitle : title;
		const max = preset.title_max || 60;
		if ( charLength( original.trim() ) <= max ) {
			return null;
		}
		return { kind: 'meta_title', value: trimToWords( original.trim(), max ), original };
	}

	if ( checkId === 'description_length' ) {
		const max = preset.desc_max || 160;
		if ( charLength( metaDescription.trim() ) <= max ) {
			return null;
		}
		return {
			kind: 'meta_description',
			value: trimToSentence( metaDescription.trim(), max ),
			original: metaDescription,
		};
	}

	return null;
}
