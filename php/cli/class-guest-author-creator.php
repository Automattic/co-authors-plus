<?php
/**
 * Creates one guest author from imported data.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use Automattic\CoAuthorsPlus\Services\Guest_Author_Service;
use CoAuthors_Plus;
use WP_CLI;

/**
 * Creates one guest author from imported data, skipping any that already exist.
 *
 * Three commands import guest authors — from a CSV, from a WXR file, and one at
 * a time from the command line — and all three need the same thing: look for an
 * existing profile by email and then by login, create one if neither matches,
 * and record where it came from.
 *
 * Progress is reported through WP_CLI, because the bulk importers interleave it
 * with their own per-row logging. The return value says only whether the caller
 * should treat this author as a failure, which the single-author command turns
 * into an exit code and the importers tally.
 */
class Guest_Author_Creator {

	/**
	 * Plugin instance.
	 *
	 * @var CoAuthors_Plus
	 */
	private $coauthors_plus;

	/**
	 * Sanitises profile fields the way the admin edit screen does.
	 *
	 * @var Guest_Author_Service
	 */
	private $profiles;

	/**
	 * Constructor.
	 *
	 * @param CoAuthors_Plus       $coauthors_plus Plugin instance.
	 * @param Guest_Author_Service $profiles       Sanitises profile fields.
	 */
	public function __construct( CoAuthors_Plus $coauthors_plus, Guest_Author_Service $profiles ) {
		$this->coauthors_plus = $coauthors_plus;
		$this->profiles       = $profiles;
	}

	/**
	 * Create a guest author, unless one already exists for it.
	 *
	 * Every field other than display_name and user_login is optional; an absent
	 * key is treated the same as an empty value. An `ID` key, where the source
	 * has one, is recorded as `_original_author_id`.
	 *
	 * @param array<string, mixed> $author Author args. Requires display_name and user_login.
	 * @return bool True when a profile exists for this author afterwards, whether it
	 *              was created now or was already there. False when creation failed.
	 */
	public function create( array $author ): bool {
		$coauthors_plus = $this->coauthors_plus;

		// Sanitised exactly as the admin edit screen would sanitise the same values,
		// before the duplicate lookups and the collision guard inside
		// CoAuthors_Guest_Authors::create() run, so both vet the value that will
		// actually be stored. $author itself stays unsanitised: the provenance meta
		// below records the login exactly as the source supplied it.
		$profile = $this->profiles->sanitize_profile(
			array(
				'display_name' => (string) ( $author['display_name'] ?? '' ),
				'user_login'   => (string) ( $author['user_login'] ?? '' ),
				'user_email'   => (string) ( $author['user_email'] ?? '' ),
				'first_name'   => (string) ( $author['first_name'] ?? '' ),
				'last_name'    => (string) ( $author['last_name'] ?? '' ),
				'website'      => (string) ( $author['website'] ?? '' ),
				'description'  => (string) ( $author['description'] ?? '' ),
			)
		);

		$guest_author = false;

		if ( ! empty( $profile['user_email'] ) ) {
			$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'user_email', $profile['user_email'], true );
		}

		if ( ! $guest_author && ! empty( $profile['user_login'] ) ) {
			$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'user_login', $profile['user_login'], true );
		}

		if ( $guest_author ) {
			WP_CLI::warning(
				sprintf(
					/* translators: 1: Guest author ID. 2: Guest author user_login. */
					esc_html__( '-- Author already exists (ID #%1$s, user_login %2$s); skipping.', 'co-authors-plus' ),
					$guest_author->ID,
					$guest_author->user_login
				)
			);

			return true;
		}

		// avatar is an attachment ID, not a declared profile field, so it bypasses
		// the sanitiser and keeps reaching set_post_thumbnail().
		$guest_author_id = $coauthors_plus->guest_authors->create(
			array_merge( $profile, array( 'avatar' => $author['avatar'] ?? '' ) )
		);

		if ( is_wp_error( $guest_author_id ) ) {
			/* translators: The error message. */
			WP_CLI::warning( sprintf( esc_html__( '-- Failed to create guest author: %s', 'co-authors-plus' ), $guest_author_id->get_error_message() ) );

			return false;
		}

		// The importers supply the source author's ID under `ID`. The guard used to
		// test `author_id`, which no caller sets, so this never ran.
		if ( isset( $author['ID'] ) ) {
			update_post_meta( $guest_author_id, '_original_author_id', $author['ID'] );
		}

		update_post_meta( $guest_author_id, '_original_author_login', $author['user_login'] );

		/* translators: Guest Author ID. */
		WP_CLI::success( sprintf( esc_html__( '-- Created as guest author #%s', 'co-authors-plus' ), $guest_author_id ) );

		return true;
	}
}
