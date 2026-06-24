import { memo } from '@wordpress/element';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- Block preview relies on this experimental hook; there is no stable equivalent yet.
import { __experimentalUseBlockPreview as useBlockPreview } from '@wordpress/block-editor';

/**
 * CoAuthor Template Block Preview
 *
 * @param {Object}   props                         Component props.
 * @param {Array}    props.blocks                  Blocks to render in the preview.
 * @param {number}   props.blockContextId          The author ID this preview represents.
 * @param {boolean}  props.isHidden                Whether the preview is hidden.
 * @param {Function} props.setActiveBlockContextId Setter for the active block context ID.
 */
function CoAuthorTemplateBlockPreview( {
	blocks,
	blockContextId,
	isHidden,
	setActiveBlockContextId,
} ) {
	const blockPreviewProps = useBlockPreview( {
		blocks,
		props: {
			className: 'wp-block-co-authors-plus-coauthor',
		},
	} );

	const handleOnClick = () => {
		setActiveBlockContextId( blockContextId );
	};

	const style = {
		display: isHidden ? 'none' : undefined,
	};

	return (
		<div
			{ ...blockPreviewProps }
			tabIndex={ 0 }
			role="button"
			onClick={ handleOnClick }
			onKeyUp={ handleOnClick }
			style={ style }
		/>
	);
}

export default memo( CoAuthorTemplateBlockPreview );
