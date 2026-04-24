<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration\Blocks;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Blocks\Block_CoAuthor_Image;
use WP_Block;
use WP_Block_Type_Registry;

/**
 * Regression tests for the href escaping in Block_CoAuthor_Image::render_block.
 *
 * The block previously used the raw $author['link'] value for the anchor's
 * href attribute, relying solely on render_attributes' esc_attr handling.
 * Because render_attributes does not strip unsafe URL schemes, a filter
 * rewriting the REST "link" field could surface a javascript: URL. The
 * block now uses the esc_url'd copy of the link.
 *
 * @covers \CoAuthors\Blocks\Block_CoAuthor_Image::render_block
 */
class BlockCoAuthorImageTest extends TestCase {

	const BLOCK_NAME = 'co-authors-plus/image';

	/** @var int */
	private $attachment_id;

	public function set_up() {
		parent::set_up();

		$this->attachment_id = $this->factory()->attachment->create_upload_object(
			dirname( __DIR__ ) . '/fixtures/dummy-attachment.png'
		);

		// The render callback requires a registered block type so that
		// WP_Block's constructor can map available_context onto $block->context.
		// If the plugin's build dir is not present (dev checkouts without a JS
		// build), register a minimal block type here so the test still runs.
		$registry = WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( self::BLOCK_NAME ) ) {
			register_block_type(
				self::BLOCK_NAME,
				array(
					'uses_context'    => array( 'co-authors-plus/author', 'co-authors-plus/layout' ),
					'render_callback' => array( Block_CoAuthor_Image::class, 'render_block' ),
				)
			);
		}
	}

	/**
	 * Build a WP_Block instance whose context carries an author payload with
	 * the given REST "link" value.
	 */
	private function make_block_for_link( string $link, bool $is_link = true ): WP_Block {
		$available_context = array(
			'co-authors-plus/author' => array(
				'id'             => 1,
				'display_name'   => 'Example Author',
				'link'           => $link,
				'featured_media' => $this->attachment_id,
			),
			'co-authors-plus/layout' => 'default',
		);

		return new WP_Block(
			array(
				'blockName'    => self::BLOCK_NAME,
				'attrs'        => array( 'isLink' => $is_link ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			$available_context
		);
	}

	/**
	 * A javascript: URL in the REST "link" field must not reach the rendered
	 * href attribute. esc_url returns an empty string for unsafe schemes, so
	 * the rendered anchor should carry no javascript: scheme at all.
	 */
	public function test_javascript_link_is_stripped_from_href(): void {
		$block  = $this->make_block_for_link( 'javascript:alert(1)' );
		$output = Block_CoAuthor_Image::render_block( array( 'isLink' => true ), '', $block );

		$this->assertStringNotContainsString( 'javascript:', $output );
		$this->assertStringNotContainsString( 'href="javascript', $output );
	}

	/**
	 * A legitimate http(s) link should survive escaping and appear in the
	 * rendered anchor's href.
	 */
	public function test_valid_http_link_survives_escaping(): void {
		$link   = 'https://example.com/author/example-author/';
		$block  = $this->make_block_for_link( $link );
		$output = Block_CoAuthor_Image::render_block( array( 'isLink' => true ), '', $block );

		$this->assertStringContainsString( 'href="' . esc_url( $link ) . '"', $output );
	}

	/**
	 * When isLink is false, no wrapping anchor should be rendered and the
	 * href bug cannot surface at all.
	 */
	public function test_no_anchor_is_rendered_when_is_link_is_false(): void {
		$block  = $this->make_block_for_link( 'https://example.com/author/example-author/', false );
		$output = Block_CoAuthor_Image::render_block( array( 'isLink' => false ), '', $block );

		$this->assertStringNotContainsString( '<a ', $output );
		$this->assertStringNotContainsString( 'href=', $output );
	}
}
