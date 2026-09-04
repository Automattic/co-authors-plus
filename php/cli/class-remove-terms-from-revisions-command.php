<?php
/**
 * The remove-terms-from-revisions WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use WP_CLI;

/**
 * Strips author terms from revisions, which were assigned them for years.
 *
 * Moved here from CoAuthorsPlus_Command unchanged, including its use of a
 * hardcoded taxonomy name rather than the configured one; correcting that
 * changes behaviour and belongs in its own change. Behaviour is pinned by
 * features/remove-terms-from-revisions.feature.
 */
class Remove_Terms_From_Revisions_Command {

	/**
	 * Remove author terms from revisions.
	 *
	 * Revisions were given author terms for a long time, which they have no use for.
	 * This removes them.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clean author terms off revisions.
	 *     $ wp co-authors-plus remove-terms-from-revisions
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;

		$ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type='revision' AND post_status='inherit'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WP-CLI one-time maintenance command.

		WP_CLI::log( 'Found ' . count( $ids ) . ' revisions to look through' );
		$affected = 0;
		foreach ( $ids as $post_id ) {

			$terms = cap_get_coauthor_terms_for_post( $post_id );
			if ( empty( $terms ) ) {
				continue;
			}

			WP_CLI::log( "#{$post_id}: Removing " . implode( ',', wp_list_pluck( $terms, 'slug' ) ) );
			wp_set_post_terms( $post_id, array(), 'author' );
			$affected++;
		}
		WP_CLI::log( "All done! {$affected} revisions had author terms removed" );
	}
}
