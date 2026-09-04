<?php
/**
 * The list-authors WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use WP_CLI;

/**
 * Lists a post's co-authors.
 *
 * Moved here from CoAuthorsPlus_Command unchanged. Behaviour is pinned by
 * features/list-authors.feature.
 */
class List_Authors_Command {

	/**
	 * List the co-authors assigned to a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post to read.
	 *
	 * [--field=<field>]
	 * : Print one field for each co-author.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to show.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show the co-authors of a post.
	 *     $ wp co-authors-plus list-authors 42
	 *
	 *     # Just their logins.
	 *     $ wp co-authors-plus list-authors 42 --field=user_login
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$post_id = absint( $args[0] ?? 0 );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			WP_CLI::error( 'Please specify a valid post_id.' );
		}

		$coauthors = get_coauthors( $post_id );

		if ( empty( $coauthors ) ) {
			WP_CLI::log( 'No co-authors found for post #' . $post_id );
			return;
		}

		$fields = array( 'ID', 'display_name', 'user_nicename', 'user_email' );

		$items = array_map(
			static function ( $coauthor ) use ( $fields ) {
				$item = array();

				foreach ( $fields as $field ) {
					$item[ $field ] = $coauthor->$field ?? '';
				}

				return $item;
			},
			$coauthors
		);

		$formatter = new \WP_CLI\Formatter( $assoc_args, $fields );
		$formatter->display_items( $items );
	}
}
