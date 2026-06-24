const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: './src/index.js',
		'blocks/block-coauthor-avatar/index':
			'./src/blocks/block-coauthor-avatar/index.js',
		'blocks/block-coauthor-description/index':
			'./src/blocks/block-coauthor-description/index.js',
		'blocks/block-coauthor-image/index':
			'./src/blocks/block-coauthor-image/index.js',
		'blocks/block-coauthor-name/index':
			'./src/blocks/block-coauthor-name/index.js',
		'blocks/block-coauthors/index': './src/blocks/block-coauthors/index.js',
		'blocks-store/index': './src/blocks-store/index.js',
	},
};
