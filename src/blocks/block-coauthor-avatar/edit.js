import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- Border support relies on this experimental hook; there is no stable equivalent yet.
	__experimentalUseBorderProps as useBorderProps,
	BlockControls,
	BlockAlignmentToolbar,
} from '@wordpress/block-editor';
import {
	SelectControl,
	PanelBody,
	ToggleControl,
	TextControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import classnames from 'classnames';

import PlaceholderImage from '../components/placeholder-image';

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
	const { isLink, rel, size, verticalAlign, align } = attributes;
	const authorPlaceholder = useSelect(
		( select ) => select( 'co-authors-plus/blocks' ).getAuthorPlaceholder(),
		[]
	);
	const author = context[ 'co-authors-plus/author' ] || authorPlaceholder;
	const layout = context[ 'co-authors-plus/layout' ] || '';

	// Hooks must run on every render, so they are called before the early
	// return below (rules of hooks).
	const borderProps = useBorderProps( attributes );
	const blockProps = useBlockProps( {
		className: classnames( {
			[ `align${ align }` ]:
				'default' !== layout && align && 'none' !== align,
		} ),
	} );

	const { avatar_urls: avatarUrls } = author;

	if ( ! avatarUrls || 0 === avatarUrls.length ) {
		return null;
	}

	const sizes = Object.keys( avatarUrls ).map( ( sizeKey ) => {
		return {
			value: sizeKey,
			label: `${ sizeKey } x ${ sizeKey }`,
		};
	} );

	const src = avatarUrls[ size ] ?? '';

	return (
		<>
			{ 'default' !== layout ? (
				<BlockControls>
					<BlockAlignmentToolbar
						value={ align }
						onChange={ ( nextAlign ) => {
							setAttributes( { align: nextAlign } );
						} }
						controls={ [ 'none', 'left', 'center', 'right' ] }
					/>
				</BlockControls>
			) : null }

			<div { ...blockProps }>
				{ '' === src ? (
					<PlaceholderImage
						className={ borderProps.className }
						dimensions={ { width: size, height: size } }
						style={ {
							height: size,
							width: size,
							minWidth: 'auto',
							minHeight: 'auto',
							padding: 0,
							verticalAlign,
							...borderProps.style,
						} }
					/>
				) : (
					<img
						alt={ author.display_name || '' }
						style={ { ...borderProps.style, verticalAlign } }
						width={ size }
						height={ size }
						src={ `${ avatarUrls[ size ] }` }
					/>
				) }
			</div>
			<InspectorControls>
				<PanelBody title={ __( 'Avatar Settings', 'co-authors-plus' ) }>
					<SelectControl
						label={ __( 'Avatar size', 'co-authors-plus' ) }
						value={ size }
						options={ sizes }
						onChange={ ( nextSize ) => {
							setAttributes( {
								size: Number( nextSize ),
							} );
						} }
					/>
					<ToggleControl
						label={ __(
							'Make avatar a link to author archive.',
							'co-authors-plus'
						) }
						onChange={ () => setAttributes( { isLink: ! isLink } ) }
						checked={ isLink }
					/>
					{ isLink && (
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Link rel', 'co-authors-plus' ) }
							value={ rel }
							onChange={ ( newRel ) =>
								setAttributes( { rel: newRel } )
							}
						/>
					) }
				</PanelBody>
				{ 'default' === layout ? (
					<PanelBody
						initialOpen={ false }
						title={ __( 'Co-Authors Layout', 'co-authors-plus' ) }
					>
						<SelectControl
							label={ __( 'Vertical align', 'co-authors-plus' ) }
							value={ verticalAlign }
							options={ [
								{
									value: '',
									label: __( 'Default', 'co-authors-plus' ),
								},
								{
									value: 'baseline',
									label: __( 'Baseline', 'co-authors-plus' ),
								},
								{
									value: 'bottom',
									label: __( 'Bottom', 'co-authors-plus' ),
								},
								{
									value: 'middle',
									label: __( 'Middle', 'co-authors-plus' ),
								},
								{
									value: 'sub',
									label: __( 'Sub', 'co-authors-plus' ),
								},
								{
									value: 'super',
									label: __( 'Super', 'co-authors-plus' ),
								},
								{
									value: 'text-bottom',
									label: __(
										'Text Bottom',
										'co-authors-plus'
									),
								},
								{
									value: 'text-top',
									label: __( 'Text Top', 'co-authors-plus' ),
								},
								{
									value: 'top',
									label: __( 'Top', 'co-authors-plus' ),
								},
							] }
							onChange={ ( value ) => {
								setAttributes( {
									verticalAlign:
										'' === value ? undefined : value,
								} );
							} }
							help={ __(
								'Vertical alignment defaults to bottom in the block layout and middle in the inline layout.',
								'co-authors-plus'
							) }
						/>
					</PanelBody>
				) : null }
			</InspectorControls>
		</>
	);
}
