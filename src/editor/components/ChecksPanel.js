import { __ } from '@wordpress/i18n';
import { groupResults } from '../analysis/group';

const GLYPH = { fail: '✕', warn: '!', pass: '✓', na: '-', error: '-' };

function Row( { row, keyword, renderAction } ) {
	const { def, res } = row;
	return (
		<li className={ `swps-check swps-check--${ res.status }` } data-check={ def.id }>
			<span className="swps-check__glyph" aria-hidden="true">
				{ GLYPH[ res.status ] }
			</span>
			<span className="swps-check__body">
				<span className="swps-check__label">
					{ def.label }
					{ res.value !== null && res.value !== undefined && (
						<span className="swps-check__value"> ({ res.value })</span>
					) }
				</span>
				{ ( res.status === 'fail' || res.status === 'warn' ) && (
					<span className="swps-check__help">{ def.help }</span>
				) }
				{ res.status === 'na' && (
					<span className="swps-check__help">
						{ __( 'Not analyzed', 'stratawp-seo' ) }
					</span>
				) }
			</span>
			{ renderAction && renderAction( def, res, keyword ) }
		</li>
	);
}

function Bucket( { title, rows, keyword, renderAction } ) {
	if ( ! rows.length ) {
		return null;
	}
	return (
		<section className="swps-bucket">
			<h3 className="swps-bucket__title">
				{ title } ({ rows.length })
			</h3>
			<ul className="swps-bucket__list">
				{ rows.map( ( row ) => (
					<Row key={ row.def.id } row={ row } keyword={ keyword } renderAction={ renderAction } />
				) ) }
			</ul>
		</section>
	);
}

export default function ChecksPanel( {
	output,
	registry,
	group,
	keywordIndex = 0,
	renderAction,
} ) {
	if ( ! output ) {
		return (
			<p className="swps-editor__muted">
				{ __( 'Analysis could not run. It will retry after your next edit.', 'stratawp-seo' ) }
			</p>
		);
	}
	const g = groupResults( output, registry, group, keywordIndex );
	const keyword = output.keywords[ keywordIndex ]?.keyword || '';
	return (
		<div className="swps-checks">
			<Bucket title={ __( 'Problems', 'stratawp-seo' ) } rows={ g.problems } keyword={ keyword } renderAction={ renderAction } />
			<Bucket title={ __( 'Improvements', 'stratawp-seo' ) } rows={ g.improvements } keyword={ keyword } renderAction={ renderAction } />
			<Bucket title={ __( 'Good', 'stratawp-seo' ) } rows={ g.good } keyword={ keyword } renderAction={ renderAction } />
			<Bucket title={ __( 'Not analyzed', 'stratawp-seo' ) } rows={ g.notAnalyzed } keyword={ keyword } renderAction={ renderAction } />
		</div>
	);
}
