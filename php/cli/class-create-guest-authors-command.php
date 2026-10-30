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
	 * repeated. A user whose profile cannot be created is reported with the
	 * reason and counted as a failure; the run carries on and still exits 0.
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
		$failed   = 0;

		WP_CLI::log(
			sprintf(
				/* translators: Count of users. */
				_n(
					'Attempting to create a guest author profile for %s user.',
					'Attempting to create guest author profiles for %s users.',
					$count,
					'co-authors-plus'
				),
				number_format_i18n( $count )
			)
		);

		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing guest authors...', $count );
		foreach ( $users as $user ) {

			// Ask whether the user is already linked rather than reading it from a
			// creation error: the same duplicate-field error covers an unrelated
			// guest author holding the login, and a renamed user would not collide.
			if ( $coauthors_plus->guest_authors->get_guest_author_by( 'linked_account', $user->user_login ) ) {
				$skipped++;
				$progress->tick();
				continue;
			}

			$result = $coauthors_plus->guest_authors->create_guest_author_from_user_id( $user->ID );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning(
					sprintf(
						/* translators: 1: User login. 2: Error message. */
						__( 'Could not create a guest author profile for user %1$s: %2$s', 'co-authors-plus' ),
						$user->user_login,
						$result->get_error_message()
					)
				);
				$failed++;
			} else {
				$created++;
			}
			$progress->tick();
		}//end foreach
		$progress->finish();
		WP_CLI::log( 'All done! Here are your results:' );
		WP_CLI::log(
			'- ' . sprintf(
				/* translators: Count of guest author profiles. */
				_n(
					'%s guest author profile was created',
					'%s guest author profiles were created',
					$created,
					'co-authors-plus'
				),
				number_format_i18n( $created )
			)
		);
		WP_CLI::log(
			'- ' . sprintf(
				/* translators: Count of users. */
				_n(
					'%s user already had a guest author profile',
					'%s users already had guest author profiles',
					$skipped,
					'co-authors-plus'
				),
				number_format_i18n( $skipped )
			)
		);

		if ( $failed > 0 ) {
			// A failed user does not abort the run, matching the CSV and WXR importers.
			WP_CLI::warning(
				sprintf(
					/* translators: 1: Count of failed users. 2: Total count of users. */
					_n(
						'%1$s of %2$s user could not be given a guest author profile.',
						'%1$s of %2$s users could not be given a guest author profile.',
						$count,
						'co-authors-plus'
					),
					number_format_i18n( $failed ),
					number_format_i18n( $count )
				)
			);
		}
	}
}
