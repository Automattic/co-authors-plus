<?php
/**
 * The create-guest-authors-from-csv WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use WP_CLI;

/**
 * Creates guest authors from a CSV file.
 *
 * Moved here from CoAuthorsPlus_Command unchanged, save for the scratch
 * property holding its parsed arguments becoming a local. Behaviour is pinned
 * by features/create-guest-authors-from-csv.feature.
 */
class Create_Guest_Authors_From_Csv_Command {

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
	 * Create guest authors from a CSV file.
	 *
	 * The first row names the columns, which are matched against the guest author
	 * fields. Where a row gives a display name but no first or last name, the display
	 * name is split on the first space.
	 *
	 * ## OPTIONS
	 *
	 * --file=<file>
	 * : Path to the CSV file.
	 *
	 * ## EXAMPLES
	 *
	 *     # Import a list of contributors.
	 *     $ wp co-authors-plus create-guest-authors-from-csv --file=./authors.csv
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
			WP_CLI::error( 'Please specify a valid CSV file with the --file arg.' );
		}

		$file = fopen( $parsed_args['file'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fgetcsv() requires native PHP file handle

		if ( ! $file ) {
			WP_CLI::error( 'Failed to read file.' );
		}

		$authors = array();

		$row = 0;
		while ( false !== ( $data = fgetcsv( $file ) ) ) {
			if ( 0 === $row ) {
				$field_keys = array_map( 'trim', $data );
				// TODO: bail if required fields not found.
			} else {
				$row_data    = array_map( 'trim', $data );
				$author_data = array();
				foreach ( $row_data as $col_num => $val ) {
						// Don't use the value of the field key isn't set.
					if ( empty( $field_keys[ $col_num ] ) ) {
						continue;
					}
					$author_data[ $field_keys[ $col_num ] ] = $val;
				}

				$authors[] = $author_data;
			}
			$row++;
		}
		fclose( $file );

		WP_CLI::log(
			sprintf(
				/* translators: Count of authors. */
				_n(
					'Found %s author in CSV',
					'Found %s authors in CSV',
					count( $authors ),
					'co-authors-plus'
				),
				number_format_i18n( count( $authors ) )
			)
		);

		$failed = 0;

		foreach ( $authors as $author ) {
			WP_CLI::log( sprintf( 'Processing author %s (%s)', $author['user_login'], $author['user_email'] ) );

			$guest_author_data = array(
				'display_name' => sanitize_text_field( $author['display_name'] ),
				'user_login'   => sanitize_user( $author['user_login'] ),
				'user_email'   => sanitize_email( $author['user_email'] ),
				'website'      => sanitize_url( $author['website'] ),
				'description'  => wp_filter_post_kses( $author['description'] ),
				'avatar'       => absint( $author['avatar'] ),
			);

			$display_name_space_pos = strpos( $author['display_name'], ' ' );

			// Take whichever name columns the row supplies, and fall back to splitting
			// display_name only when it supplies neither. Requiring BOTH columns meant a
			// row carrying just one matched no branch at all and had its name discarded.
			if ( ! empty( $author['first_name'] ) || ! empty( $author['last_name'] ) ) {
				$guest_author_data['first_name'] = sanitize_text_field( $author['first_name'] );
				$guest_author_data['last_name']  = sanitize_text_field( $author['last_name'] );
			} elseif ( false !== $display_name_space_pos ) {
				$first_name = substr( $author['display_name'], 0, $display_name_space_pos );
				$last_name  = substr( $author['display_name'], ( $display_name_space_pos + 1 ) );

				$guest_author_data['first_name'] = sanitize_text_field( $first_name );
				$guest_author_data['last_name']  = sanitize_text_field( $last_name );
			}

			if ( ! $this->creator->create( $guest_author_data ) ) {
				++$failed;
			}
		}//end foreach

		if ( $failed > 0 ) {
			// A bad row does not abort the import, so say how many were dropped.
			WP_CLI::warning(
				sprintf(
					/* translators: 1: Count of failed authors. 2: Total count of authors. */
					_n(
						'%1$s of %2$s author could not be created.',
						'%1$s of %2$s authors could not be created.',
						count( $authors ),
						'co-authors-plus'
					),
					number_format_i18n( $failed ),
					number_format_i18n( count( $authors ) )
				)
			);
		}

		WP_CLI::log( 'All done!' );
	}
}
