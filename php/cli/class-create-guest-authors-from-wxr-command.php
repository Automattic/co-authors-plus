<?php
/**
 * The create-guest-authors-from-wxr WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use WP_CLI;
use WXR_Parser;

/**
 * Creates guest authors from the author list in a WXR export.
 *
 * Moved here from CoAuthorsPlus_Command unchanged, save for the scratch
 * property holding its parsed arguments becoming a local. Behaviour is pinned
 * by features/create-guest-authors-from-wxr.feature.
 */
class Create_Guest_Authors_From_Wxr_Command {

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
	 * Create guest authors from a WordPress export file.
	 *
	 * Reads the author list out of a WXR file and creates a profile for each one.
	 * Requires the WordPress Importer plugin, whose parser this borrows.
	 *
	 * ## OPTIONS
	 *
	 * --file=<file>
	 * : Path to the WXR file.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create profiles for the authors in an export.
	 *     $ wp co-authors-plus create-guest-authors-from-wxr --file=./export.xml
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $coauthors_plus;

		$defaults   = array(
			'file' => '',
		);
		$parsed_args = wp_parse_args( $assoc_args, $defaults );

		if ( empty( $parsed_args['file'] ) || ! is_readable( $parsed_args['file'] ) ) {
			WP_CLI::error( 'Please specify a valid WXR file with the --file arg.' );
		}

		if ( ! class_exists( 'WXR_Parser' ) ) {
			// WP_PLUGIN_DIR rather than WP_CONTENT_DIR . '/plugins', so a site with a
			// relocated plugin directory is not told the importer is missing when it is
			// simply somewhere else.
			$parser_path = WP_PLUGIN_DIR . '/wordpress-importer/parsers.php';

			if ( ! file_exists( $parser_path ) ) {
				WP_CLI::error( 'This command needs the WordPress Importer plugin. Install it with `wp plugin install wordpress-importer`.' );
			}

			require_once $parser_path;
		}

		// The 0.9.x importer's fallback parser needs the bundled toolkit, which only
		// the importer's own bootstrap loads. Without it, a file that is not a WXR
		// fatals inside the fallback instead of returning the WP_Error the branch
		// below reads — the branch was unreachable for exactly this reason. Guarded
		// as the bootstrap guards it; older importers have no toolkit and their
		// fallback chain is self-contained.
		$toolkit_path = WP_PLUGIN_DIR . '/wordpress-importer/php-toolkit/load.php';

		if ( ! class_exists( 'WordPress\\XML\\XMLProcessor' ) && file_exists( $toolkit_path ) ) {
			require_once $toolkit_path;
		}

		$parser      = new WXR_Parser();
		$import_data = $parser->parse( $parsed_args['file'] );

		if ( is_wp_error( $import_data ) ) {
			WP_CLI::error( 'Failed to read WXR file: ' . $import_data->get_error_message() );
		}

		// Get author nodes.
		$authors = $import_data['authors'];
		$failed  = 0;

		foreach ( $authors as $author ) {
			WP_CLI::log( sprintf( 'Processing author %s (%s)', $author['author_login'], $author['author_email'] ) );

			$guest_author_data = array(
				'display_name' => $author['author_display_name'],
				'user_login'   => $author['author_login'],
				'user_email'   => $author['author_email'],
				'first_name'   => $author['author_first_name'],
				'last_name'    => $author['author_last_name'],
				'ID'           => $author['author_id'],
			);

			if ( ! $this->creator->create( $guest_author_data ) ) {
				++$failed;
			}
		}

		if ( $failed > 0 ) {
			// A bad author node does not abort the import, so say how many were dropped.
			WP_CLI::warning( sprintf( '%d of %d authors could not be created.', $failed, count( $authors ) ) );
		}

		WP_CLI::log( 'All done!' );
	}
}
