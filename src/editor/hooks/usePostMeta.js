import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';

export function usePostMeta() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const setKey = ( key, value ) => setMeta( { ...( meta || {} ), [ key ]: value } );
	return { meta: meta || {}, setKey, postType };
}
