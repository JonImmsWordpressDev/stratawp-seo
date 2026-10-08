// Mirror of includes/editor/class-editor-text.php. Keep the two identical:
// tests/fixtures/editor/golden.json pins them together.

const FOLD_FROM = 'àáâãäåçèéêëìíîïñòóôõöùúûüýÿ';
const FOLD_TO = 'aaaaaaceeeeiiiinooooouuuuyy';
const FOLD_MAP = {};
Array.from( FOLD_FROM ).forEach( ( ch, i ) => {
	FOLD_MAP[ ch ] = FOLD_TO[ i ];
} );

const ENTITIES = {
	amp: '&',
	nbsp: ' ',
	quot: '"',
	'#039': "'",
	'#8217': "'",
	lt: '<',
	gt: '>',
};

// PHP trim() default set only (JS String.trim() also strips NBSP, U+3000 etc).
export const trim = ( s ) => s.replace( /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '' );

export const lower = ( s ) => s.toLowerCase();

export const fold = ( s ) =>
	Array.from( s )
		.map( ( ch ) => FOLD_MAP[ ch ] ?? ch )
		.join( '' );

export const slugify = ( s ) =>
	fold( lower( s ) )
		.replace( /[^\p{L}\p{N}]+/gu, '-' )
		.replace( /^-+|-+$/g, '' );

export const hostKey = ( h ) => lower( trim( h ) ).replace( /^www\./, '' );

export const charLength = ( s ) => Array.from( s ).length;

export function plain( html ) {
	let sanitized = html;
	let previous;

	do {
		previous = sanitized;
		sanitized = sanitized
			.replace( /<!--[\s\S]*?-->/g, ' ' )
			.replace( /<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ' )
			.replace( /\[\/?[a-z_][\w-]*(?:\s[^\]]*)?\]/gi, ' ' )
			.replace( /<[^>]*>/g, '' );
	} while ( sanitized !== previous );

	return trim(
		sanitized
			.replace(
				/<\/(?:p|div|h[1-6]|li|blockquote|tr|ul|ol)>|<br\s*\/?>/gi,
				'\n'
			)
			.replace( /&(amp|nbsp|quot|#039|#8217|lt|gt);/g, ( m, k ) => ENTITIES[ k ] )
			.replace( /[ \t\u00a0]+/g, ' ' )
			.replace( / *\n[ \n]*/g, '\n' )
	);
}

export const words = ( text ) =>
	text.split( /[^\p{L}\p{N}'’-]+/u ).filter( Boolean );

export function sentences( text ) {
	const out = [];
	text.split( '\n' ).forEach( ( line ) => {
		line.split( /(?<=[.!?])\s+/u ).forEach( ( part ) => {
			const t = trim( part );
			if ( t !== '' && words( t ).length > 0 ) {
				out.push( t );
			}
		} );
	} );
	return out;
}

export function paragraphs( html ) {
	const out = [];
	for ( const m of html.matchAll( /<p\b[^>]*>([\s\S]*?)<\/p>/gi ) ) {
		const t = plain( m[ 1 ] );
		if ( t !== '' ) {
			out.push( t );
		}
	}
	if ( ! out.length ) {
		plain( html )
			.split( '\n' )
			.forEach( ( line ) => {
				const t = trim( line );
				if ( t !== '' ) {
					out.push( t );
				}
			} );
	}
	return out;
}

export function headings( html ) {
	const out = [];
	for ( const m of html.matchAll( /<h([1-6])\b[^>]*>([\s\S]*?)<\/h\1>/gi ) ) {
		out.push( { level: Number( m[ 1 ] ), text: plain( m[ 2 ] ) } );
	}
	return out;
}

export function imageAlts( html ) {
	const out = [];
	for ( const m of html.matchAll( /<img\b[^>]*>/gi ) ) {
		const a = m[ 0 ].match(
			/(?<![\w-])alt\s*=\s*(?:"([^"]*)"|'([^']*)')/i
		);
		let alt = '';
		if ( a ) {
			alt = ( a[ 1 ] ?? '' ) !== '' ? a[ 1 ] : a[ 2 ] ?? '';
		}
		out.push( trim( alt ) );
	}
	return out;
}

export function linkCounts( html, host ) {
	const own = hostKey( host );
	let internal = 0;
	let external = 0;
	for ( const m of html.matchAll(
		/<a\b[^>]*?(?<![\w-])href\s*=\s*(?:"([^"]*)"|'([^']*)')/gi
	) ) {
		const href = trim( ( m[ 1 ] ?? '' ) !== '' ? m[ 1 ] : m[ 2 ] ?? '' );
		if ( href === '' || /^(#|mailto:|tel:|javascript:)/i.test( href ) ) {
			continue;
		}
		const h = href.match( /^(?:https?:)?\/\/([^/:?#]+)/i );
		if ( h ) {
			if ( hostKey( h[ 1 ] ) === own ) {
				internal++;
			} else {
				external++;
			}
		} else {
			internal++;
		}
	}
	return { internal, external };
}
