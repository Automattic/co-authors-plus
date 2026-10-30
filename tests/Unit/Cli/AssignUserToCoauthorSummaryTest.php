<?php
/**
 * WordPress-free unit test for the assign-user-to-coauthor summary formatting.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Cli;

use Automattic\CoAuthorsPlus\CLI\Assign_User_To_Coauthor_Command;
use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use Mockery;
use WP_CLI;
use WP_CLI\Loggers\Execution;

/**
 * The summary interpolates number_format_i18n() output, which is a
 * thousands-separated STRING above 999 — so the placeholder must be %s. The
 * suite cannot stage a thousand posts, so this pins the formatting contract
 * instead: a formatter stubbed to return "1,234" must survive into the output
 * verbatim. Under the old %d placeholder, sprintf truncates "1,234" to "1"
 * and this test fails.
 *
 * @covers \Automattic\CoAuthorsPlus\CLI\Assign_User_To_Coauthor_Command::__invoke
 */
final class AssignUserToCoauthorSummaryTest extends TestCase {

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
	}

	public function tear_down(): void {
		WP_CLI::set_logger( null );
		unset( $GLOBALS['wpdb'] );

		parent::tear_down();
	}

	public function test_summary_preserves_a_thousands_separated_count(): void {
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === $number ? $single : $plural;
			}
		);
		// Deliberately a lie for a count of zero: the point is that whatever
		// string the formatter returns must reach the output uncast.
		Functions\when( 'number_format_i18n' )->justReturn( '1,234' );

		$wpdb        = Mockery::mock( 'wpdb' );
		$wpdb->posts = 'wp_posts';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT 1' );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		$coauthors_plus = Mockery::mock( 'CoAuthors_Plus' );
		$coauthors_plus->shouldReceive( 'get_coauthor_by' )
			->with( 'login', 'jane' )
			->andReturn( (object) array( 'user_login' => 'jane' ) );
		$coauthors_plus->shouldReceive( 'supported_post_types' )->andReturn( array( 'post' ) );

		$command = new Assign_User_To_Coauthor_Command( $coauthors_plus );
		$command(
			array(),
			array(
				'user_id'  => '5',
				'coauthor' => 'jane',
			)
		);

		$this->assertStringContainsString(
			'1,234 posts were affected.',
			$this->logger->stdout,
			'The formatted count must survive sprintf intact; %d would truncate it to 1.'
		);
	}
}
