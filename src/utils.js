import { applyFilters } from '@wordpress/hooks';

/**
 * Normalize the raw coauthors value from the core entity store into
 * an array of integer term IDs.
 *
 * The entity store may return plain integers [42, 43] or full objects
 * with various ID properties depending on the WordPress version and
 * active plugins. This function handles both formats.
 *
 * @param {Array|undefined} coauthors Raw value from getEditedPostAttribute.
 * @return {Array} Array of integer term IDs.
 */
export const extractTermIds = ( coauthors ) => {
	if ( ! Array.isArray( coauthors ) || 0 === coauthors.length ) {
		return [];
	}

	return coauthors
		.map( ( item ) => {
			if ( Number.isInteger( item ) ) {
				return item;
			}
			if ( item && 'object' === typeof item ) {
				return item.term_id ?? null;
			}
			return null;
		} )
		.filter( ( id ) => Number.isInteger( id ) );
};

/**
 * Decide whether the post's co-authors must be resolved by post ID rather than
 * from the entity store's `coauthors` value.
 *
 * The sidebar normally reads the author taxonomy's default REST output — an
 * array of term IDs — from `getEditedPostAttribute( 'coauthors' )`. Some sites
 * override that REST field (commonly a `register_rest_field( 'post', 'coauthors',
 * … )` carried over from before Co-Authors Plus exposed the field itself), so it
 * arrives as full author objects with no `term_id`. `extractTermIds` then yields
 * nothing and the panel would render empty even though the post has co-authors.
 *
 * When that happens we fall back to the plugin's own `/coauthors/v1/authors/{id}`
 * endpoint, which returns the authors regardless of the REST field's shape.
 *
 * @param {Array|undefined} coauthors Raw value from getEditedPostAttribute.
 * @return {boolean} True when the post has co-authors but no usable term IDs.
 */
export const needsPostIdFallback = ( coauthors ) => {
	return (
		Array.isArray( coauthors ) &&
		coauthors.length > 0 &&
		0 === extractTermIds( coauthors ).length
	);
};

/**
 * Move an item up or down in an array.
 *
 * The array is returned unchanged when the move would leave it — moving the
 * first item up or the last item down — or when the item is not in the list at
 * all. Without that guard, moving the first item up splices at index -1, which
 * wraps it to second-from-last instead of doing nothing.
 *
 * @param {Object} targetItem Item to move.
 * @param {Array}  itemsArr   Array in which to move the item.
 * @param {string} direction  'up' or 'down'
 * @return {Array} Array with reordered items.
 */
export const moveItem = ( targetItem, itemsArr, direction ) => {
	const currIndex = itemsArr.findIndex(
		( item ) => item.value === targetItem.value
	);
	const newIndex = currIndex + ( 'up' === direction ? -1 : 1 );

	if ( currIndex < 0 || newIndex < 0 || newIndex >= itemsArr.length ) {
		return itemsArr;
	}

	const sortedArr = [ ...itemsArr ];
	sortedArr.splice( newIndex, 0, sortedArr.splice( currIndex, 1 )[ 0 ] );

	return sortedArr;
};

/**
 * Remove an item from the array.
 *
 * @param {Object} targetItem
 * @param {Array}  itemsArr
 * @return {Array} array of items with the target item removed.
 */
export const removeItem = ( targetItem, itemsArr ) => {
	return itemsArr.filter( ( item ) => item.value !== targetItem.value );
};

/**
 * Get the author object from the list of available authors,
 * then add it to the selected authors.
 *
 * @param {string} newAuthorValue
 * @param {Array}  currAuthors
 * @param {Array}  dropDownAuthors
 * @return {Array} Author objects including the new author.
 */
export const addItemByValue = (
	newAuthorValue,
	currAuthors,
	dropDownAuthors
) => {
	const newAuthorObj = dropDownAuthors.find(
		( item ) => item.value === newAuthorValue
	);
	return [ ...currAuthors, newAuthorObj ];
};

/**
 * Build the term ID list to persist after an edit.
 *
 * The editor can only display authors whose details the REST endpoint
 * resolved (`selectedAuthors`). When some IDs in `currentTermIds` couldn't be
 * resolved, they aren't represented in the UI, so a naive
 * `newAuthors.map( a => a.termId )` would silently drop them on the next
 * edit. This helper preserves those unresolved IDs at the front of the
 * returned list and appends the user's edited authors in order.
 *
 * @param {Array} newAuthors      Resolved authors after the user's edit.
 * @param {Array} selectedAuthors Resolved authors before the edit.
 * @param {Array} currentTermIds  All term IDs in the entity store (resolved + unresolved).
 * @return {Array} Term IDs to persist, with unresolved IDs preserved.
 */
export const buildCoauthorTermIds = (
	newAuthors,
	selectedAuthors,
	currentTermIds
) => {
	const isValidId = ( id ) => Number.isInteger( id );

	const newTermIds = newAuthors
		.map( ( author ) => author?.termId )
		.filter( isValidId );

	const resolvedTermIds = new Set(
		selectedAuthors.map( ( author ) => author?.termId ).filter( isValidId )
	);

	const unresolvedTermIds = ( currentTermIds || [] ).filter(
		( id ) => isValidId( id ) && ! resolvedTermIds.has( id )
	);

	return [ ...unresolvedTermIds, ...newTermIds ];
};

/**
 * Format the author option object.
 *
 * @param {Object} author              An author object from the API endpoint.
 * @param {string} author.id           The author ID.
 * @param {string} author.displayName  Name to display in the UI.
 * @param {string} author.userNicename The unique username.
 * @param {string} author.email        The author's email address.
 * @param {string} author.userType     The entity type, either 'wpuser' or 'guest-user'.
 *
 * @return {Object} The object containing data relevant to the Coauthors component.
 */
export const formatAuthorData = ( author ) => {
	const { id, displayName, userNicename, email, userType, termId } = author;

	return {
		id,
		termId,
		label: applyFilters(
			'coAuthors.formatAuthorData.label',
			`${ displayName } | ${ email }`,
			author
		),
		display: displayName,
		value: userNicename,
		userType,
	};
};
