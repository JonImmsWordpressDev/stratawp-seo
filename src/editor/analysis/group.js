/**
 * Split one group of results (seo or readability) into the four buckets the
 * panels show. Global checks are always included; keyword checks use the
 * keyword at keywordIndex.
 */
export function groupResults( output, registry, group, keywordIndex = 0 ) {
	const rows = [];
	registry.checks.forEach( ( def ) => {
		if ( def.group !== group ) {
			return;
		}
		const res =
			def.scope === 'global'
				? output.global[ def.id ]
				: output.keywords[ keywordIndex ]?.results[ def.id ];
		if ( res ) {
			rows.push( { def, res } );
		}
	} );
	const pick = ( ...statuses ) =>
		rows.filter( ( row ) => statuses.includes( row.res.status ) );
	return {
		problems: pick( 'fail' ),
		improvements: pick( 'warn' ),
		good: pick( 'pass' ),
		notAnalyzed: pick( 'na', 'error' ),
	};
}
