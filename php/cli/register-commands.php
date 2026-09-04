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

use Automattic\CoAuthorsPlus\CLI\Assign_Coauthors_Command;
use Automattic\CoAuthorsPlus\CLI\Command_Namespace;
use Automattic\CoAuthorsPlus\CLI\Assign_User_To_Coauthor_Command;
use Automattic\CoAuthorsPlus\CLI\Create_Author_Command;
use Automattic\CoAuthorsPlus\CLI\Create_Author_Terms_For_Posts_Command;
use Automattic\CoAuthorsPlus\CLI\Create_Guest_Authors_Command;
use Automattic\CoAuthorsPlus\CLI\Create_Guest_Authors_From_Csv_Command;
use Automattic\CoAuthorsPlus\CLI\Create_Guest_Authors_From_Wxr_Command;
use Automattic\CoAuthorsPlus\CLI\Create_Terms_For_Posts_Command;
use Automattic\CoAuthorsPlus\CLI\Delete_Skip_Backfill_Postmeta_Command;
use Automattic\CoAuthorsPlus\CLI\Export_Coauthors_Command;
use Automattic\CoAuthorsPlus\CLI\Guest_Author_Creator;
use Automattic\CoAuthorsPlus\CLI\Import_Coauthors_Command;
use Automattic\CoAuthorsPlus\CLI\List_Authors_Command;
use Automattic\CoAuthorsPlus\CLI\List_Posts_Without_Terms_Command;
use Automattic\CoAuthorsPlus\CLI\Migrate_Author_Terms_Command;
use Automattic\CoAuthorsPlus\CLI\Reassign_Terms_Command;
use Automattic\CoAuthorsPlus\CLI\Remove_Terms_From_Revisions_Command;
use Automattic\CoAuthorsPlus\CLI\Rename_Coauthor_Command;
use Automattic\CoAuthorsPlus\CLI\Swap_Coauthors_Command;
use Automattic\CoAuthorsPlus\CLI\Update_Author_Terms_Command;
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
require_once __DIR__ . '/class-migrate-author-terms-command.php';
require_once __DIR__ . '/class-reassign-terms-command.php';
require_once __DIR__ . '/class-remove-terms-from-revisions-command.php';
require_once __DIR__ . '/class-rename-coauthor-command.php';
require_once __DIR__ . '/class-guest-author-creator.php';
require_once __DIR__ . '/class-create-author-command.php';
require_once __DIR__ . '/class-create-guest-authors-command.php';
require_once __DIR__ . '/class-create-guest-authors-from-csv-command.php';
require_once __DIR__ . '/class-create-guest-authors-from-wxr-command.php';
require_once __DIR__ . '/class-list-authors-command.php';
require_once __DIR__ . '/class-create-author-terms-for-posts-command.php';
require_once __DIR__ . '/class-create-terms-for-posts-command.php';
require_once __DIR__ . '/class-delete-skip-backfill-postmeta-command.php';
require_once __DIR__ . '/class-list-posts-without-terms-command.php';
require_once __DIR__ . '/class-update-author-terms-command.php';
require_once __DIR__ . '/class-command-namespace.php';
require_once __DIR__ . '/class-assign-coauthors-command.php';
require_once __DIR__ . '/class-assign-user-to-coauthor-command.php';
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

		$creator       = new Guest_Author_Creator( $coauthors_plus );
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
			'co-authors-plus migrate-author-terms',
			new Migrate_Author_Terms_Command( $coauthors_plus )
		);

		WP_CLI::add_command(
			'co-authors-plus reassign-terms',
			new Reassign_Terms_Command( $coauthors_plus )
		);

		WP_CLI::add_command(
			'co-authors-plus remove-terms-from-revisions',
			new Remove_Terms_From_Revisions_Command( $coauthors_plus )
		);

		WP_CLI::add_command(
			'co-authors-plus rename-coauthor',
			new Rename_Coauthor_Command( $coauthors_plus )
		);

		WP_CLI::add_command(
			'co-authors-plus create-author',
			new Create_Author_Command( $creator )
		);

		WP_CLI::add_command(
			'co-authors-plus create-guest-authors',
			new Create_Guest_Authors_Command( $coauthors_plus )
		);

		WP_CLI::add_command(
			'co-authors-plus create-guest-authors-from-csv',
			new Create_Guest_Authors_From_Csv_Command( $creator )
		);

		WP_CLI::add_command(
			'co-authors-plus create-guest-authors-from-wxr',
			new Create_Guest_Authors_From_Wxr_Command( $creator )
		);

		WP_CLI::add_command(
			'co-authors-plus list-authors',
			new List_Authors_Command()
		);

		WP_CLI::add_command(
			'co-authors-plus create-author-terms-for-posts',
			new Create_Author_Terms_For_Posts_Command( $coauthors_plus )
		);

		WP_CLI::add_command(
			'co-authors-plus create-terms-for-posts',
			new Create_Terms_For_Posts_Command( $coauthors_plus )
		);

		WP_CLI::add_command(
			'co-authors-plus delete-postmeta-that-skip-author-term-backfill',
			new Delete_Skip_Backfill_Postmeta_Command()
		);

		WP_CLI::add_command(
			'co-authors-plus list-posts-without-terms',
			new List_Posts_Without_Terms_Command( $coauthors_plus )
		);

		WP_CLI::add_command(
			'co-authors-plus update-author-terms',
			new Update_Author_Terms_Command( $coauthors_plus )
		);

		// Declares the namespace the subcommands sit in, so `wp co-authors-plus`
		// has a description of its own.
		WP_CLI::add_command( 'co-authors-plus', Command_Namespace::class );

		WP_CLI::add_command(
			'co-authors-plus assign-coauthors',
			new Assign_Coauthors_Command( $coauthors_plus )
		);

		WP_CLI::add_command(
			'co-authors-plus assign-user-to-coauthor',
			new Assign_User_To_Coauthor_Command( $coauthors_plus )
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
