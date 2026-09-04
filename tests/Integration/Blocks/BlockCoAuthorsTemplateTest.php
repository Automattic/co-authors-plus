<?php
/**
 * Tests for the per-author template rendering in the Co-Authors block.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Blocks;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Blocks\Block_CoAuthors;

/**
 * Covers the three transformations applied to each rendered author.
 *
 * The block renders its inner template once per co-author, then has to make
 * the server output match the JSX the editor produces: line breaks between
 * blocks removed, surrounding whitespace trimmed, and the result wrapped in a
 * per-author div. Those steps used to run through a variadic function
 * composition helper; they are asserted here directly on the output.
 *
 * @covers \CoAuthors\Blocks\Block_CoAuthors::render_coauthors_blocks_with_template
 */
class BlockCoAuthorsTemplateTest extends TestCase {

	/**
	 * A block registered only by this test, to observe the context it receives.
	 */
	const PROBE_BLOCK = 'cap-test/context-probe';

	public function tear_down() {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::PROBE_BLOCK ) ) {
			unregister_block_type( self::PROBE_BLOCK );
		}

		parent::tear_down();
	}

	/**
	 * A parsed block whose inner content carries the line breaks and padding
	 * the render pipeline is expected to strip.
	 *
	 * `core/null` is what get_block_as_template() substitutes for the real
	 * block name, so rendering concatenates the inner content rather than
	 * dispatching to a render callback.
	 *
	 * @return array
	 */
	private function template(): array {
		return array(
			'blockName'    => 'core/null',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array( "\n\t<span>Byline</span>\n" ),
		);
	}

	/**
	 * Each author is wrapped, its line breaks removed and its edges trimmed.
	 */
	public function test_renders_each_author_trimmed_and_wrapped(): void {
		$blocks = Block_CoAuthors::render_coauthors_blocks_with_template(
			$this->template(),
			array( array( 'display_name' => 'Ada Lovelace' ) )
		);

		$this->assertSame(
			array( '<div class="wp-block-co-authors-plus-coauthor"><span>Byline</span></div>' ),
			$blocks
		);
	}

	/**
	 * One rendered block comes back per author, in the order given.
	 */
	public function test_renders_one_block_per_author(): void {
		$blocks = Block_CoAuthors::render_coauthors_blocks_with_template(
			$this->template(),
			array(
				array( 'display_name' => 'Ada Lovelace' ),
				array( 'display_name' => 'Grace Hopper' ),
			)
		);

		$this->assertCount( 2, $blocks );
		$this->assertSame( $blocks[0], $blocks[1] );
	}

	/**
	 * No authors means no blocks, rather than one empty wrapper.
	 */
	public function test_renders_nothing_without_authors(): void {
		$this->assertSame(
			array(),
			Block_CoAuthors::render_coauthors_blocks_with_template( $this->template(), array() )
		);
	}

	/**
	 * The author payload reaches the template as block context.
	 *
	 * This is the first step of the pipeline, and the only part of it that
	 * asserting on the wrapper markup alone would not catch.
	 */
	public function test_passes_the_author_through_as_block_context(): void {
		$seen = array();

		register_block_type(
			self::PROBE_BLOCK,
			array(
				'uses_context'    => array( 'co-authors-plus/author' ),
				'render_callback' => static function ( $attributes, $content, $block ) use ( &$seen ) {
					$seen[] = $block->context['co-authors-plus/author'] ?? null;
					return '';
				},
			)
		);

		$template                 = $this->template();
		$template['innerContent'] = array( null );
		$template['innerBlocks']  = array(
			array(
				'blockName'    => self::PROBE_BLOCK,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
		);

		Block_CoAuthors::render_coauthors_blocks_with_template(
			$template,
			array( array( 'display_name' => 'Ada Lovelace' ) )
		);

		$this->assertSame(
			array( array( 'display_name' => 'Ada Lovelace' ) ),
			$seen
		);
	}
}
