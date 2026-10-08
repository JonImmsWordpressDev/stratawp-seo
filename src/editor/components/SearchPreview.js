import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { Button, ButtonGroup, TextControl, TextareaControl } from '@wordpress/components';
import { usePostMeta } from '../hooks/usePostMeta';
import { paragraphs } from '../analysis/text';

const trunc = ( s, n ) => ( s.length > n ? s.slice( 0, n - 1 ).trimEnd() + '…' : s );

export default function SearchPreview( { input } ) {
	const { meta, setKey } = usePostMeta();
	const [ mode, setMode ] = useState( 'desktop' );
	const { permalink, title } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			permalink: editor.getPermalink() || '',
			title: editor.getEditedPostAttribute( 'title' ) || '',
		};
	}, [] );
	const data = window.swpsEditor || {};
	const preset = data.preset || {};

	const seoTitle = meta._swps_meta_title || title || __( 'Untitled', 'stratawp-seo' );
	const firstPara = input ? paragraphs( input.content_html )[ 0 ] || '' : '';
	const description = meta._swps_meta_description || firstPara;
	const host = permalink.replace( /^https?:\/\//, '' ).replace( /\/$/, '' ).split( '/' ).join( ' › ' );

	return (
		<div className="swps-preview">
			<ButtonGroup className="swps-preview__modes">
				<Button variant={ mode === 'desktop' ? 'primary' : 'secondary' } size="small" onClick={ () => setMode( 'desktop' ) }>
					{ __( 'Desktop', 'stratawp-seo' ) }
				</Button>
				<Button variant={ mode === 'mobile' ? 'primary' : 'secondary' } size="small" onClick={ () => setMode( 'mobile' ) }>
					{ __( 'Mobile', 'stratawp-seo' ) }
				</Button>
				<Button variant={ mode === 'social' ? 'primary' : 'secondary' } size="small" onClick={ () => setMode( 'social' ) }>
					{ __( 'Social', 'stratawp-seo' ) }
				</Button>
			</ButtonGroup>

			{ mode === 'social' ? (
				<div className="swps-social" data-testid="swps-social">
					{ meta._swps_social_image ? (
						<img className="swps-social__image" src={ meta._swps_social_image } alt="" />
					) : (
						<div className="swps-social__image swps-social__image--empty">
							{ __( 'No social image set. The featured image is used if there is one.', 'stratawp-seo' ) }
						</div>
					) }
					<div className="swps-social__host">{ permalink.replace( /^https?:\/\//, '' ).split( '/' )[ 0 ] }</div>
					<div className="swps-social__title">{ meta._swps_social_title || seoTitle }</div>
					<div className="swps-social__desc">
						{ trunc( meta._swps_social_description || description, 110 ) }
					</div>
				</div>
			) : (
				<div className={ `swps-serp swps-serp--${ mode }` } data-testid="swps-serp">
					<div className="swps-serp__url">{ host }</div>
					<div className="swps-serp__title">{ trunc( seoTitle, preset.title_max || 60 ) }</div>
					<div className="swps-serp__desc">
						{ trunc( description, mode === 'mobile' ? 120 : preset.desc_max || 160 ) }
					</div>
				</div>
			) }

			<TextControl
				label={ __( 'SEO title', 'stratawp-seo' ) }
				value={ meta._swps_meta_title || '' }
				placeholder={ title }
				onChange={ ( v ) => setKey( '_swps_meta_title', v ) }
				help={ `${ ( meta._swps_meta_title || title ).length } / ${ preset.title_max || 60 }` }
			/>
			<TextareaControl
				label={ __( 'Meta description', 'stratawp-seo' ) }
				value={ meta._swps_meta_description || '' }
				onChange={ ( v ) => setKey( '_swps_meta_description', v ) }
				help={ `${ ( meta._swps_meta_description || '' ).length } / ${ preset.desc_max || 160 }` }
			/>
		</div>
	);
}
