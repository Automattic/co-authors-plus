<?php
/**
 * Tests for comment notification recipients.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Comments;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_User;

/**
 * Tests that comment notifications fire the core `comment_notification_recipients`
 * filter so plugins and themes can add or remove email addresses.
 *
 * @covers ::wp_notify_postauthor
 */
class CommentNotificationsTest extends TestCase {

	/**
	 * Creates a post with the given co-authors, a reader comment, and returns the
	 * filtered recipient list plus the emails actually passed to wp_mail().
	 *
	 * No real email is ever sent; the pre_wp_mail filter captures the recipients.
	 *
	 * @param WP_User|null $post_author       Author to assign the post to. Defaults to a fresh author.
	 * @param string[]     $extra_coauthor_logins Additional co-author login slugs (users or guest authors).
	 * @param array        $comment_args      Comment args passed to factory.
	 * @param string       $post_status       Status of the post.
	 * @return array
	 */
	private function capture_recipients( ?WP_User $post_author = null, array $extra_coauthor_logins = array(), array $comment_args = array(), string $post_status = 'publish' ): array {
		global $coauthors_plus;

		$post_author = $post_author ? $post_author : $this->create_author( 'notif_author' );
		$post        = $this->create_post( $post_author );

		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => $post_status,
			)
		);

		$coauthor_logins = array_merge( array( $post_author->user_login ), $extra_coauthor_logins );
		$coauthors_plus->add_coauthors( $post->ID, $coauthor_logins );

		$recipients = array();
		add_filter(
			'comment_notification_recipients',
			function ( $emails ) use ( &$recipients ) {
				$recipients = $emails;
				return $emails;
			}
		);

		$sent_to = array();
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) use ( &$sent_to ) {
				$to        = $atts['to'];
				$sent_to[] = is_array( $to ) ? implode( ', ', $to ) : $to;
				return true;
			},
			10,
			2
		);

		$comment_args = array_merge(
			array(
				'comment_post_ID'     => $post->ID,
				'comment_author'      => 'Some Reader',
				'comment_author_email' => 'reader@example.com',
				'comment_content'     => 'A brand new comment.',
				'user_id'             => 0,
				'comment_author_IP'   => '127.0.0.1',
				'comment_approved'    => 1,
			),
			$comment_args
		);
		$comment_id   = $this->factory()->comment->create( $comment_args );

		wp_notify_postauthor( $comment_id );

		return array(
			'recipients' => $recipients,
			'sent_to'    => $sent_to,
			'comment_id' => $comment_id,
		);
	}

	/**
	 * Multiple co-authors are all notified, and the recipient filter sees them all.
	 */
	public function test_comment_notification_recipients_includes_all_coauthors(): void {
		$author_a = $this->create_author( 'notif_author_a' );
		$author_b = $this->create_author( 'notif_author_b' );

		$result = $this->capture_recipients( $author_a, array( $author_b->user_login ) );

		$this->assertContains( $author_a->user_email, $result['recipients'], 'First co-author email must be in the recipient list.' );
		$this->assertContains( $author_b->user_email, $result['recipients'], 'Second co-author email must be in the recipient list.' );
		$this->assertContains( $author_b->user_email, $result['sent_to'], 'Second co-author must receive the notification.' );
	}

	/**
	 * A guest author's email is included in the recipients.
	 */
	public function test_comment_notification_recipients_includes_guest_author(): void {
		$guest_id = $this->create_guest_author( 'notif_guest' );
		$this->assertIsInt( $guest_id );

		// Give the guest author an email before fetching the author object.
		update_post_meta(
			$guest_id,
			$this->_cap->guest_authors->get_post_meta_key( 'user_email' ),
			'guest@example.com'
		);

		$guest_author = $this->_cap->get_coauthor_by( 'user_login', 'notif_guest' );
		$this->assertIsObject( $guest_author );

		$result = $this->capture_recipients( null, array( $guest_author->user_login ) );

		$this->assertContains( 'guest@example.com', $result['recipients'], 'Guest author email must be in the recipient list.' );
		$this->assertContains( 'guest@example.com', $result['sent_to'], 'Guest author must receive the notification.' );
	}

	/**
	 * The comment author is not notified for their own comment on their own post.
	 */
	public function test_comment_author_is_not_notified_for_their_own_comment(): void {
		$author = $this->create_author( 'notif_own_author' );
		$other  = $this->create_author( 'notif_own_other' );

		$result = $this->capture_recipients(
			$author,
			array( $other->user_login ),
			array(
				'user_id'             => $author->ID,
				'comment_author'      => $author->display_name,
				'comment_author_email' => $author->user_email,
			)
		);

		$this->assertNotContains( $author->user_email, $result['sent_to'], 'Author must not be sent their own comment notification.' );
		$this->assertContains( $other->user_email, $result['sent_to'], 'Other co-author must still be notified.' );
	}

	/**
	 * A theme can add extra recipients through the filter.
	 */
	public function test_comment_notification_recipients_can_add_addresses(): void {
		$extra_email = 'extra@example.com';

		add_filter(
			'comment_notification_recipients',
			function ( $emails ) use ( $extra_email ) {
				$emails[] = $extra_email;
				return $emails;
			}
		);

		$result = $this->capture_recipients();

		$this->assertContains( $extra_email, $result['recipients'], 'Extra address added by the filter must be in the recipient list.' );
		$this->assertContains( $extra_email, $result['sent_to'], 'Extra address must receive the notification.' );
	}

	/**
	 * Setting comment_notification_notify_author to true re-includes the author.
	 */
	public function test_notify_author_filter_reincludes_author(): void {
		$author = $this->create_author( 'notif_reinclude' );

		add_filter( 'comment_notification_notify_author', '__return_true' );

		$result = $this->capture_recipients(
			$author,
			array(),
			array(
				'user_id'             => $author->ID,
				'comment_author'      => $author->display_name,
				'comment_author_email' => $author->user_email,
			)
		);

		$this->assertContains( $author->user_email, $result['sent_to'], 'Author must be re-included when notify_author is true.' );
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
	 * A co-author left on the byline after losing their role has no access to the post.
	 */
	public function test_coauthor_without_a_role_is_not_notified(): void {
		$author = $this->create_author( 'notif_keeps_role' );
		$former = $this->create_author( 'notif_lost_role' );
		$former->set_role( '' );

		$result = $this->capture_recipients( $author, array( $former->user_login ) );

		$this->assertContains( $author->user_email, $result['sent_to'], 'A co-author who can read the post must be notified.' );
		$this->assertNotContains( $former->user_email, $result['sent_to'], 'A co-author with no role must not be notified.' );
	}

	/**
	 * A co-author removed from the site is still on the byline, but can't read the post.
	 *
	 * @group ms-required
	 */
	public function test_coauthor_removed_from_the_site_is_not_notified(): void {
		$author = $this->create_author( 'notif_member' );
		$former = $this->create_author( 'notif_removed' );

		remove_user_from_blog( $former->ID, get_current_blog_id() );

		$result = $this->capture_recipients( $author, array( $former->user_login ) );

		$this->assertContains( $author->user_email, $result['sent_to'], 'A site member must be notified.' );
		$this->assertNotContains( $former->user_email, $result['sent_to'], 'A co-author removed from the site must not be notified.' );
	}

	/**
	 * A demoted co-author can't read a private post, so isn't told about its comments.
	 */
	public function test_demoted_coauthor_is_not_notified_on_a_private_post(): void {
		$editor = $this->create_editor( 'notif_private_editor' );
		$former = $this->create_editor( 'notif_private_former' );
		$former->set_role( 'subscriber' );

		$result = $this->capture_recipients( $editor, array( $former->user_login ), array(), 'private' );

		$this->assertContains( $editor->user_email, $result['sent_to'], 'An editor can read the private post.' );
		$this->assertNotContains( $former->user_email, $result['sent_to'], 'A subscriber can not read the private post.' );
	}

	/**
	 * Anyone with the read capability can read a published post, as core treats the post author.
	 */
	public function test_demoted_coauthor_is_notified_on_a_published_post(): void {
		$author = $this->create_author( 'notif_public_author' );
		$former = $this->create_author( 'notif_public_former' );
		$former->set_role( 'subscriber' );

		$result = $this->capture_recipients( $author, array( $former->user_login ) );

		$this->assertContains( $former->user_email, $result['sent_to'], 'A subscriber can read a published post.' );
	}

	/**
	 * On a private post, read_post is what decides: the post author can read it, and
	 * so can another co-author with read_private_posts, but another Author can't.
	 */
	public function test_private_post_notifies_only_coauthors_who_can_read_it(): void {
		$author        = $this->create_author( 'notif_private_owner' );
		$editor        = $this->create_editor( 'notif_private_reader' );
		$second_author = $this->create_author( 'notif_private_second' );

		$result = $this->capture_recipients( $author, array( $editor->user_login, $second_author->user_login ), array(), 'private' );

		$this->assertContains( $author->user_email, $result['sent_to'], 'The post author can read the private post.' );
		$this->assertContains( $editor->user_email, $result['sent_to'], 'An editor can read the private post.' );
		$this->assertNotContains( $second_author->user_email, $result['sent_to'], 'Another Author can not read the private post.' );
	}

	/**
	 * A guest author with no account can only read what's public.
	 */
	public function test_unlinked_guest_author_is_not_notified_on_a_private_post(): void {
		$author = $this->create_author( 'notif_guest_private' );
		$guest  = $this->create_guest_author_with_email( 'notif_unlinked', 'unlinked@example.com' );

		$result = $this->capture_recipients( $author, array( $guest ), array(), 'private' );

		$this->assertContains( $author->user_email, $result['sent_to'], 'The post author can read the private post.' );
		$this->assertNotContains( 'unlinked@example.com', $result['sent_to'], 'A guest author with no account can not read the private post.' );
	}

	/**
	 * A linked guest author can read whatever their account can.
	 */
	public function test_linked_guest_author_is_notified_when_their_account_can_read_the_post(): void {
		$author = $this->create_author( 'notif_linked_owner' );
		$guest  = $this->create_guest_author_with_email( 'notif_linked', 'linked@example.com', $this->create_editor( 'notif_linked_editor' ) );

		$result = $this->capture_recipients( $author, array( $guest ), array(), 'private' );

		$this->assertContains( 'linked@example.com', $result['sent_to'], 'The linked account can read the private post.' );
	}

	/**
	 * A linked guest author loses access along with their account.
	 */
	public function test_linked_guest_author_is_not_notified_when_their_account_lost_access(): void {
		$author  = $this->create_author( 'notif_revoked_owner' );
		$account = $this->create_author( 'notif_revoked_account' );
		$account->set_role( '' );
		$guest = $this->create_guest_author_with_email( 'notif_revoked', 'revoked@example.com', $account );

		$result = $this->capture_recipients( $author, array( $guest ) );

		$this->assertContains( $author->user_email, $result['sent_to'], 'The post author can read the post.' );
		$this->assertNotContains( 'revoked@example.com', $result['sent_to'], 'The linked account has no access to the post.' );
	}

	/**
	 * The post author the function falls back to gets the same check as a co-author.
	 */
	public function test_post_author_fallback_is_not_notified_without_access(): void {
		$author = $this->create_author( 'notif_fallback' );
		$post   = $this->create_post( $author );
		$author->set_role( '' );

		add_filter( 'get_coauthors', '__return_empty_array' );

		$comment_id = $this->factory()->comment->create(
			array(
				'comment_post_ID' => $post->ID,
				'user_id'         => 0,
			)
		);

		$this->assertFalse( wp_notify_postauthor( $comment_id ), 'There must be nobody left to notify.' );
	}

	/**
	 * As in core, comment_notification_notify_author overrides the access check.
	 */
	public function test_notify_author_filter_overrides_the_access_check(): void {
		$author = $this->create_author( 'notif_override_author' );
		$former = $this->create_author( 'notif_override_former' );
		$former->set_role( '' );

		add_filter( 'comment_notification_notify_author', '__return_true' );

		$result = $this->capture_recipients( $author, array( $former->user_login ) );

		$this->assertContains( $former->user_email, $result['sent_to'], 'The filter must re-include the co-author.' );
	}
}
