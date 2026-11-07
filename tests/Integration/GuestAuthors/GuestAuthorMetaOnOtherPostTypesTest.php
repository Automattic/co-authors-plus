<?php
/**
 * Tests that guest author meta on other post types is never read as a guest author.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Anyone who can edit a post can add unprotected meta to it, cap- keys included,
 * through the Custom Fields box, admin-ajax or XML-RPC.
 *
 * @coversDefaultClass \CoAuthors_Guest_Authors
 */
class GuestAuthorMetaOnOtherPostTypesTest extends TestCase {

	/**
	 * Creates a post owned by the given user, carrying the given cap- meta rows in order.
	 *
	 * @param int   $user_id Post author.
	 * @param array $rows    Pairs of meta key and value.
	 * @return int Post ID.
	 */
	private function create_post_with_meta( int $user_id, array $rows ): int {
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $user_id,
				'post_status' => 'draft',
			)
		);

		foreach ( $rows as $row ) {
			add_post_meta( $post_id, $row[0], $row[1] );
		}

		return $post_id;
	}

	/**
	 * @covers ::get_guest_author_by
	 * @dataProvider data_meta_keys
	 *
	 * @param string $key   Lookup key.
	 * @param string $field Victim user field the meta value copies.
	 */
	public function test_meta_on_a_regular_post_is_not_a_guest_author( string $key, string $field ): void {
		$author = $this->create_author( 'eve' );
		$victim = $this->create_editor( 'victim' );

		$this->create_post_with_meta( $author->ID, array( array( 'cap-' . $key, $victim->$field ) ) );

		$this->assertFalse( $this->_cap->guest_authors->get_guest_author_by( $key, $victim->$field, true ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function data_meta_keys(): array {
		return array(
			'linked_account' => array( 'linked_account', 'user_login' ),
			'user_login'     => array( 'user_login', 'user_login' ),
			'user_email'     => array( 'user_email', 'user_email' ),
		);
	}

	/**
	 * @covers ::get_guest_author_by
	 */
	public function test_a_real_guest_author_is_still_found_by_meta(): void {
		$victim   = $this->create_editor( 'victim' );
		$guest_id = $this->_cap->guest_authors->create(
			array(
				'display_name'   => 'Pen Name',
				'user_login'     => 'penname',
				'user_email'     => 'pen@example.com',
				'linked_account' => 'victim',
			)
		);

		$lookups = array(
			'linked_account' => 'victim',
			'user_login'     => 'penname',
			'user_email'     => 'pen@example.com',
		);

		foreach ( $lookups as $key => $value ) {
			$guest = $this->_cap->guest_authors->get_guest_author_by( $key, $value, true );
			$this->assertIsObject( $guest, $key );
			$this->assertSame( $guest_id, (int) $guest->ID, $key );
		}

		$this->assertContains( $victim->ID, wp_list_pluck( $this->_cap->guest_authors->get_all_linked_accounts( true ), 'ID' ) );
	}

	/**
	 * @covers ::get_all_linked_accounts
	 */
	public function test_meta_on_a_regular_post_does_not_link_an_account(): void {
		$author = $this->create_author( 'eve' );
		$victim = $this->create_editor( 'victim' );

		$this->create_post_with_meta( $author->ID, array( array( 'cap-linked_account', 'victim' ) ) );

		$this->assertNotContains( $victim->ID, wp_list_pluck( $this->_cap->guest_authors->get_all_linked_accounts( true ), 'ID' ) );
	}

	/**
	 * The first row is what get_post_meta() reads back, so the matched profile
	 * claims to be linked to the attacker.
	 *
	 * @covers \CoAuthors_Plus::get_coauthor_by
	 * @covers \CoAuthors_Plus::filter_map_meta_cap
	 */
	public function test_meta_on_a_regular_post_gives_no_rights_on_another_users_posts(): void {
		$author = $this->create_author( 'eve' );
		$victim = $this->create_editor( 'victim' );

		$this->create_post_with_meta(
			$author->ID,
			array(
				array( 'cap-linked_account', 'eve' ),
				array( 'cap-linked_account', 'victim' ),
				array( 'cap-display_name', 'Not The Victim' ),
				array( 'cap-user_login', 'eve' ),
			)
		);

		// After the meta, or the victim's negative lookup is cached first.
		$victim_post = $this->create_post( $victim );
		$this->_cap->add_coauthors( $victim_post->ID, array( 'victim' ) );

		$this->assertFalse( user_can( $author, 'edit_post', $victim_post->ID ) );
		$this->assertFalse( user_can( $author, 'delete_post', $victim_post->ID ) );

		$coauthors = get_coauthors( $victim_post->ID );
		$this->assertCount( 1, $coauthors );
		$this->assertSame( 'wpuser', $coauthors[0]->type );
		$this->assertSame( $victim->ID, $coauthors[0]->ID );
	}
}
