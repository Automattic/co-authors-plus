<?php
/**
 * WordPress-free unit tests for the check command's argument handling.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Cli;

use Automattic\CoAuthorsPlus\CLI\Check_Command;
use Automattic\CoAuthorsPlus\Services\Coauthor_Checks_Service;
use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use WP_CLI;
use WP_CLI\ExitException;
use WP_CLI\Loggers\Execution;

/**
 * The command validates --only before asking the service to run.
 *
 * A typo in a runbook that silently ran every check instead of the one named
 * would be worse than failing, so an unknown name is an error rather than a
 * filter that matches nothing. The service is mocked: what is under test here is
 * the parsing and the error, not the checks themselves, which the Behat suite
 * exercises against a real installation.
 *
 * The real WP_CLI class is used (Composer loads it eagerly, so it cannot be
 * alias-mocked): its Execution logger captures output in memory, and its own
 * $capture_exit switch makes error() throw ExitException instead of exiting the
 * test process.
 *
 * @covers \Automattic\CoAuthorsPlus\CLI\Check_Command::__invoke
 */
final class CheckCommandTest extends TestCase {

	/**
	 * In-memory logger capturing what the command writes.
	 *
	 * @var Execution
	 */
	private $logger;

	public function set_up(): void {
		parent::set_up();

		$this->logger = new Execution();
		WP_CLI::set_logger( $this->logger );
		$this->set_capture_exit( true );
	}

	public function tear_down(): void {
		$this->set_capture_exit( false );
		WP_CLI::set_logger( null );

		parent::tear_down();
	}

	/**
	 * Flip WP_CLI's private $capture_exit switch so error() throws instead of exiting.
	 *
	 * The setAccessible() call is required to reach a private static property on
	 * PHP 7.4, the lowest version the plugin supports, but is deprecated from
	 * 8.1 where reflection no longer needs it. Calling it unconditionally would
	 * fail this suite on 8.5.
	 *
	 * @param bool $capture Whether exits should be captured as ExitException.
	 */
	private function set_capture_exit( bool $capture ): void {
		$property = new \ReflectionProperty( WP_CLI::class, 'capture_exit' );

		if ( \PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		$property->setValue( null, $capture );
	}

	/**
	 * A service that records the checks it was asked for.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows the service should return.
	 * @param string[]                         $run  Receives the `$only` argument.
	 * @return Coauthor_Checks_Service
	 */
	private function service_recording_run( array $rows, array &$run ): Coauthor_Checks_Service {
		$service = \Mockery::mock( Coauthor_Checks_Service::class );
		$service->shouldReceive( 'run' )
			->once()
			->andReturnUsing(
				static function ( $only ) use ( $rows, &$run ) {
					$run[] = $only;
					return $rows;
				}
			);

		return $service;
	}

	/**
	 * An unknown check name halts with an error naming it.
	 */
	public function test_unknown_check_name_errors_out(): void {
		$service = \Mockery::mock( Coauthor_Checks_Service::class );
		$service->shouldNotReceive( 'run' );

		$command = new Check_Command( $service );

		try {
			$command( array(), array( 'only' => 'no-such-check' ) );
			$this->fail( 'WP_CLI::error() should have halted the command for an unknown check name.' );
		} catch ( ExitException $e ) {
			$this->assertSame( 1, $e->getCode(), 'The command should halt with exit code 1.' );
		}

		$this->assertStringContainsString( 'Unknown check(s): no-such-check', $this->logger->stderr );
	}

	/**
	 * Only the names given are passed through to the service.
	 *
	 * --format=count is used because it is the one format that renders without
	 * WP_CLI\Utils\pick_fields(), which is not loaded in the WordPress-free unit
	 * environment. Output is buffered so the direct echo is not flagged as risky.
	 */
	public function test_valid_only_list_is_passed_through(): void {
		$only    = array();
		$service = $this->service_recording_run(
			array(
				array(
					'check'   => 'unprefixed-terms',
					'status'  => 'ok',
					'count'   => 0,
					'summary' => 'Nothing to see.',
				),
			),
			$only
		);

		$command = new Check_Command( $service );
		$args    = array(
			'only'   => 'unprefixed-terms, revision-terms',
			'format' => 'count',
		);

		ob_start();
		$command( array(), $args );
		$output = (string) ob_get_clean();

		$this->assertSame(
			array( array( 'unprefixed-terms', 'revision-terms' ) ),
			$only,
			'The trimmed names should reach the service in the order given.'
		);
		$this->assertSame( '1', $output, 'One row was returned, so count renders it.' );
	}

	/**
	 * With no --only, the service runs every check.
	 *
	 * --format=count for the same reason as the test above.
	 */
	public function test_no_only_runs_every_check(): void {
		$only    = array();
		$service = $this->service_recording_run( array(), $only );

		$command = new Check_Command( $service );

		ob_start();
		$command( array(), array( 'format' => 'count' ) );
		ob_end_clean();

		$this->assertSame(
			array( array() ),
			$only,
			'An empty list is what the service takes to mean "all checks".'
		);
	}
}
