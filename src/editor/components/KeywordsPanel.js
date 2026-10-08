import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';
import { useKeywords } from '../hooks/useKeywords';
import KeywordSuggestions from './KeywordSuggestions';

function summary( output, registry, index ) {
	if ( ! output || ! output.keywords[ index ] || ! output.keywords[ index ].keyword ) {
		return '';
	}
	const ids = registry.checks.filter( ( d ) => d.scope === 'keyword' ).map( ( d ) => d.id );
	const results = ids
		.map( ( id ) => output.keywords[ index ].results[ id ] )
		.filter( ( r ) => r && r.status !== 'na' );
	const passing = results.filter( ( r ) => r.status === 'pass' ).length;
	return `${ passing } / ${ results.length }`;
}

export default function KeywordsPanel( { output, activeIndex, onSelect, onRemove, children } ) {
	const { focus, related, setFocus, setRelated } = useKeywords();
	const [ draft, setDraft ] = useState( '' );
	const registry = ( window.swpsEditor || {} ).registry || { checks: [] };

	const add = () => {
		const value = draft.trim();
		if ( ! value || related.length >= 4 ) {
			return;
		}
		setRelated( [ ...related, value ] );
		setDraft( '' );
	};

	return (
		<div className="swps-keywords">
			<TextControl
				label={ __( 'Focus keyword', 'stratawp-seo' ) }
				value={ focus }
				onChange={ setFocus }
				help={ summary( output, registry, 0 ) }
			/>
			<ul className="swps-keywords__list">
				{ related.map( ( kw, i ) => (
					<li key={ kw } className={ activeIndex === i + 1 ? 'is-active' : '' }>
						<Button variant="link" onClick={ () => onSelect( i + 1 ) }>{ kw }</Button>
						<span className="swps-keywords__sum">{ summary( output, registry, i + 1 ) }</span>
						<Button
							icon="no-alt"
							label={ __( 'Remove related keyword', 'stratawp-seo' ) + ': ' + kw }
							onClick={ () => {
								setRelated( related.filter( ( k ) => k !== kw ) );
								if ( onRemove ) {
									onRemove( i + 1 );
								}
							} }
						/>
					</li>
				) ) }
			</ul>
			{ related.length < 4 && (
				<div className="swps-keywords__add">
					<TextControl
						label={ __( 'Add a related keyword', 'stratawp-seo' ) }
						value={ draft }
						onChange={ setDraft }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								e.preventDefault();
								add();
							}
						} }
					/>
					<Button variant="secondary" onClick={ add }>{ __( 'Add', 'stratawp-seo' ) }</Button>
				</div>
			) }
			{ activeIndex > 0 && (
				<Button variant="link" onClick={ () => onSelect( 0 ) }>
					{ __( 'Show checks for the focus keyword', 'stratawp-seo' ) }
				</Button>
			) }
			<KeywordSuggestions />
			{ children }
		</div>
	);
}
