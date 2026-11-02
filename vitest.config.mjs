import { transformWithOxc } from 'vite';
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	plugins: [
		{
			// Our sources and tests write JSX in .js files (webpack's Babel
			// handles that), but Vite only parses JSX in .jsx files.
			name: 'js-as-jsx',
			transform( code, id ) {
				if ( /\/src\/.*\.js$/.test( id ) ) {
					return transformWithOxc( code, id, { lang: 'jsx' } );
				}
			},
		},
	],
	test: {
		environment: 'jsdom',
		include: [ 'src/**/*.test.js' ],
		setupFiles: [ './vitest.setup.mjs' ],
	},
} );
