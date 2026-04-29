<?php
/**
 * Co-Authors Plus
 *
 * @package           CoAuthors
 * @author            Automattic
 * @copyright         2008-onwards Shared and distributed between Mohammad Jangda, Daniel Bachhuber, Weston Ruter, Automattic, and contributors.
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Co-Authors Plus
 * Plugin URI:        https://wordpress.org/plugins/co-authors-plus/
 * Description:       Allows multiple authors to be assigned to a post. This plugin is an extended version of the Co-Authors plugin developed by Weston Ruter.
 * Version:           4.0.2
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Mohammad Jangda, Daniel Bachhuber, Automattic
 * Author URI:        https://automattic.com
 * Text Domain:       co-authors-plus
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

const COAUTHORS_PLUS_VERSION = '4.0.2';
const COAUTHORS_PLUS_FILE = __FILE__;

require_once __DIR__ . '/template-tags.php';

require_once __DIR__ . '/php/class-coauthors-template-filters.php';
require_once __DIR__ . '/php/class-coauthors-endpoint.php';
require_once __DIR__ . '/php/integrations/amp.php';
require_once __DIR__ . '/php/integrations/yoast.php';
require_once __DIR__ . '/php/integrations/class-wordpress-importer.php';
require_once __DIR__ . '/php/integrations/class-jetpack-subscriber-emails.php';
require_once __DIR__ . '/php/class-coauthors-plus.php';
require_once __DIR__ . '/php/class-coauthors-iterator.php';

// Blocks
require_once __DIR__ . '/php/blocks/class-blocks.php';

// REST APIs for Blocks
require_once __DIR__ . '/php/api/endpoints/class-coauthors-controller.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/php/class-wp-cli.php';
}

global $coauthors_plus;
$coauthors_plus     = new CoAuthors_Plus();
$coauthors_endpoint = new CoAuthors\API\Endpoints( $coauthors_plus );
CoAuthors\Blocks::run();

// Initialize integrations.
( new Automattic\CoAuthorsPlus\Integrations\WordPress_Importer() )->init();
( new Automattic\CoAuthorsPlus\Integrations\Jetpack_Subscriber_Emails() )->init();

if ( ! function_exists( 'wp_notify_postauthor' ) ) :
	/**
	 * Notify a co-author of a comment/trackback/pingback to one of their posts.
	 * This is a modified version of the core function in wp-includes/pluggable.php that
	 * supports notifs to multiple co-authors. Unfortunately, this is the best way to do it :(
	 *
	 * @since 2.6.2
	 *
	 * @param int    $comment_id Comment ID
	 * @param string $comment_type Optional. The comment type either 'comment' (default), 'trackback', or 'pingback'
	 * @return bool False if user email does not exist. True on completion.
	 */
	function wp_notify_postauthor( $comment_id, $comment_type = '' ) {
		$comment   = get_comment( $comment_id );
		$post      = get_post( $comment->comment_post_ID );
		$coauthors = get_coauthors( $post->ID );
		foreach ( $coauthors as $author ) {

			// The comment was left by the co-author
			if ( $comment->user_id == $author->ID ) {
				continue;
			}

			// The co-author moderated a comment on his own post
			if ( $author->ID == get_current_user_id() ) {
				continue;
			}

			// If there's no email to send the comment to
			if ( '' == $author->user_email ) {
				continue;
			}

			$comment_author_domain = @gethostbyaddr( $comment->comment_author_IP );

			// The blogname option is escaped with esc_html on the way into the database in sanitize_option
			// we want to reverse this for the plain text arena of emails.
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

			if ( empty( $comment_type ) ) {
				$comment_type = 'comment';
			}

			if ( 'comment' == $comment_type ) {
				/* translators: Post title. */
				$notify_message = sprintf( __( 'New comment on your post "%s"', 'co-authors-plus' ), $post->post_title ) . "\r\n";
				/* translators: 1: comment author, 2: author IP, 3: author domain */
				$notify_message .= sprintf( __( 'Author : %1$s (IP: %2$s , %3$s)', 'co-authors-plus' ), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
				/* translators: Comment author email address. */
				$notify_message .= sprintf( __( 'Email : %s', 'co-authors-plus' ), $comment->comment_author_email ) . "\r\n";
				/* translators: Comment author URL. */
				$notify_message .= sprintf( __( 'URL    : %s', 'co-authors-plus' ), $comment->comment_author_url ) . "\r\n";
				/* translators: Comment author IP address. */
				$notify_message .= sprintf( __( 'Whois  : https://whois.arin.net/rest/ip/%s', 'co-authors-plus' ), $comment->comment_author_IP ) . "\r\n";
				$notify_message .= __( 'Comment: ', 'co-authors-plus' ) . "\r\n" . $comment->comment_content . "\r\n\r\n";
				$notify_message .= __( 'You can see all comments on this post here: ', 'co-authors-plus' ) . "\r\n";
				/* translators: 1: blog name, 2: post title */
				$subject = sprintf( __( '[%1$s] Comment: "%2$s"', 'co-authors-plus' ), $blogname, $post->post_title );
			} elseif ( 'trackback' == $comment_type ) {
				/* translators: Post title. */
				$notify_message = sprintf( __( 'New trackback on your post "%s"', 'co-authors-plus' ), $post->post_title ) . "\r\n";
				/* translators: 1: comment author, 2: author IP, 3: author domain */
				$notify_message .= sprintf( __( 'Website: %1$s (IP: %2$s , %3$s)', 'co-authors-plus' ), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
				/* translators: Comment author URL. */
				$notify_message .= sprintf( __( 'URL    : %s', 'co-authors-plus' ), $comment->comment_author_url ) . "\r\n";
				$notify_message .= __( 'Excerpt: ', 'co-authors-plus' ) . "\r\n" . $comment->comment_content . "\r\n\r\n";
				$notify_message .= __( 'You can see all trackbacks on this post here: ', 'co-authors-plus' ) . "\r\n";
				/* translators: 1: blog name, 2: post title */
				$subject = sprintf( __( '[%1$s] Trackback: "%2$s"', 'co-authors-plus' ), $blogname, $post->post_title );
			} elseif ( 'pingback' == $comment_type ) {
				/* translators: Post title. */
				$notify_message = sprintf( __( 'New pingback on your post "%s"', 'co-authors-plus' ), $post->post_title ) . "\r\n";
				/* translators: 1: comment author, 2: author IP, 3: author domain */
				$notify_message .= sprintf( __( 'Website: %1$s (IP: %2$s , %3$s)', 'co-authors-plus' ), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
				/* translators: Comment author URL. */
				$notify_message .= sprintf( __( 'URL    : %s', 'co-authors-plus' ), $comment->comment_author_url ) . "\r\n";
				$notify_message .= __( 'Excerpt: ', 'co-authors-plus' ) . "\r\n" . sprintf( '[...] %s [...]', $comment->comment_content ) . "\r\n\r\n";
				$notify_message .= __( 'You can see all pingbacks on this post here: ', 'co-authors-plus' ) . "\r\n";
				/* translators: 1: blog name, 2: post title */
				$subject = sprintf( __( '[%1$s] Pingback: "%2$s"', 'co-authors-plus' ), $blogname, $post->post_title );
			}
			$notify_message .= get_permalink( $comment->comment_post_ID ) . "#comments\r\n\r\n";
			/* translators: Comment URL. */
			$notify_message .= sprintf( __( 'Permalink: %s', 'co-authors-plus' ), get_permalink( $comment->comment_post_ID ) . '#comment-' . $comment_id ) . "\r\n";
			if ( EMPTY_TRASH_DAYS ) {
				/* translators: URL for trashing a comment. */
				$notify_message .= sprintf( __( 'Trash it: %s', 'co-authors-plus' ), admin_url( "comment.php?action=trash&c=$comment_id" ) ) . "\r\n";
			} else {
				/* translators: URL for deleting a comment. */
				$notify_message .= sprintf( __( 'Delete it: %s', 'co-authors-plus' ), admin_url( "comment.php?action=delete&c=$comment_id" ) ) . "\r\n";
			}
			/* translators: URL for marking a comment as spam. */
			$notify_message .= sprintf( __( 'Spam it: %s', 'co-authors-plus' ), admin_url( "comment.php?action=spam&c=$comment_id" ) ) . "\r\n";

			$domain = strtolower( sanitize_text_field( $_SERVER['SERVER_NAME'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			$wp_email = 'wordpress@' . preg_replace( '#^www\.#', '', $domain  );

			if ( '' == $comment->comment_author ) {
				$from = "From: \"$blogname\" <$wp_email>";
				if ( '' != $comment->comment_author_email ) {
					$reply_to = "Reply-To: $comment->comment_author_email";
				}
			} else {
				$from = "From: \"$comment->comment_author\" <$wp_email>";
				if ( '' != $comment->comment_author_email ) {
					$reply_to = "Reply-To: \"$comment->comment_author_email\" <$comment->comment_author_email>";
				}
			}

			$message_headers = "$from\n"
				. 'Content-Type: text/plain; charset="' . get_option( 'blog_charset' ) . "\"\n";

			if ( isset( $reply_to ) ) {
				$message_headers .= $reply_to . "\n";
			}

			$notify_message  = apply_filters( 'comment_notification_text', $notify_message, $comment_id );
			$subject         = apply_filters( 'comment_notification_subject', $subject, $comment_id );
			$message_headers = apply_filters( 'comment_notification_headers', $message_headers, $comment_id );

			@wp_mail( $author->user_email, $subject, $notify_message, $message_headers );
		}

		return true;
	}
endif;

/**
 * Filter array of moderation notification email addresses
 *
 * @param array $recipients
 * @param int   $comment_id
 * @return array
 */
function cap_filter_comment_moderation_email_recipients( $recipients, $comment_id ) {
	$comment = get_comment( $comment_id );
	$post_id = $comment->comment_post_ID;

	if ( isset( $post_id ) ) {
		$coauthors        = get_coauthors( $post_id );
		$extra_recipients = array();
		foreach ( $coauthors as $user ) {
			if ( ! empty( $user->user_email ) ) {
				$extra_recipients[] = $user->user_email;
			}
		}

		return array_unique( array_merge( $recipients, $extra_recipients ) );
	}
	return $recipients;
}

/**
 * Retrieve a list of co-author terms for a single post.
 *
 * Grabs a correctly ordered list of authors for a single post, appropriately
 * cached because it requires `wp_get_object_terms()` to succeed.
 *
 * @param int $post_id ID of the post for which to retrieve authors.
 * @return array Array of coauthor WP_Term objects
 */
function cap_get_coauthor_terms_for_post( $post_id ) {
	global $coauthors_plus;
	return $coauthors_plus->get_coauthor_terms_for_post( $post_id );
}

/**
 * Register CoAuthor REST API Routes
 */
function cap_register_coauthors_rest_api_routes(): void {
	global $coauthors_plus;
	(new CoAuthors\API\Endpoints\CoAuthors_Controller( $coauthors_plus ))->register_routes();
}
add_action( 'rest_api_init', 'cap_register_coauthors_rest_api_routes' );
