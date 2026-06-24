<?php
/**
 * Unit tests for CoAuthors_Guest_Authors::get_guest_author_fields().
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Unit\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use CoAuthors_Guest_Authors;
use ReflectionClass;

/**
 * @covers \CoAuthors_Guest_Authors::get_guest_author_fields
 */
final class GuestAuthorFieldsTest extends TestCase {

	/**
	 * Guest authors instance under test, built without its hook-registering constructor.
	 *
	 * @var CoAuthors_Guest_Authors
	 */
	private CoAuthors_Guest_Authors $guest_authors;

	protected function set_up(): void {
		parent::set_up();

		$this->guest_authors = ( new ReflectionClass( CoAuthors_Guest_Authors::class ) )->newInstanceWithoutConstructor();

		Functions\when( '__' )->returnArg();
		// apply_filters( $tag, $fields, $groups ) returns the fields unchanged.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * @dataProvider provide_groups
	 *
	 * @param string   $group         Requested field group.
	 * @param string[] $expected_keys Field keys expected for that group, in order.
	 */
	public function test_returns_the_fields_for_a_group( string $group, array $expected_keys ): void {
		$keys = array_column( $this->guest_authors->get_guest_author_fields( $group ), 'key' );

		$this->assertSame( $expected_keys, $keys );
	}

	public static function provide_groups(): iterable {
		yield 'name'         => array( 'name', array( 'display_name', 'first_name', 'last_name' ) );
		yield 'slug'         => array( 'slug', array( 'user_login', 'linked_account' ) );
		yield 'contact-info' => array( 'contact-info', array( 'user_email', 'website' ) );
		yield 'about'        => array( 'about', array( 'description' ) );
	}

	public function test_all_returns_every_field_except_hidden(): void {
		$keys = array_column( $this->guest_authors->get_guest_author_fields( 'all' ), 'key' );

		$this->assertNotContains( 'ID', $keys, 'The hidden ID field must be excluded from the "all" group.' );
		$this->assertSame(
			array( 'display_name', 'first_name', 'last_name', 'user_login', 'user_email', 'linked_account', 'website', 'description' ),
			$keys
		);
	}

	public function test_every_field_has_a_key_and_a_label(): void {
		foreach ( $this->guest_authors->get_guest_author_fields( 'all' ) as $field ) {
			$this->assertArrayHasKey( 'key', $field );
			$this->assertArrayHasKey( 'label', $field );
		}
	}
}
