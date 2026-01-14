/**
 * Dependencies.
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { ComboboxControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useDispatch, useSelect, register } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { useDebounce } from '@wordpress/compose';

/**
 * Components
 */
import AuthorsSelection from '../author-selection'

/**
 * Utilities
 */
import { addItemByValue, formatAuthorData } from '../../utils';

/**
 * Store
 */
import coauthorsStore from '../../store';

/**
 * Styles
 */
import './style.css';

/**
 * Register our data store.
 */
register( coauthorsStore );

/**
 * The Render component that will be populated with data from
 * the select and methods from dispatch as composed below.
 *
 * @return {JSX.Element} Document sidebar panel component.
*/
const CoAuthors = () => {
	/**
	 * Local state
	 */
	const [ selectedAuthors, setSelectedAuthors ] = useState( [] ); // Currently selected options.
	const [ dropdownOptions, setDropdownOptions ] = useState( [] ); // Options that are available in the dropdown.

	/**
	 * Retrieve post id.
	 */
	const postId = useSelect( ( select ) =>
		select( 'core/editor' ).getCurrentPostId()
	);

	/**
	 * CoAuthor select functions.
	 */
	const saveAuthors = useSelect(
		( select ) => select( 'cap/authors' )?.saveAuthors,
		[]
	);

	/**
	 * CoAuthor select functions.
	 */
	const authors = useSelect(
		( select ) => select( 'cap/authors' )?.getAuthors( postId ),
		[ postId ]
	);

	/**
	 * Dispatchers
	 */
	const { setAuthorsStore } = useDispatch( 'cap/authors' );

	/**
	 * Threshold filter for determining when a search query is preformed.
	 *
	 * @param {integer} threshold length threshold. default 2.
	 */
	const threshold = applyFilters( 'coAuthors.search.threshold', 2 );

	/**
	 * Setter for updating authors and selected authors simultaneously.
	 *
	 * @param {Array} newAuthors array of new authors.
	 */
	const updateAuthors = ( newAuthors ) => {
		setAuthorsStore( newAuthors );
		setSelectedAuthors( newAuthors );
	};

	/**
	 * Change handler for adding new item by value.
	 * Updates authors state.
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

	/**
	 * Run when authors updates.
	 */
	useEffect( () => {
		// Bail if no authors exist, no need to set empty values.
		if ( ! authors.length ) {
			return;
		}

		updateAuthors( authors );

	}, [ authors ] );

	return (
		<>
			{ Boolean( selectedAuthors.length ) ? (
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
