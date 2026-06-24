<?php
/**
 * Tests for the get_the_coauthor_meta() template tag.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers ::get_the_coauthor_meta()
 */
class GetTheCoauthorMetaTest extends TestCase {

	private $author1;
	private $post;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->factory()->user->create_and_get(
			array(
				'role'       => 'author',
				'user_login' => 'author1',
			)
		);
		$this->post = $this->factory()->post->create_and_get(
			array(
				'post_author'  => $this->author1->ID,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'post',
			)
		);
	}

	/**
	 * Checks co-authors meta.
	 */
	public function test_get_the_coauthor_meta(): void {

		global $post;

		// Backing up global post.
		$post_backup = $post;

		$this->assertEmpty( get_the_coauthor_meta( '' ) );

		update_user_meta( $this->author1->ID, 'meta_key', 'meta_value' );

		$this->assertEmpty( get_the_coauthor_meta( 'meta_key' ) );

		$post = $this->post;
		$meta = get_the_coauthor_meta( 'meta_key' );

		$this->assertEquals( 'meta_value', $meta[ $this->author1->ID ] );

		// Restore global post from backup.
		$post = $post_backup;
	}
}
