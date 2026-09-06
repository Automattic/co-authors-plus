<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Version:           4.1.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Mohammad Jangda, Daniel Bachhuber, Automattic
 * Author URI:        https://automattic.com
 * Text Domain:       co-authors-plus
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

const COAUTHORS_PLUS_VERSION = '4.1.1';
const COAUTHORS_PLUS_FILE = __FILE__;

require_once __DIR__ . '/template-tags.php';

require_once __DIR__ . '/php/class-prefix.php';
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

global $coauthors_plus;
$coauthors_plus     = new CoAuthors_Plus();
$coauthors_plus->register_hooks();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/php/cli/register-commands.php';
}

$coauthors_endpoint = new CoAuthors\API\Endpoints( $coauthors_plus );
$coauthors_endpoint->register_hooks();
CoAuthors\Blocks::run();

// Initialize integrations.
( new Automattic\CoAuthorsPlus\Integrations\WordPress_Importer() )->init();
( new Automattic\CoAuthorsPlus\Integrations\Jetpack_Subscriber_Emails() )->init();

if ( ! function_exists( 'wp_notify_postauthor' ) ) :
	/**
	 * Notify a co-author of a comment/trackback/pingback to one of their posts.
	 *
	 * This mirrors the core function in wp-includes/pluggable.php but pre-populates
	 * the recipient list with all co-authors of the post, so every co-author gets
	 * notified. By running the recipients through the core `comment_notification_recipients`
	 * filter, plugins and themes can still add or remove email addresses.
	 *
	 * @since 2.6.2
	 *
	 * @param int|WP_Comment $comment_id Comment ID or WP_Comment object.
	 * @param string         $deprecated Not used.
	 * @return bool True on completion, false if there are no email addresses to notify.
	 */
	function wp_notify_postauthor( $comment_id, $deprecated = null ) {
		if ( null !== $deprecated ) {
			_deprecated_argument( __FUNCTION__, '3.8.0' );
		}

		$comment = get_comment( $comment_id );
		if ( empty( $comment ) || empty( $comment->comment_post_ID ) ) {
			return false;
		}

		$post = get_post( $comment->comment_post_ID );

		$author = get_userdata( $post->post_author );

		$emails = array();

		// Notify every co-author, not just the post author.
		$coauthors = get_coauthors( $post->ID );
		foreach ( $coauthors as $coauthor ) {
			if ( ! empty( $coauthor->user_email ) ) {
				$emails[] = $coauthor->user_email;
			}
		}

		// Fall back to the post author if no co-author terms are assigned.
		if ( empty( $emails ) && $author ) {
			$emails[] = $author->user_email;
		}

		/**
		 * Filters the list of email addresses to receive a comment notification.
		 *
		 * By default, only co-authors of the post are notified of comments. This filter allows
		 * others to be added.
		 *
		 * @param string[] $emails     An array of email addresses to receive a comment notification.
		 * @param string   $comment_id The comment ID as a numeric string.
		 */
		$emails = apply_filters( 'comment_notification_recipients', $emails, $comment->comment_ID );
		$emails = array_filter( $emails );

		// If there are no addresses to send the comment to, bail.
		if ( ! count( $emails ) ) {
			return false;
		}

		// Facilitate unsetting below without knowing the keys.
		$emails = array_flip( $emails );

		/**
		 * Filters whether to notify comment authors of their comments on their own posts.
		 *
		 * @param bool   $notify_author Whether to notify the post author of their own comment.
		 * @param string $comment_id    The comment ID as a numeric string.
		 */
		$notify_author = apply_filters( 'comment_notification_notify_author', false, $comment->comment_ID );

		// The comment was left by one of the co-authors.
		// Note: for a guest author, $coauthor->ID is the guest-author CPT's post ID, not a WP user ID —
		// it could theoretically collide with $comment->user_id or the current user's ID for an unrelated post/user pair.
		foreach ( $coauthors as $coauthor ) {
			if ( ! $notify_author && (int) $comment->user_id === (int) $coauthor->ID ) {
				unset( $emails[ $coauthor->user_email ] );
			}

			// The co-author moderated a comment on their own post.
			if ( ! $notify_author && get_current_user_id() === (int) $coauthor->ID ) {
				unset( $emails[ $coauthor->user_email ] );
			}
		}

		// If there's no email to send the comment to, bail, otherwise flip array back around for use below.
		if ( ! count( $emails ) ) {
			return false;
		} else {
			$emails = array_flip( $emails );
		}

		$comment_author_domain = '';
		if ( WP_Http::is_ip_address( $comment->comment_author_IP ) ) {
			$comment_author_domain = gethostbyaddr( $comment->comment_author_IP );
		}

		/*
		 * The blogname option is escaped with esc_html() on the way into the database in sanitize_option().
		 * We want to reverse this for the plain text arena of emails.
		 */
		$blogname        = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$comment_content = wp_specialchars_decode( $comment->comment_content );

		$wp_email = 'wordpress@' . preg_replace( '#^www\.#', '', wp_parse_url( network_home_url(), PHP_URL_HOST ) );

		if ( '' === $comment->comment_author ) {
			$from = "From: \"$blogname\" <$wp_email>";
			if ( '' !== $comment->comment_author_email ) {
				$reply_to = "Reply-To: $comment->comment_author_email";
			}
		} else {
			$from = "From: \"$comment->comment_author\" <$wp_email>";
			if ( '' !== $comment->comment_author_email ) {
				$reply_to = "Reply-To: \"$comment->comment_author_email\" <$comment->comment_author_email>";
			}
		}

		$message_headers = "$from\n"
			. 'Content-Type: text/plain; charset="' . get_option( 'blog_charset' ) . "\"\n";

		if ( isset( $reply_to ) ) {
			$message_headers .= $reply_to . "\n";
		}

		/**
		 * Filters the comment notification email headers.
		 *
		 * @param string $message_headers Headers for the comment notification email.
		 * @param string $comment_id      Comment ID as a numeric string.
		 */
		$message_headers = apply_filters( 'comment_notification_headers', $message_headers, $comment->comment_ID );

		foreach ( $emails as $email ) {
			$user = get_user_by( 'email', $email );

			if ( $user ) {
				$switched_locale = switch_to_user_locale( $user->ID );
			} else {
				$switched_locale = switch_to_locale( get_locale() );
			}

			switch ( $comment->comment_type ) {
				case 'trackback':
					/* translators: %s: Post title. */
					$notify_message = sprintf( __( 'New trackback on your post "%s"', 'co-authors-plus' ), $post->post_title ) . "\r\n";
					/* translators: 1: Trackback/pingback website name, 2: Website IP address, 3: Website hostname. */
					$notify_message .= sprintf( __( 'Website: %1$s (IP address: %2$s, %3$s)', 'co-authors-plus' ), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
					/* translators: %s: Trackback/pingback/comment author URL. */
					$notify_message .= sprintf( __( 'URL: %s', 'co-authors-plus' ), $comment->comment_author_url ) . "\r\n";
					/* translators: %s: Comment text. */
					$notify_message .= sprintf( __( 'Comment: %s', 'co-authors-plus' ), "\r\n" . $comment_content ) . "\r\n\r\n";
					$notify_message .= __( 'You can see all trackbacks on this post here:', 'co-authors-plus' ) . "\r\n";
					/* translators: Trackback notification email subject. 1: Site title, 2: Post title. */
					$subject = sprintf( __( '[%1$s] Trackback: "%2$s"', 'co-authors-plus' ), $blogname, $post->post_title );
					break;

				case 'pingback':
					/* translators: %s: Post title. */
					$notify_message = sprintf( __( 'New pingback on your post "%s"', 'co-authors-plus' ), $post->post_title ) . "\r\n";
					/* translators: 1: Trackback/pingback website name, 2: Website IP address, 3: Website hostname. */
					$notify_message .= sprintf( __( 'Website: %1$s (IP address: %2$s, %3$s)', 'co-authors-plus' ), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
					/* translators: %s: Trackback/pingback/comment author URL. */
					$notify_message .= sprintf( __( 'URL: %s', 'co-authors-plus' ), $comment->comment_author_url ) . "\r\n";
					/* translators: %s: Comment text. */
					$notify_message .= sprintf( __( 'Comment: %s', 'co-authors-plus' ), "\r\n" . $comment_content ) . "\r\n\r\n";
					$notify_message .= __( 'You can see all pingbacks on this post here:', 'co-authors-plus' ) . "\r\n";
					/* translators: Pingback notification email subject. 1: Site title, 2: Post title. */
					$subject = sprintf( __( '[%1$s] Pingback: "%2$s"', 'co-authors-plus' ), $blogname, $post->post_title );
					break;

				default:
					// Comments.
					/* translators: %s: Post title. */
					$notify_message = sprintf( __( 'New comment on your post "%s"', 'co-authors-plus' ), $post->post_title ) . "\r\n";
					/* translators: 1: Comment author's name, 2: Comment author's IP address, 3: Comment author's hostname. */
					$notify_message .= sprintf( __( 'Author: %1$s (IP address: %2$s, %3$s)', 'co-authors-plus' ), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
					/* translators: %s: Comment author email. */
					$notify_message .= sprintf( __( 'Email: %s', 'co-authors-plus' ), $comment->comment_author_email ) . "\r\n";
					/* translators: %s: Trackback/pingback/comment author URL. */
					$notify_message .= sprintf( __( 'URL: %s', 'co-authors-plus' ), $comment->comment_author_url ) . "\r\n";

					/* translators: %s: Comment text. */
					$notify_message .= sprintf( __( 'Comment: %s', 'co-authors-plus' ), "\r\n" . $comment_content ) . "\r\n\r\n";
					$notify_message .= __( 'You can see all comments on this post here:', 'co-authors-plus' ) . "\r\n";
					/* translators: Comment notification email subject. 1: Site title, 2: Post title. */
					$subject = sprintf( __( '[%1$s] Comment: "%2$s"', 'co-authors-plus' ), $blogname, $post->post_title );
					break;
			}//end switch

			$notify_message .= get_permalink( $comment->comment_post_ID ) . "#comments\r\n\r\n";
			/* translators: %s: Comment URL. */
			$notify_message .= sprintf( __( 'Permalink: %s', 'co-authors-plus' ), get_comment_link( $comment ) ) . "\r\n";

			if ( $user && user_can( $user, 'edit_comment', $comment->comment_ID ) ) {
				if ( EMPTY_TRASH_DAYS ) {
					/* translators: Comment moderation. %s: Comment action URL. */
					$notify_message .= sprintf( __( 'Trash it: %s', 'co-authors-plus' ), admin_url( "comment.php?action=trash&c={$comment->comment_ID}#wpbody-content" ) ) . "\r\n";
				} else {
					/* translators: Comment moderation. %s: Comment action URL. */
					$notify_message .= sprintf( __( 'Delete it: %s', 'co-authors-plus' ), admin_url( "comment.php?action=delete&c={$comment->comment_ID}#wpbody-content" ) ) . "\r\n";
				}
				/* translators: Comment moderation. %s: Comment action URL. */
				$notify_message .= sprintf( __( 'Spam it: %s', 'co-authors-plus' ), admin_url( "comment.php?action=spam&c={$comment->comment_ID}#wpbody-content" ) ) . "\r\n";
			}

			/**
			 * Filters the comment notification email text.
			 *
			 * @param string $notify_message The comment notification email text.
			 * @param string $comment_id     The comment ID as a numeric string.
			 */
			$notify_message = apply_filters( 'comment_notification_text', $notify_message, $comment->comment_ID );

			/**
			 * Filters the comment notification email subject.
			 *
			 * @param string $subject    The comment notification email subject.
			 * @param string $comment_id The comment ID as a numeric string.
			 */
			$subject = apply_filters( 'comment_notification_subject', $subject, $comment->comment_ID );

			wp_mail( $email, wp_specialchars_decode( $subject ), $notify_message, $message_headers );

			if ( $switched_locale ) {
				restore_previous_locale();
			}
		}//end foreach

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
	( new CoAuthors\API\Endpoints\CoAuthors_Controller( $coauthors_plus ) )->register_routes();
}
add_action( 'rest_api_init', 'cap_register_coauthors_rest_api_routes' );
