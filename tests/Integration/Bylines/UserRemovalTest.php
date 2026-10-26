<?php
/**
 * Tests for the co-author clean-up that runs when a user is deleted, or is
 * removed from a site on multisite.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class UserRemovalTest extends TestCase {

	public function tear_down() {
		unset( $_POST['reassign_user'] );
		parent::tear_down();
	}

	/**
	 * Get the author term slugs assigned to a post.
	 *
	 * @param int $post_id Post to read.
	 * @return string[]
	 */
	private function get_author_term_slugs( int $post_id ): array {
		return wp_get_post_terms( $post_id, $this->_cap->coauthor_taxonomy, array( 'fields' => 'slugs' ) );
	}

	/**
	 * Deleting a user with a reassignment, as WP-CLI and direct calls do, credits
	 * the reassign user on the deleted user's posts.
	 *
	 * @covers ::delete_user_action
	 */
	public function test_delete_user_reassigns_bylines_to_the_reassign_argument(): void {
		$deleted  = $this->create_author( 'deletedauthor' );
		$other    = $this->create_author( 'otherauthor' );
		$reassign = $this->create_author( 'reassignauthor' );
		$post_id  = $this->create_post( $deleted )->ID;
		$this->_cap->add_coauthors( $post_id, array( $other->user_nicename ), true );

		wp_delete_user( $deleted->ID, $reassign->ID );

		$this->assertPostHasCoAuthors( $post_id, array( $other, $reassign ) );
	}

	/**
	 * The Users screen submits a reassign user even when "Delete all content" is
	 * chosen, so the submitted value must not be credited on posts being deleted.
	 *
	 * @covers ::delete_user_action
	 */
	public function test_delete_user_without_reassign_ignores_submitted_reassign_user(): void {
		$deleted   = $this->create_author( 'deletedauthor' );
		$bystander = $this->create_author( 'bystander' );
		$post_id   = $this->create_post( $deleted )->ID;

		$_POST['reassign_user'] = (string) $bystander->ID;

		wp_delete_user( $deleted->ID );

		$this->assertNotContains( 'cap-bystander', $this->get_author_term_slugs( $post_id ) );
	}

	/**
	 * Deleting a user without reassignment trashes their posts after their term
	 * is deleted. Trashing saves the post, which must not recreate the term.
	 *
	 * @covers ::delete_user_action
	 * @covers ::coauthors_update_post
	 */
	public function test_delete_user_without_reassign_leaves_no_author_term_on_trashed_posts(): void {
		$deleted = $this->create_author( 'deletedauthor' );
		$post_id = $this->create_post( $deleted )->ID;

		// Saving a post without co-authors credits its post_author.
		$this->assertSame( array( 'cap-deletedauthor' ), $this->get_author_term_slugs( $post_id ) );

		wp_delete_user( $deleted->ID );

		$this->assertSame( 'trash', get_post_status( $post_id ) );
		$this->assertSame( array(), $this->get_author_term_slugs( $post_id ) );
		// Neither the prefixed term nor an unprefixed replacement survives.
		$this->assertNull( term_exists( 'cap-deletedauthor', $this->_cap->coauthor_taxonomy ) );
		$this->assertNull( term_exists( 'deletedauthor', $this->_cap->coauthor_taxonomy ) );
	}

	/**
	 * Set up a user who authors a post on the main site and, on a second site,
	 * a post and a linked guest author.
	 *
	 * @param \WP_User $user          The user to set up.
	 * @param \WP_User $site_reassign A second member of the second site.
	 * @return array{blog_id: int, main_post_id: int, site_post_id: int, guest_author_id: int}
	 */
	private function set_up_user_on_second_site( \WP_User $user, \WP_User $site_reassign ): array {
		$main_post_id = $this->create_post( $user )->ID;
		$blog_id      = (int) $this->factory()->blog->create();
		add_user_to_blog( $blog_id, $user->ID, 'author' );
		add_user_to_blog( $blog_id, $site_reassign->ID, 'author' );

		switch_to_blog( $blog_id );
		$site_post_id    = $this->create_post( $user )->ID;
		$guest_author_id = $this->_cap->guest_authors->create_guest_author_from_user_id( $user->ID );
		restore_current_blog();

		$this->assertIsInt( $guest_author_id );

		return compact( 'blog_id', 'main_post_id', 'site_post_id', 'guest_author_id' );
	}

	/**
	 * Removing a user from a site with a reassignment moves their bylines and
	 * deletes their linked guest author on that site, leaving other sites alone.
	 *
	 * @group ms-required
	 *
	 * @covers ::remove_user_from_blog_action
	 */
	public function test_remove_user_from_site_with_reassign_cleans_up_that_site_only(): void {
		$removed  = $this->create_author( 'removedauthor' );
		$reassign = $this->create_author( 'reassignauthor' );
		$setup    = $this->set_up_user_on_second_site( $removed, $reassign );

		remove_user_from_blog( $removed->ID, $setup['blog_id'], $reassign->ID );

		switch_to_blog( $setup['blog_id'] );
		$site_terms   = $this->get_author_term_slugs( $setup['site_post_id'] );
		$guest_author = get_post( $setup['guest_author_id'] );
		restore_current_blog();

		$this->assertSame( array( 'cap-reassignauthor' ), $site_terms );
		$this->assertNull( $guest_author, 'The guest author linked to the removed user should be deleted on that site.' );
		$this->assertSame( array( 'cap-removedauthor' ), $this->get_author_term_slugs( $setup['main_post_id'] ) );
	}

	/**
	 * Removing a user from a site without a reassignment leaves their posts
	 * attributed to them, as WordPress does with post_author.
	 *
	 * @group ms-required
	 *
	 * @covers ::remove_user_from_blog_action
	 */
	public function test_remove_user_from_site_without_reassign_keeps_their_bylines(): void {
		$removed = $this->create_author( 'removedauthor' );
		$member  = $this->create_author( 'memberauthor' );
		$setup   = $this->set_up_user_on_second_site( $removed, $member );

		remove_user_from_blog( $removed->ID, $setup['blog_id'] );

		switch_to_blog( $setup['blog_id'] );
		$site_terms   = $this->get_author_term_slugs( $setup['site_post_id'] );
		$guest_author = get_post( $setup['guest_author_id'] );
		restore_current_blog();

		$this->assertSame( array( 'cap-removedauthor' ), $site_terms );
		$this->assertNotNull( $guest_author );
	}
}
