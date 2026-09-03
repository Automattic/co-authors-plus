<?php
/**
 * The export-coauthors WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use Automattic\CoAuthorsPlus\Services\Coauthor_Export_Service;
use CoAuthors_Plus;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Writes the site's guest authors out to a file.
 *
 * Holds only the concerns of running as a command: reading arguments, showing
 * progress, writing the file and reporting. What to export is decided by
 * Coauthor_Export_Service, which is where that logic is tested.
 */
class Export_Coauthors_Command {

	/**
	 * Plugin instance, for the default post type list.
	 *
	 * @var CoAuthors_Plus
	 */
	private $coauthors_plus;

	/**
	 * Export builder.
	 *
	 * @var Coauthor_Export_Service
	 */
	private $export;

	/**
	 * Constructor.
	 *
	 * @param CoAuthors_Plus          $coauthors_plus Plugin instance.
	 * @param Coauthor_Export_Service $export         Export builder.
	 */
	public function __construct( CoAuthors_Plus $coauthors_plus, Coauthor_Export_Service $export ) {
		$this->coauthors_plus = $coauthors_plus;
		$this->export         = $export;
	}

	/**
	 * Export guest authors and their post assignments to a JSON file.
	 *
	 * Assignments are recorded by post slug and post type, not by ID, so the
	 * file still means something after a WordPress export and import cycle has
	 * renumbered the posts. Feed it to import-coauthors on the far side.
	 *
	 * Only guest author profiles are exported. Co-authors that are WordPress
	 * users are left alone, since those accounts are moved by other means.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Where to write the file. Defaults to cap-coauthors-export.json inside
	 * wp-content.
	 *
	 * [--post-types=<post-types>]
	 * : Comma-separated post types to record assignments for. Defaults to the
	 * post types Co-Authors Plus is enabled for.
	 *
	 * ## EXAMPLES
	 *
	 *     # Export every guest author and assignment.
	 *     $ wp co-authors-plus export-coauthors
	 *     Success: Exported 12 guest authors to /var/www/wp-content/cap-coauthors-export.json
	 *
	 *     # Export assignments on posts and pages only, to a chosen path.
	 *     $ wp co-authors-plus export-coauthors --file=/tmp/authors.json --post-types=post,page
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$file       = (string) Utils\get_flag_value( $assoc_args, 'file', WP_CONTENT_DIR . '/cap-coauthors-export.json' );
		$post_types = $this->post_types( $assoc_args );

		$ids = $this->export->guest_author_ids();

		if ( empty( $ids ) ) {
			WP_CLI::warning( 'No guest authors found, so there is nothing to export.' );
			return;
		}

		$progress = Utils\make_progress_bar( 'Exporting guest authors', count( $ids ) );
		$entries  = array();

		foreach ( $ids as $id ) {
			$entry = $this->export->entry_for( $id, $post_types );

			if ( null === $entry ) {
				WP_CLI::warning( sprintf( 'Could not read guest author %d, so it is not in the file.', $id ) );
			} else {
				$entries[] = $entry;
			}

			$progress->tick();
		}

		$progress->finish();

		$this->write(
			$file,
			array(
				'version'       => COAUTHORS_PLUS_VERSION,
				'exported_at'   => gmdate( 'c' ),
				'post_types'    => $post_types,
				'guest_authors' => $entries,
			)
		);

		WP_CLI::success( sprintf( 'Exported %d guest authors to %s', count( $entries ), $file ) );
	}

	/**
	 * Post types to record assignments for.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return string[]
	 */
	private function post_types( array $assoc_args ): array {
		$given = Utils\get_flag_value( $assoc_args, 'post-types' );

		if ( null === $given ) {
			return $this->coauthors_plus->supported_post_types();
		}

		return array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $given ) ) ) );
	}

	/**
	 * Write the export, failing loudly if it cannot be written.
	 *
	 * @param string               $file   Destination path.
	 * @param array<string, mixed> $export Export payload.
	 * @return void
	 */
	private function write( string $file, array $export ): void {
		$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			WP_CLI::error( 'Could not encode the export as JSON.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a file the operator named, outside the uploads directory.
		if ( false === file_put_contents( $file, $json ) ) {
			WP_CLI::error( sprintf( 'Could not write to %s. Check the path exists and is writable.', $file ) );
		}
	}
}
