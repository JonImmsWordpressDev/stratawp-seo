import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useRegistry, useSelect } from '@wordpress/data';
import { useKeywords } from './useKeywords';

const KEYWORD_DEBOUNCE_MS = 1200;

/**
 * Deep tier: uniqueness, cached AEO, schema and citations from the server.
 * Runs when the keyword list changes (debounced), after each save, and on
 * demand. mode "rescore" is the only call that can spend AI budget.
 */
export function useDeep() {
	const registry = useRegistry();
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const saving = useSelect(
		( select ) => {
			const editor = select( 'core/editor' );
			return editor.isSavingPost() && ! editor.isAutosavingPost();
		},
		[]
	);
	const { focus, related } = useKeywords();
	const keywords = [ focus, ...related ].filter( Boolean );
	const signature = keywords.join( '\u0001' );

	const [ state, setState ] = useState( { data: null, loading: false, error: null } );

	const refresh = useCallback(
		async ( mode = 'cached' ) => {
			if ( ! postId ) {
				return;
			}
			setState( ( s ) => ( { ...s, loading: true, error: null } ) );
			try {
				const data = await apiFetch( {
					path: '/swps/v1/editor/analyze',
					method: 'POST',
					data: {
						post_id: postId,
						mode,
						keywords,
						focus,
						content_html: registry.select( 'core/editor' ).getEditedPostContent(),
					},
				} );
				setState( { data, loading: false, error: null } );
			} catch ( e ) {
				setState( ( s ) => ( {
					...s,
					loading: false,
					error: e && e.message ? e.message : String( e ),
				} ) );
			}
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ postId, signature ]
	);

	useEffect( () => {
		const timer = setTimeout( () => refresh( 'cached' ), KEYWORD_DEBOUNCE_MS );
		return () => clearTimeout( timer );
	}, [ refresh ] );

	const wasSaving = useRef( false );
	useEffect( () => {
		if ( wasSaving.current && ! saving ) {
			refresh( 'cached' );
		}
		wasSaving.current = saving;
	}, [ saving, refresh ] );

	return { ...state, refresh };
}
