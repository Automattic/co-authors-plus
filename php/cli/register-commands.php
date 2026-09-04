<?php
/**
 * Registers the WP-CLI commands that are classes of their own.
 *
 * Loaded after $coauthors_plus exists, because the commands take their
 * collaborators through the constructor rather than reaching for globals.
 * Registering a class name instead would hand WP-CLI the job of constructing
 * them, which it can only do for a no-argument constructor.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

use Automattic\CoAuthorsPlus\CLI\Export_Coauthors_Command;
use Automattic\CoAuthorsPlus\CLI\Import_Coauthors_Command;
use Automattic\CoAuthorsPlus\CLI\Swap_Coauthors_Command;
use Automattic\CoAuthorsPlus\Services\Coauthor_Assignment_Service;
use Automattic\CoAuthorsPlus\Services\Coauthor_Export_Service;
use Automattic\CoAuthorsPlus\Services\Coauthor_Import_Service;
use Automattic\CoAuthorsPlus\Services\Guest_Author_Service;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// The plugin does not ship Composer's autoloader, so every class is required
// explicitly, as the rest of the plugin does.
require_once __DIR__ . '/../services/class-coauthor-assignment-service.php';
require_once __DIR__ . '/../services/class-guest-author-service.php';
require_once __DIR__ . '/../services/class-coauthor-export-service.php';
require_once __DIR__ . '/../services/class-coauthor-import-service.php';
require_once __DIR__ . '/class-export-coauthors-command.php';
require_once __DIR__ . '/class-import-coauthors-command.php';
require_once __DIR__ . '/class-swap-coauthors-command.php';

// Registration waits for init, because CoAuthors_Plus builds its guest authors
// instance there, and these commands take theirs through the constructor.
add_action(
	'init',
	static function (): void {
		global $coauthors_plus;

		// Guest authors can be switched off, in which case the plugin never
		// builds the instance and there is nothing for these commands to act
		// on. The rest of the guest author functionality is loaded on the same
		// condition, so leaving them unregistered is consistent with it.
		if ( ! $coauthors_plus instanceof CoAuthors_Plus || ! $coauthors_plus->guest_authors instanceof CoAuthors_Guest_Authors ) {
			return;
		}

		$assignments   = new Coauthor_Assignment_Service( $coauthors_plus );
		$guest_authors = new Guest_Author_Service( $coauthors_plus->guest_authors );

		WP_CLI::add_command(
			'co-authors-plus export-coauthors',
			new Export_Coauthors_Command(
				$coauthors_plus,
				new Coauthor_Export_Service( $coauthors_plus, $guest_authors, $assignments )
			)
		);

		WP_CLI::add_command(
			'co-authors-plus swap-coauthors',
			new Swap_Coauthors_Command( $coauthors_plus )
		);

		WP_CLI::add_command(
			'co-authors-plus import-coauthors',
			new Import_Coauthors_Command(
				new Coauthor_Import_Service( $guest_authors, $assignments )
			)
		);
	},
	20
);
