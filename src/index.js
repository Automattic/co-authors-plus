/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { __ } from '@wordpress/i18n';

// PluginDocumentSettingPanel moved from @wordpress/edit-post to @wordpress/editor in WP 6.6.
// Use fallback for backwards compatibility with WP 6.4-6.5.
const PluginDocumentSettingPanel =
	wp.editor?.PluginDocumentSettingPanel ||
	wp.editPost?.PluginDocumentSettingPanel;

/**
 * Components
 */
import CoAuthors from './components/co-authors';

/**
 * Component for rendering the plugin sidebar.
 */
const PluginDocumentSettingPanelAuthors = () => (
	<PluginDocumentSettingPanel
		name="coauthors-panel"
		title={ __( 'Authors', 'co-authors-plus' ) }
		className="coauthors"
	>
		<CoAuthors />
	</PluginDocumentSettingPanel>
);

// Only register plugin if PluginDocumentSettingPanel is available.
if ( PluginDocumentSettingPanel ) {
	registerPlugin( 'plugin-coauthors-document-setting', {
		render: PluginDocumentSettingPanelAuthors,
		icon: 'users',
	} );
}
