/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { select, subscribe } from "@wordpress/data";

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

registerPlugin( 'plugin-coauthors-document-setting', {
	render: PluginDocumentSettingPanelAuthors,
	icon: 'users',
} );

// Save authors when the post is saved.
// https://github.com/WordPress/gutenberg/issues/17632
const { isSavingPost, getCurrentPost } = select("core/editor");
const { getAuthors, saveAuthors } = select("cap/authors");

let checked = true; // Start in a checked state.

subscribe(() => {
	if (isSavingPost()) {
		checked = false;
	} else if (!checked) {
		const { id } = getCurrentPost();
		const authors = getAuthors(id);
		saveAuthors(id, authors);
		checked = true;
	}
});

