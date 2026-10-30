<?php
/**
 * The delete-postmeta-that-skip-author-term-backfill WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use WP_CLI;

/**
 * Removes the postmeta that marks a post as skipped during backfill.
 *
 * Behaviour is pinned by
 * scenarios in features/create-author-terms-for-posts.feature.
 */
class Delete_Skip_Backfill_Postmeta_Command {

	/**
	 * Delete the postmeta marking posts as skipped during author term backfill.
	 *
	 * The backfill leaves a marker on any post it could not handle,
	 * so a later run does not keep retrying it. This clears those markers.
	 *
	 * ## OPTIONS
	 *
	 * [--specific-post-ids=<ids>]
	 * : Comma-separated post IDs to clear. Defaults to every post carrying the
	 * marker.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear every marker.
	 *     $ wp co-authors-plus delete-postmeta-that-skip-author-term-backfill
	 *
	 *     # Clear two specific posts.
	 *     $ wp co-authors-plus delete-postmeta-that-skip-author-term-backfill --specific-post-ids=12,34
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;

		$meta_key          = Create_Author_Terms_For_Posts_Command::SKIP_POST_FOR_BACKFILL_META_KEY;
		$specific_post_ids = isset( $assoc_args['specific-post-ids'] ) ? explode( ',', $assoc_args['specific-post-ids'] ) : array();

		if ( empty( $specific_post_ids ) ) {
			// Read the meta table directly. A WP_Query here inherits.
			// post_type=post, post_status=publish and the site's.
			// posts_per_page, which silently hid the meta on pages, on drafts.
			// and on everything past the first page of results.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$specific_post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$meta_key
				)
			);
		}

		foreach ( $specific_post_ids as $post_id ) {
			// A stray non-numeric entry used to be cast to 0 and reported as
			// "post 0", which reads as a real post. Name it and move on, so the
			// valid IDs in the same list are still processed. Checked here rather
			// than before the empty() fallback: emptying the user's list there
			// would silently widen the run to every marker on the site.
			if ( ! is_numeric( $post_id ) ) {
				WP_CLI::warning( sprintf( 'Ignoring non-numeric post ID `%s` in --specific-post-ids.', $post_id ) );
				continue;
			}

			$post_id = (int) $post_id;

			// A post that never carried the marker is not a failure, and aborting on one
			// left every later --specific-post-ids entry unprocessed after earlier
			// deletions had already succeeded.
			if ( delete_post_meta( $post_id, $meta_key ) ) {
				WP_CLI::success( sprintf( 'Deleted `%s` postmeta from post %d.', $meta_key, $post_id ) );
			} else {
				WP_CLI::warning( sprintf( 'No `%s` postmeta to delete on post %d.', $meta_key, $post_id ) );
			}
		}//end foreach
	}
}
