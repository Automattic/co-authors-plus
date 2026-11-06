<?php
/**
 * Tests for comment moderation recipients.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Comments;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_User;

/**
 * Tests that moderation emails only reach co-authors who can moderate the comment.
 *
 * @covers ::cap_filter_comment_moderation_email_recipients
 */
class CommentModerationRecipientsTest extends TestCase {

	/**
	 * Holds a comment on a post with the given byline, and returns the moderation recipients.
	 *
	 * @param WP_User  $post_author     Author of the post, and first co-author.
	 * @param string[] $coauthor_logins Other co-authors.
	 * @return string[]
	 */
	private function get_recipients( WP_User $post_author, array $coauthor_logins ): array {
		$post = $this->create_post( $post_author );
		$this->_cap->add_coauthors( $post->ID, array_merge( array( $post_author->user_login ), $coauthor_logins ) );

		$comment_id = $this->factory()->comment->create(
			array(
				'comment_post_ID'  => $post->ID,
				'comment_approved' => 0,
				'user_id'          => 0,
			)
		);

		return apply_filters( 'comment_moderation_recipients', array( 'admin@example.com' ), $comment_id );
	}

	/**
	 * Creates a guest author with an email address, optionally linked to a user.
	 *
	 * @param string       $login  Guest author slug.
	 * @param string       $email  Guest author email address.
	 * @param WP_User|null $linked User to link the guest author to.
	 * @return string The guest author's slug.
	 */
	private function create_guest_author_with_email( string $login, string $email, ?WP_User $linked = null ): string {
		$guest_id = $this->create_guest_author( $login );
		$this->assertIsInt( $guest_id );

		update_post_meta( $guest_id, $this->_cap->guest_authors->get_post_meta_key( 'user_email' ), $email );

		if ( $linked ) {
			update_post_meta( $guest_id, $this->_cap->guest_authors->get_post_meta_key( 'linked_account' ), $linked->user_login );
		}

		return $login;
	}

	/**
	 * An Author co-author can moderate comments on a published post, as the post author could.
	 */
	public function test_coauthor_who_can_edit_the_comment_is_included(): void {
		$author = $this->create_author( 'mod_author' );
		$second = $this->create_author( 'mod_second' );

		$recipients = $this->get_recipients( $author, array( $second->user_login ) );

		$this->assertContains( $second->user_email, $recipients, 'An Author co-author can moderate the comment.' );
		$this->assertContains( 'admin@example.com', $recipients, 'Existing recipients must be kept.' );
	}

	/**
	 * A demoted co-author can't moderate the comment.
	 */
	public function test_demoted_coauthor_is_excluded(): void {
		$author = $this->create_author( 'mod_keeps_role' );
		$former = $this->create_author( 'mod_demoted' );
		$former->set_role( 'subscriber' );

		$recipients = $this->get_recipients( $author, array( $former->user_login ) );

		$this->assertContains( $author->user_email, $recipients, 'A co-author who can moderate must be included.' );
		$this->assertNotContains( $former->user_email, $recipients, 'A subscriber can not moderate the comment.' );
	}

	/**
	 * A Contributor can't edit a published post, so can't moderate its comments.
	 */
	public function test_contributor_coauthor_is_excluded_on_a_published_post(): void {
		$author      = $this->create_author( 'mod_contributor_owner' );
		$contributor = $this->create_contributor( 'mod_contributor' );

		$recipients = $this->get_recipients( $author, array( $contributor->user_login ) );

		$this->assertNotContains( $contributor->user_email, $recipients, 'A Contributor can not moderate comments on a published post.' );
	}

	/**
	 * A guest author with no account can't moderate anything.
	 */
	public function test_unlinked_guest_author_is_excluded(): void {
		$author = $this->create_author( 'mod_guest_owner' );
		$guest  = $this->create_guest_author_with_email( 'mod_unlinked', 'unlinked@example.com' );

		$recipients = $this->get_recipients( $author, array( $guest ) );

		$this->assertContains( $author->user_email, $recipients, 'A co-author who can moderate must be included.' );
		$this->assertNotContains( 'unlinked@example.com', $recipients, 'A guest author with no account can not moderate the comment.' );
	}

	/**
	 * A linked guest author can moderate as far as their account can.
	 */
	public function test_linked_guest_author_follows_their_account(): void {
		$author  = $this->create_author( 'mod_linked_owner' );
		$capable = $this->create_guest_author_with_email( 'mod_linked', 'linked@example.com', $this->create_author( 'mod_linked_account' ) );

		$demoted_account = $this->create_author( 'mod_revoked_account' );
		$demoted_account->set_role( 'subscriber' );
		$revoked = $this->create_guest_author_with_email( 'mod_revoked', 'revoked@example.com', $demoted_account );

		$recipients = $this->get_recipients( $author, array( $capable, $revoked ) );

		$this->assertContains( 'linked@example.com', $recipients, 'The linked account can moderate the comment.' );
		$this->assertNotContains( 'revoked@example.com', $recipients, 'The linked account can not moderate the comment.' );
	}
}
