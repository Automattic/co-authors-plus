<?php
/**
 * Reads and writes guest author profiles.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Services;

use CoAuthors_Guest_Authors;
use WP_Error;

/**
 * Reads and writes guest author profiles.
 *
 * Both halves are driven by get_guest_author_fields() rather than a hard-coded
 * list, which means a field added through the coauthors_guest_author_fields
 * filter is carried across by anything using this service, instead of being
 * silently dropped.
 *
 * Writes apply each field's declared sanitize_function, falling back to
 * sanitize_text_field, which is what the Add New / Edit Guest Author screen
 * does on save. CoAuthors_Guest_Authors::create() itself stores whatever it is
 * handed, so without this a profile created from a file would keep raw values
 * where the same profile typed into the admin would not.
 */
class Guest_Author_Service {

	/**
	 * The guest authors instance profiles are read from and written through.
	 *
	 * @var CoAuthors_Guest_Authors
	 */
	private $guest_authors;

	/**
	 * Constructor.
	 *
	 * @param CoAuthors_Guest_Authors $guest_authors Guest authors instance.
	 */
	public function __construct( CoAuthors_Guest_Authors $guest_authors ) {
		$this->guest_authors = $guest_authors;
	}

	/**
	 * The profile fields carried by this service.
	 *
	 * Excludes the hidden group, so the guest author's own post ID is not
	 * treated as part of the profile.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function fields(): array {
		return $this->guest_authors->get_guest_author_fields();
	}

	/**
	 * Find a guest author.
	 *
	 * @param string $key   Field to search by, for example 'user_login'.
	 * @param string $value Value to search for.
	 * @return object|false Guest author object, or false when absent.
	 */
	public function find_by( string $key, string $value ) {
		return $this->guest_authors->get_guest_author_by( $key, $value );
	}

	/**
	 * Extract a portable profile from a guest author object.
	 *
	 * @param object $guest_author Guest author object.
	 * @return array<string, string> Field key => value.
	 */
	public function profile( $guest_author ): array {
		$profile = array();

		foreach ( $this->fields() as $field ) {
			$key             = $field['key'];
			$profile[ $key ] = (string) ( $guest_author->{$key} ?? '' );
		}

		return $profile;
	}

	/**
	 * Create a guest author from a portable profile.
	 *
	 * Unknown keys are ignored rather than stored, so a profile written by a
	 * newer version of the plugin does not create meta this one knows nothing
	 * about.
	 *
	 * @param array<string, string> $profile Field key => value.
	 * @return int|WP_Error New guest author ID, or an error.
	 */
	public function create( array $profile ) {
		return $this->guest_authors->create( $this->sanitize_profile( $profile ) );
	}

	/**
	 * Sanitise a profile the way the admin save does.
	 *
	 * Each declared field's sanitize_function is applied, falling back to
	 * sanitize_text_field. Keys that are not declared fields are dropped, and
	 * absent fields stay absent rather than becoming empty strings.
	 *
	 * @param array<string, string> $profile Field key => raw value.
	 * @return array<string, string> Field key => sanitised value.
	 */
	public function sanitize_profile( array $profile ): array {
		$args = array();

		foreach ( $this->fields() as $field ) {
			$key = $field['key'];

			if ( ! isset( $profile[ $key ] ) ) {
				continue;
			}

			$args[ $key ] = $this->sanitize( $field, $profile[ $key ] );
		}

		return $args;
	}

	/**
	 * Sanitise one field value the way the admin save does.
	 *
	 * @param array<string, string> $field Field definition.
	 * @param string                $value Raw value.
	 * @return string
	 */
	private function sanitize( array $field, string $value ): string {
		if ( isset( $field['sanitize_function'] ) && is_callable( $field['sanitize_function'] ) ) {
			return (string) call_user_func( $field['sanitize_function'], $value );
		}

		return (string) sanitize_text_field( $value );
	}
}
