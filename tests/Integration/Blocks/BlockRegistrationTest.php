<?php
/**
 * Tests for registering the blocks with or without the build directory.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Blocks;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Blocks;
use WP_Block_Type_Registry;

/**
 * @coversDefaultClass \CoAuthors\Blocks
 */
class BlockRegistrationTest extends TestCase {

	/**
	 * Holds in a checkout either way. Without build/, initializing the blocks
	 * again must not call register_block_type(), which would fail the test with
	 * an unexpected _doing_it_wrong() notice.
	 *
	 * @covers ::initialize_blocks
	 */
	public function test_blocks_are_registered_only_when_build_exists(): void {
		$build_exists = is_dir( dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks' );

		if ( ! $build_exists ) {
			Blocks::initialize_blocks();
		}

		$this->assertSame( $build_exists, WP_Block_Type_Registry::get_instance()->is_registered( 'co-authors-plus/coauthors' ) );
	}
}
