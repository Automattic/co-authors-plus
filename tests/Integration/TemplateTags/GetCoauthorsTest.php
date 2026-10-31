<?php
/**
 * Tests for the get_coauthors() template tag.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\TemplateTags;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @covers ::get_coauthors()
 */
class GetCoauthorsTest extends TestCase {

	/**
	 * Checks coauthors when post not exist.
	 */
	public function test_get_coauthors_when_post_not_exists(): void {
		$this->assertEmpty( get_coauthors() );
	}

	/**
	 * Checks coauthors when post exist (not global).
	 */
	public function test_get_coauthors_when_post_exists(): void {
		$author = $this->create_author();
		$editor = $this->create_editor();
		$post   = $this->create_post( $author );

		global $coauthors_plus;

		// Compare single author.
		$this->assertEquals( array( $author->ID ), wp_list_pluck( get_coauthors( $post->ID ), 'ID' ) );

		// Compare multiple authors.
		$coauthors_plus->add_coauthors( $post->ID, array( $editor->user_login ), true );
		$this->assertEquals(
			array(
				$author->ID,
				$editor->ID,
			),
			wp_list_pluck( get_coauthors( $post->ID ), 'ID' )
		);
	}

	/**
	 * Checks coauthors when terms for post not exist.
	 */
	public function test_get_coauthors_when_terms_for_post_not_exists(): void {

		$post_id = $this->factory()->post->create();
		$this->assertEmpty( get_coauthors( $post_id ) );
	}

	/**
	 * Checks coauthors when post not exist but global post does.
	 */
	public function test_get_coauthors_when_global_post_exists(): void {

		global $post;

		// Backing up global post.
		$post_backup = $post;

		$post = $this->factory()->post->create_and_get();

		$this->assertEmpty( get_coauthors() );

		$user_id = $this->factory()->user->create();
		$post    = $this->factory()->post->create_and_get(
			array(
				'post_author' => $user_id,
			)
		);

		$this->assertEquals( array( $user_id ), wp_list_pluck( get_coauthors(), 'ID' ) );

		// Restore global post from backup.
		$post = $post_backup;
	}

	/**
	 * Checks coauthors when post not exist but global post_ID does.
	 */
	public function test_get_coauthors_when_global_post_id_exists(): void {

		global $post_ID;

		// Backing up global post_ID.
		$post_id_backup = $post_ID;

		$post = $this->factory()->post->create_and_get();

		$GLOBALS['post_ID'] = $post->ID;

		$this->assertEmpty( get_coauthors() );

		$user_id = $this->factory()->user->create();
		$post    = $this->factory()->post->create_and_get(
			array(
				'post_author' => $user_id,
			)
		);

		$GLOBALS['post_ID'] = $post->ID;

		$this->assertEquals( array( $user_id ), wp_list_pluck( get_coauthors(), 'ID' ) );

		// Restore global post from backup.
		$post_ID = $post_id_backup;
	}

	/**
	 * Checks that a mapped guest author profile is returned instead of the raw
	 * WP_User when a post has no coauthor terms yet (e.g. created before the
	 * plugin was active) but its post_author has a linked guest author.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/114
	 */
	public function test_get_coauthors_when_terms_missing_but_post_author_has_linked_guest_author(): void {

		global $coauthors_plus;

		$user = $this->create_author( 'mapped-author' );

		$guest_author_id = $coauthors_plus->guest_authors->create_guest_author_from_user_id( $user->ID );
		$this->assertIsInt( $guest_author_id );

		$post_id = $this->factory()->post->create( array( 'post_author' => $user->ID ) );

		// Remove the co-author term CAP auto-assigns on save, simulating a post
		// created before CAP was active (i.e. no coauthor terms at all).
		wp_delete_object_term_relationships( $post_id, $coauthors_plus->coauthor_taxonomy );
		$this->assertFalse( $coauthors_plus->has_author_terms( $post_id ) );

		$coauthors = get_coauthors( $post_id );

		$this->assertCount( 1, $coauthors );
		$this->assertIsGuestAuthorNotWpUser( $coauthors[0] );
		$this->assertEquals( $guest_author_id, $coauthors[0]->ID );
		$this->assertEquals( $user->user_login, $coauthors[0]->linked_account );
	}

	/**
	 * Checks coauthors order.
	 */
	public function test_coauthors_order(): void {

		global $coauthors_plus;

		$author = $this->create_author();
		$editor = $this->create_editor();
		$post_id = $this->factory()->post->create();

		// Checks when no author exist.
		$this->assertEmpty( get_coauthors( $post_id ) );

		// Checks coauthors order.
		$coauthors_plus->add_coauthors( $post_id, array( $author->user_login ), true );
		$coauthors_plus->add_coauthors( $post_id, array( $editor->user_login ), true );

		$expected = array( $author->user_login, $editor->user_login );

		$this->assertEquals( $expected, wp_list_pluck( get_coauthors( $post_id ), 'user_login' ) );

		// Checks coauthors order after modifying.
		$post_id = $this->factory()->post->create();

		$coauthors_plus->add_coauthors( $post_id, array( $editor->user_login ), true );
		$coauthors_plus->add_coauthors( $post_id, array( $author->user_login ), true );

		$expected = array( $editor->user_login, $author->user_login );

		$this->assertEquals( $expected, wp_list_pluck( get_coauthors( $post_id ), 'user_login' ) );
	}
}
