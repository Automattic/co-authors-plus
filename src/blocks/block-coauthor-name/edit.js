/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import {
	useBlockProps,
	InspectorControls,
	AlignmentControl,
	BlockControls,
} from '@wordpress/block-editor';
import {
	TextControl,
	PanelBody,
	ToggleControl,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import classnames from 'classnames';

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.context       Block context provided by the parent Co-Authors block.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update block attributes.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @return {Element} Element to render.
 */
export default function Edit( { context, attributes, setAttributes } ) {
	const { isLink, rel, tagName, textAlign } = attributes;
	const authorPlaceholder = useSelect(
		( select ) => select( 'co-authors-plus/blocks' ).getAuthorPlaceholder(),
		[]
	);
	const author = context[ 'co-authors-plus/author' ] || authorPlaceholder;
	const { link, display_name: displayName } = author;

	const TagName = tagName;

	return (
		<>
			<BlockControls>
				<AlignmentControl
					value={ textAlign }
					onChange={ ( nextAlign ) => {
						setAttributes( { textAlign: nextAlign } );
					} }
				/>
			</BlockControls>
			<TagName
				{ ...useBlockProps( {
					className: classnames( {
						[ `has-text-align-${ textAlign }` ]: textAlign,
					} ),
				} ) }
			>
				{ isLink ? (
					<a
						href={ link }
						rel={ rel }
						onClick={ ( event ) => event.preventDefault() }
					>
						{ displayName }
					</a>
				) : (
					displayName
				) }
			</TagName>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'co-authors-plus' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Make co-author name a link',
							'co-authors-plus'
						) }
						onChange={ () => setAttributes( { isLink: ! isLink } ) }
						checked={ isLink }
					/>
					{ isLink && (
						<>
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Link rel', 'co-authors-plus' ) }
								value={ rel }
								onChange={ ( newRel ) =>
									setAttributes( { rel: newRel } )
								}
							/>
						</>
					) }
				</PanelBody>
			</InspectorControls>
			<InspectorControls group="advanced">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'HTML element', 'co-authors-plus' ) }
					options={ [
						{ label: __( 'Default (<p>)' ), value: 'p' },
						{ label: '<span>', value: 'span' },
						{ label: '<h1>', value: 'h1' },
						{ label: '<h2>', value: 'h2' },
						{ label: '<h3>', value: 'h3' },
						{ label: '<h4>', value: 'h4' },
						{ label: '<h5>', value: 'h5' },
						{ label: '<h6>', value: 'h6' },
					] }
					value={ tagName }
					onChange={ ( value ) =>
						setAttributes( { tagName: value } )
					}
				/>
			</InspectorControls>
		</>
	);
}
