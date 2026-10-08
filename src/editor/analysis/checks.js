// Mirror of includes/editor/class-editor-check-engine.php.
import * as T from './text';

const TRANSITIONS = [
	'however',
	'therefore',
	'moreover',
	'furthermore',
	'consequently',
	'meanwhile',
	'additionally',
	'similarly',
	'for example',
	'for instance',
	'in addition',
	'as a result',
	'in conclusion',
	'on the other hand',
	'in contrast',
	'finally',
	'first',
	'second',
	'third',
	'next',
	'then',
	'also',
	'because',
	'although',
	'while',
	'since',
	'instead',
	'otherwise',
	'nevertheless',
	'thus',
	'hence',
	'besides',
	'likewise',
	'specifically',
	'in fact',
	'in summary',
];
const escapeRegExp = ( value ) => value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
const TRANSITION_RE = new RegExp( '\\b(?:' + TRANSITIONS.map( escapeRegExp ).join( '|' ) + ')\\b', 'i' );
const PASSIVE_RE = /\b(?:is|are|was|were|be|been|being)\s+(?:\w+ed|\w+en)\b/i;

const KEYWORD_IDS = [
	'kw_in_title',
	'kw_in_description',
	'kw_in_slug',
	'kw_in_intro',
	'kw_in_subheading',
	'kw_in_image_alt',
	'kw_density',
	'kw_title_position',
	'kw_in_conclusion',
	'kw_unique',
];

const rnd = ( x ) => Math.floor( x + 0.5 );
const r = ( status, value = null ) => ( { status, value } );
const has = ( haystack, needleLower ) => T.lower( haystack ).includes( needleLower );
const countMatches = ( haystack, needle ) => {
	const text = T.lower( haystack );
	const search = T.lower( needle );
	if ( search === '' ) {
		return 0;
	}
	const matches = text.match( new RegExp( escapeRegExp( search ), 'gi' ) );
	return matches ? matches.length : 0;
};

export function normalize( input ) {
	const preset = {
		min_words: 300,
		title_min: 30,
		title_max: 60,
		desc_min: 70,
		desc_max: 160,
		...( input.preset || {} ),
	};
	Object.keys( preset ).forEach( ( k ) => {
		preset[ k ] = Math.trunc( Number( preset[ k ] ) ) || 0;
	} );

	let keywords = ( Array.isArray( input.keywords ) ? input.keywords : [] ).map( ( raw ) => {
		const row = raw ?? {};
		return {
			keyword: T.trim( String( row.keyword ?? '' ) ),
			used_elsewhere:
				row.used_elsewhere === undefined || row.used_elsewhere === null
					? null
					: Boolean( row.used_elsewhere ),
		};
	} );
	if ( ! keywords.length ) {
		keywords = [ { keyword: '', used_elsewhere: null } ];
	}

	return {
		title: String( input.title ?? '' ),
		slug: String( input.slug ?? '' ),
		content_html: String( input.content_html ?? '' ),
		meta_title: String( input.meta_title ?? '' ),
		meta_description: String( input.meta_description ?? '' ),
		lang: String( input.lang ?? '' ) || 'en',
		host: String( input.host ?? '' ),
		preset,
		keywords,
	};
}

function context( inp ) {
	const html = inp.content_html;
	const text = T.plain( html );
	const sentences = T.sentences( text );
	return {
		title: T.trim( inp.meta_title ) !== '' ? inp.meta_title : inp.title,
		text,
		wordCount: T.words( text ).length,
		wordbased: ! /^(ja|zh|ko|th)/i.test( inp.lang ),
		english: /^en/i.test( inp.lang ),
		paragraphs: T.paragraphs( html ),
		headings: T.headings( html ),
		alts: T.imageAlts( html ),
		links: T.linkCounts( html, inp.host ),
		sentences,
		swords: sentences.map( T.words ),
	};
}

function globalChecks( inp, c ) {
	const p = inp.preset;
	const g = {};

	const tl = T.charLength( T.trim( c.title ) );
	g.title_length =
		tl === 0
			? r( 'fail', 0 )
			: r( tl >= p.title_min && tl <= p.title_max ? 'pass' : 'warn', tl );

	const dl = T.charLength( T.trim( inp.meta_description ) );
	g.description_length =
		dl === 0
			? r( 'fail', 0 )
			: r( dl >= p.desc_min && dl <= p.desc_max ? 'pass' : 'warn', dl );

	if ( c.wordbased ) {
		const wc = c.wordCount;
		g.content_length = r(
			wc >= p.min_words ? 'pass' : wc * 2 >= p.min_words ? 'warn' : 'fail',
			wc
		);
	} else {
		g.content_length = r( 'na' );
	}

	g.internal_links = r( c.links.internal > 0 ? 'pass' : 'warn', c.links.internal );
	g.external_links = r( c.links.external > 0 ? 'pass' : 'warn', c.links.external );

	if ( c.wordbased && c.wordCount >= 300 ) {
		let max = 0;
		inp.content_html
			.split( /<h[1-6]\b[^>]*>[\s\S]*?<\/h[1-6]>/gi )
			.forEach( ( seg ) => {
				max = Math.max( max, T.words( T.plain( seg ) ).length );
			} );
		g.subheading_distribution = r( max > 300 ? 'warn' : 'pass', max );
	} else {
		g.subheading_distribution = r( 'na' );
	}

	if ( c.alts.length ) {
		const missing = c.alts.filter( ( a ) => a === '' ).length;
		g.image_alt_missing = r( missing === 0 ? 'pass' : 'warn', missing );
	} else {
		g.image_alt_missing = r( 'na' );
	}

	const n = c.sentences.length;
	const ready = c.wordbased && n >= 3;

	if ( ready ) {
		const long = c.swords.filter( ( w ) => w.length > 20 ).length;
		const pct = rnd( ( long / n ) * 100 );
		g.sentence_length = r( pct <= 25 ? 'pass' : pct <= 35 ? 'warn' : 'fail', pct );
	} else {
		g.sentence_length = r( 'na' );
	}

	if ( c.wordbased && c.paragraphs.length ) {
		const long = c.paragraphs.filter( ( para ) => T.words( para ).length > 150 ).length;
		g.paragraph_length = r( long === 0 ? 'pass' : 'warn', long );
	} else {
		g.paragraph_length = r( 'na' );
	}

	if ( ready && c.english ) {
		let hits = 0;
		let passive = 0;
		c.sentences.forEach( ( s ) => {
			if ( TRANSITION_RE.test( s ) ) {
				hits++;
			}
			if ( PASSIVE_RE.test( s ) ) {
				passive++;
			}
		} );
		const tp = rnd( ( hits / n ) * 100 );
		const pp = rnd( ( passive / n ) * 100 );
		g.transition_words = r( tp >= 20 ? 'pass' : tp >= 10 ? 'warn' : 'fail', tp );
		g.passive_voice = r( pp <= 10 ? 'pass' : pp <= 15 ? 'warn' : 'fail', pp );
	} else {
		g.transition_words = r( 'na' );
		g.passive_voice = r( 'na' );
	}

	if ( ready ) {
		let run = 1;
		let best = 1;
		let prev = null;
		c.swords.forEach( ( w ) => {
			const first = T.lower( w[ 0 ] );
			run = first === prev ? run + 1 : 1;
			best = Math.max( best, run );
			prev = first;
		} );
		g.consecutive_starters = r( best >= 3 ? 'warn' : 'pass', best );
	} else {
		g.consecutive_starters = r( 'na' );
	}

	return g;
}

function keywordChecks( row, inp, c ) {
	const kw = row.keyword;
	if ( kw === '' ) {
		const na = {};
		KEYWORD_IDS.forEach( ( id ) => {
			na[ id ] = r( 'na' );
		} );
		return na;
	}

	const k = T.lower( kw );
	const res = {};

	res.kw_in_title = r( has( c.title, k ) ? 'pass' : 'fail' );

	const desc = T.trim( inp.meta_description );
	res.kw_in_description = r( desc !== '' && has( desc, k ) ? 'pass' : 'fail' );

	const slugKw = T.slugify( kw );
	if ( slugKw === '' ) {
		res.kw_in_slug = r( 'na' );
	} else {
		const slug = T.fold( T.lower( inp.slug ) );
		res.kw_in_slug = r( slug.includes( slugKw ) ? 'pass' : 'fail' );
	}

	res.kw_in_intro = c.paragraphs.length
		? r( has( c.paragraphs[ 0 ], k ) ? 'pass' : 'fail' )
		: r( 'na' );

	const subs = c.headings.filter( ( h ) => h.level === 2 || h.level === 3 );
	res.kw_in_subheading = subs.length
		? r( subs.some( ( h ) => has( h.text, k ) ) ? 'pass' : 'fail' )
		: r( 'na' );

	res.kw_in_image_alt = c.alts.length
		? r( c.alts.some( ( a ) => has( a, k ) ) ? 'pass' : 'fail' )
		: r( 'na' );

	if ( c.wordbased && c.wordCount > 0 ) {
		const occ = countMatches( c.text, kw );
		const kwords = Math.max( 1, T.words( kw ).length );
		const percent = ( ( occ * kwords ) / c.wordCount ) * 100;
		const density = Math.floor( percent * 100 + 0.5 ) / 100;
		let st;
		if ( occ === 0 ) {
			st = 'fail';
		} else if ( density < 0.5 ) {
			st = 'warn';
		} else if ( density <= 3 ) {
			st = 'pass';
		} else {
			st = 'fail';
		}
		res.kw_density = r( st, density );
	} else {
		res.kw_density = r( 'na' );
	}

	const titleLower = T.lower( c.title );
	const at = titleLower.indexOf( k );
	if ( at < 0 ) {
		res.kw_title_position = r( 'na' );
	} else {
		const idx = T.charLength( titleLower.slice( 0, at ) );
		res.kw_title_position = r(
			idx * 2 <= T.charLength( titleLower ) ? 'pass' : 'warn',
			idx
		);
	}

	res.kw_in_conclusion =
		c.paragraphs.length >= 3
			? r( has( c.paragraphs[ c.paragraphs.length - 1 ], k ) ? 'pass' : 'warn' )
			: r( 'na' );

	res.kw_unique =
		row.used_elsewhere === null
			? r( 'na' )
			: r( row.used_elsewhere ? 'fail' : 'pass' );

	return res;
}

export function runChecks( input ) {
	const inp = normalize( input );
	const c = context( inp );
	return {
		global: globalChecks( inp, c ),
		keywords: inp.keywords.map( ( row ) => ( {
			keyword: row.keyword,
			results: keywordChecks( row, inp, c ),
		} ) ),
	};
}
