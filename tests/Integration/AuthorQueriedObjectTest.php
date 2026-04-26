<?php
/**
 * Test Co-Authors Plus' modifications of author queries
 */

namespace Automattic\CoAuthorsPlus\Tests\Integration;

class AuthorQueriedObjectTest extends TestCase {

	/**
	 * On author pages, the queried object should only be set
	 * to a user that's not a member of the blog if they
	 * have at least one published post. This matches core behavior.
	 *
	 * @see https://core.trac.wordpress.org/changeset/27290
	 *
	 * @group ms-required
	 */
	public function test__author_queried_object_fix(): void {

		global $wp_rewrite, $coauthors_plus;

		/**
		 * Set up
		 */
		$author1 = $this->factory()->user->create( array( 'user_login' => 'msauthor1' ) );
		$author2 = $this->factory()->user->create( array( 'user_login' => 'msauthor2' ) );
		$blog2   = $this->factory()->blog->create( array( 'user_id' => $author1 ) );

		switch_to_blog( $blog2 );
		$wp_rewrite->init();

		$blog2_post1 = $this->factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => rand_str(),
				'post_title'   => rand_str(),
				'post_author'  => $author1,
			)
		);

		/**
		 * Author 1 is an author on the blog
		 */
		$this->go_to( get_author_posts_url( $author1 ) );
		$this->assertQueryTrue( 'is_author', 'is_archive' );

		// Add the user to the blog
		add_user_to_blog( $blog2, $author2, 'author' );

		/**
		 * Author 2 is now on the blog, but not yet published
		 */
		$this->go_to( get_author_posts_url( $author2 ) );
		$this->assertQueryTrue( 'is_author', 'is_archive' );

		// Add the user as an author on the original post
		$author2_obj = get_user_by( 'id', $author2 );
		$coauthors_plus->add_coauthors( $blog2_post1, array( $author2_obj->user_login ), true );

		/**
		 * Author 2 is now on the blog, and published
		 */
		$this->go_to( get_author_posts_url( $author2 ) );
		$this->assertQueryTrue( 'is_author', 'is_archive' );

		// Remove the user from the blog
		remove_user_from_blog( $author2, $blog2 );

		/**
		 * Author 2 was removed from the blog, but still a published author
		 */
		$this->go_to( get_author_posts_url( $author2 ) );
		$this->assertQueryTrue( 'is_author', 'is_archive' );

		// Delete the user from the network
		wpmu_delete_user( $author2 );

		/**
		 * Author 2 is no more
		 */
		$this->go_to( get_author_posts_url( $author2 ) );
		$this->assertEquals( false, get_user_by( 'id', $author2 ) );

		restore_current_blog();
	}

	/**
	 * On author pages, when paginated,
	 * if page number is outside the range, throws 404.
	 *
	 * @group ms-required
	 */
	public function test__author_non_existent_page_throws_404(): void {
		global $wp_rewrite;

		/**
		 * Set up
		 */
		$author = $this->factory()->user->create( array( 'user_login' => 'author' ) );
		$blog   = $this->factory()->blog->create( array( 'user_id' => $author ) );

		switch_to_blog( $blog );
		$wp_rewrite->init();

		/**
		* Author non-existent page throws 404
		*/
		$non_existent_page = 1000;
		$this->go_to( get_author_posts_url( $author ) . 'page/' . $non_existent_page );
		$this->assertQueryTrue( 'is_404' );

		/**
		* Author existent page loads
		*/
		$this->go_to( get_author_posts_url( $author ) );
		$this->assertQueryTrue( 'is_archive', 'is_author' );
	}

	/**
	 * On guest-author pages, conflicting query flags such as is_category must be
	 * cleared even when unexpected query vars arrive alongside the author URL.
	 *
	 * Visiting /author/guest/?cat=1 (e.g. from a vulnerability scanner) causes
	 * WordPress to set is_category=true simultaneously with is_author=true.
	 * fix_author_page() must reset these flags to prevent PHP warnings from core
	 * functions like single_term_title() that read queried_object->name / ->term_id.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/1109
	 */
	public function test__guest_author_page_with_cat_query_var_does_not_set_is_category(): void {
		global $coauthors_plus, $wp_query;

		// Create a guest author.
		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'user_login'   => 'test-guest-1109',
				'display_name' => 'Test Guest 1109',
			)
		);
		$guest_author    = $coauthors_plus->guest_authors->get_guest_author_by( 'id', $guest_author_id );

		$this->assertNotFalse( $guest_author, 'Guest author should exist.' );

		// Directly configure $wp_query to reproduce the conflicting state that
		// arises when a guest author URL is accessed with ?cat=1. WordPress parses
		// the pretty-permalink path (/author/slug/) and sets is_author=true, but
		// also processes the ?cat query var and sets is_category=true. Using go_to()
		// with ?cat=1 does not reliably reproduce this in all environments (notably
		// multisite), so we inject the state manually.
		$wp_query->is_author   = true;
		$wp_query->is_archive  = true;
		$wp_query->is_category = true;

		set_query_var( 'author_name', $guest_author->user_nicename );

		// Trigger fix_author_page() — the method hooked to posts_selection that
		// resolves the queried_object for guest authors.
		$coauthors_plus->fix_author_page( '' );

		// is_author and is_archive must remain true.
		$this->assertTrue( is_author(), 'is_author() must remain true after fix_author_page() runs.' );
		$this->assertTrue( is_archive(), 'is_archive() must remain true after fix_author_page() runs.' );

		// Conflicting flags must be cleared — this was the source of the PHP warnings.
		$this->assertFalse( is_category(), 'is_category() must be false on a guest author page.' );
		$this->assertFalse( is_tag(), 'is_tag() must be false on a guest author page.' );
		$this->assertFalse( is_tax(), 'is_tax() must be false on a guest author page.' );
	}
}
