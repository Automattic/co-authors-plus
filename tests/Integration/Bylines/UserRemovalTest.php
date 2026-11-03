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

	/**
	 * Creates a guest author and a post bylined to them, with an Editor as post_author.
	 *
	 * @param string $slug           Guest author slug.
	 * @param string $linked_account Login of the user to link, if any.
	 * @return array{0: int, 1: int} The guest author ID, and the post ID.
	 */
	private function create_guest_author_with_post( string $slug, string $linked_account = '' ): array {
		$guest_id = $this->_cap->guest_authors->create(
			array(
				'display_name'   => $slug,
				'user_login'     => $slug,
				'linked_account' => $linked_account,
			)
		);
		$this->assertIsInt( $guest_id );

		$post_id = $this->factory()->post->create(
			array( 'post_author' => $this->factory()->user->create( array( 'role' => 'editor' ) ) )
		);
		$this->_cap->add_coauthors( $post_id, array( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id )->user_nicename ) );

		return array( $guest_id, $post_id );
	}

	/**
	 * Asserts a guest author, its term and its byline on a post all exist.
	 *
	 * @param int    $guest_id Guest author ID.
	 * @param int    $post_id  Post bylined to the guest author.
	 * @param string $slug     Expected term slug.
	 */
	private function assertGuestAuthorKept( int $guest_id, int $post_id, string $slug ): void {
		$this->assertIsObject( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id, true ) );
		$this->assertInstanceOf( \WP_Term::class, get_term_by( 'slug', $slug, $this->_cap->coauthor_taxonomy ) );
		$this->assertSame( array( $slug ), $this->get_author_term_slugs( $post_id ) );
		$this->assertSame( $guest_id, (int) get_coauthors( $post_id )[0]->ID );
	}

	/**
	 * @dataProvider data_logins_sharing_a_guest_authors_term
	 * @covers ::delete_user_action
	 *
	 * @param string $login Login of the user to delete.
	 */
	public function test_deleting_a_user_keeps_an_unlinked_guest_authors_term( string $login ): void {
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'penname' );
		$user                       = $this->create_subscriber( $login );

		wp_delete_user( $user->ID );

		$this->assertGuestAuthorKept( $guest_id, $post_id, 'cap-penname' );
	}

	/**
	 * @dataProvider data_logins_sharing_a_guest_authors_term
	 * @covers ::delete_user_action
	 *
	 * @param string $login Login of the user to delete.
	 */
	public function test_deleting_a_user_with_reassign_keeps_an_unlinked_guest_authors_term( string $login ): void {
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'penname' );
		$user                       = $this->create_author( $login );
		$reassign                   = $this->create_author( 'reassignauthor' );
		$own_post_id                = $this->create_post( $user )->ID;

		wp_delete_user( $user->ID, $reassign->ID );

		$this->assertGuestAuthorKept( $guest_id, $post_id, 'cap-penname' );
		$this->assertSame( $reassign->ID, (int) get_post( $own_post_id )->post_author );
		$this->assertContains( 'cap-reassignauthor', $this->get_author_term_slugs( $own_post_id ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function data_logins_sharing_a_guest_authors_term(): array {
		return array(
			'same login'   => array( 'penname' ),
			'case differs' => array( 'PenName' ),
		);
	}

	/**
	 * @covers ::delete_user_action
	 */
	public function test_deleting_a_user_keeps_the_term_of_a_guest_author_linked_to_someone_else(): void {
		$this->create_author( 'linkeduser' );
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'penname', 'linkeduser' );
		$user                       = $this->create_subscriber( 'penname' );

		wp_delete_user( $user->ID );

		$this->assertGuestAuthorKept( $guest_id, $post_id, 'cap-penname' );
	}

	/**
	 * A guest author named "Cap Ri" has the nicename cap-ri and the term cap-cap-ri.
	 * Its post_name is cap-cap-ri, until the edit screen saves it as cap-ri.
	 *
	 * @dataProvider data_post_names_with_a_doubled_prefix_term
	 * @covers ::delete_user_action
	 *
	 * @param string $post_name The guest author's post_name.
	 */
	public function test_deleting_a_user_keeps_the_term_of_a_guest_author_with_a_doubled_prefix( string $post_name ): void {
		$guest_id = $this->create_guest_author( 'Cap Ri' );
		$guest    = $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id );
		$this->assertSame( 'cap-cap-ri', $this->_cap->update_author_term( $guest )->slug );
		wp_update_post(
			array(
				'ID'        => $guest_id,
				'post_name' => $post_name,
			)
		);
		$this->_cap->guest_authors->delete_guest_author_cache( $guest_id );

		$post_id = $this->create_post( $this->create_editor() )->ID;
		wp_set_post_terms( $post_id, array( 'cap-cap-ri' ), $this->_cap->coauthor_taxonomy );

		$user = $this->create_subscriber( 'cap-ri' );

		// Lookups by nicename share a guest author cache key with the doubled post_name.
		$this->_cap->get_coauthor_by( 'user_nicename', 'cap-ri' );

		wp_delete_user( $user->ID );

		$this->assertInstanceOf( \WP_Term::class, get_term_by( 'slug', 'cap-cap-ri', $this->_cap->coauthor_taxonomy ) );
		$this->assertSame( array( 'cap-cap-ri' ), $this->get_author_term_slugs( $post_id ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function data_post_names_with_a_doubled_prefix_term(): array {
		return array(
			'after creation' => array( 'cap-cap-ri' ),
			'after saving'   => array( 'cap-ri' ),
		);
	}

	/**
	 * @covers ::delete_user_action
	 */
	public function test_deleting_a_user_still_removes_their_own_term_when_a_guest_author_has_a_similar_slug(): void {
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'penname' );
		$user                       = $this->create_author( 'cap-penname' );
		$term                       = $this->_cap->update_author_term( $user );
		$this->assertSame( 'cap-cap-penname', $term->slug );

		wp_delete_user( $user->ID );

		$this->assertFalse( get_term_by( 'slug', 'cap-cap-penname', $this->_cap->coauthor_taxonomy ) );
		$this->assertGuestAuthorKept( $guest_id, $post_id, 'cap-penname' );
	}

	/**
	 * Only the login matches, so the user has a term of their own.
	 *
	 * @covers ::delete_user_action
	 */
	public function test_deleting_a_user_with_only_the_login_in_common_removes_their_own_term(): void {
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'penname' );
		$user                       = $this->factory()->user->create_and_get(
			array(
				'role'          => 'author',
				'user_login'    => 'penname',
				'user_nicename' => 'someone-else',
			)
		);
		$this->_cap->update_author_term( $user );

		wp_delete_user( $user->ID );

		$this->assertFalse( get_term_by( 'slug', 'cap-someone-else', $this->_cap->coauthor_taxonomy ) );
		$this->assertGuestAuthorKept( $guest_id, $post_id, 'cap-penname' );
	}

	/**
	 * @covers ::delete_user_action
	 */
	public function test_deleting_a_user_leaves_their_trashed_post_on_the_shared_term(): void {
		$this->create_guest_author( 'penname' );
		$user    = $this->create_author( 'penname' );
		$post_id = $this->create_post( $user )->ID;
		$this->assertSame( array( 'cap-penname' ), $this->get_author_term_slugs( $post_id ) );

		wp_delete_user( $user->ID );

		$this->assertSame( 'trash', get_post_status( $post_id ) );
		$this->assertSame( array( 'cap-penname' ), $this->get_author_term_slugs( $post_id ) );
	}

	/**
	 * With guest authors turned off there's no guest author to keep the term for.
	 *
	 * @covers ::delete_user_action
	 */
	public function test_deleting_a_user_removes_the_term_when_guest_authors_are_off(): void {
		$this->create_guest_author( 'penname' );
		$user = $this->create_author( 'penname' );
		$this->_cap->update_author_term( $user );

		add_filter( 'coauthors_guest_authors_enabled', '__return_false' );
		wp_delete_user( $user->ID );
		remove_filter( 'coauthors_guest_authors_enabled', '__return_false' );

		$this->assertFalse( get_term_by( 'slug', 'cap-penname', $this->_cap->coauthor_taxonomy ) );
	}

	/**
	 * @covers ::delete_user_action
	 */
	public function test_deleting_an_unrelated_user_leaves_a_guest_author_alone(): void {
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'penname' );
		$user                       = $this->create_subscriber( 'unrelated' );

		wp_delete_user( $user->ID );

		$this->assertGuestAuthorKept( $guest_id, $post_id, 'cap-penname' );
	}

	/**
	 * @covers ::delete_user_action
	 */
	public function test_deleting_a_user_linked_with_the_same_slug_removes_the_guest_author_and_term(): void {
		$user                       = $this->create_author( 'samepair' );
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'samepair', 'samepair' );

		wp_delete_user( $user->ID );

		$this->assertFalse( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id, true ) );
		$this->assertFalse( get_term_by( 'slug', 'cap-samepair', $this->_cap->coauthor_taxonomy ) );
		$this->assertSame( array(), $this->get_author_term_slugs( $post_id ) );
	}

	/**
	 * @covers ::delete_user_action
	 */
	public function test_deleting_a_user_linked_with_a_different_slug_removes_the_guest_author_and_terms(): void {
		$user = $this->create_author( 'difflogin' );
		$this->_cap->update_author_term( $user );
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'diffslug', 'difflogin' );

		wp_delete_user( $user->ID );

		$this->assertFalse( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id, true ) );
		$this->assertFalse( get_term_by( 'slug', 'cap-difflogin', $this->_cap->coauthor_taxonomy ) );
		$this->assertFalse( get_term_by( 'slug', 'cap-diffslug', $this->_cap->coauthor_taxonomy ) );
		$this->assertSame( array(), $this->get_author_term_slugs( $post_id ) );
	}

	/**
	 * @group ms-required
	 *
	 * @covers ::remove_user_from_blog_action
	 */
	public function test_remove_user_from_site_with_reassign_keeps_an_unlinked_guest_authors_term(): void {
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'penname' );
		$user                       = $this->create_author( 'penname' );
		$reassign                   = $this->create_author( 'reassignauthor' );

		remove_user_from_blog( $user->ID, get_current_blog_id(), $reassign->ID );

		$this->assertGuestAuthorKept( $guest_id, $post_id, 'cap-penname' );
	}

	/**
	 * @group ms-required
	 *
	 * @covers ::remove_user_from_blog_action
	 */
	public function test_remove_user_from_site_without_reassign_keeps_an_unlinked_guest_authors_term(): void {
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'penname' );
		$user                       = $this->create_author( 'penname' );

		remove_user_from_blog( $user->ID, get_current_blog_id() );

		$this->assertGuestAuthorKept( $guest_id, $post_id, 'cap-penname' );
	}

	/**
	 * @group ms-required
	 *
	 * @covers ::delete_user_action
	 */
	public function test_deleting_a_network_user_keeps_an_unlinked_guest_authors_term(): void {
		list( $guest_id, $post_id ) = $this->create_guest_author_with_post( 'penname' );
		$user                       = $this->create_author( 'penname' );

		wpmu_delete_user( $user->ID );

		$this->assertGuestAuthorKept( $guest_id, $post_id, 'cap-penname' );
	}
}
