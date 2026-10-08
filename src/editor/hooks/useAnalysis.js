import { useEffect, useState } from '@wordpress/element';
import { useRegistry, useSelect } from '@wordpress/data';
import { runChecks } from '../analysis/checks';
import { scoreOutput } from '../analysis/score';
import { usePostMeta } from './usePostMeta';
import { useKeywords } from './useKeywords';

const DEBOUNCE_MS = 400;

/**
 * Instant tier. Re-runs the pure checks 400 ms after the last edit. Reads the
 * editor state, never the saved copy, so unsaved and auto-draft posts work.
 */
export function useAnalysis( usedElsewhere = {} ) {
	const registry = useRegistry();
	const { meta } = usePostMeta();
	const { focus, related } = useKeywords();
	const blocks = useSelect(
		( select ) => select( 'core/block-editor' ).getBlocks(),
		[]
	);
	const { title, slug } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			title: editor.getEditedPostAttribute( 'title' ) || '',
			slug: editor.getEditedPostAttribute( 'slug' ) || '',
		};
	}, [] );

	const [ state, setState ] = useState( {
		input: null,
		output: null,
		score: null,
		error: null,
	} );

	const signature = JSON.stringify( [
		title,
		slug,
		meta._swps_meta_title,
		meta._swps_meta_description,
		focus,
		related,
		usedElsewhere,
	] );

	useEffect( () => {
		const timer = setTimeout( () => {
			const data = window.swpsEditor || {};
			const input = {
				title,
				slug,
				content_html: registry.select( 'core/editor' ).getEditedPostContent(),
				meta_title: meta._swps_meta_title || '',
				meta_description: meta._swps_meta_description || '',
				lang: data.lang || 'en',
				host: data.host || '',
				preset: data.preset || {},
				keywords: [ focus, ...related ].map( ( keyword ) => ( {
					keyword,
					used_elsewhere: Object.prototype.hasOwnProperty.call( usedElsewhere, keyword )
						? usedElsewhere[ keyword ]
						: null,
				} ) ),
			};
			try {
				const output = runChecks( input );
				setState( {
					input,
					output,
					score: scoreOutput( output, data.registry ),
					error: null,
				} );
			} catch ( error ) {
				setState( { input, output: null, score: null, error } );
			}
		}, DEBOUNCE_MS );
		return () => clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ blocks, signature ] );

	return state;
}
