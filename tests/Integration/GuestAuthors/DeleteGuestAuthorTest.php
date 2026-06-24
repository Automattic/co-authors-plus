<?php
/**
 * Tests for CoAuthors_Guest_Authors::delete(), including the missing-author
 * guard and the various reassign permutations.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class DeleteGuestAuthorTest extends TestCase {

	private $admin1;

	public function set_up() {
		parent::set_up();

		$this->admin1 = $this->factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'admin1',
			)
		);
	}

	/**
	 * Checks delete guest author when he/she does not exist.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_when_guest_author_not_exist(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$response = $guest_author_obj->delete( $this->admin1->ID );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'guest-author-missing', $response->get_error_code() );
	}

	/**
	 * Checks delete guest author without reassign author.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_without_reassign(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$author2           = $this->factory()->user->create_and_get();
		$guest_author_id   = $guest_author_obj->create_guest_author_from_user_id( $author2->ID );
		$guest_author      = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );
		$guest_author_term = $coauthors_plus->get_author_term( $guest_author );

		$response = $guest_author_obj->delete( $guest_author_id );

		$this->assertTrue( $response );
		$this->assertFalse( get_term_by( 'id', $guest_author_term->term_id, $coauthors_plus->coauthor_taxonomy ) );
		$this->assertNull( get_post( $guest_author_id ) );
	}

	/**
	 * Checks delete guest author with reassign author but he/she does not exist.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_with_reassign_author_not_exist(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		// Checks when reassign author is not exist.
		$author2         = $this->factory()->user->create_and_get();
		$guest_author_id = $guest_author_obj->create_guest_author_from_user_id( $author2->ID );

		$response = $guest_author_obj->delete( $guest_author_id, 'test' );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 'reassign-to-missing', $response->get_error_code() );
	}

	/**
	 * Checks delete guest author with reassign author when linked account and author are same user.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_with_reassign_when_linked_account_and_author_are_same_user(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$author2            = $this->factory()->user->create_and_get();
		$guest_author2_id   = $guest_author_obj->create_guest_author_from_user_id( $author2->ID );
		$guest_author2      = $guest_author_obj->get_guest_author_by( 'ID', $guest_author2_id );
		$guest_author2_term = $coauthors_plus->get_author_term( $guest_author2 );

		$response = $guest_author_obj->delete( $guest_author2_id, $guest_author2->linked_account );

		$this->assertTrue( $response );
		$this->assertNotEmpty( get_term_by( 'id', $guest_author2_term->term_id, $coauthors_plus->coauthor_taxonomy ) );
		$this->assertNull( get_post( $guest_author2_id ) );
	}

	/**
	 * Checks delete guest author with reassign author when linked account and author are different user.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_with_reassign_when_linked_account_and_author_are_different_user(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_admin_id = $guest_author_obj->create_guest_author_from_user_id( $this->admin1->ID );
		$guest_admin    = $guest_author_obj->get_guest_author_by( 'ID', $guest_admin_id );

		$author2            = $this->factory()->user->create_and_get();
		$guest_author_id2   = $guest_author_obj->create_guest_author_from_user_id( $author2->ID );
		$guest_author2      = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id2 );
		$guest_author_term2 = $coauthors_plus->get_author_term( $guest_author2 );

		$post = $this->factory()->post->create_and_get(
			array(
				'post_author' => $author2->ID,
			)
		);

		$response = $guest_author_obj->delete( $guest_author_id2, $guest_admin->linked_account );

		// Checks post author, it should be reassigned to new author.
		$this->assertEquals( array( $guest_admin->linked_account ), wp_list_pluck( get_coauthors( $post->ID ), 'linked_account' ) );
		$this->assertTrue( $response );
		$this->assertFalse( get_term_by( 'id', $guest_author_term2->term_id, $coauthors_plus->coauthor_taxonomy ) );
		$this->assertNull( get_post( $guest_author_id2 ) );
	}

	/**
	 * Checks delete guest author with reassign author and without linked account and author is the same user.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_with_reassign_without_linked_account_and_author_is_same_user(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_author_id   = $guest_author_obj->create(
			array(
				'user_login'   => 'guest_author',
				'display_name' => 'guest_author',
			)
		);
		$guest_author      = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );
		$guest_author_term = $coauthors_plus->get_author_term( $guest_author );

		$response = $guest_author_obj->delete( $guest_author_id, $guest_author->user_login );

		$this->assertTrue( $response );
		$this->assertNotEmpty( get_term_by( 'id', $guest_author_term->term_id, $coauthors_plus->coauthor_taxonomy ) );
		$this->assertNull( get_post( $guest_author_id ) );
	}

	/**
	 * Checks delete guest author with reassign author and without linked account and author is other user.
	 *
	 * @covers CoAuthors_Guest_Authors::delete()
	 */
	public function test_delete_with_reassign_without_linked_account_and_author_is_other_user(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_admin_id = $guest_author_obj->create_guest_author_from_user_id( $this->admin1->ID );
		$guest_admin    = $guest_author_obj->get_guest_author_by( 'ID', $guest_admin_id );

		$guest_author_id   = $guest_author_obj->create(
			array(
				'user_login'   => 'guest_author',
				'display_name' => 'guest_author',
			)
		);
		$guest_author      = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id );
		$guest_author_term = $coauthors_plus->get_author_term( $guest_author );

		$response = $guest_author_obj->delete( $guest_author_id, $guest_admin->user_login );

		$this->assertTrue( $response );
		$this->assertFalse( get_term_by( 'id', $guest_author_term->term_id, $coauthors_plus->coauthor_taxonomy ) );
		$this->assertNull( get_post( $guest_author_id ) );
	}
}
