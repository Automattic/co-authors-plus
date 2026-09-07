<?php
/**
 * The assign-user-to-coauthor WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors_Plus;
use WP_CLI;

/**
 * Gives a co-author the byline on a WordPress user's posts.
 *
 * Moved here from CoAuthorsPlus_Command unchanged. Behaviour is pinned by
 * features/assign-user-to-coauthor.feature.
 */
class Assign_User_To_Coauthor_Command {

	/**
	 * Plugin instance.
	 *
	 * @var CoAuthors_Plus
	 */
	private $coauthors_plus;

	/**
	 * Constructor.
	 *
	 * @param CoAuthors_Plus $coauthors_plus Plugin instance.
	 */
	public function __construct( CoAuthors_Plus $coauthors_plus ) {
		$this->coauthors_plus = $coauthors_plus;
	}

	/**
	 * Give a co-author the byline on every post a WordPress user authored.
	 *
	 * Identify the source author by either --user_login or --user_id. The latter
	 * is useful once the WordPress user has been deleted, since a login lookup is
	 * no longer possible but post_author still holds the original ID.
	 *
	 * ## OPTIONS
	 *
	 * [--user_login=<user-login>]
	 * : The WordPress user whose posts should be reassigned.
	 *
	 * [--user_id=<user-id>]
	 * : The same, by ID. Use this when the user no longer exists.
	 *
	 * --coauthor=<co-author>
	 * : The co-author to give the byline to.
	 *
	 * [--append_coauthors]
	 * : Add to the existing byline rather than replacing it.
	 *
	 * ## EXAMPLES
	 *
	 *     # Give alice the byline on everything bob wrote.
	 *     $ wp co-authors-plus assign-user-to-coauthor --user_login=bob --coauthor=alice
	 *
	 *     # The same, for a user that has since been deleted.
	 *     $ wp co-authors-plus assign-user-to-coauthor --user_id=42 --coauthor=alice
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;

		$coauthors_plus = $this->coauthors_plus;

		$defaults   = array(
			'user_login'       => '',
			'user_id'          => '',
			'coauthor'         => '',
			'append_coauthors' => false,
		);
		$assoc_args = wp_parse_args( $assoc_args, $defaults );

		$has_login = '' !== $assoc_args['user_login'];
		$has_id    = '' !== $assoc_args['user_id'];

		if ( $has_login === $has_id ) {
			WP_CLI::error( __( 'Please specify exactly one of --user_login or --user_id.', 'co-authors-plus' ) );
		}

		if ( $has_login ) {
			$user = get_user_by( 'login', $assoc_args['user_login'] );
			if ( ! $user ) {
				WP_CLI::error( __( 'Please specify a valid user_login.', 'co-authors-plus' ) );
			}
			$user_id = (int) $user->ID;
		} else {
			$user_id = (int) $assoc_args['user_id'];
			if ( $user_id <= 0 ) {
				WP_CLI::error( __( 'Please specify a positive integer for user_id.', 'co-authors-plus' ) );
			}
		}

		$coauthor = $coauthors_plus->get_coauthor_by( 'login', $assoc_args['coauthor'] );

		if ( ! $coauthor ) {
			WP_CLI::error( __( 'Please specify a valid co-author login', 'co-authors-plus' ) );
		}

		$post_types = implode( "','", $coauthors_plus->supported_post_types() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$posts    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_author=%d AND post_type IN ('{$post_types}')", $user_id ) );
		$affected = 0;
		foreach ( $posts as $post_id ) {
			$coauthors = cap_get_coauthor_terms_for_post( $post_id );
			if ( ! empty( $coauthors ) && ! $assoc_args['append_coauthors'] ) {
				WP_CLI::log(
					sprintf(
						/* translators: 1: Post ID, 2: Comma-separated list of co-author slugs. */
						__( 'Skipping - Post #%1$d already has co-authors assigned: %2$s', 'co-authors-plus' ),
						$post_id,
						implode( ', ', wp_list_pluck( $coauthors, 'slug' ) )
					)
				);
				continue;
			}

			$coauthors_plus->add_coauthors( $post_id, array( $coauthor->user_login ), $assoc_args['append_coauthors'] );
			/* translators: 1: Co-author login, 2: Post ID */
			WP_CLI::log( sprintf( __( "Updating - Adding %1\$s's byline to post #%2\$d", 'co-authors-plus' ), $coauthor->user_login, $post_id ) );
			$affected++;
			if ( $affected && 0 === $affected % 100 ) {
				sleep( 2 );
			}
		}//end foreach

		$success_message = sprintf(
			/* translators: Count of posts. */
			_n(
				'All done! %s post was affected.',
				'All done! %s posts were affected.',
				$affected,
				'co-authors-plus'
			),
			number_format_i18n( $affected )
		);
		WP_CLI::success( $success_message );
	}
}
