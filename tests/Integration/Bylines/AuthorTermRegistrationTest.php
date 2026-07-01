<?php
/**
 * Tests for creating author terms on user registration.
 *
 * Covers CoAuthors_Plus::create_author_term_on_user_registration().
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Bylines;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Tests that an author term is created proactively when a user is registered
 * or added to a blog, preventing stale-object-cache issues (issue #1314).
 *
 * @covers \CoAuthors_Plus::create_author_term_on_user_registration
 */
class AuthorTermRegistrationTest extends TestCase {

	/**
	 * The user_register hook should create an author term for a user
	 * with edit_posts capability.
	 */
	public function test_author_term_created_on_user_register_for_author_role(): void {
		global $coauthors_plus;

		$user = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'newauthorreg',
				'display_name' => 'New Author',
			)
		);

		// The user_register hook fires during user creation, so the term
		// should already exist.
		$term = $coauthors_plus->get_author_term( $user );

		$this->assertNotEmpty( $term, 'An author term should be created when a user with edit_posts is registered.' );
		$this->assertEquals( 'cap-newauthorreg', $term->slug, 'The term slug should use the cap- prefix.' );

		// The new author should be searchable immediately.
		$results = $coauthors_plus->search_authors( 'New Author' );
		$this->assertArrayHasKey( $user->user_login, $results );
	}

	/**
	 * The user_register hook should NOT create an author term for a subscriber.
	 */
	public function test_author_term_not_created_for_subscriber(): void {
		global $coauthors_plus;

		$user = $this->factory()->user->create_and_get(
			array(
				'role'         => 'subscriber',
				'user_login'   => 'subonlyreg',
			)
		);

		$term = $coauthors_plus->get_author_term( $user );

		$this->assertEmpty( $term, 'A subscriber should not get an author term.' );
	}

	/**
	 * Calling create_author_term_on_user_registration twice should be
	 * idempotent — no duplicate term or error.
	 */
	public function test_create_author_term_is_idempotent(): void {
		global $coauthors_plus;

		$user = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'idempotentreg',
			)
		);

		// Remove the auto-created term to start fresh.
		$existing_term = $coauthors_plus->get_author_term( $user );
		if ( $existing_term ) {
			wp_delete_term( $existing_term->term_id, $coauthors_plus->coauthor_taxonomy );
			wp_cache_delete( 'author-term-' . $user->user_nicename, 'co-authors-plus' );
		}
		$this->assertEmpty( $coauthors_plus->get_author_term( $user ) );

		// First call creates the term.
		$coauthors_plus->create_author_term_on_user_registration( $user->ID );
		$term1 = $coauthors_plus->get_author_term( $user );
		$this->assertNotEmpty( $term1, 'First call should create the term.' );

		// Second call should silently skip.
		$coauthors_plus->create_author_term_on_user_registration( $user->ID );
		$term2 = $coauthors_plus->get_author_term( $user );
		$this->assertEquals( $term1->term_id, $term2->term_id, 'Second call should not create a duplicate term.' );
	}

	/**
	 * The add_user_to_blog hook should create an author term for a user
	 * who has the required capability on that blog.
	 */
	public function test_author_term_created_on_add_user_to_blog(): void {
		global $coauthors_plus;

		$user = $this->factory()->user->create_and_get(
			array(
				'user_login' => 'blogaddedreg',
			)
		);

		// Assign the user the author role so user_can() returns true
		// when the callback checks for edit_posts capability.
		$user->add_role( 'author' );

		// Fire the add_user_to_blog action as if the user was just
		// added to a site with the author role.
		do_action( 'add_user_to_blog', $user->ID, 'author', get_current_blog_id() );

		$term = $coauthors_plus->get_author_term( $user );

		$this->assertNotEmpty( $term, 'An author term should be created via add_user_to_blog.' );
		$this->assertEquals( 'cap-blogaddedreg', $term->slug );
	}

	/**
	 * Passing a non-existent user ID should not cause errors.
	 */
	public function test_invalid_user_id_returns_void(): void {
		global $coauthors_plus;

		$result = $coauthors_plus->create_author_term_on_user_registration( 999999 );

		$this->assertNull( $result );
	}
}
