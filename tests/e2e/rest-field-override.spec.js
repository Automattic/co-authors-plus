/**
 * Regression coverage for sites where third-party code overrides the
 * `coauthors` REST field on posts.
 *
 * A widespread snippet — predating Co-Authors Plus exposing co-authors in
 * REST itself — registers a `coauthors` field returning full author objects
 * (with a `user_id`, no `term_id`) instead of the author taxonomy's term-ID
 * array. After 4.x moved the Authors panel onto the core entity store, that
 * shape yielded no term IDs, so the panel rendered empty even though the post
 * had co-authors (issue #1277). The panel now falls back to resolving the
 * byline by post ID.
 *
 * The override fixture lives in tests/e2e/plugins/, mounted via the
 * `mappings` entry in .wp-env.json, and is activated only for this spec.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	addCoAuthor,
	getCoAuthorNames,
	getSavedCoAuthors,
	openAuthorsPanel,
} = require( './helpers/co-authors' );
const { createCoAuthorUser } = require( './helpers/fixtures' );

test.describe( 'Authors panel with an overridden coauthors REST field', () => {
	let postId;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'cap-coauthors-rest-override' );

		await createCoAuthorUser( requestUtils, {
			username: 'e2eodette',
			displayName: 'Odette Override',
		} );
		await createCoAuthorUser( requestUtils, {
			username: 'e2eoscar',
			displayName: 'Oscar Object',
		} );

		// A draft that already carries two co-authors, assigned server-side —
		// the state an affected site's existing posts are in.
		const post = await requestUtils.createPost( {
			title: 'Overridden byline',
			status: 'draft',
		} );
		postId = post.id;

		await requestUtils.rest( {
			method: 'POST',
			path: `/coauthors/v1/authors/${ postId }`,
			data: { new_authors: 'admin,e2eodette' },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'cap-coauthors-rest-override' );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'panel populates from the post ID and edits persist', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		// Precondition: the override is in effect, so the post's REST field
		// carries author objects with a user_id and no term_id — the exact
		// payload reported in #1277. If this fails, the fixture plugin is not
		// active and the rest of the spec would pass vacuously.
		const post = await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }`,
			params: { context: 'edit' },
		} );
		expect( post.coauthors ).toHaveLength( 2 );
		for ( const coauthor of post.coauthors ) {
			expect( coauthor ).toHaveProperty( 'user_id' );
			expect( coauthor ).not.toHaveProperty( 'term_id' );
		}

		await admin.editPost( postId );
		await editor.openDocumentSettingsSidebar();
		await openAuthorsPanel( page );

		// The regression: before the post-ID fallback, the panel rendered
		// empty here despite the post having two co-authors.
		await expect
			.poll( () => getCoAuthorNames( page ) )
			.toEqual( [ 'admin', 'Odette Override' ] );

		// The (read-only) override does not touch the write path: an edit
		// still persists term IDs through the entity store.
		await addCoAuthor( page, 'Oscar Object' );

		const publishedId = await editor.publishPost();
		await expect
			.poll( () => getSavedCoAuthors( requestUtils, publishedId ) )
			.toEqual( [ 'admin', 'Odette Override', 'Oscar Object' ] );
	} );
} );
