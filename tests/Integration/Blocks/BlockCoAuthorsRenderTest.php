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
 * the template renders a second, discarded time.
 *
 * @covers \CoAuthors\Blocks\Block_CoAuthors::render_block
 */
class BlockCoAuthorsRenderTest extends TestCase {

	const BLOCK_NAME  = 'co-authors-plus/coauthors';
	const PROBE_BLOCK = 'cap-test/render-probe';

	/**
	 * One entry per render of the probe block, holding the context it saw.
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

	public function set_up() {
		parent::set_up();

		$this->probe_renders      = array();
		$this->registered_by_test = false;

		// The render callback builds its REST request with
		// WP_REST_Request::from_url(), which can only derive a route when
		// pretty permalinks are on. Core resets the structure to plain for
		// every test, so set it back here or the dispatch gets false.
		$this->set_permalink_structure( '/%postname%/' );

		$registry = WP_Block_Type_Registry::get_instance();

		// The plugin registers this block from its JS build directory, which
		// CI does not produce. Copy the source block.json there for the
		// registration only, so the plugin's own register_block() runs and
		// every test sees the arguments the plugin really passes.
		if ( ! $registry->is_registered( self::BLOCK_NAME ) ) {
			$metadata_file = dirname( COAUTHORS_PLUS_FILE ) . '/build/blocks/block-coauthors/block.json';

			if ( file_exists( $metadata_file ) ) {
				Block_CoAuthors::register_block();
			} else {
				$this->register_from_source_metadata( $metadata_file );
			}

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

		parent::tear_down();
	}

	/**
	 * Run the plugin's register_block() against a copy of the source
	 * block.json, then remove the copy straight away.
	 *
	 * The file is only read during registration, so it lives for a few lines
	 * and the finally block removes it even when registration throws. The
	 * asset fields are dropped because src/ has no built index.js or
	 * index.asset.php for core to register.
	 *
	 * @param string $metadata_file Path the plugin reads block.json from.
	 */
	private function register_from_source_metadata( string $metadata_file ): void {
		$metadata = wp_json_file_decode(
			dirname( COAUTHORS_PLUS_FILE ) . '/src/blocks/block-coauthors/block.json',
			array( 'associative' => true )
		);
		unset( $metadata['editorScript'], $metadata['style'] );

		wp_mkdir_p( dirname( $metadata_file ) );
		file_put_contents( $metadata_file, (string) wp_json_encode( $metadata ) );

		try {
			Block_CoAuthors::register_block();
		} finally {
			unlink( $metadata_file );

			// rmdir only succeeds on empty directories, so a real build is
			// never touched.
			// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- removing empty dirs the test created; WordPress has no helper for this, and the recursive WP_Filesystem::rmdir() would not be safe here.
			@rmdir( dirname( $metadata_file ) );
			@rmdir( dirname( $metadata_file, 2 ) );
			@rmdir( dirname( $metadata_file, 3 ) );
			// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
		}
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
	 * The template's blocks get each author added to the post context the
	 * Co-Authors block sits in, rather than in place of it.
	 */
	public function test_inner_blocks_keep_the_post_context(): void {
		$author = $this->create_author( 'ada' );
		$post   = $this->create_post( $author );

		$this->render_block_with_probe(
			array(
				'postId'   => $post->ID,
				'postType' => 'post',
			)
		);

		$this->assertCount( 1, $this->probe_renders );
		$this->assertSame( $author->ID, $this->probe_renders[0]['co-authors-plus/author']['id'] ?? null );
		$this->assertSame( $post->ID, $this->probe_renders[0]['postId'] ?? null );
		$this->assertSame( 'post', $this->probe_renders[0]['postType'] ?? null );
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
				'uses_context'    => array( 'co-authors-plus/author', 'postId', 'postType' ),
				'render_callback' => static function ( $attributes, $content, $block ) use ( &$renders ): string {
					$renders[] = $block->context;
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
