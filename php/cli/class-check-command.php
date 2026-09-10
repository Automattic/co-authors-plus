<?php
/**
 * The check WP-CLI command.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use Automattic\CoAuthorsPlus\Services\Coauthor_Checks_Service;
use WP_CLI;
use WP_CLI\Formatter;

/**
 * Reports where the co-author term index and the bylines agree.
 *
 * Read-only, and a report rather than a gate: it always exits 0, so a cron job
 * or a monitoring run is not failed by finding drift. Consumers read the
 * machine-readable formats, and decide what a finding means for them.
 *
 * Each check corresponds to a condition one of the fixer commands can repair.
 * Nothing here writes, so it is safe to run on production at any time.
 */
class Check_Command {

	/**
	 * Checks to run.
	 *
	 * @var Coauthor_Checks_Service
	 */
	private $checks;

	/**
	 * Constructor.
	 *
	 * @param Coauthor_Checks_Service $checks Check service.
	 */
	public function __construct( Coauthor_Checks_Service $checks ) {
		$this->checks = $checks;
	}

	/**
	 * Check the co-author term index for the conditions the fixer commands repair.
	 *
	 * ## OPTIONS
	 *
	 * [--only=<checks>]
	 * : Comma-separated list of checks to run. Defaults to all of them. An
	 * unknown name is rejected. The names are listed by the error message, and
	 * are checked against Services\Coauthor_Checks_Service::CHECKS, so this
	 * description does not have to be kept in step with a second list.
	 *
	 * [--field=<field>]
	 * : Print one field for each check.
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
	 * ## EXAMPLES
	 *
	 *     # Run every check.
	 *     $ wp co-authors-plus check
	 *
	 *     # The machine-readable audit for monitoring.
	 *     $ wp co-authors-plus check --format=json
	 *
	 *     # Just the two backfill-related checks.
	 *     $ wp co-authors-plus check --only=posts-missing-terms,orphaned-skip-markers
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$only = $this->requested_checks( $assoc_args['only'] ?? '' );

		$rows = $this->checks->run( $only );

		$formatter = new Formatter( $assoc_args, array( 'check', 'status', 'count', 'summary' ) );
		$formatter->display_items( $rows );
	}

	/**
	 * Parse and validate the --only list.
	 *
	 * An unknown name is reported rather than ignored: a typo in a runbook that
	 * silently ran every check instead of the one named would be worse than
	 * failing.
	 *
	 * @param string $only Comma-separated check names, or an empty string.
	 * @return string[] Validated check names, or an empty array for "all".
	 */
	private function requested_checks( string $only ): array {
		if ( '' === trim( $only ) ) {
			return array();
		}

		$requested = array_filter( array_map( 'trim', explode( ',', $only ) ) );
		$unknown   = array_diff( $requested, Coauthor_Checks_Service::CHECKS );

		if ( ! empty( $unknown ) ) {
			WP_CLI::error(
				sprintf(
					'Unknown check(s): %1$s. Available checks: %2$s.',
					implode( ', ', $unknown ),
					implode( ', ', Coauthor_Checks_Service::CHECKS )
				)
			);
		}

		return $requested;
	}
}
