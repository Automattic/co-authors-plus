<?php
/**
 * Tests for who is allowed to set and edit co-authors.
 *
 * Covers CoAuthors_Plus::current_user_can_set_authors() — via role and via the
 * coauthors_plus_edit_authors filter — and that a co-author gains edit_post
 * capability for posts they are credited on.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Capabilities;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class CapabilitiesTest extends TestCase {

	private $author1;

	private $editor1;

	public function set_up() {
		parent::set_up();

		$this->author1 = $this->create_author( 'author1' );
		$this->editor1 = $this->create_editor( 'editor1' );
	}

	/**
	 * Checks if the current user can set co-authors or not using current screen.
	 *
	 * @covers ::current_user_can_set_authors
	 */
	public function test_current_user_can_set_author(): void {
		global $coauthors_plus;

		$this->assertFalse( $coauthors_plus->current_user_can_set_authors() );

		// Backing up current user.
		$original_user = get_current_user_id();

		// Checks when current user is author.
		wp_set_current_user( $this->author1->ID );

		$this->assertFalse( $coauthors_plus->current_user_can_set_authors() );

		// Checks when current user is editor.
		wp_set_current_user( $this->editor1->ID );

		$this->assertTrue( $coauthors_plus->current_user_can_set_authors() );

		// Checks when current user is admin.
		$admin1 = $this->factory()->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);

		wp_set_current_user( $admin1->ID );

		$this->assertTrue( $coauthors_plus->current_user_can_set_authors() );

		// Restore current user from backup.
		wp_set_current_user( $original_user );
	}

	/**
	 * Checks if the current user can set co-authors or not using coauthors_plus_edit_authors filter.
	 *
	 * @covers ::current_user_can_set_authors
	 */
	public function test_current_user_can_set_authors_using_coauthors_plus_edit_authors_filter(): void {

		global $coauthors_plus;

		// Backing up current user.
		$current_user = get_current_user_id();

		// Checking when current user is subscriber and filter is true/false.
		$this->create_subscriber( 'subscriber_caps' );

		$this->assertFalse( $coauthors_plus->current_user_can_set_authors() );

		add_filter( 'coauthors_plus_edit_authors', '__return_true' );

		$this->assertTrue( $coauthors_plus->current_user_can_set_authors() );

		remove_filter( 'coauthors_plus_edit_authors', '__return_true' );

		// Checks when current user is editor.
		wp_set_current_user( $this->editor1->ID );

		$this->assertTrue( $coauthors_plus->current_user_can_set_authors() );

		add_filter( 'coauthors_plus_edit_authors', '__return_false' );

		$this->assertFalse( $coauthors_plus->current_user_can_set_authors() );

		remove_filter( 'coauthors_plus_edit_authors', '__return_false' );

		// Restore original user from backup.
		wp_set_current_user( $current_user );
	}

	/**
	 * Checks if the current user can edit a post they are set as a coauthor for.
	 *
	 * @covers ::filter_map_meta_cap
	 */
	public function test_current_user_can_edit_post_they_coauthor(): void {
		global $coauthors_plus;

		// Backing up current user.
		$current_user = get_current_user_id();

		// Set up test post.
		$admin_user = $this->factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'admin1',
			)
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $admin_user->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// Checks when current user is author.
		wp_set_current_user( $this->author1->ID );

		// Author cannot edit by default.
		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );

		// Author can edit when coauthor.
		$coauthors_plus->add_coauthors( $post_id, array( $this->author1->user_login ) );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );

		// Editor can edit by default.
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );

		// Restore original user from backup.
		wp_set_current_user( $current_user );
	}

	/**
	 * Creates a post owned by an administrator, so test users are never the post author.
	 *
	 * @param string $status Post status.
	 * @return int Post ID.
	 */
	private function create_admin_post( string $status ): int {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		return $this->factory()->post->create(
			array(
				'post_author' => $admin,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Adds users as co-authors after the post author, so post_author stays the same.
	 *
	 * Otherwise add_coauthors() makes the first co-author the post author, and the
	 * cap check passes as the owner instead of as a co-author.
	 *
	 * @param int        $post_id Post ID.
	 * @param \WP_User[] $users   Users to add.
	 */
	private function add_secondary_coauthors( int $post_id, array $users ): void {
		global $coauthors_plus;

		$post_author = get_post( $post_id )->post_author;

		$coauthors_plus->add_coauthors( $post_id, wp_list_pluck( $users, 'user_login' ), true, 'user_login' );

		$this->assertSame( $post_author, get_post( $post_id )->post_author );
	}

	/**
	 * A co-author needs edit_posts for a draft, like the post author does.
	 *
	 * @covers ::filter_map_meta_cap
	 */
	public function test_contributor_coauthor_can_edit_draft(): void {
		$contributor = $this->create_contributor();
		$post_id     = $this->create_admin_post( 'draft' );

		$this->assertFalse( user_can( $contributor, 'edit_post', $post_id ) );

		$this->add_secondary_coauthors( $post_id, array( $contributor ) );

		$this->assertTrue( user_can( $contributor, 'edit_post', $post_id ) );
		$this->assertTrue( user_can( $contributor, 'read_post', $post_id ) );
	}

	/**
	 * A user whose role has no edit_posts can't edit a draft only because they are credited on it.
	 *
	 * @covers ::filter_map_meta_cap
	 */
	public function test_subscriber_coauthor_cannot_edit_draft(): void {
		$subscriber = $this->create_subscriber();
		$post_id    = $this->create_admin_post( 'draft' );

		$this->add_secondary_coauthors( $post_id, array( $subscriber ) );

		$current_user = get_current_user_id();
		wp_set_current_user( $subscriber->ID );

		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );

		wp_set_current_user( $current_user );
	}

	/**
	 * Status caps still come from the user's own role.
	 *
	 * @covers ::filter_map_meta_cap
	 */
	public function test_coauthor_status_caps_come_from_own_role(): void {
		$contributor  = $this->create_contributor();
		$published_id = $this->create_admin_post( 'publish' );
		$private_id   = $this->create_admin_post( 'private' );

		$this->add_secondary_coauthors( $published_id, array( $contributor, $this->author1 ) );
		$this->add_secondary_coauthors( $private_id, array( $this->author1 ) );

		// Contributors have no edit_published_posts, authors do.
		$this->assertFalse( user_can( $contributor, 'edit_post', $published_id ) );
		$this->assertTrue( user_can( $this->author1, 'edit_post', $published_id ) );

		// Authors have no edit_private_posts.
		$this->assertFalse( user_can( $this->author1, 'edit_post', $private_id ) );
	}

	/**
	 * A co-author can trash or delete a post they're credited on, like the post author can.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1029
	 *
	 * @covers ::filter_map_meta_cap
	 */
	public function test_coauthor_can_delete_post_they_coauthor(): void {
		$contributor  = $this->create_contributor();
		$draft_id     = $this->create_admin_post( 'draft' );
		$published_id = $this->create_admin_post( 'publish' );

		$this->assertFalse( user_can( $contributor, 'delete_post', $draft_id ) );
		$this->assertFalse( user_can( $this->author1, 'delete_post', $published_id ) );

		$this->add_secondary_coauthors( $draft_id, array( $contributor ) );
		$this->add_secondary_coauthors( $published_id, array( $this->author1 ) );

		$this->assertTrue( user_can( $contributor, 'delete_post', $draft_id ) );
		$this->assertTrue( user_can( $this->author1, 'delete_post', $published_id ) );
		$this->assertFalse( user_can( $this->author1, 'delete_others_posts' ) );
	}

	/**
	 * Delete status caps still come from the user's own role, and a role with no
	 * delete_posts can't delete a post only because they are credited on it.
	 *
	 * @covers ::filter_map_meta_cap
	 */
	public function test_coauthor_delete_caps_come_from_own_role(): void {
		$contributor  = $this->create_contributor();
		$subscriber   = $this->create_subscriber();
		$published_id = $this->create_admin_post( 'publish' );
		$private_id   = $this->create_admin_post( 'private' );
		$draft_id     = $this->create_admin_post( 'draft' );

		$this->add_secondary_coauthors( $published_id, array( $contributor ) );
		$this->add_secondary_coauthors( $private_id, array( $this->author1 ) );
		$this->add_secondary_coauthors( $draft_id, array( $subscriber ) );

		// Contributors have no delete_published_posts.
		$this->assertFalse( user_can( $contributor, 'delete_post', $published_id ) );

		// Authors have no delete_private_posts.
		$this->assertFalse( user_can( $this->author1, 'delete_post', $private_id ) );

		// Subscribers have no delete_posts.
		$this->assertFalse( user_can( $subscriber, 'delete_post', $draft_id ) );
	}

	/**
	 * A co-author can save from the classic editor, which sends the stored post_author.
	 *
	 * @covers ::filter_map_meta_cap
	 */
	public function test_coauthor_can_save_post_without_changing_author(): void {
		$current_user = get_current_user_id();
		$post_id      = $this->create_admin_post( 'publish' );
		$post_author  = (int) get_post( $post_id )->post_author;

		$this->add_secondary_coauthors( $post_id, array( $this->author1 ) );

		set_current_screen( 'post' );
		wp_set_current_user( $this->author1->ID );

		$_POST = array(
			'post_ID'     => $post_id,
			'post_author' => $post_author,
			'post_title'  => 'Saved by co-author',
		);

		$this->assertSame( $post_id, edit_post() );
		$this->assertSame( 'Saved by co-author', get_post( $post_id )->post_title );
		$this->assertSame( $post_author, (int) get_post( $post_id )->post_author );

		// Changing the post author to someone else still needs edit_others_posts.
		$_POST['post_author'] = $this->editor1->ID;
		$this->assertFalse( current_user_can( 'edit_others_posts' ) );

		$_POST = array();
		set_current_screen( 'front' );
		wp_set_current_user( $current_user );
	}

	/**
	 * Being a co-author must not give the edit_others_posts primitive cap.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/138
	 *
	 * @covers ::filter_map_meta_cap
	 */
	public function test_coauthor_does_not_get_edit_others_posts(): void {
		global $coauthors_plus, $post;

		$current_user = get_current_user_id();
		$post_id      = $this->create_admin_post( 'publish' );

		$this->add_secondary_coauthors( $post_id, array( $this->author1 ) );

		wp_set_current_user( $this->author1->ID );
		// Like on the edit screen, where the global post is the co-authored post.
		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );
		$this->assertFalse( current_user_can( 'edit_others_posts' ) );
		$this->assertFalse( $coauthors_plus->current_user_can_set_authors() );

		$post = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( $current_user );
	}
}
