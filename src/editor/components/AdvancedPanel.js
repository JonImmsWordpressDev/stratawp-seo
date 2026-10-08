import { __ } from '@wordpress/i18n';
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { usePostMeta } from '../hooks/usePostMeta';

const ROBOTS = [
	{ label: __( 'Default (index, follow)', 'stratawp-seo' ), value: '' },
	{ label: 'noindex, follow', value: 'noindex, follow' },
	{ label: 'index, nofollow', value: 'index, nofollow' },
	{ label: 'noindex, nofollow', value: 'noindex, nofollow' },
];

const PRIORITY = [
	{ label: __( 'Auto', 'stratawp-seo' ), value: '' },
	...[ '1.0', '0.9', '0.8', '0.7', '0.6', '0.5', '0.4', '0.3', '0.2', '0.1' ].map( ( p ) => ( {
		label: p,
		value: p,
	} ) ),
];

const CHANGEFREQ = [
	{ label: __( 'Auto', 'stratawp-seo' ), value: '' },
	{ label: __( 'Always', 'stratawp-seo' ), value: 'always' },
	{ label: __( 'Hourly', 'stratawp-seo' ), value: 'hourly' },
	{ label: __( 'Daily', 'stratawp-seo' ), value: 'daily' },
	{ label: __( 'Weekly', 'stratawp-seo' ), value: 'weekly' },
	{ label: __( 'Monthly', 'stratawp-seo' ), value: 'monthly' },
	{ label: __( 'Yearly', 'stratawp-seo' ), value: 'yearly' },
	{ label: __( 'Never', 'stratawp-seo' ), value: 'never' },
];

export default function AdvancedPanel() {
	const { meta, setKey } = usePostMeta();
	// Switching back needs manage_options, so only offer it to those who have it.
	const { toggleUrl, canManage } = window.swpsEditor || {};

	return (
		<div className="swps-advanced">
			<TextControl
				label={ __( 'Canonical URL', 'stratawp-seo' ) }
				type="url"
				value={ meta._swps_canonical_url || '' }
				onChange={ ( v ) => setKey( '_swps_canonical_url', v ) }
			/>
			<SelectControl
				label={ __( 'Robots meta', 'stratawp-seo' ) }
				value={ meta._swps_robots || '' }
				options={ ROBOTS }
				onChange={ ( v ) => setKey( '_swps_robots', v ) }
			/>
			<TextControl
				label={ __( 'Breadcrumb title', 'stratawp-seo' ) }
				value={ meta._swps_breadcrumb_title || '' }
				onChange={ ( v ) => setKey( '_swps_breadcrumb_title', v ) }
			/>
			<TextControl
				label={ __( 'Social title', 'stratawp-seo' ) }
				value={ meta._swps_social_title || '' }
				onChange={ ( v ) => setKey( '_swps_social_title', v ) }
			/>
			<TextareaControl
				label={ __( 'Social description', 'stratawp-seo' ) }
				value={ meta._swps_social_description || '' }
				onChange={ ( v ) => setKey( '_swps_social_description', v ) }
			/>
			<TextControl
				label={ __( 'Social image URL', 'stratawp-seo' ) }
				type="url"
				value={ meta._swps_social_image || '' }
				onChange={ ( v ) => setKey( '_swps_social_image', v ) }
			/>
			<MediaUploadCheck>
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					onSelect={ ( media ) => setKey( '_swps_social_image', media.url ) }
					render={ ( { open } ) => (
						<Button variant="secondary" onClick={ open }>
							{ __( 'Choose social image', 'stratawp-seo' ) }
						</Button>
					) }
				/>
			</MediaUploadCheck>

			<h3 className="swps-advanced__heading">{ __( 'Sitemap', 'stratawp-seo' ) }</h3>
			<ToggleControl
				label={ __( 'Exclude from the sitemap', 'stratawp-seo' ) }
				checked={ !! meta._swps_sitemap_exclude }
				onChange={ ( v ) => setKey( '_swps_sitemap_exclude', v ? 1 : 0 ) }
			/>
			<SelectControl
				label={ __( 'Priority', 'stratawp-seo' ) }
				value={ meta._swps_sitemap_priority || '' }
				options={ PRIORITY }
				onChange={ ( v ) => setKey( '_swps_sitemap_priority', v ) }
			/>
			<SelectControl
				label={ __( 'Change frequency', 'stratawp-seo' ) }
				value={ meta._swps_sitemap_changefreq || '' }
				options={ CHANGEFREQ }
				onChange={ ( v ) => setKey( '_swps_sitemap_changefreq', v ) }
			/>

			{ canManage && toggleUrl && (
				<p className="swps-advanced__switch">
					<a href={ toggleUrl }>
						{ __( 'Use the classic editor panel instead', 'stratawp-seo' ) }
					</a>
				</p>
			) }
		</div>
	);
}
