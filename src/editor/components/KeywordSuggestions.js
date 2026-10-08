import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { Button, Notice } from '@wordpress/components';
import { useKeywords } from '../hooks/useKeywords';

export default function KeywordSuggestions() {
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const { focus, related, setFocus, setRelated } = useKeywords();
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const load = async ( useAi ) => {
		setBusy( true );
		setError( null );
		try {
			setResult(
				await apiFetch( {
					path: '/swps/v1/editor/keyword-suggestions',
					method: 'POST',
					data: { post_id: postId, use_ai: useAi, seed: focus },
				} )
			);
		} catch ( e ) {
			setError( e && e.message ? e.message : String( e ) );
		}
		setBusy( false );
	};

	const addRelated = ( kw ) => {
		if ( kw && ! related.includes( kw ) && related.length < 4 ) {
			setRelated( [ ...related, kw ] );
		}
	};

	const rows = result ? [ ...result.gsc.map( ( r ) => r.query ), ...result.ai.map( ( r ) => r.keyword ) ] : [];

	return (
		<div className="swps-suggest">
			<div className="swps-suggest__actions">
				<Button variant="secondary" size="small" onClick={ () => load( false ) } disabled={ busy }>
					{ __( 'Suggest from Search Console', 'stratawp-seo' ) }
				</Button>
				<Button variant="secondary" size="small" onClick={ () => load( true ) } disabled={ busy }>
					{ __( 'Suggest with AI', 'stratawp-seo' ) }
				</Button>
			</div>
			{ error && <Notice status="warning" isDismissible={ false }>{ error }</Notice> }
			{ result && ! rows.length && ! error && (
				<p className="swps-editor__muted">{ __( 'No suggestions found.', 'stratawp-seo' ) }</p>
			) }
			<ul className="swps-suggest__list">
				{ rows.map( ( kw ) => (
					<li key={ kw }>
						<span>{ kw }</span>
						<Button variant="link" onClick={ () => setFocus( kw ) }>{ __( 'Focus', 'stratawp-seo' ) }</Button>
						<Button variant="link" onClick={ () => addRelated( kw ) }>{ __( 'Related', 'stratawp-seo' ) }</Button>
					</li>
				) ) }
			</ul>
		</div>
	);
}
