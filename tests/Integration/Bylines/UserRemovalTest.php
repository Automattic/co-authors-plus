<?php
/**
 * Tests for the co-author clean-up that runs when a user is deleted.
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
}
