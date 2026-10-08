/**
 * End-to-end coverage for managing multiple co-authors in the block editor.
 *
 * The reorder / add / remove array logic is already unit-tested in
 * src/__tests__/utils.test.js. These specs prove the part units cannot: that
 * the panel's buttons drive the editor entity store, and that the resulting
 * byline — membership AND order — survives a publish.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	coAuthorChip,
	openAuthorsPanel,
	addCoAuthor,
	removeCoAuthor,
	moveCoAuthor,
	getCoAuthorNames,
	getSavedCoAuthors,
} = require( './helpers/co-authors' );
const { createCoAuthorUser } = require( './helpers/fixtures' );

test.describe( 'Managing co-authors in the block editor', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// Distinct, non-substring display names keep option/chip matching
		// unambiguous for the substring-based locators.
		await createCoAuthorUser( requestUtils, {
			username: 'e2ebianca',
			displayName: 'Bianca Byline',
		} );
		await createCoAuthorUser( requestUtils, {
			username: 'e2ecarl',
			displayName: 'Carl Column',
		} );
		await createCoAuthorUser( requestUtils, {
			username: 'e2edana',
			displayName: 'Dana Draft',
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'adds several co-authors, reorders them, and persists the new order', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await admin.createNewPost( { title: 'Ordered byline' } );
		await editor.openDocumentSettingsSidebar();
		await openAuthorsPanel( page );

		// Wait for the panel to resolve the default author before editing so the
		// ordering assertions start from a known state.
		await coAuthorChip( page, 'admin' ).waitFor();

		await addCoAuthor( page, 'Bianca Byline' );
		await addCoAuthor( page, 'Carl Column' );
		await addCoAuthor( page, 'Dana Draft' );

		// New authors append after the default author (admin).
		await expect
			.poll( () => getCoAuthorNames( page ) )
			.toEqual( [
				'admin',
				'Bianca Byline',
				'Carl Column',
				'Dana Draft',
			] );

		// Move the last author up one place.
		await moveCoAuthor( page, 'Dana Draft', 'up' );
		await expect
			.poll( () => getCoAuthorNames( page ) )
			.toEqual( [
				'admin',
				'Bianca Byline',
				'Dana Draft',
				'Carl Column',
			] );

		// The saved byline reflects the reordered sequence, not just membership.
		const postId = await editor.publishPost();
		await expect
			.poll( () => getSavedCoAuthors( requestUtils, postId ) )
			.toEqual( [
				'admin',
				'Bianca Byline',
				'Dana Draft',
				'Carl Column',
			] );
	} );

	test( 'removing co-authors down to one disables the row controls', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await admin.createNewPost( { title: 'Single byline' } );
		await editor.openDocumentSettingsSidebar();
		await openAuthorsPanel( page );

		await coAuthorChip( page, 'admin' ).waitFor();

		await addCoAuthor( page, 'Bianca Byline' );
		await expect
			.poll( () => getCoAuthorNames( page ) )
			.toEqual( [ 'admin', 'Bianca Byline' ] );

		await removeCoAuthor( page, 'Bianca Byline' );
		await expect
			.poll( () => getCoAuthorNames( page ) )
			.toEqual( [ 'admin' ] );

		// With a single co-author remaining, its move/remove controls disable.
		const adminChip = coAuthorChip( page, 'admin' );
		await expect(
			adminChip.getByRole( 'button', { name: 'Remove Author' } )
		).toBeDisabled();
		await expect(
			adminChip.getByRole( 'button', { name: 'Move Up', exact: true } )
		).toBeDisabled();
		await expect(
			adminChip.getByRole( 'button', { name: 'Move down', exact: true } )
		).toBeDisabled();

		const postId = await editor.publishPost();
		await expect
			.poll( () => getSavedCoAuthors( requestUtils, postId ) )
			.toEqual( [ 'admin' ] );
	} );
} );
