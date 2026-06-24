/**
 * Save
 */
import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';
import classnames from 'classnames';

/**
 * Save
 *
 * @param {Object} props            Block props.
 * @param {Object} props.attributes Block attributes.
 *
 * @return {Element} Element to render.
 */
export default function save( { attributes } ) {
	const { textAlign } = attributes;

	const className = classnames( {
		[ `has-text-align-${ textAlign }` ]: textAlign,
	} );

	return (
		<div { ...useBlockProps.save( { className } ) }>
			<InnerBlocks.Content />
		</div>
	);
}
