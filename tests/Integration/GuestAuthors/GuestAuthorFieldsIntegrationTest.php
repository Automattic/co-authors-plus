<?php
/**
 * Integration tests for CoAuthors_Guest_Authors::get_guest_author_fields().
 *
 * The unit-level field-config test lives in
 * tests/Unit/GuestAuthors/GuestAuthorFieldsTest.php; this class has a distinct
 * name to avoid a class collision and exercises the live guest authors object.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class GuestAuthorFieldsIntegrationTest extends TestCase {

	/**
	 * Checks the default field set (no group) and its shape.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_fields()
	 */
	public function test_get_guest_author_fields_returns_all_fields_by_default(): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$fields = $guest_author_obj->get_guest_author_fields();

		$this->assertNotEmpty( $fields );
		$this->assertIsArray( $fields );

		$keys = wp_list_pluck( $fields, 'key' );

		$global_fields = array(
			'display_name',
			'first_name',
			'last_name',
			'user_login',
			'user_email',
			'linked_account',
			'website',
			'description',
		);

		$this->assertEquals( $global_fields, $keys );
	}

	/**
	 * Checks the field keys returned for each field group.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_fields()
	 *
	 * @dataProvider data_field_groups
	 *
	 * @param string   $group         Requested field group.
	 * @param string[] $expected_keys Field keys expected for that group, in order.
	 */
	public function test_get_guest_author_fields_for_group( string $group, array $expected_keys ): void {

		global $coauthors_plus;

		$guest_author_obj = $coauthors_plus->guest_authors;

		$keys = wp_list_pluck( $guest_author_obj->get_guest_author_fields( $group ), 'key' );

		$this->assertEquals( $expected_keys, $keys );
	}

	/**
	 * Issue #317: a contact method added by a plugin like Yoast SEO is stored on
	 * the guest author, loaded back, and included in the personal data export.
	 *
	 * @covers CoAuthors_Guest_Authors::get_guest_author_fields()
	 * @covers CoAuthors_Guest_Authors::personal_data_exporter()
	 */
	public function test_user_contact_method_is_saved_loaded_and_exported(): void {

		global $coauthors_plus;

		add_filter(
			'user_contactmethods',
			static function ( $methods ) {
				$methods['twitter'] = 'X username (without @)';
				return $methods;
			}
		);

		$guest_author_obj = $coauthors_plus->guest_authors;

		$guest_author_id = $guest_author_obj->create(
			array(
				'display_name' => 'Social Guest',
				'user_login'   => 'social-guest',
				'user_email'   => 'social-guest@example.com',
				'twitter'      => 'socialguest',
			)
		);

		$guest_author = $guest_author_obj->get_guest_author_by( 'ID', $guest_author_id, true );
		$this->assertSame( 'socialguest', $guest_author->twitter );

		$export = $guest_author_obj->personal_data_exporter( 'social-guest@example.com' );
		$this->assertContains(
			array(
				'name'  => 'X username (without @)',
				'value' => 'socialguest',
			),
			$export['data'][0]['data']
		);
	}

	/**
	 * Provides field groups mapped to their expected field keys.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public function data_field_groups(): array {
		return array(
			'unknown group returns no fields' => array( 'test', array() ),
			'name group'                      => array( 'name', array( 'display_name', 'first_name', 'last_name' ) ),
			'slug group'                      => array( 'slug', array( 'user_login', 'linked_account' ) ),
			'contact-info group'              => array( 'contact-info', array( 'user_email', 'website' ) ),
			'about group'                     => array( 'about', array( 'description' ) ),
		);
	}
}
