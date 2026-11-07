<?php
/**
 * Tests for the Co-Authors block's prefix and suffix.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Blocks;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Blocks\Block_CoAuthors;

/**
 * The prefix and suffix are rich text attributes. Core only runs kses on them when
 * the post is saved by a user without unfiltered_html, so they are filtered on output.
 *
 * @covers \CoAuthors\Blocks\Block_CoAuthors::render_prefix
 * @covers \CoAuthors\Blocks\Block_CoAuthors::render_suffix
 */
class BlockCoAuthorsPrefixSuffixTest extends TestCase {

	/**
	 * @dataProvider data_render_methods
	 *
	 * @param string $method Render method.
	 */
	public function test_keeps_formatting_and_strips_scripts( string $method ): void {
		$output = Block_CoAuthors::$method( '<strong>By</strong><script>alert(1)</script>' );

		$this->assertStringContainsString( '<strong>By</strong>', $output );
		$this->assertStringNotContainsString( '<script>', $output );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function data_render_methods(): array {
		return array(
			'prefix' => array( 'render_prefix' ),
			'suffix' => array( 'render_suffix' ),
		);
	}
}
