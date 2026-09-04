<?php
/**
 * Unit tests for the guest author service.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\Services;

use Automattic\CoAuthorsPlus\Services\Guest_Author_Service;
use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @covers \Automattic\CoAuthorsPlus\Services\Guest_Author_Service
 */
final class GuestAuthorServiceTest extends TestCase {

	/**
	 * Field definitions standing in for get_guest_author_fields().
	 *
	 * Mirrors the real shape: most fields carry no sanitiser, description
	 * declares one, and the hidden ID field is already excluded.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function fields(): array {
		return array(
			array(
				'key'   => 'display_name',
				'group' => 'name',
			),
			array(
				'key'   => 'user_login',
				'group' => 'slug',
			),
			array(
				'key'               => 'description',
				'group'             => 'about',
				'sanitize_function' => 'wp_filter_post_kses',
			),
		);
	}

	/**
	 * Build a service with a mocked guest authors instance.
	 *
	 * @return array{0: Guest_Author_Service, 1: \Mockery\MockInterface}
	 */
	private function service(): array {
		$guest_authors = Mockery::mock( 'CoAuthors_Guest_Authors' );
		$guest_authors->shouldReceive( 'get_guest_author_fields' )->andReturn( $this->fields() );

		return array( new Guest_Author_Service( $guest_authors ), $guest_authors );
	}

	public function test_it_reads_a_profile_for_every_declared_field(): void {
		list( $service ) = $this->service();

		$guest_author = (object) array(
			'display_name' => 'Jane Doe',
			'user_login'   => 'jane-doe',
			'description'  => 'Writes things.',
			'ID'           => 42,
		);

		$this->assertSame(
			array(
				'display_name' => 'Jane Doe',
				'user_login'   => 'jane-doe',
				'description'  => 'Writes things.',
			),
			$service->profile( $guest_author )
		);
	}

	/**
	 * A field the object does not carry must still appear, so the exported
	 * shape does not vary between profiles.
	 */
	public function test_it_reads_an_absent_field_as_an_empty_string(): void {
		list( $service ) = $this->service();

		$profile = $service->profile( (object) array( 'display_name' => 'Jane Doe' ) );

		$this->assertSame( '', $profile['description'] );
		$this->assertArrayHasKey( 'user_login', $profile );
	}

	/**
	 * The admin save applies each field's declared sanitiser and falls back to
	 * sanitize_text_field. Creating a profile has to match, or the same values
	 * would be stored differently depending on which route wrote them.
	 */
	public function test_it_applies_each_fields_declared_sanitiser(): void {
		list( $service, $guest_authors ) = $this->service();

		Functions\expect( 'sanitize_text_field' )->twice()->andReturnUsing(
			static function ( $value ) {
				return 'clean:' . $value;
			}
		);
		Functions\expect( 'wp_filter_post_kses' )->once()->andReturnUsing(
			static function ( $value ) {
				return 'kses:' . $value;
			}
		);

		$guest_authors->shouldReceive( 'create' )
			->once()
			->with(
				array(
					'display_name' => 'clean:Jane Doe',
					'user_login'   => 'clean:jane-doe',
					'description'  => 'kses:<b>Hi</b>',
				)
			)
			->andReturn( 42 );

		$this->assertSame(
			42,
			$service->create(
				array(
					'display_name' => 'Jane Doe',
					'user_login'   => 'jane-doe',
					'description'  => '<b>Hi</b>',
				)
			)
		);
	}

	/**
	 * A profile from a newer version of the plugin must not create meta this
	 * one knows nothing about.
	 */
	public function test_it_ignores_keys_that_are_not_declared_fields(): void {
		list( $service, $guest_authors ) = $this->service();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_filter_post_kses' )->returnArg();

		$guest_authors->shouldReceive( 'create' )
			->once()
			->with(
				array(
					'display_name' => 'Jane Doe',
					'user_login'   => 'jane-doe',
					'description'  => '',
				)
			)
			->andReturn( 42 );

		$service->create(
			array(
				'display_name'   => 'Jane Doe',
				'user_login'     => 'jane-doe',
				'description'    => '',
				'future_feature' => 'should not be stored',
			)
		);
	}

	/**
	 * Fields absent from the profile are left out entirely rather than sent as
	 * empty strings, so create() applies its own defaults.
	 */
	public function test_it_omits_fields_the_profile_does_not_carry(): void {
		list( $service, $guest_authors ) = $this->service();

		Functions\when( 'sanitize_text_field' )->returnArg();

		$guest_authors->shouldReceive( 'create' )
			->once()
			->with( array( 'user_login' => 'jane-doe' ) )
			->andReturn( 42 );

		$service->create( array( 'user_login' => 'jane-doe' ) );
	}

	public function test_it_finds_a_guest_author_by_field(): void {
		list( $service, $guest_authors ) = $this->service();

		$expected = (object) array( 'user_login' => 'jane-doe' );

		$guest_authors->shouldReceive( 'get_guest_author_by' )
			->once()
			->with( 'user_login', 'jane-doe' )
			->andReturn( $expected );

		$this->assertSame( $expected, $service->find_by( 'user_login', 'jane-doe' ) );
	}
}
