import * as editor from '@wordpress/editor';
import * as editPost from '@wordpress/edit-post';

// PluginSidebar and friends moved from @wordpress/edit-post to
// @wordpress/editor in WordPress 6.6. The plugin supports 6.0+, so prefer the
// new home and fall back to the old one.
export const PluginSidebar = editor.PluginSidebar || editPost.PluginSidebar;
export const PluginSidebarMoreMenuItem =
	editor.PluginSidebarMoreMenuItem || editPost.PluginSidebarMoreMenuItem;
export const PluginDocumentSettingPanel =
	editor.PluginDocumentSettingPanel || editPost.PluginDocumentSettingPanel;
