<?php
/**
 * Tests for the guest author personal data exporter.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * The exporter must cover every field a guest author profile stores, under the
 * same labels the profile screen shows, including fields a site adds through
 * the coauthors_guest_author_fields filter.
 *
 * @covers \CoAuthors_Guest_Authors::personal_data_exporter
 */
class PersonalDataExporterTest extends TestCase {

	private const EMAIL = 'pat@example.com';

	/**
	 * Every stored field is exported, in field order, under its field label.
	 */
	public function test_exports_every_stored_field_under_its_label(): void {
		$this->factory()->user->create( array( 'user_login' => 'pat-account' ) );

		$id = $this->_cap->guest_authors->create(
			array(
				'display_name'   => 'Pat Smith',
				'first_name'     => 'Pat',
				'last_name'      => 'Smith',
				'user_login'     => 'pat-smith',
				'user_email'     => self::EMAIL,
				'linked_account' => 'pat-account',
				'website'        => 'https://example.com/pat',
				'description'    => 'Writes about birds.',
			)
		);
		$this->assertIsInt( $id );

		$this->assertSame(
			array(
				array(
					'name'  => 'ID',
					'value' => (string) $id,
				),
				array(
					'name'  => 'Display Name',
					'value' => 'Pat Smith',
				),
				array(
					'name'  => 'First Name',
					'value' => 'Pat',
				),
				array(
					'name'  => 'Last Name',
					'value' => 'Smith',
				),
				array(
					'name'  => 'Slug',
					'value' => 'pat-smith',
				),
				array(
					'name'  => 'E-mail',
					'value' => self::EMAIL,
				),
				array(
					'name'  => 'Linked Account',
					'value' => 'pat-account',
				),
				array(
					'name'  => 'Website',
					'value' => 'https://example.com/pat',
				),
				array(
					'name'  => 'Biographical Info',
					'value' => 'Writes about birds.',
				),
			),
			$this->export_data()
		);
	}

	/**
	 * A field added through coauthors_guest_author_fields is exported too, and
	 * empty fields are left out.
	 */
	public function test_exports_filtered_in_field_and_skips_empty_ones(): void {
		add_filter( 'coauthors_guest_author_fields', array( $this, 'add_phone_field' ) );

		$id = $this->_cap->guest_authors->create(
			array(
				'display_name' => 'Pat Smith',
				'user_login'   => 'pat-smith',
				'user_email'   => self::EMAIL,
				'phone'        => '01632 960001',
			)
		);
		$this->assertIsInt( $id );

		$this->assertSame(
			array(
				array(
					'name'  => 'ID',
					'value' => (string) $id,
				),
				array(
					'name'  => 'Display Name',
					'value' => 'Pat Smith',
				),
				array(
					'name'  => 'Slug',
					'value' => 'pat-smith',
				),
				array(
					'name'  => 'E-mail',
					'value' => self::EMAIL,
				),
				array(
					'name'  => 'Phone',
					'value' => '01632 960001',
				),
			),
			$this->export_data()
		);
	}

	/**
	 * Data from coauthors_guest_author_personal_export_extra_data is appended.
	 */
	public function test_appends_extra_data_from_filter(): void {
		$id = $this->_cap->guest_authors->create(
			array(
				'display_name' => 'Pat Smith',
				'user_login'   => 'pat-smith',
				'user_email'   => self::EMAIL,
			)
		);
		$this->assertIsInt( $id );

		$extra = array(
			'name'  => 'Newsletter',
			'value' => 'Subscribed',
		);
		add_filter(
			'coauthors_guest_author_personal_export_extra_data',
			static function ( $data, $author_id, $email ) use ( $extra, $id ) {
				return ( (int) $author_id === $id && self::EMAIL === $email ) ? array( $extra ) : $data;
			},
			10,
			3
		);

		$data = $this->export_data();

		$this->assertSame( $extra, end( $data ) );
	}

	/**
	 * An address with no guest author exports nothing.
	 */
	public function test_unknown_email_exports_nothing(): void {
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$this->_cap->guest_authors->personal_data_exporter( 'nobody@example.com' )
		);
	}

	/**
	 * Adds a contact field, as a site might through coauthors_guest_author_fields.
	 *
	 * @param array $fields Guest author fields.
	 * @return array
	 */
	public function add_phone_field( $fields ) {
		$fields[] = array(
			'key'   => 'phone',
			'label' => 'Phone',
			'group' => 'contact-info',
		);
		return $fields;
	}

	/**
	 * Runs the exporter for the test address and returns the guest author's rows.
	 *
	 * @return array<int, array{name: string, value: string}>
	 */
	private function export_data(): array {
		$export = $this->_cap->guest_authors->personal_data_exporter( self::EMAIL );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'cap-guest-author', $export['data'][0]['group_id'] );

		return $export['data'][0]['data'];
	}
}
