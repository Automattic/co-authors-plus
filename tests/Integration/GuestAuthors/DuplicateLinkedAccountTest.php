<?php
/**
 * Tests for a user linked to more than one guest author.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class DuplicateLinkedAccountTest extends TestCase {

	/**
	 * Create two guest authors and link the newer one first, so the order of the
	 * links differs from the order of the posts.
	 *
	 * @param string $user_login Login of the user to link.
	 * @return int[] Guest author IDs, first linked first.
	 */
	private function create_two_linked_guest_authors( string $user_login ): array {
		$guest_authors = $this->_cap->guest_authors;

		$older = $guest_authors->create(
			array(
				'display_name' => 'Pen One',
				'user_login'   => 'pen-one',
			)
		);
		$newer = $guest_authors->create(
			array(
				'display_name' => 'Pen Two',
				'user_login'   => 'pen-two',
			)
		);

		update_post_meta( $newer, $guest_authors->get_post_meta_key( 'linked_account' ), $user_login );
		update_post_meta( $older, $guest_authors->get_post_meta_key( 'linked_account' ), $user_login );

		return array( $newer, $older );
	}

	/**
	 * @covers ::get_guest_author_ids_by_linked_account
	 */
	public function test_get_guest_author_ids_by_linked_account_returns_all_first_linked_first(): void {
		$user = $this->create_author( 'jane' );

		$this->assertSame(
			$this->create_two_linked_guest_authors( $user->user_login ),
			$this->_cap->guest_authors->get_guest_author_ids_by_linked_account( $user->user_login )
		);
		$this->assertSame( array(), $this->_cap->guest_authors->get_guest_author_ids_by_linked_account( '' ) );
	}

	/**
	 * Lookups by the user return the guest author linked first, which is what the
	 * unordered query returned, so existing sites keep the same answer.
	 *
	 * @covers ::get_guest_author_by
	 */
	public function test_lookup_by_linked_account_returns_the_first_linked_guest_author(): void {
		$user = $this->create_author( 'jane' );

		list( $first_linked ) = $this->create_two_linked_guest_authors( $user->user_login );

		$guest_author = $this->_cap->guest_authors->get_guest_author_by( 'linked_account', $user->user_login, true );

		$this->assertSame( $first_linked, (int) $guest_author->ID );
	}

	/**
	 * A shared email resolves the same way, with no linked user involved.
	 *
	 * @covers ::get_guest_author_by
	 */
	public function test_lookup_by_shared_email_returns_the_first_to_set_it(): void {
		$guest_authors = $this->_cap->guest_authors;

		$older = $guest_authors->create(
			array(
				'display_name' => 'Pen One',
				'user_login'   => 'pen-one',
			)
		);
		$newer = $guest_authors->create(
			array(
				'display_name' => 'Pen Two',
				'user_login'   => 'pen-two',
				'user_email'   => 'desk@example.com',
			)
		);

		update_post_meta( $older, $guest_authors->get_post_meta_key( 'user_email' ), 'desk@example.com' );

		$guest_author = $guest_authors->get_guest_author_by( 'user_email', 'desk@example.com', true );

		$this->assertSame( $newer, (int) $guest_author->ID );
	}

	/**
	 * Deleting the user deletes every linked guest author, not only one.
	 *
	 * @covers \CoAuthors_Plus::delete_user_action
	 */
	public function test_deleting_the_user_deletes_every_linked_guest_author(): void {
		$user = $this->create_author( 'jane' );

		list( $first_linked, $second_linked ) = $this->create_two_linked_guest_authors( $user->user_login );

		wp_delete_user( $user->ID );

		$this->assertNull( get_post( $first_linked ) );
		$this->assertNull( get_post( $second_linked ) );
	}

	/**
	 * A second guest author can be linked through create() without complaint.
	 *
	 * @covers ::create
	 */
	public function test_create_links_a_second_guest_author(): void {
		$user = $this->create_author( 'jane' );

		$first = $this->_cap->guest_authors->create(
			array(
				'display_name'   => 'Pen One',
				'user_login'     => 'pen-one',
				'linked_account' => $user->user_login,
			)
		);
		$this->assertIsInt( $first );

		$second = $this->_cap->guest_authors->create(
			array(
				'display_name'   => 'Pen Two',
				'user_login'     => 'pen-two',
				'linked_account' => $user->user_login,
			)
		);
		$this->assertIsInt( $second );

		$this->assertSame(
			array( $first, $second ),
			$this->_cap->guest_authors->get_guest_author_ids_by_linked_account( $user->user_login )
		);
	}
}
