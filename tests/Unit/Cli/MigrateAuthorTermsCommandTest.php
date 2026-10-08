<?php
/**
 * WordPress-free unit tests for the migrate-author-terms command's error guard.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Cli;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Automattic\CoAuthorsPlus\CLI\Migrate_Author_Terms_Command;
use Brain\Monkey\Functions;
use WP_CLI;
use WP_CLI\ExitException;
use WP_CLI\Loggers\Execution;

/**
 * The command halts when the taxonomy lookup itself fails.
 *
 * A get_terms() call returns a WP_Error when the taxonomy is not registered (for
 * example when the command runs before init, or on a site where the plugin did
 * not load). Without the guard the WP_Error fell through into array_filter()
 * and the migration loop. The Behat suite cannot reach this branch — the
 * taxonomy is always registered there — so it is pinned here with Brain Monkey.
 *
 * The real WP_CLI class is used (Composer loads it eagerly, so it cannot be
 * alias-mocked): its Execution logger captures output in memory, and its own
 * $capture_exit switch — the one runcommand() flips — makes error() throw
 * ExitException instead of exiting the test process.
 *
 * @covers \Automattic\CoAuthorsPlus\CLI\Migrate_Author_Terms_Command::__invoke
 */
final class MigrateAuthorTermsCommandTest extends TestCase {

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
	 * @param bool $capture Whether exits should be captured as ExitException.
	 */
	private function set_capture_exit( bool $capture ): void {
		$property = new \ReflectionProperty( WP_CLI::class, 'capture_exit' );
		$property->setAccessible( true );
		$property->setValue( null, $capture );
	}

	/**
	 * A failed term lookup is reported through WP_CLI::error() and nothing runs after it.
	 */
	public function test_invoke_errors_out_when_get_terms_fails(): void {
		$error = \Mockery::mock( 'WP_Error' );
		$error->shouldReceive( 'get_error_message' )->andReturn( 'Invalid taxonomy.' );

		Functions\when( 'get_terms' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			static fn ( $thing ): bool => $thing instanceof \WP_Error
		);

		$command = new Migrate_Author_Terms_Command( \Mockery::mock( \CoAuthors_Plus::class ) );

		try {
			$command( array(), array() );
			$this->fail( 'WP_CLI::error() should have halted the command when get_terms() failed.' );
		} catch ( ExitException $e ) {
			$this->assertSame( 1, $e->getCode(), 'The command should halt with exit code 1.' );
		}

		$this->assertStringContainsString( 'Invalid taxonomy.', $this->logger->stderr );
		$this->assertSame( '', $this->logger->stdout, 'Nothing should be logged once the guard has fired.' );
	}
}
