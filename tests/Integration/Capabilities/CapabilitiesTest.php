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
	 * @covers ::filter_user_has_cap
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
	 * Checks that a co-author (not the primary post author) can trash/delete a
	 * published post they are credited on, mirroring the existing edit_post
	 * parity. Before the fix, only edit_others_posts was granted, so the
	 * "Move to Trash" action never appeared for co-authors.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1029
	 *
	 * @covers ::filter_user_has_cap
	 */
	public function test_current_user_can_delete_published_post_they_coauthor(): void {
		global $coauthors_plus;

		// Backing up current user.
		$current_user = get_current_user_id();

		$admin_user = $this->factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'admin-delete-1',
			)
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $admin_user->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		wp_set_current_user( $this->author1->ID );

		// Author cannot delete someone else's post by default.
		$this->assertFalse( current_user_can( 'delete_post', $post_id ) );

		// Append (not replace) so the admin stays the primary post_author and
		// author1 is a secondary co-author, not swapped in as the sole author.
		$coauthors_plus->add_coauthors( $post_id, array( $this->author1->user_login ), true );
		$this->assertSame( $admin_user->ID, (int) get_post( $post_id )->post_author, 'Precondition: admin must remain the primary author.' );

		// Author can delete once they're a (secondary) coauthor.
		$this->assertTrue( current_user_can( 'delete_post', $post_id ) );

		// Restore original user from backup.
		wp_set_current_user( $current_user );
	}

	/**
	 * A coauthor whose role lacks delete_published_posts (e.g. contributor)
	 * must not gain the ability to delete a *published* post just by being
	 * credited on it — only delete_others_posts is granted, matching how
	 * edit_published_posts is left to the user's own role for editing.
	 *
	 * @covers ::filter_user_has_cap
	 */
	public function test_contributor_coauthor_cannot_delete_published_post(): void {
		global $coauthors_plus;

		$current_user = get_current_user_id();

		$admin_user = $this->factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'admin-delete-2',
			)
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $admin_user->ID,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$contributor = $this->create_contributor( 'contributor-delete-1' );
		wp_set_current_user( $contributor->ID );

		// Append (not replace) so the admin stays the primary post_author and
		// the contributor is a secondary co-author, not swapped in as sole author.
		$coauthors_plus->add_coauthors( $post_id, array( $contributor->user_login ), true );
		$this->assertSame( $admin_user->ID, (int) get_post( $post_id )->post_author, 'Precondition: admin must remain the primary author.' );

		$this->assertFalse( current_user_can( 'delete_post', $post_id ) );

		wp_set_current_user( $current_user );
	}
}
