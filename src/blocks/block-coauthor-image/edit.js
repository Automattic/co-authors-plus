/**
 * Co-Author Feature Image
 */

import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	store as blockEditorStore,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- Border support relies on this experimental hook; there is no stable equivalent yet.
	__experimentalUseBorderProps as useBorderProps,
	InspectorControls,
	BlockControls,
	BlockAlignmentToolbar,
} from '@wordpress/block-editor';
import {
	TextControl,
	PanelBody,
	ToggleControl,
	SelectControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import DimensionControls from './dimension-controls';
import PlaceholderImage from '../components/placeholder-image';
import {
	getAvailableSizeSlug,
	getMediaSrc,
	getMediaDimensions,
	getPlaceholderImageDimensions,
} from './utils';

import classnames from 'classnames';

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update block attributes.
 * @param {Object}   props.context       Block context provided by the parent Co-Authors block.
 * @param {string}   props.clientId      Block client ID.
 *
 * @return {Element} Element to render.
 */
export default function Edit( {
	attributes,
	setAttributes,
	context,
	clientId,
} ) {
	const {
		aspectRatio,
		height,
		isLink,
		rel,
		scale,
		sizeSlug,
		verticalAlign,
		width,
		align,
	} = attributes;

	// Author
	const authorPlaceholder = useSelect(
		( select ) => select( 'co-authors-plus/blocks' ).getAuthorPlaceholder(),
		[]
	);
	const author = context[ 'co-authors-plus/author' ] || authorPlaceholder;
	const layout = context[ 'co-authors-plus/layout' ] || '';

	// Media
	const media = useSelect(
		( select ) =>
			0 !== author.featured_media &&
			select( coreStore ).getMedia( author.featured_media, {
				context: 'view',
			} ),
		[ author.featured_media ]
	);

	// Image Sizes and Dimensions
	const { imageSizes, imageDimensions } = useSelect(
		( select ) => select( blockEditorStore ).getSettings(),
		[]
	);
	const imageSizeOptions = imageSizes.map( ( { name, slug } ) => ( {
		value: slug,
		label: name,
	} ) );
	const availableSizeSlug = getAvailableSizeSlug(
		media,
		imageDimensions,
		sizeSlug
	);
	const dimensions = getMediaDimensions(
		media,
		imageDimensions,
		availableSizeSlug
	);
	const placeholderDimensions = media
		? {}
		: getPlaceholderImageDimensions( imageDimensions, availableSizeSlug );

	// Border
	const borderProps = useBorderProps( attributes );

	// Don't placehold feature images for real authors with no image.
	// Do placehold them in author archive contexts.
	const panic = 0 !== author.id && false === media;

	// Called unconditionally (rules of hooks); only the figure below consumes it.
	const blockProps = useBlockProps( {
		className: classnames( {
			[ `align${ align }` ]: ! layout && align && 'none' !== align,
		} ),
	} );

	return (
		<>
			<DimensionControls
				clientId={ clientId }
				attributes={ attributes }
				setAttributes={ setAttributes }
				imageSizeOptions={ imageSizeOptions }
			/>
			{ '' === layout ? (
				<BlockControls>
					<BlockAlignmentToolbar
						value={ align }
						onChange={ ( nextAlign ) => {
							setAttributes( { align: nextAlign } );
						} }
						controls={ [
							'none',
							'left',
							'center',
							'right',
							'wide',
							'full',
						] }
					/>
				</BlockControls>
			) : null }
			{ panic ? null : (
				<figure { ...blockProps }>
					{ media ? (
						<img
							alt={ __(
								'Author featured image',
								'co-authors-plus'
							) }
							className={ borderProps.className }
							src={ getMediaSrc( media, availableSizeSlug ) }
							style={ {
								width: ! width && height ? 'auto' : width,
								height: ! height && width ? 'auto' : height,
								aspectRatio,
								objectFit: scale,
								verticalAlign,
								...borderProps.style,
							} }
							width={ dimensions.width }
							height={ dimensions.height }
						/>
					) : (
						<PlaceholderImage
							className={ borderProps.className }
							dimensions={ placeholderDimensions }
							style={ {
								width: ! width && height ? 'auto' : width,
								height: ! height && width ? 'auto' : height,
								aspectRatio,
								objectFit: scale,
								verticalAlign,
								...borderProps.style,
							} }
						/>
					) }
				</figure>
			) }
			<InspectorControls>
				<PanelBody title={ __( 'Image Settings', 'co-authors-plus' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Make featured image a link to author archive.',
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
