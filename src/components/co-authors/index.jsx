/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { ComboboxControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { useDebounce } from '@wordpress/compose';

/**
 * Components
 */
import AuthorsSelection from '../author-selection';

/**
 * Utilities
 */
import { addItemByValue, formatAuthorData } from '../../utils';

/**
 * Hooks
 */
import useCoauthorDetails from '../../hooks/use-coauthor-details';

/**
 * Styles
 */
import './style.css';

/**
 * The Render component that will be populated with data from
 * the select and methods from dispatch as composed below.
 *
 * @return {JSX.Element} Document sidebar panel component.
*/
const CoAuthors = () => {
	/**
	 * Local state for dropdown options (search results).
	 */
	const [ dropdownOptions, setDropdownOptions ] = useState( [] );

	/**
	 * Read co-author term IDs from the core entity store.
	 * Returns undefined until the post entity has loaded, then an array of term IDs.
	 */
	const { coauthorTermIds, hasResolvedPost } = useSelect( ( select ) => {
		const { getEditedPostAttribute } = select( 'core/editor' );
		const coauthors = getEditedPostAttribute( 'coauthors' );
		return {
			coauthorTermIds: coauthors,
			hasResolvedPost: coauthors !== undefined,
		};
	}, [] );

	/**
	 * Resolve term IDs to rich author data.
	 */
	const { authors: selectedAuthors, isLoading } = useCoauthorDetails( coauthorTermIds );

	/**
	 * Get editPost dispatcher to write changes back to the core entity.
	 */
	const { editPost } = useDispatch( 'core/editor' );

	/**
	 * Threshold filter for determining when a search query is preformed.
	 *
	 * @param {integer} threshold length threshold. default 2.
	 */
	const threshold = applyFilters( 'coAuthors.search.threshold', 2 );

	/**
	 * Setter for updating authors via the core entity store.
	 *
	 * @param {Array} newAuthors array of rich author objects (with termId).
	 */
	const updateAuthors = ( newAuthors ) => {
		const termIds = newAuthors.map( ( author ) => author.termId );
		editPost( { coauthors: termIds } );
	};

	/**
	 * Change handler for adding new item by value.
	 * Updates authors state via the core entity.
	 *
	 * @param {Object} newAuthorValue new authors selected.
	 */
	const onChange = ( newAuthorValue ) => {
		const newAuthors = addItemByValue(
			newAuthorValue,
			selectedAuthors,
			dropdownOptions
		);

		updateAuthors( newAuthors );
	};

	/**
	 * The callback for updating autocomplete in the ComboBox component.
	 * Fetch a list of authors matching the search text.
	 *
	 * @param {string} query The text to search.
	 */
	const onFilterValueChange = useDebounce( async ( query ) => {
		let response = 0;

		// Don't kick off search without having at least two characters.
		if ( query.length < threshold ) {
			setDropdownOptions( [] );
			return;
		}

		const existingAuthors = selectedAuthors
			.map( ( item ) => item.value )
			.join( ',' );

		try {
			response = await apiFetch( {
				path: `/coauthors/v1/search/?q=${ query }&existing_authors=${ existingAuthors }`,
				method: 'GET',
			} );
			const formattedAuthors = ( ( items ) => {
				if ( items.length > 0 ) {
					return items.map( ( item ) => formatAuthorData( item ) );
				}
				return [];
			} )( response );

			setDropdownOptions( formattedAuthors );
		} catch ( error ) {
			response = 0;
			console.log( error ); // eslint-disable-line no-console
		}
	}, 500 );

	// Show spinner while the post entity is loading or authors are being resolved.
	const showSpinner = ! hasResolvedPost || isLoading || ( hasResolvedPost && coauthorTermIds?.length && ! selectedAuthors.length );

	return (
		<>
			{ ! showSpinner && Boolean( selectedAuthors.length ) ? (
				<>
					<AuthorsSelection
						selectedAuthors={ selectedAuthors }
						updateAuthors={ updateAuthors }
					/>
				</>
			) : (
				<Spinner />
			) }

			<ComboboxControl
				className="cap-combobox"
				label={ __( 'Select An Author', 'co-authors-plus' ) }
				value={ null }
				options={ dropdownOptions }
				onChange={ onChange }
				onFilterValueChange={ onFilterValueChange }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		</>
	);
};

export default CoAuthors;
