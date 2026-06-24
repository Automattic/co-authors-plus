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
}
