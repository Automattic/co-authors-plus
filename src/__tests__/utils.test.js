import {
	moveItem,
	removeItem,
	addItemByValue,
	buildCoauthorTermIds,
	extractTermIds,
	formatAuthorData,
} from '../utils';
import { addFilter, removeFilter } from '@wordpress/hooks';
import {
	selectedAuthors,
	newAuthorValue,
	dropdownOptions,
	rawAuthors,
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
		const result = buildCoauthorTermIds( [ author( 20 ) ], [], [ 5 ] );
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
			[ author( 10 ), author( null ), author( undefined ), {} ],
			[ author( 10 ) ],
			[ 10 ]
		);
		expect( result ).toStrictEqual( [ 10 ] );
	} );

	it( 'tolerates an undefined currentTermIds list', () => {
		const result = buildCoauthorTermIds( [ author( 10 ) ], [], undefined );
		expect( result ).toStrictEqual( [ 10 ] );
	} );
} );

describe( 'Utility - extractTermIds', () => {
	it.each( [
		[ 'returns integer IDs unchanged', [ 42, 43, 44 ], [ 42, 43, 44 ] ],
		[
			'extracts term_id from objects',
			[ { term_id: 42 }, { term_id: 43 } ],
			[ 42, 43 ],
		],
		[
			'filters out objects that only have user_id, id or ID',
			[ { user_id: 8703 }, { id: 5 }, { ID: 99 } ],
			[],
		],
		[
			'handles mixed integer and object entries',
			[ 42, { user_id: 43 }, { id: 5 }, { ID: 99 }, { term_id: 44 } ],
			[ 42, 44 ],
		],
		[
			'filters out objects with no recognizable ID property',
			[ { display_name: 'John Doe', user_nicename: 'john-doe' }, 42 ],
			[ 42 ],
		],
		[ 'returns empty array for undefined input', undefined, [] ],
		[ 'returns empty array for empty array input', [], [] ],
		[ 'returns empty array for non-array input', 'not an array', [] ],
	] )( '%s', ( _label, input, expected ) => {
		expect( extractTermIds( input ) ).toStrictEqual( expected );
	} );
} );

describe( 'Utility - formatAuthorData', () => {
	it( 'maps the raw author fields onto the Coauthors option shape', () => {
		expect( formatAuthorData( rawAuthors[ 0 ] ) ).toStrictEqual( {
			id: 5,
			termId: 42,
			label: 'Ruby Bridges | ruby@example.com',
			display: 'Ruby Bridges',
			value: 'ruby',
			userType: 'wpuser',
		} );
	} );

	it( 'preserves the guest-user userType and zero id', () => {
		expect( formatAuthorData( rawAuthors[ 1 ] ) ).toStrictEqual( {
			id: 0,
			termId: 43,
			label: 'Claudette Colvin | claudette@example.com',
			display: 'Claudette Colvin',
			value: 'claudette',
			userType: 'guest-user',
		} );
	} );

	it( 'leaves missing fields undefined rather than throwing', () => {
		expect( formatAuthorData( {} ) ).toStrictEqual( {
			id: undefined,
			termId: undefined,
			label: 'undefined | undefined',
			display: undefined,
			value: undefined,
			userType: undefined,
		} );
	} );

	it( 'lets the coAuthors.formatAuthorData.label filter override the label', () => {
		const filterName = 'coAuthors.formatAuthorData.label';
		const namespace = 'test/format-author-data';

		addFilter(
			filterName,
			namespace,
			( label, author ) =>
				`${ author.displayName } (${ author.userType })`
		);

		try {
			expect( formatAuthorData( rawAuthors[ 0 ] ).label ).toBe(
				'Ruby Bridges (wpuser)'
			);
		} finally {
			removeFilter( filterName, namespace );
		}
	} );
} );
