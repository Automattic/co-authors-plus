/**
 * Regression tests for the block editor "Select An Author" search.
 *
 * The debounced search handler must survive a re-render. Before the fix it was
 * an inline callback, rebuilt with a new reference on every render. useDebounce
 * cancels the previous debounced function on cleanup, so any store dispatch
 * within the 500ms window (autosave, notices, SEO analysis, ...) discarded the
 * queued search and the coauthors/v1/search request never reached the server.
 *
 * See PR #1356 and issue #1050.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useSelect, useDispatch } from '@wordpress/data';

/**
 * External dependencies
 */
import { render, act } from '@testing-library/react';

/**
 * Internal dependencies
 */
import CoAuthors from '../components/co-authors';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );

jest.mock( '../components/author-selection', () => ( {
	__esModule: true,
	default: () => null,
} ) );

jest.mock( '../hooks/use-coauthor-details', () => ( {
	__esModule: true,
	default: () => ( { authors: [], isLoading: false } ),
} ) );

/**
 * Capture the props handed to ComboboxControl so a test can drive the search
 * the way a user typing into the field would, without a real DOM widget.
 */
let comboboxProps;
jest.mock( '@wordpress/components', () => ( {
	ComboboxControl: ( props ) => {
		comboboxProps = props;
		return null;
	},
	Spinner: () => null,
} ) );

describe( 'CoAuthors author search', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		comboboxProps = undefined;

		apiFetch.mockResolvedValue( [] );
		useDispatch.mockReturnValue( { editPost: jest.fn() } );

		// Post resolved with no co-authors selected: the panel renders the
		// combobox rather than the loading spinner.
		useSelect.mockReturnValue( {
			coauthorTermIdsKey: '',
			hasResolvedPost: true,
		} );
	} );

	afterEach( () => {
		jest.runOnlyPendingTimers();
		jest.useRealTimers();
		jest.clearAllMocks();
	} );

	/**
	 * Render the panel and clear the mount effect's initial empty-query fetch,
	 * so assertions target the user's search alone.
	 *
	 * @return {Function} The render result's rerender() function.
	 */
	const renderPanel = async () => {
		const { rerender } = render( <CoAuthors /> );
		await act( async () => {} );
		apiFetch.mockClear();
		return rerender;
	};

	it( 'sends a search request once the debounce window closes', async () => {
		await renderPanel();

		act( () => {
			comboboxProps.onFilterValueChange( 'ada' );
		} );

		await act( async () => {
			jest.advanceTimersByTime( 500 );
		} );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: expect.stringContaining( 'q=ada' ),
			method: 'GET',
		} );
	} );

	it( 'still sends the request when the panel re-renders mid-debounce', async () => {
		const rerender = await renderPanel();

		// The user types a query, queuing the debounced search.
		act( () => {
			comboboxProps.onFilterValueChange( 'ada' );
		} );

		// A store dispatch re-renders the panel before the timer elapses. This
		// is the render that used to rebuild the callback and cancel the search.
		rerender( <CoAuthors /> );

		await act( async () => {
			jest.advanceTimersByTime( 500 );
		} );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: expect.stringContaining( 'q=ada' ),
			method: 'GET',
		} );
	} );

	it( 'offers the search results as formatted dropdown options', async () => {
		await renderPanel();

		apiFetch.mockResolvedValue( [
			{
				id: 5,
				termId: 42,
				displayName: 'Ada Lovelace',
				userNicename: 'ada',
				email: 'ada@example.com',
				userType: 'wpuser',
			},
		] );

		act( () => {
			comboboxProps.onFilterValueChange( 'ada' );
		} );

		await act( async () => {
			jest.advanceTimersByTime( 500 );
		} );

		expect( comboboxProps.options ).toStrictEqual( [
			{
				id: 5,
				termId: 42,
				label: 'Ada Lovelace | ada@example.com',
				display: 'Ada Lovelace',
				value: 'ada',
				userType: 'wpuser',
			},
		] );
	} );

	it( 'empties the dropdown when the search matches nobody', async () => {
		await renderPanel();

		act( () => {
			comboboxProps.onFilterValueChange( 'nobody' );
		} );

		await act( async () => {
			jest.advanceTimersByTime( 500 );
		} );

		expect( comboboxProps.options ).toStrictEqual( [] );
	} );
} );
