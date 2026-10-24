<?php
/**
 * Tests that a guest author slug cannot collide with a WordPress user.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\GuestAuthors;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_Error;
use WPDieException;

/**
 * A guest author and a user whose login or nicename equals its slug resolve to
 * the same author term and the same lookups, and the guest author wins them, so
 * the user's posts would show the guest author's byline. The edit screen and
 * create() (used by WP-CLI and the REST API) must refuse the same collisions.
 *
 * Users are network-wide on multisite, and so are the lookups: core and
 * get_coauthor_by() resolve a slug to a user whether or not they belong to the
 * current site, and a user who isn't a member can still have posts on it.
 *
 * @covers \CoAuthors_Guest_Authors::manage_guest_author_filter_post_data
 * @covers \CoAuthors_Guest_Authors::create
 */
class GuestAuthorSlugUserCollisionTest extends TestCase {

	private const SAME_USER_MESSAGE = 'There is a WordPress user with the same username as this guest author';

	public function tear_down() {
		unset(
			$_POST['guest-author-nonce'],
			$_POST['cap-display_name'],
			$_POST['cap-linked_account']
		);
		parent::tear_down();
	}

	/**
	 * The edit screen refuses a slug matching a network user who isn't a member
	 * of this site, as create() already did.
	 *
	 * @group ms-required
	 */
	public function test_edit_screen_refuses_slug_of_network_user_not_on_this_site(): void {
		$guest_author_id = $this->make_guest_author( 'net-jane' );
		$this->create_non_member( array( 'user_login' => 'net-jane' ) );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( self::SAME_USER_MESSAGE );

		$this->save_from_edit_screen( $guest_author_id );
	}

	/**
	 * Creating a guest author refuses the same network user.
	 *
	 * @group ms-required
	 */
	public function test_create_refuses_slug_of_network_user_not_on_this_site(): void {
		$this->create_non_member( array( 'user_login' => 'net-jane' ) );

		$this->assert_create_refused( 'net-jane' );
	}

	/**
	 * The edit screen refuses a slug matching a user of this site.
	 */
	public function test_edit_screen_refuses_slug_of_site_user(): void {
		$guest_author_id = $this->make_guest_author( 'site-jane' );
		$this->factory()->user->create( array( 'user_login' => 'site-jane' ) );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( self::SAME_USER_MESSAGE );

		$this->save_from_edit_screen( $guest_author_id );
	}

	/**
	 * Linking the guest author to the matching user is still allowed.
	 */
	public function test_edit_screen_allows_slug_when_linking_to_that_user(): void {
		$guest_author_id = $this->make_guest_author( 'site-jane' );
		$user_id         = $this->factory()->user->create( array( 'user_login' => 'site-jane' ) );

		$post_data = $this->save_from_edit_screen( $guest_author_id, $user_id );

		$this->assertSame( 'cap-site-jane', $post_data['post_name'] );
	}

	/**
	 * Creating a guest author refuses a user whose nicename, not login, equals
	 * the slug: the two would share an author term.
	 */
	public function test_create_refuses_slug_matching_user_nicename(): void {
		$this->factory()->user->create(
			array(
				'user_login'    => 'jdoe',
				'user_nicename' => 'jane-doe',
			)
		);

		$this->assert_create_refused( 'jane-doe' );
	}

	/**
	 * The edit screen refuses a user whose login, not nicename, equals the
	 * slug, as create() already did: get_coauthor_by( 'user_login' ) would
	 * resolve that login to the guest author.
	 */
	public function test_edit_screen_refuses_slug_matching_user_login(): void {
		$guest_author_id = $this->make_guest_author( 'jane-doe' );
		$this->factory()->user->create(
			array(
				'user_login'    => 'jane-doe',
				'user_nicename' => 'jane-smith',
			)
		);

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( self::SAME_USER_MESSAGE );

		$this->save_from_edit_screen( $guest_author_id );
	}

	/**
	 * Creates a guest author, which must happen before the colliding user exists.
	 *
	 * @param string $slug Guest author slug.
	 * @return int Guest author ID.
	 */
	private function make_guest_author( string $slug ): int {
		$guest_author_id = $this->create_guest_author( $slug );
		$this->assertIsInt( $guest_author_id );
		return $guest_author_id;
	}

	/**
	 * Creates a network user and removes them from the current site.
	 *
	 * @param array $args User arguments.
	 */
	private function create_non_member( array $args ): void {
		$user_id = $this->factory()->user->create( $args );
		remove_user_from_blog( $user_id, get_current_blog_id() );
		$this->assertFalse( is_user_member_of_blog( $user_id, get_current_blog_id() ) );
	}

	/**
	 * Runs the edit screen's save filter as an administrator.
	 *
	 * @param int      $guest_author_id Guest author being saved.
	 * @param int|null $linked_user_id  User picked in the linked account dropdown.
	 * @return array Filtered post data.
	 */
	private function save_from_edit_screen( int $guest_author_id, ?int $linked_user_id = null ): array {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST['guest-author-nonce'] = wp_create_nonce( 'guest-author-nonce' );
		$_POST['cap-display_name']   = 'Jane';
		if ( null !== $linked_user_id ) {
			$_POST['cap-linked_account'] = (string) $linked_user_id;
		}

		return $this->_cap->guest_authors->manage_guest_author_filter_post_data(
			array(
				'post_type'  => $this->_cap->guest_authors->post_type,
				'post_title' => 'Jane',
				'post_name'  => '',
			),
			array( 'ID' => $guest_author_id )
		);
	}

	/**
	 * Asserts create() refuses a guest author with this slug.
	 *
	 * @param string $slug Guest author slug.
	 */
	private function assert_create_refused( string $slug ): void {
		$response = $this->_cap->guest_authors->create(
			array(
				'display_name' => 'Jane Guest',
				'user_login'   => $slug,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'duplicate-field', $response->get_error_code() );
	}
}
