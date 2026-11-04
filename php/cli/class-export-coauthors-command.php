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
	 * --file=<path>
	 * : Where to write the file. Keep it outside the web root.
	 * It holds guest author email addresses and the slugs of unpublished posts.
	 *
	 * [--post-types=<post-types>]
	 * : Comma-separated post types to record assignments for. Defaults to the
	 * post types Co-Authors Plus is enabled for.
	 *
	 * ## EXAMPLES
	 *
	 *     # Export every guest author and assignment.
	 *     $ wp co-authors-plus export-coauthors --file="$HOME/authors.json"
	 *     Success: Exported 12 guest authors to /home/me/authors.json
	 *
	 *     # Export assignments on posts and pages only.
	 *     $ wp co-authors-plus export-coauthors --file="$HOME/authors.json" --post-types=post,page
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$file       = trim( (string) Utils\get_flag_value( $assoc_args, 'file', '' ) );
		$post_types = $this->post_types( $assoc_args );

		if ( '' === $file ) {
			WP_CLI::error( 'Pass --file=<path> with the path to write the export to.' );
		}

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

		WP_CLI::success(
			sprintf(
				/* translators: 1: Count of guest authors. 2: File path. */
				_n(
					'Exported %1$s guest author to %2$s',
					'Exported %1$s guest authors to %2$s',
					count( $entries ),
					'co-authors-plus'
				),
				number_format_i18n( count( $entries ) ),
				$file
			)
		);

		$this->warn_if_in_web_root( $file );
	}

	/**
	 * Warn if the export landed where the web server may serve it.
	 *
	 * The paths are resolved first, so a relative --file and a symlinked uploads
	 * directory are both caught.
	 *
	 * @param string $file Path the export was written to.
	 * @return void
	 */
	private function warn_if_in_web_root( string $file ): void {
		$path = wp_normalize_path( (string) realpath( $file ) );

		foreach ( array( ABSPATH, WP_CONTENT_DIR, wp_upload_dir( null, false )['basedir'] ) as $root ) {
			$root = realpath( $root );

			if ( false !== $root && 0 === strpos( $path, trailingslashit( wp_normalize_path( $root ) ) ) ) {
				WP_CLI::warning(
					sprintf(
						'%s is inside the web root, so anyone may be able to download it. Move it somewhere private, or delete it with: %s',
						$path,
						Utils\esc_cmd( 'rm %s', $path )
					)
				);
				return;
			}
		}
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

		// tempnam() creates a file only its owner can read. Moving that into place
		// means the export never sits under an existing file's mode, and a file or
		// symlink already at the path is replaced rather than written through.
		$temp = tempnam( dirname( $file ), 'cap-export-' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Writing a file the operator named, outside the uploads directory.
		if ( false === $temp || false === file_put_contents( $temp, $json ) || ! rename( $temp, $file ) ) {
			if ( false !== $temp ) {
				wp_delete_file( $temp );
			}

			WP_CLI::error( sprintf( 'Could not write to %s. Check the path exists and is writable.', $file ) );
		}
	}
}
