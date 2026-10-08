import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

const DIMENSIONS = [
	[ 'extractability', __( 'Extractability', 'stratawp-seo' ) ],
	[ 'markup', __( 'Markup', 'stratawp-seo' ) ],
	[ 'authority', __( 'Authority', 'stratawp-seo' ) ],
	[ 'coverage', __( 'Coverage', 'stratawp-seo' ) ],
];
const QUERY_GLYPH = { answered: '✓', partial: '!', missing: '✕' };

export default function AiVisibilityPanel( {
	data,
	loading,
	error,
	dirty,
	focus,
	onRescore,
	onTrack,
	renderQueryAction,
} ) {
	if ( ! data ) {
		return error ? (
			<Notice status="warning" isDismissible={ false }>{ error }</Notice>
		) : (
			<p className="swps-editor__muted">{ __( 'Loading', 'stratawp-seo' ) }</p>
		);
	}
	const { aeo, citations } = data;

	return (
		<div className="swps-ai">
			{ error && <Notice status="warning" isDismissible={ false }>{ error }</Notice> }
			{ aeo.error && <Notice status="warning" isDismissible={ false }>{ aeo.error }</Notice> }

			<div className="swps-ai__score" data-testid="swps-aeo">
				<strong>{ aeo.total === null ? __( 'Not scored yet', 'stratawp-seo' ) : aeo.total }</strong>
				<span>{ __( 'AI visibility score', 'stratawp-seo' ) }</span>
				{ loading && <Spinner /> }
			</div>
			{ aeo.scanned && (
				<p className="swps-editor__muted">
					{ __( 'Last scored', 'stratawp-seo' ) } { new Date( aeo.scanned * 1000 ).toLocaleString() }
				</p>
			) }
			{ aeo.stale && (
				<p className="swps-editor__muted">
					{ __( 'This score is from an earlier version of the post.', 'stratawp-seo' ) }
				</p>
			) }

			<ul className="swps-ai__dims">
				{ DIMENSIONS.map( ( [ key, label ] ) => (
					<li key={ key }>
						<span>{ label }</span>
						<span>{ aeo.subscores[ key ] === null ? '-' : aeo.subscores[ key ] }</span>
					</li>
				) ) }
			</ul>

			<Button
				variant="secondary"
				onClick={ onRescore }
				disabled={ dirty || loading }
				title={ dirty ? __( 'Save the post first. The score reads the saved copy.', 'stratawp-seo' ) : undefined }
			>
				{ __( 'Re-score (may use AI)', 'stratawp-seo' ) }
			</Button>

			{ aeo.sub_queries.length > 0 && (
				<>
					<h3 className="swps-bucket__title">{ __( 'Questions an answer engine would ask', 'stratawp-seo' ) }</h3>
					<ul className="swps-ai__queries">
						{ aeo.sub_queries.map( ( sq ) => (
							<li key={ sq.q } className={ `swps-query swps-query--${ sq.status }` }>
								<span aria-hidden="true">{ QUERY_GLYPH[ sq.status ] }</span>
								<span className="swps-query__q">{ sq.q }</span>
								{ renderQueryAction && sq.status !== 'answered' && renderQueryAction( sq ) }
							</li>
						) ) }
					</ul>
				</>
			) }

			<h3 className="swps-bucket__title">{ __( 'AI citations', 'stratawp-seo' ) }</h3>
			{ citations.tracked ? (
				<ul className="swps-ai__dims">
					{ Object.entries( citations.states ).map( ( [ engine, s ] ) => (
						<li key={ engine }>
							<span>{ engine }</span>
							<span>{ s.state }{ s.last_date ? ` (${ s.last_date })` : '' }</span>
						</li>
					) ) }
					{ Object.keys( citations.states ).length === 0 && (
						<li>{ __( 'Tracked. No checks have run yet.', 'stratawp-seo' ) }</li>
					) }
				</ul>
			) : (
				<>
					<p className="swps-editor__muted">
						{ __( 'No citation data for this post yet.', 'stratawp-seo' ) }
					</p>
					{ focus && window.swpsEditor?.canManage && (
						<Button variant="secondary" onClick={ () => onTrack( focus ) }>
							{ __( 'Track this keyword for AI citations', 'stratawp-seo' ) }
						</Button>
					) }
				</>
			) }
		</div>
	);
}
