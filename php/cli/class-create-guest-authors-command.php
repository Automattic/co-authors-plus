<?php
/**
 * The create-guest-authors WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use CoAuthors_Plus;
use WP_CLI;

/**
 * Creates a guest author profile for each WordPress user.
 *
 * Moved here from CoAuthorsPlus_Command unchanged, save for the scratch
 * property holding its parsed arguments becoming a local. Behaviour is pinned
 * by features/create-guest-author.feature.
 */
class Create_Guest_Authors_Command {

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
	 * Create a guest author profile for every WordPress user.
	 *
	 * Users that already have a linked profile are skipped, so this is safe to run
	 * again. Use --offset and --number to work through a large user list in chunks;
	 * because existing profiles are skipped, an interrupted run can simply be
	 * repeated.
	 *
	 * ## OPTIONS
	 *
	 * [--offset=<offset>]
	 * : How many users to skip before starting. Defaults to 0.
	 *
	 * [--number=<number>]
	 * : How many users to process in this run. Defaults to all of them.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create profiles for every user.
	 *     $ wp co-authors-plus create-guest-authors
	 *
	 *     # Work through the list a thousand at a time.
	 *     $ wp co-authors-plus create-guest-authors --offset=1000 --number=1000
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$coauthors_plus = $this->coauthors_plus;

		$defaults = array(
			'offset' => '',
			'number' => '',
		);
		$parsed_args = wp_parse_args( $assoc_args, $defaults );

		// Chunked runs rely on a stable ordering across invocations. get_users().
		// orders by user_login ASC by default, which is stable provided users.
		// are not added or removed between chunks.
		$users    = get_users( $parsed_args );
		$count    = count( $users );
		$created  = 0;
		$skipped  = 0;

		WP_CLI::log( "Attempting to create guest author profiles for {$count} users." );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing guest authors...', $count );
		foreach ( $users as $user ) {

			$result = $coauthors_plus->guest_authors->create_guest_author_from_user_id( $user->ID );
			if ( is_wp_error( $result ) ) {
				$skipped++;
			} else {
				$created++;
			}
			$progress->tick();
		}
		$progress->finish();
		WP_CLI::log( 'All done! Here are your results:' );
		WP_CLI::log( "- {$created} guest author profiles were created" );
		WP_CLI::log( "- {$skipped} users already had guest author profiles" );
	}
}
