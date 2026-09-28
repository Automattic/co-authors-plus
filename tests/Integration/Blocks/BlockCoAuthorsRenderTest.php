<?php
/**
 * Tests for rendering the Co-Authors block as a registered block type.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Blocks;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Blocks\Block_CoAuthors;
use WP_Block;
use WP_Block_Type_Registry;

/**
 * Covers the block's registration and one full render pass.
 *
 * The render callback builds the per-author template itself, so the block
 * must be registered with skip_inner_blocks. Without it WP_Block::render()
 * walks the inner blocks first, with no author context, and every block in
 * the template renders a second, discarded time. On WordPress 6.9+ that
 * empty-handed pass also makes core dequeue any assets enqueued during it,
 * which can strip stylesheets from the rest of the page.
 *
 * @covers \CoAuthors\Blocks\Block_CoAuthors::render_block
 */
class BlockCoAuthorsRenderTest extends TestCase {

	const BLOCK_NAME  = 'co-authors-plus/coauthors';
	const PROBE_BLOCK = 'cap-test/render-probe';

	/**
	 * One entry per render of the probe block, holding the author context it saw.
	 *
	 * @var array
	 */
	private array $probe_renders = array();

	/**
	 * Whether set_up() registered the block itself, which happens when the
	 * environment has no JS build directory.
	 *
	 * @var bool
	 */
	private bool $registered_by_test = false;

	/**
	 * Whether set_up() wrote a temporary block.json for that registration.
	 *
	 * @var bool
	 */
	private bool $created_metadata = false;

	public function set_up() {
		parent::set_up();

		$this->probe_renders      = array();
		$this->registered_by_test = false;
		$this->created_metadata   = false;

		// The render callback builds its REST request with
		// WP_REST_Request::from_url(), which can only derive a route when
		// pretty permalinks are on. Core resets the structure to plain for
		// every test, so set it back here or the dispatch gets false.
		$this->set_permalink_structure( '/%postname%/' );

		$registry = WP_Block_Type_Registry::get_instance();

		// The plugin registers this block from its JS build directory, which
		// CI does not produce. Write the small block.json the registration
		// reads, so the plugin's own register_block() runs and every test
		// sees the arguments the plugin really passes rather than a copy.
		if ( ! $registry->is_registered( self::BLOCK_NAME ) ) {
			$metadata_file = dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks/block-coauthors/block.json';

			if ( ! file_exists( $metadata_file ) ) {
				wp_mkdir_p( dirname( $metadata_file ) );
				file_put_contents(
					$metadata_file,
					(string) wp_json_encode(
						array(
							'name'        => self::BLOCK_NAME,
							'usesContext' => array( 'postId' ),
							'attributes'  => array(),
						)
					)
				);
				$this->created_metadata = true;
			}

			Block_CoAuthors::register_block();
			$this->registered_by_test = true;
		}

		// The render callback reads authors through the REST API, so the
		// coauthors routes have to be on the server before it dispatches.
		if ( ! in_array( 'coauthors/v1', rest_get_server()->get_namespaces(), true ) ) {
			do_action( 'rest_api_init' );
		}
	}

	public function tear_down() {
		$registry = WP_Block_Type_Registry::get_instance();

		if ( $registry->is_registered( self::PROBE_BLOCK ) ) {
			unregister_block_type( self::PROBE_BLOCK );
		}

		// The registry outlives a single test, so anything this class
		// registered has to go, or the next test mistakes it for the
		// plugin's own registration.
		if ( $this->registered_by_test && $registry->is_registered( self::BLOCK_NAME ) ) {
			unregister_block_type( self::BLOCK_NAME );
		}

		if ( $this->created_metadata && file_exists( dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks/block-coauthors/block.json' ) ) {
			unlink( dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks/block-coauthors/block.json' );
		}

		// Drop the directories the metadata write created. rmdir only
		// succeeds on empty directories, so a real build is never touched.
		@rmdir( dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks/block-coauthors' );
		@rmdir( dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks' );
		@rmdir( dirname( COAUTHORS_PLUS_FILE ) . '/build' );

		parent::tear_down();
	}

	/**
	 * The registration itself is the fix: the block type must carry
	 * skip_inner_blocks so WP_Block::render() never walks the template.
	 */
	public function test_is_registered_with_skip_inner_blocks(): void {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK_NAME );

		$this->assertNotNull( $block_type );
		$this->assertTrue( (bool) $block_type->skip_inner_blocks );
	}

	/**
	 * Each inner block renders once per author, and never on a pass before
	 * the callback supplies author context.
	 */
	public function test_inner_blocks_render_once_per_author(): void {
		$author_1 = $this->create_author( 'ada' );
		$author_2 = $this->create_editor( 'grace' );
		$post     = $this->create_post( $author_1 );

		$this->_cap->add_coauthors( $post->ID, array( $author_1->user_login, $author_2->user_login ), true );

		$output = $this->render_block_with_probe( array( 'postId' => $post->ID ) );

		$this->assertCount( 2, $this->probe_renders );
		$this->assertSame( 2, substr_count( (string) $output, 'data-cap-probe' ) );
	}

	/**
	 * Without a post to read authors from, the block renders nothing and its
	 * inner blocks are not rendered at all.
	 *
	 * Before skip_inner_blocks, the discarded pre-callback pass still reached
	 * the inner blocks once.
	 */
	public function test_inner_blocks_do_not_render_without_post_id(): void {
		$output = $this->render_block_with_probe( array() );

		$this->assertSame( '', $output );
		$this->assertSame( array(), $this->probe_renders );
	}

	/**
	 * Render the registered block with a probe block as its only inner block.
	 *
	 * @param array $context Available context, e.g. postId.
	 * @return string
	 */
	private function render_block_with_probe( array $context ): string {
		$renders = &$this->probe_renders;

		register_block_type(
			self::PROBE_BLOCK,
			array(
				'uses_context'    => array( 'co-authors-plus/author' ),
				'render_callback' => static function ( $attributes, $content, $block ) use ( &$renders ): string {
					$renders[] = $block->context['co-authors-plus/author'] ?? null;
					return '<span data-cap-probe></span>';
				},
			)
		);

		$parsed_block = array(
			'blockName'    => self::BLOCK_NAME,
			'attrs'        => array(
				'layout' => array( 'type' => 'default' ),
			),
			'innerBlocks'  => array(
				array(
					'blockName'    => self::PROBE_BLOCK,
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				),
			),
			'innerHTML'    => '',
			'innerContent' => array( "\n\t", null, "\n\t" ),
		);

		return ( new WP_Block( $parsed_block, $context ) )->render();
	}
}
