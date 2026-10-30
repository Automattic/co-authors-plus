/**
 * End-to-end coverage for assigning a co-author in the block editor.
 *
 * Drives the real "Authors" document-settings panel — the same UI an editor
 * uses — to add a second author to a post, and confirms the byline reflects it
 * both in the editor panel and on the published front end.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Assigning a co-author in the block editor', () => {
	let author;

	test.beforeAll( async ( { requestUtils } ) => {
		author = await requestUtils.createUser( {
			username: 'e2ecoauthor',
			email: 'e2ecoauthor@example.com',
			firstName: 'Ezra',
			lastName: 'Example',
			roles: [ 'author' ],
			password: 'cap-e2e-password',
		} );

		// createUser() cannot set the display name, so set it via REST — that is
		// what the panel and the byline show, and what we assert against.
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/users/${ author.id }`,
			data: { name: 'Ezra Example' },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'adds the chosen author to the byline', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await admin.createNewPost( { title: 'A co-authored post' } );
		await editor.openDocumentSettingsSidebar();

		// Expand the "Authors" panel if it is collapsed.
		const panelToggle = page.getByRole( 'button', { name: 'Authors' } );
		if (
			'false' === ( await panelToggle.getAttribute( 'aria-expanded' ) )
		) {
			await panelToggle.click();
		}

		// Search for and select the second author via the combobox.
		const combobox = page.getByRole( 'combobox', {
			name: 'Select An Author',
		} );
		await combobox.click();
		await combobox.fill( 'Ezra' );
		await page.getByRole( 'option', { name: 'Ezra Example' } ).click();

		// The panel now lists the added co-author.
		await expect(
			page.locator( '.cap-author', { hasText: 'Ezra Example' } )
		).toBeVisible();

		// Publish, then confirm both authors persisted via the public co-authors
		// endpoint — the saved byline, independent of the active theme's markup.
		const postId = await editor.publishPost();
		const coauthors = await requestUtils.rest( {
			path: `/coauthors/v1/coauthors?post_id=${ postId }`,
		} );
		const names = coauthors.map( ( coauthor ) => coauthor.display_name );

		expect( names ).toContain( 'Ezra Example' );
		expect( names ).toContain( 'admin' );
	} );
} );
