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
 * Tests that an author term is created proactively when a user is registered,
 * added to a blog, or promoted to a role that can edit posts, preventing
 * stale-object-cache issues (issue #1314).
 *
 * @covers \CoAuthors_Plus::create_author_term_on_user_registration
 */
class AuthorTermRegistrationTest extends TestCase {

	/**
	 * The user_register hook should create an author term for a user
	 * with edit_posts capability.
	 */
	public function test_author_term_created_on_user_register_for_author_role(): void {
		$user = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'newauthorreg',
				'display_name' => 'New Author',
			)
		);

		// The user_register hook fires during user creation, so the term
		// should already exist.
		$term = $this->_cap->get_author_term( $user );

		$this->assertNotEmpty( $term, 'An author term should be created when a user with edit_posts is registered.' );
		$this->assertEquals( 'cap-newauthorreg', $term->slug, 'The term slug should use the cap- prefix.' );

		// The new author should be searchable immediately.
		$results = $this->_cap->search_authors( 'New Author' );
		$this->assertArrayHasKey( $user->user_login, $results );
	}

	/**
	 * The user_register hook should NOT create an author term for a subscriber.
	 */
	public function test_author_term_not_created_for_subscriber(): void {
		$user = $this->factory()->user->create_and_get(
			array(
				'role'         => 'subscriber',
				'user_login'   => 'subonlyreg',
			)
		);

		$term = $this->_cap->get_author_term( $user );

		$this->assertEmpty( $term, 'A subscriber should not get an author term.' );
	}

	/**
	 * Calling create_author_term_on_user_registration twice should be
	 * idempotent — no duplicate term or error.
	 */
	public function test_create_author_term_is_idempotent(): void {
		$user = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'idempotentreg',
			)
		);

		// Remove the auto-created term to start fresh.
		$existing_term = $this->_cap->get_author_term( $user );
		if ( $existing_term ) {
			wp_delete_term( $existing_term->term_id, $this->_cap->coauthor_taxonomy );
			wp_cache_delete( 'author-term-' . $user->user_nicename, 'co-authors-plus' );
		}
		$this->assertEmpty( $this->_cap->get_author_term( $user ) );

		// First call creates the term.
		$this->_cap->create_author_term_on_user_registration( $user->ID );
		$term1 = $this->_cap->get_author_term( $user );
		$this->assertNotEmpty( $term1, 'First call should create the term.' );

		// Second call should silently skip.
		$this->_cap->create_author_term_on_user_registration( $user->ID );
		$term2 = $this->_cap->get_author_term( $user );
		$this->assertEquals( $term1->term_id, $term2->term_id, 'Second call should not create a duplicate term.' );
	}

	/**
	 * A subscriber promoted to a role with edit_posts should get an author term.
	 */
	public function test_author_term_created_on_role_promotion(): void {
		$user = $this->factory()->user->create_and_get(
			array(
				'role'         => 'subscriber',
				'user_login'   => 'promotedreg',
			)
		);

		// No term while the user is a subscriber.
		$this->assertEmpty( $this->_cap->get_author_term( $user ), 'A subscriber should not have an author term.' );

		// Promoting fires set_user_role, which creates the term.
		$user->set_role( 'author' );

		$term = $this->_cap->get_author_term( $user );
		$this->assertNotEmpty( $term, 'Promotion to a role with edit_posts should create an author term.' );
		$this->assertEquals( 'cap-promotedreg', $term->slug );

		// The promoted author should be searchable.
		$results = $this->_cap->search_authors( 'promotedreg' );
		$this->assertArrayHasKey( $user->user_login, $results );
	}

	/**
	 * The lazy back-fill in search_authors() can miss a user on some sites
	 * (issue #1314). A term created proactively at registration must still
	 * make the author searchable even when that back-fill returns nothing.
	 */
	public function test_search_authors_finds_proactively_created_term_when_backfill_misses(): void {
		$user = $this->factory()->user->create_and_get(
			array(
				'role'         => 'author',
				'user_login'   => 'backfillmissreg',
				'display_name' => 'Backfill Miss',
			)
		);

		// The term must already exist, created by the user_register hook.
		$this->assertNotEmpty( $this->_cap->get_author_term( $user ), 'Sanity check: the term should already exist.' );

		// Simulate the reported scenario: the get_users() back-fill query that
		// normally creates terms during search returns no users.
		$force_empty_users = static function ( $query ) {
			$query->set( 'include', array( 0 ) );
		};
		add_filter( 'pre_get_users', $force_empty_users );

		try {
			$results = $this->_cap->search_authors( 'Backfill' );
		} finally {
			remove_filter( 'pre_get_users', $force_empty_users );
		}

		$this->assertArrayHasKey(
			$user->user_login,
			$results,
			'A proactively created term must make the user searchable even when the back-fill user query returns no users.'
		);
	}

	/**
	 * Passing a non-existent user ID should not create a term or cause errors.
	 */
	public function test_invalid_user_id_creates_no_term(): void {
		$this->_cap->create_author_term_on_user_registration( 999999 );

		// A non-existent user cannot have an author term; the callback must
		// simply exit without warnings.
		$term = get_term_by( 'slug', 'cap-999999', $this->_cap->coauthor_taxonomy );
		$this->assertFalse( $term, 'No term should be created for a non-existent user ID.' );
	}
}
