<?php
/**
 * Tests for assigning co-authors and the resulting post_author value.
 *
 * @package CoAuthors
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Tests that CoAuthors_Plus::add_coauthors() assigns co-authors and keeps the
 * post's post_author field consistent.
 *
 * @covers \CoAuthors_Plus::add_coauthors
 */
class AddCoauthorPostAuthorTest extends TestCase {

	/**
	 * @var int
	 */
	private $author1;

	/**
	 * @var int
	 */
	private $editor1;

	/**
	 * @var int|\WP_Error
	 */
	private $author1_post1;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->factory()->user->create(
			array(
				'role'       => 'author',
				'user_login' => 'author1',
			)
		);
		$this->editor1 = $this->factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'editor2',
			)
		);

		// Authoring a post through wp_insert_post fires save_post, so CoAuthors
		// Plus creates the author1 co-author term for this post.
		$this->author1_post1 = wp_insert_post(
			array(
				'post_author'  => $this->author1,
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_type'    => 'post',
			)
		);
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	/**
	 * Test assigning a Co-Author to a post.
	 *
	 * @covers \CoAuthors_Plus::add_coauthors
	 */
	public function test_add_coauthor_to_post(): void {
		global $coauthors_plus;

		$coauthors = get_coauthors( $this->author1_post1 );
		$this->assertCount( 1, $coauthors );

		// append = true, should preserve order.
		$editor1 = get_user_by( 'id', $this->editor1 );
		$coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login ), true );
		$coauthors = get_coauthors( $this->author1_post1 );
		$this->assertEquals( array( $this->author1, $this->editor1 ), wp_list_pluck( $coauthors, 'ID' ) );

		// append = false, overrides existing authors.
		$coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login ) );
		$coauthors = get_coauthors( $this->author1_post1 );
		$this->assertEquals( array( $this->editor1 ), wp_list_pluck( $coauthors, 'ID' ) );
	}

	/**
	 * When a co-author is assigned to a post, the post author value
	 * should be set appropriately.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/140
	 *
	 * @covers \CoAuthors_Plus::add_coauthors
	 */
	public function test_add_coauthor_updates_post_author(): void {
		global $coauthors_plus;

		// append = true, preserves existing post_author.
		$editor1 = get_user_by( 'id', $this->editor1 );
		$coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login ), true );
		$this->assertEquals( $this->author1, get_post( $this->author1_post1 )->post_author );

		// append = false, overrides existing post_author.
		$coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login ) );
		$this->assertEquals( $this->editor1, get_post( $this->author1_post1 )->post_author );
	}

	/**
	 * The post_author follows the first co-author, even when the previous post
	 * author is still further down the byline, and a later save of the post keeps
	 * it.
	 *
	 * @covers \CoAuthors_Plus::add_coauthors
	 */
	public function test_post_author_moves_to_first_coauthor_when_post_author_stays_in_byline(): void {
		global $coauthors_plus;

		$editor1 = get_user_by( 'id', $this->editor1 );
		$author1 = get_user_by( 'id', $this->author1 );

		$result = $coauthors_plus->add_coauthors( $this->author1_post1, array( $editor1->user_login, $author1->user_login ) );

		$this->assertTrue( $result );
		$this->assertSame( $this->editor1, (int) get_post( $this->author1_post1 )->post_author );

		wp_update_post(
			array(
				'ID'         => $this->author1_post1,
				'post_title' => rand_str(),
			)
		);

		$this->assertSame( $this->editor1, (int) get_post( $this->author1_post1 )->post_author );
	}

	/**
	 * A guest author linked to a WordPress account counts as that account, so
	 * putting it first makes the linked user the post_author.
	 *
	 * @covers \CoAuthors_Plus::add_coauthors
	 */
	public function test_post_author_moves_to_user_linked_to_first_guest_author(): void {
		global $coauthors_plus;

		$guest_author_id = $coauthors_plus->guest_authors->create_guest_author_from_user_id( $this->editor1 );
		$guest_author    = $coauthors_plus->guest_authors->get_guest_author_by( 'id', $guest_author_id );
		$author1         = get_user_by( 'id', $this->author1 );

		$result = $coauthors_plus->add_coauthors( $this->author1_post1, array( $guest_author->user_login, $author1->user_login ) );

		$this->assertTrue( $result );
		$this->assertSame( $this->editor1, (int) get_post( $this->author1_post1 )->post_author );
	}
}
