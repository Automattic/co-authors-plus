<?php
/**
 * Tests that a guest author's co-author rights only reach its linked user.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\Tests\Integration\Capabilities;

use Automattic\CoAuthorsPlus\Tests\Integration\TestCase;
use WP_REST_Request;
use WPDieException;

/**
 * @coversDefaultClass \CoAuthors_Plus
 */
class GuestAuthorCoauthorCapsTest extends TestCase {

	public function tear_down() {
		$_POST = array();
		unset( $_REQUEST['_wpnonce'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Creates a post bylined to a guest author, with an Editor as its post_author.
	 *
	 * @param string $slug   Guest author slug.
	 * @param string $status Post status.
	 * @return int Post ID.
	 */
	private function create_guest_author_post( string $slug, string $status = 'publish' ): int {
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $this->factory()->user->create( array( 'role' => 'editor' ) ),
				'post_status' => $status,
			)
		);
		$this->_cap->add_coauthors( $post_id, array( $slug ) );

		return $post_id;
	}

	/**
	 * Creates a guest author linked to a user.
	 *
	 * @param string $slug  Guest author slug.
	 * @param string $login Login of the linked user.
	 */
	private function create_linked_guest_author( string $slug, string $login ): void {
		$this->assertIsInt(
			$this->_cap->guest_authors->create(
				array(
					'display_name'   => $slug,
					'user_login'     => $slug,
					'linked_account' => $login,
				)
			)
		);
	}

	/**
	 * Sends a post update or delete through core's REST API.
	 *
	 * @param string $method  HTTP method.
	 * @param int    $post_id Post ID.
	 * @return int Response status.
	 */
	private function rest_status( string $method, int $post_id ): int {
		$request = new WP_REST_Request( $method, '/wp/v2/posts/' . $post_id );
		$request->set_param( 'title', 'Saved over REST' );

		return rest_do_request( $request )->get_status();
	}

	/**
	 * Prepares a classic editor save of a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function prepare_classic_save( int $post_id ): void {
		set_current_screen( 'post' );

		$_POST = array(
			'post_ID'     => $post_id,
			'post_author' => (int) get_post( $post_id )->post_author,
			'post_title'  => 'Saved in the classic editor',
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'update-post_' . $post_id );
		$this->assertSame( 1, check_admin_referer( 'update-post_' . $post_id ) );
	}

	/**
	 * @covers ::filter_map_meta_cap
	 */
	public function test_an_unlinked_author_gets_no_rights_on_a_guest_authors_published_post(): void {
		$this->create_guest_author( 'penname' );
		$post_id = $this->create_guest_author_post( 'penname' );

		$user = $this->create_author( 'penname' );

		$this->assertFalse( user_can( $user, 'edit_post', $post_id ) );
		$this->assertFalse( user_can( $user, 'delete_post', $post_id ) );
	}

	/**
	 * @covers ::filter_map_meta_cap
	 */
	public function test_a_linked_author_can_edit_and_delete_a_guest_authors_published_post(): void {
		$user = $this->create_author( 'penname' );
		$this->create_linked_guest_author( 'penname', 'penname' );
		$post_id = $this->create_guest_author_post( 'penname' );

		$this->assertTrue( user_can( $user, 'edit_post', $post_id ) );
		$this->assertTrue( user_can( $user, 'delete_post', $post_id ) );
	}

	/**
	 * @covers ::filter_map_meta_cap
	 */
	public function test_an_unlinked_contributor_gets_no_rights_on_a_guest_authors_draft(): void {
		$this->create_guest_author( 'penname' );
		$post_id = $this->create_guest_author_post( 'penname', 'draft' );

		$user = $this->create_contributor( 'penname' );

		$this->assertFalse( user_can( $user, 'edit_post', $post_id ) );
		$this->assertFalse( user_can( $user, 'delete_post', $post_id ) );
	}

	/**
	 * @covers ::filter_map_meta_cap
	 */
	public function test_a_linked_contributor_can_edit_and_delete_a_guest_authors_draft(): void {
		$user = $this->create_contributor( 'penname' );
		$this->create_linked_guest_author( 'penname', 'penname' );
		$post_id = $this->create_guest_author_post( 'penname', 'draft' );

		$this->assertTrue( user_can( $user, 'edit_post', $post_id ) );
		$this->assertTrue( user_can( $user, 'delete_post', $post_id ) );
	}

	/**
	 * The login alone is enough to be looked up as the guest author, so it's tested
	 * apart from a nicename that also matches.
	 *
	 * @covers ::filter_map_meta_cap
	 */
	public function test_an_unlinked_author_with_only_the_login_in_common_gets_no_rights(): void {
		$this->create_guest_author( 'penname' );
		$post_id = $this->create_guest_author_post( 'penname' );

		$user = $this->factory()->user->create_and_get(
			array(
				'role'          => 'author',
				'user_login'    => 'penname',
				'user_nicename' => 'someone-else',
			)
		);

		$this->assertFalse( user_can( $user, 'edit_post', $post_id ) );
		$this->assertFalse( user_can( $user, 'delete_post', $post_id ) );
	}

	/**
	 * The steps in docs/guest-authors.md for a user and a profile that are one person.
	 *
	 * @covers ::filter_map_meta_cap
	 */
	public function test_linking_a_matching_user_afterwards_gives_them_rights(): void {
		$guest_id = $this->create_guest_author( 'penname' );
		$post_id  = $this->create_guest_author_post( 'penname' );
		$user     = $this->create_author( 'penname' );

		$this->assertFalse( user_can( $user, 'edit_post', $post_id ) );

		update_post_meta( $guest_id, 'cap-linked_account', 'penname' );
		$this->_cap->guest_authors->delete_guest_author_cache( $guest_id );

		$this->assertTrue( user_can( $user, 'edit_post', $post_id ) );
		$this->assertTrue( user_can( $user, 'delete_post', $post_id ) );
	}

	/**
	 * @covers ::filter_map_meta_cap
	 */
	public function test_a_guest_author_linked_to_someone_else_gives_rights_only_to_that_user(): void {
		$linked = $this->create_author( 'linkeduser' );
		$this->create_linked_guest_author( 'penname', 'linkeduser' );
		$post_id = $this->create_guest_author_post( 'penname' );

		$user = $this->create_author( 'penname' );

		$this->assertFalse( user_can( $user, 'edit_post', $post_id ) );
		$this->assertFalse( user_can( $user, 'delete_post', $post_id ) );
		$this->assertTrue( user_can( $linked, 'edit_post', $post_id ) );
		$this->assertTrue( user_can( $linked, 'delete_post', $post_id ) );
	}

	/**
	 * @covers ::filter_map_meta_cap
	 */
	public function test_a_linked_user_with_a_different_slug_can_edit_and_delete(): void {
		$user = $this->create_author( 'difflogin' );
		$this->create_linked_guest_author( 'diffslug', 'difflogin' );
		$post_id = $this->create_guest_author_post( 'diffslug' );

		$this->assertTrue( user_can( $user, 'edit_post', $post_id ) );
		$this->assertTrue( user_can( $user, 'delete_post', $post_id ) );
	}

	/**
	 * @covers ::filter_map_meta_cap
	 */
	public function test_an_ordinary_user_coauthor_can_edit_and_delete(): void {
		$first   = $this->create_author( 'first' );
		$second  = $this->create_author( 'second' );
		$post_id = $this->create_post( $first )->ID;
		$this->_cap->add_coauthors( $post_id, array( 'first', 'second' ) );

		$this->assertTrue( user_can( $second, 'edit_post', $post_id ) );
		$this->assertTrue( user_can( $second, 'delete_post', $post_id ) );
	}

	/**
	 * @covers ::filter_map_meta_cap
	 */
	public function test_core_rest_refuses_an_unlinked_author(): void {
		$this->create_guest_author( 'penname' );
		$post_id = $this->create_guest_author_post( 'penname' );
		$title   = get_post( $post_id )->post_title;

		wp_set_current_user( $this->create_author( 'penname' )->ID );

		$this->assertSame( 403, $this->rest_status( 'POST', $post_id ) );
		$this->assertSame( 403, $this->rest_status( 'DELETE', $post_id ) );
		$this->assertSame( $title, get_post( $post_id )->post_title );
		$this->assertSame( 'publish', get_post( $post_id )->post_status );
	}

	/**
	 * @covers ::filter_map_meta_cap
	 */
	public function test_core_rest_lets_a_linked_author_update(): void {
		$user = $this->create_author( 'penname' );
		$this->create_linked_guest_author( 'penname', 'penname' );
		$post_id = $this->create_guest_author_post( 'penname' );

		wp_set_current_user( $user->ID );

		$this->assertSame( 200, $this->rest_status( 'POST', $post_id ) );
		$this->assertSame( 'Saved over REST', get_post( $post_id )->post_title );
	}

	/**
	 * @covers ::filter_map_meta_cap
	 */
	public function test_a_classic_editor_save_refuses_an_unlinked_author(): void {
		$this->create_guest_author( 'penname' );
		$post_id = $this->create_guest_author_post( 'penname' );
		$title   = get_post( $post_id )->post_title;

		wp_set_current_user( $this->create_author( 'penname' )->ID );
		$this->prepare_classic_save( $post_id );

		try {
			edit_post();
			$this->fail( 'The save should have been refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $title, get_post( $post_id )->post_title );
		}
	}

	/**
	 * The check after edit_post, for edit_others_posts, is maybe_allow_coauthor_save().
	 *
	 * @covers ::maybe_allow_coauthor_save
	 */
	public function test_the_coauthor_save_grant_refuses_an_unlinked_author(): void {
		$this->create_guest_author( 'penname' );
		$post_id = $this->create_guest_author_post( 'penname' );

		wp_set_current_user( $this->create_author( 'penname' )->ID );
		$this->prepare_classic_save( $post_id );

		$this->assertFalse( current_user_can( 'edit_others_posts' ) );
	}

	/**
	 * @covers ::maybe_allow_coauthor_save
	 */
	public function test_a_classic_editor_save_lets_a_linked_author_save(): void {
		$user = $this->create_author( 'penname' );
		$this->create_linked_guest_author( 'penname', 'penname' );
		$post_id = $this->create_guest_author_post( 'penname' );

		wp_set_current_user( $user->ID );
		$this->prepare_classic_save( $post_id );

		$this->assertSame( $post_id, edit_post() );
		$this->assertSame( 'Saved in the classic editor', get_post( $post_id )->post_title );
	}
}
