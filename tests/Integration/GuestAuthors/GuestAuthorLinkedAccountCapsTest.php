<?php
/**
 * Who can link a guest author to a WordPress account.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WPDieException;

/**
 * A linked guest author takes the user's place on every byline, and anyone
 * whose login matches the guest author's counts as a co-author of that user's
 * posts. So only users who can manage guest authors can change the link, even
 * though anyone who can edit posts can create a guest author.
 *
 * @covers \CoAuthors_Guest_Authors::manage_guest_author_filter_post_data
 * @covers \CoAuthors_Guest_Authors::manage_guest_author_save_meta_fields
 * @covers \CoAuthors_Guest_Authors::metabox_manage_guest_author_slug
 */
class GuestAuthorLinkedAccountCapsTest extends TestCase {

	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Save a guest author the way the classic edit screen does, as the current user.
	 *
	 * Like post-new.php, a new guest author starts as an auto-draft that the
	 * submitted form then updates.
	 *
	 * @param array $fields  Submitted guest author fields.
	 * @param int   $post_id Guest author to update, or 0 to create one.
	 * @return int Guest author post ID.
	 */
	private function save_guest_author( array $fields, int $post_id = 0 ): int {
		if ( ! $post_id ) {
			$post_id = (int) wp_insert_post(
				array(
					'post_type'   => $this->_cap->guest_authors->post_type,
					'post_status' => 'auto-draft',
				)
			);
		}

		$_POST    = array( 'guest-author-nonce' => wp_create_nonce( 'guest-author-nonce' ) ) + $fields;
		$_REQUEST = $_POST;

		$saved = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_type'   => $this->_cap->guest_authors->post_type,
				'post_status' => 'pending',
				'post_title'  => $fields['cap-display_name'],
			)
		);

		$_POST    = array();
		$_REQUEST = array();
		$this->_cap->guest_authors->delete_guest_author_cache( (int) $saved );

		return (int) $saved;
	}

	public static function data_users_who_cannot_manage_guest_authors(): array {
		return array(
			'contributor' => array( 'contributor' ),
			'author'      => array( 'author' ),
			'editor'      => array( 'editor' ),
		);
	}

	/**
	 * @dataProvider data_users_who_cannot_manage_guest_authors
	 */
	public function test_user_who_cannot_manage_guest_authors_cannot_link_an_account( string $role ): void {
		$admin = $this->factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'linkedadmin',
			)
		);
		wp_set_current_user( $this->factory()->user->create( array( 'role' => $role ) ) );

		$guest_author_id = $this->save_guest_author(
			array(
				'cap-display_name'   => 'Someone Else',
				'cap-linked_account' => (string) $admin->ID,
			)
		);

		$this->assertSame( '', $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id )->linked_account );
		$this->assertSame( 'wpuser', $this->_cap->get_coauthor_by( 'user_login', 'linkedadmin' )->type );
	}

	public function test_author_cannot_gain_edit_rights_on_another_users_posts_through_a_guest_author(): void {
		$victim      = $this->create_editor( 'victim' );
		$victim_post = $this->create_post( $victim );
		$attacker    = $this->create_author( 'attacker' );
		wp_set_current_user( $attacker->ID );

		// Name the guest author after their own login and link it to themselves, then
		// relink it to someone else.
		try {
			$guest_author_id = $this->save_guest_author(
				array(
					'cap-display_name'   => 'attacker',
					'cap-linked_account' => (string) $attacker->ID,
				)
			);
			$this->save_guest_author(
				array(
					'cap-display_name'   => 'attacker',
					'cap-linked_account' => (string) $victim->ID,
				),
				$guest_author_id
			);
		} catch ( WPDieException $e ) {
			// Refusing the save is fine too.
			unset( $e );
		}

		$this->assertFalse( current_user_can( 'edit_post', $victim_post->ID ) );
		$this->assertSame( 'wpuser', $this->_cap->get_coauthor_by( 'user_login', 'victim' )->type );
	}

	/**
	 * Otherwise the guest author would be saved with the user's login and no link,
	 * and that login would count as a co-author wherever the guest author is.
	 */
	public function test_user_who_cannot_manage_guest_authors_cannot_name_a_guest_author_after_a_user(): void {
		$author = $this->create_author( 'takenlogin' );
		wp_set_current_user( $author->ID );

		$this->expectException( WPDieException::class );

		$this->save_guest_author(
			array(
				'cap-display_name'   => 'takenlogin',
				'cap-linked_account' => (string) $author->ID,
			)
		);
	}

	public function test_user_who_can_manage_guest_authors_can_link_an_account(): void {
		$author = $this->create_author( 'linkme' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$guest_author_id = $this->save_guest_author(
			array(
				'cap-display_name'   => 'Pen Name',
				'cap-linked_account' => (string) $author->ID,
			)
		);

		$this->assertSame( 'linkme', $this->_cap->guest_authors->get_guest_author_by( 'ID', $guest_author_id )->linked_account );
		$this->assertSame( 'Pen Name', $this->_cap->get_coauthor_by( 'user_login', 'linkme' )->display_name );
	}

	public function test_linked_account_dropdown_is_disabled_for_user_who_cannot_manage_guest_authors(): void {
		global $post;

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = get_post( $this->create_guest_author( 'dropdown-guest' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		$this->_cap->guest_authors->metabox_manage_guest_author_slug();
		$this->assertStringNotContainsString( '<select disabled="disabled"', ob_get_clean() );

		wp_set_current_user( $this->create_editor( 'dropdown-editor' )->ID );
		ob_start();
		$this->_cap->guest_authors->metabox_manage_guest_author_slug();
		$this->assertStringContainsString( '<select disabled="disabled"', ob_get_clean() );
	}
}
