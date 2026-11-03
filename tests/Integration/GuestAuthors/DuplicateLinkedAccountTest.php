<?php
/**
 * Tests for a user linked to more than one guest author, which create() and
 * direct meta writes allow even though the dropdown doesn't.
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
	 * Create two guest authors and link both to the user, newest first, so
	 * the oldest guest author's link has the higher meta ID.
	 *
	 * @param string $user_login Login of the user to link.
	 * @return int[] Guest author IDs, oldest first.
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

		return array( $older, $newer );
	}

	/**
	 * @covers ::get_guest_author_ids_by_linked_account
	 */
	public function test_get_guest_author_ids_by_linked_account_returns_all_oldest_first(): void {
		$user = $this->create_author( 'jane' );

		$this->assertSame(
			$this->create_two_linked_guest_authors( $user->user_login ),
			$this->_cap->guest_authors->get_guest_author_ids_by_linked_account( $user->user_login )
		);
		$this->assertSame( array(), $this->_cap->guest_authors->get_guest_author_ids_by_linked_account( '' ) );
	}

	/**
	 * Lookups by the user return the same guest author every time: the oldest.
	 *
	 * @covers ::get_guest_author_by
	 */
	public function test_lookup_by_linked_account_returns_the_oldest_guest_author(): void {
		$user = $this->create_author( 'jane' );

		list( $older ) = $this->create_two_linked_guest_authors( $user->user_login );

		$guest_author = $this->_cap->guest_authors->get_guest_author_by( 'linked_account', $user->user_login, true );

		$this->assertSame( $older, (int) $guest_author->ID );
	}

	/**
	 * Deleting the user deletes every linked guest author, not only one.
	 *
	 * @covers \CoAuthors_Plus::delete_user_action
	 */
	public function test_deleting_the_user_deletes_every_linked_guest_author(): void {
		$user = $this->create_author( 'jane' );

		list( $older, $newer ) = $this->create_two_linked_guest_authors( $user->user_login );

		wp_delete_user( $user->ID );

		$this->assertNull( get_post( $older ) );
		$this->assertNull( get_post( $newer ) );
	}

	/**
	 * Linking a second guest author through create() warns, but still creates
	 * it so existing callers keep working.
	 *
	 * @covers ::create
	 */
	public function test_create_warns_when_the_user_is_already_linked(): void {
		$this->setExpectedIncorrectUsage( 'CoAuthors_Guest_Authors::create' );

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
	}
}
