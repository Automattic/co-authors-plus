import {
	moveItem,
	removeItem,
	addItemByValue,
	buildCoauthorTermIds,
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
