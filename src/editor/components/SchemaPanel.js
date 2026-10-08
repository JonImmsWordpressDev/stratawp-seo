import { __ } from '@wordpress/i18n';

export default function SchemaPanel( { data } ) {
	if ( ! data ) {
		return <p className="swps-editor__muted">{ __( 'Loading', 'stratawp-seo' ) }</p>;
	}
	const nodes = data.schema.nodes;
	if ( ! nodes.length ) {
		return (
			<p className="swps-editor__muted">
				{ __( 'No structured data in this post\'s content. Site-wide schema is added automatically.', 'stratawp-seo' ) }
			</p>
		);
	}
	return (
		<ul className="swps-ai__dims">
			{ nodes.map( ( n, i ) => (
				<li key={ `${ n.type }-${ i }` }>
					<span>{ n.type }</span>
					<span>
						{ n.missing.length
							? `${ __( 'Missing', 'stratawp-seo' ) }: ${ n.missing.join( ', ' ) }`
							: __( 'Complete', 'stratawp-seo' ) }
					</span>
				</li>
			) ) }
		</ul>
	);
}
