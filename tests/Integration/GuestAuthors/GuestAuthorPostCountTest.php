<?php
/**
 * Tests for CoAuthors_Plus::get_guest_author_post_count().
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class GuestAuthorPostCountTest extends TestCase {

	/**
	 * Checks post count for a guest author with no linked account.
	 *
	 * @covers ::get_guest_author_post_count
	 */
	public function test_get_guest_author_post_count_without_linked_account(): void {
		global $coauthors_plus;

		$guest_author_id = $coauthors_plus->guest_authors->create(
			array(
				'display_name' => 'Unlinked Guest',
				'user_login'   => 'unlinked-guest',
			)
		);
		$guest_author = $coauthors_plus->guest_authors->get_guest_author_by( 'id', $guest_author_id );

		$post_ids = $this->factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		foreach ( $post_ids as $post_id ) {
			$coauthors_plus->add_coauthors( $post_id, array( $guest_author->user_login ), true );
		}

		$this->assertSame( 3, $coauthors_plus->get_guest_author_post_count( $guest_author ) );
	}

	/**
	 * Checks that a guest author profile linked to a WP user using that
	 * user's own nicename (the common "replace this WP user's byline with a
	 * guest profile" mapping) reports the combined, de-duplicated total
	 * rather than counting shared posts twice.
	 *
	 * @see https://github.com/Automattic/co-authors-plus/issues/928
	 *
	 * @covers ::get_guest_author_post_count
	 */
	public function test_get_guest_author_post_count_with_linked_account_sharing_users_slug(): void {
		global $coauthors_plus;

		$author = $this->create_author( 'shared-slug-author' );

		// Posts authored directly by the WP user.
		$this->factory()->post->create_many(
			3,
			array(
				'post_author' => $author->ID,
				'post_status' => 'publish',
			)
		);

		$guest_author_id = $coauthors_plus->guest_authors->create_guest_author_from_user_id( $author->ID );
		$guest_author    = $coauthors_plus->get_coauthor_by( 'id', $guest_author_id );

		// Precondition for this scenario: the linked guest profile shares the
		// WP user's own nicename/term, rather than having a distinct identity.
		$this->assertSame( $author->user_nicename, $guest_author->user_nicename );

		// More posts authored via the guest byline.
		$post_ids = $this->factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		foreach ( $post_ids as $post_id ) {
			$coauthors_plus->add_coauthors( $post_id, array( $guest_author->user_login ), true );
		}

		$this->assertSame( 5, $coauthors_plus->get_guest_author_post_count( $guest_author ) );
	}

	/**
	 * Checks that an invalid $guest_author argument returns 0 rather than
	 * emitting a warning.
	 *
	 * @covers ::get_guest_author_post_count
	 */
	public function test_get_guest_author_post_count_with_invalid_argument(): void {
		global $coauthors_plus;

		$this->assertSame( 0, $coauthors_plus->get_guest_author_post_count( null ) );
		$this->assertSame( 0, $coauthors_plus->get_guest_author_post_count( 'not-an-object' ) );
	}
}
