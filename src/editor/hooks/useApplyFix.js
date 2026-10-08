import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useCallback } from '@wordpress/element';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';
import { usePostMeta } from './usePostMeta';
import { plain } from '../analysis/text';

/**
 * Find the core/paragraph block whose visible text is exactly `text`.
 */
export function findParagraphBlock( registry, text ) {
	const store = registry.select( 'core/block-editor' );
	return (
		store.getClientIdsWithDescendants().find( ( id ) => {
			const block = store.getBlock( id );
			return (
				block &&
				block.name === 'core/paragraph' &&
				plain( String( block.attributes.content ?? '' ) ) === text
			);
		} ) || null
	);
}

/**
 * Apply a validated proposal through the editor stores so one Undo reverts it.
 * Returns { ok } or { ok: false, message } and never throws.
 */
export function useApplyFix() {
	const registry = useRegistry();
	const { setKey } = usePostMeta();
	const { editPost } = useDispatch( 'core/editor' );
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );

	return useCallback(
		( proposal ) => {
			try {
				switch ( proposal.kind ) {
					case 'slug':
						editPost( { slug: proposal.value } );
						break;
					case 'meta_title':
						setKey( '_swps_meta_title', proposal.value );
						break;
					case 'meta_description':
						setKey( '_swps_meta_description', proposal.value );
						break;
					case 'paragraph': {
						const id = findParagraphBlock( registry, proposal.original );
						if ( ! id ) {
							return {
								ok: false,
								message: __( 'The paragraph changed. Try again.', 'stratawp-seo' ),
							};
						}
						registry
							.dispatch( 'core/block-editor' )
							.updateBlockAttributes( id, { content: proposal.value } );
						break;
					}
					case 'insert': {
						const store = registry.select( 'core/block-editor' );
						const selected = store.getSelectedBlockClientId();
						const root = selected ? store.getBlockRootClientId( selected ) : '';
						const index = selected
							? store.getBlockIndex( selected ) + 1
							: store.getBlockCount();
						registry.dispatch( 'core/block-editor' ).insertBlocks(
							[
								createBlock( 'core/heading', { level: 3, content: proposal.heading } ),
								createBlock( 'core/paragraph', { content: proposal.value } ),
							],
							index,
							root || ''
						);
						break;
					}
					default:
						return { ok: false, message: __( 'Unknown fix.', 'stratawp-seo' ) };
				}
			} catch ( e ) {
				return { ok: false, message: e && e.message ? e.message : String( e ) };
			}

			// Record for the proof snapshot. Failure here must never undo the fix.
			apiFetch( {
				path: '/swps/v1/editor/applied',
				method: 'POST',
				data: { post_id: postId, check_id: proposal.check_id },
			} ).catch( () => {} );

			return { ok: true };
		},
		[ registry, setKey, editPost, postId ]
	);
}
