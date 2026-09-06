<?php
/**
 * The import-coauthors WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use Automattic\CoAuthorsPlus\Services\Coauthor_Import_Service;
use InvalidArgumentException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Restores guest authors from a file written by export-coauthors.
 *
 * Holds only the concerns of running as a command: reading arguments and the
 * file, and reporting what happened. The restoring itself is
 * Coauthor_Import_Service, which is where that logic is tested.
 */
class Import_Coauthors_Command {

	/**
	 * Import runner.
	 *
	 * @var Coauthor_Import_Service
	 */
	private $import;

	/**
	 * Constructor.
	 *
	 * @param Coauthor_Import_Service $import Import runner.
	 */
	public function __construct( Coauthor_Import_Service $import ) {
		$this->import = $import;
	}

	/**
	 * Import guest authors and their post assignments from a JSON file.
	 *
	 * Creates any guest author profile the file describes and this site does
	 * not have, then puts each author back on the posts it was on, matched by
	 * slug and post type and restored to its original position in the byline.
	 *
	 * Safe to run more than once: an author already on a post is left alone,
	 * so a run that was interrupted can simply be repeated.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : The file to read, as written by export-coauthors.
	 *
	 * [--dry-run]
	 * : Report what would happen without writing anything.
	 *
	 * [--skip-create]
	 * : Only put authors back on posts. Profiles this site does not already
	 * have are left uncreated, which is what you want when the guest author
	 * posts have arrived by WordPress import already.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what would happen.
	 *     $ wp co-authors-plus import-coauthors --file=/tmp/authors.json --dry-run
	 *
	 *     # Do it.
	 *     $ wp co-authors-plus import-coauthors --file=/tmp/authors.json
	 *     Success: Created 12 profiles and linked 84 posts.
	 *
	 *     # Only re-link, because the profiles came over with a WordPress import.
	 *     $ wp co-authors-plus import-coauthors --file=/tmp/authors.json --skip-create
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$file        = (string) Utils\get_flag_value( $assoc_args, 'file', '' );
		$dry_run     = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$skip_create = (bool) Utils\get_flag_value( $assoc_args, 'skip-create', false );

		$data = $this->read( $file );

		$this->warn_on_version_mismatch( $data );

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run: nothing will be written.' );
		}

		try {
			$summary = $this->import->import( $data, $dry_run, $skip_create );
		} catch ( InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$this->report( $summary, $dry_run );
	}

	/**
	 * Read and decode the export.
	 *
	 * @param string $file Path to read.
	 * @return array<string, mixed>
	 */
	private function read( string $file ): array {
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s. Pass --file=<path> to a file written by export-coauthors.', $file ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file the operator named.
		$raw = file_get_contents( $file );

		if ( false === $raw ) {
			WP_CLI::error( sprintf( 'Could not read %s.', $file ) );
		}

		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) ) {
			WP_CLI::error( sprintf( '%s is not valid JSON.', $file ) );
		}

		return $data;
	}

	/**
	 * Point out that the file came from a different version of the plugin.
	 *
	 * Not fatal, but worth saying: the shape of a profile can change between
	 * versions, and an unexplained result is easier to understand with this in
	 * the scrollback.
	 *
	 * @param array<string, mixed> $data Decoded export.
	 * @return void
	 */
	private function warn_on_version_mismatch( array $data ): void {
		$version = (string) ( $data['version'] ?? '' );

		if ( '' === $version || COAUTHORS_PLUS_VERSION === $version ) {
			return;
		}

		WP_CLI::warning(
			sprintf(
				'This file was written by Co-Authors Plus %s and this site runs %s. Check the results.',
				$version,
				COAUTHORS_PLUS_VERSION
			)
		);
	}

	/**
	 * Report what the import did.
	 *
	 * @param array<string, mixed> $summary Import summary.
	 * @param bool                 $dry_run Whether anything was written.
	 * @return void
	 */
	private function report( array $summary, bool $dry_run ): void {
		foreach ( $summary['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}

		if ( $summary['posts_not_found'] > 0 ) {
			WP_CLI::log(
				sprintf(
					/* translators: Count of byline assignments. */
					_n(
						'%s assignment had no matching post on this site.',
						'%s assignments had no matching post on this site.',
						$summary['posts_not_found'],
						'co-authors-plus'
					),
					number_format_i18n( $summary['posts_not_found'] )
				)
			);
		}

		// Label form rather than a sentence, so neither count needs verb agreement
		// and the old 'Would create N profiles and linked N posts' tense clash goes.
		$message = sprintf(
			$dry_run
				? 'Dry run. Profiles to create: %1$s. Posts to link: %2$s.'
				: 'Done. Profiles created: %1$s. Posts linked: %2$s.',
			number_format_i18n( $summary['authors_created'] ),
			number_format_i18n( $summary['posts_linked'] )
		);

		if ( $summary['authors_skipped'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: Count of guest author profiles. */
				_n(
					'%s profile already existed.',
					'%s profiles already existed.',
					$summary['authors_skipped'],
					'co-authors-plus'
				),
				number_format_i18n( $summary['authors_skipped'] )
			);
		}

		WP_CLI::success( $message );
	}
}
