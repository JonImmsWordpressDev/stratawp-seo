import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { PanelBody } from '@wordpress/components';
import {
	PluginDocumentSettingPanel,
	PluginSidebar,
	PluginSidebarMoreMenuItem,
} from '../compat';
import { useAnalysis } from '../hooks/useAnalysis';
import { useKeywords } from '../hooks/useKeywords';
import { usePostMeta } from '../hooks/usePostMeta';
import ScoreBadge from './ScoreBadge';
import SearchPreview from './SearchPreview';
import KeywordsPanel from './KeywordsPanel';
import ChecksPanel from './ChecksPanel';

export default function Sidebar() {
	const title = __( 'StrataWP SEO', 'stratawp-seo' );
	const { meta } = usePostMeta();
	const analysis = useAnalysis();
	const { related } = useKeywords();
	const [ selected, setSelected ] = useState( 0 );
	// Always a valid index, even while analysis output lags the keyword list.
	const activeIndex = Math.min( selected, related.length );
	// Removing the active keyword or one before it steps the selection back one.
	const handleRemove = ( position ) => {
		if ( position <= activeIndex ) {
			setSelected( Math.max( 0, activeIndex - 1 ) );
		}
	};
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
							onSelect={ setSelected }
							onRemove={ handleRemove }
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
