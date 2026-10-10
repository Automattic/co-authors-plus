/**
 * ESLint flat configuration for Co-Authors Plus.
 *
 * Extends the default configuration shipped with `@wordpress/scripts` and
 * declares the `@wordpress/*` packages that WordPress provides at runtime as
 * core modules. They are externalised at build time by
 * `@wordpress/dependency-extraction-webpack-plugin`, so they are not bundled
 * and several are intentionally absent from `node_modules`. Declaring them as
 * core modules stops `import/no-unresolved` and `import/no-extraneous-dependencies`
 * flagging these legitimate runtime externals.
 *
 * @see https://eslint.org/docs/latest/use/configure/configuration-files
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	{
		// The `js/` directory holds hand-written legacy jQuery admin scripts that
		// are enqueued directly (not built from `src/` by webpack). They predate the
		// block-editor toolchain and are intentionally excluded from this config.
		ignores: [ 'js/**' ],
	},
	...defaultConfig,
	{
		settings: {
			'import/core-modules': [
				'@wordpress/block-editor',
				'@wordpress/blocks',
				'@wordpress/compose',
				'@wordpress/core-data',
				'@wordpress/hooks',
				'@wordpress/plugins',
			],
		},
	},
];
