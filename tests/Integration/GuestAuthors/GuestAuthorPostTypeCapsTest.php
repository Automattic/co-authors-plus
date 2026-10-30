<?php
/**
 * Tests for the guest-author post type's capabilities.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;

/**
 * Every capability of the guest-author post type resolves to
 * `coauthors_guest_author_manage_cap` (`list_users` by default), the same
 * capability as the Guest Authors screen, so core's post screens give no one
 * access to profiles that the Guest Authors screen wouldn't.
 *
 * @covers \CoAuthors_Guest_Authors::register_hooks
 */
class GuestAuthorPostTypeCapsTest extends TestCase {

	/** @var array<string, \WP_User> */
	private $users = array();

	/** @var int */
	private $guest_author_id;

	public function set_up() {
		parent::set_up();

		foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $role ) {
			$this->users[ $role ] = $this->factory()->user->create_and_get( array( 'role' => $role ) );
		}

		wp_set_current_user( $this->users['administrator']->ID );
		$this->guest_author_id = $this->_cap->guest_authors->create(
			array(
				'display_name' => 'Post Type Caps Guest',
				'user_login'   => 'post_type_caps_guest',
			)
		);
	}

	public function tear_down() {
		remove_all_filters( 'coauthors_guest_author_manage_cap' );
		// Restore the default registration if a test re-registered the post type.
		( new \CoAuthors_Guest_Authors() )->register_hooks();

		parent::tear_down();
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function data_roles(): array {
		return array(
			'administrator' => array( 'administrator', true ),
			'editor'        => array( 'editor', false ),
			'author'        => array( 'author', false ),
			'contributor'   => array( 'contributor', false ),
		);
	}

	/**
	 * @dataProvider data_roles
	 */
	public function test_only_the_manage_cap_can_create_or_list_guest_authors( string $role, bool $expected ): void {
		$caps = get_post_type_object( $this->_cap->guest_authors->post_type )->cap;

		wp_set_current_user( $this->users[ $role ]->ID );

		$this->assertSame( $expected, current_user_can( $caps->create_posts ), 'create_posts (post-new.php)' );
		$this->assertSame( $expected, current_user_can( $caps->edit_posts ), 'edit_posts (edit.php list)' );
	}

	/**
	 * @dataProvider data_roles
	 */
	public function test_only_the_manage_cap_can_edit_or_delete_a_guest_author( string $role, bool $expected ): void {
		wp_set_current_user( $this->users[ $role ]->ID );

		$this->assertSame( $expected, current_user_can( 'edit_post', $this->guest_author_id ), 'edit_post (post.php)' );
		$this->assertSame( $expected, current_user_can( 'delete_post', $this->guest_author_id ), 'delete_post (trash)' );
	}

	/**
	 * A profile's creator gets no extra rights over it.
	 */
	public function test_creator_without_the_manage_cap_cannot_edit_their_own_profile(): void {
		$contributor = $this->users['contributor'];
		wp_update_post(
			array(
				'ID'          => $this->guest_author_id,
				'post_author' => $contributor->ID,
				'post_status' => 'pending',
			)
		);

		wp_set_current_user( $contributor->ID );

		$this->assertFalse( current_user_can( 'edit_post', $this->guest_author_id ) );
		$this->assertFalse( current_user_can( 'delete_post', $this->guest_author_id ) );
	}

	/**
	 * Lowering the manage capability opens every path together.
	 */
	public function test_manage_cap_filter_applies_to_the_post_type(): void {
		add_filter(
			'coauthors_guest_author_manage_cap',
			static function () {
				return 'edit_others_posts';
			}
		);
		( new \CoAuthors_Guest_Authors() )->register_hooks();

		$caps = get_post_type_object( $this->_cap->guest_authors->post_type )->cap;

		wp_set_current_user( $this->users['editor']->ID );
		$this->assertTrue( current_user_can( $caps->create_posts ) );
		$this->assertTrue( current_user_can( 'edit_post', $this->guest_author_id ) );

		wp_set_current_user( $this->users['author']->ID );
		$this->assertFalse( current_user_can( $caps->create_posts ) );
		$this->assertFalse( current_user_can( 'edit_post', $this->guest_author_id ) );
	}
}
