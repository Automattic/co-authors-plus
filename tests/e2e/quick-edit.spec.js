/**
 * End-to-end coverage for assigning a co-author via Quick Edit on the posts list.
 *
 * Quick Edit works by hijacking inlineEditPost.edit (js/co-authors-plus.js): it
 * re-parents the hidden #coauthors-edit control into the inline-edit row, seeds
 * it from the list column's data-* attributes, and re-initialises the jQuery UI
 * autocomplete. None of that runs without the real list-table DOM, so this glue
 * is browser-only and untested at any lower level. We assert the client → save
 * round-trip, not assignment semantics (already covered by integration tests).
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { getSavedCoAuthors } = require( './helpers/co-authors' );
const { createCoAuthorUser } = require( './helpers/fixtures' );

test.describe( 'Assigning a co-author via Quick Edit', () => {
	let post;

	test.beforeAll( async ( { requestUtils } ) => {
		await createCoAuthorUser( requestUtils, {
			username: 'e2equincy',
			displayName: 'Quincy Quill',
		} );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		post = await requestUtils.createPost( {
			title: 'Quick edit me',
			status: 'publish',
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'adds an author through the inline autocomplete and saves it', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( 'edit.php' );

		const row = page.locator( `#post-${ post.id }` );

		// The Authors column is seeded from the post author.
		await expect( row.locator( '.column-coauthors' ) ).toContainText(
			'admin'
		);

		// Open Quick Edit (row actions reveal on hover).
		await row.hover();
		await row.locator( '.editinline' ).click();

		const quickEditRow = page.locator( `#edit-${ post.id }` );

		// The co-authors control mounted, pre-populated with the existing author.
		await expect(
			quickEditRow.locator( '.inline-edit-coauthors .coauthor-tag', {
				hasText: 'admin',
			} )
		).toBeVisible();

		// Add a second author via the trailing autocomplete input. Type real
		// keystrokes so the jQuery UI autocomplete search fires.
		const suggest = quickEditRow
			.locator( '.inline-edit-coauthors .coauthor-suggest' )
			.last();
		await suggest.click();
		await suggest.pressSequentially( 'Quincy' );

		// The dropdown is appended to <body>; pick the matching suggestion.
		await page
			.locator( 'ul.ui-autocomplete:visible li', {
				hasText: 'Quincy Quill',
			} )
			.click();

		await expect(
			quickEditRow.locator( '.coauthor-tag', { hasText: 'Quincy Quill' } )
		).toBeVisible();

		// Save the inline edit.
		await quickEditRow.getByRole( 'button', { name: 'Update' } ).click();

		// Once the AJAX save completes, the row's Authors column reflects it.
		await expect( row.locator( '.column-coauthors' ) ).toContainText(
			'Quincy Quill'
		);

		// Confirm persistence independent of the list markup.
		const names = await getSavedCoAuthors( requestUtils, post.id );
		expect( names ).toContain( 'admin' );
		expect( names ).toContain( 'Quincy Quill' );
	} );
} );
