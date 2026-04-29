import { applyFilters } from '@wordpress/hooks';

/**
 * Move an item up or down in an array.
 *
 * @param {string} targetItem Item to move.
 * @param {Array}  itemsArr   Array in which to move the item.
 * @param {string} direction  'up' or 'down'
 * @return {Array} Array with reordered items.
 */
export const moveItem = ( targetItem, itemsArr, direction ) => {
	const currIndex = itemsArr
		.map( ( item ) => item.value )
		.indexOf( targetItem.value );
	const indexUpdate = direction === 'up' ? -1 : 1;
	const newIndex = currIndex + indexUpdate;

	const arrCopy = itemsArr.map( ( item ) => Object.assign( {}, item ) );
	const targetCopy = arrCopy[ currIndex ];

	const newItems = ( () => {
		return arrCopy.filter( ( item ) => {
			if ( item.value ) {
				return item.value !== targetCopy.value;
			}
			return item !== targetCopy;
		} );
	} )();
	const sortedArr = [ ...newItems ];

	sortedArr.splice( newIndex, 0, targetCopy );

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
	const newAuthorObj = dropDownAuthors.filter(
		( item ) => item.value === newAuthorValue
	);
	return [ ...currAuthors, newAuthorObj[ 0 ] ];
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
 * @param {Object} root0              An author object from the API endpoint.
 * @param {string} root0.id           The author ID.
 * @param {string} root0.displayName  Name to display in the UI.
 * @param {string} root0.userNicename The unique username.
 * @param {string} root0.email        The author's email address.
 * @param {string} root0.userType     The entity type, either 'wpuser' or 'guest-user'.
 *
 * @return {Object} The object containing data relevant to the Coauthors component.
 */
export const formatAuthorData = ( author ) => {
	const { id, displayName, userNicename, email, userType, termId } = author;

	return {
		id,
		termId,
		label: applyFilters( 'coAuthors.formatAuthorData.label', `${ displayName } | ${ email }`, author ),
		display: displayName,
		value: userNicename,
		userType,
	};
};
