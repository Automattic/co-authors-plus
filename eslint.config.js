/**
 * ESLint flat configuration for Co-Authors Plus.
 *
 * Extends the default configuration shipped with `@wordpress/scripts` and layers
 * two project-specific overrides on top.
 *
 * 1. Import resolver. This project is pure JavaScript/JSX (there are no
 *    TypeScript sources), so the `node` resolver is used. The wp-scripts default
 *    selects the TypeScript resolver whenever the `typescript` package is present
 *    in the dependency tree (it is, transitively). However,
 *    `eslint-import-resolver-typescript` is nested deep under `@wordpress/scripts`
 *    and is not resolvable from the hoisted `eslint-module-utils`, which then
 *    falls back to loading the `typescript` compiler itself as the resolver and
 *    fails with "typescript with invalid interface loaded as resolver". Replacing
 *    the resolver with `node` sidesteps that fault entirely.
 *
 *    ESLint deep-merges `settings` across flat-config entries, so the resolver
 *    must be rewritten in place on the default entries rather than appended
 *    (appending would merge `node` alongside the broken `typescript` resolver).
 *
 * 2. Core modules. These `@wordpress/*` packages are provided by WordPress at
 *    runtime and externalised at build time by
 *    `@wordpress/dependency-extraction-webpack-plugin`, so they are not bundled
 *    and several are intentionally absent from `node_modules`. Declaring them as
 *    core modules stops `import/no-unresolved` and `import/no-extraneous-dependencies`
 *    flagging these legitimate runtime externals.
 *
 * @see https://eslint.org/docs/latest/use/configure/configuration-files
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

const nodeResolver = {
	node: {
		extensions: [ '.js', '.jsx', '.json' ],
	},
};

// Replace the TypeScript import resolver with the node resolver wherever the
// default configuration sets it.
const config = defaultConfig.map( ( entry ) => {
	if ( entry?.settings?.[ 'import/resolver' ] ) {
		return {
			...entry,
			settings: {
				...entry.settings,
				'import/resolver': nodeResolver,
			},
		};
	}

	return entry;
} );

module.exports = [
	{
		// The `js/` directory holds hand-written legacy jQuery admin scripts that
		// are enqueued directly (not built from `src/` by webpack). They predate the
		// block-editor toolchain and are intentionally excluded from this config.
		ignores: [ 'js/**' ],
	},
	...config,
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
