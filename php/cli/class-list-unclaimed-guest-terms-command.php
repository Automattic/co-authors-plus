<?php
/**
 * The list-unclaimed-guest-terms WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use Automattic\CoAuthorsPlus\Services\Guest_Author_Term_Report_Service;
use WP_CLI;

/**
 * Reports guest authors who have no usable author term.
 *
 * Behaviour is pinned by features/list-unclaimed-guest-terms.feature.
 */
class List_Unclaimed_Guest_Terms_Command {

	/**
	 * The fields shown unless --fields or --field asks for others.
	 *
	 * @var string[]
	 */
	private const DEFAULT_FIELDS = array(
		'ID',
		'user_login',
		'status',
		'linked_account',
		'resolved_term',
		'matching_term',
		'matching_term_owner',
		'matching_term_count',
		'doubled_term',
		'doubled_term_count',
	);

	/**
	 * The report.
	 *
	 * @var Guest_Author_Term_Report_Service
	 */
	private $report;

	/**
	 * Constructor.
	 *
	 * @param Guest_Author_Term_Report_Service $report The report.
	 */
	public function __construct( Guest_Author_Term_Report_Service $report ) {
		$this->report = $report;
	}

	/**
	 * Report guest authors who have no usable author term.
	 *
	 * This is a report. It changes nothing, and a row doesn't mean a login is
	 * wrong: a login that starts with cap- can be legitimate, such as a guest
	 * author named "Cap Ri". Trashed guest authors aren't checked.
	 *
	 * Each row has one of these statuses:
	 *
	 * * no-term: the guest author resolves to no term.
	 * * split: the guest author resolves to a doubled term, and posts are still on
	 * the term that matches their nicename.
	 * * term-owned-by-other: the guest author resolves to no term, and the term
	 * that matches their nicename belongs to someone else.
	 *
	 * ## OPTIONS
	 *
	 * [--records-per-batch=<number>]
	 * : How many guest authors to check per batch, as a positive integer.
	 * Defaults to 100.
	 *
	 * [--field=<field>]
	 * : Print one field for each guest author.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to show.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 *   - yaml
	 * ---
	 *
	 * ## AVAILABLE FIELDS
	 *
	 * These fields are shown by default:
	 *
	 * * ID
	 * * user_login
	 * * status
	 * * linked_account
	 * * resolved_term
	 * * matching_term
	 * * matching_term_owner
	 * * matching_term_count
	 * * doubled_term
	 * * doubled_term_count
	 *
	 * These fields are optionally available:
	 *
	 * * display_name
	 * * matching_term_name
	 * * matching_term_objects
	 * * doubled_term_objects
	 *
	 * The count fields cover published posts. The objects fields cover everything
	 * attached to the term except the guest author's own profile.
	 *
	 * ## EXAMPLES
	 *
	 *     # Report every affected guest author.
	 *     $ wp co-authors-plus list-unclaimed-guest-terms
	 *
	 *     # Save the report, including what's on each term.
	 *     $ wp co-authors-plus list-unclaimed-guest-terms --format=csv --fields=ID,user_login,status,matching_term,matching_term_objects > report.csv
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$records_per_batch = filter_var(
			$assoc_args['records-per-batch'] ?? 100,
			FILTER_VALIDATE_INT,
			array( 'options' => array( 'min_range' => 1 ) )
		);

		if ( false === $records_per_batch ) {
			WP_CLI::error( '--records-per-batch must be a positive integer.' );
		}

		// Everything but the table is read by scripts, so it gets only the data.
		// Decided here because the formatter removes these arguments as it reads them.
		$show_summary = 'table' === ( $assoc_args['format'] ?? 'table' ) && ! isset( $assoc_args['field'] );

		$rows = $this->get_rows( $records_per_batch );

		if ( $show_summary && empty( $rows ) ) {
			WP_CLI::log( 'No guest authors to report.' );
			return;
		}

		$formatter = new \WP_CLI\Formatter( $assoc_args, self::DEFAULT_FIELDS );
		$formatter->display_items( $rows );

		if ( $show_summary ) {
			WP_CLI::log( 'This is a report. Nothing was changed, and a row does not mean a login is wrong.' );
		}
	}

	/**
	 * Walk every guest author in batches.
	 *
	 * @param int $records_per_batch Guest authors per batch.
	 * @return array<int, array<string, int|string>>
	 */
	private function get_rows( int $records_per_batch ): array {
		$rows = array();
		$page = 1;

		do {
			$result = $this->report->get_page( $page, $records_per_batch );
			$rows   = array_merge( $rows, $result['rows'] );

			\WP_CLI\Utils\wp_clear_object_cache();

			++$page;
		} while ( $result['checked'] === $records_per_batch );

		return $rows;
	}
}
