<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use WP_Post;
use WP_REST_Request;

/**
 * Regression coverage for issue #1269.
 *
 * The block editor REST save flow used to ship the stale `post_author` to any
 * listener of `wp_insert_post` (notably Jetpack Sync) because CAP did not
 * update `post_author` until `rest_after_insert_{post_type}`, which fires
 * after `wp_insert_post`. The Jetpack Newsletter preview, rendered on
 * WordPress.com from synced post fields, therefore showed the wrong author
 * until a second save landed.
 *
 * `set_post_author_for_rest_save()` closes the gap by deriving `post_author`
 * from the coauthors term IDs in the request at `rest_pre_insert_{post_type}`,
 * before the post is written.
 *
 * @covers \CoAuthors_Plus::set_post_author_for_rest_save
 */
class RestSavePostAuthorTimingTest extends TestCase {

	/**
	 * The post_author value seen by `wp_insert_post` listeners during the
	 * most recent REST save. Captured via a one-shot listener installed by
	 * each test so we can assert what Jetpack Sync (and friends) would see.
	 *
	 * @var int|null
	 */
	private $post_author_seen_by_wp_insert_post;

	public function set_up() {
		parent::set_up();
		$this->post_author_seen_by_wp_insert_post = null;
	}

	/**
	 * Install a listener that records the post_author at the moment
	 * `wp_insert_post` fires. Mirrors where Jetpack Sync queues the post.
	 */
	private function spy_on_wp_insert_post( int $post_id ): void {
		$callback = function ( $captured_id, $post ) use ( $post_id ) {
			if ( (int) $captured_id === $post_id ) {
				$this->post_author_seen_by_wp_insert_post = (int) $post->post_author;
			}
		};
		add_action( 'wp_insert_post', $callback, 5, 2 );
	}

	public function test_rest_save_updates_post_author_before_wp_insert_post_fires(): void {
		$original = $this->create_author( 'cap-1269-original' );
		$switch_to = $this->create_author( 'cap-1269-replacement' );
		$post = $this->create_post( $original );

		$this->_cap->update_author_term( $original );
		$this->_cap->update_author_term( $switch_to );
		$replacement_term = $this->_cap->get_author_term( $switch_to );

		// An admin must be doing the save so current_user_can_set_authors() returns true.
		$admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );

		$this->spy_on_wp_insert_post( $post->ID );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post->ID );
		$request->set_param( 'id', $post->ID );
		$request->set_param( 'coauthors', array( $replacement_term->term_id ) );
		$request->set_param( 'title', 'After switching primary author' );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame(
			$switch_to->ID,
			$this->post_author_seen_by_wp_insert_post,
			'wp_insert_post listeners must see the new co-author as post_author, not the previous one.'
		);

		$this->assertSame(
			$switch_to->ID,
			(int) get_post( $post->ID )->post_author,
			'post_author in the DB after the save must reflect the new co-author.'
		);
	}

	public function test_rest_save_uses_linked_wp_user_for_guest_author_coauthor(): void {
		$linked_user = $this->create_author( 'linked-user-1269' );
		$original    = $this->create_author( 'original-1269-2' );
		$post        = $this->create_post( $original );

		// Guest author with a linked WP user account.
		$guest_id = $this->_cap->guest_authors->create(
			array(
				'display_name'   => 'Linked Guest',
				'user_login'     => 'guest-linked-1269',
				'linked_account' => $linked_user->user_login,
			)
		);

		$guest = $this->_cap->guest_authors->get_guest_author_by( 'id', $guest_id );
		$this->_cap->update_author_term( $guest );
		$guest_term = $this->_cap->get_author_term( $guest );

		$admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );

		$this->spy_on_wp_insert_post( $post->ID );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post->ID );
		$request->set_param( 'id', $post->ID );
		$request->set_param( 'coauthors', array( $guest_term->term_id ) );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame(
			$linked_user->ID,
			$this->post_author_seen_by_wp_insert_post,
			'A guest author with a linked WP user must surface that user as post_author at wp_insert_post.'
		);
	}

	public function test_rest_save_leaves_post_author_unchanged_when_still_a_coauthor(): void {
		$author    = $this->create_author( 'cap-1269-stays' );
		$secondary = $this->create_author( 'cap-1269-secondary' );
		$post      = $this->create_post( $author );

		$this->_cap->update_author_term( $author );
		$this->_cap->update_author_term( $secondary );
		$author_term    = $this->_cap->get_author_term( $author );
		$secondary_term = $this->_cap->get_author_term( $secondary );

		$admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );

		$this->spy_on_wp_insert_post( $post->ID );

		// Add a secondary coauthor; the original author is still primary.
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post->ID );
		$request->set_param( 'id', $post->ID );
		$request->set_param( 'coauthors', array( $author_term->term_id, $secondary_term->term_id ) );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame(
			$author->ID,
			$this->post_author_seen_by_wp_insert_post,
			'When the existing post_author remains in the coauthors list, post_author must not be reassigned.'
		);
	}

	public function test_rest_save_leaves_post_author_unchanged_when_no_coauthors_param(): void {
		$author = $this->create_author( 'cap-1269-no-coauthors-param' );
		$post   = $this->create_post( $author );

		$admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );

		$this->spy_on_wp_insert_post( $post->ID );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post->ID );
		$request->set_param( 'id', $post->ID );
		$request->set_param( 'title', 'No coauthors data in this save' );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame(
			$author->ID,
			$this->post_author_seen_by_wp_insert_post,
			'When no coauthors taxonomy data is sent, post_author must be preserved as-is.'
		);
	}

	public function test_rest_save_leaves_post_author_unchanged_for_guest_only_coauthor(): void {
		$author = $this->create_author( 'cap-1269-guest-only' );
		$post   = $this->create_post( $author );

		// Guest author with NO linked WP user — there is no WP_User to surface.
		$guest_id = $this->_cap->guest_authors->create(
			array(
				'display_name' => 'Standalone Guest',
				'user_login'   => 'guest-only-1269',
			)
		);
		$guest = $this->_cap->guest_authors->get_guest_author_by( 'id', $guest_id );
		$this->_cap->update_author_term( $guest );
		$guest_term = $this->_cap->get_author_term( $guest );

		$admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );

		$this->spy_on_wp_insert_post( $post->ID );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post->ID );
		$request->set_param( 'id', $post->ID );
		$request->set_param( 'coauthors', array( $guest_term->term_id ) );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame(
			$author->ID,
			$this->post_author_seen_by_wp_insert_post,
			'A guest-only coauthor cannot replace post_author because there is no underlying WP_User. This is a known gap that requires a wpcom-side fix; see #1269.'
		);
	}
}
