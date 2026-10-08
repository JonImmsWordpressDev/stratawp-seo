import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { PanelBody } from '@wordpress/components';
import {
	PluginDocumentSettingPanel,
	PluginSidebar,
	PluginSidebarMoreMenuItem,
} from '../compat';
import { useAnalysis } from '../hooks/useAnalysis';
import { usePostMeta } from '../hooks/usePostMeta';
import ScoreBadge from './ScoreBadge';
import SearchPreview from './SearchPreview';
import KeywordsPanel from './KeywordsPanel';
import ChecksPanel from './ChecksPanel';

export default function Sidebar() {
	const title = __( 'StrataWP SEO', 'stratawp-seo' );
	const { meta } = usePostMeta();
	const analysis = useAnalysis();
	const [ activeIndex, setActiveIndex ] = useState( 0 );
	const registry = ( window.swpsEditor || {} ).registry;

	return (
		<>
			<PluginSidebarMoreMenuItem target="stratawp-seo">{ title }</PluginSidebarMoreMenuItem>

			<PluginDocumentSettingPanel name="swps-score" title={ title }>
				<ScoreBadge score={ analysis.score } legacy={ meta._swps_seo_score_value } />
			</PluginDocumentSettingPanel>

			<PluginSidebar name="stratawp-seo" title={ title } icon="search">
				<div className="swps-editor">
					<ScoreBadge score={ analysis.score } legacy={ meta._swps_seo_score_value } />

					<PanelBody title={ __( 'Search preview', 'stratawp-seo' ) } initialOpen>
						<SearchPreview input={ analysis.input } />
					</PanelBody>

					<PanelBody title={ __( 'Keywords', 'stratawp-seo' ) } initialOpen>
						<KeywordsPanel
							output={ analysis.output }
							activeIndex={ activeIndex }
							onSelect={ setActiveIndex }
						/>
					</PanelBody>

					<PanelBody title={ __( 'SEO checks', 'stratawp-seo' ) } initialOpen>
						<ChecksPanel
							output={ analysis.output }
							registry={ registry }
							group="seo"
							keywordIndex={ activeIndex }
						/>
					</PanelBody>

					<PanelBody title={ __( 'Readability', 'stratawp-seo' ) } initialOpen={ false }>
						<ChecksPanel output={ analysis.output } registry={ registry } group="readability" />
					</PanelBody>
				</div>
			</PluginSidebar>
		</>
	);
}
