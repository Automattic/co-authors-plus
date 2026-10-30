<?php
/**
 * WordPress-free unit tests for the shared guest author creator's sanitisation.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Cli;

use Automattic\CoAuthorsPlus\CLI\Guest_Author_Creator;
use Automattic\CoAuthorsPlus\Services\Guest_Author_Service;
use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use Mockery;
use WP_CLI;
use WP_CLI\Loggers\Execution;

/**
 * The creator sanitises the way the admin edit screen does, before its own
 * duplicate lookups and before CoAuthors_Guest_Authors::create() runs its
 * collision guard, so both vet the value that will actually be stored — while
 * the provenance meta keeps the login exactly as the source supplied it.
 *
 * @covers \Automattic\CoAuthorsPlus\CLI\Guest_Author_Creator::create
 */
final class GuestAuthorCreatorTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		WP_CLI::set_logger( new Execution() );
	}

	public function tear_down(): void {
		WP_CLI::set_logger( null );

		parent::tear_down();
	}

	/**
	 * One mocked guest authors instance backs both the service and the creator.
	 *
	 * @return array{0: Guest_Author_Creator, 1: \Mockery\MockInterface}
	 */
	private function creator(): array {
		$guest_authors = Mockery::mock( 'CoAuthors_Guest_Authors' );
		$guest_authors->shouldReceive( 'get_guest_author_fields' )->andReturn(
			array(
				array(
					'key'   => 'display_name',
					'group' => 'name',
				),
				array(
					'key'   => 'user_login',
					'group' => 'slug',
				),
				array(
					'key'   => 'user_email',
					'group' => 'contact-info',
				),
			)
		);

		$coauthors_plus                = Mockery::mock( 'CoAuthors_Plus' );
		$coauthors_plus->guest_authors = $guest_authors;

		return array(
			new Guest_Author_Creator( $coauthors_plus, new Guest_Author_Service( $guest_authors ) ),
			$guest_authors,
		);
	}

	/**
	 * Fails against the unsanitised creator: without the sanitise pass the
	 * clean: prefixes disappear from every expectation below, the duplicate
	 * lookups receive the raw values, and the provenance assertion flips.
	 */
	public function test_it_sanitises_before_deduping_and_creating_but_keeps_raw_provenance(): void {
		list( $creator, $guest_authors ) = $this->creator();

		Functions\expect( 'sanitize_text_field' )->times( 3 )->andReturnUsing(
			static function ( $value ) {
				return 'clean:' . $value;
			}
		);

		$guest_authors->shouldReceive( 'get_guest_author_by' )
			->once()
			->with( 'user_email', 'clean:jane@example.com', true )
			->andReturn( false );
		$guest_authors->shouldReceive( 'get_guest_author_by' )
			->once()
			->with( 'user_login', 'clean:<b>Jane</b>', true )
			->andReturn( false );

		// avatar is not a declared field, so it must arrive untouched.
		$guest_authors->shouldReceive( 'create' )
			->once()
			->with(
				array(
					'display_name' => 'clean:Jane Doe',
					'user_login'   => 'clean:<b>Jane</b>',
					'user_email'   => 'clean:jane@example.com',
					'avatar'       => 123,
				)
			)
			->andReturn( 42 );

		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'esc_html__' )->andReturnFirstArg();
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_original_author_login', '<b>Jane</b>' );

		$this->assertTrue(
			$creator->create(
				array(
					'display_name' => 'Jane Doe',
					'user_login'   => '<b>Jane</b>',
					'user_email'   => 'jane@example.com',
					'avatar'       => 123,
				)
			)
		);
	}
}
