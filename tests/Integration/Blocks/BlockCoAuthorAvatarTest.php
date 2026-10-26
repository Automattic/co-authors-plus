<?php
/**
 * Tests for the Co-Author Avatar block rendering.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Blocks;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Blocks\Block_CoAuthor_Avatar;
use WP_Block;
use WP_Block_Type_Registry;

/**
 * Covers the markup the avatar block emits for the image itself.
 *
 * The <img> used to be built through a Templating::render_self_closing_element
 * helper. Nothing else called it, so the tag is now written where it is used;
 * these assertions hold the emitted markup steady across that change.
 *
 * @covers \CoAuthors\Blocks\Block_CoAuthor_Avatar::render_block
 */
class BlockCoAuthorAvatarTest extends TestCase {

	const BLOCK_NAME = 'co-authors-plus/avatar';

	public function set_up() {
		parent::set_up();

		// WP_Block only maps available_context onto $block->context for a
		// registered block type, so register a minimal one when the plugin's
		// build directory is absent (dev checkouts without a JS build).
		$registry = WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( self::BLOCK_NAME ) ) {
			register_block_type(
				self::BLOCK_NAME,
				array(
					'uses_context'    => array( 'co-authors-plus/author', 'co-authors-plus/layout' ),
					'render_callback' => array( Block_CoAuthor_Avatar::class, 'render_block' ),
				)
			);
		}
	}

	/**
	 * Render the block for an author, by default with two avatar sizes.
	 *
	 * @param array      $attributes  Block attributes.
	 * @param array|null $avatar_urls Avatar URLs keyed by size, or null for sizes 24 and 48.
	 * @return string
	 */
	private function render( array $attributes = array(), ?array $avatar_urls = null ): string {
		// isLink is always supplied: the block reads it as
		// `'' !== $link && $attributes['isLink'] ?? false`, and ?? binds
		// looser than &&, so a missing key warns rather than defaulting.
		$block = new WP_Block(
			array(
				'blockName'    => self::BLOCK_NAME,
				'attrs'        => array_merge( array( 'isLink' => false ), $attributes ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array(
				'co-authors-plus/author' => array(
					'display_name' => 'Ada Lovelace',
					'link'         => 'https://example.org/author/ada/',
					'avatar_urls'  => $avatar_urls ?? array(
						24 => 'https://example.org/avatar-24.png',
						48 => 'https://example.org/avatar-48.png',
					),
				),
				'co-authors-plus/layout' => 'default',
			)
		);

		return $block->render();
	}

	/**
	 * The image is emitted as a self-closing tag carrying its attributes.
	 */
	public function test_renders_a_self_closing_img_tag(): void {
		$output = $this->render( array( 'size' => 48 ) );

		$this->assertStringContainsString( '<img ', $output );
		$this->assertStringContainsString( '/>', $output );
		$this->assertStringContainsString( 'src="https://example.org/avatar-48.png"', $output );
		$this->assertStringContainsString( 'width="48"', $output );
		$this->assertStringContainsString( 'height="48"', $output );
	}

	/**
	 * Every registered avatar size is offered in the srcset.
	 */
	public function test_builds_a_srcset_from_every_avatar_size(): void {
		$output = $this->render( array( 'size' => 24 ) );

		$this->assertStringContainsString(
			'srcset="https://example.org/avatar-24.png 24w, https://example.org/avatar-48.png 48w"',
			$output
		);
	}

	/**
	 * With isLink set, the image is wrapped in an anchor to the author archive.
	 */
	public function test_wraps_the_image_in_a_link_when_asked(): void {
		$output = $this->render(
			array(
				'size'   => 48,
				'isLink' => true,
			)
		);

		$this->assertMatchesRegularExpression(
			'#<a [^>]*href="https://example\.org/author/ada/"[^>]*><img [^>]*/></a>#',
			$output
		);
	}

	/**
	 * Without isLink there is no anchor at all.
	 */
	public function test_renders_a_bare_image_without_a_link(): void {
		$output = $this->render(
			array(
				'size'   => 48,
				'isLink' => false,
			)
		);

		$this->assertStringNotContainsString( '<a ', $output );
	}

	/**
	 * The image names the author in its alt text, as core's Avatar block does.
	 */
	public function test_gives_the_image_alt_text_naming_the_author(): void {
		$output = $this->render( array( 'size' => 48 ) );

		$this->assertMatchesRegularExpression( '#<img [^>]*alt="Ada Lovelace Avatar"[^>]*/>#', $output );
	}

	/**
	 * A linked image keeps its alt text, which gives the link its accessible name.
	 */
	public function test_keeps_the_alt_text_when_the_image_is_linked(): void {
		$output = $this->render(
			array(
				'size'   => 48,
				'isLink' => true,
			)
		);

		$this->assertMatchesRegularExpression(
			'#<a [^>]*><img [^>]*alt="Ada Lovelace Avatar"[^>]*/></a>#',
			$output
		);
	}

	/**
	 * A saved size that is no longer offered falls back to the next larger one.
	 *
	 * Sites can drop sizes with the rest_avatar_sizes filter, including the
	 * block's default of 24. The image keeps the size chosen in the editor and
	 * scales the larger avatar down.
	 */
	public function test_falls_back_to_the_next_larger_size_when_the_saved_size_is_missing(): void {
		$output = $this->render(
			array( 'size' => 24 ),
			array(
				48 => 'https://example.org/avatar-48.png',
				96 => 'https://example.org/avatar-96.png',
			)
		);

		$this->assertStringContainsString( 'src="https://example.org/avatar-48.png"', $output );
		$this->assertStringContainsString( 'width="24"', $output );
		$this->assertStringContainsString( 'height="24"', $output );
	}

	/**
	 * A saved size larger than every offered size falls back to the largest.
	 */
	public function test_falls_back_to_the_largest_size_when_none_is_large_enough(): void {
		$output = $this->render(
			array( 'size' => 96 ),
			array(
				48 => 'https://example.org/avatar-48.png',
				24 => 'https://example.org/avatar-24.png',
			)
		);

		$this->assertStringContainsString( 'src="https://example.org/avatar-48.png"', $output );
		$this->assertStringContainsString( 'width="96"', $output );
	}

	/**
	 * An author with no avatars renders nothing.
	 */
	public function test_renders_nothing_without_avatar_urls(): void {
		$block = new WP_Block(
			array(
				'blockName'    => self::BLOCK_NAME,
				'attrs'        => array( 'isLink' => false ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array(
				'co-authors-plus/author' => array( 'display_name' => 'Ada Lovelace' ),
			)
		);

		$this->assertSame( '', $block->render() );
	}
}
