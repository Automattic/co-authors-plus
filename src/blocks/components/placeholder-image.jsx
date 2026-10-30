import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Encode SVG
 *
 * @param {string} svgHTML SVG markup.
 * @return {string} URI-encoded SVG.
 */
function encodeSVG( svgHTML ) {
	return (
		encodeURIComponent(
			svgHTML
				// Strip newlines and tabs
				.replace( /[\t\n\r]/gim, '' )
				// Condense multiple spaces
				.replace( /\s\s+/g, ' ' )
		)
			// Encode parenthesis
			.replace( /\(/g, '%28' )
			.replace( /\)/g, '%29' )
	);
}

/**
 * Get Placeholder Src
 *
 * @param {Object} dimensions        Image dimensions.
 * @param {number} dimensions.width  Width in pixels.
 * @param {number} dimensions.height Height in pixels.
 * @return {string} SVG data URI.
 */
function getPlaceholderSrc( { width, height } ) {
	const svg = encodeSVG(
		`<svg width="${ width }" height="${ height }" viewBox="0 0 ${ width } ${ width }" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
			<rect width="${ width }" height="${ width }" fill="#eeeeee"></rect>
			<path stroke="black" vector-effect="non-scaling-stroke" d="M ${ width } ${ width } 0 0" />
		</svg>`
	);
	return `data:image/svg+xml;charset=UTF-8,${ svg }`;
}

/**
 * Placeholder Image
 *
 * @param {Object} props            Component props.
 * @param {Object} props.dimensions Image width and height.
 * @param {Object} props.style      Inline styles.
 * @param {string} props.className  Class name.
 * @return {Element} Element to render.
 */
export default function PlaceholderImage( { dimensions, style, className } ) {
	const src = useMemo(
		() => getPlaceholderSrc( dimensions ),
		[ dimensions ]
	);

	return (
		<img
			alt={ __( 'Placeholder image' ) }
			className={ className }
			src={ src }
			style={ style }
			width={ dimensions.width }
			height={ dimensions.height }
		/>
	);
}
