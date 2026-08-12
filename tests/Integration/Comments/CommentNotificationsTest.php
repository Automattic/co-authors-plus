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
	 * @return array
	 */
	private function capture_recipients( ?WP_User $post_author = null, array $extra_coauthor_logins = array(), array $comment_args = array() ): array {
		global $coauthors_plus;

		$post_author = $post_author ? $post_author : $this->create_author( 'notif_author' );
		$post        = $this->create_post( $post_author );

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
	}

	/**
	 * The comment author is not notified for their own comment on their own post.
	 */
	public function test_comment_author_is_not_notified_for_their_own_comment(): void {
		$author = $this->create_author( 'notif_own_author' );

		$result = $this->capture_recipients(
			$author,
			array(),
			array(
				'user_id'             => $author->ID,
				'comment_author'      => $author->display_name,
				'comment_author_email' => $author->user_email,
			)
		);

		$this->assertNotContains( $author->user_email, $result['sent_to'], 'Author must not be sent their own comment notification.' );
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
}
