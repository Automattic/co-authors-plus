/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	ComboboxControl,
	Modal,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { useDebounce } from '@wordpress/compose';

/**
 * Components
 */
import AuthorsSelection from '../author-selection';

/**
 * Utilities
 */
import {
	addItemByValue,
	buildCoauthorTermIds,
	extractTermIds,
	formatAuthorData,
	needsPostIdFallback,
} from '../../utils';

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
	 * Tracks whether the initial empty-query author list has been loaded
	 * for this mount. The mount effect uses this to avoid duplicate initial
	 * fetches; clearing the input now intentionally re-fetches the list.
	 */
	const hasInitialLoaded = useRef( false );

	/**
	 * Last non-empty query the user typed into the combobox. Prefills the
	 * create-guest-author modal so the user does not have to retype. The
	 * short-query branch in onFilterValueChange does not overwrite this, so
	 * the prefill stays accurate even when the user backspaces.
	 */
	const lastQuery = useRef( '' );

	/**
	 * Create-guest-author modal state.
	 */
	const [ createModalOpen, setCreateModalOpen ] = useState( false );
	const [ createDraftName, setCreateDraftName ] = useState( '' );
	const [ createEmail, setCreateEmail ] = useState( '' );
	const [ createError, setCreateError ] = useState( null );
	const [ createSubmitting, setCreateSubmitting ] = useState( false );

	/**
	 * Read co-author term IDs from the core entity store.
	 * Returns undefined until the post entity has loaded, then an array of term IDs.
	 * Normalizes the value to handle both integer IDs and author objects.
	 *
	 * The IDs are returned as a comma-joined string rather than an array so that
	 * useSelect's shallow equality check can actually compare them. Returning a
	 * freshly built array fails that check on every store change, which
	 * re-renders this component continuously and cancels the debounced search
	 * below before it can fire.
	 */
	const { coauthorTermIdsKey, hasResolvedPost, postIdFallback } = useSelect(
		( select ) => {
			const { getEditedPostAttribute, getCurrentPostId } =
				select( 'core/editor' );
			const coauthors = getEditedPostAttribute( 'coauthors' );
			return {
				coauthorTermIdsKey: extractTermIds( coauthors ).join( ',' ),
				hasResolvedPost: coauthors !== undefined,
				// When the post has co-authors but their stored shape yields no
				// term IDs (e.g. a third-party REST override), resolve them by
				// post ID instead so the panel still populates.
				postIdFallback: needsPostIdFallback( coauthors )
					? getCurrentPostId()
					: null,
			};
		},
		[]
	);

	/**
	 * Rebuild the term ID array from the stable key, so consumers below keep a
	 * stable reference for as long as the co-authors themselves are unchanged.
	 */
	const coauthorTermIds = useMemo(
		() =>
			'' === coauthorTermIdsKey
				? []
				: coauthorTermIdsKey.split( ',' ).map( Number ),
		[ coauthorTermIdsKey ]
	);

	/**
	 * Resolve term IDs to rich author data, falling back to the post ID when the
	 * stored co-author shape can't be read as term IDs.
	 */
	const { authors: selectedAuthors, isLoading } = useCoauthorDetails(
		coauthorTermIds,
		postIdFallback
	);

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
	 * Fetch authors matching the supplied query and update the dropdown.
	 *
	 * @param {string} query Search text to send to the REST endpoint.
	 */
	const fetchAuthors = async ( query ) => {
		const existingAuthors = selectedAuthors
			.map( ( item ) => item.value )
			.join( ',' );

		try {
			const response = await apiFetch( {
				path: `/coauthors/v1/search/?q=${ query }&existing_authors=${ existingAuthors }`,
				method: 'GET',
			} );
			setDropdownOptions(
				response.map( ( item ) => formatAuthorData( item ) )
			);
		} catch ( error ) {
			console.log( error ); // eslint-disable-line no-console
		}
	};

	/**
	 * Load the initial alphabetical author list once the post and its
	 * resolved co-authors are available, so the dropdown is already
	 * populated when the user first focuses it.
	 */
	useEffect( () => {
		if ( ! hasResolvedPost || isLoading || hasInitialLoaded.current ) {
			return;
		}

		hasInitialLoaded.current = true;
		fetchAuthors( '' );
	}, [ hasResolvedPost, isLoading ] );

	/**
	 * Setter for updating authors via the core entity store.
	 *
	 * Builds the new term ID list via buildCoauthorTermIds() so that any
	 * unresolved term IDs (whose details the REST endpoint couldn't load)
	 * survive an add / remove / reorder action by the user. Without this
	 * guard those IDs would be silently dropped when the user makes any edit.
	 *
	 * @param {Array} newAuthors array of rich author objects (with termId).
	 */
	const updateAuthors = ( newAuthors ) => {
		editPost( {
			coauthors: buildCoauthorTermIds(
				newAuthors,
				selectedAuthors,
				coauthorTermIds
			),
		} );
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
	 * Latest values needed by the debounced search handler.
	 *
	 * useDebounce lists its callback in the dependency array of the memo that
	 * builds the debounced function, and cancels the previous one on cleanup.
	 * An inline callback is a new reference on every render, so the pending
	 * search is rebuilt and cancelled before its timer elapses and the request
	 * is never sent. The handler below is therefore created once, with an empty
	 * dependency array, and reads current values through this ref instead.
	 */
	const latest = useRef( {} );
	latest.current = { fetchAuthors, threshold };

	/**
	 * The callback for updating autocomplete in the ComboBox component.
	 * Fetch a list of authors matching the search text.
	 *
	 * @param {string} query The text to search.
	 */
		const onFilterValueChange = useDebounce(
		useCallback( ( query ) => {
			const { fetchAuthors: search, threshold: minLength } =
				latest.current;

			if ( query.length >= minLength ) {
				lastQuery.current = query;
			}

			// Short or empty query: show the full alphabetical list. This keeps the
			// dropdown useful while the user is still typing, and avoids the confusing
			// "No items found" state for single-character input.
			if ( query.length < minLength ) {
				search( '' );
				return;
			}

			search( query );
		}, [] ),
		500
	);

	/**
	 * Open the create-guest-author modal, prefilled with the user's last
	 * query when available.
	 */
	const openCreateModal = () => {
		setCreateDraftName( lastQuery.current );
		setCreateEmail( '' );
		setCreateError( null );
		setCreateModalOpen( true );
	};

	/**
	 * Close the create-guest-author modal and reset its state.
	 */
	const closeCreateModal = () => {
		setCreateModalOpen( false );
		setCreateError( null );
		setCreateSubmitting( false );
	};

	/**
	 * Submit handler for the create-guest-author modal. POSTs to the REST
	 * endpoint, then inserts the new author into the byline using the same
	 * path as combobox selection so unresolved term IDs are preserved.
	 */
	const onSubmitCreate = async () => {
		setCreateError( null );
		setCreateSubmitting( true );

		try {
			const response = await apiFetch( {
				path: '/coauthors/v1/guest-authors',
				method: 'POST',
				data: {
					display_name: createDraftName,
					user_email: createEmail,
				},
			} );

			const formatted = formatAuthorData( response );
			updateAuthors( [ ...selectedAuthors, formatted ] );
			closeCreateModal();
		} catch ( error ) {
			const message =
				( error && error.message ) ||
				__( 'Could not create guest author.', 'co-authors-plus' );
			setCreateError( message );
			setCreateSubmitting( false );
		}
	};

	// Show spinner while the post entity or author details are still loading.
	// Once loading completes, render the resolved authors (which may be an
	// empty list) so the panel never sits on a perpetual spinner if the
	// REST endpoint can't resolve a term ID.
	const showSpinner = ! hasResolvedPost || isLoading;

	return (
		<>
			{ showSpinner ? (
				<Spinner />
			) : (
				<AuthorsSelection
					selectedAuthors={ selectedAuthors }
					updateAuthors={ updateAuthors }
				/>
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

			<Button
				variant="link"
				className="cap-create-guest-author-trigger"
				onClick={ openCreateModal }
			>
				{ __( '+ Create new guest author', 'co-authors-plus' ) }
			</Button>

			{ createModalOpen && (
				<Modal
					title={ __( 'Create new guest author', 'co-authors-plus' ) }
					onRequestClose={ closeCreateModal }
					className="cap-create-guest-author-modal"
				>
					<TextControl
						label={ __( 'Display name', 'co-authors-plus' ) }
						value={ createDraftName }
						onChange={ setCreateDraftName }
						required
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Email (optional)', 'co-authors-plus' ) }
						value={ createEmail }
						onChange={ setCreateEmail }
						type="email"
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<p className="cap-create-guest-author-help">
						{ __(
							'Creates a guest byline for someone who is not a registered WordPress user on this site. You can complete the rest of their profile later.',
							'co-authors-plus'
						) }
					</p>
					{ createError && (
						<p className="cap-create-guest-author-error" role="alert">
							{ createError }
						</p>
					) }
					<div className="cap-create-guest-author-actions">
						<Button
							variant="tertiary"
							onClick={ closeCreateModal }
							disabled={ createSubmitting }
						>
							{ __( 'Cancel', 'co-authors-plus' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ onSubmitCreate }
							disabled={
								createSubmitting ||
								createDraftName.trim().length === 0
							}
						>
							{ createSubmitting
								? __( 'Creating…', 'co-authors-plus' )
								: __( 'Create', 'co-authors-plus' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
};

export default CoAuthors;
