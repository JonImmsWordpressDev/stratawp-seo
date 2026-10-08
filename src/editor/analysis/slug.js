import { cleanForSlug } from '@wordpress/url';

/**
 * The slug the instant checks should judge. Drafts have no saved slug until
 * they are published, so fall back to what WordPress will generate: the
 * editor's own derived slug when available, else the cleaned title.
 *
 * @param {string} slug       Edited slug attribute.
 * @param {string} title      Edited title.
 * @param {string} editorSlug Result of core/editor getEditedPostSlug, if any.
 * @return {string} Slug for the checks.
 */
export function deriveSlug( slug, title, editorSlug = '' ) {
	return slug || editorSlug || cleanForSlug( title || '' );
}
