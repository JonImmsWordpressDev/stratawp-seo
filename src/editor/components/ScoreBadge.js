import { __ } from '@wordpress/i18n';

const LABELS = {
	good: __( 'Good', 'stratawp-seo' ),
	needs_work: __( 'Needs work', 'stratawp-seo' ),
	poor: __( 'Poor', 'stratawp-seo' ),
	no_keyword: __( 'Add a focus keyword', 'stratawp-seo' ),
};

export default function ScoreBadge( { score, legacy, aeo = null, error = null } ) {
	if ( ! score && error ) {
		return (
			<p className="swps-editor__muted">
				{ __( 'Analysis could not run. It will retry after your next edit.', 'stratawp-seo' ) }
			</p>
		);
	}
	if ( ! score ) {
		return (
			<p className="swps-editor__muted">{ __( 'Analyzing', 'stratawp-seo' ) }</p>
		);
	}
	const showLegacy =
		Number.isFinite( legacy ) && legacy > 0 && Math.abs( legacy - score.overall ) > 10;
	return (
		<div className={ `swps-score swps-score--${ score.status }` } data-testid="swps-score">
			<span className="swps-score__num">{ score.overall }</span>
			<span className="swps-score__label">{ LABELS[ score.status ] }</span>
			<span className="swps-score__sub">
				{ score.seo !== null && (
					<span>{ __( 'SEO', 'stratawp-seo' ) } { score.seo }</span>
				) }
				{ score.readability !== null && (
					<span>{ __( 'Readability', 'stratawp-seo' ) } { score.readability }</span>
				) }
				{ aeo !== null && (
					<span>{ __( 'AI visibility', 'stratawp-seo' ) } { aeo }</span>
				) }
			</span>
			{ showLegacy && (
				<span className="swps-score__legacy">
					{ __( 'Previous score', 'stratawp-seo' ) } { legacy }
				</span>
			) }
		</div>
	);
}
