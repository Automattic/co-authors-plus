<?php
/**
 * Tests that the guest author edit screen fields have associated labels.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use DOMDocument;
use DOMXPath;

/**
 * Every visible field on the guest author edit screen needs a label whose
 * `for` matches the field's `id`, so screen readers announce the field's name
 * and clicking the label focuses the field.
 *
 * @covers CoAuthors_Guest_Authors::metabox_manage_guest_author_slug
 * @covers CoAuthors_Guest_Authors::metabox_manage_guest_author_name
 * @covers CoAuthors_Guest_Authors::metabox_manage_guest_author_contact_info
 * @covers CoAuthors_Guest_Authors::metabox_manage_guest_author_bio
 */
class GuestAuthorMetaboxLabelsTest extends TestCase {

	public function set_up() {
		parent::set_up();

		global $post;

		$post = get_post( $this->create_guest_author( 'galabels' ) );
	}

	public function tear_down() {
		global $post;
		$post = null;
		parent::tear_down();
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function data_metaboxes(): array {
		return array(
			'slug'         => array( 'metabox_manage_guest_author_slug' ),
			'name'         => array( 'metabox_manage_guest_author_name' ),
			'contact info' => array( 'metabox_manage_guest_author_contact_info' ),
			'bio'          => array( 'metabox_manage_guest_author_bio' ),
		);
	}

	/**
	 * @dataProvider data_metaboxes
	 */
	public function test_every_field_has_an_associated_label( string $metabox ): void {
		$this->assert_fields_are_labelled( $this->render( $metabox ) );
	}

	/**
	 * Fields switched to a checkbox with the per-field input type filter keep
	 * their label.
	 */
	public function test_checkbox_fields_have_an_associated_label(): void {
		add_filter( 'coauthors_name_field_type_cap-display_name', static fn() => 'checkbox' );
		add_filter( 'coauthors_name_field_type_cap-website', static fn() => 'checkbox' );

		$this->assert_fields_are_labelled( $this->render( 'metabox_manage_guest_author_name' ) );
		$this->assert_fields_are_labelled( $this->render( 'metabox_manage_guest_author_contact_info' ) );
	}

	/**
	 * The User Mapping label follows the select when a site renames it with
	 * the linked account args filter.
	 */
	public function test_user_mapping_label_follows_filtered_select_id(): void {
		add_filter(
			'coauthors_guest_author_linked_account_args',
			static function ( array $args ): array {
				$args['id'] = 'custom-linked-account';
				return $args;
			}
		);

		$html = $this->render( 'metabox_manage_guest_author_slug' );

		$this->assertStringContainsString( '<label for="custom-linked-account">', $html );
		$this->assert_fields_are_labelled( $html );
	}

	private function render( string $metabox ): string {
		global $coauthors_plus;

		ob_start();
		$coauthors_plus->guest_authors->$metabox();
		return (string) ob_get_clean();
	}

	private function assert_fields_are_labelled( string $html ): void {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		$fields = $xpath->query( '//input[not(@type="hidden")] | //select | //textarea' );
		$this->assertGreaterThan( 0, $fields->length, 'The meta box should render at least one field.' );

		foreach ( $fields as $field ) {
			$name = $field->getAttribute( 'name' );
			$id   = $field->getAttribute( 'id' );

			$this->assertNotSame( '', $id, "The {$name} field should have an id." );
			$this->assertSame(
				1,
				$xpath->query( '//label[@for="' . $id . '"]' )->length,
				"The {$name} field should have exactly one label pointing at it."
			);
		}

		foreach ( $xpath->query( '//label[@for]' ) as $label ) {
			$for = $label->getAttribute( 'for' );
			$this->assertSame(
				1,
				$xpath->query( '//*[@id="' . $for . '"]' )->length,
				"The label for {$for} should point at exactly one element."
			);
		}
	}
}
