<?php
/**
 * Tests for the is_coauthor_for_post() template tag.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers ::is_coauthor_for_post()
 */
class IsCoauthorForPostTest extends TestCase {

	/**
	 * Checks whether a non-existent user is a coauthor of a post.
	 */
	public function test_return_false_when_user_is_not_valid(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );
		$user = new \WP_User();

		$this->assertFalse( is_coauthor_for_post( 1234, $post->ID ), 'User ID should not exist.' );
		$this->assertFalse( is_coauthor_for_post( 0, $post->ID ), 'User ID 0 should not be valid.' );
		$this->assertFalse( is_coauthor_for_post( $user, $post->ID ), 'User_login should not be set.' );
	}

	/**
	 * Checks whether a user is a coauthor of a non-existent post.
	 */
	public function test_return_false_when_post_does_not_exist(): void {
		$author = $this->create_author();
		$post   = $this->create_post( $author );

		$this->assertFalse( is_coauthor_for_post( $author->ID, '1234' ) );
	}

	/**
	 * Checks whether a user is a coauthor of a non-existent post.
	 */
	public function test_return_false_when_post_is_not_provided_and_global_post_is_not_set(): void {
		$author = $this->create_author();
		unset( $GLOBALS['post'] );

		$this->assertFalse( is_coauthor_for_post( $author->ID ) );
	}

	/**
	 * Checks whether user is a coauthor of the post when user is set in either way,
	 * as user_id or user object but he/she is not coauthor of the post.
	 */
	public function test_is_coauthor_for_post_when_user_numeric_or_user_login_set_but_not_coauthor(): void {
		$author = $this->create_author();
		$editor = $this->create_editor();
		$post   = $this->create_post( $author );

		$this->assertFalse( is_coauthor_for_post( $editor->ID, $post->ID ) );
		$this->assertFalse( is_coauthor_for_post( $editor, $post->ID ) );
	}

	/**
	 * Checks whether user is a coauthor of the post.
	 */
	public function test_is_coauthor_for_post_when_user_is_coauthor(): void {
		$author = $this->create_author();
		$editor = $this->create_editor();
		$post   = $this->create_post( $author );

		global $coauthors_plus;

		// Checking with specific post and user_id as well as user object.
		$this->assertTrue( is_coauthor_for_post( $author->ID, $post->ID ) );
		$this->assertTrue( is_coauthor_for_post( $author, $post->ID ) );

		$coauthors_plus->add_coauthors( $post->ID, array( $editor->user_login ), true );

		$this->assertTrue( is_coauthor_for_post( $editor->ID, $post->ID ) );
		$this->assertTrue( is_coauthor_for_post( $editor, $post->ID ) );

		// Now checking with global post and user_id as well as user object.
		$GLOBALS['post'] = $post;

		$this->assertTrue( is_coauthor_for_post( $author->ID ) );
		$this->assertTrue( is_coauthor_for_post( $author ) );

		$this->assertTrue( is_coauthor_for_post( $editor->ID ) );
		$this->assertTrue( is_coauthor_for_post( $editor ) );
	}

	/**
	 * Creates a post bylined to a new guest author.
	 *
	 * @param string $slug           Guest author slug.
	 * @param string $linked_account Login of the user to link, if any.
	 * @return array{0: object, 1: int} The guest author, and the post ID.
	 */
	private function create_guest_author_post( string $slug, string $linked_account = '' ): array {
		$guest_id = $this->_cap->guest_authors->create(
			array(
				'display_name'   => $slug,
				'user_login'     => $slug,
				'linked_account' => $linked_account,
			)
		);
		$post_id  = $this->create_post( $this->create_editor( 'owner-of-' . $slug ) )->ID;
		$this->_cap->add_coauthors( $post_id, array( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id )->user_nicename ) );

		return array( $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_id ), $post_id );
	}

	/**
	 * A user ID or WP_User only matches a guest author through its linked account.
	 */
	public function test_a_user_is_not_a_guest_author_unless_linked(): void {
		list( , $post_id ) = $this->create_guest_author_post( 'penname' );
		$user              = $this->create_author( 'penname' );

		$this->assertFalse( is_coauthor_for_post( $user->ID, $post_id ), 'User ID' );
		$this->assertFalse( is_coauthor_for_post( (string) $user->ID, $post_id ), 'Numeric string user ID' );
		$this->assertFalse( is_coauthor_for_post( $user, $post_id ), 'WP_User' );
	}

	public function test_a_user_is_not_a_guest_author_linked_to_someone_else(): void {
		$linked            = $this->create_author( 'linkeduser' );
		list( , $post_id ) = $this->create_guest_author_post( 'penname', 'linkeduser' );
		$user              = $this->create_author( 'penname' );

		$this->assertFalse( is_coauthor_for_post( $user->ID, $post_id ) );
		$this->assertTrue( is_coauthor_for_post( $linked->ID, $post_id ) );
	}

	public function test_a_guest_author_object_is_a_coauthor_of_its_own_post(): void {
		list( $guest, $post_id ) = $this->create_guest_author_post( 'penname' );

		$this->assertTrue( is_coauthor_for_post( $guest, $post_id ) );
	}

	public function test_a_co_author_object_with_a_login_still_matches_a_guest_author(): void {
		list( , $post_id ) = $this->create_guest_author_post( 'penname' );

		$this->assertTrue( is_coauthor_for_post( (object) array( 'user_login' => 'penname' ), $post_id ) );
	}

	public function test_a_linked_user_with_the_same_slug_is_a_coauthor(): void {
		$user              = $this->create_author( 'samepair' );
		list( , $post_id ) = $this->create_guest_author_post( 'samepair', 'samepair' );

		$this->assertTrue( is_coauthor_for_post( $user->ID, $post_id ) );
		$this->assertTrue( is_coauthor_for_post( $user, $post_id ) );
	}

	public function test_a_linked_user_with_a_different_slug_is_a_coauthor(): void {
		$user              = $this->create_author( 'difflogin' );
		list( , $post_id ) = $this->create_guest_author_post( 'diffslug', 'difflogin' );

		$this->assertTrue( is_coauthor_for_post( $user->ID, $post_id ) );
		$this->assertTrue( is_coauthor_for_post( $user, $post_id ) );
	}

	public function test_an_ordinary_user_coauthor_is_a_coauthor(): void {
		$first   = $this->create_author( 'first' );
		$second  = $this->create_author( 'second' );
		$post_id = $this->create_post( $first )->ID;
		$this->_cap->add_coauthors( $post_id, array( 'first', 'second' ) );

		$this->assertTrue( is_coauthor_for_post( $second->ID, $post_id ) );
		$this->assertTrue( is_coauthor_for_post( $second, $post_id ) );
	}

	/**
	 * Logins are compared as strings, so '1e3' is not '1000'.
	 */
	public function test_a_numeric_looking_login_does_not_match_a_different_linked_account(): void {
		$this->create_author( '1000' );
		list( , $post_id ) = $this->create_guest_author_post( 'penname', '1000' );
		$user              = $this->create_author( '1e3' );

		$this->assertFalse( is_coauthor_for_post( $user->ID, $post_id ) );
	}

	public function test_a_numeric_looking_login_does_not_match_a_different_user(): void {
		$first   = $this->create_author( '1000' );
		$post_id = $this->create_post( $first )->ID;
		$this->_cap->add_coauthors( $post_id, array( '1000' ) );
		$user = $this->create_author( '1e3' );

		$this->assertFalse( is_coauthor_for_post( $user->ID, $post_id ) );
	}
}
