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

namespace Automattic\CoAuthorsPlus\Tests\Integration;

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
}
