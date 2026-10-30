<?php
/**
 * Tests for the author context blocks receive on author archives.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Blocks;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use CoAuthors\Blocks;
use WP_Block_Type_Registry;

/**
 * On an author archive, blocks that use the `co-authors-plus/author` context
 * get the archive's author added to the context they already have. Anything
 * else in that context, such as the postId and postType a Query Loop post
 * template supplies, has to survive.
 *
 * @covers \CoAuthors\Blocks::provide_author_archive_context
 */
class AuthorArchiveContextTest extends TestCase {

	const PROBE_BLOCK = 'cap-test/archive-context-probe';

	public function set_up() {
		parent::set_up();

		// The author is fetched with WP_REST_Request::from_url(), which can
		// only derive a route when pretty permalinks are on.
		$this->set_permalink_structure( '/%postname%/' );

		if ( ! in_array( 'coauthors/v1', rest_get_server()->get_namespaces(), true ) ) {
			do_action( 'rest_api_init' );
		}

		register_block_type(
			self::PROBE_BLOCK,
			array(
				'uses_context' => array( 'co-authors-plus/author', 'postId', 'postType' ),
			)
		);
	}

	public function tear_down() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::PROBE_BLOCK ) ) {
			unregister_block_type( self::PROBE_BLOCK );
		}

		parent::tear_down();
	}

	/**
	 * The author is added alongside the existing post context, not in place of it.
	 */
	public function test_adds_author_without_dropping_existing_context(): void {
		$author = $this->create_author( 'ada' );
		$post   = $this->create_post( $author );

		$this->go_to( home_url( '/?author_name=' . $author->user_nicename ) );

		$context = Blocks::provide_author_archive_context(
			array(
				'postId'   => $post->ID,
				'postType' => 'post',
			),
			array( 'blockName' => self::PROBE_BLOCK )
		);

		$this->assertSame( $author->ID, $context['co-authors-plus/author']['id'] ?? null );
		$this->assertSame( $post->ID, $context['postId'] ?? null );
		$this->assertSame( 'post', $context['postType'] ?? null );
	}
}
