/**
 * WordPress dependencies.
 */
import { chevronUp, chevronDown, close } from '@wordpress/icons';
import { Button, Flex, FlexItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Utils
 */
import { moveItem, removeItem } from '../../utils';

/**
 * Author Selection feature.
 *
 * @param {Object}   param0 props.
 * @param {array}    param0.selectedAuthors selected authors array.
 * @param {function} param0.updateAuthors function to set selected authors.
 *
 * @returns {JSXElement}
 */
const AuthorsSelection = ( { selectedAuthors, updateAuthors } ) => {
	/**
	 *
	 * @param {object}   author author object.
	 * @param {function} action action type.
	 */
	const onClick = ( author, action ) => {
		let authors;

		switch ( action ) {
			case 'moveDown':
				authors = moveItem( author, selectedAuthors, 'down' );
				break;

			case 'moveUp':
				authors = moveItem( author, selectedAuthors, 'up' );
				break;

			case 'remove':
				authors = removeItem( author, selectedAuthors );
				break;
		}

		updateAuthors( authors );
	};

	// Bail if there are no selected authors.
	if ( ! selectedAuthors?.length ) {
		return null;
	}

	return selectedAuthors.map( ( author, i ) => {
		const display = author.display;
		const value = author.value;

		return (
			<div key={ value } className="cap-author">
				<Flex align="center">
					<FlexItem className="cap-author-flex-item">
						<span>{ display }</span>
					</FlexItem>
					<FlexItem
						justify="flex-end"
						className="cap-author-flex-item"
					>
						<Flex>
							<div className="cap-icon-button-stack">
								<Button
									icon={ chevronUp }
									className={ 'cap-icon-button' }
									label={ __( 'Move Up', 'co-authors-plus' ) }
									disabled={
										i === 0 || 1 === selectedAuthors.length
									}
									onClick={ () =>
										onClick( author, 'moveUp' )
									}
								/>
								<Button
									icon={ chevronDown }
									className={ 'cap-icon-button' }
									label={ __(
										'Move down',
										'co-authors-plus'
									) }
									disabled={
										i === selectedAuthors.length - 1 ||
										1 === selectedAuthors.length
									}
									onClick={ () =>
										onClick( author, 'moveDown' )
									}
								/>
							</div>
							<Button
								icon={ close }
								iconSize={ 20 }
								className={ 'cap-icon-button' }
								label={ __(
									'Remove Author',
									'co-authors-plus'
								) }
								disabled={ 1 === selectedAuthors.length }
								onClick={ () => onClick( author, 'remove' ) }
							/>
						</Flex>
					</FlexItem>
				</Flex>
			</div>
		);
	} );
};

export default AuthorsSelection;
