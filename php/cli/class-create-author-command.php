<?php
/**
 * The create-author WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use WP_CLI;

/**
 * Creates a single guest author from the command line.
 *
 * Moved here from CoAuthorsPlus_Command unchanged. Behaviour is pinned by
 * features/create-author.feature.
 */
class Create_Author_Command {

	/**
	 * Creates guest authors, skipping existing ones.
	 *
	 * @var Guest_Author_Creator
	 */
	private $creator;

	/**
	 * Constructor.
	 *
	 * @param Guest_Author_Creator $creator Creates guest authors, skipping existing ones.
	 */
	public function __construct( Guest_Author_Creator $creator ) {
		$this->creator = $creator;
	}

	/**
	 * Create one guest author.
	 *
	 * Skips creation when a profile already exists with the given email or login, so
	 * this is safe to run again.
	 *
	 * ## OPTIONS
	 *
	 * [--display_name=<display-name>]
	 * : The name shown on the byline.
	 *
	 * [--user_login=<user-login>]
	 * : The login the author term is keyed on.
	 *
	 * [--first_name=<first-name>]
	 * : First name.
	 *
	 * [--last_name=<last-name>]
	 * : Last name.
	 *
	 * [--user_email=<user-email>]
	 * : Email address, also used to spot an existing profile.
	 *
	 * [--website=<website>]
	 * : Website URL.
	 *
	 * [--description=<description>]
	 * : Biographical text.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a guest author.
	 *     $ wp co-authors-plus create-author --display_name="Jane Doe" --user_login=jane-doe
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$this->creator->create( $assoc_args );
	}
}
