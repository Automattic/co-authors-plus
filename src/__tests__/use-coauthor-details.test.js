/**
 * Regression tests for the co-author details hook — in particular the post-ID
 * fallback that keeps the Authors panel populated when a third-party
 * `register_rest_field` override replaces the `coauthors` REST field's
 * term-ID array with author objects carrying no `term_id`.
 *
 * See issue #1277.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * External dependencies
 */
import { renderHook, act } from '@testing-library/react';

/**
 * Internal dependencies
 */
import useCoauthorDetails from '../hooks/use-coauthor-details';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

/**
 * Author rows as returned by both coauthors/v1 endpoints
 * (see CoAuthors_API_Endpoints::_format_author_data()).
 */
const RAW_AUTHORS = [
	{
		id: '1',
		termId: 101,
		userNicename: 'admin',
		email: 'admin@example.com',
		displayName: 'admin',
		userType: 'wp-user',
	},
	{
		id: '2',
		termId: 102,
		userNicename: 'jane-doe',
		email: 'jane@example.com',
		displayName: 'Jane Doe',
		userType: 'guest-user',
	},
];

describe( 'useCoauthorDetails', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'resolves term IDs through the authors-by-term-ids endpoint', async () => {
		apiFetch.mockResolvedValue( RAW_AUTHORS );

		const { result } = renderHook( () =>
			useCoauthorDetails( [ 101, 102 ] )
		);

		await act( async () => {} );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/coauthors/v1/authors-by-term-ids?ids=101,102',
			method: 'GET',
		} );
		expect( result.current.authors.map( ( a ) => a.display ) ).toEqual( [
			'admin',
			'Jane Doe',
		] );
	} );

	it( 'falls back to resolving by post ID when no term IDs are available', async () => {
		apiFetch.mockResolvedValue( RAW_AUTHORS );

		const { result } = renderHook( () => useCoauthorDetails( [], 123 ) );

		await act( async () => {} );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/coauthors/v1/authors/123',
			method: 'GET',
		} );
		expect( result.current.authors.map( ( a ) => a.display ) ).toEqual( [
			'admin',
			'Jane Doe',
		] );
		expect( result.current.isLoading ).toBe( false );
	} );

	it( 'fetches nothing when the post has no co-authors and no fallback', async () => {
		const { result } = renderHook( () => useCoauthorDetails( [] ) );

		await act( async () => {} );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( result.current.authors ).toEqual( [] );
	} );

	it( 'primes the term-ID cache from the fallback response', async () => {
		apiFetch.mockResolvedValue( RAW_AUTHORS );

		const { rerender, result } = renderHook(
			( { termIds, postId } ) => useCoauthorDetails( termIds, postId ),
			{ initialProps: { termIds: [], postId: 123 } }
		);

		await act( async () => {} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		// Once an edit writes real term IDs back to the entity store, the hook
		// serves them from the cache the fallback built — no second request.
		rerender( { termIds: [ 101, 102 ], postId: null } );
		await act( async () => {} );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( result.current.authors.map( ( a ) => a.termId ) ).toEqual( [
			101, 102,
		] );
	} );
} );
