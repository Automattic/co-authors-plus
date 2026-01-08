<?php
/**
 * WordPress Importer integration
 *
 * Prevents duplicate guest-author posts during imports when posts
 * differ only by post_date.
 *
 * @package CoAuthors
 * @since 3.6.3
 */

namespace Automattic\CoAuthorsPlus\Integrations;

/**
 * WordPress Importer integration class.
 */
class WordPress_Importer {

	/**
	 * The guest-author post type.
	 */
	const POST_TYPE = 'guest-author';

	/**
	 * Initialize the integration by registering hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'wp_import_existing_post', array( $this, 'check_existing_guest_author' ), 10, 2 );
	}

	/**
	 * Check for existing guest-author posts by title only.
	 *
	 * During imports, guest-author posts that differ only by post_date
	 * should be considered duplicates. This filter modifies the duplicate
	 * detection to check by title alone for guest-author posts.
	 *
	 * @link https://github.com/Automattic/Co-Authors-Plus/issues/326
	 *
	 * @param int   $post_exists Post ID if the post exists, 0 otherwise.
	 * @param array $post        Post data being imported.
	 * @return int Post ID if the post exists, 0 otherwise.
	 */
	public function check_existing_guest_author( int $post_exists, array $post ): int {
		// Only modify behavior for guest-author posts.
		if ( ! isset( $post['post_type'] ) || self::POST_TYPE !== $post['post_type'] ) {
			return $post_exists;
		}

		// If WordPress already found a match, use that.
		if ( 0 !== $post_exists ) {
			return $post_exists;
		}

		// Check for existing post by title only (ignoring post_date).
		if ( isset( $post['post_title'] ) ) {
			$post_exists = post_exists( $post['post_title'], '', '', self::POST_TYPE );
		}

		return $post_exists;
	}
}
