import { __ } from '@wordpress/i18n';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '../compat';

export default function Sidebar() {
	const title = __( 'StrataWP SEO', 'stratawp-seo' );
	return (
		<>
			<PluginSidebarMoreMenuItem target="stratawp-seo">
				{ title }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name="stratawp-seo" title={ title } icon="search">
				<p className="swps-editor__empty">
					{ __( 'Analysis loads here.', 'stratawp-seo' ) }
				</p>
			</PluginSidebar>
		</>
	);
}
