import { describe, expect, it } from 'vitest';
import { existsSync, readdirSync, readFileSync } from 'fs';
import { join } from 'path';

const blocksDir = join( __dirname, '../blocks' );
const blockNames = readdirSync( blocksDir ).filter( ( name ) =>
	existsSync( join( blocksDir, name, 'block.json' ) )
);

describe( 'block.json supports', () => {
	it( 'finds the plugin blocks', () => {
		expect( blockNames ).toHaveLength( 5 );
	} );

	// Without this flag, a block inside a Query Loop forces a full page reload
	// and turns off client-side navigation.
	it.each( blockNames )( '%s supports client-side navigation', ( name ) => {
		const metadata = JSON.parse(
			readFileSync( join( blocksDir, name, 'block.json' ), 'utf8' )
		);

		expect( metadata.supports.interactivity.clientNavigation ).toBe( true );
	} );
} );
