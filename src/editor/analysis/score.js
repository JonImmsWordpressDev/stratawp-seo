// Mirror of SWPS_Editor_Check_Engine::score().
const rnd = ( x ) => Math.floor( x + 0.5 );

export function scoreOutput( output, registry ) {
	const sum = {};
	const den = {};
	const add = ( dim, w, status ) => {
		if ( status === 'na' ) {
			return;
		}
		const v = status === 'pass' ? 1 : status === 'warn' ? 0.5 : 0;
		sum[ dim ] = ( sum[ dim ] ?? 0 ) + w * v;
		den[ dim ] = ( den[ dim ] ?? 0 ) + w;
	};

	registry.checks.forEach( ( def ) => {
		if ( def.scope === 'global' ) {
			add( def.dimension, 1, output.global[ def.id ]?.status ?? 'na' );
			return;
		}
		output.keywords.forEach( ( kw, i ) => {
			add( def.dimension, i === 0 ? 1 : 0.25, kw.results[ def.id ]?.status ?? 'na' );
		} );
	} );

	const dims = Object.keys( registry.weights );
	const dimScore = {};
	dims.forEach( ( dim ) => {
		if ( den[ dim ] > 0 ) {
			dimScore[ dim ] = sum[ dim ] / den[ dim ];
		}
	} );

	const weighted = ( subset ) => {
		let num = 0;
		let d = 0;
		dims.forEach( ( dim ) => {
			if ( dimScore[ dim ] === undefined ) {
				return;
			}
			if ( subset === 'seo' && dim === 'readability' ) {
				return;
			}
			if ( subset === 'readability' && dim !== 'readability' ) {
				return;
			}
			const w = registry.weights[ dim ];
			num += w * dimScore[ dim ];
			d += w;
		} );
		return d > 0 ? rnd( ( num / d ) * 100 ) : null;
	};

	const overall = weighted( 'all' ) ?? 0;
	const hasKeyword = ( output.keywords[ 0 ]?.keyword ?? '' ) !== '';
	let status = 'poor';
	if ( ! hasKeyword ) {
		status = 'no_keyword';
	} else if ( overall >= 75 ) {
		status = 'good';
	} else if ( overall >= 50 ) {
		status = 'needs_work';
	}

	return {
		overall,
		seo: weighted( 'seo' ),
		readability: weighted( 'readability' ),
		status,
	};
}
