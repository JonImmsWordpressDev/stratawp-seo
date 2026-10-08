import { usePostMeta } from './usePostMeta';

export function useKeywords() {
	const { meta, setKey } = usePostMeta();
	const related = Array.isArray( meta._swps_related_keywords )
		? meta._swps_related_keywords
		: [];
	return {
		focus: meta._swps_focus_keyword || '',
		related,
		setFocus: ( value ) => setKey( '_swps_focus_keyword', value ),
		setRelated: ( list ) => setKey( '_swps_related_keywords', list.slice( 0, 4 ) ),
	};
}
