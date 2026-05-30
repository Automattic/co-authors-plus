import {
	moveItem,
	removeItem,
	addItemByValue,
	buildCoauthorTermIds,
	extractTermIds,
} from '../utils';
import {
	selectedAuthors,
	newAuthorValue,
	dropdownOptions,
} from '../__mocks__/authors';

describe( 'Utility - moveItem', () => {
	it( 'should move an option down', () => {
		expect(
			moveItem( selectedAuthors[ 0 ], selectedAuthors, 'down' )
		).toStrictEqual( [
			selectedAuthors[ 1 ],
			selectedAuthors[ 0 ],
			selectedAuthors[ 2 ],
			selectedAuthors[ 3 ],
		] );
	} );

	it( 'should move an option up', () => {
		expect(
			moveItem( selectedAuthors[ 2 ], selectedAuthors, 'up' )
		).toStrictEqual( [
			selectedAuthors[ 0 ],
			selectedAuthors[ 2 ],
			selectedAuthors[ 1 ],
			selectedAuthors[ 3 ],
		] );
	} );

	it( 'should move an item to last', () => {
		expect(
			moveItem( selectedAuthors[ 2 ], selectedAuthors, 'down' )
		).toStrictEqual( [
			selectedAuthors[ 0 ],
			selectedAuthors[ 1 ],
			selectedAuthors[ 3 ],
			selectedAuthors[ 2 ],
		] );
	} );

	it( 'should move items multiple times in multiple directions', () => {
		expect(
			moveItem( selectedAuthors[ 2 ], selectedAuthors, 'up' )
		).toStrictEqual( [
			selectedAuthors[ 0 ],
			selectedAuthors[ 2 ],
			selectedAuthors[ 1 ],
			selectedAuthors[ 3 ],
		] );

		const reorderedArray = [
			selectedAuthors[ 0 ],
			selectedAuthors[ 2 ],
			selectedAuthors[ 1 ],
			selectedAuthors[ 3 ],
		];

		expect(
			moveItem( selectedAuthors[ 2 ], reorderedArray, 'down' )
		).toStrictEqual( [
			selectedAuthors[ 0 ],
			selectedAuthors[ 1 ],
			selectedAuthors[ 2 ],
			selectedAuthors[ 3 ],
		] );
	} );
} );

describe( 'Utility - removeItem', () => {
	it( 'should remove an item from an array', () => {
		expect(
			removeItem( selectedAuthors[ 2 ], selectedAuthors )
		).toStrictEqual( [
			selectedAuthors[ 0 ],
			selectedAuthors[ 1 ],
			selectedAuthors[ 3 ],
		] );
	} );
} );

describe( 'Utility - addItemByValue', () => {
	it( 'should add an item from dropdown options to end of the array', () => {
		expect(
			addItemByValue( newAuthorValue, selectedAuthors, dropdownOptions )
		).toStrictEqual( [ ...selectedAuthors, dropdownOptions[ 0 ] ] );
	} );
} );

describe( 'Utility - buildCoauthorTermIds', () => {
	const author = ( termId ) => ( { termId } );

	it( 'returns the term IDs of the new authors when everything resolved', () => {
		const result = buildCoauthorTermIds(
			[ author( 10 ), author( 20 ) ],
			[ author( 10 ) ],
			[ 10 ]
		);
		expect( result ).toStrictEqual( [ 10, 20 ] );
	} );

	it( 'preserves term IDs that the REST endpoint failed to resolve', () => {
		// User's own term (5) couldn't be resolved, selectedAuthors is empty.
		// User then picks term 20 from the dropdown — term 5 must survive.
		const result = buildCoauthorTermIds(
			[ author( 20 ) ],
			[],
			[ 5 ]
		);
		expect( result ).toStrictEqual( [ 5, 20 ] );
	} );

	it( 'preserves unresolved IDs when the user removes a resolved author', () => {
		// Term 5 is unresolved, terms 10 + 20 are resolved.
		// User removes 10; the remaining list should keep 5 and 20.
		const result = buildCoauthorTermIds(
			[ author( 20 ) ],
			[ author( 10 ), author( 20 ) ],
			[ 5, 10, 20 ]
		);
		expect( result ).toStrictEqual( [ 5, 20 ] );
	} );

	it( 'drops authors that have no valid termId', () => {
		const result = buildCoauthorTermIds(
			[ author( 10 ), author( null ), author( undefined ), { } ],
			[ author( 10 ) ],
			[ 10 ]
		);
		expect( result ).toStrictEqual( [ 10 ] );
	} );

	it( 'tolerates an undefined currentTermIds list', () => {
		const result = buildCoauthorTermIds(
			[ author( 10 ) ],
			[],
			undefined
		);
		expect( result ).toStrictEqual( [ 10 ] );
	} );
} );

describe( 'Utility - extractTermIds', () => {
	it( 'returns integer IDs unchanged', () => {
		const result = extractTermIds( [ 42, 43, 44 ] );
		expect( result ).toStrictEqual( [ 42, 43, 44 ] );
	} );

	it( 'extracts term_id from objects', () => {
		const result = extractTermIds( [ { term_id: 42 }, { term_id: 43 } ] );
		expect( result ).toStrictEqual( [ 42, 43 ] );
	} );

	it( 'extracts user_id from objects (co-author objects)', () => {
		const result = extractTermIds( [ { user_id: 8703 }, { user_id: 16 } ] );
		expect( result ).toStrictEqual( [ 8703, 16 ] );
	} );

	it( 'extracts id from objects', () => {
		const result = extractTermIds( [ { id: 5 } ] );
		expect( result ).toStrictEqual( [ 5 ] );
	} );

	it( 'extracts ID from objects', () => {
		const result = extractTermIds( [ { ID: 99 } ] );
		expect( result ).toStrictEqual( [ 99 ] );
	} );

	it( 'handles mixed integer and object entries', () => {
		const result = extractTermIds( [ 42, { user_id: 43 }, { term_id: 44 } ] );
		expect( result ).toStrictEqual( [ 42, 43, 44 ] );
	} );

	it( 'filters out objects with no recognizable ID property', () => {
		const result = extractTermIds( [
			{ display_name: 'John Doe', user_nicename: 'john-doe' },
			42,
		] );
		expect( result ).toStrictEqual( [ 42 ] );
	} );

	it( 'returns empty array for undefined input', () => {
		expect( extractTermIds( undefined ) ).toStrictEqual( [] );
	} );

	it( 'returns empty array for empty array input', () => {
		expect( extractTermIds( [] ) ).toStrictEqual( [] );
	} );

	it( 'returns empty array for non-array input', () => {
		expect( extractTermIds( 'not an array' ) ).toStrictEqual( [] );
	} );
} );
