<?php
/**
 * Creates one guest author from imported data.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

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
	 * Constructor.
	 *
	 * @param CoAuthors_Plus $coauthors_plus Plugin instance.
	 */
	public function __construct( CoAuthors_Plus $coauthors_plus ) {
		$this->coauthors_plus = $coauthors_plus;
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

		$guest_author = false;

		if ( ! empty( $author['user_email'] ) ) {
			$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'user_email', $author['user_email'], true );
		}

		if ( ! $guest_author && ! empty( $author['user_login'] ) ) {
			$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'user_login', $author['user_login'], true );
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

		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => $author['display_name'] ?? '',
				'user_login'   => $author['user_login'] ?? '',
				'user_email'   => $author['user_email'] ?? '',
				'first_name'   => $author['first_name'] ?? '',
				'last_name'    => $author['last_name'] ?? '',
				'website'      => $author['website'] ?? '',
				'description'  => $author['description'] ?? '',
				'avatar'       => $author['avatar'] ?? '',
			)
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
