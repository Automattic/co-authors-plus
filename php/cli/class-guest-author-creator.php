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
 * and record where it came from. This was a private helper on
 * CoAuthorsPlus_Command and moves here unchanged so those commands can share it
 * once they are classes of their own.
 *
 * It reports through WP_CLI rather than returning, which is how it behaved
 * before; giving it a return value would change what the commands print.
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
	 * @param array<string, mixed> $author Author args. Requires display_name and user_login.
	 * @return void
	 */
	public function create( array $author ): void {
		$coauthors_plus = $this->coauthors_plus;

		$guest_author = false;

		if ( ! empty( $author['user_email'] ) ) {
			$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'user_email', $author['user_email'], true );
		}

		if ( ! $guest_author && ! empty( $author['user_login'] ) ) {
			$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'user_login', $author['user_login'], true );
		}

		if ( $guest_author ) {
			/* translators: Guest Author ID. */
			WP_CLI::warning( sprintf( esc_html__( '-- Author already exists (ID #%s); skipping.', 'co-authors-plus' ), $guest_author->ID ) );
			return;
		}

		WP_CLI::log( esc_html__( '-- Not found; creating profile.', 'co-authors-plus' ) );

		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => $author['display_name'],
				'user_login'   => $author['user_login'],
				'user_email'   => $author['user_email'],
				'first_name'   => $author['first_name'],
				'last_name'    => $author['last_name'],
				'website'      => $author['website'],
				'description'  => $author['description'],
				'avatar'       => $author['avatar'],
			)
		);

		if ( is_wp_error( $guest_author_id ) ) {
			/* translators: The error message. */
			WP_CLI::warning( sprintf( esc_html__( '-- Failed to create guest author: %s', 'co-authors-plus' ), $guest_author_id->get_error_message() ) );
			return;
		}

		if ( isset( $author['author_id'] ) ) {
			update_post_meta( $guest_author_id, '_original_author_id', $author['ID'] );
		}

		update_post_meta( $guest_author_id, '_original_author_login', $author['user_login'] );

		/* translators: Guest Author ID. */
		WP_CLI::success( sprintf( esc_html__( '-- Created as guest author #%s', 'co-authors-plus' ), $guest_author_id ) );
	}
}
