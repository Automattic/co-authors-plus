/**
 * Tests for PluginDocumentSettingPanel backwards compatibility.
 *
 * The component moved from @wordpress/edit-post to @wordpress/editor in WP 6.6.
 * We use a runtime fallback to support both WP 6.4-6.5 (edit-post) and WP 6.6+ (editor).
 */

describe( 'PluginDocumentSettingPanel compatibility', () => {
	const MockComponent = () => 'MockPluginDocumentSettingPanel';

	beforeEach( () => {
		// Reset the wp global before each test
		global.wp = {};
	} );

	afterEach( () => {
		// Clean up
		delete global.wp;
	} );

	it( 'should use wp.editor.PluginDocumentSettingPanel when available (WP 6.6+)', () => {
		global.wp = {
			editor: {
				PluginDocumentSettingPanel: MockComponent,
			},
			editPost: {
				PluginDocumentSettingPanel: () => 'OldComponent',
			},
		};

		const PluginDocumentSettingPanel =
			wp.editor?.PluginDocumentSettingPanel ||
			wp.editPost?.PluginDocumentSettingPanel;

		expect( PluginDocumentSettingPanel ).toBe( MockComponent );
	} );

	it( 'should fall back to wp.editPost.PluginDocumentSettingPanel (WP 6.4-6.5)', () => {
		global.wp = {
			editor: {},
			editPost: {
				PluginDocumentSettingPanel: MockComponent,
			},
		};

		const PluginDocumentSettingPanel =
			wp.editor?.PluginDocumentSettingPanel ||
			wp.editPost?.PluginDocumentSettingPanel;

		expect( PluginDocumentSettingPanel ).toBe( MockComponent );
	} );

	it( 'should handle wp.editor being undefined (WP 6.4-6.5)', () => {
		global.wp = {
			editPost: {
				PluginDocumentSettingPanel: MockComponent,
			},
		};

		const PluginDocumentSettingPanel =
			wp.editor?.PluginDocumentSettingPanel ||
			wp.editPost?.PluginDocumentSettingPanel;

		expect( PluginDocumentSettingPanel ).toBe( MockComponent );
	} );
} );
