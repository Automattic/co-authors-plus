/**
 * Tests for the selected co-authors list in the document sidebar.
 *
 * These cover the contract a malformed `propTypes` block used to claim to
 * check: what `selectedAuthors` entries the component reads, and what shape it
 * hands back through `updateAuthors`. `PropTypes.arrayOf()` takes a validator
 * rather than an array literal, so the declaration validated nothing and named
 * fields (`displayName`, `userNiceName`, `avatar`) this component never reads.
 */

/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AuthorsSelection from '../components/author-selection';
import { selectedAuthors } from '../__fixtures__/authors';

/**
 * The real @wordpress/components cannot be required here: it pulls in an ESM
 * build of uuid that this Jest config will not transform. Stub the three
 * components used, keeping Button faithful enough for role-and-name queries —
 * it renders a real <button> whose accessible name comes from `label`, exactly
 * as the WordPress component does.
 */
jest.mock( '@wordpress/components', () => ( {
	Button: ( { label, disabled, onClick } ) => (
		<button
			aria-label={ label }
			disabled={ disabled }
			onClick={ onClick }
		/>
	),
	Flex: ( { children } ) => <div>{ children }</div>,
	FlexItem: ( { children } ) => <div>{ children }</div>,
} ) );

jest.mock( '@wordpress/icons', () => ( {
	chevronUp: 'chevron-up',
	chevronDown: 'chevron-down',
	close: 'close',
} ) );

const renderSelection = ( authors ) => {
	const updateAuthors = jest.fn();
	render(
		<AuthorsSelection
			selectedAuthors={ authors }
			updateAuthors={ updateAuthors }
		/>
	);
	return updateAuthors;
};

describe( 'AuthorsSelection', () => {
	it( 'renders one row per author, labelled by display', () => {
		renderSelection( selectedAuthors );

		selectedAuthors.forEach( ( author ) => {
			expect( screen.getByText( author.display ) ).toBeTruthy();
		} );
	} );

	it.each( [ [ undefined ], [ null ], [ [] ] ] )(
		'renders nothing for %p',
		( authors ) => {
			const { container } = render(
				<AuthorsSelection
					selectedAuthors={ authors }
					updateAuthors={ jest.fn() }
				/>
			);

			expect( container.innerHTML ).toBe( '' );
		}
	);

	it( 'disables the moves that would fall off either end', () => {
		renderSelection( selectedAuthors );

		const up = screen.getAllByRole( 'button', { name: 'Move Up' } );
		const down = screen.getAllByRole( 'button', { name: 'Move down' } );

		// First author cannot move up, last cannot move down. moveItem() would
		// splice at -1 and wrap the author to the end of the list.
		expect( up[ 0 ].disabled ).toBe( true );
		expect( up[ up.length - 1 ].disabled ).toBe( false );
		expect( down[ 0 ].disabled ).toBe( false );
		expect( down[ down.length - 1 ].disabled ).toBe( true );
	} );

	it( 'disables every control when a single author is left', () => {
		renderSelection( [ selectedAuthors[ 0 ] ] );

		[ 'Move Up', 'Move down', 'Remove Author' ].forEach( ( name ) => {
			expect( screen.getByRole( 'button', { name } ).disabled ).toBe(
				true
			);
		} );
	} );

	it( 'reorders the list when an author is moved down', () => {
		const updateAuthors = renderSelection( selectedAuthors );

		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'Move down' } )[ 0 ]
		);

		expect( updateAuthors ).toHaveBeenCalledWith( [
			selectedAuthors[ 1 ],
			selectedAuthors[ 0 ],
			selectedAuthors[ 2 ],
			selectedAuthors[ 3 ],
		] );
	} );

	it( 'drops the author from the list when removed', () => {
		const updateAuthors = renderSelection( selectedAuthors );

		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'Remove Author' } )[ 1 ]
		);

		expect( updateAuthors ).toHaveBeenCalledWith( [
			selectedAuthors[ 0 ],
			selectedAuthors[ 2 ],
			selectedAuthors[ 3 ],
		] );
	} );
} );
