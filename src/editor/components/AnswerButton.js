import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useRegistry, useSelect } from '@wordpress/data';
import { Button, Notice } from '@wordpress/components';
import FixDiff from './FixDiff';
import { useApplyFix } from '../hooks/useApplyFix';

export default function AnswerButton( { question, keyword, input } ) {
	const registry = useRegistry();
	const apply = useApplyFix();
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const [ proposal, setProposal ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const request = async () => {
		setBusy( true );
		setError( null );
		try {
			const r = await apiFetch( {
				path: '/swps/v1/editor/fix',
				method: 'POST',
				data: {
					post_id: postId,
					check_id: 'aeo_answer',
					keyword,
					question,
					title: input ? input.title : '',
					content_html: registry.select( 'core/editor' ).getEditedPostContent(),
					lang: input ? input.lang : 'en',
				},
			} );
			setProposal( { ...r.proposal, heading: question } );
		} catch ( e ) {
			setError( e && e.message ? e.message : String( e ) );
		}
		setBusy( false );
	};

	const cost = ( window.swpsEditor || {} ).fixCost;

	return (
		<div className="swps-fix">
			{ ! proposal && (
				<Button variant="secondary" size="small" isBusy={ busy } disabled={ busy } onClick={ request }>
					{ __( 'Add an answer', 'stratawp-seo' ) }{ cost ? ` (about $${ cost })` : '' }
				</Button>
			) }
			{ proposal && (
				<FixDiff
					proposal={ proposal }
					onApply={ () => {
						const r = apply( proposal );
						if ( r.ok ) {
							setProposal( null );
						} else {
							setError( r.message );
						}
					} }
					onDismiss={ () => setProposal( null ) }
				/>
			) }
			{ error && (
				<Notice status="warning" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
		</div>
	);
}
