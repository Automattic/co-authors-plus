/**
 * Shared helpers for driving the Co-Authors Plus "Authors" panel in the block
 * editor, and for reading the saved byline back via the public REST endpoint.
 *
 * These are deliberately thin, stateless, selector-driven actions — not
 * Playwright fixtures or page objects, which would be premature at this scale.
 * They mirror the selectors used by the original tests/e2e/coauthors.spec.js
 * so every spec drives the same UI an editor uses.
 */

/**
 * Locate the panel chip (`.cap-author`) for a given co-author by display name.
 *
 * @param {import('@playwright/test').Page} page        Playwright page.
 * @param {string}                          displayName Author display name shown in the chip.
 * @return {import('@playwright/test').Locator} The chip locator.
 */
function coAuthorChip( page, displayName ) {
	return page.locator( '.cap-author', { hasText: displayName } );
}

/**
 * Open the "Authors" document-settings panel, expanding it if it is collapsed.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function openAuthorsPanel( page ) {
	const panelToggle = page.getByRole( 'button', { name: 'Authors' } );
	if ( 'false' === ( await panelToggle.getAttribute( 'aria-expanded' ) ) ) {
		await panelToggle.click();
	}
}

/**
 * Search for an author in the panel combobox and add them to the byline.
 *
 * Relies on Playwright auto-waiting for the matching option and the resulting
 * chip, which absorbs the 500ms search debounce without a fixed timeout.
 *
 * @param {import('@playwright/test').Page} page         Playwright page.
 * @param {string}                          displayName  Author display name (the option label and resulting chip text).
 * @param {string}                          [searchTerm] Text to type; defaults to the display name.
 */
async function addCoAuthor( page, displayName, searchTerm = displayName ) {
	const combobox = page.getByRole( 'combobox', { name: 'Select An Author' } );
	await combobox.click();
	await combobox.fill( searchTerm );

	// The option label is "Display Name | email"; a substring match on the
	// display name is enough so long as display names are not substrings of
	// one another (enforced by the fixtures).
	await page.getByRole( 'option', { name: displayName } ).click();
	await coAuthorChip( page, displayName ).waitFor();
}

/**
 * Remove a co-author from the byline via its chip's "Remove Author" button.
 *
 * @param {import('@playwright/test').Page} page        Playwright page.
 * @param {string}                          displayName Author display name to remove.
 */
async function removeCoAuthor( page, displayName ) {
	await coAuthorChip( page, displayName )
		.getByRole( 'button', { name: 'Remove Author' } )
		.click();
}

/**
 * Move a co-author up or down via its chip's chevron button.
 *
 * @param {import('@playwright/test').Page} page        Playwright page.
 * @param {string}                          displayName Author display name to move.
 * @param {'up'|'down'}                     direction   Direction to move the author.
 */
async function moveCoAuthor( page, displayName, direction ) {
	// Source labels are inconsistently cased ("Move Up" / "Move down").
	const label = 'up' === direction ? 'Move Up' : 'Move down';
	await coAuthorChip( page, displayName )
		.getByRole( 'button', { name: label, exact: true } )
		.click();
}

/**
 * Read the ordered list of co-author display names currently shown in the panel.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<string[]>} Display names in panel order.
 */
async function getCoAuthorNames( page ) {
	const names = await page
		.locator( '.cap-author .cap-author-flex-item span' )
		.allInnerTexts();
	return names.map( ( name ) => name.trim() );
}

/**
 * Read the saved co-authors for a post via the public REST endpoint — the
 * persisted byline, independent of the active theme's markup or the editor UI.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils fixture.
 * @param {number}                                                      postId       Post ID.
 * @return {Promise<string[]>} Display names in saved order.
 */
async function getSavedCoAuthors( requestUtils, postId ) {
	const coauthors = await requestUtils.rest( {
		path: `/coauthors/v1/coauthors?post_id=${ postId }`,
	} );
	return coauthors.map( ( coauthor ) => coauthor.display_name );
}

module.exports = {
	coAuthorChip,
	openAuthorsPanel,
	addCoAuthor,
	removeCoAuthor,
	moveCoAuthor,
	getCoAuthorNames,
	getSavedCoAuthors,
};
