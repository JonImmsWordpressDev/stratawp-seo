import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useRegistry, useSelect } from '@wordpress/data';
import { Button, Notice } from '@wordpress/components';
import FixDiff from './FixDiff';
import { ruleFix } from '../analysis/fixes';
import { paragraphs } from '../analysis/text';
import { findParagraphBlock, useApplyFix } from '../hooks/useApplyFix';

export default function FixButton( { def, res, keyword, input } ) {
	const registry = useRegistry();
	const apply = useApplyFix();
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const status = useSelect(
		( select ) => select( 'core/editor' ).getEditedPostAttribute( 'status' ),
		[]
	);
	const savedStatus = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostAttribute( 'status' ),
		[]
	);
	const [ proposal, setProposal ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	if ( ! input || def.fix === 'none' || ( res.status !== 'fail' && res.status !== 'warn' ) ) {
		return null;
	}

	let rule = null;
	if ( def.fix === 'rule' ) {
		rule = ruleFix( def.id, {
			keyword,
			slug: input.slug,
			title: input.title,
			metaTitle: input.meta_title,
			metaDescription: input.meta_description,
			status,
			preset: input.preset,
		} );
		if ( ! rule ) {
			return null;
		}
	} else if ( ! keyword ) {
		return null;
	}

	const requestAi = async () => {
		setBusy( true );
		setError( null );
		// Read the editor now, not the last debounced snapshot.
		const content = registry.select( 'core/editor' ).getEditedPostContent();
		const paras = paragraphs( content );
		let target = '';
		if ( def.id === 'kw_in_intro' ) {
			target = paras[ 0 ] || '';
		} else if ( def.id === 'kw_in_conclusion' ) {
			target = paras[ paras.length - 1 ] || '';
		}
		if ( target && ! findParagraphBlock( registry, target ) ) {
			setError( __( 'That paragraph is not a paragraph block, so it cannot be fixed automatically.', 'stratawp-seo' ) );
			setBusy( false );
			return;
		}
		try {
			const r = await apiFetch( {
				path: '/swps/v1/editor/fix',
				method: 'POST',
				data: {
					post_id: postId,
					check_id: def.id,
					keyword,
					title: input.title,
					meta_title: input.meta_title,
					meta_description: input.meta_description,
					content_html: content,
					lang: input.lang,
					target_text: target,
				},
			} );
			setProposal( r.proposal );
		} catch ( e ) {
			setError( e && e.message ? e.message : String( e ) );
		}
		setBusy( false );
	};

	const onApply = () => {
		const r = apply( proposal );
		if ( r.ok ) {
			setProposal( null );
		} else {
			setError( r.message );
		}
	};

	const cost = ( window.swpsEditor || {} ).fixCost;
	const label = rule
		? __( 'Fix', 'stratawp-seo' )
		: `${ __( 'Fix with AI', 'stratawp-seo' ) }${ cost ? ` (about $${ cost })` : '' }`;

	return (
		<div className="swps-fix">
			{ ! proposal && (
				<Button
					variant="secondary"
					size="small"
					disabled={ busy }
					isBusy={ busy }
					aria-label={ `${ __( 'Fix', 'stratawp-seo' ) }: ${ def.label }` }
					onClick={ rule ? () => setProposal( { ...rule, check_id: def.id } ) : requestAi }
				>
					{ label }
				</Button>
			) }
			{ proposal && (
				<FixDiff proposal={ proposal } onApply={ onApply } onDismiss={ () => setProposal( null ) } />
			) }
			{ error && (
				<Notice status="warning" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
		</div>
	);
}
