/**
 * End-to-end coverage for loading the plugin's blocks in the block editor.
 *
 * Confirms every block script runs in the real editor, and that the Co-Authors
 * block's own edit component renders once inserted.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const BLOCK_NAMES = [
	'co-authors-plus/coauthors',
	'co-authors-plus/avatar',
	'co-authors-plus/description',
	'co-authors-plus/image',
	'co-authors-plus/name',
];

test.describe( 'Co-Authors Plus blocks', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'are registered and render in the editor', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();

		const registered = await page.evaluate(
			( names ) =>
				names.filter( ( name ) =>
					window.wp.blocks.getBlockType( name )
				),
			BLOCK_NAMES
		);
		expect( registered ).toEqual( BLOCK_NAMES );

		await editor.insertBlock( { name: 'co-authors-plus/coauthors' } );

		await expect(
			editor.canvas.locator( '[data-type="co-authors-plus/coauthors"]' )
		).toBeVisible();
	} );
} );
