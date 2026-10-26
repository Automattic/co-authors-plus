<?php
/**
 * Tests for CoAuthors_Plus::coauthors_update_post().
 *
 * @package CoAuthors
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Tests the save_post action that persists co-authors when a post is saved.
 *
 * @covers \CoAuthors_Plus::coauthors_update_post
 */
class CoauthorsUpdatePostTest extends TestCase {

	/**
	 * @var int
	 */
	private $admin1;

	/**
	 * @var int
	 */
	private $author1;

	public function set_up() {
		parent::set_up();

		$this->admin1  = $this->factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'admin1',
			)
		);
		$this->author1 = $this->factory()->user->create(
			array(
				'role'       => 'author',
				'user_login' => 'author1',
			)
		);
	}

	public function tear_down(): void {
		unset( $_REQUEST['coauthors-nonce'], $_POST['coauthors'], $_POST['coauthors-nonce'] );
		parent::tear_down();
	}

	/**
	 * Bypass coauthors_update_post() when post type is not allowed.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_update_post
	 */
	public function test_coauthors_update_post_when_post_type_is_attachment(): void {

		global $coauthors_plus;

		$this->assertEquals(
			10,
			has_action(
				'save_post',
				array(
					$coauthors_plus,
					'coauthors_update_post',
				)
			)
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1,
				'post_type'   => 'attachment',
			)
		);

		$post   = get_post( $post_id );
		$return = $coauthors_plus->coauthors_update_post( $post_id, $post );

		$this->assertNull( $return );
	}

	/**
	 * Checks coauthors when current user can set authors.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_update_post
	 */
	public function test_coauthors_update_post_when_current_user_can_set_authors(): void {

		global $coauthors_plus;

		wp_set_current_user( $this->admin1 );

		$admin1  = get_user_by( 'id', $this->admin1 );
		$author1 = get_user_by( 'id', $this->author1 );

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->admin1,
			)
		);

		$post = get_post( $post_id );

		$nonce                       = wp_create_nonce( 'coauthors-edit' );
		$_POST['coauthors-nonce']    = $nonce;
		$_REQUEST['coauthors-nonce'] = $nonce;
		$_POST['coauthors']          = array(
			$admin1->user_nicename,
			$author1->user_nicename,
		);

		$coauthors_plus->coauthors_update_post( $post_id, $post );

		$coauthors = get_coauthors( $post_id );

		$this->assertEquals( array( $this->admin1, $this->author1 ), wp_list_pluck( $coauthors, 'ID' ) );
	}

	/**
	 * Coauthors should be empty if post does not have any author terms
	 * and current user can not set authors for the post.
	 *
	 * @see https://github.com/Automattic/Co-Authors-Plus/issues/198
	 *
	 * @covers \CoAuthors_Plus::coauthors_update_post
	 */
	public function test_coauthors_update_post_when_post_has_not_author_terms(): void {

		global $coauthors_plus;

		$post_id = $this->factory()->post->create();
		$post    = get_post( $post_id );

		$coauthors_plus->coauthors_update_post( $post_id, $post );

		$coauthors = get_coauthors( $post_id );

		$this->assertEmpty( $coauthors );
	}

	/**
	 * Setting co-authors from the classic editor checks the saved post's type.
	 *
	 * @covers \CoAuthors_Plus::coauthors_update_post
	 */
	public function test_coauthors_update_post_checks_the_post_type_of_the_saved_post(): void {
		global $coauthors_plus;

		$pages_only = $this->create_editor( 'pages_only' );
		$pages_only->add_cap( 'edit_others_posts', false );

		$page_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1,
				'post_type'   => 'page',
			)
		);
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->author1,
			)
		);

		wp_set_current_user( $pages_only->ID );

		$nonce                       = wp_create_nonce( 'coauthors-edit' );
		$_POST['coauthors-nonce']    = $nonce;
		$_REQUEST['coauthors-nonce'] = $nonce;
		$_POST['coauthors']          = array(
			$pages_only->user_nicename,
			get_userdata( $this->author1 )->user_nicename,
		);

		$coauthors_plus->coauthors_update_post( $page_id, get_post( $page_id ) );
		$coauthors_plus->coauthors_update_post( $post_id, get_post( $post_id ) );

		$this->assertSame( array( $pages_only->ID, $this->author1 ), wp_list_pluck( get_coauthors( $page_id ), 'ID' ) );
		$this->assertSame( array( $this->author1 ), wp_list_pluck( get_coauthors( $post_id ), 'ID' ) );
	}
}
