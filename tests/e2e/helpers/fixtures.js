/**
 * Shared data fixtures for the Co-Authors Plus E2E specs.
 */

/**
 * Create a WordPress user that can be assigned as a co-author.
 *
 * createUser() cannot set the display name, so it is set via REST afterwards —
 * that is what the panel, the list column and the byline show, and what the
 * specs assert against (the same workaround the original spec uses inline).
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils        Request utils fixture.
 * @param {Object}                                                      options             User options.
 * @param {string}                                                      options.username    Login / username.
 * @param {string}                                                      options.displayName Display name to set via REST.
 * @param {string[]}                                                    [options.roles]     Roles; defaults to [ 'author' ].
 * @return {Promise<Object>} The created user object (includes `id`).
 */
async function createCoAuthorUser(
	requestUtils,
	{ username, displayName, roles = [ 'author' ] }
) {
	const [ firstName, ...rest ] = displayName.split( ' ' );

	const user = await requestUtils.createUser( {
		username,
		email: `${ username }@example.com`,
		firstName,
		lastName: rest.join( ' ' ) || 'Example',
		roles,
		password: 'cap-e2e-password',
	} );

	await requestUtils.rest( {
		method: 'POST',
		path: `/wp/v2/users/${ user.id }`,
		data: { name: displayName },
	} );

	return user;
}

module.exports = {
	createCoAuthorUser,
};
