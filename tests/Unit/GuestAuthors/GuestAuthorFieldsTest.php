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

/**
 * @covers \CoAuthors_Guest_Authors::get_guest_author_fields
 */
final class GuestAuthorFieldsTest extends TestCase {

	/**
	 * Guest authors instance under test.
	 *
	 * @var CoAuthors_Guest_Authors
	 */
	private CoAuthors_Guest_Authors $guest_authors;

	protected function set_up(): void {
		parent::set_up();

		// Since #1316 the constructor registers no hooks, so it is safe to build directly.
		$this->guest_authors = new CoAuthors_Guest_Authors();

		Functions\when( '__' )->returnArg();
		// apply_filters( $tag, $fields, $groups ) returns the fields unchanged.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// No plugin adds contact methods unless a test says so.
		Functions\when( 'wp_get_user_contact_methods' )->justReturn( array() );
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

	/**
	 * Issue #317: contact methods added by plugins such as Yoast SEO show up on
	 * guest authors too, but never replace a field we already define.
	 */
	public function test_user_contact_methods_are_added_to_contact_info(): void {
		Functions\when( 'wp_get_user_contact_methods' )->justReturn(
			array(
				'twitter' => 'X username (without @)',
				'website' => 'Should not replace our Website field',
			)
		);

		$fields = $this->guest_authors->get_guest_author_fields( 'contact-info' );

		$this->assertSame( array( 'user_email', 'website', 'twitter' ), array_column( $fields, 'key' ) );
		$this->assertSame( 'Website', $fields[1]['label'] );
		$this->assertSame( 'sanitize_url', $fields[1]['sanitize_function'] );
		$this->assertSame(
			array(
				'key'   => 'twitter',
				'label' => 'X username (without @)',
				'group' => 'contact-info',
			),
			$fields[2]
		);
	}

	/**
	 * A site that already adds 'twitter' through the coauthors_guest_author_fields
	 * filter keeps its own definition, and the field is listed only once.
	 */
	public function test_filter_field_replaces_contact_method_with_same_key(): void {
		Functions\when( 'wp_get_user_contact_methods' )->justReturn( array( 'twitter' => 'X username (without @)' ) );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $fields ) {
				$fields[] = array(
					'key'               => 'twitter',
					'label'             => 'Twitter',
					'group'             => 'contact-info',
					'sanitize_function' => 'sanitize_key',
				);
				return $fields;
			}
		);

		$fields = $this->guest_authors->get_guest_author_fields( 'contact-info' );

		$this->assertSame( array( 'user_email', 'website', 'twitter' ), array_column( $fields, 'key' ) );
		$this->assertSame( 'Twitter', $fields[2]['label'] );
		$this->assertSame( 'sanitize_key', $fields[2]['sanitize_function'] );
	}

	public function test_every_field_has_a_key_and_a_label(): void {
		foreach ( $this->guest_authors->get_guest_author_fields( 'all' ) as $field ) {
			$this->assertArrayHasKey( 'key', $field );
			$this->assertArrayHasKey( 'label', $field );
		}
	}
}
