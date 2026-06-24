/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import {
	BlockControls,
	BlockContextProvider,
	useBlockProps,
	useInnerBlocksProps,
	store as blockEditorStore,
	InspectorControls,
	RichText,
	AlignmentControl,
} from '@wordpress/block-editor';
import { TextControl, ToolbarGroup, PanelBody } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { row, stack, grid, list, lineDashed } from '@wordpress/icons';
import classnames from 'classnames';

import MemoizedCoAuthorTemplateBlockPreview from './components/memoized-coauthor-template-block-preview';

/**
 * CoAuthor Template Inner Blocks
 */
function CoAuthorTemplateInnerBlocks() {
	return (
		<div
			{ ...useInnerBlocksProps(
				{ className: 'wp-block-co-authors-plus-coauthor' },
				{
					template: [ [ 'co-authors-plus/name' ] ],
					__unstableDisableLayoutClassNames: true,
				}
			) }
		/>
	);
}

const ALLOWED_FORMATS = [ 'core/bold', 'core/italic', 'core/text-color' ];

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @param {Object}   props                            Block props.
 * @param {Object}   props.attributes                 Block attributes.
 * @param {Function} props.setAttributes              Function to update block attributes.
 * @param {string}   props.clientId                   Block client ID.
 * @param {Object}   props.context                    Block context.
 * @param {boolean}  props.isSelected                 Whether the block is currently selected.
 * @param {string}   props.__unstableLayoutClassNames Layout class names provided by the editor.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @return {Element} Element to render.
 */
export default function Edit( {
	attributes,
	setAttributes,
	clientId,
	context,
	isSelected,
	__unstableLayoutClassNames,
} ) {
	const { prefix, separator, lastSeparator, suffix, layout, textAlign } =
		attributes;
	const { type: layoutType, orientation: layoutOrientation } = layout || {};

	const { postId } = context;
	const authorPlaceholder = useSelect(
		( select ) => select( 'co-authors-plus/blocks' ).getAuthorPlaceholder(),
		[]
	);
	const [ coAuthors, setCoAuthors ] = useState( [ authorPlaceholder ] );
	const [ activeBlockContextId, setActiveBlockContextId ] = useState();
	const noticesDispatch = useDispatch( 'core/notices' );

	useEffect( () => {
		if ( ! postId ) {
			return;
		}

		const controller = new AbortController();

		const handleError = ( error ) => {
			if ( 'AbortError' === error.name ) {
				return;
			}
			noticesDispatch.createErrorNotice( error.message, {
				isDismissible: true,
			} );
		};

		apiFetch( {
			path: `/coauthors/v1/coauthors?post_id=${ postId }`,
			signal: controller.signal,
		} )
			.then( setCoAuthors )
			.catch( handleError );

		return () => {
			controller.abort();
		};
	}, [ postId, noticesDispatch ] );

	const blocks = useSelect( ( select ) => {
		return select( blockEditorStore ).getBlocks( clientId );
	} );

	const setLayout = ( nextLayout ) => {
		setAttributes( {
			layout: nextLayout,
		} );
	};

	const layoutControls = [
		{
			icon: lineDashed,
			title: __( 'Inline view' ),
			onClick: () =>
				setLayout( {
					type: 'default',
				} ),
			isActive: layoutType === 'default',
		},
		{
			icon: list,
			title: __( 'List view' ),
			onClick: () =>
				setLayout( {
					type: 'constrained',
				} ),
			isActive: layoutType === 'constrained',
		},
		{
			icon: grid,
			title: __( 'Grid view' ),
			onClick: () =>
				setLayout( {
					type: 'grid',
				} ),
			isActive: layoutType === 'grid',
		},
		{
			icon: row,
			title: __( 'Row view' ),
			onClick: () =>
				setLayout( {
					type: 'flex',
					orientation: 'horizontal',
				} ),
			isActive:
				layoutType === 'flex' && 'horizontal' === layoutOrientation,
		},
		{
			icon: stack,
			title: __( 'Stack view' ),
			onClick: () =>
				setLayout( {
					type: 'flex',
					orientation: 'vertical',
				} ),
			isActive: layoutType === 'flex' && 'vertical' === layoutOrientation,
		},
	];

	return (
		<>
			<BlockControls>
				<ToolbarGroup controls={ layoutControls } />
				<AlignmentControl
					value={ textAlign }
					onChange={ ( nextAlign ) => {
						setAttributes( { textAlign: nextAlign } );
					} }
				/>
			</BlockControls>
			<div
				{ ...useBlockProps( {
					className: classnames(
						__unstableLayoutClassNames,
						{
							[ `has-text-align-${ textAlign }` ]: textAlign,
						},
						'remove-outline'
					),
				} ) }
			>
				{ coAuthors &&
					'default' === layoutType &&
					( isSelected || prefix ) && (
						<RichText
							allowedFormats={ ALLOWED_FORMATS }
							className="wp-block-co-authors-plus-coauthors__prefix"
							multiline={ false }
							aria-label={ __( 'Prefix', 'co-authors-plus' ) }
							placeholder={
								__( 'Prefix', 'co-authors-plus' ) + ' '
							}
							value={ prefix }
							onChange={ ( value ) =>
								setAttributes( { prefix: value } )
							}
							tagName="span"
						/>
					) }
				{ coAuthors &&
					coAuthors
						.map( ( author ) => {
							const isHidden =
								author.id ===
								( activeBlockContextId || coAuthors[ 0 ]?.id );
							return (
								<BlockContextProvider
									key={ author.id }
									value={ {
										'co-authors-plus/author': author,
										'co-authors-plus/layout': layoutType,
									} }
								>
									{ isHidden ? (
										<CoAuthorTemplateInnerBlocks />
									) : null }
									<MemoizedCoAuthorTemplateBlockPreview
										blocks={ blocks }
										blockContextId={ author.id }
										setActiveBlockContextId={
											setActiveBlockContextId
										}
										isHidden={ isHidden }
									/>
								</BlockContextProvider>
							);
						} )
						.reduce( ( previous, current, index, all ) => (
							<>
								{ previous }
								{ 'default' === layoutType && (
									<span className="wp-block-co-authors-plus-coauthors__separator">
										{ lastSeparator &&
										index === all.length - 1
											? `${ lastSeparator }`
											: `${ separator }` }
									</span>
								) }
								{ current }
							</>
						) ) }
				{ coAuthors &&
					'default' === layoutType &&
					( isSelected || suffix ) && (
						<RichText
							allowedFormats={ ALLOWED_FORMATS }
							className="wp-block-co-authors-plus-coauthors__suffix"
							multiline={ false }
							aria-label={ __( 'Suffix' ) }
							placeholder={ __( 'Suffix' ) + ' ' }
							value={ suffix }
							onChange={ ( value ) =>
								setAttributes( { suffix: value } )
							}
							tagName="span"
						/>
					) }
			</div>
			<InspectorControls>
				{ 'default' === layoutType && (
					<PanelBody
						title={ __( 'Co-Authors Layout', 'co-authors-plus' ) }
					>
						<TextControl
							autoComplete="off"
							label={ __( 'Separator', 'co-authors-plus' ) }
							value={ separator || '' }
							onChange={ ( nextValue ) => {
								setAttributes( { separator: nextValue } );
							} }
							help={ __(
								'Enter character(s) used to separate authors.',
								'co-authors-plus'
							) }
						/>
						<TextControl
							autoComplete="off"
							label={ __( 'Last Separator', 'co-authors-plus' ) }
							value={ lastSeparator || '' }
							onChange={ ( nextValue ) => {
								setAttributes( { lastSeparator: nextValue } );
							} }
							help={ __(
								'Enter character(s) used to separate the last author.',
								'co-authors-plus'
							) }
						/>
					</PanelBody>
				) }
			</InspectorControls>
		</>
	);
}
