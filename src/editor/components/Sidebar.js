import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { PanelBody } from '@wordpress/components';
import {
	PluginDocumentSettingPanel,
	PluginSidebar,
	PluginSidebarMoreMenuItem,
} from '../compat';
import { useAnalysis } from '../hooks/useAnalysis';
import { useDeep } from '../hooks/useDeep';
import { useKeywords } from '../hooks/useKeywords';
import { usePostMeta } from '../hooks/usePostMeta';
import ScoreBadge from './ScoreBadge';
import SearchPreview from './SearchPreview';
import KeywordsPanel from './KeywordsPanel';
import ChecksPanel from './ChecksPanel';
import AdvancedPanel from './AdvancedPanel';
import AiVisibilityPanel from './AiVisibilityPanel';
import SchemaPanel from './SchemaPanel';
import FixButton from './FixButton';
import AnswerButton from './AnswerButton';

export default function Sidebar() {
	const title = __( 'StrataWP SEO', 'stratawp-seo' );
	const { meta } = usePostMeta();
	const deep = useDeep();
	const analysis = useAnalysis( deep.data ? deep.data.unique : {} );
	const dirty = useSelect( ( select ) => select( 'core/editor' ).isEditedPostDirty(), [] );
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const { focus, related } = useKeywords();
	const track = async ( prompt ) => {
		await apiFetch( {
			path: '/swps/v1/editor/citation-track',
			method: 'POST',
			data: { post_id: postId, prompt },
		} );
		deep.refresh( 'cached' );
	};
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
				<ScoreBadge score={ analysis.score } legacy={ meta._swps_seo_score_value } aeo={ deep.data && deep.data.aeo ? deep.data.aeo.total : null } />
			</PluginDocumentSettingPanel>

			<PluginSidebar name="stratawp-seo" title={ title } icon="search">
				<div className="swps-editor">
					<ScoreBadge score={ analysis.score } legacy={ meta._swps_seo_score_value } aeo={ deep.data && deep.data.aeo ? deep.data.aeo.total : null } />

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
							renderAction={ ( def, res, keyword ) => (
								<FixButton def={ def } res={ res } keyword={ keyword } input={ analysis.input } />
							) }
						/>
					</PanelBody>

					<PanelBody title={ __( 'Readability', 'stratawp-seo' ) } initialOpen={ false }>
						<ChecksPanel
							output={ analysis.output }
							registry={ registry }
							group="readability"
							renderAction={ ( def, res, keyword ) => (
								<FixButton def={ def } res={ res } keyword={ keyword } input={ analysis.input } />
							) }
						/>
					</PanelBody>

					<PanelBody title={ __( 'AI visibility', 'stratawp-seo' ) } initialOpen>
						<AiVisibilityPanel
							data={ deep.data }
							loading={ deep.loading }
							error={ deep.error }
							dirty={ dirty }
							focus={ focus }
							onRescore={ () => deep.refresh( 'rescore' ) }
							onTrack={ track }
							renderQueryAction={ ( sq ) => (
								<AnswerButton question={ sq.q } keyword={ focus } input={ analysis.input } />
							) }
						/>
					</PanelBody>

					<PanelBody title={ __( 'Schema', 'stratawp-seo' ) } initialOpen={ false }>
						<SchemaPanel data={ deep.data } />
					</PanelBody>

					<PanelBody title={ __( 'Advanced', 'stratawp-seo' ) } initialOpen={ false }>
						<AdvancedPanel />
					</PanelBody>
				</div>
			</PluginSidebar>
		</>
	);
}
